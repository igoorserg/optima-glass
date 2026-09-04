<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

function eDash(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$name = $user['name'] ?? '';
$role = $user['role'] ?? '';

$roleLabels = [
    'superadmin'      => 'Суперадміністратор',
    'admin'           => 'Адміністратор',
    'manager'         => 'Менеджер',
    'section_manager' => 'Начальник дільниці',
    'employee'        => 'Працівник',
];

$roleLabel =
    $roleLabels[$role]
    ?? $role;

/*
|--------------------------------------------------------------------------
| KPI
|--------------------------------------------------------------------------
*/

$newOrders = (int) $db->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'new'
")->fetchColumn();

$inProduction = (int) $db->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'in_production'
")->fetchColumn();

$completedOrders = (int) $db->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'completed'
")->fetchColumn();

$totalGlass = (int) $db->query("
    SELECT COUNT(*)
    FROM glasses
")->fetchColumn();

$rejectedGlass = (int) $db->query("
    SELECT COUNT(*)
    FROM glasses
    WHERE status = 'rejected'
")->fetchColumn();

$urgentOrders = (int) $db->query("
    SELECT COUNT(*)
    FROM orders
    WHERE priority >= 3
      AND status != 'completed'
")->fetchColumn();

$overdueOrders = (int) $db->query("
    SELECT COUNT(*)
    FROM orders
    WHERE planned_date IS NOT NULL
      AND planned_date != ''
      AND DATE(planned_date) < DATE('now', 'localtime')
      AND status != 'completed'
")->fetchColumn();

$waitingShipment = (int) $db->query("
    SELECT COUNT(DISTINCT o.id)
    FROM orders o
    INNER JOIN glasses g
        ON g.order_id = o.id
    WHERE o.status = 'in_production'
      AND g.status = 'waiting'
      AND (
            g.current_location = 'Відвантаження'
            OR g.current_location = 'Отгрузка'
      )
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| Розподіл скла по етапах
|--------------------------------------------------------------------------
*/

$stageStats = $db->query("
    SELECT
        COALESCE(
            rs.name,
            g.current_location,
            'Не визначено'
        ) AS stage,
        COUNT(*) AS total
    FROM glasses g
    LEFT JOIN route_steps rs
        ON rs.id = g.current_step_id
    WHERE g.status != 'completed'
    GROUP BY stage
    ORDER BY total DESC
")->fetchAll();

/*
|--------------------------------------------------------------------------
| Останні замовлення
|--------------------------------------------------------------------------
*/

$recentOrders = $db->query("
    SELECT
        o.id,
        o.order_number,
        o.customer_name,
        o.status,
        o.priority,
        o.planned_date,
        o.created_at,
        COUNT(g.id) AS glass_count
    FROM orders o
    LEFT JOIN glasses g
        ON g.order_id = o.id
    GROUP BY o.id
    ORDER BY o.id DESC
    LIMIT 8
")->fetchAll();

/*
|--------------------------------------------------------------------------
| Остання активність
|--------------------------------------------------------------------------
*/

$recentOperations = $db->query("
    SELECT
        go.id,
        go.operation_type,
        go.from_stage,
        go.to_stage,
        go.result,
        go.created_at,
        g.code,
        g.order_number,
        u.name AS employee_name
    FROM glass_operations go
    INNER JOIN glasses g
        ON g.id = go.glass_id
    LEFT JOIN users u
        ON u.id = go.employee_id
    ORDER BY go.id DESC
    LIMIT 10
")->fetchAll();

function orderStatusLabel(string $status): string
{
    return match ($status) {
        'new' => 'Нове',
        'in_production' => 'У виробництві',
        'completed' => 'Завершено',
        default => $status,
    };
}

function orderStatusClass(string $status): string
{
    return match ($status) {
        'new' => 'og-chip-warning',
        'in_production' => '',
        'completed' => 'og-chip-success',
        default => '',
    };
}

?>
<!DOCTYPE html>
<html lang="uk">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Dashboard — OPTIMA GLASS
    </title>

</head>

<body>

<?php
require __DIR__
    . '/../src/partials/header.php';
?>

<?php if (
    $overdueOrders > 0
    || $rejectedGlass > 0
): ?>

<section
    class="og-attention"
    id="dashboardAttention"
>

    <button
        type="button"
        class="og-attention-toggle"
        id="dashboardAttentionToggle"
        aria-expanded="true"
        aria-controls="dashboardAttentionBody"
    >

        <span class="og-attention-title">

            <span class="og-attention-icon">
                ⚠️
            </span>

            <strong>
                Потребує уваги
            </strong>

        </span>


        <span class="og-attention-summary">

            <?php if ($overdueOrders > 0): ?>

                <span>
                    Прострочені:
                    <strong>
                        <?= $overdueOrders ?>
                    </strong>
                </span>

            <?php endif; ?>


            <?php if ($rejectedGlass > 0): ?>

                <span>
                    Брак:
                    <strong>
                        <?= $rejectedGlass ?>
                    </strong>
                </span>

            <?php endif; ?>

            <span
                class="og-attention-chevron"
                id="dashboardAttentionChevron"
            >
                ▴
            </span>

        </span>

    </button>


    <div
        class="og-attention-body"
        id="dashboardAttentionBody"
    >

        <div class="og-attention-actions">

            <?php if ($overdueOrders > 0): ?>

                <a
                    href="/dashboard_details.php?type=overdue"
                    class="og-attention-action"
                >

                    <span class="og-attention-action-icon">
                        ⏰
                    </span>

                    <span>

                        <strong>
                            Прострочені замовлення
                        </strong>

                        <small>
                            <?= $overdueOrders ?>
                            —
                            переглянути список
                        </small>

                    </span>

                    <span class="og-attention-go">
                        →
                    </span>

                </a>

            <?php endif; ?>


            <?php if ($rejectedGlass > 0): ?>

                <a
                    href="/dashboard_details.php?type=rejected"
                    class="og-attention-action"
                >

                    <span class="og-attention-action-icon">
                        ❌
                    </span>

                    <span>

                        <strong>
                            Забраковане скло
                        </strong>

                        <small>
                            <?= $rejectedGlass ?>
                            —
                            переглянути список
                        </small>

                    </span>

                    <span class="og-attention-go">
                        →
                    </span>

                </a>

            <?php endif; ?>

        </div>

    </div>

</section>


<script>
(function () {

    const box =
        document.getElementById(
            'dashboardAttention'
        );

    const toggle =
        document.getElementById(
            'dashboardAttentionToggle'
        );

    const body =
        document.getElementById(
            'dashboardAttentionBody'
        );

    const chevron =
        document.getElementById(
            'dashboardAttentionChevron'
        );

    if (
        !box
        || !toggle
        || !body
        || !chevron
    ) {
        return;
    }

    const storageKey =
        'optimaGlassAttentionCollapsed';

    let collapsed =
        localStorage.getItem(storageKey)
        === '1';

    function render() {

        box.classList.toggle(
            'is-collapsed',
            collapsed
        );

        body.hidden = collapsed;

        toggle.setAttribute(
            'aria-expanded',
            collapsed
                ? 'false'
                : 'true'
        );

        chevron.textContent =
            collapsed
                ? '▾'
                : '▴';
    }

    toggle.addEventListener(
        'click',
        function () {

            collapsed = !collapsed;

            localStorage.setItem(
                storageKey,
                collapsed
                    ? '1'
                    : '0'
            );

            render();
        }
    );

    render();

})();
</script>

<?php endif; ?>



<main class="og-page">

    <div class="og-page-header">

        <div>

            <h1 class="og-page-title">
                Панель керування
            </h1>

            <p class="og-page-subtitle">
                Основні показники виробництва та поточний стан замовлень
            </p>

        </div>

        <div class="og-user-badge">
            👤 <?= eDash($name) ?>
            ·
            <?= eDash($roleLabel) ?>
        </div>

    </div>


    <section class="og-dashboard-kpis">

        <a
            href="/production.php"
            class="og-stat-card og-stat-blue"
        >

            <div class="og-stat-head">

                <span class="og-stat-icon">
                    🏭
                </span>

                <span class="og-stat-label">
                    У виробництві
                </span>

            </div>

            <div class="og-stat-value">
                <?= $inProduction ?>
            </div>

            <div class="og-stat-caption">
                активних замовлень
            </div>

        </a>


        <a
            href="/manager.php"
            class="og-stat-card og-stat-yellow"
        >

            <div class="og-stat-head">

                <span class="og-stat-icon">
                    🆕
                </span>

                <span class="og-stat-label">
                    Нові замовлення
                </span>

            </div>

            <div class="og-stat-value">
                <?= $newOrders ?>
            </div>

            <div class="og-stat-caption">
                очікують запуску
            </div>

        </a>


        <a
            href="/shipping.php"
            class="og-stat-card og-stat-orange"
        >

            <div class="og-stat-head">

                <span class="og-stat-icon">
                    🚚
                </span>

                <span class="og-stat-label">
                    До відвантаження
                </span>

            </div>

            <div class="og-stat-value">
                <?= $waitingShipment ?>
            </div>

            <div class="og-stat-caption">
                готових замовлень
            </div>

        </a>


        <div class="og-stat-card og-stat-green">

            <div class="og-stat-head">

                <span class="og-stat-icon">
                    ✅
                </span>

                <span class="og-stat-label">
                    Завершені
                </span>

            </div>

            <div class="og-stat-value">
                <?= $completedOrders ?>
            </div>

            <div class="og-stat-caption">
                завершених замовлень
            </div>

        </div>


        <a
            href="/glasses.php"
            class="og-stat-card"
        >

            <div class="og-stat-head">

                <span class="og-stat-icon">
                    🪟
                </span>

                <span class="og-stat-label">
                    Усього скла
                </span>

            </div>

            <div class="og-stat-value">
                <?= $totalGlass ?>
            </div>

            <div class="og-stat-caption">
                одиниць у системі
            </div>

        </a>


        <a
            href="/dashboard_details.php?type=rejected"
            class="og-stat-card og-stat-red"
        >
<div class="og-stat-head">

                <span class="og-stat-icon">
                    ❌
                </span>

                <span class="og-stat-label">
                    Брак
                </span>

            </div>

            <div class="og-stat-value">
                <?= $rejectedGlass ?>
            </div>

            <div class="og-stat-caption">
                одиниць забраковано
            </div>


        </a>


        <a
            href="/dashboard_details.php?type=urgent"
            class="og-stat-card og-stat-purple"
        >
<div class="og-stat-head">

                <span class="og-stat-icon">
                    ⚡
                </span>

                <span class="og-stat-label">
                    Термінові
                </span>

            </div>

            <div class="og-stat-value">
                <?= $urgentOrders ?>
            </div>

            <div class="og-stat-caption">
                активних замовлень
            </div>


        </a>


        <a
            href="/dashboard_details.php?type=overdue"
            class="og-stat-card og-stat-red"
        >
<div class="og-stat-head">

                <span class="og-stat-icon">
                    ⏰
                </span>

                <span class="og-stat-label">
                    Прострочені
                </span>

            </div>

            <div class="og-stat-value">
                <?= $overdueOrders ?>
            </div>

            <div class="og-stat-caption">
                за плановою датою
            </div>


        </a>

    </section>






    <div class="og-dashboard-layout">

        <div>

            <section class="og-card">

                <div class="og-card-header">

                    <div>

                        <h2 class="og-card-title">
                            Останні замовлення
                        </h2>

                        <div class="og-card-note">
                            Останні створені замовлення в системі
                        </div>

                    </div>

                    <?php if (
                        can(
                            'orders.view',
                            $user
                        )
                    ): ?>

                        <a
                            href="/production.php"
                            class="og-link"
                        >
                            Переглянути всі →
                        </a>

                    <?php endif; ?>

                </div>


                <?php if ($recentOrders): ?>

                    <div class="og-table-wrap">

                        <table class="og-table">

                            <thead>

                                <tr>
                                    <th>
                                        Замовлення
                                    </th>

                                    <th>
                                        Клієнт
                                    </th>

                                    <th>
                                        Скло
                                    </th>

                                    <th>
                                        Статус
                                    </th>

                                    <th>
                                        Пріоритет
                                    </th>

                                    <th>
                                        План
                                    </th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $recentOrders
                                    as $order
                                ): ?>

                                    <tr>

                                        <td>

                                            <a
                                                href="/order.php?id=<?= (int) $order['id'] ?>"
                                                class="og-table-link"
                                            >
                                                №<?= eDash(
                                                    $order['order_number']
                                                ) ?>
                                            </a>

                                        </td>

                                        <td>

                                            <?= eDash(
                                                $order['customer_name']
                                                ?: '—'
                                            ) ?>

                                        </td>

                                        <td>
                                            <?= (int)
                                                $order['glass_count'] ?>
                                        </td>

                                        <td>

                                            <span
                                                class="og-chip <?= eDash(
                                                    orderStatusClass(
                                                        $order['status']
                                                    )
                                                ) ?>"
                                            >

                                                <?= eDash(
                                                    orderStatusLabel(
                                                        $order['status']
                                                    )
                                                ) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?php if (
                                                (int) $order['priority'] >= 3
                                            ): ?>

                                                <span class="og-chip og-chip-danger">
                                                    ⚡ Терміново
                                                </span>

                                            <?php elseif (
                                                (int) $order['priority'] === 2
                                            ): ?>

                                                <span class="og-chip og-chip-warning">
                                                    Підвищений
                                                </span>

                                            <?php else: ?>

                                                <span class="og-muted">
                                                    Звичайний
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php if (
                                                !empty(
                                                    $order['planned_date']
                                                )
                                            ): ?>

                                                <?= eDash(
                                                    $order['planned_date']
                                                ) ?>

                                            <?php else: ?>

                                                <span class="og-muted">
                                                    —
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="og-empty">
                        Замовлень поки немає.
                    </div>

                <?php endif; ?>

            </section>


            <section class="og-card">

                <div class="og-card-header">

                    <div>

                        <h2 class="og-card-title">
                            Остання активність
                        </h2>

                        <div class="og-card-note">
                            Останні операції зі склом
                        </div>

                    </div>

                </div>


                <?php if ($recentOperations): ?>

                    <div class="og-activity-list">

                        <?php foreach (
                            $recentOperations
                            as $operation
                        ): ?>

                            <div class="og-activity-item">

                                <div class="og-activity-icon">

                                    <?php
                                    echo match (
                                        $operation['operation_type']
                                    ) {
                                        'shipping' => '🚚',
                                        'rejection' => '❌',
                                        default => '⚙️',
                                    };
                                    ?>

                                </div>

                                <div class="og-activity-content">

                                    <div class="og-activity-title">

                                        <a
                                            href="/glass.php?code=<?= urlencode(
                                                $operation['code']
                                            ) ?>"
                                            class="og-table-link"
                                        >

                                            <?= eDash(
                                                $operation['code']
                                            ) ?>

                                        </a>

                                        <?php if (
                                            $operation['to_stage']
                                        ): ?>

                                            →
                                            <?= eDash(
                                                $operation['to_stage']
                                            ) ?>

                                        <?php endif; ?>

                                    </div>

                                    <div class="og-activity-meta">

                                        Замовлення
                                        <?= eDash(
                                            $operation['order_number']
                                            ?: '—'
                                        ) ?>

                                        ·

                                        <?= eDash(
                                            $operation['employee_name']
                                            ?: 'Система'
                                        ) ?>

                                        ·

                                        <?= eDash(
                                            $operation['created_at']
                                        ) ?>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="og-empty">
                        Операцій поки немає.
                    </div>

                <?php endif; ?>

            </section>

        </div>


        <aside>

            <section class="og-card">

                <div class="og-card-header">

                    <div>

                        <h2 class="og-card-title">
                            Стан виробництва
                        </h2>

                        <div class="og-card-note">
                            Скло по поточних етапах
                        </div>

                    </div>

                </div>


                <?php if ($stageStats): ?>

                    <div class="og-stage-list">

                        <?php foreach (
                            $stageStats
                            as $row
                        ): ?>

                            <div class="og-stage-row">

                                <span class="og-stage-name">
                                    <?= eDash(
                                        $row['stage']
                                    ) ?>
                                </span>

                                <span class="og-stage-count">
                                    <?= (int)
                                        $row['total'] ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="og-empty">
                        Активного скла немає.
                    </div>

                <?php endif; ?>

            </section>


            <section class="og-card">

                <div class="og-card-header">

                    <div>

                        <h2 class="og-card-title">
                            Швидкі дії
                        </h2>

                    </div>

                </div>

                <div class="og-card-body">

                    <div class="og-quick-list">

                        <?php if (
                            can(
                                'orders.create',
                                $user
                            )
                        ): ?>

                            <a
                                href="/glass_create.php"
                                class="og-quick-link"
                            >

                                <span class="og-quick-icon">
                                    ➕
                                </span>

                                <span>

                                    <span class="og-quick-title">
                                        Нове замовлення
                                    </span>

                                    <span class="og-quick-description">
                                        Створити замовлення
                                    </span>

                                </span>

                            </a>

                        <?php endif; ?>


                        <?php if (
                            can(
                                'production.view',
                                $user
                            )
                        ): ?>

                            <a
                                href="/production.php"
                                class="og-quick-link"
                            >

                                <span class="og-quick-icon">
                                    🏭
                                </span>

                                <span>

                                    <span class="og-quick-title">
                                        Виробництво
                                    </span>

                                    <span class="og-quick-description">
                                        Поточна черга
                                    </span>

                                </span>

                            </a>

                        <?php endif; ?>


                        <?php if (
                            can(
                                'production.ship',
                                $user
                            )
                        ): ?>

                            <a
                                href="/shipping.php"
                                class="og-quick-link"
                            >

                                <span class="og-quick-icon">
                                    🚚
                                </span>

                                <span>

                                    <span class="og-quick-title">
                                        Відвантаження
                                    </span>

                                    <span class="og-quick-description">
                                        Готові замовлення
                                    </span>

                                </span>

                            </a>

                        <?php endif; ?>


                        <?php if (
                            can(
                                'glass.view',
                                $user
                            )
                        ): ?>

                            <a
                                href="/glasses.php"
                                class="og-quick-link"
                            >

                                <span class="og-quick-icon">
                                    🪟
                                </span>

                                <span>

                                    <span class="og-quick-title">
                                        Скло
                                    </span>

                                    <span class="og-quick-description">
                                        Усі одиниці скла
                                    </span>

                                </span>

                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </section>

        </aside>

    </div>

</main>

</body>
</html>
