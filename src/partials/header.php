<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<header>
    <div>
        <strong>OPTIMA GLASS</strong>
    </div>

    <nav>
        <a href="/">Главная</a>
        <a href="/glasses.php">Стекла</a>
        <a href="/production.php">Производство</a>

        <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
            <a href="/employees.php">Сотрудники</a>
            <a href="/admin/stages.php">Производственные этапы</a>
            <a href="/admin/glass_types.php">Типы стекла</a>
        <a href="/admin/import.php">Импорт заказов</a>
        <?php endif; ?>

        <a href="/logout.php">Выйти</a>
    </nav>
</header>

<hr>
