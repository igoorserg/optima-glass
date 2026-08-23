<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

require __DIR__ . '/../src/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $error = 'Введите логин и пароль.';
    } else {
        $stmt = $db->prepare("
            SELECT id, login, password, name, role
            FROM users
            WHERE login = :login
            LIMIT 1
        ");

        $stmt->execute([
            ':login' => $login
        ]);

        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_login'] = $user['login'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];

            header('Location: /index.php');
            exit;
        }

        $error = 'Неверный логин или пароль.';
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>OPTIMA GLASS — Вход</title>

    <meta
        name="description"
        content="OPTIMA GLASS
    >

    <link rel="stylesheet" href="/assets/css/login.css">
</head>

<body>

<div class="background-effects">
    <div class="glow-orb glow-orb-1"></div>
    <div class="glow-orb glow-orb-2"></div>
    <div class="glow-orb glow-orb-3"></div>
</div>

<div class="login-container">

    <div class="login-card">

        <div class="login-header">

            <div class="logo-mark">
                OG
            </div>

            <h1>OPTIMA GLASS</h1>

            <p class="system-name">
                
            </p>

            <div class="welcome">
                <h2>Авторизація</h2>
                <p>Пройдіть авторизацію</p>
            </div>

        </div>

        <?php if ($error): ?>
            <div class="server-error">
                <span class="error-icon">!</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form
            class="login-form"
            method="POST"
            action="/login.php"
            autocomplete="on"
        >

            <div class="form-group">

                <div class="input-wrapper">

                    <input
                        type="text"
                        id="login"
                        name="login"
                        value="<?= e($_POST['login'] ?? '') ?>"
                        autocomplete="username"
                        required
                        autofocus
                    >

                    <label for="login">Логін</label>

                    <span class="input-line"></span>

                </div>

            </div>

            <div class="form-group">

                <div class="input-wrapper password-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >

                    <label for="password">Пароль</label>

                    <button
                        type="button"
                        class="password-toggle"
                        id="passwordToggle"
                        aria-label="Відобразити пароль"
                    >
                        <span class="toggle-icon"></span>
                    </button>

                    <span class="input-line"></span>

                </div>

            </div>

            <div class="form-options">

                <label class="remember-wrapper">
                    <input
                        type="checkbox"
                        name="remember"
                        id="remember"
                    >

                    <span class="custom-checkbox"></span>

                    <span class="checkbox-text">
                        Зберегти данні
                    </span>
                </label>

            </div>

            <button
                type="submit"
                class="login-btn"
                id="loginButton"
            >
                <span class="btn-text">
                Війти в систему
                </span>

                <span class="btn-loader"></span>

                <span class="btn-glow"></span>
            </button>

        </form>

        <div class="login-footer">
            <span>OPTIMA GLASS</span>
            <span class="footer-dot">•</span>
            <span>Виробленно by vkmobile 2026</span>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const password = document.getElementById('password');
    const toggle = document.getElementById('passwordToggle');
    const icon = toggle?.querySelector('.toggle-icon');
    const form = document.querySelector('.login-form');
    const button = document.getElementById('loginButton');

    if (toggle && password) {
        toggle.addEventListener('click', function () {

            const isPassword = password.type === 'password';

            password.type = isPassword ? 'text' : 'password';

            toggle.setAttribute(
                'aria-label',
                isPassword ? 'Скрыть пароль' : 'Показать пароль'
            );

            if (icon) {
                icon.classList.toggle('show-password', isPassword);
            }
        });
    }

    if (form && button) {
        form.addEventListener('submit', function () {
            button.classList.add('loading');
        });
    }

});
</script>

</body>
</html>
