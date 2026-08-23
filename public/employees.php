<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

if ($_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    exit('Доступ запрещён.');
}

require __DIR__ . '/../src/db.php';

$stmt = $db->query("
    SELECT id, name, email, role, active, created_at
    FROM users
    ORDER BY id DESC
");

$employees = $stmt->fetchAll();

function roleLabel(string $role): string
{
    return $role === 'admin' ? 'Администратор' : 'Сотрудник';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сотрудники — Optima Glass</title>
</head>
<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<h1>Сотрудники</h1>

<table border="1" cellpadding="8" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Имя</th>
            <th>Email</th>
            <th>Роль</th>
            <th>Статус</th>
            <th>Дата создания</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($employees as $employee): ?>
            <tr>
                <td><?= (int) $employee['id'] ?></td>
                <td><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($employee['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= roleLabel($employee['role']) ?></td>
                <td><?= (int) $employee['active'] === 1 ? 'Активен' : 'Отключён' ?></td>
                <td><?= htmlspecialchars($employee['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
