<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

require __DIR__ . '/../src/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare('SELECT id, name, password, role, active FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);

    $user = $stmt->fetch();

    if ($user && (int)$user['active'] === 1 && password_verify($password, $user['password'])) {
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];

        header('Location: /');
        exit;
    }

    $error = 'Неверный email или пароль.';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — Optima Glass</title>
</head>
<body>
    <h1>Optima Glass</h1>
    <h2>Вход</h2>

    <?php if ($error): ?>
        <p style="color: red;">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <form method="post">
        <div>
            <label>
                Email
                <input
                    type="email"
                    name="email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
            </label>
        </div>

        <div>
            <label>
                Пароль
                <input type="password" name="password" required>
            </label>
        </div>

        <button type="submit">Войти</button>
    </form>
</body>
</html>
