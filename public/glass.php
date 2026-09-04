```php
<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

require_permission('glass.view', $user);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

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

$code = trim($_GET['code'] ?? '');

if ($code === '') {
    http_response_code(400);
    exit('Код стекла не указан.');
}

/*
|--------------------------------------------------------------------------
| Получаем стекло
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        g.*,
        u.name AS employee_name
    FROM glasses g
    LEFT JOIN users u ON u.id = g.employee_id
    WHERE g.code = :code
    LIMIT 1
");

$stmt->execute([
    ':code' => $code
]);

$glass = $stmt->fetch();

if (!$glass) {
    http_response_code(404);
    exit('Стекло не найдено.');
}

/*
|--------------------------------------------------------------------------
| История
|--------------------------------------------------------------------------
*/

$historyStmt = $db->prepare("
    SELECT
        h.created_at,
        h.old_status,
        h.new_status,
        h.old_location,
        h.new_location,
        h.comment,
        u.name AS employee_name
    FROM glass_history h
    LEFT JOIN users u ON u.id = h.employee_id
    WHERE h.glass_id = :glass_id
    ORDER BY h.id DESC
");

$historyStmt->execute([
    ':glass_id' => $glass['id']
]);

$history = $historyStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Участки производства
|--------------------------------------------------------------------------
*/

$locations = [
    'Порезка',
    'Обработка',
    'Закалка',
    'Емалит',
    'Триплекс',
    'Сборка',
];

?>
<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= e($glass['code']) ?> — Optima Glass
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <script
        src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
    ></script>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="container">

    <div class="card">

        <h1>
            Стекло <?= e($glass['code']) ?>
        </h1>

        <div
            id="qrcode"
            style="margin: 20px 0;"
        ></div>

        <p>
            <strong>Код:</strong>
            <?= e($glass['code']) ?>
        </p>

        <?php if (!empty($glass['order_number'])): ?>

            <p>
                <strong>Заказ:</strong>
                <?= e($glass['order_number']) ?>
            </p>

        <?php endif; ?>

        <?php if (!empty($glass['glass_type'])): ?>

            <p>
                <strong>Тип:</strong>
                <?= e($glass['glass_type']) ?>
            </p>

        <?php endif; ?>

        <?php if (isset($glass['width'], $glass['height'])): ?>

            <p>
                <strong>Размер:</strong>
                <?= (int) $glass['width'] ?>
                ×
                <?= (int) $glass['height'] ?>
                мм
            </p>

        <?php endif; ?>

        <?php if (isset($glass['quantity'])): ?>

            <p>
                <strong>Количество:</strong>
                <?= (int) $glass['quantity'] ?>
            </p>

        <?php endif; ?>

        <?php if (isset($glass['status'])): ?>

            <p>
                <strong>Статус:</strong>
                <?= e(statusLabel((string) $glass['status'])) ?>
            </p>

        <?php endif; ?>

        <?php if (
            isset($glass['current_location']) &&
            $glass['current_location'] !== ''
        ): ?>

            <p>
                <strong>Участок:</strong>
                <?= e($glass['current_location']) ?>
            </p>

        <?php endif; ?>

        <?php if (
            isset($glass['employee_name']) &&
            $glass['employee_name']
        ): ?>

            <p>
                <strong>Ответственный:</strong>
                <?= e($glass['employee_name']) ?>
            </p>

        <?php else: ?>

            <p>
                <strong>Ответственный:</strong>
                Не назначен
            </p>

        <?php endif; ?>

        <?php if (
            isset($glass['comment']) &&
            $glass['comment'] !== ''
        ): ?>

            <p>
                <strong>Комментарий:</strong>
                <?= e($glass['comment']) ?>
            </p>

        <?php endif; ?>

        <p>
            <?php if (can('glass.move', $user)): ?>

            <a href="/update_glass.php?code=<?= urlencode($glass['code']) ?>">
                Изменить статус и участок
            </a>

            <?php endif; ?>
        </p>

    </div>


    <?php if ($history): ?>

        <div class="card">

            <h2>История перемещений</h2>

            <?php foreach ($history as $item): ?>

                <div class="history-item">

                    <p>
                        <strong>
                            <?= e($item['created_at']) ?>
                        </strong>
                    </p>

                    <p>

                        <?php if ($item['old_status'] !== null): ?>

                            <?= e(statusLabel((string) $item['old_status'])) ?>

                            →

                            <?= e(statusLabel((string) $item['new_status'])) ?>

                        <?php else: ?>

                            <?= e(statusLabel((string) $item['new_status'])) ?>

                        <?php endif; ?>

                    </p>


                    <?php if (
                        $item['old_location'] !== null ||
                        $item['new_location'] !== null
                    ): ?>

                        <p>

                            Участок:

                            <?= e($item['old_location'] ?? '—') ?>

                            →

                            <?= e($item['new_location'] ?? '—') ?>

                        </p>

                    <?php endif; ?>


                    <?php if (
                        isset($item['employee_name']) &&
                        $item['employee_name']
                    ): ?>

                        <p>
                            Сотрудник:
                            <?= e($item['employee_name']) ?>
                        </p>

                    <?php endif; ?>


                    <?php if (
                        isset($item['comment']) &&
                        $item['comment'] !== ''
                    ): ?>

                        <p>
                            Комментарий:
                            <?= e($item['comment']) ?>
                        </p>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="card">

            <h2>История</h2>

            <p>История пока пуста.</p>

        </div>

    <?php endif; ?>

</main>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const qrElement = document.getElementById('qrcode');

    if (
        qrElement &&
        typeof QRCode !== 'undefined'
    ) {

        new QRCode(qrElement, {

            text:
                window.location.origin +
                '/glass.php?code=<?= urlencode($glass['code']) ?>',

            width: 220,

            height: 220

        });

    }

});

</script>

</body>

</html>
