<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$currentUser = require_user();

require_permission('employees.view', $currentUser);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function roleLabel(string $role): string
{
    return match ($role) {
        'superadmin'      => 'Суперадміністратор',
        'admin'           => 'Адміністратор',
        'manager'         => 'Менеджер',
        'section_manager' => 'Майстер дільниці',
        'employee'        => 'Працівник',
        default           => $role,
    };
}

function roleNeedsStage(string $role): bool
{
    return in_array(
        $role,
        ['employee', 'section_manager'],
        true
    );
}

function allowedRolesForUser(array $currentUser): array
{
    if (($currentUser['role'] ?? '') === 'superadmin') {
        return [
            'superadmin',
            'admin',
            'manager',
            'section_manager',
            'employee',
        ];
    }

    return [
        'admin',
        'manager',
        'section_manager',
        'employee',
    ];
}

function canManageTarget(
    array $currentUser,
    array $targetUser
): bool {
    if (($currentUser['role'] ?? '') === 'superadmin') {
        return true;
    }

    return ($targetUser['role'] ?? '') !== 'superadmin';
}

function loadUserById(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare("
        SELECT
            id,
            name,
            email,
            role,
            active,
            stage_id,
            created_at
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $userId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function stageExists(PDO $db, int $stageId): bool
{
    $stmt = $db->prepare("
        SELECT id
        FROM production_stages
        WHERE id = :id
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $stageId,
    ]);

    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['employees_csrf'])
    || !is_string($_SESSION['employees_csrf'])
) {
    $_SESSION['employees_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['employees_csrf'];

/*
|--------------------------------------------------------------------------
| Виробничі дільниці
|--------------------------------------------------------------------------
*/

$stageStmt = $db->query("
    SELECT id, name
    FROM production_stages
    WHERE active = 1
    ORDER BY id
");

$stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken)
        || !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(419);
        exit('Недійсний CSRF-токен. Оновіть сторінку.');
    }

    $action = (string) ($_POST['action'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Додавання користувача
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        if (!can('employees.create', $currentUser)) {
            http_response_code(403);
            exit('Недостатньо прав для створення користувачів.');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'employee');
        $stageRaw = trim((string) ($_POST['stage_id'] ?? ''));

        $allowedRoles = allowedRolesForUser($currentUser);

        if ($name === '') {

            $error = 'Вкажіть ім’я користувача.';

        } elseif ($email === '') {

            $error = 'Вкажіть email.';

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error = 'Вкажіть коректний email.';

        } elseif (strlen($password) < 6) {

            $error = 'Пароль повинен містити щонайменше 6 символів.';

        } elseif (!in_array($role, $allowedRoles, true)) {

            $error = 'Недопустима роль користувача.';

        } else {

            $stageId = null;

            if (roleNeedsStage($role)) {

                if ($stageRaw === '') {

                    $error = 'Для цієї ролі потрібно вибрати дільницю.';

                } else {

                    $stageId = (int) $stageRaw;

                    if (
                        $stageId <= 0
                        || !stageExists($db, $stageId)
                    ) {
                        $error = 'Вибрана дільниця недоступна.';
                    }
                }
            }

            if ($error === '') {

                try {

                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $insert = $db->prepare("
                        INSERT INTO users (
                            name,
                            email,
                            password,
                            role,
                            active,
                            stage_id
                        )
                        VALUES (
                            :name,
                            :email,
                            :password,
                            :role,
                            1,
                            :stage_id
                        )
                    ");

                    $insert->execute([
                        ':name' => $name,
                        ':email' => $email,
                        ':password' => $passwordHash,
                        ':role' => $role,
                        ':stage_id' => $stageId,
                    ]);

                    $success = 'Користувача успішно створено.';

                } catch (PDOException $exception) {

                    if (
                        str_contains(
                            strtolower($exception->getMessage()),
                            'unique'
                        )
                    ) {
                        $error = 'Користувач із таким email уже існує.';
                    } else {
                        $error = 'Не вдалося створити користувача.';
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Редагування користувача
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'edit') {

        if (!can('employees.edit', $currentUser)) {
            http_response_code(403);
            exit('Недостатньо прав для редагування користувачів.');
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = (string) ($_POST['role'] ?? '');

        $targetUser = loadUserById($db, $userId);

        if (!$targetUser) {

            $error = 'Користувача не знайдено.';

        } elseif (!canManageTarget($currentUser, $targetUser)) {

            http_response_code(403);
            exit('Адміністратор не може змінювати суперадміністратора.');

        } elseif ($name === '') {

            $error = 'Вкажіть ім’я користувача.';

        } elseif (
            $email === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {

            $error = 'Вкажіть коректний email.';

        } elseif (
            !in_array(
                $role,
                allowedRolesForUser($currentUser),
                true
            )
        ) {

            $error = 'Недопустима роль користувача.';

        } else {

            /*
             * Для виробничих ролей залишаємо поточну дільницю.
             * Для адміністративних ролей stage_id очищаємо.
             */

            $stageId = $targetUser['stage_id'];

            if (!roleNeedsStage($role)) {
                $stageId = null;
            }

            if (
                roleNeedsStage($role)
                && empty($stageId)
            ) {
                $error = 'Спочатку призначте користувачу виробничу дільницю.';
            }

            if ($error === '') {

                try {

                    $update = $db->prepare("
                        UPDATE users
                        SET
                            name = :name,
                            email = :email,
                            role = :role,
                            stage_id = :stage_id
                        WHERE id = :id
                    ");

                    $update->execute([
                        ':name' => $name,
                        ':email' => $email,
                        ':role' => $role,
                        ':stage_id' => $stageId,
                        ':id' => $userId,
                    ]);

                    /*
                     * Якщо користувач редагує сам себе,
                     * синхронізуємо базові дані сесії.
                     */

                    if (
                        $userId ===
                        (int) ($currentUser['id'] ?? 0)
                    ) {
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_role'] = $role;
                    }

                    $success = 'Дані користувача оновлено.';

                } catch (PDOException $exception) {

                    if (
                        str_contains(
                            strtolower($exception->getMessage()),
                            'unique'
                        )
                    ) {
                        $error = 'Користувач із таким email уже існує.';
                    } else {
                        $error = 'Не вдалося оновити користувача.';
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Призначення дільниці
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'stage') {

        if (!can('employees.assign_stage', $currentUser)) {
            http_response_code(403);
            exit('Недостатньо прав для призначення дільниці.');
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $stageId = (int) ($_POST['stage_id'] ?? 0);

        $targetUser = loadUserById($db, $userId);

        if (!$targetUser) {

            $error = 'Користувача не знайдено.';

        } elseif (!canManageTarget($currentUser, $targetUser)) {

            http_response_code(403);
            exit('Адміністратор не може змінювати суперадміністратора.');

        } elseif (!roleNeedsStage($targetUser['role'])) {

            $error = 'Для цієї ролі виробнича дільниця не використовується.';

        } elseif (
            $stageId <= 0
            || !stageExists($db, $stageId)
        ) {

            $error = 'Вибрана дільниця недоступна.';

        } else {

            $update = $db->prepare("
                UPDATE users
                SET stage_id = :stage_id
                WHERE id = :id
            ");

            $update->execute([
                ':stage_id' => $stageId,
                ':id' => $userId,
            ]);

            $success = 'Дільницю користувача змінено.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Увімкнення / вимкнення
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'toggle') {

        if (!can('employees.disable', $currentUser)) {
            http_response_code(403);
            exit('Недостатньо прав для зміни статусу користувача.');
        }

        $userId = (int) ($_POST['user_id'] ?? 0);

        $targetUser = loadUserById($db, $userId);

        if (!$targetUser) {

            $error = 'Користувача не знайдено.';

        } elseif (
            $userId ===
            (int) ($currentUser['id'] ?? 0)
        ) {

            $error = 'Не можна вимкнути власний обліковий запис.';

        } elseif (!canManageTarget($currentUser, $targetUser)) {

            http_response_code(403);
            exit('Адміністратор не може вимкнути суперадміністратора.');

        } else {

            $update = $db->prepare("
                UPDATE users
                SET active = CASE
                    WHEN active = 1 THEN 0
                    ELSE 1
                END
                WHERE id = :id
            ");

            $update->execute([
                ':id' => $userId,
            ]);

            $success = 'Статус користувача змінено.';
        }
    }

    else {

        $error = 'Невідома дія.';
    }
}

/*
|--------------------------------------------------------------------------
| Користувачі
|--------------------------------------------------------------------------
*/

$userStmt = $db->query("
    SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        u.active,
        u.stage_id,
        u.created_at,
        ps.name AS stage_name
    FROM users u
    LEFT JOIN production_stages ps
        ON ps.id = u.stage_id
    ORDER BY
        CASE u.role
            WHEN 'superadmin' THEN 1
            WHEN 'admin' THEN 2
            WHEN 'manager' THEN 3
            WHEN 'section_manager' THEN 4
            WHEN 'employee' THEN 5
            ELSE 6
        END,
        u.id
");

$employees = $userStmt->fetchAll(PDO::FETCH_ASSOC);

$canCreate = can('employees.create', $currentUser);
$canEdit = can('employees.edit', $currentUser);
$canDisable = can('employees.disable', $currentUser);
$canAssignStage = can('employees.assign_stage', $currentUser);

$availableRoles = allowedRolesForUser($currentUser);

?>
<!DOCTYPE html>
<html lang="uk">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Працівники — Optima Glass</title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>

        .employees-page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .employees-card {
            margin-bottom: 24px;
            padding: 24px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid #e5e7eb;
        }

        .employees-card h1,
        .employees-card h2 {
            margin-top: 0;
        }

        .employees-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .employees-field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .employees-field.full {
            grid-column: 1 / -1;
        }

        .employees-field input,
        .employees-field select,
        .inline-edit input,
        .inline-edit select,
        .stage-form select {
            width: 100%;
            min-height: 42px;
            padding: 9px 12px;
            box-sizing: border-box;
        }

        .employees-table-wrap {
            overflow-x: auto;
        }

        .employees-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1150px;
        }

        .employees-table th,
        .employees-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }

        .employees-success {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 9px;
            background: #dcfce7;
            color: #166534;
        }

        .employees-error {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 9px;
            background: #fee2e2;
            color: #991b1b;
        }

        .employee-active {
            color: #15803d;
            font-weight: 600;
        }

        .employee-inactive {
            color: #6b7280;
            font-weight: 600;
        }

        .role-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            background: #eef2ff;
            font-size: 13px;
            font-weight: 600;
        }

        .stage-form,
        .inline-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .stage-form select {
            min-width: 150px;
        }

        .inline-edit {
            display: grid;
            gap: 8px;
            min-width: 210px;
        }

        .employee-note {
            color: #6b7280;
            font-size: 13px;
        }

        .employee-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 135px;
        }

        .employee-actions form {
            margin: 0;
        }

        .employee-actions button,
        .stage-form button,
        .inline-edit button {
            white-space: nowrap;
        }

        @media (max-width: 700px) {

            .employees-form {
                grid-template-columns: 1fr;
            }

            .employees-field.full {
                grid-column: auto;
            }

            .stage-form {
                flex-direction: column;
                align-items: stretch;
            }

        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="employees-page">

    <section class="employees-card">

        <h1>👥 Працівники</h1>

        <p>
            Користувачі системи, їхні ролі, доступ і виробничі дільниці.
        </p>

    </section>

    <?php if ($error !== ''): ?>

        <div class="employees-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($success !== ''): ?>

        <div class="employees-success">
            <?= e($success) ?>
        </div>

    <?php endif; ?>


    <?php if ($canCreate): ?>

        <section class="employees-card">

            <h2>Додати користувача</h2>

            <form
                method="post"
                class="employees-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="add"
                >

                <div class="employees-field">

                    <label for="name">
                        Ім’я
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        required
                    >

                </div>

                <div class="employees-field">

                    <label for="email">
                        Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                    >

                </div>

                <div class="employees-field">

                    <label for="password">
                        Пароль
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="6"
                        required
                    >

                </div>

                <div class="employees-field">

                    <label for="role">
                        Роль
                    </label>

                    <select
                        id="role"
                        name="role"
                        required
                    >

                        <?php foreach ($availableRoles as $role): ?>

                            <option
                                value="<?= e($role) ?>"
                                <?= $role === 'employee' ? 'selected' : '' ?>
                            >
                                <?= e(roleLabel($role)) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="employees-field">

                    <label for="stage_id">
                        Виробнича дільниця
                    </label>

                    <select
                        id="stage_id"
                        name="stage_id"
                    >

                        <option value="">
                            Без дільниці
                        </option>

                        <?php foreach ($stages as $stage): ?>

                            <option
                                value="<?= (int) $stage['id'] ?>"
                            >
                                <?= e($stage['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <span class="employee-note">
                        Для працівника та начальника дільниці вибір дільниці обов’язковий.
                    </span>

                </div>

                <div class="employees-field full">

                    <button type="submit">
                        ➕ Додати користувача
                    </button>

                </div>

            </form>

        </section>

    <?php endif; ?>


    <section class="employees-card">

        <h2>Користувачі</h2>

        <?php if (!$employees): ?>

            <p>Користувачів поки немає.</p>

        <?php else: ?>

            <div class="employees-table-wrap">

                <table class="employees-table">

                    <thead>

                        <tr>
                            <th>ID</th>
                            <th>Користувач</th>
                            <th>Роль</th>
                            <th>Дільниця</th>
                            <th>Статус</th>
                            <th>Створено</th>
                            <th>Дії</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($employees as $employee): ?>

                        <?php
                        $targetManageable =
                            canManageTarget(
                                $currentUser,
                                $employee
                            );

                        $isCurrent =
                            (int) $employee['id'] ===
                            (int) ($currentUser['id'] ?? 0);
                        ?>

                        <tr>

                            <td>
                                <?= (int) $employee['id'] ?>
                            </td>

                            <td>

                                <?php if (
                                    $canEdit
                                    && $targetManageable
                                ): ?>

                                    <form
                                        method="post"
                                        class="inline-edit"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="edit"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int) $employee['id'] ?>"
                                        >

                                        <input
                                            type="text"
                                            name="name"
                                            value="<?= e($employee['name']) ?>"
                                            required
                                        >

                                        <input
                                            type="email"
                                            name="email"
                                            value="<?= e($employee['email']) ?>"
                                            required
                                        >

                                        <select
                                            name="role"
                                            required
                                        >

                                            <?php
                                            $editRoles =
                                                allowedRolesForUser(
                                                    $currentUser
                                                );
                                            ?>

                                            <?php foreach ($editRoles as $role): ?>

                                                <option
                                                    value="<?= e($role) ?>"
                                                    <?= $employee['role'] === $role ? 'selected' : '' ?>
                                                >
                                                    <?= e(roleLabel($role)) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                        <button type="submit">
                                            💾 Зберегти
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <strong>
                                        <?= e($employee['name']) ?>
                                    </strong>

                                    <br>

                                    <span class="employee-note">
                                        <?= e($employee['email']) ?>
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <span class="role-badge">
                                    <?= e(roleLabel($employee['role'])) ?>
                                </span>

                            </td>

                            <td>

                                <?php if (
                                    roleNeedsStage(
                                        $employee['role']
                                    )
                                ): ?>

                                    <?php if (
                                        $canAssignStage
                                        && $targetManageable
                                    ): ?>

                                        <form
                                            method="post"
                                            class="stage-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="stage"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int) $employee['id'] ?>"
                                            >

                                            <select
                                                name="stage_id"
                                                required
                                            >

                                                <option value="">
                                                    Виберіть дільницю
                                                </option>

                                                <?php foreach ($stages as $stage): ?>

                                                    <option
                                                        value="<?= (int) $stage['id'] ?>"
                                                        <?= (int) $employee['stage_id'] === (int) $stage['id'] ? 'selected' : '' ?>
                                                    >
                                                        <?= e($stage['name']) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                            <button type="submit">
                                                💾
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <?= e(
                                            $employee['stage_name']
                                            ?: 'Не призначено'
                                        ) ?>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <strong>
                                        Усі дільниці
                                    </strong>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if (
                                    (int) $employee['active'] === 1
                                ): ?>

                                    <span class="employee-active">
                                        ● Активний
                                    </span>

                                <?php else: ?>

                                    <span class="employee-inactive">
                                        ● Вимкнений
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= e($employee['created_at']) ?>
                            </td>

                            <td>

                                <div class="employee-actions">

                                    <?php if ($isCurrent): ?>

                                        <span class="employee-note">
                                            Поточний користувач
                                        </span>

                                    <?php elseif (
                                        $canDisable
                                        && $targetManageable
                                    ): ?>

                                        <form method="post">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int) $employee['id'] ?>"
                                            >

                                            <button type="submit">

                                                <?php if (
                                                    (int) $employee['active'] === 1
                                                ): ?>

                                                    ⛔ Вимкнути

                                                <?php else: ?>

                                                    ✅ Увімкнути

                                                <?php endif; ?>

                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="employee-note">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>

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
