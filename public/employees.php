```php
<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Доступ запрещён.');
}

require __DIR__ . '/../src/db.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function roleLabel(string $role): string
{
    return match ($role) {
        'admin' => 'Администратор',
        default => 'Сотрудник',
    };
}

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Производственные участки
|--------------------------------------------------------------------------
*/

$stageStmt = $db->query("
    SELECT id, name, active
    FROM production_stages
    WHERE active = 1
    ORDER BY id
");

$stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Обработка POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
     * Добавление пользователя
     */
    if ($action === 'add') {

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'employee';
        $stageIdRaw = $_POST['stage_id'] ?? '';

        $stageId = $stageIdRaw === ''
            ? null
            : (int) $stageIdRaw;

        if ($name === '') {
            $error = 'Введите имя сотрудника.';
        } elseif ($email === '') {
            $error = 'Введите email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Введите корректный email.';
        } elseif ($password === '') {
            $error = 'Введите пароль.';
        } elseif (strlen($password) < 6) {
            $error = 'Пароль должен содержать минимум 6 символов.';
        } elseif (!in_array($role, ['employee', 'admin'], true)) {
            $error = 'Недопустимая роль.';
        } elseif ($role === 'employee' && $stageId === null) {
            $error = 'Для сотрудника необходимо выбрать участок.';
        } else {

            if ($stageId !== null) {

                $checkStage = $db->prepare("
                    SELECT id
                    FROM production_stages
                    WHERE id = :id
                      AND active = 1
                    LIMIT 1
                ");

                $checkStage->execute([
                    ':id' => $stageId,
                ]);

                if (!$checkStage->fetch()) {
                    $error = 'Выбранный участок не существует или отключён.';
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

                    $success = 'Пользователь успешно добавлен.';

                } catch (PDOException $exception) {

                    $message = strtolower($exception->getMessage());

                    if (str_contains($message, 'unique')) {
                        $error = 'Пользователь с таким email уже существует.';
                    } else {
                        $error = 'Не удалось добавить пользователя.';
                    }
                }
            }
        }
    }

    /*
     * Изменение участка
     */
    if ($action === 'stage') {

        $userId = (int) ($_POST['user_id'] ?? 0);
        $stageIdRaw = $_POST['stage_id'] ?? '';

        $stageId = $stageIdRaw === ''
            ? null
            : (int) $stageIdRaw;

        if ($userId <= 0) {

            $error = 'Некорректный пользователь.';

        } elseif ($stageId === null) {

            $error = 'Выберите участок.';

        } else {

            $checkStage = $db->prepare("
                SELECT id
                FROM production_stages
                WHERE id = :id
                  AND active = 1
                LIMIT 1
            ");

            $checkStage->execute([
                ':id' => $stageId,
            ]);

            if (!$checkStage->fetch()) {

                $error = 'Выбранный участок не существует или отключён.';

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

                $success = 'Участок пользователя изменён.';
            }
        }
    }

    /*
     * Включение / отключение пользователя
     */
    if ($action === 'toggle') {

        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === (int) $_SESSION['user_id']) {

            $error = 'Нельзя отключить текущего администратора.';

        } elseif ($userId <= 0) {

            $error = 'Некорректный пользователь.';

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

            $success = 'Статус пользователя изменён.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Пользователи
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
    ORDER BY u.id DESC
");

$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Сотрудники — Optima Glass</title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>
        .employees-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .employees-card {
            margin-bottom: 24px;
            padding: 24px;
            border: 1px solid #ddd;
            border-radius: 12px;
            background: #fff;
        }

        .employees-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .employees-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .employees-field.full {
            grid-column: 1 / -1;
        }

        .employees-field input,
        .employees-field select {
            width: 100%;
            padding: 10px 12px;
        }

        .employees-table-wrap {
            overflow-x: auto;
        }

        .employees-table {
            width: 100%;
            border-collapse: collapse;
        }

        .employees-table th,
        .employees-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
            vertical-align: top;
        }

        .employee-active {
            color: #15803d;
            font-weight: 600;
        }

        .employee-inactive {
            color: #6b7280;
            font-weight: 600;
        }

        .employees-message {
            margin-bottom: 20px;
            padding: 12px 15px;
            border-radius: 8px;
        }

        .employees-error {
            color: #991b1b;
            background: #fee2e2;
        }

        .employees-success {
            color: #166534;
            background: #dcfce7;
        }

        .stage-form {
            display: flex;
            gap: 8px;
            min-width: 240px;
        }

        .stage-form select {
            flex: 1;
            padding: 8px;
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
            }
        }
    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="employees-page">

    <div class="employees-card">

        <h1>Сотрудники</h1>

        <p>
            Управление пользователями и их производственными участками.
        </p>

    </div>


    <?php if ($error !== ''): ?>

        <div class="employees-message employees-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($success !== ''): ?>

        <div class="employees-message employees-success">
            <?= e($success) ?>
        </div>

    <?php endif; ?>


    <section class="employees-card">

        <h2>Добавить пользователя</h2>

        <form method="post" class="employees-form">

            <input
                type="hidden"
                name="action"
                value="add"
            >

            <div class="employees-field">

                <label for="name">
                    Имя
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

                    <option value="employee">
                        Сотрудник
                    </option>

                    <option value="admin">
                        Администратор
                    </option>

                </select>

            </div>


            <div class="employees-field">

                <label for="stage_id">
                    Производственный участок
                </label>

                <select
                    id="stage_id"
                    name="stage_id"
                >

                    <option value="">
                        Выберите участок
                    </option>

                    <?php foreach ($stages as $stage): ?>

                        <option
                            value="<?= (int) $stage['id'] ?>"
                        >
                            <?= e($stage['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="employees-field full">

                <button type="submit">
                    Добавить пользователя
                </button>

            </div>

        </form>

    </section>


    <section class="employees-card">

        <h2>Пользователи</h2>

        <?php if (!$users): ?>

            <p>
                Пользователей пока нет.
            </p>

        <?php else: ?>

            <div class="employees-table-wrap">

                <table class="employees-table">

                    <thead>

                        <tr>
                            <th>ID</th>
                            <th>Имя</th>
                            <th>Email</th>
                            <th>Роль</th>
                            <th>Участок</th>
                            <th>Статус</th>
                            <th>Создан</th>
                            <th>Действия</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($users as $user): ?>

                        <tr>

                            <td>
                                <?= (int) $user['id'] ?>
                            </td>

                            <td>
                                <?= e($user['name']) ?>
                            </td>

                            <td>
                                <?= e($user['email']) ?>
                            </td>

                            <td>
                                <?= e(roleLabel($user['role'])) ?>
                            </td>

                            <td>

                                <?php if ($user['role'] === 'admin'): ?>

                                    <strong>
                                        Все участки
                                    </strong>

                                <?php elseif ($user['stage_name']): ?>

                                    <form
                                        method="post"
                                        class="stage-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="stage"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int) $user['id'] ?>"
                                        >

                                        <select name="stage_id">

                                            <?php foreach ($stages as $stage): ?>

                                                <option
                                                    value="<?= (int) $stage['id'] ?>"
                                                    <?= (int) $user['stage_id'] === (int) $stage['id'] ? 'selected' : '' ?>
                                                >
                                                    <?= e($stage['name']) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                        <button type="submit">
                                            Сохранить
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <form
                                        method="post"
                                        class="stage-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="stage"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int) $user['id'] ?>"
                                        >

                                        <select name="stage_id" required>

                                            <option value="">
                                                Выберите участок
                                            </option>

                                            <?php foreach ($stages as $stage): ?>

                                                <option
                                                    value="<?= (int) $stage['id'] ?>"
                                                >
                                                    <?= e($stage['name']) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                        <button type="submit">
                                            Назначить
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if ((int) $user['active'] === 1): ?>

                                    <span class="employee-active">
                                        Активен
                                    </span>

                                <?php else: ?>

                                    <span class="employee-inactive">
                                        Отключён
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= e($user['created_at']) ?>
                            </td>

                            <td>

                                <?php if (
                                    (int) $user['id'] !==
                                    (int) $_SESSION['user_id']
                                ): ?>

                                    <form method="post">

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="toggle"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int) $user['id'] ?>"
                                        >

                                        <button type="submit">

                                            <?php if (
                                                (int) $user['active'] === 1
                                            ): ?>

                                                Отключить

                                            <?php else: ?>

                                                Включить

                                            <?php endif; ?>

                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span>
                                        Текущий пользователь
                                    </span>

                                <?php endif; ?>

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

