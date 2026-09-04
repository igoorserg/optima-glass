<?php

require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/permissions.php';

$user = require_user();

require_permission('system.settings', $user);

$error = '';
$success = '';

/*
 * Добавление типа стекла
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');

    if ($code === '') {
        $error = 'Укажите тип стекла.';
    } else {

        try {

            $stmt = $db->prepare("
                INSERT INTO glass_types (code, name, active)
                VALUES (:code, :name, 1)
            ");

            $stmt->execute([
                ':code' => $code,
                ':name' => $name !== '' ? $name : null,
            ]);

            $success = 'Тип стекла добавлен.';

        } catch (PDOException $e) {

            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $error = 'Такой тип стекла уже существует.';
            } else {
                $error = 'Не удалось добавить тип стекла.';
            }
        }
    }
}

/*
 * Получаем список типов
 */
$stmt = $db->query("
    SELECT id, code, name, active, created_at
    FROM glass_types
    ORDER BY code
");

$glassTypes = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Типы стекла — Optima Glass</title>
</head>

<body>

<?php require __DIR__ . '/../../src/partials/header.php'; ?>

<h1>Типы стекла</h1>

<?php if ($error): ?>

    <p>
        <strong>Ошибка:</strong>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </p>

<?php endif; ?>

<?php if ($success): ?>

    <p>
        <strong><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></strong>
    </p>

<?php endif; ?>

<hr>

<h2>Добавить тип стекла</h2>

<form method="post">

    <p>
        <label>
            Тип стекла:<br>
            <input
                type="text"
                name="code"
                placeholder="Например: 4FL"
                required
            >
        </label>
    </p>

    <p>
        <label>
            Описание:<br>
            <input
                type="text"
                name="name"
                placeholder="Например: Float 4 мм"
            >
        </label>
    </p>

    <button type="submit">
        Добавить
    </button>

</form>

<hr>

<h2>Справочник</h2>

<?php if ($glassTypes): ?>

<table border="1" cellpadding="8" cellspacing="0">

    <thead>
        <tr>
            <th>ID</th>
            <th>Тип стекла</th>
            <th>Описание</th>
            <th>Статус</th>
            <th>Дата создания</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($glassTypes as $glassType): ?>

        <tr>

            <td>
                <?= (int) $glassType['id'] ?>
            </td>

            <td>
                <strong>
                    <?= htmlspecialchars($glassType['code'], ENT_QUOTES, 'UTF-8') ?>
                </strong>
            </td>

            <td>
                <?= htmlspecialchars($glassType['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>

            <td>
                <?= (int) $glassType['active'] === 1 ? 'Активен' : 'Неактивен' ?>
            </td>

            <td>
                <?= htmlspecialchars($glassType['created_at'], ENT_QUOTES, 'UTF-8') ?>
            </td>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

<?php else: ?>

<p>
    Типы стекла ещё не добавлены.
</p>

<?php endif; ?>

<p>
    <a href="/">← На главную</a>
</p>

</body>
</html>
