<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

function ddE(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$type = trim($_GET['type'] ?? '');

$allowedTypes = [
    'rejected',
    'urgent',
    'overdue',
];

if (!in_array($type, $allowedTypes, true)) {
    http_response_code(404);
    exit('Розділ не знайдено.');
}

/*
|--------------------------------------------------------------------------
| БРАК
|--------------------------------------------------------------------------
*/

if ($type === 'rejected') {

    require_permission('glass.view', $user);

    $pageTitle = 'Забраковане скло';
    $pageSubtitle = 'Список усіх одиниць скла зі статусом «Брак»';
    $pageIcon = '❌';

    $stmt = $db->query("
        SELECT
            g.id,
            g.code,
            g.order_number,
            g.glass_type,
            g.width,
            g.height,
            g.thickness,
            g.quantity,
            g.status,
            g.current_location,
            g.comment,
            g.updated_at,
            o.customer_name
        FROM glasses g
        LEFT JOIN orders o
            ON o.id = g.order_id
        WHERE g.status = 'rejected'
        ORDER BY g.updated_at DESC, g.id DESC
    ");

    $rows = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| ТЕРМІНОВІ
|--------------------------------------------------------------------------
*/

if ($type === 'urgent') {

    require_permission('orders.view', $user);

    $pageTitle = 'Термінові замовлення';
    $pageSubtitle = 'Активні замовлення з високим пріоритетом';
    $pageIcon = '⚡';

    $stmt = $db->query("
        SELECT
            o.id,
            o.order_number,
            o.customer_name,
            o.status,
            o.priority,
            o.planned_date,
            o.created_at,
            o.production_started_at,
            COUNT(g.id) AS glass_count,
            SUM(
                CASE
                    WHEN g.status = 'rejected'
                    THEN 1
                    ELSE 0
                END
            ) AS rejected_count
        FROM orders o
        LEFT JOIN glasses g
            ON g.order_id = o.id
        WHERE o.priority >= 3
          AND o.status != 'completed'
        GROUP BY o.id
        ORDER BY
            CASE
                WHEN o.planned_date IS NULL
                     OR o.planned_date = ''
                THEN 1
                ELSE 0
            END,
            o.planned_date ASC,
            o.id DESC
    ");

    $rows = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| ПРОСТРОЧЕНІ
|--------------------------------------------------------------------------
*/

if ($type === 'overdue') {

    require_permission('orders.view', $user);

    $pageTitle = 'Прострочені замовлення';
    $pageSubtitle = 'Замовлення, у яких минула планова дата виконання';
    $pageIcon = '⏰';

    $stmt = $db->query("
        SELECT
            o.id,
            o.order_number,
            o.customer_name,
            o.status,
            o.priority,
            o.planned_date,
            o.created_at,
            o.production_started_at,
            CAST(
                julianday('now', 'localtime')
                - julianday(DATE(o.planned_date))
                AS INTEGER
            ) AS overdue_days,
            COUNT(g.id) AS glass_count,
            SUM(
                CASE
                    WHEN g.status = 'rejected'
                    THEN 1
                    ELSE 0
                END
            ) AS rejected_count
        FROM orders o
        LEFT JOIN glasses g
            ON g.order_id = o.id
        WHERE o.planned_date IS NOT NULL
          AND o.planned_date != ''
          AND DATE(o.planned_date) < DATE('now', 'localtime')
          AND o.status != 'completed'
        GROUP BY o.id
        ORDER BY
            DATE(o.planned_date) ASC,
            o.id DESC
    ");

    $rows = $stmt->fetchAll();
}

function ddOrderStatus(string $status): string
{
    return match ($status) {
        'new' => 'Нове',
        'in_production' => 'У виробництві',
        'completed' => 'Завершено',
        default => $status,
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
        <?= ddE($pageTitle) ?> — OPTIMA GLASS
    </title>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="og-page">

    <div class="og-page-header">

        <div>

            <div class="og-detail-back">
                <a href="/">
                    ← Назад до панелі
                </a>
            </div>

            <h1 class="og-page-title">
                <?= $pageIcon ?>
                <?= ddE($pageTitle) ?>
            </h1>

            <p class="og-page-subtitle">
                <?= ddE($pageSubtitle) ?>
            </p>

        </div>

        <div class="og-user-badge">
            Знайдено:
            <strong><?= count($rows) ?></strong>
        </div>

    </div>


    <?php if ($type === 'rejected'): ?>

        <section class="og-card">

            <?php if ($rows): ?>

                <div class="og-table-wrap">

                    <table class="og-table">

                        <thead>

                            <tr>
                                <th>Код скла</th>
                                <th>Замовлення</th>
                                <th>Клієнт</th>
                                <th>Тип скла</th>
                                <th>Розмір</th>
                                <th>Дільниця</th>
                                <th>Причина / коментар</th>
                                <th>Оновлено</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($rows as $row): ?>

                                <tr>

                                    <td>

                                        <a
                                            href="/glass.php?code=<?= urlencode(
                                                $row['code']
                                            ) ?>"
                                            class="og-table-link"
                                        >
                                            <?= ddE($row['code']) ?>
                                        </a>

                                    </td>

                                    <td>

                                        <?php if (!empty($row['order_number'])): ?>

                                            <?= ddE(
                                                $row['order_number']
                                            ) ?>

                                        <?php else: ?>

                                            <span class="og-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= ddE(
                                            $row['customer_name']
                                            ?: '—'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= ddE(
                                            $row['glass_type']
                                            ?: '—'
                                        ) ?>
                                    </td>

                                    <td>

                                        <?php if (
                                            !empty($row['width'])
                                            && !empty($row['height'])
                                        ): ?>

                                            <?= (int) $row['width'] ?>
                                            ×
                                            <?= (int) $row['height'] ?>

                                        <?php else: ?>

                                            <span class="og-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= ddE(
                                            $row['current_location']
                                            ?: '—'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= ddE(
                                            $row['comment']
                                            ?: 'Причину не вказано'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= ddE(
                                            $row['updated_at']
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="og-empty">
                    Забракованого скла немає.
                </div>

            <?php endif; ?>

        </section>

    <?php else: ?>

        <section class="og-card">

            <?php if ($rows): ?>

                <div class="og-table-wrap">

                    <table class="og-table">

                        <thead>

                            <tr>
                                <th>Замовлення</th>
                                <th>Клієнт</th>
                                <th>Статус</th>
                                <th>Пріоритет</th>
                                <th>Скла</th>
                                <th>Брак</th>
                                <th>Планова дата</th>

                                <?php if (
                                    $type === 'overdue'
                                ): ?>

                                    <th>Прострочено</th>

                                <?php endif; ?>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($rows as $row): ?>

                                <tr>

                                    <td>

                                        <a
                                            href="/order.php?id=<?= (int) $row['id'] ?>"
                                            class="og-table-link"
                                        >
                                            №<?= ddE(
                                                $row['order_number']
                                            ) ?>
                                        </a>

                                    </td>

                                    <td>
                                        <?= ddE(
                                            $row['customer_name']
                                            ?: '—'
                                        ) ?>
                                    </td>

                                    <td>

                                        <?php if (
                                            $row['status']
                                            === 'in_production'
                                        ): ?>

                                            <span class="og-chip">
                                                У виробництві
                                            </span>

                                        <?php elseif (
                                            $row['status']
                                            === 'new'
                                        ): ?>

                                            <span class="og-chip og-chip-warning">
                                                Нове
                                            </span>

                                        <?php else: ?>

                                            <span class="og-chip">
                                                <?= ddE(
                                                    ddOrderStatus(
                                                        $row['status']
                                                    )
                                                ) ?>
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if (
                                            (int) $row['priority'] >= 3
                                        ): ?>

                                            <span class="og-chip og-chip-danger">
                                                ⚡ Терміново
                                            </span>

                                        <?php elseif (
                                            (int) $row['priority'] === 2
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
                                        <?= (int)
                                            $row['glass_count'] ?>
                                    </td>

                                    <td>

                                        <?php if (
                                            (int)
                                            $row['rejected_count'] > 0
                                        ): ?>

                                            <span class="og-chip og-chip-danger">
                                                <?= (int)
                                                    $row['rejected_count'] ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="og-muted">
                                                0
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if (
                                            !empty(
                                                $row['planned_date']
                                            )
                                        ): ?>

                                            <?= ddE(
                                                $row['planned_date']
                                            ) ?>

                                        <?php else: ?>

                                            <span class="og-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <?php if (
                                        $type === 'overdue'
                                    ): ?>

                                        <td>

                                            <span class="og-chip og-chip-danger">

                                                <?= max(
                                                    1,
                                                    (int)
                                                    $row['overdue_days']
                                                ) ?>

                                                дн.

                                            </span>

                                        </td>

                                    <?php endif; ?>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="og-empty">

                    <?php if ($type === 'urgent'): ?>
                        Термінових замовлень немає.
                    <?php else: ?>
                        Прострочених замовлень немає.
                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>

</body>
</html>
