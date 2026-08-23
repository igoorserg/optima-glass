<?php

session_start();

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Доступ запрещён');
}

require __DIR__ . '/../../src/db.php';

$message = '';
$errors = [];
$preview = [];

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function detectDelimiter(string $line): string
{
    $delimiters = [';', ',', "\t"];

    $best = ';';
    $max = 0;

    foreach ($delimiters as $delimiter) {
        $count = substr_count($line, $delimiter);

        if ($count > $max) {
            $max = $count;
            $best = $delimiter;
        }
    }

    return $best;
}

function normalizeHeader(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

    return strtolower($value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_FILES['import_file']) ||
        $_FILES['import_file']['error'] !== UPLOAD_ERR_OK
    ) {
        $errors[] = 'Не удалось загрузить файл.';
    } else {

        $file = $_FILES['import_file'];

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'txt'], true)) {
            $errors[] = 'Разрешены только файлы CSV и TXT.';
        } else {

            $handle = fopen($file['tmp_name'], 'r');

            if ($handle === false) {
                $errors[] = 'Не удалось открыть файл.';
            } else {

                $firstLine = fgets($handle);

                if ($firstLine === false) {
                    $errors[] = 'Файл пустой.';
                } else {

                    $delimiter = detectDelimiter($firstLine);

                    rewind($handle);

                    $headers = fgetcsv($handle, 0, $delimiter);

                    if (!$headers) {
                        $errors[] = 'Не удалось прочитать заголовок файла.';
                    } else {

                        $headers = array_map('normalizeHeader', $headers);

                        $required = [
                            'order_number',
                            'glass_type',
                            'thickness',
                            'width',
                            'height',
                            'quantity'
                        ];

                        foreach ($required as $requiredColumn) {
                            if (!in_array($requiredColumn, $headers, true)) {
                                $errors[] = "Отсутствует обязательная колонка: {$requiredColumn}";
                            }
                        }

                        if (!$errors) {

                            $indexes = array_flip($headers);

                            $lineNumber = 1;

                            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {

                                $lineNumber++;

                                if (count($row) === 1 && trim((string)$row[0]) === '') {
                                    continue;
                                }

                                $row = array_pad($row, count($headers), '');

                                $data = [];

                                foreach ($headers as $index => $header) {
                                    $data[$header] = trim((string)($row[$index] ?? ''));
                                }

                                $orderNumber = $data['order_number'] ?? '';
                                $glassType = $data['glass_type'] ?? '';
                                $thickness = $data['thickness'] ?? '';
                                $width = $data['width'] ?? '';
                                $height = $data['height'] ?? '';
                                $quantity = $data['quantity'] ?? '';
                                $comment = $data['comment'] ?? '';

                                if ($orderNumber === '') {
                                    $errors[] = "Строка {$lineNumber}: не указан order_number.";
                                    continue;
                                }

                                if ($glassType === '') {
                                    $errors[] = "Строка {$lineNumber}: не указан glass_type.";
                                    continue;
                                }

                                if (!is_numeric($thickness) || (float)$thickness <= 0) {
                                    $errors[] = "Строка {$lineNumber}: неправильная толщина.";
                                    continue;
                                }

                                if (!ctype_digit($width) || (int)$width <= 0) {
                                    $errors[] = "Строка {$lineNumber}: неправильная ширина.";
                                    continue;
                                }

                                if (!ctype_digit($height) || (int)$height <= 0) {
                                    $errors[] = "Строка {$lineNumber}: неправильная высота.";
                                    continue;
                                }

                                if (!ctype_digit($quantity) || (int)$quantity <= 0) {
                                    $errors[] = "Строка {$lineNumber}: неправильное количество.";
                                    continue;
                                }

                                $preview[] = [
                                    'line' => $lineNumber,
                                    'order_number' => $orderNumber,
                                    'glass_type' => $glassType,
                                    'thickness' => (float)$thickness,
                                    'width' => (int)$width,
                                    'height' => (int)$height,
                                    'quantity' => (int)$quantity,
                                    'comment' => $comment
                                ];
                            }
                        }
                    }
                }

                fclose($handle);
            }
        }
    }

    /*
     * Если файл корректный — импортируем.
     */
    if (!$errors && $preview) {

        try {

            $db->beginTransaction();

            $findOrder = $db->prepare(
                'SELECT id FROM orders WHERE order_number = ?'
            );

            $createOrder = $db->prepare(
                'INSERT INTO orders (order_number, status)
                 VALUES (?, "new")'
            );

            $findGlassType = $db->prepare(
                'SELECT id, code, name
                 FROM glass_types
                 WHERE code = ? OR name = ?
                 LIMIT 1'
            );

            $getNextCode = $db->query(
                "SELECT COALESCE(MAX(id), 0) + 1 FROM glasses"
            );

            $nextId = (int)$getNextCode->fetchColumn();

            $insertGlass = $db->prepare(
                'INSERT INTO glasses (
                    code,
                    order_number,
                    glass_type,
                    width,
                    height,
                    quantity,
                    status,
                    comment,
                    order_id,
                    thickness
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, "created", ?, ?, ?
                )'
            );

            $imported = 0;

            foreach ($preview as $item) {

                /*
                 * Ищем заказ.
                 */
                $findOrder->execute([
                    $item['order_number']
                ]);

                $orderId = $findOrder->fetchColumn();

                /*
                 * Если заказа нет — создаём.
                 */
                if (!$orderId) {

                    $createOrder->execute([
                        $item['order_number']
                    ]);

                    $orderId = (int)$db->lastInsertId();
                }

                /*
                 * Проверяем тип стекла.
                 */
                $findGlassType->execute([
                    $item['glass_type'],
                    $item['glass_type']
                ]);

                $glassTypeRow = $findGlassType->fetch();

                if (!$glassTypeRow) {
                    throw new RuntimeException(
                        'Не найден тип стекла: ' . $item['glass_type']
                    );
                }

                /*
                 * Создаём отдельную запись для каждого стекла.
                 */
                for ($i = 0; $i < $item['quantity']; $i++) {

                    $code = sprintf(
                        'GLASS-%06d',
                        $nextId
                    );

                    $insertGlass->execute([
                        $code,
                        $item['order_number'],
                        $glassTypeRow['code'],
                        $item['width'],
                        $item['height'],
                        1,
                        $item['comment'],
                        $orderId,
                        $item['thickness']
                    ]);

                    $nextId++;
                    $imported++;
                }
            }

            $db->commit();

            $message = "Импорт завершён. Добавлено стекол: {$imported}";

            $preview = [];

        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $errors[] = 'Ошибка импорта: ' . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Импорт — Optima Glass</title>
</head>

<body>

<?php require __DIR__ . '/../../src/partials/header.php'; ?>

<h1>Массовый импорт стекла</h1>

<p>
    Загрузите CSV или TXT файл.
</p>

<p>
    Формат:
</p>

<pre>order_number;glass_type;thickness;width;height;quantity;comment
292;4FL;8;875;875;10;Стандартная партия
292;4FL;8;1000;700;5;Срочный заказ
292;8SN70;10;1200;800;3;Solar Control</pre>

<?php if ($message): ?>

    <p>
        <strong><?= e($message) ?></strong>
    </p>

<?php endif; ?>

<?php if ($errors): ?>

    <h2>Ошибки</h2>

    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>

<?php endif; ?>

<form method="post" enctype="multipart/form-data">

    <p>
        <label>
            Файл CSV/TXT:
            <input
                type="file"
                name="import_file"
                accept=".csv,.txt"
                required
            >
        </label>
    </p>

    <button type="submit">
        Импортировать
    </button>

</form>

<?php if ($preview): ?>

    <h2>Предпросмотр</h2>

    <table border="1" cellpadding="8" cellspacing="0">

        <thead>
        <tr>
            <th>Строка</th>
            <th>Заказ</th>
            <th>Тип</th>
            <th>Толщина</th>
            <th>Ширина</th>
            <th>Высота</th>
            <th>Количество</th>
            <th>Комментарий</th>
        </tr>
        </thead>

        <tbody>

        <?php foreach ($preview as $item): ?>

            <tr>

                <td><?= (int)$item['line'] ?></td>

                <td><?= e($item['order_number']) ?></td>

                <td><?= e($item['glass_type']) ?></td>

                <td><?= e((string)$item['thickness']) ?></td>

                <td><?= (int)$item['width'] ?></td>

                <td><?= (int)$item['height'] ?></td>

                <td><?= (int)$item['quantity'] ?></td>

                <td><?= e($item['comment']) ?></td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

<?php endif; ?>

</body>
</html>
