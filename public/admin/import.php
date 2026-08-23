<?php

declare(strict_types=1);

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

/**
 * Определяем разделитель CSV/TXT.
 */
function detectDelimiter(string $line): string
{
    $delimiters = [
        ';',
        ',',
        "\t"
    ];

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

/**
 * Нормализация заголовка.
 */
function normalizeHeader(string $value): string
{
    $value = trim($value);

    // UTF-8 BOM
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

    return strtolower(trim($value));
}

/**
 * Нормализация значения.
 */
function normalizeValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return trim((string)$value);
}

/**
 * Читаем CSV/TXT.
 */
function readCsvFile(string $filename): array
{
    $rows = [];

    $handle = fopen($filename, 'r');

    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть CSV/TXT файл.');
    }

    $firstLine = fgets($handle);

    if ($firstLine === false) {
        fclose($handle);
        throw new RuntimeException('Файл пустой.');
    }

    $delimiter = detectDelimiter($firstLine);

    rewind($handle);

    $headers = fgetcsv($handle, 0, $delimiter);

    if ($headers === false) {
        fclose($handle);
        throw new RuntimeException('Не удалось прочитать заголовок файла.');
    }

    $headers = array_map(
        static fn($header) => normalizeHeader((string)$header),
        $headers
    );

    $lineNumber = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {

        $lineNumber++;

        if (
            count($row) === 1 &&
            trim((string)($row[0] ?? '')) === ''
        ) {
            continue;
        }

        $row = array_pad($row, count($headers), '');

        $data = [
            '_line' => $lineNumber
        ];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $data[$header] = normalizeValue(
                $row[$index] ?? ''
            );
        }

        $rows[] = $data;
    }

    fclose($handle);

    return [
        'headers' => $headers,
        'rows' => $rows
    ];
}

/**
 * Читаем XLSX через PhpSpreadsheet.
 */
function readXlsxFile(string $filename): array
{
    $autoload = __DIR__ . '/../../vendor/autoload.php';

    if (!file_exists($autoload)) {
        throw new RuntimeException(
            'Не найден vendor/autoload.php. Установите PhpSpreadsheet через Composer.'
        );
    }

    require_once $autoload;

    if (!class_exists(
        'PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx'
    )) {
        throw new RuntimeException(
            'PhpSpreadsheet не установлен.'
        );
    }

    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();

    $reader->setReadDataOnly(true);

    try {
        $spreadsheet = $reader->load($filename);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Не удалось прочитать XLSX файл: ' . $e->getMessage()
        );
    }

    $sheet = $spreadsheet->getActiveSheet();

    $highestRow = $sheet->getHighestDataRow();
    $highestColumn = $sheet->getHighestDataColumn();

    if ($highestRow < 1) {
        throw new RuntimeException('XLSX файл пустой.');
    }

    $highestColumnIndex =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $highestColumn
        );

    $headers = [];

    for ($column = 1; $column <= $highestColumnIndex; $column++) {

        $value = $sheet->getCellByColumnAndRow(
            $column,
            1
        )->getValue();

        $headers[] = normalizeHeader(
            (string)$value
        );
    }

    $rows = [];

    for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {

        $data = [
            '_line' => $rowNumber
        ];

        $hasData = false;

        for ($column = 1; $column <= $highestColumnIndex; $column++) {

            $header = $headers[$column - 1] ?? '';

            if ($header === '') {
                continue;
            }

            $value = $sheet->getCellByColumnAndRow(
                $column,
                $rowNumber
            )->getCalculatedValue();

            $value = normalizeValue($value);

            if ($value !== '') {
                $hasData = true;
            }

            $data[$header] = $value;
        }

        if (!$hasData) {
            continue;
        }

        $rows[] = $data;
    }

    return [
        'headers' => $headers,
        'rows' => $rows
    ];
}

/**
 * Проверка обязательных колонок.
 */
function validateHeaders(array $headers): array
{
    $required = [
        'order_number',
        'glass_type',
        'thickness',
        'width',
        'height',
        'quantity'
    ];

    $errors = [];

    foreach ($required as $column) {

        if (!in_array($column, $headers, true)) {
            $errors[] =
                "Отсутствует обязательная колонка: {$column}";
        }
    }

    return $errors;
}

/**
 * Преобразование строк импорта в нормализованные записи.
 */
function validateRows(
    array $rows,
    array &$errors
): array {

    $result = [];

    foreach ($rows as $row) {

        $lineNumber = (int)($row['_line'] ?? 0);

        $orderNumber =
            normalizeValue($row['order_number'] ?? '');

        $glassType =
            normalizeValue($row['glass_type'] ?? '');

        $thickness =
            normalizeValue($row['thickness'] ?? '');

        $width =
            normalizeValue($row['width'] ?? '');

        $height =
            normalizeValue($row['height'] ?? '');

        $quantity =
            normalizeValue($row['quantity'] ?? '');

        $comment =
            normalizeValue($row['comment'] ?? '');

        if ($orderNumber === '') {

            $errors[] =
                "Строка {$lineNumber}: не указан order_number.";

            continue;
        }

        if ($glassType === '') {

            $errors[] =
                "Строка {$lineNumber}: не указан glass_type.";

            continue;
        }

        if (
            !is_numeric($thickness) ||
            (float)$thickness <= 0
        ) {

            $errors[] =
                "Строка {$lineNumber}: неправильная толщина.";

            continue;
        }

        if (
            !ctype_digit($width) ||
            (int)$width <= 0
        ) {

            $errors[] =
                "Строка {$lineNumber}: неправильная ширина.";

            continue;
        }

        if (
            !ctype_digit($height) ||
            (int)$height <= 0
        ) {

            $errors[] =
                "Строка {$lineNumber}: неправильная высота.";

            continue;
        }

        if (
            !ctype_digit($quantity) ||
            (int)$quantity <= 0
        ) {

            $errors[] =
                "Строка {$lineNumber}: неправильное количество.";

            continue;
        }

        $result[] = [
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

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_FILES['import_file']) ||
        !is_array($_FILES['import_file'])
    ) {

        $errors[] = 'Файл не выбран.';

    } elseif (
        ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE)
        !== UPLOAD_ERR_OK
    ) {

        $uploadError =
            (int)($_FILES['import_file']['error'] ?? -1);

        $errors[] =
            "Ошибка загрузки файла. Код: {$uploadError}";

    } else {

        $file = $_FILES['import_file'];

        $extension =
            strtolower(
                pathinfo(
                    (string)$file['name'],
                    PATHINFO_EXTENSION
                )
            );

        $allowedExtensions = [
            'csv',
            'txt',
            'xlsx'
        ];

        if (
            !in_array(
                $extension,
                $allowedExtensions,
                true
            )
        ) {

            $errors[] =
                'Разрешены только файлы CSV, TXT и XLSX.';

        } else {

            try {

                if ($extension === 'xlsx') {

                    if (!class_exists('ZipArchive')) {

                        /*
                         * PhpSpreadsheet использует ZIP
                         * для чтения XLSX.
                         */
                        throw new RuntimeException(
                            'На сервере отсутствует расширение PHP ZipArchive. ' .
                            'Для импорта XLSX необходимо включить PHP extension zip.'
                        );
                    }

                    $parsed =
                        readXlsxFile(
                            (string)$file['tmp_name']
                        );

                } else {

                    $parsed =
                        readCsvFile(
                            (string)$file['tmp_name']
                        );
                }

                $headers = $parsed['headers'];
                $rows = $parsed['rows'];

                $errors = array_merge(
                    $errors,
                    validateHeaders($headers)
                );

                if (!$errors) {

                    $preview =
                        validateRows(
                            $rows,
                            $errors
                        );
                }

            } catch (Throwable $e) {

                $errors[] =
                    'Ошибка чтения файла: ' .
                    $e->getMessage();
            }
        }
    }

    /*
     * Если файл корректный — импортируем.
     */
    if (!$errors && $preview) {

        try {

            $db->beginTransaction();

            /*
             * Ищем заказ.
             */
            $findOrder = $db->prepare(
                'SELECT id
                 FROM orders
                 WHERE order_number = ?
                 LIMIT 1'
            );

            /*
             * Создаём заказ.
             */
            $createOrder = $db->prepare(
                'INSERT INTO orders (
                    order_number,
                    status
                )
                VALUES (
                    ?,
                    "new"
                )'
            );

            /*
             * Ищем тип стекла.
             */
            $findGlassType = $db->prepare(
                'SELECT id, code, name
                 FROM glass_types
                 WHERE code = ?
                    OR name = ?
                 LIMIT 1'
            );

            /*
             * Следующий ID стекла.
             */
            $getNextCode = $db->query(
                'SELECT COALESCE(MAX(id), 0) + 1
                 FROM glasses'
            );

            $nextId =
                (int)$getNextCode->fetchColumn();

            /*
             * Создание стекла.
             */
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
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    "created",
                    ?,
                    ?,
                    ?
                )'
            );

            $imported = 0;
            $ordersCreated = 0;

            foreach ($preview as $item) {

                /*
                 * Ищем заказ.
                 */
                $findOrder->execute([
                    $item['order_number']
                ]);

                $orderId =
                    $findOrder->fetchColumn();

                /*
                 * Если заказа нет — создаём.
                 */
                if (!$orderId) {

                    $createOrder->execute([
                        $item['order_number']
                    ]);

                    $orderId =
                        (int)$db->lastInsertId();

                    $ordersCreated++;
                }

                /*
                 * Ищем тип стекла.
                 */
                $findGlassType->execute([
                    $item['glass_type'],
                    $item['glass_type']
                ]);

                $glassTypeRow =
                    $findGlassType->fetch();

                if (!$glassTypeRow) {

                    throw new RuntimeException(
                        'Строка ' .
                        $item['line'] .
                        ': не найден тип стекла "' .
                        $item['glass_type'] .
                        '".'
                    );
                }

                /*
                 * Создаём отдельную запись
                 * для каждого стекла.
                 */
                for (
                    $i = 0;
                    $i < $item['quantity'];
                    $i++
                ) {

                    /*
                     * Код:
                     * GLASS-000001
                     * GLASS-000002
                     * ...
                     */
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

            $message =
                "Импорт завершён. " .
                "Добавлено стекол: {$imported}. " .
                "Создано новых заказов: {$ordersCreated}.";

            $preview = [];

        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $errors[] =
                'Ошибка импорта: ' .
                $e->getMessage();
        }

    } elseif (!$errors && !$preview) {

        $errors[] =
            'В файле нет данных для импорта.';
    }
}

?>

<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Импорт — Optima Glass</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            margin: 30px;
        }

        .message {
            padding: 12px;
            margin: 20px 0;
            background: #e8f5e9;
            border: 1px solid #81c784;
        }

        .errors {
            padding: 12px 20px;
            margin: 20px 0;
            background: #ffebee;
            border: 1px solid #e57373;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
        }

        th {
            background: #f5f5f5;
        }

        .format {
            background: #f7f7f7;
            padding: 15px;
            border: 1px solid #ddd;
            overflow-x: auto;
        }

        button {
            padding: 10px 20px;
            cursor: pointer;
        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../../src/partials/header.php'; ?>

<h1>Массовый импорт стекла</h1>

<p>
    Загрузите файл с данными стекла.
</p>

<p>
    Поддерживаются:
    <strong>CSV</strong>,
    <strong>TXT</strong> и
    <strong>XLSX</strong>.
</p>

<h2>Формат файла</h2>

<p>
    Обязательные колонки:
</p>

<pre class="format">order_number;glass_type;thickness;width;height;quantity;comment
292;4FL;8;875;875;10;Стандартная партия
292;4FL;8;1000;700;5;Срочный заказ
292;8SN70;10;1200;800;3;Solar Control</pre>

<p>
    Для XLSX первая строка должна содержать те же названия колонок.
</p>

<ul>
    <li><strong>order_number</strong> — номер заказа</li>
    <li><strong>glass_type</strong> — код или название типа стекла</li>
    <li><strong>thickness</strong> — толщина</li>
    <li><strong>width</strong> — ширина в мм</li>
    <li><strong>height</strong> — высота в мм</li>
    <li><strong>quantity</strong> — количество</li>
    <li><strong>comment</strong> — комментарий, необязательно</li>
</ul>

<?php if ($message): ?>

    <div class="message">
        <strong>
            <?= e($message) ?>
        </strong>
    </div>

<?php endif; ?>

<?php if ($errors): ?>

    <div class="errors">

        <h2>Ошибки</h2>

        <ul>

            <?php foreach ($errors as $error): ?>

                <li>
                    <?= e($error) ?>
                </li>

            <?php endforeach; ?>

        </ul>

    </div>

<?php endif; ?>

<form
    method="post"
    enctype="multipart/form-data"
>

    <p>

        <label>

            <strong>
                Файл CSV / TXT / XLSX:
            </strong>

            <br>

            <input
                type="file"
                name="import_file"
                accept=".csv,.txt,.xlsx"
                required
            >

        </label>

    </p>

    <p>

        <button type="submit">
            Импортировать
        </button>

    </p>

</form>

<?php if ($preview): ?>

    <h2>
        Предпросмотр
    </h2>

    <p>
        Найдено строк:
        <strong><?= count($preview) ?></strong>
    </p>

    <table>

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

                <td>
                    <?= (int)$item['line'] ?>
                </td>

                <td>
                    <?= e($item['order_number']) ?>
                </td>

                <td>
                    <?= e($item['glass_type']) ?>
                </td>

                <td>
                    <?= e((string)$item['thickness']) ?>
                </td>

                <td>
                    <?= (int)$item['width'] ?>
                </td>

                <td>
                    <?= (int)$item['height'] ?>
                </td>

                <td>
                    <?= (int)$item['quantity'] ?>
                </td>

                <td>
                    <?= e($item['comment']) ?>
                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

<?php endif; ?>

</body>

</html>
