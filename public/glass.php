<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require __DIR__ . '/../src/db.php';

$code = trim($_GET['code'] ?? '');

if ($code === '') {
    http_response_code(400);
    exit('Код стекла не указан.');
}

$stmt = $db->prepare("
    SELECT
        g.*,
        u.name AS employee_name
    FROM glasses g
    LEFT JOIN users u ON u.id = g.employee_id
    WHERE g.code = :code
    LIMIT 1
");

$stmt->execute([':code' => $code]);

$glass = $stmt->fetch();

if (!$glass) {
    http_response_code(404);
    exit('Стекло не найдено.');
}

$historyStmt = $db->prepare("
    SELECT
        h.created_at,
        h.old_status,
        h.new_status,
        h.old_location,
        h.new_location,
        u.name AS employee_name
    FROM glass_history h
    LEFT JOIN users u ON u.id = h.employee_id
    WHERE h.glass_id = :glass_id
    ORDER BY h.id DESC
");

$historyStmt->execute([':glass_id' => $glass['id']]);
$history = $historyStmt->fetchAll();

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
    <title><?= e($glass['code']) ?> — Optima Glass</title>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>

    <?php require __DIR__ . '/../src/partials/header.php'; ?>

    <h1>Optima Glass</h1>

    <p>
        <a href="/glasses.php">← Все стекла</a> |
    </p>

    <h2><?= e($glass['code']) ?></h2>

    <p><strong>Заказ:</strong> <?= e($glass['order_number']) ?></p>
    <p><strong>Тип:</strong> <?= e($glass['glass_type']) ?></p>
    <p><strong>Размер:</strong> <?= (int) $glass['width'] ?> × <?= (int) $glass['height'] ?> мм</p>
    <p><strong>Количество:</strong> <?= (int) $glass['quantity'] ?></p>
    <p><strong>Статус:</strong> <?= e(statusLabel($glass['status'])) ?></p>
    <p><strong>Место:</strong> <?= e($glass['current_location']) ?></p>
    <p><strong>Ответственный:</strong> <?= e($glass['employee_name'] ?? 'Не назначен') ?></p>
    <p><strong>Комментарий:</strong> <?= e($glass['comment']) ?></p>
    <p><a href="/update_glass.php?code=<?= urlencode($glass['code']) ?>">Изменить статус</a></p>

    <h2>История перемещений</h2>

    <?php if (!$history): ?>
        <p>История пока пуста.</p>
    <?php else: ?>
        <table border="1" cellpadding="8" cellspacing="0">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Сотрудник</th>
                    <th>Статус</th>
                    <th>Место</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $item): ?>
                    <tr>
                        <td>
                            <?= e($item['created_at']) ?>
                        </td>

                        <td>
                            <?= e($item['employee_name'] ?? 'Неизвестно') ?>
                        </td>

                        <td>
                            <?= e(statusLabel($item['old_status'])) ?>
                            →
                            <?= e(statusLabel($item['new_status'])) ?>
                        </td>

                        <td>
                            <?= e($item['old_location'] ?? '—') ?>
                            →
                            <?= e($item['new_location']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</body>
</html>
