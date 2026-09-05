<?php

require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/permissions.php';

$user = require_user();

require_permission('system.settings', $user);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {

        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $error = 'Введите название этапа.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO production_stages (name, active)
                    VALUES (:name, 1)
                ");

                $stmt->execute([
                    ':name' => $name,
                ]);

                $success = 'Этап добавлен.';
            } catch (PDOException $e) {
                $error = 'Не удалось добавить этап. Возможно, такой этап уже существует.';
            }
        }
    }

    if ($action === 'toggle') {

        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $db->prepare("
            UPDATE production_stages
            SET active = CASE active WHEN 1 THEN 0 ELSE 1 END
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id,
        ]);

        $success = 'Статус этапа изменён.';
    }

    if ($action === 'rename') {

        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $error = 'Название этапа не может быть пустым.';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE production_stages
                    SET name = :name
                    WHERE id = :id
                ");

                $stmt->execute([
                    ':id' => $id,
                    ':name' => $name,
                ]);

                $success = 'Этап переименован.';
            } catch (PDOException $e) {
                $error = 'Не удалось переименовать этап.';
            }
        }
    }
}

$stmt = $db->query("
    SELECT id, name, active, created_at
    FROM production_stages
    ORDER BY id
");

$stages = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Производственные этапы — Optima Glass</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=4">
</head>

<body>
<main class="legacy-page admin-page">


<?php require __DIR__ . '/../../src/partials/header.php'; ?>

<h1>Производственные этапы</h1>

<p>
    <a href="/">Главная</a> |
    <a href="/glasses.php">Стекла</a> |
    <a href="/employees.php">Сотрудники</a>
</p>

<?php if ($error): ?>
    <p style="color: red;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<?php if ($success): ?>
    <p style="color: green;">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<h2>Добавить этап</h2>

<form method="post">

    <input type="hidden" name="action" value="add">

    <label>
        Название этапа:
        <input
            type="text"
            name="name"
            required
        >
    </label>

    <button type="submit">
        Добавить
    </button>

</form>

<h2>Существующие этапы</h2>

<table border="1" cellpadding="8" cellspacing="0">

    <thead>
        <tr>
            <th>ID</th>
            <th>Название</th>
            <th>Статус</th>
            <th>Действия</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($stages as $stage): ?>

        <tr>

            <td>
                <?= (int) $stage['id'] ?>
            </td>

            <td>

                <form method="post">

                    <input
                        type="hidden"
                        name="action"
                        value="rename"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int) $stage['id'] ?>"
                    >

                    <input
                        type="text"
                        name="name"
                        value="<?= htmlspecialchars($stage['name'], ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >

                    <button type="submit">
                        Сохранить
                    </button>

                </form>

            </td>

            <td>

                <?php if ((int) $stage['active'] === 1): ?>

                    <span style="color: green;">
                        Активен
                    </span>

                <?php else: ?>

                    <span style="color: gray;">
                        Выключен
                    </span>

                <?php endif; ?>

            </td>

            <td>

                <form method="post">

                    <input
                        type="hidden"
                        name="action"
                        value="toggle"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int) $stage['id'] ?>"
                    >

                    <button type="submit">

                        <?php if ((int) $stage['active'] === 1): ?>
                            Выключить
                        <?php else: ?>
                            Включить
                        <?php endif; ?>

                    </button>

                </form>

            </td>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>


</main>
</body>
</html>
