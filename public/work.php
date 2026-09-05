<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/notifications.php';
require __DIR__ . '/../src/telegram.php';
require __DIR__ . '/../src/permissions.php';
require_once __DIR__ . '/../src/team_work.php';

$user = require_user();

/*
|--------------------------------------------------------------------------
| Допоміжні функції
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function priorityLabel(int $priority): string
{
    return match ($priority) {
        3 => '🔴 Критичний',
        2 => '🟠 Терміновий',
        default => '🟢 Звичайний',
    };
}

function stageLabel(string $name): string
{
    return match ($name) {
        'Порезка' => 'Порізка',
        'Обработка' => 'Обробка',
        'Закалка' => 'Гартування',
        'Контроль качества' => 'Контроль якості',
        'Упаковка' => 'Пакування',
        'Отгрузка' => 'Відвантаження',
        'Емалит' => 'Емаліт',
        'Триплекс' => 'Триплекс',
        default => $name,
    };
}

function writeAudit(
    PDO $db,
    int $userId,
    string $action,
    ?string $entityType,
    ?int $entityId,
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
            :entity_type,
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
        ':entity_type' => $entityType,
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


/*
|--------------------------------------------------------------------------
| Розподіл виробітку між працівниками
|--------------------------------------------------------------------------
|
| Якщо активної спільної роботи немає:
| працівник, який виконав сканування, отримує 100%.
|
| Якщо є активна work_session на цій дільниці:
| площа розподіляється між її учасниками.
|
*/



/*
|--------------------------------------------------------------------------
| Доступ
|--------------------------------------------------------------------------
*/

require_permission(
    'production.view',
    $user
);

$canScan =
    can(
        'glass.scan',
        $user
    );

$canReject =
    can(
        'glass.reject',
        $user
    );

$canCompleteOrderStage =
    can(
        'production.complete_order_stage',
        $user
    );

/*
|--------------------------------------------------------------------------
| Менеджер
|--------------------------------------------------------------------------
|
| Сторінка work.php призначена для працівника та майстра дільниці.
| Менеджер працює із замовленнями через manager.php.
|
*/

if (
    ($user['role'] ?? '') === 'manager'
) {
    header('Location: /manager.php');
    exit;
}

$stageId =
    current_stage_id(
        $user
    );

if ($stageId === null) {

    http_response_code(403);

    exit(
        'Користувачу не призначено виробничу дільницю.'
    );
}

/*
|--------------------------------------------------------------------------
| Поточна дільниця
|--------------------------------------------------------------------------
*/

$stageStmt =
    $db->prepare("
        SELECT
            id,
            name,
            execution_mode
        FROM production_stages
        WHERE id = :id
          AND active = 1
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

    http_response_code(403);

    exit(
        'Виробничу дільницю не знайдено.'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION['csrf_work']
    )
) {

    $_SESSION['csrf_work'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['csrf_work'];

/*
|--------------------------------------------------------------------------
| Результат операції
|--------------------------------------------------------------------------
*/

$scanType = '';
$scanTitle = '';
$scanMessage = '';
$scannedCode = '';

$orderQrPreview = null;
$orderQrGlasses = [];
$orderQrArea = 0.0;
$orderQrBatchCount = 0;
$orderQrRejectedCount = 0;

/*
|--------------------------------------------------------------------------
| Завантаження скла замовлення на поточній дільниці
|--------------------------------------------------------------------------
*/

function loadOrderStageGlasses(
    PDO $db,
    int $orderId,
    int $stageId
): array {

    $stmt =
        $db->prepare("
            SELECT
                g.id,
                g.code,
                g.order_id,
                g.order_number,
                g.status,
                g.width,
                g.height,
                g.quantity,
                g.route_id,
                g.current_step_id,
                g.current_location,

                rs.step_number,
                rs.name AS stage_name,

                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM production_batch_items pbi
                        JOIN production_batches pb
                            ON pb.id = pbi.batch_id
                        WHERE pbi.glass_id = g.id
                          AND pb.status IN (
                              'created',
                              'in_progress'
                          )
                    )
                    THEN 1
                    ELSE 0
                END AS in_active_batch

            FROM glasses g

            JOIN route_steps rs
                ON rs.id =
                    g.current_step_id

            JOIN production_stages ps
                ON ps.name =
                    rs.name

            WHERE g.order_id =
                :order_id

              AND ps.id =
                :stage_id

            ORDER BY g.id
        ");

    $stmt->execute([
        ':order_id' =>
            $orderId,

        ':stage_id' =>
            $stageId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    /*
     * CSRF.
     */

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

    /*
    |--------------------------------------------------------------------------
    | Спільна робота
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Бригади
    |--------------------------------------------------------------------------
    |
    | Бригада завжди належить конкретній дільниці.
    |
    | Працівник може створити бригаду тільки на своїй дільниці.
    | Майстер дільниці також працює тільки в межах своєї stage_id.
    |
    | Працівників з інших дільниць дозволено додавати до бригади.
    | Підтвердження запрошеного працівника не потрібне.
    |
    | При зміні складу поточна work_session закривається,
    | а нова відкривається з новим складом. Завдяки цьому
    | історичний виробіток не перераховується.
    |
    */

    if ($action === 'team_start') {

        try {

            $ownerId = (int)$user['id'];
            $teamStageId = (int)$stageId;

            if ($teamStageId <= 0) {
                throw new RuntimeException(
                    'Для створення бригади необхідно бути закріпленим за дільницею.'
                );
            }

            if (!can_access_stage($teamStageId, $user)) {
                throw new RuntimeException(
                    'Ви не можете створювати бригаду на цій дільниці.'
                );
            }

            $memberIds = $_POST['member_ids'] ?? [];

            if (!is_array($memberIds)) {
                $memberIds = [];
            }

            $memberIds = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn($id) => (int)$id,
                            $memberIds
                        ),
                        static fn($id) => $id > 0
                    )
                )
            );

            /*
             * Власник бригади завжди входить до складу.
             */

            if (!in_array($ownerId, $memberIds, true)) {
                array_unshift($memberIds, $ownerId);
            }

            if (count($memberIds) < 2) {
                throw new RuntimeException(
                    'Для бригади потрібно щонайменше два працівники.'
                );
            }

            $db->beginTransaction();

            /*
             * Перевіряємо всіх учасників.
             */

            $placeholders = implode(
                ',',
                array_fill(0, count($memberIds), '?')
            );

            $membersStmt = $db->prepare("
                SELECT
                    id,
                    name,
                    stage_id,
                    role,
                    active
                FROM users
                WHERE id IN ($placeholders)
                  AND active = 1
                  AND role IN (
                      'employee',
                      'section_manager'
                  )
            ");

            $membersStmt->execute($memberIds);

            $validMembers = $membersStmt->fetchAll(
                PDO::FETCH_ASSOC
            );

            $validIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                $validMembers
            );

            sort($validIds);

            $expectedIds = $memberIds;
            sort($expectedIds);

            if ($validIds !== $expectedIds) {
                throw new RuntimeException(
                    'Один або декілька обраних працівників недоступні.'
                );
            }

            /*
             * Один працівник не може одночасно входити
             * до двох різних активних бригад НА ОДНІЙ ДІЛЬНИЦІ.
             *
             * На іншій дільниці участь допускається.
             */

            $busyStmt = $db->prepare("
                SELECT
                    u.name,
                    ws.id
                FROM work_sessions ws

                JOIN work_session_members wsm
                    ON wsm.work_session_id = ws.id

                JOIN users u
                    ON u.id = wsm.employee_id

                WHERE ws.active = 1
                  AND ws.mode = 'team'
                  AND ws.stage_id = ?
                  AND wsm.employee_id IN ($placeholders)

                LIMIT 1
            ");

            $busyStmt->execute(
                array_merge(
                    [$teamStageId],
                    $memberIds
                )
            );

            $busy = $busyStmt->fetch(PDO::FETCH_ASSOC);

            if ($busy) {
                throw new RuntimeException(
                    'Працівник '
                    . $busy['name']
                    . ' вже входить до іншої активної бригади на цій дільниці.'
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
                    :owner_employee_id,
                    :stage_id,
                    'team',
                    1,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
            ");

            $sessionStmt->execute([
                ':owner_employee_id' => $ownerId,
                ':stage_id' => $teamStageId,
            ]);

            $sessionId = (int)$db->lastInsertId();

            /*
             * Внутрішньо ділимо виробіток порівну.
             * Працівникам проценти в інтерфейсі не показуємо.
             */

            $share = 100 / count($memberIds);

            $insertMember = $db->prepare("
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
                $insertMember->execute([
                    ':session_id' => $sessionId,
                    ':employee_id' => $memberId,
                    ':share_percent' => $share,
                ]);
            }

            writeAudit(
                $db,
                $ownerId,
                'team_work_started',
                'work_session',
                $sessionId,
                null,
                [
                    'stage_id' => $teamStageId,
                    'member_ids' => $memberIds,
                ]
            );

            $db->commit();

            $_SESSION['team_flash'] = [
                'type' => 'success',
                'message' =>
                    'Бригаду створено. Учасників: '
                    . count($memberIds)
                    . '.',
            ];

        } catch (Throwable $exception) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $_SESSION['team_flash'] = [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ];
        }

        header('Location: /work.php');
        exit;
    }


    if (
        $action === 'team_add_member'
        ||
        $action === 'team_remove_member'
    ) {

        try {

            $sessionId =
                (int)($_POST['session_id'] ?? 0);

            $changedMemberId =
                (int)($_POST['employee_id'] ?? 0);

            if ($sessionId <= 0 || $changedMemberId <= 0) {
                throw new RuntimeException(
                    'Некоректні дані бригади.'
                );
            }

            $db->beginTransaction();

            $sessionStmt = $db->prepare("
                SELECT
                    id,
                    owner_employee_id,
                    stage_id
                FROM work_sessions
                WHERE id = :id
                  AND active = 1
                  AND mode = 'team'
                LIMIT 1
            ");

            $sessionStmt->execute([
                ':id' => $sessionId,
            ]);

            $session = $sessionStmt->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$session) {
                throw new RuntimeException(
                    'Активну бригаду не знайдено.'
                );
            }

            $sessionStageId =
                (int)$session['stage_id'];

            $isOwner =
                (int)$session['owner_employee_id']
                === (int)$user['id'];

            $isStageManager =
                is_section_manager($user)
                &&
                current_stage_id($user)
                === $sessionStageId;

            if (!$isOwner && !$isStageManager) {
                throw new RuntimeException(
                    'Ви не можете змінювати склад цієї бригади.'
                );
            }

            $memberStmt = $db->prepare("
                SELECT
                    wsm.employee_id,
                    u.name
                FROM work_session_members wsm
                JOIN users u
                    ON u.id = wsm.employee_id
                WHERE wsm.work_session_id = :session_id
                ORDER BY wsm.id
            ");

            $memberStmt->execute([
                ':session_id' => $sessionId,
            ]);

            $currentMembers =
                $memberStmt->fetchAll(PDO::FETCH_ASSOC);

            $currentIds = array_map(
                static fn(array $row): int =>
                    (int)$row['employee_id'],
                $currentMembers
            );

            $newIds = $currentIds;

            if ($action === 'team_add_member') {

                $employeeStmt = $db->prepare("
                    SELECT id, name
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
                    ':id' => $changedMemberId,
                ]);

                $employee =
                    $employeeStmt->fetch(PDO::FETCH_ASSOC);

                if (!$employee) {
                    throw new RuntimeException(
                        'Працівника не знайдено.'
                    );
                }

                if (in_array(
                    $changedMemberId,
                    $newIds,
                    true
                )) {
                    throw new RuntimeException(
                        'Працівник уже входить до цієї бригади.'
                    );
                }

                $busyStmt = $db->prepare("
                    SELECT ws.id
                    FROM work_sessions ws

                    JOIN work_session_members wsm
                        ON wsm.work_session_id = ws.id

                    WHERE ws.active = 1
                      AND ws.mode = 'team'
                      AND ws.stage_id = :stage_id
                      AND ws.id != :session_id
                      AND wsm.employee_id = :employee_id

                    LIMIT 1
                ");

                $busyStmt->execute([
                    ':stage_id' => $sessionStageId,
                    ':session_id' => $sessionId,
                    ':employee_id' => $changedMemberId,
                ]);

                if ($busyStmt->fetchColumn() !== false) {
                    throw new RuntimeException(
                        'Працівник уже входить до іншої бригади на цій дільниці.'
                    );
                }

                $newIds[] = $changedMemberId;

            } else {

                /*
                 * Власника не видаляємо через кнопку.
                 * Якщо власник припиняє роботу — бригада завершується.
                 */

                if (
                    $changedMemberId
                    === (int)$session['owner_employee_id']
                ) {
                    throw new RuntimeException(
                        'Власника бригади неможливо видалити. Завершіть бригаду.'
                    );
                }

                $newIds = array_values(
                    array_filter(
                        $newIds,
                        static fn(int $id): bool =>
                            $id !== $changedMemberId
                    )
                );

                if (count($newIds) < 2) {
                    throw new RuntimeException(
                        'Після видалення залишиться один працівник. У такому випадку завершіть бригаду.'
                    );
                }
            }

            /*
             * Закриваємо стару версію складу.
             */

            $closeStmt = $db->prepare("
                UPDATE work_sessions
                SET
                    active = 0,
                    ended_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");

            $closeStmt->execute([
                ':id' => $sessionId,
            ]);

            /*
             * Створюємо нову версію тієї ж бригади.
             */

            $newSessionStmt = $db->prepare("
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

            $newSessionStmt->execute([
                ':owner_id' =>
                    (int)$session['owner_employee_id'],
                ':stage_id' =>
                    $sessionStageId,
            ]);

            $newSessionId =
                (int)$db->lastInsertId();

            $share = 100 / count($newIds);

            $insertMember = $db->prepare("
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

            foreach ($newIds as $memberId) {
                $insertMember->execute([
                    ':session_id' =>
                        $newSessionId,
                    ':employee_id' =>
                        $memberId,
                    ':share_percent' =>
                        $share,
                ]);
            }

            writeAudit(
                $db,
                (int)$user['id'],
                $action === 'team_add_member'
                    ? 'team_member_added'
                    : 'team_member_removed',
                'work_session',
                $newSessionId,
                [
                    'previous_session_id' => $sessionId,
                    'member_ids' => $currentIds,
                ],
                [
                    'member_ids' => $newIds,
                    'changed_employee_id' =>
                        $changedMemberId,
                ]
            );

            $db->commit();

            $_SESSION['team_flash'] = [
                'type' => 'success',
                'message' =>
                    $action === 'team_add_member'
                        ? 'Працівника додано до бригади.'
                        : 'Працівника виведено з бригади.',
            ];

        } catch (Throwable $exception) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $_SESSION['team_flash'] = [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ];
        }

        header('Location: /work.php');
        exit;
    }


    if ($action === 'team_end') {

        try {

            $sessionId =
                (int)($_POST['session_id'] ?? 0);

            if ($sessionId <= 0) {
                throw new RuntimeException(
                    'Некоректний ID бригади.'
                );
            }

            $db->beginTransaction();

            $activeStmt = $db->prepare("
                SELECT
                    id,
                    owner_employee_id,
                    stage_id
                FROM work_sessions
                WHERE id = :id
                  AND active = 1
                  AND mode = 'team'
                LIMIT 1
            ");

            $activeStmt->execute([
                ':id' => $sessionId,
            ]);

            $session =
                $activeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new RuntimeException(
                    'Активної бригади не знайдено.'
                );
            }

            $isOwner =
                (int)$session['owner_employee_id']
                === (int)$user['id'];

            $isStageManager =
                is_section_manager($user)
                &&
                current_stage_id($user)
                === (int)$session['stage_id'];

            if (!$isOwner && !$isStageManager) {
                throw new RuntimeException(
                    'Ви не можете завершити цю бригаду.'
                );
            }

            $endStmt = $db->prepare("
                UPDATE work_sessions
                SET
                    active = 0,
                    ended_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");

            $endStmt->execute([
                ':id' => $sessionId,
            ]);

            writeAudit(
                $db,
                (int)$user['id'],
                'team_work_ended',
                'work_session',
                $sessionId,
                ['active' => 1],
                ['active' => 0]
            );

            $db->commit();

            $_SESSION['team_flash'] = [
                'type' => 'success',
                'message' =>
                    'Бригаду завершено.',
            ];

        } catch (Throwable $exception) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $_SESSION['team_flash'] = [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ];
        }

        header('Location: /work.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Сканування QR
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'scan_glass'
    ) {

        require_permission(
            'glass.scan',
            $user
        );

        $code =
            trim(
                $_POST['code']
                ?? ''
            );

        $scannedCode =
            $code;

        /*
         * ---------------------------------------------------------------
         * Порожній код.
         * ---------------------------------------------------------------
         */

        if ($code === '') {

            $scanType =
                'error';

            $scanTitle =
                'QR-код не вказано';

            $scanMessage =
                'Відскануйте QR-код скла або замовлення.';

            writeAudit(
                $db,
                (int) $user['id'],
                'scan_empty',
                null,
                null,
                null,
                [
                    'code' => '',
                ]
            );

        /*
         * ---------------------------------------------------------------
         * Службовий QR замовлення.
         * ---------------------------------------------------------------
         */

        } elseif (
            str_starts_with(
                strtoupper($code),
                'ORDER-'
            )
        ) {

            require_permission(
                'production.complete_order_stage',
                $user
            );

            $orderNumber =
                trim(
                    substr(
                        $code,
                        6
                    )
                );

            if (
                $orderNumber === ''
            ) {

                $scanType =
                    'error';

                $scanTitle =
                    'Некоректний QR замовлення';

                $scanMessage =
                    'Не вдалося визначити номер замовлення.';

            } else {

                $orderStmt =
                    $db->prepare("
                        SELECT
                            id,
                            order_number,
                            customer_name,
                            priority,
                            status
                        FROM orders
                        WHERE order_number =
                            :order_number
                        LIMIT 1
                    ");

                $orderStmt->execute([
                    ':order_number' =>
                        $orderNumber,
                ]);

                $orderQrPreview =
                    $orderStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (
                    !$orderQrPreview
                ) {

                    $scanType =
                        'error';

                    $scanTitle =
                        'Замовлення не знайдено';

                    $scanMessage =
                        'QR-код «'
                        . e($code)
                        . '» не відповідає жодному замовленню.';

                    writeAudit(
                        $db,
                        (int) $user['id'],
                        'scan_order_not_found',
                        'order',
                        null,
                        null,
                        [
                            'code' =>
                                $code,

                            'order_number' =>
                                $orderNumber,
                        ]
                    );

                } elseif (
                    $orderQrPreview[
                        'status'
                    ]
                    !==
                    'in_production'
                ) {

                    $scanType =
                        'warning';

                    $scanTitle =
                        'Замовлення не у виробництві';

                    $scanMessage =
                        'Замовлення №'
                        . e(
                            $orderQrPreview[
                                'order_number'
                            ]
                        )
                        . ' зараз не перебуває у виробництві.';

                } else {

                    $allOrderGlasses =
                        loadOrderStageGlasses(
                            $db,
                            (int)
                            $orderQrPreview['id'],
                            $stageId
                        );

                    foreach (
                        $allOrderGlasses
                        as $glass
                    ) {

                        if (
                            (int)
                            $glass[
                                'in_active_batch'
                            ]
                            === 1
                        ) {

                            $orderQrBatchCount++;

                            continue;
                        }

                        if (
                            $glass['status']
                            === 'rejected'
                        ) {

                            $orderQrRejectedCount++;

                            continue;
                        }

                        if (
                            !in_array(
                                $glass[
                                    'status'
                                ],
                                [
                                    'waiting',
                                    'in_progress',
                                ],
                                true
                            )
                        ) {

                            continue;
                        }

                        $orderQrGlasses[] =
                            $glass;

                        $orderQrArea +=
                            (
                                (int)
                                $glass['width']
                                *
                                (int)
                                $glass['height']
                                *
                                max(
                                    1,
                                    (int)
                                    $glass[
                                        'quantity'
                                    ]
                                )
                            )
                            / 1000000;
                    }

                    $scanType =
                        'order_preview';

                    $scanTitle =
                        'Замовлення №'
                        . $orderQrPreview[
                            'order_number'
                        ];

                    $scanMessage =
                        'Перевірте дані перед масовим завершенням.';
                }
            }

        /*
         * ---------------------------------------------------------------
         * Звичайний QR конкретного скла.
         * ---------------------------------------------------------------
         */

        } else {

            $stmt =
                $db->prepare("
                    SELECT
                        g.id,
                        g.code,
                        g.order_id,
                        g.order_number,
                        g.glass_type,
                        g.thickness,
                        g.width,
                        g.height,
                        g.quantity,
                        g.status,
                        g.current_step_id,
                        g.current_location,
                        g.route_id,

                        o.status AS order_status,
                        o.customer_name,
                        o.priority,
                        o.planned_date,

                        rs.id AS route_step_id,
                        rs.step_number,
                        rs.name AS stage_name,

                        ps.id AS production_stage_id,
                        ps.name AS production_stage_name

                    FROM glasses g

                    JOIN route_steps rs
                        ON rs.id =
                            g.current_step_id

                    JOIN production_stages ps
                        ON ps.name =
                            rs.name

                    LEFT JOIN orders o
                        ON o.id =
                            g.order_id

                    WHERE g.code =
                        :code

                    LIMIT 1
                ");

            $stmt->execute([
                ':code' =>
                    $code,
            ]);

            $glass =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            /*
             * Скло не знайдено.
             */

            if (!$glass) {

                $scanType =
                    'error';

                $scanTitle =
                    'Скло не знайдено';

                $scanMessage =
                    'QR-код «'
                    . e($code)
                    . '» відсутній у системі.';

                writeAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_not_found',
                    'glass',
                    null,
                    null,
                    [
                        'code' =>
                            $code,
                    ]
                );

            /*
             * Інша дільниця.
             */

            } elseif (
                (int)
                $glass[
                    'production_stage_id'
                ]
                !==
                $stageId
            ) {

                $scanType =
                    'warning';

                $scanTitle =
                    '⚠️ СКАНУВАННЯ НЕ ПРИЙНЯТО';

                $scanMessage =
                    'Скло зараз знаходиться на дільниці «'
                    . e(
                        stageLabel(
                            $glass[
                                'production_stage_name'
                            ]
                        )
                    )
                    . '», а ваша дільниця — «'
                    . e(
                        stageLabel(
                            $currentStage[
                                'name'
                            ]
                        )
                    )
                    . '».';

                writeAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_denied',
                    'glass',
                    (int) $glass['id'],
                    null,
                    [
                        'reason' =>
                            'wrong_stage',

                        'code' =>
                            $glass['code'],

                        'glass_stage_id' =>
                            (int)
                            $glass[
                                'production_stage_id'
                            ],

                        'employee_stage_id' =>
                            $stageId,
                    ]
                );

            /*
             * Замовлення не запущено.
             */

            } elseif (
                $glass[
                    'order_status'
                ]
                !==
                'in_production'
            ) {

                $scanType =
                    'warning';

                $scanTitle =
                    '⚠️ СКАНУВАННЯ НЕ ПРИЙНЯТО';

                $scanMessage =
                    'Замовлення №'
                    . e(
                        $glass[
                            'order_number'
                        ]
                    )
                    . ' не перебуває у виробництві.';

            /*
             * Некоректний статус.
             */

            } elseif (
                !in_array(
                    $glass[
                        'status'
                    ],
                    [
                        'waiting',
                        'in_progress',
                    ],
                    true
                )
            ) {

                $scanType =
                    'warning';

                $scanTitle =
                    '⚠️ СКАНУВАННЯ НЕ ПРИЙНЯТО';

                $scanMessage =
                    'Скло має статус «'
                    . e(
                        $glass[
                            'status'
                        ]
                    )
                    . '» і не може бути оброблене.';

            } else {

                /*
                 * Активна партія.
                 */

                $batchStmt =
                    $db->prepare("
                        SELECT
                            pb.id

                        FROM production_batch_items pbi

                        JOIN production_batches pb
                            ON pb.id =
                                pbi.batch_id

                        WHERE pbi.glass_id =
                            :glass_id

                          AND pb.status IN (
                              'created',
                              'in_progress'
                          )

                        LIMIT 1
                    ");

                $batchStmt->execute([
                    ':glass_id' =>
                        (int)
                        $glass['id'],
                ]);

                $activeBatchId =
                    $batchStmt
                        ->fetchColumn();

                if (
                    $activeBatchId !== false
                ) {

                    $scanType =
                        'warning';

                    $scanTitle =
                        '⚠️ СКЛО У ПАРТІЇ';

                    $scanMessage =
                        'Скло входить до активної партії №'
                        . (int)
                        $activeBatchId
                        . '. Завершіть його через сторінку партії.';

                } else {

                    try {

                        $db->beginTransaction();

                        /*
                         * Повторне читання.
                         */

                        $currentStmt =
                            $db->prepare("
                                SELECT
                                    g.id,
                                    g.code,
                                    g.order_id,
                                    g.order_number,
                                    g.status,
                                    g.width,
                                    g.height,
                                    g.quantity,
                                    g.current_step_id,
                                    g.current_location,
                                    g.route_id,

                                    o.status AS order_status,
                                    o.priority,

                                    rs.step_number,
                                    rs.name AS stage_name

                                FROM glasses g

                                JOIN route_steps rs
                                    ON rs.id =
                                        g.current_step_id

                                JOIN orders o
                                    ON o.id =
                                        g.order_id

                                WHERE g.id =
                                    :id

                                LIMIT 1
                            ");

                        $currentStmt->execute([
                            ':id' =>
                                (int)
                                $glass['id'],
                        ]);

                        $currentGlass =
                            $currentStmt->fetch(
                                PDO::FETCH_ASSOC
                            );

                        if (!$currentGlass) {

                            throw new RuntimeException(
                                'Скло більше не знайдено.'
                            );
                        }

                        if (
                            $currentGlass[
                                'order_status'
                            ]
                            !==
                            'in_production'
                        ) {

                            throw new RuntimeException(
                                'Замовлення більше не перебуває у виробництві.'
                            );
                        }

                        if (
                            !in_array(
                                $currentGlass[
                                    'status'
                                ],
                                [
                                    'waiting',
                                    'in_progress',
                                ],
                                true
                            )
                        ) {

                            throw new RuntimeException(
                                'Скло вже оброблене.'
                            );
                        }

                        /*
                         * Відвантаження завершується тільки менеджером.
                         */

                        if (
                            in_array(
                                trim(
                                    (string)
                                    $currentGlass['stage_name']
                                ),
                                [
                                    'Відвантаження',
                                    'Отгрузка',
                                ],
                                true
                            )
                        ) {
                            throw new RuntimeException(
                                'Етап «Відвантаження» завершується тільки менеджером через сторінку відвантаження.'
                            );
                        }

                        /*
                         * Наступний етап.
                         */

                        $nextStmt =
                            $db->prepare("
                                SELECT
                                    id,
                                    step_number,
                                    name
                                FROM route_steps
                                WHERE route_id =
                                    :route_id
                                  AND step_number =
                                    :step_number
                                LIMIT 1
                            ");

                        $nextStmt->execute([
                            ':route_id' =>
                                (int)
                                $currentGlass[
                                    'route_id'
                                ],

                            ':step_number' =>
                                (int)
                                $currentGlass[
                                    'step_number'
                                ]
                                + 1,
                        ]);

                        $nextStep =
                            $nextStmt->fetch(
                                PDO::FETCH_ASSOC
                            );

                        $nextStageId = null;

                        if ($nextStep) {

                            $newStatus =
                                'waiting';

                            $newStepId =
                                (int)
                                $nextStep['id'];

                            $newLocation =
                                $nextStep['name'];

                            $toStage =
                                $nextStep['name'];

                            $nextStageStmt =
                                $db->prepare("
                                    SELECT id
                                    FROM production_stages
                                    WHERE name =
                                        :name
                                      AND active = 1
                                    LIMIT 1
                                ");

                            $nextStageStmt->execute([
                                ':name' =>
                                    $nextStep[
                                        'name'
                                    ],
                            ]);

                            $nextStageId =
                                $nextStageStmt
                                    ->fetchColumn();

                            if (
                                $nextStageId !== false
                            ) {

                                $nextStageId =
                                    (int)
                                    $nextStageId;

                            } else {

                                $nextStageId =
                                    null;
                            }

                        } else {

                            $newStatus =
                                'completed';

                            $newStepId =
                                (int)
                                $currentGlass[
                                    'current_step_id'
                                ];

                            $newLocation =
                                'Готово';

                            $toStage =
                                null;
                        }

                        /*
                         * glass_operations.
                         */

                        $operationStmt =
                            $db->prepare("
                                INSERT INTO glass_operations (
                                    glass_id,
                                    employee_id,
                                    route_step_id,
                                    operation_type,
                                    from_stage,
                                    to_stage,
                                    result,
                                    batch_id,
                                    comment
                                )
                                VALUES (
                                    :glass_id,
                                    :employee_id,
                                    :route_step_id,
                                    'production',
                                    :from_stage,
                                    :to_stage,
                                    'completed',
                                    NULL,
                                    :comment
                                )
                            ");

                        $operationStmt->execute([
                            ':glass_id' =>
                                (int)
                                $currentGlass['id'],

                            ':employee_id' =>
                                (int)
                                $user['id'],

                            ':route_step_id' =>
                                (int)
                                $currentGlass[
                                    'current_step_id'
                                ],

                            ':from_stage' =>
                                $currentGlass[
                                    'stage_name'
                                ],

                            ':to_stage' =>
                                $toStage,

                            ':comment' =>
                                'Операцію завершено QR-скануванням.',
                        ]);


                        /*
                         * Фіксуємо персональний виробіток.
                         */

                        $operationId =
                            (int)
                            $db->lastInsertId();

                        recordOperationWorkers(
                            $db,
                            $operationId,
                            (int)$user['id'],
                            $stageId,
                            $currentGlass
                        );

                        /*
                         * glass_history.
                         */

                        $historyStmt =
                            $db->prepare("
                                INSERT INTO glass_history (
                                    glass_id,
                                    employee_id,
                                    old_status,
                                    new_status,
                                    old_location,
                                    new_location,
                                    comment
                                )
                                VALUES (
                                    :glass_id,
                                    :employee_id,
                                    :old_status,
                                    :new_status,
                                    :old_location,
                                    :new_location,
                                    :comment
                                )
                            ");

                        $historyStmt->execute([
                            ':glass_id' =>
                                (int)
                                $currentGlass['id'],

                            ':employee_id' =>
                                (int)
                                $user['id'],

                            ':old_status' =>
                                $currentGlass[
                                    'status'
                                ],

                            ':new_status' =>
                                $newStatus,

                            ':old_location' =>
                                $currentGlass[
                                    'current_location'
                                ],

                            ':new_location' =>
                                $newLocation,

                            ':comment' =>
                                'QR-сканування.',
                        ]);

                        /*
                         * glasses.
                         */

                        $updateStmt =
                            $db->prepare("
                                UPDATE glasses
                                SET
                                    status =
                                        :status,

                                    current_step_id =
                                        :current_step_id,

                                    current_location =
                                        :current_location,

                                    employee_id =
                                        NULL,

                                    updated_at =
                                        CURRENT_TIMESTAMP

                                WHERE id =
                                    :id
                            ");

                        $updateStmt->execute([
                            ':status' =>
                                $newStatus,

                            ':current_step_id' =>
                                $newStepId,

                            ':current_location' =>
                                $newLocation,

                            ':id' =>
                                (int)
                                $currentGlass['id'],
                        ]);

                        /*
                         * Сповіщення.
                         */

                        $notificationIds = [];

                        if (
                            $nextStageId !== null
                        ) {

                            $notificationIds =
                                notifyStage(
                                    $db,
                                    $nextStageId,
                                    'glass_moved',
                                    'Нове скло надійшло на дільницю',
                                    'Скло '
                                    . $currentGlass['code']
                                    . ' із замовлення '
                                    . $currentGlass[
                                        'order_number'
                                    ]
                                    . ' надійшло на дільницю «'
                                    . $nextStep['name']
                                    . '».',
                                    'glass',
                                    (int)
                                    $currentGlass['id']
                                );
                        }

                        writeAudit(
                            $db,
                            (int) $user['id'],
                            'scan_glass',
                            'glass',
                            (int)
                            $currentGlass['id'],
                            [
                                'status' =>
                                    $currentGlass[
                                        'status'
                                    ],

                                'current_step_id' =>
                                    (int)
                                    $currentGlass[
                                        'current_step_id'
                                    ],

                                'current_location' =>
                                    $currentGlass[
                                        'current_location'
                                    ],
                            ],
                            [
                                'status' =>
                                    $newStatus,

                                'current_step_id' =>
                                    $newStepId,

                                'current_location' =>
                                    $newLocation,

                                'notification_ids' =>
                                    $notificationIds,
                            ]
                        );

                        $db->commit();

                        /*
                         * Telegram після commit.
                         */

                        if ($nextStep) {

                            try {

                                $areaM2 =
                                    (
                                        (int)
                                        $currentGlass[
                                            'width'
                                        ]
                                        *
                                        (int)
                                        $currentGlass[
                                            'height'
                                        ]
                                        *
                                        max(
                                            1,
                                            (int)
                                            $currentGlass[
                                                'quantity'
                                            ]
                                        )
                                    )
                                    / 1000000;

                                $telegramMessage =
                                    formatTelegramGlassMoved(
                                        $currentGlass[
                                            'code'
                                        ],
                                        $currentGlass[
                                            'order_number'
                                        ],
                                        $currentGlass[
                                            'stage_name'
                                        ],
                                        $toStage,
                                        (int)
                                        $currentGlass[
                                            'priority'
                                        ],
                                        (int)
                                        $currentGlass[
                                            'width'
                                        ],
                                        (int)
                                        $currentGlass[
                                            'height'
                                        ],
                                        $areaM2
                                    );

                                $telegramResult =
                                    sendTelegramToGroup(
                                        $db,
                                        $telegramMessage
                                    );

                                writeAudit(
                                    $db,
                                    (int)
                                    $user['id'],
                                    'telegram_notification',
                                    'glass',
                                    (int)
                                    $currentGlass['id'],
                                    null,
                                    [
                                        'sent' =>
                                            $telegramResult[
                                                'sent'
                                            ]
                                            ?? false,

                                        'group' =>
                                            $telegramResult[
                                                'group_title'
                                            ]
                                            ?? null,

                                        'error' =>
                                            $telegramResult[
                                                'error'
                                            ]
                                            ?? null,
                                    ]
                                );

                            } catch (
                                Throwable
                                $telegramException
                            ) {
                                // Telegram не впливає на виробництво.
                            }
                        }

                        $scanType =
                            'success';

                        $scanTitle =
                            '✅ СКАНУВАННЯ ПРИЙНЯТО';

                        if ($nextStep) {

                            $scanMessage =
                                '<strong>'
                                . e(
                                    $currentGlass[
                                        'code'
                                    ]
                                )
                                . '</strong><br>'
                                . e(
                                    stageLabel(
                                        $currentGlass[
                                            'stage_name'
                                        ]
                                    )
                                )
                                . ' → '
                                . e(
                                    stageLabel(
                                        $toStage
                                    )
                                );

                        } else {

                            $scanMessage =
                                '<strong>'
                                . e(
                                    $currentGlass[
                                        'code'
                                    ]
                                )
                                . '</strong><br>'
                                . 'Маршрут скла завершено.';
                        }

                    } catch (
                        Throwable
                        $exception
                    ) {

                        if (
                            $db->inTransaction()
                        ) {
                            $db->rollBack();
                        }

                        $scanType =
                            'error';

                        $scanTitle =
                            '❌ ОПЕРАЦІЮ НЕ ВИКОНАНО';

                        $scanMessage =
                            e(
                                $exception
                                    ->getMessage()
                            );

                        try {

                            writeAudit(
                                $db,
                                (int)
                                $user['id'],
                                'scan_glass_error',
                                'glass',
                                (int)
                                $glass['id'],
                                null,
                                [
                                    'code' =>
                                        $code,

                                    'error' =>
                                        $exception
                                            ->getMessage(),
                                ]
                            );

                        } catch (
                            Throwable
                            $auditException
                        ) {
                        }
                    }
                }
            }
        }

    /*
    |--------------------------------------------------------------------------
    | Масове завершення замовлення
    |--------------------------------------------------------------------------
    */

    } elseif (
        $action ===
        'complete_order_stage'
    ) {

        require_permission(
            'production.complete_order_stage',
            $user
        );

        $orderId =
            (int) (
                $_POST[
                    'order_id'
                ]
                ?? 0
            );

        if (
            $orderId <= 0
        ) {

            $scanType =
                'error';

            $scanTitle =
                'Замовлення не вказано';

            $scanMessage =
                'Не вдалося визначити замовлення.';

        } else {

            try {

                $db->beginTransaction();

                /*
                 * Замовлення.
                 */

                $orderStmt =
                    $db->prepare("
                        SELECT
                            id,
                            order_number,
                            customer_name,
                            priority,
                            status
                        FROM orders
                        WHERE id = :id
                        LIMIT 1
                    ");

                $orderStmt->execute([
                    ':id' =>
                        $orderId,
                ]);

                $order =
                    $orderStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$order) {

                    throw new RuntimeException(
                        'Замовлення не знайдено.'
                    );
                }

                if (
                    $order['status']
                    !==
                    'in_production'
                ) {

                    throw new RuntimeException(
                        'Замовлення не перебуває у виробництві.'
                    );
                }

                /*
                 * Повторно читаємо скло.
                 */

                $allGlasses =
                    loadOrderStageGlasses(
                        $db,
                        $orderId,
                        $stageId
                    );

                $completedIds = [];
                $completedCodes = [];
                $completedArea = 0.0;

                foreach (
                    $allGlasses
                    as $glass
                ) {

                    /*
                     * Скло в активній партії
                     * не чіпаємо.
                     */

                    if (
                        (int)
                        $glass[
                            'in_active_batch'
                        ]
                        === 1
                    ) {
                        continue;
                    }

                    /*
                     * Брак / готове / інше
                     * не чіпаємо.
                     */

                    if (
                        !in_array(
                            $glass['status'],
                            [
                                'waiting',
                                'in_progress',
                            ],
                            true
                        )
                    ) {
                        continue;
                    }

                    /*
                     * Відвантаження не можна завершувати службовим QR.
                     */

                    if (
                        in_array(
                            trim(
                                (string)
                                $glass['stage_name']
                            ),
                            [
                                'Відвантаження',
                                'Отгрузка',
                            ],
                            true
                        )
                    ) {
                        continue;
                    }

                    /*
                     * Наступний етап.
                     */

                    $nextStmt =
                        $db->prepare("
                            SELECT
                                id,
                                step_number,
                                name
                            FROM route_steps
                            WHERE route_id =
                                :route_id
                              AND step_number =
                                :step_number
                            LIMIT 1
                        ");

                    $nextStmt->execute([
                        ':route_id' =>
                            (int)
                            $glass[
                                'route_id'
                            ],

                        ':step_number' =>
                            (int)
                            $glass[
                                'step_number'
                            ]
                            + 1,
                    ]);

                    $nextStep =
                        $nextStmt->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if ($nextStep) {

                        $newStatus =
                            'waiting';

                        $newStepId =
                            (int)
                            $nextStep['id'];

                        $newLocation =
                            $nextStep['name'];

                        $toStage =
                            $nextStep['name'];

                    } else {

                        $newStatus =
                            'completed';

                        $newStepId =
                            (int)
                            $glass[
                                'current_step_id'
                            ];

                        $newLocation =
                            'Готово';

                        $toStage =
                            null;
                    }

                    /*
                     * operation.
                     */

                    $operationStmt =
                        $db->prepare("
                            INSERT INTO glass_operations (
                                glass_id,
                                employee_id,
                                route_step_id,
                                operation_type,
                                from_stage,
                                to_stage,
                                result,
                                batch_id,
                                comment
                            )
                            VALUES (
                                :glass_id,
                                :employee_id,
                                :route_step_id,
                                'production',
                                :from_stage,
                                :to_stage,
                                'completed',
                                NULL,
                                :comment
                            )
                        ");

                    $operationStmt->execute([
                        ':glass_id' =>
                            (int)
                            $glass['id'],

                        ':employee_id' =>
                            (int)
                            $user['id'],

                        ':route_step_id' =>
                            (int)
                            $glass[
                                'current_step_id'
                            ],

                        ':from_stage' =>
                            $glass[
                                'stage_name'
                            ],

                        ':to_stage' =>
                            $toStage,

                        ':comment' =>
                            'Масове завершення QR-кодом замовлення.',
                    ]);

                    /*
                     * Фіксуємо персональний виробіток для масового завершення.
                     */

                    $operationId =
                        (int)
                        $db->lastInsertId();

                    recordOperationWorkers(
                        $db,
                        $operationId,
                        (int)$user['id'],
                        $stageId,
                        $glass
                    );

                    /*
                     * history.
                     */

                    $historyStmt =
                        $db->prepare("
                            INSERT INTO glass_history (
                                glass_id,
                                employee_id,
                                old_status,
                                new_status,
                                old_location,
                                new_location,
                                comment
                            )
                            VALUES (
                                :glass_id,
                                :employee_id,
                                :old_status,
                                :new_status,
                                :old_location,
                                :new_location,
                                :comment
                            )
                        ");

                    $historyStmt->execute([
                        ':glass_id' =>
                            (int)
                            $glass['id'],

                        ':employee_id' =>
                            (int)
                            $user['id'],

                        ':old_status' =>
                            $glass['status'],

                        ':new_status' =>
                            $newStatus,

                        ':old_location' =>
                            $glass[
                                'current_location'
                            ],

                        ':new_location' =>
                            $newLocation,

                        ':comment' =>
                            'Замовлення №'
                            . $order[
                                'order_number'
                            ]
                            . ': масове завершення.',
                    ]);

                    /*
                     * glasses.
                     */

                    $updateStmt =
                        $db->prepare("
                            UPDATE glasses
                            SET
                                status =
                                    :status,

                                current_step_id =
                                    :current_step_id,

                                current_location =
                                    :current_location,

                                employee_id =
                                    NULL,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id =
                                :id
                        ");

                    $updateStmt->execute([
                        ':status' =>
                            $newStatus,

                        ':current_step_id' =>
                            $newStepId,

                        ':current_location' =>
                            $newLocation,

                        ':id' =>
                            (int)
                            $glass['id'],
                    ]);

                    /*
                     * Сповіщення наступній дільниці.
                     */

                    if ($nextStep) {

                        $nextStageStmt =
                            $db->prepare("
                                SELECT id
                                FROM production_stages
                                WHERE name =
                                    :name
                                  AND active = 1
                                LIMIT 1
                            ");

                        $nextStageStmt->execute([
                            ':name' =>
                                $nextStep[
                                    'name'
                                ],
                        ]);

                        $nextStageId =
                            $nextStageStmt
                                ->fetchColumn();

                        if (
                            $nextStageId !== false
                        ) {

                            notifyStage(
                                $db,
                                (int)
                                $nextStageId,
                                'glass_moved',
                                'Нове скло надійшло на дільницю',
                                'Скло '
                                . $glass['code']
                                . ' із замовлення '
                                . $order[
                                    'order_number'
                                ]
                                . ' надійшло на дільницю «'
                                . $nextStep[
                                    'name'
                                ]
                                . '».',
                                'glass',
                                (int)
                                $glass['id']
                            );
                        }
                    }

                    $completedIds[] =
                        (int)
                        $glass['id'];

                    $completedCodes[] =
                        $glass['code'];

                    $completedArea +=
                        (
                            (int)
                            $glass['width']
                            *
                            (int)
                            $glass['height']
                            *
                            max(
                                1,
                                (int)
                                $glass[
                                    'quantity'
                                ]
                            )
                        )
                        / 1000000;
                }

                if (
                    !$completedIds
                ) {

                    throw new RuntimeException(
                        'На цій дільниці немає доступного скла для завершення.'
                    );
                }

                /*
                 * Один загальний audit.
                 */

                writeAudit(
                    $db,
                    (int)
                    $user['id'],
                    'complete_order_stage',
                    'order',
                    $orderId,
                    null,
                    [
                        'order_number' =>
                            $order[
                                'order_number'
                            ],

                        'stage_id' =>
                            $stageId,

                        'stage' =>
                            $currentStage[
                                'name'
                            ],

                        'completed_count' =>
                            count(
                                $completedIds
                            ),

                        'completed_area_m2' =>
                            round(
                                $completedArea,
                                3
                            ),

                        'glass_ids' =>
                            $completedIds,

                        'glass_codes' =>
                            $completedCodes,

                        'completed_by_user_id' =>
                            (int)
                            $user['id'],
                    ]
                );

                /*
                 * Перевірка завершення замовлення.
                 */

                $progressStmt =
                    $db->prepare("
                        SELECT
                            COUNT(*) AS total,

                            SUM(
                                CASE
                                    WHEN status =
                                        'completed'
                                    THEN 1
                                    ELSE 0
                                END
                            ) AS completed

                        FROM glasses

                        WHERE order_id =
                            :order_id
                    ");

                $progressStmt->execute([
                    ':order_id' =>
                        $orderId,
                ]);

                $progress =
                    $progressStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (
                    (int)
                    ($progress['total'] ?? 0)
                    > 0
                    &&
                    (int)
                    ($progress['total'] ?? 0)
                    ===
                    (int)
                    ($progress['completed'] ?? 0)
                ) {

                    $orderUpdate =
                        $db->prepare("
                            UPDATE orders
                            SET
                                status =
                                    'completed',

                                production_completed_at =
                                    CURRENT_TIMESTAMP,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id =
                                :id
                        ");

                    $orderUpdate->execute([
                        ':id' =>
                            $orderId,
                    ]);

                    writeAudit(
                        $db,
                        (int)
                        $user['id'],
                        'order_completed',
                        'order',
                        $orderId,
                        null,
                        [
                            'status' =>
                                'completed',

                            'total_glasses' =>
                                (int)
                                $progress['total'],
                        ]
                    );
                }

                $db->commit();

                /*
                 * Telegram.
                 */

                try {

                    $telegramMessage =
                        "✅ OPTIMA GLASS\n\n"
                        . "Замовлення №"
                        . $order[
                            'order_number'
                        ]
                        . "\n"
                        . "Працівник: "
                        . (
                            $user[
                                'name'
                            ]
                            ?? ''
                        )
                        . "\n"
                        . "Дільниця: "
                        . stageLabel(
                            $currentStage[
                                'name'
                            ]
                        )
                        . "\n"
                        . "Завершено стекол: "
                        . count(
                            $completedIds
                        )
                        . "\n"
                        . "Площа: "
                        . number_format(
                            $completedArea,
                            2,
                            '.',
                            ''
                        )
                        . " м²";

                    $telegramResult =
                        sendTelegramToGroup(
                            $db,
                            $telegramMessage
                        );

                    writeAudit(
                        $db,
                        (int)
                        $user['id'],
                        'telegram_notification',
                        'order',
                        $orderId,
                        null,
                        [
                            'event' =>
                                'order_stage_completed',

                            'sent' =>
                                $telegramResult[
                                    'sent'
                                ]
                                ?? false,

                            'group' =>
                                $telegramResult[
                                    'group_title'
                                ]
                                ?? null,

                            'error' =>
                                $telegramResult[
                                    'error'
                                ]
                                ?? null,
                        ]
                    );

                } catch (
                    Throwable
                    $telegramException
                ) {
                }

                $scanType =
                    'success';

                $scanTitle =
                    '✅ ЗАМОВЛЕННЯ ОБРОБЛЕНО';

                $scanMessage =
                    'Завершено стекол: '
                    . count(
                        $completedIds
                    )
                    . '. Площа: '
                    . number_format(
                        $completedArea,
                        2,
                        ',',
                        ' '
                    )
                    . ' м².';

            } catch (
                Throwable
                $exception
            ) {

                if (
                    $db->inTransaction()
                ) {
                    $db->rollBack();
                }

                $scanType =
                    'error';

                $scanTitle =
                    '❌ ОПЕРАЦІЮ НЕ ВИКОНАНО';

                $scanMessage =
                    e(
                        $exception
                            ->getMessage()
                    );
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Черга дільниці
|--------------------------------------------------------------------------
*/

$queueStmt =
    $db->prepare("
        SELECT
            g.id AS glass_id,
            g.code,
            g.order_number,
            g.glass_type,
            g.thickness,
            g.width,
            g.height,
            g.quantity,
            g.status,

            o.id AS order_id,
            o.customer_name,
            o.priority,
            o.planned_date

        FROM glasses g

        JOIN orders o
            ON o.id =
                g.order_id

        JOIN route_steps rs
            ON rs.id =
                g.current_step_id

        JOIN production_stages ps
            ON ps.name =
                rs.name

        WHERE ps.id =
            :stage_id

          AND o.status =
            'in_production'

          AND g.status =
            'waiting'

          AND NOT EXISTS (
              SELECT 1
              FROM production_batch_items pbi
              JOIN production_batches pb
                  ON pb.id =
                      pbi.batch_id
              WHERE pbi.glass_id =
                  g.id
                AND pb.status IN (
                    'created',
                    'in_progress'
                )
          )

        ORDER BY
            o.priority DESC,

            CASE
                WHEN o.planned_date IS NULL
                THEN 1
                ELSE 0
            END,

            o.planned_date ASC,

            g.created_at ASC,

            g.id ASC
    ");

$queueStmt->execute([
    ':stage_id' =>
        $stageId,
]);

$queue =
    $queueStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Мої активні партії
|--------------------------------------------------------------------------
*/

$batchStmt =
    $db->prepare("
        SELECT
            pb.id,
            pb.status,
            pb.created_at,
            pb.started_at,

            o.order_number,
            o.customer_name,
            o.priority,
            o.planned_date,

            COUNT(pbi.id)
                AS total_items,

            SUM(
                CASE
                    WHEN pbi.status =
                        'completed'
                    THEN 1
                    ELSE 0
                END
            )
                AS completed_items,

            SUM(
                CASE
                    WHEN pbi.status =
                        'rejected'
                    THEN 1
                    ELSE 0
                END
            )
                AS rejected_items

        FROM production_batches pb

        JOIN orders o
            ON o.id =
                pb.order_id

        JOIN production_batch_items pbi
            ON pbi.batch_id =
                pb.id

        WHERE pb.assigned_employee_id =
            :employee_id

          AND pb.status IN (
              'created',
              'in_progress'
          )

        GROUP BY
            pb.id,
            o.id

        ORDER BY
            o.priority DESC,
            o.planned_date ASC,
            pb.created_at ASC
    ");

$batchStmt->execute([
    ':employee_id' =>
        (int) $user['id'],
]);

$batches =
    $batchStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Площа черги
|--------------------------------------------------------------------------
*/

$queueArea = 0.0;

foreach (
    $queue
    as $item
) {

    $queueArea +=
        (
            (int)
            $item['width']
            *
            (int)
            $item['height']
            *
            max(
                1,
                (int)
                $item['quantity']
            )
        )
        / 1000000;
}


/*
|--------------------------------------------------------------------------
| Активна бригада
|--------------------------------------------------------------------------
*/

$teamFlash =
    $_SESSION['team_flash']
    ?? null;

unset($_SESSION['team_flash']);

$activeTeam = null;
$activeTeamMembers = [];

/*
 * На сторінці працівника показуємо активну бригаду
 * його поточної дільниці, до якої він входить.
 *
 * Для власника також спрацює цей самий запит,
 * оскільки власник завжди є учасником.
 */

$activeTeamStmt = $db->prepare("
    SELECT
        ws.id,
        ws.owner_employee_id,
        ws.stage_id,
        ws.started_at,
        owner.name AS owner_name
    FROM work_sessions ws

    JOIN work_session_members mine
        ON mine.work_session_id = ws.id
       AND mine.employee_id = :employee_id

    JOIN users owner
        ON owner.id = ws.owner_employee_id

    WHERE ws.active = 1
      AND ws.mode = 'team'
      AND ws.stage_id = :stage_id

    ORDER BY ws.id DESC
    LIMIT 1
");

$activeTeamStmt->execute([
    ':employee_id' => (int)$user['id'],
    ':stage_id' => (int)$stageId,
]);

$activeTeam =
    $activeTeamStmt->fetch(PDO::FETCH_ASSOC);

if ($activeTeam) {

    $activeTeamMembersStmt = $db->prepare("
        SELECT
            u.id,
            u.name,
            u.stage_id,
            ps.name AS stage_name
        FROM work_session_members wsm

        JOIN users u
            ON u.id = wsm.employee_id

        LEFT JOIN production_stages ps
            ON ps.id = u.stage_id

        WHERE wsm.work_session_id =
            :session_id

        ORDER BY
            CASE
                WHEN u.id = :owner_id THEN 0
                ELSE 1
            END,
            u.name
    ");

    $activeTeamMembersStmt->execute([
        ':session_id' =>
            (int)$activeTeam['id'],
        ':owner_id' =>
            (int)$activeTeam['owner_employee_id'],
    ]);

    $activeTeamMembers =
        $activeTeamMembersStmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/*
 * Працівники, яких можна запросити до бригади.
 *
 * Вони можуть бути закріплені за будь-якою дільницею.
 */

$teammatesStmt = $db->prepare("
    SELECT
        u.id,
        u.name,
        u.stage_id,
        ps.name AS stage_name
    FROM users u

    LEFT JOIN production_stages ps
        ON ps.id = u.stage_id

    WHERE u.active = 1
      AND u.id != :employee_id
      AND u.role IN (
          'employee',
          'section_manager'
      )

    ORDER BY
        ps.name,
        u.name
");

$teammatesStmt->execute([
    ':employee_id' =>
        (int)$user['id'],
]);

$teammates =
    $teammatesStmt->fetchAll(PDO::FETCH_ASSOC);

$currentTeamMemberIds = array_map(
    static fn(array $member): int =>
        (int)$member['id'],
    $activeTeamMembers
);

$canManageActiveTeam = false;

if ($activeTeam) {

    $canManageActiveTeam =
        (int)$activeTeam['owner_employee_id']
        === (int)$user['id']
        ||
        (
            is_section_manager($user)
            &&
            current_stage_id($user)
            === (int)$activeTeam['stage_id']
        );
}



/*
|--------------------------------------------------------------------------
| Призначено мені
|--------------------------------------------------------------------------
|
| Це НЕ обмеження доступу до роботи.
|
| Працівник як і раніше може виконувати будь-яке доступне
| скло своєї дільниці через QR / загальну чергу.
|
| Тут лише показуємо замовлення, які майстер окремо призначив:
| - безпосередньо цьому працівнику;
| - активній бригаді, учасником якої він є.
|
*/

$assignedWork = [];

$assignedWorkStmt = $db->prepare("
    SELECT
        osa.id AS assignment_id,
        osa.assignment_type,
        osa.employee_id AS responsible_employee_id,
        osa.work_session_id,

        o.id AS order_id,
        o.order_number,
        o.customer_name,
        o.priority,

        COUNT(g.id) AS glass_count,

        COALESCE(
            SUM(
                (
                    CAST(g.width AS REAL)
                    *
                    CAST(g.height AS REAL)
                    *
                    CASE
                        WHEN g.quantity IS NULL
                             OR g.quantity < 1
                        THEN 1
                        ELSE g.quantity
                    END
                ) / 1000000.0
            ),
            0
        ) AS total_area

    FROM order_stage_assignments osa

    JOIN orders o
        ON o.id = osa.order_id

    JOIN glasses g
        ON g.order_id = o.id

    JOIN route_steps rs
        ON rs.id = g.current_step_id

    JOIN production_stages ps
        ON ps.name = rs.name

    WHERE osa.active = 1

      AND osa.status IN (
          'assigned',
          'in_progress'
      )

      AND osa.stage_id = :stage_id

      AND ps.id = :stage_id

      AND g.status = 'waiting'

      AND NOT EXISTS (
          SELECT 1
          FROM production_batch_items pbi
          JOIN production_batches pb
              ON pb.id = pbi.batch_id
          WHERE pbi.glass_id = g.id
            AND pb.status IN (
                'created',
                'in_progress'
            )
      )

      AND (
          (
              osa.assignment_type = 'employee'
              AND osa.employee_id = :employee_id
          )

          OR

          (
              osa.assignment_type = 'brigade'

              AND EXISTS (
                  SELECT 1

                  FROM work_sessions ws

                  JOIN work_session_members wsm
                      ON wsm.work_session_id = ws.id

                  WHERE ws.active = 1
                    AND ws.mode = 'team'
                    AND ws.stage_id = osa.stage_id

                    /*
                     * Поки немає окремої стабільної сутності brigade_id,
                     * вважаємо продовженням тієї ж бригади активну сесію
                     * з тим самим відповідальним owner.
                     */
                    AND ws.owner_employee_id =
                        osa.employee_id

                    AND wsm.employee_id =
                        :employee_id
              )
          )
      )

    GROUP BY
        osa.id,
        osa.assignment_type,
        osa.employee_id,
        osa.work_session_id,
        o.id,
        o.order_number,
        o.customer_name,
        o.priority

    ORDER BY
        o.priority DESC,
        o.id
");

$assignedWorkStmt->execute([
    ':stage_id' => $stageId,
    ':employee_id' => (int)$user['id'],
]);

$assignedWork =
    $assignedWorkStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Скло для призначених замовлень
|--------------------------------------------------------------------------
|
| Використовуємо вже сформовану чергу поточної дільниці.
| Призначення не блокує інше скло і не змінює логіку QR.
|
*/

foreach ($assignedWork as &$assignment) {

    $assignment['glasses'] = [];

    foreach ($queue as $queueItem) {

        $sameOrder = false;

        if (
            isset($queueItem['order_id'])
            &&
            (int)$queueItem['order_id']
                === (int)$assignment['order_id']
        ) {
            $sameOrder = true;
        }

        /*
         * Резервне зіставлення за номером замовлення,
         * якщо order_id не входить у SELECT черги.
         */
        if (
            !$sameOrder
            &&
            isset($queueItem['order_number'])
            &&
            (string)$queueItem['order_number']
                === (string)$assignment['order_number']
        ) {
            $sameOrder = true;
        }

        if ($sameOrder) {
            $assignment['glasses'][] =
                $queueItem;
        }
    }
}

unset($assignment);



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
        Моя робота — OPTIMA GLASS
    </title>

    <style>
.team-card {
            border: 1px solid #dbe4f0;
            background: #ffffff;
        }

        .team-card h2 {
            margin-top: 0;
            margin-bottom: 8px;
        }
.team-members {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 14px 0 18px;
        }

        .team-member {
            min-width: 160px;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f9fafb;
        }

        .team-member-name {
            display: block;
            font-weight: 700;
        }

        .team-start-form {
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .team-select-wrap {
            flex: 1;
            min-width: 240px;
        }

        .team-select-wrap label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #374151;
        }
.scan-description {
            color: #6b7280;
            margin-bottom: 18px;
            line-height: 1.5;
        }
.scan-result {
            margin-bottom: 20px;
            padding: 18px;
            border-radius: 10px;
        }

        .scan-result.success {
            background: #dcfce7;
            color: #166534;
        }

        .scan-result.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .scan-result.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .scan-result.order_preview {
            background: #eff6ff;
            color: #1e3a8a;
        }

        .scan-result-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .order-preview-grid {
            display: grid;
            grid-template-columns:
                repeat(4, 1fr);
            gap: 10px;
            margin-top: 18px;
        }

        .preview-box {
            padding: 13px;
            border-radius: 9px;
            background: rgba(255,255,255,.65);
        }

        .preview-value {
            display: block;
            margin-top: 4px;
            font-size: 21px;
            font-weight: 700;
        }
.batch-list {
            display: grid;
            gap: 10px;
        }

        .batch-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }

        .batch-title {
            font-weight: 700;
        }

        .batch-meta {
            margin-top: 5px;
            color: #6b7280;
            font-size: 13px;
        }
table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }
@media (
            max-width: 750px
        ) {
.summary,
            .order-preview-grid {
                grid-template-columns: 1fr 1fr;
            }

            .batch-item {
                flex-direction: column;
                align-items: stretch;
            }

        }

        @media (
            max-width: 500px
        ) {

            .summary,
            .order-preview-grid {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<?php
require __DIR__
    . '/../src/partials/header.php';
?>

<main class="work-page">

    <header class="work-header">

        <h1>
            Моя робота
        </h1>

        <div class="muted">

            <?= e(
                $user['name']
                ?? ''
            ) ?>

            ·

            <?= e(
                stageLabel(
                    $currentStage[
                        'name'
                    ]
                )
            ) ?>

        </div>

    </header>


    <?php if ($teamFlash): ?>

        <div
            class="team-flash <?= e(
                $teamFlash['type']
                ?? 'success'
            ) ?>"
        >
            <?= e(
                $teamFlash['message']
                ?? ''
            ) ?>
        </div>

    <?php endif; ?>


    <section class="card team-card">

        <h2>
            Бригада
        </h2>

        <?php if ($activeTeam): ?>

            <div class="team-status">
                <span class="team-dot"></span>

                <strong>
                    Бригада працює
                </strong>
            </div>

            <div class="muted">
                Дільниця:
                <strong>
                    <?= e(
                        stageLabel(
                            $currentStage['name']
                        )
                    ) ?>
                </strong>

                · відповідальний:
                <strong>
                    <?= e(
                        $activeTeam['owner_name']
                        ?? ''
                    ) ?>
                </strong>
            </div>

            <div class="team-members">

                <?php foreach (
                    $activeTeamMembers
                    as $member
                ): ?>

                    <div class="team-member">

                        <span class="team-member-name">
                            <?= e(
                                $member['name']
                            ) ?>
                        </span>

                        <?php if (
                            !empty(
                                $member['stage_name']
                            )
                        ): ?>

                            <span class="muted">
                                <?= e(
                                    stageLabel(
                                        $member['stage_name']
                                    )
                                ) ?>
                            </span>

                        <?php endif; ?>

                        <?php if (
                            $canManageActiveTeam
                            &&
                            (int)$member['id']
                            !== (int)$activeTeam[
                                'owner_employee_id'
                            ]
                        ): ?>

                            <form
                                method="post"
                                style="margin-top:8px"
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
                                    value="team_remove_member"
                                >

                                <input
                                    type="hidden"
                                    name="session_id"
                                    value="<?= (int)
                                        $activeTeam['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="employee_id"
                                    value="<?= (int)
                                        $member['id'] ?>"
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

            <div class="muted">
                Нові операції на цій дільниці
                зараховуються поточному складу бригади.
            </div>

            <?php if (
                $canManageActiveTeam
                &&
                $teammates
            ): ?>

                <form
                    method="post"
                    class="team-start-form"
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
                        value="team_add_member"
                    >

                    <input
                        type="hidden"
                        name="session_id"
                        value="<?= (int)
                            $activeTeam['id'] ?>"
                    >

                    <div class="team-select-wrap">

                        <label for="team_add_employee">
                            Додати працівника
                        </label>

                        <select
                            name="employee_id"
                            id="team_add_employee"
                            class="team-select"
                            required
                        >

                            <option value="">
                                Оберіть працівника
                            </option>

                            <?php foreach (
                                $teammates
                                as $teammate
                            ): ?>

                                <?php if (
                                    in_array(
                                        (int)$teammate['id'],
                                        $currentTeamMemberIds,
                                        true
                                    )
                                ) {
                                    continue;
                                } ?>

                                <option
                                    value="<?= (int)
                                        $teammate['id'] ?>"
                                >
                                    <?= e(
                                        $teammate['name']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $teammate[
                                                'stage_name'
                                            ]
                                        )
                                    ): ?>
                                        —
                                        <?= e(
                                            stageLabel(
                                                $teammate[
                                                    'stage_name'
                                                ]
                                            )
                                        ) ?>
                                    <?php endif; ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <button
                        type="submit"
                        class="button"
                    >
                        Додати
                    </button>

                </form>

            <?php endif; ?>

            <?php if ($canManageActiveTeam): ?>

                <form
                    method="post"
                    class="actions"
                    style="margin-top:16px"
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
                        value="team_end"
                    >

                    <input
                        type="hidden"
                        name="session_id"
                        value="<?= (int)
                            $activeTeam['id'] ?>"
                    >

                    <button
                        type="submit"
                        class="button button-secondary"
                        onclick="return confirm('Завершити роботу бригади?');"
                    >
                        Завершити бригаду
                    </button>

                </form>

            <?php endif; ?>

        <?php else: ?>

            <div class="team-status">
                <span class="team-dot solo"></span>

                <strong>
                    Працюю сам
                </strong>
            </div>

            <div class="muted">
                Якщо на вашій дільниці працюєте разом,
                створіть бригаду та оберіть усіх учасників.
            </div>

            <?php if ($teammates): ?>

                <form
                    method="post"
                    class="team-start-form"
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
                        value="team_start"
                    >

                    <div class="team-select-wrap">

                        <label for="member_ids">
                            Учасники бригади
                        </label>

                        <select
                            name="member_ids[]"
                            id="member_ids"
                            class="team-select"
                            multiple
                            size="6"
                            required
                        >

                            <?php foreach (
                                $teammates
                                as $teammate
                            ): ?>

                                <option
                                    value="<?= (int)
                                        $teammate['id'] ?>"
                                >
                                    <?= e(
                                        $teammate['name']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $teammate[
                                                'stage_name'
                                            ]
                                        )
                                    ): ?>
                                        —
                                        <?= e(
                                            stageLabel(
                                                $teammate[
                                                    'stage_name'
                                                ]
                                            )
                                        ) ?>
                                    <?php endif; ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="muted" style="margin-top:6px">
                            Можна обрати декількох працівників.
                            Ви додаєтесь до бригади автоматично.
                        </div>

                    </div>

                    <button
                        type="submit"
                        class="button"
                    >
                        Створити бригаду
                    </button>

                </form>

            <?php endif; ?>

        <?php endif; ?>

    </section>


    <section class="card">

        <h2>
            Сканування
        </h2>

        <div class="scan-description">

            Відскануйте QR-код конкретного скла
            або службовий QR замовлення.

            <br>

            QR замовлення має формат:
            <strong>ORDER-номер</strong>.

        </div>


        <?php if (
            $scanType !== ''
        ): ?>

            <div
                class="scan-result <?= e(
                    $scanType
                ) ?>"
            >

                <div class="scan-result-title">

                    <?= e(
                        $scanTitle
                    ) ?>

                </div>

                <div>
                    <?= $scanMessage ?>
                </div>


                <?php if (
                    $scanType ===
                    'order_preview'
                    &&
                    $orderQrPreview
                ): ?>

                    <div
                        style="margin-top:12px;"
                    >

                        Клієнт:
                        <strong>
                            <?= e(
                                $orderQrPreview[
                                    'customer_name'
                                ]
                                ?? '—'
                            ) ?>
                        </strong>

                        <br>

                        Дільниця:
                        <strong>
                            <?= e(
                                stageLabel(
                                    $currentStage[
                                        'name'
                                    ]
                                )
                            ) ?>
                        </strong>

                        <br>

                        Пріоритет:
                        <strong>
                            <?= e(
                                priorityLabel(
                                    (int)
                                    $orderQrPreview[
                                        'priority'
                                    ]
                                )
                            ) ?>
                        </strong>

                    </div>


                    <div class="order-preview-grid">

                        <div class="preview-box">

                            Доступно

                            <span class="preview-value">
                                <?= count(
                                    $orderQrGlasses
                                ) ?>
                            </span>

                        </div>

                        <div class="preview-box">

                            Площа

                            <span class="preview-value">

                                <?= number_format(
                                    $orderQrArea,
                                    2,
                                    ',',
                                    ' '
                                ) ?>

                                м²

                            </span>

                        </div>

                        <div class="preview-box">

                            У партіях

                            <span class="preview-value">
                                <?= $orderQrBatchCount ?>
                            </span>

                        </div>

                        <div class="preview-box">

                            Брак

                            <span class="preview-value">
                                <?= $orderQrRejectedCount ?>
                            </span>

                        </div>

                    </div>


                    <?php if (
                        $orderQrGlasses
                    ): ?>

                        <form
                            method="post"
                            class="actions"
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
                                value="complete_order_stage"
                            >

                            <input
                                type="hidden"
                                name="order_id"
                                value="<?= (int)
                                    $orderQrPreview[
                                        'id'
                                    ] ?>"
                            >

                            <button
                                type="submit"
                                class="button"
                                onclick="return confirm('Завершити всі доступні стекла цього замовлення на поточній дільниці?');"
                            >

                                ✅ Завершити
                                <?= count(
                                    $orderQrGlasses
                                ) ?>
                                стекол

                            </button>

                            <a
                                href="/work.php"
                                class="button button-secondary"
                            >
                                Скасувати
                            </a>

                        </form>

                    <?php else: ?>

                        <div
                            style="margin-top:15px;"
                        >

                            На цій дільниці немає
                            доступного скла для масового завершення.

                        </div>

                    <?php endif; ?>

                <?php elseif (
                    $scanType ===
                    'success'
                    &&
                    $scannedCode !== ''
                    &&
                    !str_starts_with(
                        strtoupper(
                            $scannedCode
                        ),
                        'ORDER-'
                    )
                ): ?>

                    <div class="actions">

                        <?php if (
                            $canReject
                        ): ?>

                            <a
                                href="/reject_glass.php?code=<?= urlencode(
                                    $scannedCode
                                ) ?>"
                                class="button button-danger"
                            >
                                ❌ Оформити брак
                            </a>

                        <?php endif; ?>

                        <a
                            href="/work.php"
                            class="button button-secondary"
                        >
                            Готово
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>


        <?php if (
            $canScan
        ): ?>

            <form
                method="post"
                class="scan-form"
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
                    value="scan_glass"
                >

                <input
                    type="text"
                    name="code"
                    class="scan-input"
                    placeholder="QR скла або ORDER-номер"
                    autocomplete="off"
                    autofocus
                    required
                >

                <button
                    type="submit"
                    class="button"
                >
                    Сканувати
                </button>

            </form>

        <?php endif; ?>

    </section>


    <section class="card">

        <div class="summary">

            <div class="summary-box">

                Скло в черзі

                <span class="summary-value">
                    <?= count($queue) ?>
                </span>

            </div>

            <div class="summary-box">

                Площа черги

                <span class="summary-value">

                    <?= number_format(
                        $queueArea,
                        2,
                        ',',
                        ' '
                    ) ?>

                    м²

                </span>

            </div>

            <div class="summary-box">

                Мої активні партії

                <span class="summary-value">
                    <?= count(
                        $batches
                    ) ?>
                </span>

            </div>

        </div>

    </section>



    <section class="card">

        <h2>
            Призначено мені
        </h2>

        <div
            style="
                margin-bottom:14px;
                color:#64748b;
                font-size:14px;
            "
        >
            Завдання, які майстер призначив вам
            особисто або вашій активній бригаді.
            Інше доступне скло дільниці можна
            виконувати через загальну чергу.
        </div>

        <?php if (
            !$assignedWork
        ): ?>

            <div class="empty">
                Окремо призначених завдань немає.
            </div>

        <?php else: ?>

            <div class="batch-list">

                <?php foreach (
                    $assignedWork
                    as $assignment
                ): ?>

                    <div class="batch-item">

                        <div>

                            <div class="batch-title">

                                Замовлення
                                <?= e(
                                    $assignment[
                                        'order_number'
                                    ]
                                ) ?>

                                ·

                                <?= e(
                                    priorityLabel(
                                        (int)
                                        $assignment[
                                            'priority'
                                        ]
                                    )
                                ) ?>

                            </div>

                            <div class="batch-meta">

                                <?php if (
                                    !empty(
                                        $assignment[
                                            'customer_name'
                                        ]
                                    )
                                ): ?>

                                    <?= e(
                                        $assignment[
                                            'customer_name'
                                        ]
                                    ) ?>

                                    ·

                                <?php endif; ?>

                                Скло:
                                <?= (int)
                                    $assignment[
                                        'glass_count'
                                    ] ?>

                                · Площа:

                                <?= number_format(
                                    (float)
                                    $assignment[
                                        'total_area'
                                    ],
                                    2,
                                    ',',
                                    ' '
                                ) ?>

                                м²

                                ·

                                <?php if (
                                    $assignment[
                                        'assignment_type'
                                    ] === 'brigade'
                                ): ?>

                                    Бригада

                                <?php else: ?>

                                    Особисто вам

                                <?php endif; ?>

                            </div>

                            <?php if (
                                !empty(
                                    $assignment[
                                        'glasses'
                                    ]
                                )
                            ): ?>

                                <details
                                    style="
                                        margin-top:12px;
                                    "
                                >

                                    <summary
                                        style="
                                            cursor:pointer;
                                            font-weight:600;
                                        "
                                    >
                                        Показати скло
                                        (<?= count(
                                            $assignment[
                                                'glasses'
                                            ]
                                        ) ?>)
                                    </summary>

                                    <div
                                        class="table-wrap"
                                        style="
                                            margin-top:12px;
                                        "
                                    >

                                        <table>

                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Скло</th>
                                                    <th>Розмір</th>
                                                    <th>Товщина</th>
                                                    <th>Площа</th>
                                                </tr>
                                            </thead>

                                            <tbody>

                                            <?php foreach (
                                                $assignment[
                                                    'glasses'
                                                ]
                                                as $glassIndex =>
                                                    $glass
                                            ): ?>

                                                <tr>

                                                    <td>
                                                        <?= $glassIndex + 1 ?>
                                                    </td>

                                                    <td>

                                                        <strong>
                                                            <?= e(
                                                                $glass[
                                                                    'code'
                                                                ]
                                                            ) ?>
                                                        </strong>

                                                        <?php if (
                                                            !empty(
                                                                $glass[
                                                                    'glass_type'
                                                                ]
                                                            )
                                                        ): ?>

                                                            <br>

                                                            <small>
                                                                <?= e(
                                                                    $glass[
                                                                        'glass_type'
                                                                    ]
                                                                ) ?>
                                                            </small>

                                                        <?php endif; ?>

                                                    </td>

                                                    <td>
                                                        <?= (int)
                                                            $glass[
                                                                'width'
                                                            ] ?>
                                                        ×
                                                        <?= (int)
                                                            $glass[
                                                                'height'
                                                            ] ?>
                                                        мм
                                                    </td>

                                                    <td>

                                                        <?= $glass[
                                                            'thickness'
                                                        ] !== null
                                                            ? e(
                                                                (string)
                                                                $glass[
                                                                    'thickness'
                                                                ]
                                                            ) . ' мм'
                                                            : '—'
                                                        ?>

                                                    </td>

                                                    <td>

                                                        <?= number_format(
                                                            (
                                                                (int)
                                                                $glass[
                                                                    'width'
                                                                ]
                                                                *
                                                                (int)
                                                                $glass[
                                                                    'height'
                                                                ]
                                                                *
                                                                max(
                                                                    1,
                                                                    (int)
                                                                    $glass[
                                                                        'quantity'
                                                                    ]
                                                                )
                                                            )
                                                            / 1000000,
                                                            2,
                                                            ',',
                                                            ' '
                                                        ) ?>

                                                        м²

                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                            </tbody>

                                        </table>

                                    </div>

                                </details>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <section class="card">

        <h2>
            Мої партії
        </h2>

        <?php if (
            !$batches
        ): ?>

            <div class="empty">

                Вам не призначено активних партій.

            </div>

        <?php else: ?>

            <div class="batch-list">

                <?php foreach (
                    $batches
                    as $batch
                ): ?>

                    <?php

                    $total =
                        (int)
                        $batch[
                            'total_items'
                        ];

                    $completed =
                        (int)
                        $batch[
                            'completed_items'
                        ];

                    $rejected =
                        (int)
                        $batch[
                            'rejected_items'
                        ];

                    $remaining =
                        max(
                            0,
                            $total
                            -
                            $completed
                            -
                            $rejected
                        );

                    ?>

                    <div class="batch-item">

                        <div>

                            <div class="batch-title">

                                Партія №
                                <?= (int)
                                    $batch['id'] ?>

                                ·

                                <?= e(
                                    $batch[
                                        'order_number'
                                    ]
                                ) ?>

                                ·

                                <?= e(
                                    priorityLabel(
                                        (int)
                                        $batch[
                                            'priority'
                                        ]
                                    )
                                ) ?>

                            </div>

                            <div class="batch-meta">

                                Всього:
                                <?= $total ?>

                                · Готово:
                                <?= $completed ?>

                                · Брак:
                                <?= $rejected ?>

                                · Залишилось:
                                <?= $remaining ?>

                            </div>

                        </div>

                        <a
                            href="/batch.php?id=<?= (int)
                                $batch[
                                    'id'
                                ] ?>"
                            class="button button-secondary"
                        >
                            Відкрити партію
                        </a>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <section class="card">

        <h2>

            Черга —
            <?= e(
                stageLabel(
                    $currentStage[
                        'name'
                    ]
                )
            ) ?>

        </h2>

        <?php if (
            !$queue
        ): ?>

            <div class="empty">

                На дільниці немає доступного скла.

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>#</th>
                            <th>Скло</th>
                            <th>Замовлення</th>
                            <th>Пріоритет</th>
                            <th>Розмір</th>
                            <th>Товщина</th>
                            <th>Площа</th>

                            <?php if (
                                $canReject
                            ): ?>

                                <th>Дія</th>

                            <?php endif; ?>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach (
                        $queue
                        as $index =>
                            $item
                    ): ?>

                        <tr>

                            <td>
                                <?= $index + 1 ?>
                            </td>

                            <td>

                                <strong>
                                    <?= e(
                                        $item[
                                            'code'
                                        ]
                                    ) ?>
                                </strong>

                                <?php if (
                                    !empty(
                                        $item[
                                            'glass_type'
                                        ]
                                    )
                                ): ?>

                                    <br>

                                    <small>
                                        <?= e(
                                            $item[
                                                'glass_type'
                                            ]
                                        ) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?= e(
                                    $item[
                                        'order_number'
                                    ]
                                ) ?>

                                <?php if (
                                    !empty(
                                        $item[
                                            'customer_name'
                                        ]
                                    )
                                ): ?>

                                    <br>

                                    <small>
                                        <?= e(
                                            $item[
                                                'customer_name'
                                            ]
                                        ) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?= e(
                                    priorityLabel(
                                        (int)
                                        $item[
                                            'priority'
                                        ]
                                    )
                                ) ?>

                            </td>

                            <td>

                                <?= (int)
                                    $item[
                                        'width'
                                    ] ?>

                                ×

                                <?= (int)
                                    $item[
                                        'height'
                                    ] ?>

                                мм

                            </td>

                            <td>

                                <?= $item[
                                    'thickness'
                                ] !== null
                                    ? e(
                                        (string)
                                        $item[
                                            'thickness'
                                        ]
                                    ) . ' мм'
                                    : '—'
                                ?>

                            </td>

                            <td>

                                <?= number_format(
                                    (
                                        (int)
                                        $item[
                                            'width'
                                        ]
                                        *
                                        (int)
                                        $item[
                                            'height'
                                        ]
                                        *
                                        max(
                                            1,
                                            (int)
                                            $item[
                                                'quantity'
                                            ]
                                        )
                                    )
                                    / 1000000,
                                    2,
                                    ',',
                                    ' '
                                ) ?>

                                м²

                            </td>

                            <?php if (
                                $canReject
                            ): ?>

                                <td>

                                    <a
                                        href="/reject_glass.php?code=<?= urlencode(
                                            $item[
                                                'code'
                                            ]
                                        ) ?>"
                                        class="button button-danger"
                                    >
                                        ❌ Брак
                                    </a>

                                </td>

                            <?php endif; ?>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>

</html>
