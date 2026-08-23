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

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'employee';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Заполните все обязательные поля.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Введите корректный email.';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен содержать минимум 6 символов.';
    } elseif (!in_array($role, ['admin', 'employee'], true)) {
        $error = 'Недопустимая роль.';
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO users (name, email, password, role, active)
                VALUES (:name, :email, :password, :role, 1)
            ");

            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
            ]);

            header('Location: /employees.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Пользователь с таким email уже существует.';
            } else {
                $error = 'Не удалось создать сотрудника.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новый сотрудник — Optima Glass</title>
</head>
<body>

    <?php require __DIR__ . '/../src/partials/header.php'; ?>

<h1>Новый сотрудник</h1>

<p>
    <a href="/employees.php">← Сотрудники</a>
</p>

<?php if ($error): ?>
    <p style="color: red;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<form method="post">

    <p>
        <label>
            Имя:<br>
            <input
                type="text"
                name="name"
                required
                value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
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
                value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            >
        </label>
    </p>

    <p>
        <label>
            Пароль:<br>
            <input type="password" name="password" required>
        </label>
    </p>

    <p>
        <label>
            Роль:<br>
            <select name="role">
                <option value="employee">Сотрудник</option>
                <option value="admin">Администратор</option>
            </select>
        </label>
    </p>

    <button type="submit">Создать сотрудника</button>

</form>

</body>
</html>
