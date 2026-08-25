
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

$error = '';
$success = '';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function roleLabel(string $role): string
{
    return $role === 'admin' ? 'Администратор' : 'Сотрудник';
}


/*
 * Загружаем активные производственные участки.
 */
$stageStmt = $db->query("
    SELECT id, name
    FROM production_stages
    WHERE active = 1
    ORDER BY id
");

$stages = $stageStmt->fetchAll();


/*
 * Добавление сотрудника.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'employee';
        $stageId = (int) ($_POST['stage_id'] ?? 0);

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
            $error = 'Неверная роль.';

        } elseif ($role === 'employee' && $stageId <= 0) {
            $error = 'Выберите производственный участок.';

        } else {

            /*
             * Проверяем существование участка.
             */
            if ($stageId > 0) {

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
                    $error = 'Выбранный производственный участок недоступен.';
                }
            }


            if ($error === '') {

                try {

                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $db->prepare("
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

                    $stmt->execute([
                        ':name' => $name,
                        ':email' => $email,
                        ':password' => $passwordHash,
                        ':role' => $role,
                        ':stage_id' => $stageId > 0 ? $stageId : null,
                    ]);

                    $success = 'Сотрудник успешно добавлен.';

                } catch (PDOException $e) {

                    if (str_contains(
                        strtolower($e->getMessage()),
                        'unique'
                    )) {
                        $error = 'Пользователь с таким email уже существует.';
                    } else {
                        $error = 'Не удалось добавить сотрудника.';
                    }
                }
            }
        }
    }


    /*
     * Включение / отключение сотрудника.
     */
    if ($action === 'toggle') {

        $id = (int) ($_POST['id'] ?? 0);

        if ($id === (int) $_SESSION['user_id']) {

            $error = 'Нельзя отключить самого себя.';

        } else {

            $stmt = $db->prepare("
                UPDATE users
                SET active = CASE
                    WHEN active = 1 THEN 0
                    ELSE 1
                END
                WHERE id = :id
            ");

            $stmt->execute([
                ':id' => $id,
            ]);

            $success = 'Статус сотрудника изменён.';
        }
    }


    /*
     * Изменение участка сотрудника.
     */
    if ($action === 'stage') {

        $id = (int) ($_POST['id'] ?? 0);
        $stageId = (int) ($_POST['stage_id'] ?? 0);

        if ($stageId <= 0) {

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

                $error = 'Выбранный участок недоступен.';

            } else {

                $stmt = $db->prepare("
                    UPDATE users
                    SET stage_id = :stage_id
                    WHERE id = :id
                ");

                $stmt->execute([
                    ':stage_id' => $stageId,
                    ':id' => $id,
                ]);

                $success = 'Участок сотрудника изменён.';
            }
        }
    }
}


/*
 * Загружаем сотрудников.
 */
$stmt = $db->query("
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

$employees = $stmt->fetchAll();

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

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>


<h1>Сотрудники</h1>


<p>

    <a href="/">Главная</a>
    |
    <a href="/glasses.php">Стекла</a>
    |
    <a href="/admin/stages.php">Производственные этапы</a>

</p>


<?php if ($error): ?>

    <p style="color: red;">
        <?= e($error) ?>
    </p>

<?php endif; ?>


<?php if ($success): ?>

    <p style="color: green;">
        <?= e($success) ?>
    </p>

<?php endif; ?>


<h2>Добавить сотрудника</h2>


<form method="post">

    <input
        type="hidden"
        name="action"
        value="add"
    >


    <p>

        <label>
            Имя:<br>

            <input
                type="text"
                name="name"
                required
            >

        </label>

    </p>


    <p>

        <label>
            Email:<br>

            <input
                type="email"
                name="email"
                required
            >

        </label>

    </p>


    <p>

        <label>
            Пароль:<br>

            <input
                type="password"
                name="password"
                minlength="6"
                required
            >

        </label>

    </p>


    <p>

        <label>
            Роль:<br>

            <select name="role" id="role">

                <option value="employee">
                    Сотрудник
                </option>

                <option value="admin">
                    Администратор
                </option>

            </select>

        </label>

    </p>


    <p>

        <label>
            Производственный участок:<br>

            <select name="stage_id" id="stage_id">

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

        </label>

    </p>


    <button type="submit">
        Добавить сотрудника
    </button>

</form>


<h2>Список сотрудников</h2>


<?php if (!$employees): ?>

    <p>
        Сотрудников пока нет.
    </p>

<?php else: ?>


<table
    border="1"
    cellpadding="8"
    cellspacing="0"
>

    <thead>

        <tr>

            <th>ID</th>

            <th>Имя</th>

            <th>Email</th>

            <th>Роль</th>

            <th>Участок</th>

            <th>Статус</th>

            <th>Дата создания</th>

            <th>Действия</th>

        </tr>

    </thead>


    <tbody>


    <?php foreach ($employees as $employee): ?>

        <tr>


            <td>
                <?= (int) $employee['id'] ?>
            </td>


            <td>
                <?= e($employee['name']) ?>
            </td>


            <td>
                <?= e($employee['email']) ?>
            </td>


            <td>
                <?= e(roleLabel($employee['role'])) ?>
            </td>


            <td>

                <?php if ($employee['stage_name']): ?>

                    <?= e($employee['stage_name']) ?>

                <?php else: ?>

                    <span style="color: gray;">
                        Не назначен
                    </span>

                <?php endif; ?>


                <?php if ($employee['role'] === 'employee'): ?>

                    <form
                        method="post"
                        style="margin-top: 5px;"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="stage"
                        >

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $employee['id'] ?>"
                        >


                        <select name="stage_id">

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
                            Сохранить
                        </button>

                    </form>

                <?php endif; ?>

            </td>


            <td>

                <?php if ((int) $employee['active'] === 1): ?>

                    <span style="color: green;">
                        Активен
                    </span>

                <?php else: ?>

                    <span style="color: gray;">
                        Отключён
                    </span>

                <?php endif; ?>

            </td>


            <td>
                <?= e($employee['created_at']) ?>
            </td>


            <td>

                <?php if ((int) $employee['id'] !== (int) $_SESSION['user_id']): ?>

                    <form method="post">

                        <input
                            type="hidden"
                            name="action"
                            value="toggle"
                        >

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $employee['id'] ?>"
                        >


                        <button type="submit">

                            <?php if ((int) $employee['active'] === 1): ?>

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


<?php endif; ?>


</body>

</html>

