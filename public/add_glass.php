<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Доступ запрещён.');
}

require __DIR__ . '/../src/db.php';

$error = '';

$locations = [
    'Порезка',
    'Обработка',
    'Закалка',
    'Емалит',
    'Триплекс',
    'Сборка',
];

$statuses = [
    'created' => 'Создано',
    'production' => 'В производстве',
    'ready' => 'Готово',
    'warehouse' => 'На складе',
    'delivery' => 'Доставляется',
    'installed' => 'Установлено',
    'cancelled' => 'Отменено',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $orderNumber = trim($_POST['order_number'] ?? '');
    $glassType = trim($_POST['glass_type'] ?? '');
    $width = (int) ($_POST['width'] ?? 0);
    $height = (int) ($_POST['height'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $status = $_POST['status'] ?? 'created';
    $location = $_POST['location'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if ($code === '') {
        $error = 'Введите код стекла.';
    } elseif ($orderNumber === '') {
        $error = 'Введите номер заказа.';
    } elseif ($glassType === '') {
        $error = 'Введите тип стекла.';
    } elseif ($width <= 0 || $height <= 0) {
        $error = 'Ширина и высота должны быть больше нуля.';
    } elseif ($quantity <= 0) {
        $error = 'Количество должно быть больше нуля.';
    } elseif (!isset($statuses[$status])) {
        $error = 'Неверный статус.';
    } elseif (!in_array($location, $locations, true)) {
        $error = 'Неверное место.';
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO glasses (
                    code,
                    order_number,
                    glass_type,
                    width,
                    height,
                    quantity,
                    status,
                    current_location,
                    employee_id,
                    comment
                )
                VALUES (
                    :code,
                    :order_number,
                    :glass_type,
                    :width,
                    :height,
                    :quantity,
                    :status,
                    :location,
                    :employee_id,
                    :comment
                )
            ");

            $stmt->execute([
                ':code' => $code,
                ':order_number' => $orderNumber,
                ':glass_type' => $glassType,
                ':width' => $width,
                ':height' => $height,
                ':quantity' => $quantity,
                ':status' => $status,
                ':location' => $location,
                ':employee_id' => $_SESSION['user_id'],
                ':comment' => $comment,
            ]);

            $glassId = (int) $db->lastInsertId();

            $history = $db->prepare("
                INSERT INTO glass_history (
                    glass_id,
                    employee_id,
                    old_status,
                    new_status,
                    old_location,
                    new_location,
                    comment
                )
                VALUES (
                    :glass_id,
                    :employee_id,
                    NULL,
                    :new_status,
                    NULL,
                    :new_location,
                    :comment
                )
            ");

            $history->execute([
                ':glass_id' => $glassId,
                ':employee_id' => $_SESSION['user_id'],
                ':new_status' => $status,
                ':new_location' => $location,
                ':comment' => 'Стекло создано',
            ]);

            header('Location: /glass.php?code=' . urlencode($code));
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Стекло с таким кодом уже существует.';
            } else {
                $error = 'Не удалось создать стекло: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новое стекло — Optima Glass</title>
</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<h1>Новое стекло</h1>

<p>
    <a href="/glasses.php">← Все стекла</a>
</p>

<?php if ($error): ?>
    <p style="color: red;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<form method="post">

    <p>
        <label>
            Код стекла:<br>
            <input
                type="text"
                name="code"
                required
                value="<?= htmlspecialchars($_POST['code'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                placeholder="GLASS-000002"
            >
        </label>
    </p>

    <p>
        <label>
            Номер заказа:<br>
            <input
                type="text"
                name="order_number"
                required
                value="<?= htmlspecialchars($_POST['order_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                placeholder="ORDER-0002"
            >
        </label>
    </p>

    <p>
        <label>
            Тип стекла:<br>
            <input
                type="text"
                name="glass_type"
                required
                value="<?= htmlspecialchars($_POST['glass_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                placeholder="Закалённое"
            >
        </label>
    </p>

    <p>
        <label>
            Ширина, мм:<br>
            <input
                type="number"
                name="width"
                min="1"
                required
                value="<?= htmlspecialchars($_POST['width'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            >
        </label>
    </p>

    <p>
        <label>
            Высота, мм:<br>
            <input
                type="number"
                name="height"
                min="1"
                required
                value="<?= htmlspecialchars($_POST['height'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            >
        </label>
    </p>

    <p>
        <label>
            Количество:<br>
            <input
                type="number"
                name="quantity"
                min="1"
                required
                value="<?= htmlspecialchars($_POST['quantity'] ?? '1', ENT_QUOTES, 'UTF-8') ?>"
            >
        </label>
    </p>

    <p>
        <label>
            Статус:<br>
            <select name="status" required>
                <?php foreach ($statuses as $value => $label): ?>
                    <option
                        value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                        <?= ($_POST['status'] ?? 'created') === $value ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>

    <p>
        <label>
            Место:<br>
            <select name="location" required>
                <?php foreach ($locations as $location): ?>
                    <option
                        value="<?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>"
                        <?= ($_POST['location'] ?? 'Порезка') === $location ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>

    <p>
        <label>
            Комментарий:<br>
            <textarea
                name="comment"
                rows="4"
                cols="50"
            ><?= htmlspecialchars($_POST['comment'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>
    </p>

    <button type="submit">Создать стекло</button>

</form>

</body>
</html>
