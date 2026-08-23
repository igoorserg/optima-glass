<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require __DIR__ . '/../src/db.php';

$stmt = $db->query("
    SELECT
        ps.id,
        ps.name,
        ps.active,
        COUNT(g.id) AS glass_count
    FROM production_stages ps
    LEFT JOIN route_steps rs
        ON rs.name = ps.name
    LEFT JOIN glasses g
        ON g.current_step_id = rs.id
    WHERE ps.active = 1
    GROUP BY ps.id, ps.name, ps.active
    ORDER BY ps.id
");

$stages = $stmt->fetchAll();

$totalGlasses = 0;

foreach ($stages as $stage) {
    $totalGlasses += (int) $stage['glass_count'];
}

?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Производство — Optima Glass</title>
</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<h1>Производство</h1>

<p>
    <strong>Стекол по этапам:</strong>
    <?= $totalGlasses ?>
</p>

<table border="1" cellpadding="10" cellspacing="0">

    <thead>
        <tr>
            <th>№</th>
            <th>Производственный этап</th>
            <th>Стекол</th>
            <th>Открыть</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($stages as $stage): ?>

        <tr>

            <td>
                <?= (int) $stage['id'] ?>
            </td>

            <td>
                <strong>
                    <?= htmlspecialchars($stage['name'], ENT_QUOTES, 'UTF-8') ?>
                </strong>
            </td>

            <td>
                <?= (int) $stage['glass_count'] ?>
            </td>

            <td>
                <a href="/stage.php?stage=<?= urlencode($stage['name']) ?>">
                    Открыть
                </a>
            </td>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

<p>
    <a href="/">← На главную</a>
</p>

</body>
</html>
