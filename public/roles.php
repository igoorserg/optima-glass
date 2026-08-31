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
    exit('Доступ запрещён. Только суперадминистратор может управлять ролями и правами.');
}

require_permission('system.roles', $user);

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

if (empty($_SESSION['csrf_roles'])) {
    $_SESSION['csrf_roles'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_roles'];

$error = '';
$success = '';

$selectedRoleId =
    (int) (
        $_GET['role_id']
        ?? $_POST['role_id']
        ?? 0
    );

/*
|--------------------------------------------------------------------------
| Получаем роли
|--------------------------------------------------------------------------
*/

$rolesStmt = $db->query("
    SELECT
        id,
        name,
        title,
        system,
        active
    FROM roles
    WHERE active = 1
    ORDER BY id
");

$roles =
    $rolesStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

if (!$selectedRoleId && $roles) {
    $selectedRoleId = (int) $roles[0]['id'];
}

/*
|--------------------------------------------------------------------------
| Получаем выбранную роль
|--------------------------------------------------------------------------
*/

$selectedRole = null;

foreach ($roles as $role) {
    if ((int) $role['id'] === $selectedRoleId) {
        $selectedRole = $role;
        break;
    }
}

if (!$selectedRole) {
    http_response_code(404);
    exit('Роль не найдена.');
}

/*
|--------------------------------------------------------------------------
| Сохранение прав
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $csrfToken,
        $_POST['csrf_token'] ?? ''
    )) {

        $error =
            'Ошибка проверки безопасности.';

    } else {

        $action =
            $_POST['action'] ?? '';

        if ($action === 'save_role_permissions') {

            /*
             * Загружаем все доступные разрешения.
             */

            $permissionsStmt =
                $db->query("
                    SELECT
                        id,
                        code,
                        title,
                        category,
                        active
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

            $postedPermissions =
                $_POST['permissions']
                ?? [];

            try {

                $db->beginTransaction();

                /*
                 * Не позволяем через обычный интерфейс
                 * убрать базовые права суперадмина.
                 *
                 * Для system-роли оставляем полный набор.
                 */

                if (
                    (int) $selectedRole['system'] === 1
                    &&
                    $selectedRole['name'] === 'superadmin'
                ) {

                    $postedPermissions = [];

                    foreach ($permissions as $permission) {
                        $postedPermissions[
                            (int) $permission['id']
                        ] = '1';
                    }
                }

                foreach ($permissions as $permission) {

                    $permissionId =
                        (int) $permission['id'];

                    $newAllowed =
                        isset(
                            $postedPermissions[
                                $permissionId
                            ]
                        )
                            ? 1
                            : 0;

                    /*
                     * Текущее значение.
                     */

                    $oldStmt =
                        $db->prepare("
                            SELECT allowed
                            FROM role_permissions
                            WHERE role_id =
                                :role_id
                              AND permission_id =
                                :permission_id
                            LIMIT 1
                        ");

                    $oldStmt->execute([
                        ':role_id' =>
                            $selectedRoleId,

                        ':permission_id' =>
                            $permissionId,
                    ]);

                    $oldValue =
                        $oldStmt->fetchColumn();

                    if ($oldValue === false) {
                        $oldValue = 0;
                    } else {
                        $oldValue = (int) $oldValue;
                    }

                    /*
                     * Сохраняем только если изменилось.
                     */

                    if (
                        $oldValue !==
                        $newAllowed
                    ) {

                        $upsert =
                            $db->prepare("
                                INSERT INTO role_permissions (
                                    role_id,
                                    permission_id,
                                    allowed,
                                    created_at,
                                    updated_at
                                )
                                VALUES (
                                    :role_id,
                                    :permission_id,
                                    :allowed,
                                    CURRENT_TIMESTAMP,
                                    CURRENT_TIMESTAMP
                                )

                                ON CONFLICT(
                                    role_id,
                                    permission_id
                                )

                                DO UPDATE SET
                                    allowed =
                                        excluded.allowed,

                                    updated_at =
                                        CURRENT_TIMESTAMP
                            ");

                        $upsert->execute([
                            ':role_id' =>
                                $selectedRoleId,

                            ':permission_id' =>
                                $permissionId,

                            ':allowed' =>
                                $newAllowed,
                        ]);

                        /*
                         * Аудит изменения права.
                         */

                        auditPermissionChange(
                            $db,
                            (int) $user['id'],
                            'role_permission_changed',
                            'role',
                            $selectedRoleId,
                            $permission['code'],
                            $oldValue,
                            $newAllowed
                        );
                    }
                }

                $db->commit();

                $success =
                    'Права роли «'
                    . $selectedRole['title']
                    . '» сохранены.';

            } catch (Throwable $exception) {

                if ($db->inTransaction()) {
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
| Загружаем права
|--------------------------------------------------------------------------
*/

$permissionsStmt =
    $db->query("
        SELECT
            id,
            code,
            title,
            category,
            active
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
| Текущие права выбранной роли
|--------------------------------------------------------------------------
*/

$rolePermissionsStmt =
    $db->prepare("
        SELECT
            permission_id,
            allowed
        FROM role_permissions
        WHERE role_id = :role_id
    ");

$rolePermissionsStmt->execute([
    ':role_id' =>
        $selectedRoleId,
]);

$rolePermissions = [];

foreach (
    $rolePermissionsStmt->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $row
) {

    $rolePermissions[
        (int) $row['permission_id']
    ] =
        (int) $row['allowed'];
}

/*
|--------------------------------------------------------------------------
| Группируем права по категориям
|--------------------------------------------------------------------------
*/

$permissionsByCategory = [];

foreach ($permissions as $permission) {

    $category =
        $permission['category'];

    if (
        !isset(
            $permissionsByCategory[$category]
        )
    ) {

        $permissionsByCategory[$category] =
            [];
    }

    $permissionsByCategory[$category][] =
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
        Роли и права — OPTIMA GLASS
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

        .roles-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .roles-header {
            margin-bottom: 25px;
        }

        .roles-header h1 {
            margin-bottom: 7px;
        }

        .roles-subtitle {
            color: #6b7280;
        }

        .layout {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 20px;
        }

        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
        }

        .roles-list {
            overflow: hidden;
        }

        .role-link {
            display: block;
            padding: 15px 16px;
            border-bottom: 1px solid #e5e7eb;
            color: #111827;
            text-decoration: none;
        }

        .role-link:last-child {
            border-bottom: 0;
        }

        .role-link:hover {
            background: #f9fafb;
        }

        .role-link.active {
            background: #111827;
            color: #fff;
        }

        .role-name {
            font-weight: 700;
        }

        .role-system {
            margin-top: 4px;
            font-size: 12px;
            opacity: .7;
        }

        .permissions-card {
            padding: 25px;
        }

        .permissions-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
        }

        .permissions-header h2 {
            margin: 0;
        }

        .permissions-note {
            margin-top: 6px;
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

        .permission-category {
            margin-bottom: 24px;
        }

        .permission-category h3 {
            margin: 0 0 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .permission-row {
            display: grid;
            grid-template-columns: 40px minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .permission-row:last-child {
            border-bottom: 0;
        }

        .permission-row input {
            width: 19px;
            height: 19px;
        }

        .permission-title {
            font-weight: 600;
        }

        .permission-code {
            margin-top: 3px;
            color: #6b7280;
            font-size: 12px;
        }

        .permission-status {
            color: #6b7280;
            font-size: 13px;
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
            background: rgba(255,255,255,.96);
            box-shadow: 0 8px 25px rgba(0,0,0,.08);
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

        .superadmin-warning {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 9px;
            background: #fef3c7;
            color: #92400e;
            font-size: 13px;
        }

        @media (max-width: 800px) {

            .layout {
                grid-template-columns: 1fr;
            }

            .permissions-header {
                flex-direction: column;
            }

            .permission-row {
                grid-template-columns: 35px minmax(0, 1fr);
            }

            .permission-status {
                grid-column: 2;
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

<main class="roles-page">

    <header class="roles-header">

        <h1>
            Роли и права
        </h1>

        <div class="roles-subtitle">
            Управление системными разрешениями OPTIMA GLASS
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

        <aside class="card roles-list">

            <?php foreach ($roles as $role): ?>

                <a
                    href="/roles.php?role_id=<?= (int) $role['id'] ?>"
                    class="role-link <?= (int) $role['id'] === $selectedRoleId
                        ? 'active'
                        : '' ?>"
                >

                    <div class="role-name">

                        <?= e(
                            $role['title']
                        ) ?>

                    </div>

                    <?php if (
                        (int) $role['system'] === 1
                    ): ?>

                        <div class="role-system">
                            Системная роль
                        </div>

                    <?php endif; ?>

                </a>

            <?php endforeach; ?>

        </aside>


        <section class="card permissions-card">

            <div class="permissions-header">

                <div>

                    <h2>
                        <?= e(
                            $selectedRole['title']
                        ) ?>
                    </h2>

                    <div class="permissions-note">

                        Код роли:
                        <?= e(
                            $selectedRole['name']
                        ) ?>

                    </div>

                </div>

            </div>


            <?php if (
                $selectedRole['name']
                === 'superadmin'
            ): ?>

                <div class="superadmin-warning">

                    У суперадмина остаются все системные права.
                    Эта роль защищена от случайного отключения
                    критических разрешений.

                </div>

            <?php endif; ?>


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
                    value="save_role_permissions"
                >

                <input
                    type="hidden"
                    name="role_id"
                    value="<?= $selectedRoleId ?>"
                >


                <?php foreach (
                    $permissionsByCategory
                    as $category => $categoryPermissions
                ): ?>

                    <section class="permission-category">

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

                            $allowed =
                                (
                                    $rolePermissions[
                                        $permissionId
                                    ] ?? 0
                                ) === 1;

                            $isSuperadmin =
                                $selectedRole[
                                    'name'
                                ] === 'superadmin';

                            ?>

                            <label class="permission-row">

                                <input
                                    type="checkbox"
                                    name="permissions[<?= $permissionId ?>]"
                                    value="1"
                                    <?= $allowed
                                        || $isSuperadmin
                                        ? 'checked'
                                        : '' ?>

                                    <?= $isSuperadmin
                                        ? 'disabled'
                                        : '' ?>
                                >


                                <?php if (
                                    $isSuperadmin
                                ): ?>

                                    <input
                                        type="hidden"
                                        name="permissions[<?= $permissionId ?>]"
                                        value="1"
                                    >

                                <?php endif; ?>


                                <div>

                                    <div class="permission-title">

                                        <?= e(
                                            $permission[
                                                'title'
                                            ]
                                        ) ?>

                                    </div>

                                    <div class="permission-code">

                                        <?= e(
                                            $permission[
                                                'code'
                                            ]
                                        ) ?>

                                    </div>

                                </div>


                                <div class="permission-status">

                                    <?= $allowed
                                        || $isSuperadmin
                                        ? 'Разрешено'
                                        : 'Запрещено' ?>

                                </div>

                            </label>

                        <?php endforeach; ?>

                    </section>

                <?php endforeach; ?>


                <?php if (
                    $selectedRole['name']
                    !== 'superadmin'
                ): ?>

                    <div class="save-bar">

                        <div>
                            Изменения будут записаны
                            в журнал аудита.
                        </div>

                        <button
                            type="submit"
                            class="save-button"
                        >
                            Сохранить права
                        </button>

                    </div>

                <?php endif; ?>

            </form>

        </section>

    </div>

</main>

</body>

</html>
