<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

require_permission('glass.view', $user);

$stmt = $db->query("
    SELECT
        g.id,
        g.code,
        g.order_number,
        g.glass_type,
        g.width,
        g.height,
        g.quantity,
        g.status,
        g.current_location,
        g.created_at,
        u.name AS employee_name
    FROM glasses g
    LEFT JOIN users u ON u.id = g.employee_id
    ORDER BY g.id DESC
");

$glasses = $stmt->fetchAll();

function statusLabel(string $status): string
{
    return match ($status) {
        'created' => 'Создано',
        'production' => 'В производстве',
        'ready' => 'Готово',
        'warehouse' => 'На складе',
        'delivery' => 'Доставляется',
        'installed' => 'Установлено',
        'cancelled' => 'Отменено',
        default => $status,
    };
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Стекла — Optima Glass</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=4">
</head>
<body>
<main class="legacy-page glasses-page">


    <?php require __DIR__ . '/../src/partials/header.php'; ?>

    <h1>Optima Glass</h1>

    <p>
        <a href="/">Главная</a> |
        <a href="/logout.php">Выйти</a>
    </p>

    <h2>Стекла</h2>

    <?php if (!$glasses): ?>
        <p>Стекол пока нет.</p>
    <?php else: ?>
        <table border="1" cellpadding="8" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Код</th>
                    <th>Заказ</th>
                    <th>Тип</th>
                    <th>Размер</th>
                    <th>Количество</th>
                    <th>Статус</th>
                    <th>Место</th>
                    <th>Ответственный</th>
                    <th>Карточка</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($glasses as $glass): ?>
                    <tr>
                        <td><?= (int) $glass['id'] ?></td>

                        <td>
                            <strong>
                                <?= htmlspecialchars($glass['code'], ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                        </td>

                        <td>
                            <?= htmlspecialchars($glass['order_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($glass['glass_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            <?= (int) $glass['width'] ?> × <?= (int) $glass['height'] ?> мм
                        </td>

                        <td>
                            <?= (int) $glass['quantity'] ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(statusLabel($glass['status']), ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($glass['current_location'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($glass['employee_name'] ?? 'Не назначен', ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            <a href="/glass.php?code=<?= urlencode($glass['code']) ?>">
                                Открыть
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>


</main>
</body>
</html>
