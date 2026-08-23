<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require __DIR__ . '/../src/db.php';

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

$statuses = [
    'created' => 'Создано',
    'production' => 'В производстве',
    'ready' => 'Готово',
    'warehouse' => 'На складе',
    'delivery' => 'Доставляется',
    'installed' => 'Установлено',
    'cancelled' => 'Отменено',
];

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
| Изменение статуса
|--------------------------------------------------------------------------
*/

$statusError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $newStatus = trim($_POST['status'] ?? '');

    if (!isset($statuses[$newStatus])) {

        $statusError = 'Выбран недопустимый статус.';

    } elseif ($newStatus === $glass['status']) {

        $statusError = 'Статус уже установлен.';

    } else {

        try {

            $db->beginTransaction();

            $oldStatus = $glass['status'];
            $oldLocation = $glass['current_location'] ?? null;

            /*
             * Обновляем статус стекла.
             */
            $updateStmt = $db->prepare("
                UPDATE glasses
                SET status = :status
                WHERE id = :id
            ");

            $updateStmt->execute([
                ':status' => $newStatus,
                ':id' => $glass['id']
            ]);

            /*
             * Записываем изменение в историю.
             */
            $historyInsert = $db->prepare("
                INSERT INTO glass_history (
                    glass_id,
                    old_status,
                    new_status,
                    old_location,
                    new_location,
                    employee_id
                )
                VALUES (
                    :glass_id,
                    :old_status,
                    :new_status,
                    :old_location,
                    :new_location,
                    :employee_id
                )
            ");

            $historyInsert->execute([
                ':glass_id' => $glass['id'],
                ':old_status' => $oldStatus,
                ':new_status' => $newStatus,
                ':old_location' => $oldLocation,
                ':new_location' => $oldLocation,
                ':employee_id' => $_SESSION['user_id']
            ]);

            $db->commit();

            /*
             * PRG — после POST перезагружаем страницу.
             */
            header(
                'Location: /glass.php?code=' .
                urlencode($code) .
                '&updated=1'
            );

            exit;

        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $statusError = 'Не удалось изменить статус.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Получаем историю
|--------------------------------------------------------------------------
*/

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

$historyStmt->execute([
    ':glass_id' => $glass['id']
]);

$history = $historyStmt->fetchAll();

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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

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

        <?php if (isset($glass['order_number'])): ?>

            <p>
                <strong>Заказ:</strong>
                <?= e($glass['order_number']) ?>
            </p>

        <?php endif; ?>

        <?php if (isset($glass['glass_type'])): ?>

            <p>
                <strong>Тип стекла:</strong>
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

        <p>
            <strong>Текущий статус:</strong>
            <?= e(statusLabel((string) $glass['status'])) ?>
        </p>


        <?php if ($statusError): ?>

            <div
                style="
                    margin: 15px 0;
                    padding: 12px;
                    border: 1px solid #ff3d81;
                    border-radius: 8px;
                    color: #ff3d81;
                "
            >
                <?= e($statusError) ?>
            </div>

        <?php endif; ?>


        <?php if (isset($_GET['updated'])): ?>

            <div
                style="
                    margin: 15px 0;
                    padding: 12px;
                    border: 1px solid #00ff88;
                    border-radius: 8px;
                    color: #00ff88;
                "
            >
                Статус успешно изменён.
            </div>

        <?php endif; ?>


        <form
            method="post"
            action="/glass.php?code=<?= urlencode($glass['code']) ?>"
            style="margin-top: 25px;"
        >

            <label for="status">
                <strong>Изменить статус:</strong>
            </label>

            <div style="margin-top: 10px;">

                <select
                    id="status"
                    name="status"
                    required
                    style="
                        padding: 10px;
                        min-width: 220px;
                    "
                >

                    <?php foreach ($statuses as $value => $label): ?>

                        <option
                            value="<?= e($value) ?>"
                            <?= $glass['status'] === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <button
                    type="submit"
                    style="
                        margin-left: 8px;
                        padding: 10px 18px;
                        cursor: pointer;
                    "
                >
                    Изменить статус
                </button>

            </div>

        </form>


        <?php if (isset($glass['employee_name']) && $glass['employee_name']): ?>

            <p style="margin-top: 20px;">

                <strong>Ответственный:</strong>

                <?= e($glass['employee_name']) ?>

            </p>

        <?php endif; ?>


        <?php if (
            isset($glass['current_location']) &&
            $glass['current_location'] !== ''
        ): ?>

            <p>

                <strong>Местоположение:</strong>

                <?= e($glass['current_location']) ?>

            </p>

        <?php endif; ?>

    </div>


    <?php if ($history): ?>

        <div class="card">

            <h2>История изменений</h2>

            <?php foreach ($history as $item): ?>

                <div class="history-item">

                    <p>

                        <strong>
                            <?= e($item['created_at']) ?>
                        </strong>

                    </p>


                    <?php if ($item['old_status'] !== null): ?>

                        <p>

                            <?= e(
                                statusLabel(
                                    (string) $item['old_status']
                                )
                            ) ?>

                            →

                            <?= e(
                                statusLabel(
                                    (string) $item['new_status']
                                )
                            ) ?>

                        </p>

                    <?php else: ?>

                        <p>

                            <?= e(
                                statusLabel(
                                    (string) $item['new_status']
                                )
                            ) ?>

                        </p>

                    <?php endif; ?>


                    <?php if (
                        $item['old_location'] !== null ||
                        $item['new_location'] !== null
                    ): ?>

                        <p>

                            Место:

                            <?= e(
                                $item['old_location'] ?? ''
                            ) ?>

                            →

                            <?= e(
                                $item['new_location'] ?? ''
                            ) ?>

                        </p>

                    <?php endif; ?>


                    <?php if ($item['employee_name']): ?>

                        <p>

                            Сотрудник:

                            <?= e(
                                $item['employee_name']
                            ) ?>

                        </p>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const qrElement =
        document.getElementById('qrcode');

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