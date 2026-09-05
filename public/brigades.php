<?php

declare(strict_types=1);

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';
require __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/team_work.php';

$user = require_user();

require_permission(
    'production.view',
    $user
);

if (!is_section_manager($user)) {
    http_response_code(403);

    exit(
        'Ця сторінка доступна тільки Майстру дільниці.'
    );
}

$stageId = current_stage_id($user);

if (!$stageId) {
    http_response_code(403);

    exit(
        'Для Майстра не призначена дільниця.'
    );
}



function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function brigadeAudit(
    PDO $db,
    int $userId,
    string $action,
    int $entityId,
    ?array $oldValue = null,
    ?array $newValue = null
): void {

    $stmt = $db->prepare("
        INSERT INTO audit_log (
            user_id,
            action,
            entity_type,
            entity_id,
            old_value,
            new_value,
            ip_address,
            user_agent
        )
        VALUES (
            :user_id,
            :action,
            'work_session',
            :entity_id,
            :old_value,
            :new_value,
            :ip_address,
            :user_agent
        )
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':action' => $action,
        ':entity_id' => $entityId,

        ':old_value' =>
            $oldValue !== null
                ? json_encode(
                    $oldValue,
                    JSON_UNESCAPED_UNICODE
                )
                : null,

        ':new_value' =>
            $newValue !== null
                ? json_encode(
                    $newValue,
                    JSON_UNESCAPED_UNICODE
                )
                : null,

        ':ip_address' =>
            $_SERVER['REMOTE_ADDR']
            ?? null,

        ':user_agent' =>
            $_SERVER['HTTP_USER_AGENT']
            ?? null,
    ]);
}

function createBrigadeVersion(
    PDO $db,
    int $ownerId,
    int $stageId,
    array $memberIds
): int {

    $memberIds = array_values(
        array_unique(
            array_map(
                'intval',
                $memberIds
            )
        )
    );

    if (!in_array(
        $ownerId,
        $memberIds,
        true
    )) {
        array_unshift(
            $memberIds,
            $ownerId
        );
    }

    if (count($memberIds) < 2) {
        throw new RuntimeException(
            'У бригаді має бути щонайменше два працівники.'
        );
    }

    $sessionStmt = $db->prepare("
        INSERT INTO work_sessions (
            owner_employee_id,
            stage_id,
            mode,
            active,
            started_at,
            created_at,
            updated_at
        )
        VALUES (
            :owner_id,
            :stage_id,
            'team',
            1,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
    ");

    $sessionStmt->execute([
        ':owner_id' => $ownerId,
        ':stage_id' => $stageId,
    ]);

    $sessionId =
        (int)$db->lastInsertId();

    $share =
        100.0
        /
        count($memberIds);

    $memberStmt = $db->prepare("
        INSERT INTO work_session_members (
            work_session_id,
            employee_id,
            share_percent
        )
        VALUES (
            :session_id,
            :employee_id,
            :share_percent
        )
    ");

    foreach ($memberIds as $memberId) {

        $memberStmt->execute([
            ':session_id' =>
                $sessionId,

            ':employee_id' =>
                $memberId,

            ':share_percent' =>
                $share,
        ]);
    }

    return $sessionId;
}

function getBrigadeMembers(
    PDO $db,
    int $sessionId
): array {

    $stmt = $db->prepare("
        SELECT
            wsm.employee_id,
            u.name,
            u.stage_id,
            ps.name AS stage_name
        FROM work_session_members wsm

        JOIN users u
            ON u.id =
                wsm.employee_id

        LEFT JOIN production_stages ps
            ON ps.id =
                u.stage_id

        WHERE wsm.work_session_id =
            :session_id

        ORDER BY
            wsm.id
    ");

    $stmt->execute([
        ':session_id' =>
            $sessionId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/*
|--------------------------------------------------------------------------
| Дільниця Майстра
|--------------------------------------------------------------------------
*/

$stageStmt = $db->prepare("
    SELECT
        id,
        name
    FROM production_stages
    WHERE id = :id
    LIMIT 1
");

$stageStmt->execute([
    ':id' => $stageId,
]);

$currentStage =
    $stageStmt->fetch(
        PDO::FETCH_ASSOC
    );

if (!$currentStage) {
    exit('Дільницю не знайдено.');
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['brigades_csrf'])) {
    $_SESSION['brigades_csrf'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['brigades_csrf'];


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    if (
        !hash_equals(
            $csrfToken,
            $_POST['csrf_token']
            ?? ''
        )
    ) {
        http_response_code(403);
        exit(
            'Помилка перевірки безпеки.'
        );
    }

    $action =
        $_POST['action']
        ?? '';

    try {

        /*
        |--------------------------------------------------------------------------
        | Створити бригаду
        |--------------------------------------------------------------------------
        */

        if ($action === 'create') {

            $ownerId =
                (int)(
                    $_POST['owner_id']
                    ?? 0
                );

            $memberIds =
                $_POST['member_ids']
                ?? [];

            if (!is_array($memberIds)) {
                $memberIds = [];
            }

            if ($ownerId <= 0) {
                throw new RuntimeException(
                    'Оберіть відповідального працівника.'
                );
            }

            /*
             * Відповідальний повинен бути
             * працівником саме дільниці Майстра.
             */

            $ownerStmt =
                $db->prepare("
                    SELECT
                        id,
                        name
                    FROM users
                    WHERE id = :id
                      AND active = 1
                      AND role = 'employee'
                      AND stage_id = :stage_id
                    LIMIT 1
                ");

            $ownerStmt->execute([
                ':id' =>
                    $ownerId,
                ':stage_id' =>
                    $stageId,
            ]);

            $owner =
                $ownerStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$owner) {
                throw new RuntimeException(
                    'Відповідальний має бути працівником цієї дільниці.'
                );
            }

            $memberIds = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            $memberIds
                        ),
                        static fn(int $id): bool =>
                            $id > 0
                    )
                )
            );

            if (!in_array(
                $ownerId,
                $memberIds,
                true
            )) {
                array_unshift(
                    $memberIds,
                    $ownerId
                );
            }

            if (count($memberIds) < 2) {
                throw new RuntimeException(
                    'Оберіть хоча б одного учасника крім відповідального.'
                );
            }

            /*
             * Перевіряємо працівників.
             */

            $placeholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count($memberIds),
                        '?'
                    )
                );

            $validateStmt =
                $db->prepare("
                    SELECT id
                    FROM users
                    WHERE active = 1
                      AND role IN (
                          'employee',
                          'section_manager'
                      )
                      AND id IN ($placeholders)
                ");

            $validateStmt->execute(
                $memberIds
            );

            $validIds =
                array_map(
                    'intval',
                    $validateStmt->fetchAll(
                        PDO::FETCH_COLUMN
                    )
                );

            sort($validIds);

            $expectedIds =
                $memberIds;

            sort($expectedIds);

            if (
                $validIds
                !==
                $expectedIds
            ) {
                throw new RuntimeException(
                    'Один або декілька працівників недоступні.'
                );
            }

            /*
             * Один працівник не може входити
             * до двох активних бригад
             * на ОДНІЙ дільниці.
             */

            $busyStmt =
                $db->prepare("
                    SELECT
                        u.name
                    FROM work_sessions ws

                    JOIN work_session_members wsm
                        ON wsm.work_session_id =
                            ws.id

                    JOIN users u
                        ON u.id =
                            wsm.employee_id

                    WHERE ws.active = 1
                      AND ws.mode = 'team'
                      AND ws.stage_id = ?
                      AND wsm.employee_id
                          IN ($placeholders)

                    LIMIT 1
                ");

            $busyStmt->execute(
                array_merge(
                    [$stageId],
                    $memberIds
                )
            );

            $busy =
                $busyStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if ($busy) {
                throw new RuntimeException(
                    'Працівник '
                    . $busy['name']
                    . ' вже входить до активної бригади на цій дільниці.'
                );
            }

            $db->beginTransaction();

            $sessionId =
                createBrigadeVersion(
                    $db,
                    $ownerId,
                    $stageId,
                    $memberIds
                );

            brigadeAudit(
                $db,
                (int)$user['id'],
                'team_work_started_by_section_manager',
                $sessionId,
                null,
                [
                    'stage_id' =>
                        $stageId,

                    'owner_employee_id' =>
                        $ownerId,

                    'member_ids' =>
                        $memberIds,
                ]
            );

            $db->commit();

            $_SESSION['brigades_flash'] = [
                'type' =>
                    'success',

                'message' =>
                    'Бригаду створено.',
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Додати учасника
        |--------------------------------------------------------------------------
        */

        elseif (
            $action === 'add_member'
        ) {

            $sessionId =
                (int)(
                    $_POST['session_id']
                    ?? 0
                );

            $employeeId =
                (int)(
                    $_POST['employee_id']
                    ?? 0
                );

            $db->beginTransaction();

            $sessionStmt =
                $db->prepare("
                    SELECT
                        id,
                        owner_employee_id,
                        stage_id
                    FROM work_sessions
                    WHERE id = :id
                      AND active = 1
                      AND mode = 'team'
                      AND stage_id = :stage_id
                    LIMIT 1
                ");

            $sessionStmt->execute([
                ':id' =>
                    $sessionId,

                ':stage_id' =>
                    $stageId,
            ]);

            $session =
                $sessionStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$session) {
                throw new RuntimeException(
                    'Бригаду не знайдено.'
                );
            }

            $employeeStmt =
                $db->prepare("
                    SELECT
                        id,
                        name
                    FROM users
                    WHERE id = :id
                      AND active = 1
                      AND role IN (
                          'employee',
                          'section_manager'
                      )
                    LIMIT 1
                ");

            $employeeStmt->execute([
                ':id' =>
                    $employeeId,
            ]);

            $employee =
                $employeeStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$employee) {
                throw new RuntimeException(
                    'Працівника не знайдено.'
                );
            }

            $members =
                getBrigadeMembers(
                    $db,
                    $sessionId
                );

            $memberIds =
                array_map(
                    static fn(array $member): int =>
                        (int)$member[
                            'employee_id'
                        ],
                    $members
                );

            if (
                in_array(
                    $employeeId,
                    $memberIds,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Працівник уже входить до цієї бригади.'
                );
            }

            /*
             * Перевіряємо іншу бригаду
             * на цій же дільниці.
             */

            $busyStmt =
                $db->prepare("
                    SELECT ws.id
                    FROM work_sessions ws

                    JOIN work_session_members wsm
                        ON wsm.work_session_id =
                            ws.id

                    WHERE ws.active = 1
                      AND ws.mode = 'team'
                      AND ws.stage_id =
                          :stage_id
                      AND ws.id !=
                          :session_id
                      AND wsm.employee_id =
                          :employee_id

                    LIMIT 1
                ");

            $busyStmt->execute([
                ':stage_id' =>
                    $stageId,

                ':session_id' =>
                    $sessionId,

                ':employee_id' =>
                    $employeeId,
            ]);

            if (
                $busyStmt->fetchColumn()
                !== false
            ) {
                throw new RuntimeException(
                    'Працівник уже входить до іншої активної бригади на цій дільниці.'
                );
            }

            $memberIds[] =
                $employeeId;

            /*
             * Закриваємо попередню версію.
             */

            $closeStmt =
                $db->prepare("
                    UPDATE work_sessions
                    SET
                        active = 0,
                        ended_at =
                            CURRENT_TIMESTAMP,
                        updated_at =
                            CURRENT_TIMESTAMP
                    WHERE id = :id
                ");

            $closeStmt->execute([
                ':id' =>
                    $sessionId,
            ]);

            $newSessionId =
                createBrigadeVersion(
                    $db,
                    (int)$session[
                        'owner_employee_id'
                    ],
                    $stageId,
                    $memberIds
                );

            brigadeAudit(
                $db,
                (int)$user['id'],
                'team_member_added_by_section_manager',
                $newSessionId,
                [
                    'previous_session_id' =>
                        $sessionId,
                ],
                [
                    'member_ids' =>
                        $memberIds,

                    'added_employee_id' =>
                        $employeeId,
                ]
            );

            $db->commit();

            $_SESSION['brigades_flash'] = [
                'type' =>
                    'success',

                'message' =>
                    'Працівника додано до бригади.',
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Вивести учасника
        |--------------------------------------------------------------------------
        */

        elseif (
            $action === 'remove_member'
        ) {

            $sessionId =
                (int)(
                    $_POST['session_id']
                    ?? 0
                );

            $employeeId =
                (int)(
                    $_POST['employee_id']
                    ?? 0
                );

            $db->beginTransaction();

            $sessionStmt =
                $db->prepare("
                    SELECT
                        id,
                        owner_employee_id,
                        stage_id
                    FROM work_sessions
                    WHERE id = :id
                      AND active = 1
                      AND mode = 'team'
                      AND stage_id = :stage_id
                    LIMIT 1
                ");

            $sessionStmt->execute([
                ':id' =>
                    $sessionId,

                ':stage_id' =>
                    $stageId,
            ]);

            $session =
                $sessionStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$session) {
                throw new RuntimeException(
                    'Бригаду не знайдено.'
                );
            }

            if (
                $employeeId
                ===
                (int)$session[
                    'owner_employee_id'
                ]
            ) {
                throw new RuntimeException(
                    'Відповідального не можна вивести з бригади. Завершіть бригаду.'
                );
            }

            $members =
                getBrigadeMembers(
                    $db,
                    $sessionId
                );

            $memberIds =
                array_map(
                    static fn(array $member): int =>
                        (int)$member[
                            'employee_id'
                        ],
                    $members
                );

            $memberIds =
                array_values(
                    array_filter(
                        $memberIds,
                        static fn(int $id): bool =>
                            $id !== $employeeId
                    )
                );

            if (
                count($memberIds)
                < 2
            ) {
                throw new RuntimeException(
                    'Після видалення залишиться один працівник. Завершіть бригаду.'
                );
            }

            $closeStmt =
                $db->prepare("
                    UPDATE work_sessions
                    SET
                        active = 0,
                        ended_at =
                            CURRENT_TIMESTAMP,
                        updated_at =
                            CURRENT_TIMESTAMP
                    WHERE id = :id
                ");

            $closeStmt->execute([
                ':id' =>
                    $sessionId,
            ]);

            $newSessionId =
                createBrigadeVersion(
                    $db,
                    (int)$session[
                        'owner_employee_id'
                    ],
                    $stageId,
                    $memberIds
                );

            brigadeAudit(
                $db,
                (int)$user['id'],
                'team_member_removed_by_section_manager',
                $newSessionId,
                [
                    'previous_session_id' =>
                        $sessionId,
                ],
                [
                    'member_ids' =>
                        $memberIds,

                    'removed_employee_id' =>
                        $employeeId,
                ]
            );

            $db->commit();

            $_SESSION['brigades_flash'] = [
                'type' =>
                    'success',

                'message' =>
                    'Працівника виведено з бригади.',
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Завершити
        |--------------------------------------------------------------------------
        */

        elseif (
            $action === 'end'
        ) {

            $sessionId =
                (int)(
                    $_POST['session_id']
                    ?? 0
                );

            $db->beginTransaction();

            $sessionStmt =
                $db->prepare("
                    SELECT id
                    FROM work_sessions
                    WHERE id = :id
                      AND active = 1
                      AND mode = 'team'
                      AND stage_id = :stage_id
                    LIMIT 1
                ");

            $sessionStmt->execute([
                ':id' =>
                    $sessionId,

                ':stage_id' =>
                    $stageId,
            ]);

            if (
                $sessionStmt->fetchColumn()
                === false
            ) {
                throw new RuntimeException(
                    'Бригаду не знайдено.'
                );
            }

            $endStmt =
                $db->prepare("
                    UPDATE work_sessions
                    SET
                        active = 0,
                        ended_at =
                            CURRENT_TIMESTAMP,
                        updated_at =
                            CURRENT_TIMESTAMP
                    WHERE id = :id
                ");

            $endStmt->execute([
                ':id' =>
                    $sessionId,
            ]);

            brigadeAudit(
                $db,
                (int)$user['id'],
                'team_work_ended_by_section_manager',
                $sessionId,
                ['active' => 1],
                ['active' => 0]
            );

            $db->commit();

            $_SESSION['brigades_flash'] = [
                'type' =>
                    'success',

                'message' =>
                    'Бригаду завершено.',
            ];
        }

    } catch (Throwable $exception) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $_SESSION['brigades_flash'] = [
            'type' =>
                'error',

            'message' =>
                $exception->getMessage(),
        ];
    }

    header(
        'Location: /brigades.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Дані
|--------------------------------------------------------------------------
*/

$flash =
    $_SESSION['brigades_flash']
    ?? null;

unset(
    $_SESSION['brigades_flash']
);


/*
 * Працівники дільниці —
 * кандидати на роль відповідального.
 */

$ownersStmt =
    $db->prepare("
        SELECT
            id,
            name
        FROM users
        WHERE active = 1
          AND role = 'employee'
          AND stage_id = :stage_id
        ORDER BY name
    ");

$ownersStmt->execute([
    ':stage_id' =>
        $stageId,
]);

$owners =
    $ownersStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
 * Усі працівники —
 * кандидати в учасники.
 */

$workersStmt =
    $db->query("
        SELECT
            u.id,
            u.name,
            u.stage_id,
            ps.name AS stage_name
        FROM users u

        LEFT JOIN production_stages ps
            ON ps.id =
                u.stage_id

        WHERE u.active = 1
          AND u.role IN (
              'employee',
              'section_manager'
          )

        ORDER BY
            ps.name,
            u.name
    ");

$workers =
    $workersStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
 * Активні бригади лише дільниці Майстра.
 */

$teamsStmt =
    $db->prepare("
        SELECT
            ws.id,
            ws.owner_employee_id,
            ws.stage_id,
            ws.started_at,
            u.name AS owner_name
        FROM work_sessions ws

        JOIN users u
            ON u.id =
                ws.owner_employee_id

        WHERE ws.active = 1
          AND ws.mode = 'team'
          AND ws.stage_id =
              :stage_id

        ORDER BY
            ws.started_at,
            ws.id
    ");

$teamsStmt->execute([
    ':stage_id' =>
        $stageId,
]);

$teams =
    $teamsStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

foreach ($teams as &$team) {

    $team['members'] =
        getBrigadeMembers(
            $db,
            (int)$team['id']
        );

    $team['member_ids'] =
        array_map(
            static fn(array $member): int =>
                (int)$member[
                    'employee_id'
                ],
            $team['members']
        );
}

unset($team);

?>
<!DOCTYPE html>
<html lang="uk">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Бригади дільниці — OPTIMA GLASS
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>

        .brigades-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .brigades-header {
            margin-bottom: 24px;
        }

        .brigades-header h1 {
            margin-bottom: 6px;
        }

        .brigade-card {
            margin-bottom: 20px;
            padding: 22px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
        }

        .brigade-top {
            display: flex;
            gap: 15px;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .brigade-members {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 15px 0;
        }

        .brigade-member {
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f9fafb;
        }

        .brigade-member strong {
            display: block;
            margin-bottom: 4px;
        }

        .muted {
            color: #6b7280;
        }

        .form-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: end;
        }

        .form-field {
            flex: 1;
            min-width: 220px;
        }

        .form-field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .form-field select {
            width: 100%;
            min-height: 44px;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
        }

        .flash {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 10px;
            font-weight: 700;
        }

        .flash.success {
            background: #dcfce7;
            color: #166534;
        }

        .flash.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            padding: 28px;
            text-align: center;
            background: #fff;
            border: 1px dashed #d1d5db;
            border-radius: 14px;
            color: #6b7280;
        }

        .actions-inline {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

    </style>

</head>

<body>

<?php
if (
    file_exists(
        __DIR__
        . '/../src/partials/header.php'
    )
) {
    require
        __DIR__
        . '/../src/partials/header.php';
}
?>

<main class="brigades-page">

    <div class="brigades-header">

        <h1>
            Бригади дільниці
        </h1>

        <div class="muted">
            Дільниця:
            <strong>
                <?= e(
                    $currentStage['name']
                ) ?>
            </strong>
        </div>

    </div>


    <?php if ($flash): ?>

        <div
            class="flash <?= e(
                $flash['type']
            ) ?>"
        >
            <?= e(
                $flash['message']
            ) ?>
        </div>

    <?php endif; ?>


    <section class="brigade-card">

        <h2>
            Створити бригаду
        </h2>

        <p class="muted">
            Відповідальний повинен бути працівником
            цієї дільниці. Помічників можна додавати
            з інших дільниць.
        </p>

        <?php if ($owners): ?>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(
                        $csrfToken
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="create"
                >

                <div class="form-row">

                    <div class="form-field">

                        <label for="owner_id">
                            Відповідальний
                        </label>

                        <select
                            name="owner_id"
                            id="owner_id"
                            required
                        >

                            <option value="">
                                Оберіть працівника
                            </option>

                            <?php foreach (
                                $owners
                                as $owner
                            ): ?>

                                <option
                                    value="<?= (int)
                                        $owner['id'] ?>"
                                >
                                    <?= e(
                                        $owner['name']
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="form-field">

                        <label for="member_ids">
                            Учасники
                        </label>

                        <select
                            name="member_ids[]"
                            id="member_ids"
                            multiple
                            size="7"
                            required
                        >

                            <?php foreach (
                                $workers
                                as $worker
                            ): ?>

                                <option
                                    value="<?= (int)
                                        $worker['id'] ?>"
                                >
                                    <?= e(
                                        $worker['name']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $worker[
                                                'stage_name'
                                            ]
                                        )
                                    ): ?>

                                        —
                                        <?= e(
                                            $worker[
                                                'stage_name'
                                            ]
                                        ) ?>

                                    <?php endif; ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div>

                        <button
                            type="submit"
                            class="button"
                        >
                            Створити бригаду
                        </button>

                    </div>

                </div>

            </form>

        <?php else: ?>

            <div class="empty-state">
                На цій дільниці немає активних працівників,
                яких можна призначити відповідальними.
            </div>

        <?php endif; ?>

    </section>


    <h2>
        Активні бригади
    </h2>


    <?php if (!$teams): ?>

        <div class="empty-state">
            На дільниці зараз немає активних бригад.
        </div>

    <?php endif; ?>


    <?php foreach ($teams as $team): ?>

        <section class="brigade-card">

            <div class="brigade-top">

                <div>

                    <h3>
                        Бригада #<?= (int)
                            $team['id'] ?>
                    </h3>

                    <div class="muted">
                        Відповідальний:
                        <strong>
                            <?= e(
                                $team[
                                    'owner_name'
                                ]
                            ) ?>
                        </strong>
                    </div>

                    <div class="muted">
                        Початок:
                        <?= e(
                            $team[
                                'started_at'
                            ]
                        ) ?>
                    </div>

                </div>


                <form method="post">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(
                            $csrfToken
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="end"
                    >

                    <input
                        type="hidden"
                        name="session_id"
                        value="<?= (int)
                            $team['id'] ?>"
                    >

                    <button
                        type="submit"
                        class="button button-secondary"
                        onclick="return confirm('Завершити бригаду?');"
                    >
                        Завершити
                    </button>

                </form>

            </div>


            <div class="brigade-members">

                <?php foreach (
                    $team['members']
                    as $member
                ): ?>

                    <div class="brigade-member">

                        <strong>
                            <?= e(
                                $member['name']
                            ) ?>
                        </strong>

                        <span class="muted">
                            <?= e(
                                $member[
                                    'stage_name'
                                ]
                                ?? 'Без дільниці'
                            ) ?>
                        </span>

                        <?php if (
                            (int)$member[
                                'employee_id'
                            ]
                            !==
                            (int)$team[
                                'owner_employee_id'
                            ]
                        ): ?>

                            <form
                                method="post"
                                class="actions-inline"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(
                                        $csrfToken
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="remove_member"
                                >

                                <input
                                    type="hidden"
                                    name="session_id"
                                    value="<?= (int)
                                        $team['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="employee_id"
                                    value="<?= (int)
                                        $member[
                                            'employee_id'
                                        ] ?>"
                                >

                                <button
                                    type="submit"
                                    class="button button-secondary"
                                    onclick="return confirm('Вивести працівника з бригади?');"
                                >
                                    Вивести
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>


            <form
                method="post"
                class="form-row"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(
                        $csrfToken
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="add_member"
                >

                <input
                    type="hidden"
                    name="session_id"
                    value="<?= (int)
                        $team['id'] ?>"
                >

                <div class="form-field">

                    <label>
                        Додати працівника
                    </label>

                    <select
                        name="employee_id"
                        required
                    >

                        <option value="">
                            Оберіть працівника
                        </option>

                        <?php foreach (
                            $workers
                            as $worker
                        ): ?>

                            <?php if (
                                in_array(
                                    (int)$worker[
                                        'id'
                                    ],
                                    $team[
                                        'member_ids'
                                    ],
                                    true
                                )
                            ) {
                                continue;
                            } ?>

                            <option
                                value="<?= (int)
                                    $worker['id'] ?>"
                            >
                                <?= e(
                                    $worker['name']
                                ) ?>

                                <?php if (
                                    !empty(
                                        $worker[
                                            'stage_name'
                                        ]
                                    )
                                ): ?>

                                    —
                                    <?= e(
                                        $worker[
                                            'stage_name'
                                        ]
                                    ) ?>

                                <?php endif; ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <button
                        type="submit"
                        class="button"
                    >
                        Додати
                    </button>

                </div>

            </form>

        </section>

    <?php endforeach; ?>

</main>

</body>
</html>
