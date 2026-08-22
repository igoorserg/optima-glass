<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require __DIR__ . '/../src/db.php';

$code = trim($_GET['code'] ?? $_POST['code'] ?? '');

if ($code === '') {
    http_response_code(400);
    exit('Код стекла не указан.');
}

$stmt = $db->prepare("SELECT * FROM glasses WHERE code = :code LIMIT 1");
$stmt->execute([':code' => $code]);
$glass = $stmt->fetch();

if (!$glass) {
    http_response_code(404);
    exit('Стекло не найдено.');
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

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = $_POST['status'] ?? '';
    $newLocation = trim($_POST['location'] ?? '');
    $comment = trim($_POST['comment'] ?? '');

    if (!isset($statuses[$newStatus])) {
        $error = 'Неверный статус.';
    } elseif ($newLocation === '') {
        $error = 'Укажите место.';
    } else {
        $db->beginTransaction();

        try {
            $update = $db->prepare("
                UPDATE glasses
                SET status = :status,
                    current_location = :location,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");

            $update->execute([
                ':status' => $newStatus,
                ':location' => $newLocation,
                ':id' => $glass['id'],
            ]);

            $history = $db->prepare("
                INSERT INTO glass_history (
                    glass_id,
                    employee_id,
                    old_status,
                    new_status,
                    old_location,
                    new_location,
                    comment
                ) VALUES (
                    :glass_id,
                    :employee_id,
                    :old_status,
                    :new_status,
                    :old_location,
                    :new_location,
                    :comment
                )
            ");

            $history->execute([
                ':glass_id' => $glass['id'],
                ':employee_id' => $_SESSION['user_id'],
                ':old_status' => $glass['status'],
                ':new_status' => $newStatus,
                ':old_location' => $glass['current_location'],
                ':new_location' => $newLocation,
                ':comment' => $comment,
            ]);

            $db->commit();

            header('Location: /glass.php?code=' . urlencode($code));
            exit;

        } catch (Throwable $e) {
            $db->rollBack();
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Изменить стекло — Optima Glass</title>
</head>
<body>

<h1>Изменение стекла <?= htmlspecialchars($glass['code'], ENT_QUOTES, 'UTF-8') ?></h1>

<p>
    <a href="/glass.php?code=<?= urlencode($glass['code']) ?>">← Назад к стеклу</a>
</p>

<?php if ($error): ?>
    <p style="color:red;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="code" value="<?= htmlspecialchars($glass['code'], ENT_QUOTES, 'UTF-8') ?>">

    <p>
        <label>
            Новый статус:<br>
            <select name="status" required>
                <?php foreach ($statuses as $value => $label): ?>
                    <option
                        value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $glass['status'] === $value ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>

    <p>
        <label>
            Новое место:<br>
            <select name="location" required>
                <?php
                $locations = [
                    'Порезка',
                    'Обработка',
                    'Закалка',
                    'Емалит',
                    'Триплекс',
                    'Сборка',
                ];
                ?>

                <?php foreach ($locations as $location): ?>
                    <option
                        value="<?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>"
                        <?= ($glass['current_location'] ?? '') === $location ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>

    <button type="submit">Сохранить</button>
</form>

</body>
</html>
