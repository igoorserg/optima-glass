<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

require_permission('production.view', $user);

$stage = trim($_GET['stage'] ?? '');

if ($stage === '') {
    http_response_code(400);
    exit('Этап не указан.');
}

/*
 * Проверяем, что такой производственный этап существует.
 */
$stmt = $db->prepare("
    SELECT id, name
    FROM production_stages
    WHERE name = :name
      AND active = 1
");

$stmt->execute([
    ':name' => $stage,
]);

$productionStage = $stmt->fetch();

if (!$productionStage) {
    http_response_code(404);
    exit('Производственный этап не найден.');
}

/*
 * Получаем стекла, у которых текущий этап
 * соответствует выбранному этапу.
 */
$stmt = $db->prepare("
    SELECT
        g.id,
        g.code,
        g.order_number,
        g.glass_type,
        g.width,
        g.height,
        g.thickness,
        g.quantity,
        g.status,
        g.employee_id,
        u.name AS employee_name
    FROM glasses g
    LEFT JOIN route_steps rs
        ON rs.id = g.current_step_id
    LEFT JOIN users u
        ON u.id = g.employee_id
    WHERE rs.name = :stage
    ORDER BY g.id DESC
");

$stmt->execute([
    ':stage' => $stage,
]);

$glasses = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($stage, ENT_QUOTES, 'UTF-8') ?>
        — Optima Glass
    </title>
</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<h1>
    Стекла — <?= htmlspecialchars($stage, ENT_QUOTES, 'UTF-8') ?>
</h1>

<p>
    <strong>Количество:</strong>
    <?= count($glasses) ?>
</p>

<?php if ($glasses): ?>

<table border="1" cellpadding="8" cellspacing="0">

    <thead>
        <tr>
            <th>ID</th>
            <th>Код</th>
            <th>Заказ</th>
            <th>Тип</th>
            <th>Размер</th>
            <th>Толщина</th>
            <th>Количество</th>
            <th>Статус</th>
            <th>Ответственный</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($glasses as $glass): ?>

        <tr>

            <td>
                <?= (int) $glass['id'] ?>
            </td>

            <td>
                <a href="/glass.php?code=<?= urlencode($glass['code']) ?>">
                    <strong>
                        <?= htmlspecialchars($glass['code'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                </a>
            </td>

            <td>
                <?= htmlspecialchars($glass['order_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>

            <td>
                <?= htmlspecialchars($glass['glass_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>

            <td>
                <?= (int) $glass['width'] ?>
                ×
                <?= (int) $glass['height'] ?>
                мм
            </td>

            <td>
                <?php if ($glass['thickness'] !== null): ?>
                    <?= htmlspecialchars((string) $glass['thickness'], ENT_QUOTES, 'UTF-8') ?> мм
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>

            <td>
                <?= (int) $glass['quantity'] ?>
            </td>

            <td>
                <?= htmlspecialchars($glass['status'], ENT_QUOTES, 'UTF-8') ?>
            </td>

            <td>
                <?= htmlspecialchars($glass['employee_name'] ?? 'Не назначен', ENT_QUOTES, 'UTF-8') ?>
            </td>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

<?php else: ?>

<p>
    На этом этапе сейчас нет стекол.
</p>

<?php endif; ?>

<p>
    <a href="/">← На главную</a>
</p>

</body>
</html>
