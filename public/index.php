<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

$name = $user['name'] ?? '';
$role = $user['role'] ?? '';

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$orderNumber = trim($_GET['order_number'] ?? '');

/*
 * Текущее состояние стекол.
 * Если указан номер заказа — показываем только этот заказ.
 */
$currentParams = [];
$currentWhere = [];

if ($orderNumber !== '') {
    $currentWhere[] = 'g.order_number = :order_number';
    $currentParams[':order_number'] = $orderNumber;
}

$currentSql = "
    SELECT
        COALESCE(rs.name, g.current_location, 'Не определено') AS stage,
        COUNT(*) AS total
    FROM glasses g
    LEFT JOIN route_steps rs ON rs.id = g.current_step_id
";

if ($currentWhere) {
    $currentSql .= ' WHERE ' . implode(' AND ', $currentWhere);
}

$currentSql .= "
    GROUP BY stage
    ORDER BY total DESC
";

$stmt = $db->prepare($currentSql);
$stmt->execute($currentParams);
$currentStats = $stmt->fetchAll();

$currentTotal = 0;

foreach ($currentStats as $row) {
    $currentTotal += (int) $row['total'];
}

/*
 * Статистика операций за период.
 */
$operationParams = [];
$operationWhere = [];

if ($dateFrom !== '') {
    $operationWhere[] = 'DATE(go.created_at) >= :date_from';
    $operationParams[':date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $operationWhere[] = 'DATE(go.created_at) <= :date_to';
    $operationParams[':date_to'] = $dateTo;
}

if ($orderNumber !== '') {
    $operationWhere[] = 'g.order_number = :order_number';
    $operationParams[':order_number'] = $orderNumber;
}

$operationSql = "
    SELECT
        COALESCE(go.to_stage, go.from_stage, 'Не определено') AS stage,
        COUNT(*) AS total
    FROM glass_operations go
    INNER JOIN glasses g ON g.id = go.glass_id
";

if ($operationWhere) {
    $operationSql .= ' WHERE ' . implode(' AND ', $operationWhere);
}

$operationSql .= "
    GROUP BY stage
    ORDER BY total DESC
";

$stmt = $db->prepare($operationSql);
$stmt->execute($operationParams);
$operationStats = $stmt->fetchAll();

$operationTotal = 0;

foreach ($operationStats as $row) {
    $operationTotal += (int) $row['total'];
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Optima Glass</title>
</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<h1>Optima Glass</h1>

<p>
    Вы вошли как:
    <strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong>
</p>

<p>
    Роль:
    <strong><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></strong>
</p>

<hr>

<h2>Текущее состояние производства</h2>

<form method="get">

    <p>
        <label>
            Номер заказа:<br>
            <input
                type="text"
                name="order_number"
                value="<?= htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="ORDER-0001"
            >
        </label>
    </p>

    <p>
        <button type="submit">
            Найти
        </button>

        <a href="/">
            Сбросить
        </a>
    </p>

</form>

<p>
    <strong>Всего стекол:</strong>
    <?= $currentTotal ?>
</p>

<?php if ($currentStats): ?>

    <table border="1" cellpadding="8" cellspacing="0">

        <thead>
            <tr>
                <th>Этап</th>
                <th>Количество стекол</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ($currentStats as $row): ?>

            <tr>
                <td>
                    <?= htmlspecialchars($row['stage'], ENT_QUOTES, 'UTF-8') ?>
                </td>

                <td>
                    <?= (int) $row['total'] ?>
                </td>
            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

<?php else: ?>

    <p>Стекла не найдены.</p>

<?php endif; ?>

<hr>

<h2>Производство за период</h2>

<form method="get">

    <input
        type="hidden"
        name="order_number"
        value="<?= htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') ?>"
    >

    <p>
        <label>
            С даты:<br>
            <input
                type="date"
                name="date_from"
                value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>"
            >
        </label>
    </p>

    <p>
        <label>
            По дату:<br>
            <input
                type="date"
                name="date_to"
                value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>"
            >
        </label>
    </p>

    <p>
        <button type="submit">
            Показать статистику
        </button>

        <a href="/">
            Сбросить
        </a>
    </p>

</form>

<p>
    <strong>Всего операций:</strong>
    <?= $operationTotal ?>
</p>

<?php if ($operationStats): ?>

    <table border="1" cellpadding="8" cellspacing="0">

        <thead>
            <tr>
                <th>Этап</th>
                <th>Количество операций</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ($operationStats as $row): ?>

            <tr>
                <td>
                    <?= htmlspecialchars($row['stage'], ENT_QUOTES, 'UTF-8') ?>
                </td>

                <td>
                    <?= (int) $row['total'] ?>
                </td>
            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

<?php else: ?>

    <p>За выбранный период операций нет.</p>

<?php endif; ?>

</body>
</html>
