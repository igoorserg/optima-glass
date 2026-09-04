<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

require_permission(
    'production.ship',
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

function writeShippingAudit(
    PDO $db,
    int $userId,
    string $action,
    ?string $entityType,
    ?int $entityId,
    ?array $oldValue = null,
    ?array $newValue = null
): void {

    $stmt =
        $db->prepare("
            INSERT INTO audit_log (
                user_id,
                action,
                entity_type,
                entity_id,
                old_value,
                new_value,
                ip_address,
                user_agent
            )
            VALUES (
                :user_id,
                :action,
                :entity_type,
                :entity_id,
                :old_value,
                :new_value,
                :ip_address,
                :user_agent
            )
        ");

    $stmt->execute([
        ':user_id' =>
            $userId,

        ':action' =>
            $action,

        ':entity_type' =>
            $entityType,

        ':entity_id' =>
            $entityId,

        ':old_value' =>
            $oldValue !== null
                ? json_encode(
                    $oldValue,
                    JSON_UNESCAPED_UNICODE
                )
                : null,

        ':new_value' =>
            $newValue !== null
                ? json_encode(
                    $newValue,
                    JSON_UNESCAPED_UNICODE
                )
                : null,

        ':ip_address' =>
            $_SERVER['REMOTE_ADDR']
            ?? null,

        ':user_agent' =>
            $_SERVER['HTTP_USER_AGENT']
            ?? null,
    ]);
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION[
            'csrf_shipping'
        ]
    )
) {

    $_SESSION[
        'csrf_shipping'
    ] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION[
        'csrf_shipping'
    ];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Відвантаження
|--------------------------------------------------------------------------
*/

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
) {

    require_permission(
        'production.ship',
        $user
    );

    if (
        !hash_equals(
            $csrfToken,
            $_POST[
                'csrf_token'
            ]
            ?? ''
        )
    ) {

        http_response_code(403);

        exit(
            'Помилка перевірки безпеки.'
        );
    }

    $action =
        $_POST[
            'action'
        ]
        ?? '';

    $orderId =
        (int) (
            $_POST[
                'order_id'
            ]
            ?? 0
        );

    $glassIds =
        $_POST[
            'glass_ids'
        ]
        ?? [];

    if (
        !is_array(
            $glassIds
        )
    ) {
        $glassIds = [];
    }

    $glassIds =
        array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $glassIds
                    ),
                    static fn (
                        int $id
                    ): bool =>
                        $id > 0
                )
            )
        );

    if (
        $orderId <= 0
    ) {

        $error =
            'Замовлення не вказано.';

    } elseif (
        !in_array(
            $action,
            [
                'ship_selected',
                'ship_all',
            ],
            true
        )
    ) {

        $error =
            'Некоректна дія.';

    } elseif (
        $action ===
        'ship_selected'
        &&
        !$glassIds
    ) {

        $error =
            'Не вибрано жодного скла.';

    } else {

        try {

            $db->beginTransaction();

            /*
             * ----------------------------------------------------------
             * Замовлення
             * ----------------------------------------------------------
             */

            $orderStmt =
                $db->prepare("
                    SELECT
                        id,
                        order_number,
                        customer_name,
                        priority,
                        status
                    FROM orders
                    WHERE id =
                        :id
                    LIMIT 1
                ");

            $orderStmt->execute([
                ':id' =>
                    $orderId,
            ]);

            $order =
                $orderStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$order) {

                throw new RuntimeException(
                    'Замовлення не знайдено.'
                );
            }

            if (
                $order[
                    'status'
                ]
                !==
                'in_production'
            ) {

                throw new RuntimeException(
                    'Замовлення не перебуває у виробництві.'
                );
            }

            /*
             * ----------------------------------------------------------
             * Отримуємо скло на етапі Відвантаження
             * ----------------------------------------------------------
             */

            $shippingGlassStmt =
                $db->prepare("
                    SELECT
                        g.id,
                        g.code,
                        g.status,
                        g.current_step_id,
                        g.current_location,
                        g.width,
                        g.height,
                        g.quantity,

                        rs.name
                            AS stage_name

                    FROM glasses g

                    JOIN route_steps rs
                        ON rs.id =
                            g.current_step_id

                    WHERE g.order_id =
                        :order_id

                      AND rs.name =
                        'Відвантаження'

                      AND g.status IN (
                          'waiting',
                          'in_progress'
                      )

                    ORDER BY g.id
                ");

            $shippingGlassStmt->execute([
                ':order_id' =>
                    $orderId,
            ]);

            $availableGlasses =
                $shippingGlassStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

            if (!$availableGlasses) {

                throw new RuntimeException(
                    'У замовленні немає скла, готового до відвантаження.'
                );
            }

            /*
             * ----------------------------------------------------------
             * Вибираємо, що саме відвантажуємо
             * ----------------------------------------------------------
             */

            $glassesToShip = [];

            foreach (
                $availableGlasses
                as $glass
            ) {

                if (
                    $action ===
                    'ship_all'
                ) {

                    $glassesToShip[] =
                        $glass;

                    continue;
                }

                if (
                    in_array(
                        (int)
                        $glass[
                            'id'
                        ],
                        $glassIds,
                        true
                    )
                ) {

                    $glassesToShip[] =
                        $glass;
                }
            }

            if (!$glassesToShip) {

                throw new RuntimeException(
                    'Немає доступного скла для відвантаження.'
                );
            }

            /*
             * ----------------------------------------------------------
             * Підготовлені SQL
             * ----------------------------------------------------------
             */

            $operationStmt =
                $db->prepare("
                    INSERT INTO glass_operations (
                        glass_id,
                        employee_id,
                        route_step_id,
                        operation_type,
                        from_stage,
                        to_stage,
                        result,
                        batch_id,
                        comment
                    )
                    VALUES (
                        :glass_id,
                        :employee_id,
                        :route_step_id,
                        'shipping',
                        'Відвантаження',
                        'Відвантажено',
                        'completed',
                        NULL,
                        :comment
                    )
                ");

            $historyStmt =
                $db->prepare("
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
                        :old_status,
                        'completed',
                        :old_location,
                        'Відвантажено',
                        :comment
                    )
                ");

            $glassUpdateStmt =
                $db->prepare("
                    UPDATE glasses
                    SET
                        status =
                            'completed',

                        current_location =
                            'Відвантажено',

                        employee_id =
                            NULL,

                        updated_at =
                            CURRENT_TIMESTAMP

                    WHERE id =
                        :id

                      AND current_step_id =
                        :current_step_id

                      AND status IN (
                          'waiting',
                          'in_progress'
                      )
                ");

            $shippedIds = [];
            $shippedCodes = [];
            $shippedArea = 0.0;

            /*
             * ----------------------------------------------------------
             * Відвантажуємо кожне скло
             * ----------------------------------------------------------
             */

            foreach (
                $glassesToShip
                as $glass
            ) {

                /*
                 * Операція.
                 */

                $operationStmt->execute([
                    ':glass_id' =>
                        (int)
                        $glass[
                            'id'
                        ],

                    ':employee_id' =>
                        (int)
                        $user[
                            'id'
                        ],

                    ':route_step_id' =>
                        (int)
                        $glass[
                            'current_step_id'
                        ],

                    ':comment' =>
                        'Скло відвантажено менеджером.',
                ]);

                /*
                 * Історія.
                 */

                $historyStmt->execute([
                    ':glass_id' =>
                        (int)
                        $glass[
                            'id'
                        ],

                    ':employee_id' =>
                        (int)
                        $user[
                            'id'
                        ],

                    ':old_status' =>
                        $glass[
                            'status'
                        ],

                    ':old_location' =>
                        $glass[
                            'current_location'
                        ],

                    ':comment' =>
                        'Відвантаження замовлення №'
                        . $order[
                            'order_number'
                        ]
                        . '.',
                ]);

                /*
                 * Оновлення скла.
                 */

                $glassUpdateStmt->execute([
                    ':id' =>
                        (int)
                        $glass[
                            'id'
                        ],

                    ':current_step_id' =>
                        (int)
                        $glass[
                            'current_step_id'
                        ],
                ]);

                if (
                    $glassUpdateStmt
                        ->rowCount()
                    !== 1
                ) {

                    throw new RuntimeException(
                        'Стан скла '
                        . $glass[
                            'code'
                        ]
                        . ' змінився під час відвантаження.'
                    );
                }

                $shippedIds[] =
                    (int)
                    $glass[
                        'id'
                    ];

                $shippedCodes[] =
                    $glass[
                        'code'
                    ];

                $shippedArea +=
                    (
                        (int)
                        $glass[
                            'width'
                        ]
                        *
                        (int)
                        $glass[
                            'height'
                        ]
                        *
                        max(
                            1,
                            (int)
                            $glass[
                                'quantity'
                            ]
                        )
                    )
                    / 1000000;
            }

            /*
             * ----------------------------------------------------------
             * Audit відвантаження
             * ----------------------------------------------------------
             */

            writeShippingAudit(
                $db,
                (int)
                $user[
                    'id'
                ],
                'ship_glasses',
                'order',
                $orderId,
                null,
                [
                    'order_number' =>
                        $order[
                            'order_number'
                        ],

                    'shipped_count' =>
                        count(
                            $shippedIds
                        ),

                    'shipped_area_m2' =>
                        round(
                            $shippedArea,
                            3
                        ),

                    'glass_ids' =>
                        $shippedIds,

                    'glass_codes' =>
                        $shippedCodes,

                    'shipped_by_user_id' =>
                        (int)
                        $user[
                            'id'
                        ],
                ]
            );

            /*
             * ----------------------------------------------------------
             * Перевіряємо весь стан замовлення
             * ----------------------------------------------------------
             */

            $progressStmt =
                $db->prepare("
                    SELECT
                        COUNT(*)
                            AS total,

                        SUM(
                            CASE
                                WHEN status =
                                    'completed'
                                THEN 1
                                ELSE 0
                            END
                        )
                            AS completed,

                        SUM(
                            CASE
                                WHEN status =
                                    'rejected'
                                THEN 1
                                ELSE 0
                            END
                        )
                            AS rejected

                    FROM glasses

                    WHERE order_id =
                        :order_id
                ");

            $progressStmt->execute([
                ':order_id' =>
                    $orderId,
            ]);

            $progress =
                $progressStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            $total =
                (int) (
                    $progress[
                        'total'
                    ]
                    ?? 0
                );

            $completed =
                (int) (
                    $progress[
                        'completed'
                    ]
                    ?? 0
                );

            $rejected =
                (int) (
                    $progress[
                        'rejected'
                    ]
                    ?? 0
                );

            /*
             * Замовлення завершуємо тільки тоді,
             * коли ВСІ стекла мають completed.
             *
             * Якщо є rejected — замовлення
             * автоматично не закриваємо.
             */

            $orderCompleted =
                false;

            if (
                $total > 0
                &&
                $completed ===
                $total
            ) {

                $completeOrderStmt =
                    $db->prepare("
                        UPDATE orders
                        SET
                            status =
                                'completed',

                            production_completed_at =
                                CURRENT_TIMESTAMP,

                            updated_at =
                                CURRENT_TIMESTAMP

                        WHERE id =
                            :id

                          AND status =
                            'in_production'
                    ");

                $completeOrderStmt->execute([
                    ':id' =>
                        $orderId,
                ]);

                $orderCompleted =
                    true;

                writeShippingAudit(
                    $db,
                    (int)
                    $user[
                        'id'
                    ],
                    'complete_order',
                    'order',
                    $orderId,
                    [
                        'status' =>
                            'in_production',
                    ],
                    [
                        'status' =>
                            'completed',

                        'order_number' =>
                            $order[
                                'order_number'
                            ],

                        'total_glasses' =>
                            $total,

                        'completed_glasses' =>
                            $completed,
                    ]
                );
            }

            $db->commit();

            $success =
                'Відвантажено стекол: '
                . count(
                    $shippedIds
                )
                . '. Площа: '
                . number_format(
                    $shippedArea,
                    2,
                    ',',
                    ' '
                )
                . ' м².';

            if ($orderCompleted) {

                $success .=
                    ' Замовлення №'
                    . $order[
                        'order_number'
                    ]
                    . ' повністю завершено.';
            }

            if ($rejected > 0) {

                $success .=
                    ' У замовленні є брак: '
                    . $rejected
                    . '. Замовлення автоматично не закривається, доки брак не буде врегульовано.';
            }

        } catch (
            Throwable
            $exception
        ) {

            if (
                $db->inTransaction()
            ) {
                $db->rollBack();
            }

            $error =
                'Відвантаження не виконано: '
                . $exception
                    ->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Черга на відвантаження
|--------------------------------------------------------------------------
*/

$shippingStmt =
    $db->query("
        SELECT
            g.id,
            g.code,
            g.order_id,
            g.order_number,
            g.glass_type,
            g.thickness,
            g.width,
            g.height,
            g.quantity,
            g.status,
            g.current_step_id,
            g.current_location,

            o.customer_name,
            o.priority,
            o.planned_date,

            rs.name
                AS stage_name

        FROM glasses g

        JOIN orders o
            ON o.id =
                g.order_id

        JOIN route_steps rs
            ON rs.id =
                g.current_step_id

        WHERE rs.name =
            'Відвантаження'

          AND o.status =
            'in_production'

          AND g.status IN (
              'waiting',
              'in_progress'
          )

        ORDER BY
            o.priority DESC,

            CASE
                WHEN o.planned_date IS NULL
                THEN 1
                ELSE 0
            END,

            o.planned_date ASC,

            o.id ASC,

            g.id ASC
    ");

$shippingGlasses =
    $shippingStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Групуємо по замовленнях
|--------------------------------------------------------------------------
*/

$shippingOrders = [];

foreach (
    $shippingGlasses
    as $glass
) {

    $orderId =
        (int)
        $glass[
            'order_id'
        ];

    if (
        !isset(
            $shippingOrders[
                $orderId
            ]
        )
    ) {

        $shippingOrders[
            $orderId
        ] = [
            'id' =>
                $orderId,

            'order_number' =>
                $glass[
                    'order_number'
                ],

            'customer_name' =>
                $glass[
                    'customer_name'
                ],

            'priority' =>
                (int)
                $glass[
                    'priority'
                ],

            'planned_date' =>
                $glass[
                    'planned_date'
                ],

            'glasses' =>
                [],

            'area' =>
                0.0,
        ];
    }

    $shippingOrders[
        $orderId
    ][
        'glasses'
    ][] =
        $glass;

    $shippingOrders[
        $orderId
    ][
        'area'
    ] +=
        (
            (int)
            $glass[
                'width'
            ]
            *
            (int)
            $glass[
                'height'
            ]
            *
            max(
                1,
                (int)
                $glass[
                    'quantity'
                ]
            )
        )
        / 1000000;
}

/*
|--------------------------------------------------------------------------
| Загальна статистика
|--------------------------------------------------------------------------
*/

$totalShippingArea = 0.0;

foreach (
    $shippingGlasses
    as $glass
) {

    $totalShippingArea +=
        (
            (int)
            $glass[
                'width'
            ]
            *
            (int)
            $glass[
                'height'
            ]
            *
            max(
                1,
                (int)
                $glass[
                    'quantity'
                ]
            )
        )
        / 1000000;
}

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
        Відвантаження — OPTIMA GLASS
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
            margin-bottom: 22px;
        }

        .page-header h1 {
            margin-bottom: 7px;
        }

        .muted {
            color: #6b7280;
        }

        .card {
            margin-bottom: 20px;
            padding: 22px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .message {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 10px;
        }

        .message-success {
            background: #dcfce7;
            color: #166534;
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .summary {
            display: grid;
            grid-template-columns:
                repeat(
                    3,
                    minmax(0, 1fr)
                );
            gap: 12px;
        }

        .summary-card {
            padding: 17px;
            border-radius: 10px;
            background: #f9fafb;
        }

        .summary-value {
            display: block;
            margin-top: 6px;
            font-size: 25px;
            font-weight: 700;
        }

        .order-card {
            margin-bottom: 18px;
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .order-title {
            font-size: 19px;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: middle;
        }

        th {
            color: #6b7280;
            font-size: 13px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 15px;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 700;
        }

        .button-primary {
            background: #111827;
            color: #fff;
        }

        .button-secondary {
            background: #f3f4f6;
            color: #111827;
        }

        .button-success {
            background: #166534;
            color: #fff;
        }

        .order-actions {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .empty {
            padding: 30px 10px;
            text-align: center;
            color: #6b7280;
        }

        @media (
            max-width: 800px
        ) {

            .summary {
                grid-template-columns:
                    1fr;
            }

            .order-header {
                flex-direction:
                    column;
            }

            .order-actions {
                justify-content:
                    stretch;
            }

            .button {
                width: 100%;
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

        <h1>
            🚚 Відвантаження
        </h1>

        <div class="muted">

            Фінальне підтвердження
            готового скла менеджером.

        </div>

    </header>


    <?php if (
        $success !== ''
    ): ?>

        <div
            class="message message-success"
        >
            <?= e(
                $success
            ) ?>
        </div>

    <?php endif; ?>


    <?php if (
        $error !== ''
    ): ?>

        <div
            class="message message-error"
        >
            <?= e(
                $error
            ) ?>
        </div>

    <?php endif; ?>


    <section class="card">

        <div class="summary">

            <div class="summary-card">

                Замовлень

                <span class="summary-value">
                    <?= count(
                        $shippingOrders
                    ) ?>
                </span>

            </div>


            <div class="summary-card">

                Стекол

                <span class="summary-value">
                    <?= count(
                        $shippingGlasses
                    ) ?>
                </span>

            </div>


            <div class="summary-card">

                Загальна площа

                <span class="summary-value">

                    <?= number_format(
                        $totalShippingArea,
                        2,
                        ',',
                        ' '
                    ) ?>

                    м²

                </span>

            </div>

        </div>

    </section>


    <section class="card">

        <h2>
            Готово до відвантаження
        </h2>


        <?php if (
            !$shippingOrders
        ): ?>

            <div class="empty">

                Скло, готове до
                відвантаження, відсутнє.

            </div>

        <?php else: ?>


            <?php foreach (
                $shippingOrders
                as $shippingOrder
            ): ?>

                <form
                    method="post"
                    class="order-card"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(
                            $csrfToken
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="order_id"
                        value="<?= (int)
                            $shippingOrder[
                                'id'
                            ] ?>"
                    >


                    <div class="order-header">

                        <div>

                            <div class="order-title">

                                Замовлення №
                                <?= e(
                                    $shippingOrder[
                                        'order_number'
                                    ]
                                ) ?>

                            </div>

                            <div class="muted">

                                <?= e(
                                    $shippingOrder[
                                        'customer_name'
                                    ]
                                    ?? 'Клієнта не вказано'
                                ) ?>

                                ·

                                <?= e(
                                    priorityLabel(
                                        (int)
                                        $shippingOrder[
                                            'priority'
                                        ]
                                    )
                                ) ?>

                                ·

                                Готово:
                                <?= count(
                                    $shippingOrder[
                                        'glasses'
                                    ]
                                ) ?>
                                шт.

                                ·

                                <?= number_format(
                                    $shippingOrder[
                                        'area'
                                    ],
                                    2,
                                    ',',
                                    ' '
                                ) ?>
                                м²

                            </div>

                        </div>


                        <div class="actions">

                            <a
                                href="/order.php?number=<?= urlencode(
                                    (string)
                                    $shippingOrder[
                                        'order_number'
                                    ]
                                ) ?>"
                                class="button button-secondary"
                            >
                                Відкрити замовлення
                            </a>

                        </div>

                    </div>


                    <div class="table-wrap">

                        <table>

                            <thead>

                                <tr>

                                    <th>
                                        Вибрати
                                    </th>

                                    <th>
                                        Скло
                                    </th>

                                    <th>
                                        Тип
                                    </th>

                                    <th>
                                        Розмір
                                    </th>

                                    <th>
                                        Товщина
                                    </th>

                                    <th>
                                        Площа
                                    </th>

                                    <th>
                                        Статус
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach (
                                $shippingOrder[
                                    'glasses'
                                ]
                                as $glass
                            ): ?>

                                <tr>

                                    <td>

                                        <input
                                            type="checkbox"
                                            name="glass_ids[]"
                                            value="<?= (int)
                                                $glass[
                                                    'id'
                                                ] ?>"
                                        >

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
                                            )
                                                . ' мм'
                                            : '—'
                                        ?>

                                    </td>

                                    <td>

                                        <?= number_format(
                                            (
                                                (int)
                                                $glass[
                                                    'width'
                                                ]
                                                *
                                                (int)
                                                $glass[
                                                    'height'
                                                ]
                                                *
                                                max(
                                                    1,
                                                    (int)
                                                    $glass[
                                                        'quantity'
                                                    ]
                                                )
                                            )
                                            / 1000000,
                                            2,
                                            ',',
                                            ' '
                                        ) ?>

                                        м²

                                    </td>

                                    <td>
                                        Готово до відвантаження
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>


                    <div class="order-actions">

                        <button
                            type="submit"
                            name="action"
                            value="ship_selected"
                            class="button button-primary"
                            onclick="return confirm('Відвантажити вибрані стекла?');"
                        >
                            Відвантажити вибране
                        </button>


                        <button
                            type="submit"
                            name="action"
                            value="ship_all"
                            class="button button-success"
                            onclick="return confirm('Відвантажити всі готові стекла цього замовлення?');"
                        >
                            🚚 Відвантажити все замовлення
                        </button>

                    </div>

                </form>

            <?php endforeach; ?>


        <?php endif; ?>

    </section>

</main>

</body>

</html>
