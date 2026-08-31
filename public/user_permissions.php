<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

/*
|--------------------------------------------------------------------------
| Только суперадмин
|--------------------------------------------------------------------------
*/

if (!isSuperAdmin($user)) {
    http_response_code(403);
    exit(
        'Доступ запрещён. Только суперадминистратор может управлять индивидуальными правами.'
    );
}

require_permission(
    'user_permissions.manage',
    $user
);

/*
|--------------------------------------------------------------------------
| Helpers
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

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_user_permissions'])) {
    $_SESSION['csrf_user_permissions'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken =
    $_SESSION['csrf_user_permissions'];

$error = '';
$success = '';

$selectedUserId =
    (int) (
        $_GET['user_id']
        ?? $_POST['user_id']
        ?? 0
    );

/*
|--------------------------------------------------------------------------
| Пользователи
|--------------------------------------------------------------------------
|
| Суперадмина здесь не показываем для изменения
| индивидуальных прав.
|
*/

$usersStmt = $db->query("
    SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        u.active,
        u.stage_id,

        r.title AS role_title,

        ps.name AS stage_name

    FROM users u

    LEFT JOIN roles r
        ON r.name = u.role

    LEFT JOIN production_stages ps
        ON ps.id = u.stage_id

    WHERE u.active = 1
      AND u.role <> 'superadmin'

    ORDER BY
        u.name ASC,
        u.id ASC
");

$users =
    $usersStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

if (
    !$selectedUserId
    &&
    $users
) {
    $selectedUserId =
        (int) $users[0]['id'];
}

/*
|--------------------------------------------------------------------------
| Выбранный пользователь
|--------------------------------------------------------------------------
*/

$selectedUser = null;

foreach ($users as $item) {

    if (
        (int) $item['id']
        ===
        $selectedUserId
    ) {
        $selectedUser = $item;
        break;
    }
}

if (!$selectedUser) {

    http_response_code(404);

    exit(
        'Пользователь не найден.'
    );
}

/*
|--------------------------------------------------------------------------
| Получаем все активные права
|--------------------------------------------------------------------------
*/

$permissionsStmt =
    $db->query("
        SELECT
            id,
            code,
            title,
            category
        FROM permissions
        WHERE active = 1
        ORDER BY
            category,
            id
    ");

$permissions =
    $permissionsStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Сохранение индивидуальных прав
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    if (
        !hash_equals(
            $csrfToken,
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $error =
            'Ошибка проверки безопасности.';

    } else {

        $action =
            $_POST['action'] ?? '';

        if (
            $action
            ===
            'save_user_permissions'
        ) {

            $postedPermissions =
                $_POST['permissions']
                ?? [];

            try {

                $db->beginTransaction();

                foreach (
                    $permissions
                    as $permission
                ) {

                    $permissionId =
                        (int)
                        $permission['id'];

                    /*
                     * Значения формы:
                     *
                     * inherit  = NULL
                     * allow    = 1
                     * deny     = 0
                     */

                    $value =
                        $postedPermissions[
                            $permissionId
                        ]
                        ??
                        'inherit';

                    if (
                        $value === 'allow'
                    ) {

                        $newAllowed = 1;

                    } elseif (
                        $value === 'deny'
                    ) {

                        $newAllowed = 0;

                    } else {

                        $newAllowed = null;
                    }

                    /*
                     * Старое индивидуальное право.
                     */

                    $oldStmt =
                        $db->prepare("
                            SELECT
                                allowed
                            FROM user_permissions
                            WHERE user_id =
                                :user_id
                              AND permission_id =
                                :permission_id
                            LIMIT 1
                        ");

                    $oldStmt->execute([
                        ':user_id' =>
                            $selectedUserId,

                        ':permission_id' =>
                            $permissionId,
                    ]);

                    $oldValue =
                        $oldStmt->fetchColumn();

                    /*
                     * false означает,
                     * что записи ещё нет.
                     */

                    $oldExists =
                        $oldValue !== false;

                    if ($oldExists) {

                        $oldValue =
                            $oldValue === null
                                ? null
                                : (int) $oldValue;
                    } else {

                        /*
                         * Отсутствующая запись =
                         * наследование роли.
                         */

                        $oldValue = null;
                    }

                    /*
                     * Ничего не изменилось.
                     */

                    if (
                        $oldValue ===
                        $newAllowed
                        &&
                        $oldExists
                    ) {

                        continue;
                    }

                    /*
                     * Если итоговое состояние =
                     * "По роли", удаляем запись.
                     *
                     * Это держит user_permissions
                     * чистой.
                     */

                    if (
                        $newAllowed === null
                    ) {

                        if ($oldExists) {

                            $delete =
                                $db->prepare("
                                    DELETE FROM user_permissions
                                    WHERE user_id =
                                        :user_id
                                      AND permission_id =
                                        :permission_id
                                ");

                            $delete->execute([
                                ':user_id' =>
                                    $selectedUserId,

                                ':permission_id' =>
                                    $permissionId,
                            ]);
                        }

                    } else {

                        /*
                         * Разрешить / Запретить.
                         */

                        $upsert =
                            $db->prepare("
                                INSERT INTO user_permissions (
                                    user_id,
                                    permission_id,
                                    allowed,
                                    created_at,
                                    updated_at
                                )
                                VALUES (
                                    :user_id,
                                    :permission_id,
                                    :allowed,
                                    CURRENT_TIMESTAMP,
                                    CURRENT_TIMESTAMP
                                )

                                ON CONFLICT(
                                    user_id,
                                    permission_id
                                )

                                DO UPDATE SET
                                    allowed =
                                        excluded.allowed,

                                    updated_at =
                                        CURRENT_TIMESTAMP
                            ");

                        $upsert->execute([
                            ':user_id' =>
                                $selectedUserId,

                            ':permission_id' =>
                                $permissionId,

                            ':allowed' =>
                                $newAllowed,
                        ]);
                    }

                    /*
                     * Audit.
                     */

                    auditPermissionChange(
                        $db,
                        (int) $user['id'],
                        'user_permission_changed',
                        'user',
                        $selectedUserId,
                        $permission['code'],
                        $oldValue,
                        $newAllowed
                    );
                }

                $db->commit();

                $success =
                    'Индивидуальные права пользователя «'
                    . $selectedUser['name']
                    . '» сохранены.';

            } catch (
                Throwable $exception
            ) {

                if (
                    $db->inTransaction()
                ) {
                    $db->rollBack();
                }

                $error =
                    'Не удалось сохранить права: '
                    . $exception->getMessage();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Текущие индивидуальные права
|--------------------------------------------------------------------------
*/

$userPermissionsStmt =
    $db->prepare("
        SELECT
            permission_id,
            allowed
        FROM user_permissions
        WHERE user_id =
            :user_id
    ");

$userPermissionsStmt->execute([
    ':user_id' =>
        $selectedUserId,
]);

$userPermissions = [];

foreach (
    $userPermissionsStmt->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $row
) {

    $userPermissions[
        (int) $row['permission_id']
    ] =
        $row['allowed'] === null
            ? null
            : (int) $row['allowed'];
}

/*
|--------------------------------------------------------------------------
| Эффективные права роли
|--------------------------------------------------------------------------
*/

$rolePermissionsStmt =
    $db->prepare("
        SELECT
            p.id,
            rp.allowed
        FROM permissions p

        LEFT JOIN role_permissions rp
            ON rp.permission_id = p.id

           AND rp.role_id = (
                SELECT id
                FROM roles
                WHERE name = :role
                LIMIT 1
           )

        WHERE p.active = 1
    ");

$rolePermissionsStmt->execute([
    ':role' =>
        $selectedUser['role'],
]);

$rolePermissions = [];

foreach (
    $rolePermissionsStmt->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $row
) {

    $rolePermissions[
        (int) $row['id']
    ] =
        $row['allowed'] === null
            ? false
            : (int) $row['allowed'] === 1;
}

/*
|--------------------------------------------------------------------------
| Группировка прав
|--------------------------------------------------------------------------
*/

$permissionsByCategory = [];

foreach (
    $permissions
    as $permission
) {

    $category =
        $permission['category'];

    if (
        !isset(
            $permissionsByCategory[
                $category
            ]
        )
    ) {

        $permissionsByCategory[
            $category
        ] = [];
    }

    $permissionsByCategory[
        $category
    ][] =
        $permission;
}

?>
<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Индивидуальные права — OPTIMA GLASS
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            font-family: Arial, sans-serif;
        }

        .permissions-page {
            max-width: 1250px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin-bottom: 7px;
        }

        .subtitle {
            color: #6b7280;
        }

        .layout {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            gap: 20px;
        }

        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
        }

        .users-list {
            overflow: hidden;
        }

        .user-link {
            display: block;
            padding: 15px 16px;
            border-bottom: 1px solid #e5e7eb;
            color: #111827;
            text-decoration: none;
        }

        .user-link:last-child {
            border-bottom: 0;
        }

        .user-link:hover {
            background: #f9fafb;
        }

        .user-link.active {
            background: #111827;
            color: #fff;
        }

        .user-name {
            font-weight: 700;
        }

        .user-role {
            margin-top: 4px;
            font-size: 12px;
            opacity: .7;
        }

        .permissions-card {
            padding: 25px;
        }

        .selected-user {
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .selected-user h2 {
            margin: 0 0 6px;
        }

        .selected-user-meta {
            color: #6b7280;
            font-size: 13px;
        }

        .message {
            margin-bottom: 18px;
            padding: 13px 15px;
            border-radius: 9px;
        }

        .message-success {
            background: #dcfce7;
            color: #166534;
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .info-box {
            margin-bottom: 20px;
            padding: 13px 15px;
            border-radius: 9px;
            background: #f3f4f6;
            color: #4b5563;
            font-size: 13px;
            line-height: 1.5;
        }

        .permission-category {
            margin-bottom: 25px;
        }

        .permission-category h3 {
            margin: 0 0 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .permission-row {
            display: grid;
            grid-template-columns:
                minmax(0, 1fr)
                190px;

            gap: 20px;
            align-items: center;

            padding: 12px 0;

            border-bottom:
                1px solid #f3f4f6;
        }

        .permission-title {
            font-weight: 600;
        }

        .permission-code {
            margin-top: 3px;
            color: #6b7280;
            font-size: 12px;
        }

        .permission-role {
            margin-top: 6px;
            color: #6b7280;
            font-size: 12px;
        }

        .permission-role strong {
            color: #374151;
        }

        .permission-select {
            width: 100%;
            min-height: 40px;
            padding: 0 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
        }

        .save-bar {
            position: sticky;
            bottom: 15px;

            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 15px;

            margin-top: 25px;
            padding: 15px;

            border: 1px solid #e5e7eb;
            border-radius: 10px;

            background: rgba(
                255,
                255,
                255,
                .96
            );

            box-shadow:
                0 8px 25px
                rgba(
                    0,
                    0,
                    0,
                    .08
                );
        }

        .save-button {
            min-height: 44px;
            padding: 0 20px;
            border: 0;
            border-radius: 9px;
            background: #111827;
            color: #fff;
            cursor: pointer;
            font-weight: 700;
        }

        @media (
            max-width: 850px
        ) {

            .layout {
                grid-template-columns: 1fr;
            }

            .permission-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .save-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .save-button {
                width: 100%;
            }

        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="permissions-page">

    <header class="page-header">

        <h1>
            Индивидуальные права
        </h1>

        <div class="subtitle">
            Индивидуальные исключения поверх прав роли
        </div>

    </header>


    <?php if ($error !== ''): ?>

        <div class="message message-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($success !== ''): ?>

        <div class="message message-success">
            <?= e($success) ?>
        </div>

    <?php endif; ?>


    <div class="layout">

        <aside class="card users-list">

            <?php foreach ($users as $item): ?>

                <a
                    href="/user_permissions.php?user_id=<?= (int) $item['id'] ?>"
                    class="user-link <?= (int) $item['id'] === $selectedUserId
                        ? 'active'
                        : '' ?>"
                >

                    <div class="user-name">

                        <?= e(
                            $item['name']
                        ) ?>

                    </div>

                    <div class="user-role">

                        <?= e(
                            $item['role_title']
                            ?? $item['role']
                        ) ?>

                        <?php if (
                            !empty(
                                $item['stage_name']
                            )
                        ): ?>

                            ·
                            <?= e(
                                $item[
                                    'stage_name'
                                ]
                            ) ?>

                        <?php endif; ?>

                    </div>

                </a>

            <?php endforeach; ?>

        </aside>


        <section class="card permissions-card">

            <div class="selected-user">

                <h2>

                    <?= e(
                        $selectedUser[
                            'name'
                        ]
                    ) ?>

                </h2>

                <div class="selected-user-meta">

                    Роль:
                    <strong>
                        <?= e(
                            $selectedUser[
                                'role_title'
                            ]
                            ?? $selectedUser[
                                'role'
                            ]
                        ) ?>
                    </strong>

                    <?php if (
                        !empty(
                            $selectedUser[
                                'stage_name'
                            ]
                        )
                    ): ?>

                        · Участок:
                        <strong>
                            <?= e(
                                $selectedUser[
                                    'stage_name'
                                ]
                            ) ?>
                        </strong>

                    <?php endif; ?>

                </div>

            </div>


            <div class="info-box">

                «По роли» означает, что пользователь
                использует стандартное разрешение своей роли.
                «Разрешить» или «Запретить» создаёт
                индивидуальное исключение только для этого
                пользователя.

                Все изменения записываются в журнал аудита.

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
                    value="save_user_permissions"
                >

                <input
                    type="hidden"
                    name="user_id"
                    value="<?= $selectedUserId ?>"
                >


                <?php foreach (
                    $permissionsByCategory
                    as $category =>
                        $categoryPermissions
                ): ?>

                    <section
                        class="permission-category"
                    >

                        <h3>
                            <?= e(
                                $category
                            ) ?>
                        </h3>


                        <?php foreach (
                            $categoryPermissions
                            as $permission
                        ): ?>

                            <?php

                            $permissionId =
                                (int)
                                $permission['id'];

                            /*
                             * Индивидуальное
                             * значение.
                             */

                            $hasIndividual =
                                array_key_exists(
                                    $permissionId,
                                    $userPermissions
                                );

                            $individualValue =
                                $hasIndividual
                                    ? $userPermissions[
                                        $permissionId
                                    ]
                                    : null;

                            /*
                             * Эффективное значение
                             * с учётом роли.
                             */

                            if (
                                $hasIndividual
                                &&
                                $individualValue
                                !== null
                            ) {

                                $effectiveAllowed =
                                    $individualValue
                                    === 1;

                                $effectiveSource =
                                    'Индивидуально';

                            } else {

                                $effectiveAllowed =
                                    $rolePermissions[
                                        $permissionId
                                    ]
                                    ?? false;

                                $effectiveSource =
                                    'По роли';
                            }

                            ?>


                            <div
                                class="permission-row"
                            >

                                <div>

                                    <div
                                        class="permission-title"
                                    >

                                        <?= e(
                                            $permission[
                                                'title'
                                            ]
                                        ) ?>

                                    </div>

                                    <div
                                        class="permission-code"
                                    >

                                        <?= e(
                                            $permission[
                                                'code'
                                            ]
                                        ) ?>

                                    </div>

                                    <div
                                        class="permission-role"
                                    >

                                        Сейчас:
                                        <strong>

                                            <?= $effectiveAllowed
                                                ? 'Разрешено'
                                                : 'Запрещено' ?>

                                        </strong>

                                        ·
                                        <?= e(
                                            $effectiveSource
                                        ) ?>

                                    </div>

                                </div>


                                <select
                                    name="permissions[<?= $permissionId ?>]"
                                    class="permission-select"
                                >

                                    <option
                                        value="inherit"
                                        <?= !$hasIndividual
                                            || $individualValue === null
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        По роли
                                    </option>

                                    <option
                                        value="allow"
                                        <?= $hasIndividual
                                            && $individualValue === 1
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Разрешить
                                    </option>

                                    <option
                                        value="deny"
                                        <?= $hasIndividual
                                            && $individualValue === 0
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Запретить
                                    </option>

                                </select>

                            </div>

                        <?php endforeach; ?>

                    </section>

                <?php endforeach; ?>


                <div class="save-bar">

                    <div>
                        Изменения попадут в audit_log.
                    </div>

                    <button
                        type="submit"
                        class="save-button"
                    >
                        Сохранить индивидуальные права
                    </button>

                </div>

            </form>

        </section>

    </div>

</main>

</body>

</html>
