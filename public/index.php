<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$name = $_SESSION['user_name'];
$role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optima Glass</title>
</head>
<body>

    <h1>Optima Glass</h1>

    <p>
        Вы вошли как:
        <strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong>
    </p>

    <p>
        Роль:
        <strong><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></strong>
    </p>

    <p>
        <a href="/logout.php">Выйти</a>
    </p>

</body>
</html>
