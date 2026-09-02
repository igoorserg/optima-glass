<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

require_permission(
    'orders.view',
    $user
);

/*
|--------------------------------------------------------------------------
| Допоміжні функції
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function priorityLabel(int $priority): string
{
    return match ($priority) {
        3 => '🔴 Критичний',
        2 => '🟠 Терміновий',
        default => '🟢 Звичайний',
    };
}

function orderStatusLabel(string $status): string
{
    return match ($status) {
        'new' => 'Нове',
        'in_production' => 'У виробництві',
        'completed' => 'Завершено',
        'cancelled' => 'Скасовано',
        default => $status,
    };
}

function glassStatusLabel(string $status): string
{
    return match ($status) {
        'created' => 'Створено',
        'waiting' => 'Очікує',
        'in_progress' => 'У роботі',
        'completed' => 'Готово',
        'rejected' => 'Брак',
        'rework' => 'Переробка',
        default => $status,
    };
}

function stageLabel(?string $name): string
{
    if ($name === null || $name === '') {
        return '—';
    }

    return match ($name) {
        'Порезка' => 'Порізка',
        'Обработка' => 'Обробка',
        'Закалка' => 'Гартування',
        'Контроль качества' => 'Контроль якості',
        'Упаковка' => 'Пакування',
        'Отгрузка' => 'Відвантаження',
        'Емалит' => 'Емаліт',
        'Триплекс' => 'Триплекс',
        'Брак — Порезка' => 'Брак — Порізка',
        default => $name,
    };
}

function batchStatusLabel(?string $status): string
{
    return match ($status) {
        'created' => 'Створена',
        'in_progress' => 'У роботі',
        'completed' => 'Завершена',
        'cancelled' => 'Скасована',
        null => '—',
        default => $status,
    };
}

/*
|--------------------------------------------------------------------------
| Номер замовлення
|--------------------------------------------------------------------------
*/

$orderNumber =
    trim(
        $_GET['number']
        ?? ''
    );

if ($orderNumber === '') {

    http_response_code(400);

    exit(
        'Номер замовлення не вказано.'
    );
}

/*
|--------------------------------------------------------------------------
| Замовлення
|--------------------------------------------------------------------------
*/

$orderStmt =
    $db->prepare("
        SELECT
            id,
            order_number,
            customer_name,
            comment,
            status,
            priority,
            planned_date,
            production_started_at,
            production_completed_at,
            created_at,
            updated_at,
            created_by
        FROM orders
        WHERE order_number =
            :order_number
        LIMIT 1
    ");

$orderStmt->execute([
    ':order_number' =>
        $orderNumber,
]);

$order =
    $orderStmt->fetch(
        PDO::FETCH_ASSOC
    );

if (!$order) {

    http_response_code(404);

    exit(
        'Замовлення не знайдено.'
    );
}

$orderId =
    (int)
    $order['id'];

/*
|--------------------------------------------------------------------------
| Скло замовлення
|--------------------------------------------------------------------------
*/

$glassesStmt =
    $db->prepare("
        SELECT
            g.id,
            g.code,
            g.glass_type,
            g.thickness,
            g.width,
            g.height,
            g.quantity,
            g.status,
            g.current_location,
            g.current_step_id,
            g.route_id,
            g.created_at,
            g.updated_at,

            r.name AS route_name,

            rs.step_number,
            rs.name AS current_stage,

            (
                SELECT pb.id
                FROM production_batch_items pbi
                JOIN production_batches pb
                    ON pb.id =
                        pbi.batch_id
                WHERE pbi.glass_id =
                    g.id
                  AND pb.status IN (
                      'created',
                      'in_progress'
                  )
                ORDER BY pb.id DESC
                LIMIT 1
            )
                AS active_batch_id

        FROM glasses g

        LEFT JOIN routes r
            ON r.id =
                g.route_id

        LEFT JOIN route_steps rs
            ON rs.id =
                g.current_step_id

        WHERE g.order_id =
            :order_id

        ORDER BY g.id
    ");

$glassesStmt->execute([
    ':order_id' =>
        $orderId,
]);

$glasses =
    $glassesStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Загальна статистика
|--------------------------------------------------------------------------
*/

$totalGlasses =
    count($glasses);

$completedGlasses = 0;
$rejectedGlasses = 0;
$waitingGlasses = 0;
$inProgressGlasses = 0;

$totalArea = 0.0;
$completedArea = 0.0;
$rejectedArea = 0.0;

foreach ($glasses as $glass) {

    $area =
        (
            (int)
            $glass['width']
            *
            (int)
            $glass['height']
            *
            max(
                1,
                (int)
                $glass['quantity']
            )
        )
        / 1000000;

    $totalArea +=
        $area;

    switch (
        $glass['status']
    ) {

        case 'completed':

            $completedGlasses++;
            $completedArea +=
                $area;

            break;

        case 'rejected':

            $rejectedGlasses++;
            $rejectedArea +=
                $area;

            break;

        case 'in_progress':

            $inProgressGlasses++;

            break;

        case 'waiting':

            $waitingGlasses++;

            break;
    }
}

$progressPercent =
    $totalGlasses > 0
        ? round(
            (
                $completedGlasses
                / $totalGlasses
            )
            * 100
        )
        : 0;

/*
|--------------------------------------------------------------------------
| Партії замовлення
|--------------------------------------------------------------------------
*/

$batchesStmt =
    $db->prepare("
        SELECT
            pb.id,
            pb.status,
            pb.created_at,
            pb.started_at,
            pb.completed_at,

            rs.name AS stage_name,

            u.name AS employee_name,

            COUNT(pbi.id)
                AS total_items,

            SUM(
                CASE
                    WHEN pbi.status =
                        'completed'
                    THEN 1
                    ELSE 0
                END
            )
                AS completed_items,

            SUM(
                CASE
                    WHEN pbi.status =
                        'rejected'
                    THEN 1
                    ELSE 0
                END
            )
                AS rejected_items

        FROM production_batches pb

        LEFT JOIN route_steps rs
            ON rs.id =
                pb.route_step_id

        LEFT JOIN users u
            ON u.id =
                pb.assigned_employee_id

        LEFT JOIN production_batch_items pbi
            ON pbi.batch_id =
                pb.id

        WHERE pb.order_id =
            :order_id

        GROUP BY
            pb.id,
            rs.id,
            u.id

        ORDER BY pb.id DESC
    ");

$batchesStmt->execute([
    ':order_id' =>
        $orderId,
]);

$batches =
    $batchesStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Історія виробничих операцій
|--------------------------------------------------------------------------
*/

$operationsStmt =
    $db->prepare("
        SELECT
            go.id,
            go.glass_id,
            go.employee_id,
            go.route_step_id,
            go.operation_type,
            go.from_stage,
            go.to_stage,
            go.result,
            go.comment,
            go.created_at,
            go.batch_id,

            g.code AS glass_code,

            u.name AS employee_name

        FROM glass_operations go

        JOIN glasses g
            ON g.id =
                go.glass_id

        LEFT JOIN users u
            ON u.id =
                go.employee_id

        WHERE g.order_id =
            :order_id

        ORDER BY
            go.id DESC

        LIMIT 100
    ");

$operationsStmt->execute([
    ':order_id' =>
        $orderId,
]);

$operations =
    $operationsStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

?>
<!DOCTYPE html>
<html lang="uk">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Замовлення №<?= e(
            $order['order_number']
        ) ?> — OPTIMA GLASS
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            font-family: Arial, sans-serif;
            color: #111827;
        }

        .page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-header h1 {
            margin: 0 0 7px;
        }

        .muted {
            color: #6b7280;
        }

        .card {
            margin-bottom: 20px;
            padding: 22px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
        }

        .summary {
            display: grid;
            grid-template-columns:
                repeat(
                    5,
                    minmax(0, 1fr)
                );
            gap: 12px;
        }

        .summary-card {
            padding: 16px;
            background: #f9fafb;
            border-radius: 10px;
        }

        .summary-value {
            display: block;
            margin-top: 5px;
            font-size: 24px;
            font-weight: 700;
        }

        .progress {
            height: 15px;
            margin-top: 14px;
            overflow: hidden;
            border-radius: 999px;
            background: #e5e7eb;
        }

        .progress-bar {
            height: 100%;
            background: #111827;
        }

        .progress-meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 8px;
            color: #6b7280;
        }

        .button {
            display: inline-flex;
            min-height: 42px;
            align-items: center;
            justify-content: center;
            padding: 0 15px;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
        }

        .button-secondary {
            background: #f3f4f6;
            color: #111827;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 11px 9px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }

        th {
            font-size: 13px;
            color: #6b7280;
        }

        .status-rejected {
            color: #b91c1c;
            font-weight: 700;
        }

        .status-completed {
            color: #166534;
            font-weight: 700;
        }

        .batch-list {
            display: grid;
            gap: 10px;
        }

        .batch-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }

        .empty {
            padding: 22px 10px;
            text-align: center;
            color: #6b7280;
        }

        @media (
            max-width: 900px
        ) {

            .summary {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .page-header,
            .batch-row {
                flex-direction: column;
            }

        }

        @media (
            max-width: 520px
        ) {

            .summary {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<?php
require __DIR__
    . '/../src/partials/header.php';
?>

<main class="page">

    <header class="page-header">

        <div>

            <h1>

                Замовлення №
                <?= e(
                    $order[
                        'order_number'
                    ]
                ) ?>

            </h1>

            <div class="muted">

                <?= e(
                    $order[
                        'customer_name'
                    ]
                    ?? 'Клієнта не вказано'
                ) ?>

                ·

                <?= e(
                    priorityLabel(
                        (int)
                        $order[
                            'priority'
                        ]
                    )
                ) ?>

                ·

                <?= e(
                    orderStatusLabel(
                        $order[
                            'status'
                        ]
                    )
                ) ?>

            </div>

        </div>


        <div class="actions">

            <a
                href="/glass_create.php"
                class="button"
            >
                + Додати скло
            </a>

            <a
                href="/production.php"
                class="button button-secondary"
            >
                Виробництво
            </a>

        </div>

    </header>


    <section class="card">

        <div class="summary">

            <div class="summary-card">

                Всього скла

                <span class="summary-value">
                    <?= $totalGlasses ?>
                </span>

            </div>


            <div class="summary-card">

                Загальна площа

                <span class="summary-value">

                    <?= number_format(
                        $totalArea,
                        2,
                        ',',
                        ' '
                    ) ?>

                    м²

                </span>

            </div>


            <div class="summary-card">

                Готово

                <span class="summary-value">
                    <?= $completedGlasses ?>
                </span>

            </div>


            <div class="summary-card">

                У роботі / черзі

                <span class="summary-value">
                    <?= $waitingGlasses
                        + $inProgressGlasses ?>
                </span>

            </div>


            <div class="summary-card">

                Брак

                <span class="summary-value">
                    <?= $rejectedGlasses ?>
                </span>

            </div>

        </div>


        <div class="progress">

            <div
                class="progress-bar"
                style="width:<?= (int)
                    $progressPercent ?>%;"
            ></div>

        </div>

        <div class="progress-meta">

            <span>
                Готовність замовлення
            </span>

            <strong>
                <?= (int)
                    $progressPercent ?>%
            </strong>

        </div>

    </section>


    <section class="card">

        <h2>
            Інформація про замовлення
        </h2>

        <div>

            <strong>
                Пріоритет:
            </strong>

            <?= e(
                priorityLabel(
                    (int)
                    $order[
                        'priority'
                    ]
                )
            ) ?>

            <br><br>

            <strong>
                Планова дата:
            </strong>

            <?= e(
                $order[
                    'planned_date'
                ]
                ?? '—'
            ) ?>

            <br><br>

            <strong>
                Запущено у виробництво:
            </strong>

            <?= e(
                $order[
                    'production_started_at'
                ]
                ?? '—'
            ) ?>

            <br><br>

            <strong>
                Завершено:
            </strong>

            <?= e(
                $order[
                    'production_completed_at'
                ]
                ?? '—'
            ) ?>

            <?php if (
                !empty(
                    $order[
                        'comment'
                    ]
                )
            ): ?>

                <br><br>

                <strong>
                    Коментар:
                </strong>

                <?= e(
                    $order[
                        'comment'
                    ]
                ) ?>

            <?php endif; ?>

        </div>

    </section>


    <section class="card">

        <h2>
            Скло замовлення
        </h2>

        <?php if (
            !$glasses
        ): ?>

            <div class="empty">
                У замовленні ще немає скла.
            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>#</th>
                            <th>Код</th>
                            <th>Тип</th>
                            <th>Розмір</th>
                            <th>Товщина</th>
                            <th>Площа</th>
                            <th>Маршрут</th>
                            <th>Поточна дільниця</th>
                            <th>Статус</th>
                            <th>Партія</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach (
                        $glasses
                        as $index =>
                            $glass
                    ): ?>

                        <?php

                        $glassArea =
                            (
                                (int)
                                $glass['width']
                                *
                                (int)
                                $glass['height']
                                *
                                max(
                                    1,
                                    (int)
                                    $glass['quantity']
                                )
                            )
                            / 1000000;

                        $statusClass =
                            $glass['status']
                            === 'rejected'
                                ? 'status-rejected'
                                : (
                                    $glass['status']
                                    === 'completed'
                                        ? 'status-completed'
                                        : ''
                                );

                        ?>

                        <tr>

                            <td>
                                <?= $index + 1 ?>
                            </td>

                            <td>
                                <strong>
                                    <?= e(
                                        $glass[
                                            'code'
                                        ]
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e(
                                    $glass[
                                        'glass_type'
                                    ]
                                    ?? '—'
                                ) ?>
                            </td>

                            <td>

                                <?= (int)
                                    $glass[
                                        'width'
                                    ] ?>

                                ×

                                <?= (int)
                                    $glass[
                                        'height'
                                    ] ?>

                                мм

                            </td>

                            <td>

                                <?= $glass[
                                    'thickness'
                                ] !== null
                                    ? e(
                                        (string)
                                        $glass[
                                            'thickness'
                                        ]
                                    ) . ' мм'
                                    : '—'
                                ?>

                            </td>

                            <td>

                                <?= number_format(
                                    $glassArea,
                                    2,
                                    ',',
                                    ' '
                                ) ?>

                                м²

                            </td>

                            <td>

                                <?= e(
                                    $glass[
                                        'route_name'
                                    ]
                                    ?? '—'
                                ) ?>

                            </td>

                            <td>

                                <?= e(
                                    stageLabel(
                                        $glass[
                                            'current_location'
                                        ]
                                    )
                                ) ?>

                            </td>

                            <td
                                class="<?= e(
                                    $statusClass
                                ) ?>"
                            >

                                <?= e(
                                    glassStatusLabel(
                                        $glass[
                                            'status'
                                        ]
                                    )
                                ) ?>

                            </td>

                            <td>

                                <?php if (
                                    $glass[
                                        'active_batch_id'
                                    ] !== null
                                ): ?>

                                    Партія №
                                    <?= (int)
                                        $glass[
                                            'active_batch_id'
                                        ] ?>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>


    <section class="card">

        <h2>
            Партії
        </h2>

        <?php if (
            !$batches
        ): ?>

            <div class="empty">
                Для цього замовлення партій немає.
            </div>

        <?php else: ?>

            <div class="batch-list">

                <?php foreach (
                    $batches
                    as $batch
                ): ?>

                    <div class="batch-row">

                        <div>

                            <strong>

                                Партія №
                                <?= (int)
                                    $batch[
                                        'id'
                                    ] ?>

                            </strong>

                            ·

                            <?= e(
                                stageLabel(
                                    $batch[
                                        'stage_name'
                                    ]
                                )
                            ) ?>

                            ·

                            <?= e(
                                batchStatusLabel(
                                    $batch[
                                        'status'
                                    ]
                                )
                            ) ?>

                            <div class="muted">

                                Виконавець:
                                <?= e(
                                    $batch[
                                        'employee_name'
                                    ]
                                    ?? '—'
                                ) ?>

                                · Всього:
                                <?= (int)
                                    $batch[
                                        'total_items'
                                    ] ?>

                                · Готово:
                                <?= (int)
                                    $batch[
                                        'completed_items'
                                    ] ?>

                                · Брак:
                                <?= (int)
                                    $batch[
                                        'rejected_items'
                                    ] ?>

                            </div>

                        </div>

                        <?php if (
                            in_array(
                                $batch['status'],
                                [
                                    'created',
                                    'in_progress',
                                ],
                                true
                            )
                        ): ?>

                            <a
                                href="/batch.php?id=<?= (int)
                                    $batch[
                                        'id'
                                    ] ?>"
                                class="button button-secondary"
                            >
                                Відкрити
                            </a>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <section class="card">

        <h2>
            Історія виробничих операцій
        </h2>

        <?php if (
            !$operations
        ): ?>

            <div class="empty">

                Виробничих операцій
                ще немає.

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>Дата</th>
                            <th>Скло</th>
                            <th>Працівник</th>
                            <th>Звідки</th>
                            <th>Куди</th>
                            <th>Результат</th>
                            <th>Партія</th>
                            <th>Коментар</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach (
                        $operations
                        as $operation
                    ): ?>

                        <tr>

                            <td>
                                <?= e(
                                    $operation[
                                        'created_at'
                                    ]
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $operation[
                                        'glass_code'
                                    ]
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $operation[
                                        'employee_name'
                                    ]
                                    ?? '—'
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    stageLabel(
                                        $operation[
                                            'from_stage'
                                        ]
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    stageLabel(
                                        $operation[
                                            'to_stage'
                                        ]
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $operation[
                                        'result'
                                    ]
                                ) ?>
                            </td>

                            <td>

                                <?= $operation[
                                    'batch_id'
                                ] !== null
                                    ? '№'
                                        . (int)
                                        $operation[
                                            'batch_id'
                                        ]
                                    : '—'
                                ?>

                            </td>

                            <td>
                                <?= e(
                                    $operation[
                                        'comment'
                                    ]
                                    ?? '—'
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>

</html>
