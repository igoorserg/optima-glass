
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

        } else {
            $error = 'Неверный логин или пароль.';
        }
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

```
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Вход — Optima Glass</title>

<link
    rel="stylesheet"
    href="/assets/css/app.css"
>


</head>

<body class="login-page">

<div class="login-background">


<div class="login-glow login-glow-1"></div>
<div class="login-glow login-glow-2"></div>

<main class="login-container">

    <section class="login-card">

        <div class="login-brand">

            <div class="login-logo">
                OG
            </div>

            <div>
                <div class="login-brand-name">
                    OPTIMA GLASS
                </div>

                <div class="login-brand-subtitle">
                    Производственная система
                </div>
            </div>

        </div>

        <div class="login-header">

            <h1>Добро пожаловать</h1>

            <p>
                Войдите в систему для продолжения работы
            </p>

        </div>

        <?php if ($error): ?>

            <div class="login-error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <form method="post" class="login-form">

            <div class="form-group">

                <label for="login">
                    Логин
                </label>

                <input
                    type="text"
                    id="login"
                    name="login"
                    value="<?= e($_POST['login'] ?? '') ?>"
                    autocomplete="username"
                    placeholder="Введите логин"
                    autofocus
                    required
                >

            </div>

            <div class="form-group">

                <label for="password">
                    Пароль
                </label>

                <div class="password-field">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        placeholder="Введите пароль"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        onclick="togglePassword()"
                        aria-label="Показать пароль"
                    >
                        ◉
                    </button>

                </div>

            </div>

            <button
                type="submit"
                class="login-button"
            >
                <span>Войти в систему</span>
                <span class="login-button-arrow">→</span>
            </button>

        </form>

        <div class="login-footer">

            <span>
                Optima Glass
            </span>

            <span class="login-footer-dot">
                •
            </span>

            <span>
                Production Management
            </span>

        </div>

    </section>

</main>
```

</div>

<script>
function togglePassword() {

    const password = document.getElementById('password');
    const button = document.querySelector('.password-toggle');

    if (password.type === 'password') {
        password.type = 'text';
        button.textContent = '◉';
    } else {
        password.type = 'password';
        button.textContent = '◉';
    }
}
</script>

</body>
</html>
