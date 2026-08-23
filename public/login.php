<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

require __DIR__ . '/../src/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Введите email и пароль.';
    } else {
        $stmt = $db->prepare("
            SELECT id, name, email, password, role
            FROM users
            WHERE email = :email
              AND active = 1
            LIMIT 1
        ");

        $stmt->execute([
            ':email' => $email
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];

            header('Location: /index.php');
            exit;
        }

        $error = 'Неверный email или пароль.';
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
        content="Вход в производственную систему OPTIMA GLASS"
    >

    <link rel="stylesheet" href="/assets/css/login.css">
</head>

<body>

<div class="login-container">

    <div class="login-card">

        <div class="login-header">

            <div class="logo-icon">⚡</div>

            <h2>OPTIMA GLASS</h2>

            <p>Производственная система</p>

        </div>

        <form
            class="login-form"
            method="post"
            action="/login.php"
            autocomplete="on"
        >

            <div class="form-group <?= $error ? 'error' : '' ?>">

                <div class="input-wrapper">

                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e($_POST['email'] ?? '') ?>"
                        required
                        autocomplete="email"
                        autofocus
                    >

                    <label for="email">Email</label>

                    <span class="input-line"></span>

                </div>

            </div>


            <div class="form-group <?= $error ? 'error' : '' ?>">

                <div class="input-wrapper password-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >

                    <label for="password">Пароль</label>

                    <button
                        type="button"
                        class="password-toggle"
                        id="passwordToggle"
                        aria-label="Показать пароль"
                    >
                        <span class="toggle-icon"></span>
                    </button>

                    <span class="input-line"></span>

                </div>

            </div>


            <?php if ($error): ?>

                <div
                    class="error-message show"
                    role="alert"
                    style="opacity: 1; transform: translateY(0); margin-bottom: 20px;"
                >
                    <?= e($error) ?>
                </div>

            <?php endif; ?>


            <button
                type="submit"
                class="login-btn"
            >

                <span class="btn-text">
                    Войти
                </span>

                <span class="btn-loader"></span>

                <span class="btn-glow"></span>

            </button>

        </form>

    </div>


    <div class="background-effects">

        <div class="glow-orb glow-orb-1"></div>

        <div class="glow-orb glow-orb-2"></div>

        <div class="glow-orb glow-orb-3"></div>

    </div>

</div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const passwordInput = document.getElementById('password');
    const passwordToggle = document.getElementById('passwordToggle');
    const toggleIcon = passwordToggle
        ? passwordToggle.querySelector('.toggle-icon')
        : null;

    if (passwordToggle && passwordInput) {

        passwordToggle.addEventListener('click', function () {

            const isPassword = passwordInput.type === 'password';

            passwordInput.type = isPassword ? 'text' : 'password';

            passwordToggle.setAttribute(
                'aria-label',
                isPassword
                    ? 'Скрыть пароль'
                    : 'Показать пароль'
            );

            if (toggleIcon) {
                toggleIcon.classList.toggle(
                    'show-password',
                    isPassword
                );
            }

        });

    }


    const form = document.querySelector('.login-form');
    const button = document.querySelector('.login-btn');

    if (form && button) {

        form.addEventListener('submit', function () {

            if (form.checkValidity()) {
                button.classList.add('loading');
            }

        });

    }

});
</script>

</body>
</html>