<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

require_permission(
    'glass.create',
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

function stageLabel(string $name): string
{
    return match ($name) {
        'Порезка' => 'Порізка',
        'Обработка' => 'Обробка',
        'Закалка' => 'Гартування',
        'Контроль качества' => 'Контроль якості',
        'Упаковка' => 'Пакування',
        'Отгрузка' => 'Відвантаження',
        'Емалит' => 'Емаліт',
        'Триплекс' => 'Триплекс',
        default => $name,
    };
}

function routeLabel(string $name): string
{
    return match ($name) {
        'Стандартное стекло' => 'Стандартне скло',
        'Закалённое' => 'Гартоване скло',
        'Емалит' => 'Емаліт',
        'Триплекс' => 'Триплекс',
        default => $name,
    };
}

function priorityLabel(int $priority): string
{
    return match ($priority) {
        3 => '🔴 Критичний',
        2 => '🟠 Терміновий',
        default => '🟢 Звичайний',
    };
}

function writeCreateAudit(
    PDO $db,
    int $userId,
    string $action,
    string $entityType,
    int $entityId,
    ?array $oldValue,
    ?array $newValue
): void {

    $stmt = $db->prepare("
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
| Права
|--------------------------------------------------------------------------
*/

$canStartProduction =
    can(
        'orders.start_production',
        $user
    );

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION['csrf_glass_create']
    )
) {

    $_SESSION['csrf_glass_create'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['csrf_glass_create'];

/*
|--------------------------------------------------------------------------
| Довідник типів скла
|--------------------------------------------------------------------------
*/

$glassTypesStmt =
    $db->query("
        SELECT
            id,
            code,
            name
        FROM glass_types
        WHERE active = 1
        ORDER BY id
    ");

$glassTypes =
    $glassTypesStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Маршрути
|--------------------------------------------------------------------------
*/

$routesStmt =
    $db->query("
        SELECT
            r.id,
            r.name
        FROM routes r
        WHERE r.active = 1

          AND EXISTS (
              SELECT 1
              FROM route_steps rs
              WHERE rs.route_id = r.id
          )

        ORDER BY r.id
    ");

$routes =
    $routesStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

$routeSteps = [];

foreach (
    $routes
    as $route
) {

    $stmt =
        $db->prepare("
            SELECT
                id,
                step_number,
                name
            FROM route_steps
            WHERE route_id = :route_id
            ORDER BY step_number
        ");

    $stmt->execute([
        ':route_id' =>
            (int) $route['id'],
    ]);

    $routeSteps[
        (int) $route['id']
    ] =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}

/*
|--------------------------------------------------------------------------
| Значення форми
|--------------------------------------------------------------------------
*/

$orderNumber =
    trim(
        $_POST['order_number']
        ?? ''
    );

$customerName =
    trim(
        $_POST['customer_name']
        ?? ''
    );

$priority =
    (int) (
        $_POST['priority']
        ?? 1
    );

if (
    !in_array(
        $priority,
        [
            1,
            2,
            3,
        ],
        true
    )
) {
    $priority = 1;
}

$selectedGlassType =
    trim(
        $_POST['glass_type']
        ?? ''
    );

$thickness =
    trim(
        $_POST['thickness']
        ?? '4'
    );

$width =
    (int) (
        $_POST['width']
        ?? 0
    );

$height =
    (int) (
        $_POST['height']
        ?? 0
    );

$quantity =
    (int) (
        $_POST['quantity']
        ?? 1
    );

$routeId =
    (int) (
        $_POST['route_id']
        ?? 0
    );

$action =
    $_POST['action']
    ?? 'create_glass';

$error = '';
$success = '';

$createdGlasses = [];

$orderCreated = false;
$existingOrder = false;
$createdOrderId = null;
$startedOrder = null;

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    /*
     * CSRF
     */

    if (
        !hash_equals(
            $csrfToken,
            $_POST['csrf_token']
            ?? ''
        )
    ) {

        http_response_code(403);

        exit(
            'Помилка перевірки безпеки.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Запуск замовлення у виробництво
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'start_production'
    ) {

        require_permission(
            'orders.start_production',
            $user
        );

        $startOrderId =
            (int) (
                $_POST['order_id']
                ?? 0
            );

        if (
            $startOrderId <= 0
        ) {

            $error =
                'Замовлення не вказано.';

        } else {

            try {

                $db->beginTransaction();

                $orderStmt =
                    $db->prepare("
                        SELECT
                            id,
                            order_number,
                            customer_name,
                            priority,
                            status
                        FROM orders
                        WHERE id = :id
                        LIMIT 1
                    ");

                $orderStmt->execute([
                    ':id' =>
                        $startOrderId,
                ]);

                $startOrder =
                    $orderStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$startOrder) {

                    throw new RuntimeException(
                        'Замовлення не знайдено.'
                    );
                }

                if (
                    $startOrder['status']
                    === 'completed'
                ) {

                    throw new RuntimeException(
                        'Завершене замовлення неможливо повторно запустити у виробництво.'
                    );
                }

                if (
                    $startOrder['status']
                    === 'in_production'
                ) {

                    throw new RuntimeException(
                        'Замовлення вже перебуває у виробництві.'
                    );
                }

                $glassCountStmt =
                    $db->prepare("
                        SELECT COUNT(*)
                        FROM glasses
                        WHERE order_id = :order_id
                    ");

                $glassCountStmt->execute([
                    ':order_id' =>
                        $startOrderId,
                ]);

                $glassCount =
                    (int)
                    $glassCountStmt
                        ->fetchColumn();

                if (
                    $glassCount <= 0
                ) {

                    throw new RuntimeException(
                        'У замовленні немає скла.'
                    );
                }

                $updateStmt =
                    $db->prepare("
                        UPDATE orders
                        SET
                            status =
                                'in_production',

                            production_started_at =
                                CURRENT_TIMESTAMP,

                            production_completed_at =
                                NULL,

                            updated_at =
                                CURRENT_TIMESTAMP

                        WHERE id =
                            :id
                    ");

                $updateStmt->execute([
                    ':id' =>
                        $startOrderId,
                ]);

                writeCreateAudit(
                    $db,
                    (int)
                    $user['id'],
                    'start_production',
                    'order',
                    $startOrderId,
                    [
                        'status' =>
                            $startOrder['status'],
                    ],
                    [
                        'status' =>
                            'in_production',

                        'order_number' =>
                            $startOrder[
                                'order_number'
                            ],

                        'priority' =>
                            (int)
                            $startOrder[
                                'priority'
                            ],

                        'glasses_count' =>
                            $glassCount,
                    ]
                );

                $db->commit();

                $startedOrder =
                    $startOrder;

                $success =
                    'Замовлення №'
                    . $startOrder[
                        'order_number'
                    ]
                    . ' запущено у виробництво.';

                $orderNumber =
                    $startOrder[
                        'order_number'
                    ];

                $customerName =
                    $startOrder[
                        'customer_name'
                    ]
                    ?? '';

                $priority =
                    (int)
                    $startOrder[
                        'priority'
                    ];

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
                    'Не вдалося запустити замовлення: '
                    . $exception
                        ->getMessage();
            }
        }

    /*
    |--------------------------------------------------------------------------
    | Створення скла
    |--------------------------------------------------------------------------
    */

    } elseif (
        $action ===
        'create_glass'
    ) {

        require_permission(
            'glass.create',
            $user
        );

        /*
         * Валідація
         */

        if (
            $orderNumber === ''
        ) {

            $error =
                'Вкажіть номер замовлення.';

        } elseif (
            $customerName === ''
        ) {

            $error =
                'Вкажіть назву клієнта.';

        } elseif (
            $selectedGlassType === ''
        ) {

            $error =
                'Оберіть тип скла.';

        } elseif (
            !in_array(
                (float)
                $thickness,
                [
                    4.0,
                    5.0,
                    6.0,
                    8.0,
                    10.0,
                    12.0,
                ],
                true
            )
        ) {

            $error =
                'Оберіть допустиму товщину.';

        } elseif (
            $width <= 0
            ||
            $height <= 0
        ) {

            $error =
                'Ширина та висота повинні бути більшими за нуль.';

        } elseif (
            $quantity < 1
            ||
            $quantity > 100
        ) {

            $error =
                'Кількість повинна бути від 1 до 100.';

        } elseif (
            !isset(
                $routeSteps[
                    $routeId
                ]
            )
            ||
            empty(
                $routeSteps[
                    $routeId
                ]
            )
        ) {

            $error =
                'Оберіть маршрут.';

        } else {

            try {

                $db->beginTransaction();

                /*
                 * Шукаємо замовлення.
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

                /*
                 * Існуюче замовлення.
                 */

                if ($order) {

                    $existingOrder =
                        true;

                    if (
                        trim(
                            (string)
                            $order[
                                'customer_name'
                            ]
                        )
                        !== ''
                        &&
                        trim(
                            (string)
                            $order[
                                'customer_name'
                            ]
                        )
                        !==
                        $customerName
                    ) {

                        throw new RuntimeException(
                            'Замовлення №'
                            . $orderNumber
                            . ' вже існує та належить клієнту «'
                            . $order[
                                'customer_name'
                            ]
                            . '».'
                        );
                    }

                    $orderId =
                        (int)
                        $order['id'];

                    $createdOrderId =
                        $orderId;

                    /*
                     * Пріоритет існуючого
                     * замовлення НЕ змінюємо.
                     */

                    $priority =
                        (int)
                        $order[
                            'priority'
                        ];

                    /*
                     * Якщо клієнт раніше
                     * був порожній.
                     */

                    if (
                        trim(
                            (string)
                            $order[
                                'customer_name'
                            ]
                        )
                        === ''
                    ) {

                        $updateOrder =
                            $db->prepare("
                                UPDATE orders
                                SET
                                    customer_name =
                                        :customer_name,

                                    updated_at =
                                        CURRENT_TIMESTAMP

                                WHERE id =
                                    :id
                            ");

                        $updateOrder->execute([
                            ':customer_name' =>
                                $customerName,

                            ':id' =>
                                $orderId,
                        ]);

                        writeCreateAudit(
                            $db,
                            (int)
                            $user['id'],
                            'update_order_customer',
                            'order',
                            $orderId,
                            [
                                'customer_name' =>
                                    $order[
                                        'customer_name'
                                    ],
                            ],
                            [
                                'customer_name' =>
                                    $customerName,
                            ]
                        );
                    }

                /*
                 * Нове замовлення.
                 */

                } else {

                    $orderInsert =
                        $db->prepare("
                            INSERT INTO orders (
                                order_number,
                                customer_name,
                                status,
                                priority,
                                created_by,
                                created_at,
                                updated_at
                            )
                            VALUES (
                                :order_number,
                                :customer_name,
                                'new',
                                :priority,
                                :created_by,
                                CURRENT_TIMESTAMP,
                                CURRENT_TIMESTAMP
                            )
                        ");

                    $orderInsert->execute([
                        ':order_number' =>
                            $orderNumber,

                        ':customer_name' =>
                            $customerName,

                        ':priority' =>
                            $priority,

                        ':created_by' =>
                            (int)
                            $user['id'],
                    ]);

                    $orderId =
                        (int)
                        $db->lastInsertId();

                    $createdOrderId =
                        $orderId;

                    $orderCreated =
                        true;

                    writeCreateAudit(
                        $db,
                        (int)
                        $user['id'],
                        'create_order',
                        'order',
                        $orderId,
                        null,
                        [
                            'order_number' =>
                                $orderNumber,

                            'customer_name' =>
                                $customerName,

                            'status' =>
                                'new',

                            'priority' =>
                                $priority,
                        ]
                    );
                }

                /*
                 * Перший етап маршруту.
                 */

                $firstStep =
                    $routeSteps[
                        $routeId
                    ][0];

                $firstStepId =
                    (int)
                    $firstStep['id'];

                $firstLocation =
                    $firstStep['name'];

                /*
                 * Позиція замовлення.
                 */

                $itemInsert =
                    $db->prepare("
                        INSERT INTO order_items (
                            order_id,
                            glass_type,
                            thickness,
                            width,
                            height,
                            quantity,
                            created_at
                        )
                        VALUES (
                            :order_id,
                            :glass_type,
                            :thickness,
                            :width,
                            :height,
                            :quantity,
                            CURRENT_TIMESTAMP
                        )
                    ");

                $itemInsert->execute([
                    ':order_id' =>
                        $orderId,

                    ':glass_type' =>
                        $selectedGlassType,

                    ':thickness' =>
                        (float)
                        $thickness,

                    ':width' =>
                        $width,

                    ':height' =>
                        $height,

                    ':quantity' =>
                        $quantity,
                ]);

                $orderItemId =
                    (int)
                    $db->lastInsertId();

                /*
                 * Існуючі коди.
                 */

                $codesStmt =
                    $db->prepare("
                        SELECT code
                        FROM glasses
                        WHERE order_id =
                            :order_id
                    ");

                $codesStmt->execute([
                    ':order_id' =>
                        $orderId,
                ]);

                $usedCodes = [];

                foreach (
                    $codesStmt->fetchAll(
                        PDO::FETCH_COLUMN
                    )
                    as $usedCode
                ) {

                    $usedCodes[
                        (string)
                        $usedCode
                    ] =
                        true;
                }

                /*
                 * Створення фізичних листів.
                 */

                $glassInsert =
                    $db->prepare("
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
                            order_id,
                            order_item_id,
                            thickness,
                            route_id,
                            current_step_id,
                            created_at,
                            updated_at
                        )
                        VALUES (
                            :code,
                            :order_number,
                            :glass_type,
                            :width,
                            :height,
                            1,
                            'waiting',
                            :current_location,
                            NULL,
                            :order_id,
                            :order_item_id,
                            :thickness,
                            :route_id,
                            :current_step_id,
                            CURRENT_TIMESTAMP,
                            CURRENT_TIMESTAMP
                        )
                    ");

                $sequence = 1;

                for (
                    $i = 1;
                    $i <= $quantity;
                    $i++
                ) {

                    do {

                        $code =
                            $orderNumber
                            . '-'
                            . str_pad(
                                (string)
                                $sequence,
                                3,
                                '0',
                                STR_PAD_LEFT
                            );

                        $sequence++;

                    } while (
                        isset(
                            $usedCodes[
                                $code
                            ]
                        )
                    );

                    $usedCodes[
                        $code
                    ] =
                        true;

                    $glassInsert->execute([
                        ':code' =>
                            $code,

                        ':order_number' =>
                            $orderNumber,

                        ':glass_type' =>
                            $selectedGlassType,

                        ':width' =>
                            $width,

                        ':height' =>
                            $height,

                        ':current_location' =>
                            $firstLocation,

                        ':order_id' =>
                            $orderId,

                        ':order_item_id' =>
                            $orderItemId,

                        ':thickness' =>
                            (float)
                            $thickness,

                        ':route_id' =>
                            $routeId,

                        ':current_step_id' =>
                            $firstStepId,
                    ]);

                    $glassId =
                        (int)
                        $db->lastInsertId();

                    writeCreateAudit(
                        $db,
                        (int)
                        $user['id'],
                        'create_glass',
                        'glass',
                        $glassId,
                        null,
                        [
                            'code' =>
                                $code,

                            'order_id' =>
                                $orderId,

                            'order_number' =>
                                $orderNumber,

                            'customer_name' =>
                                $customerName,

                            'priority' =>
                                $priority,

                            'glass_type' =>
                                $selectedGlassType,

                            'thickness' =>
                                (float)
                                $thickness,

                            'width' =>
                                $width,

                            'height' =>
                                $height,

                            'route_id' =>
                                $routeId,

                            'current_step_id' =>
                                $firstStepId,

                            'current_location' =>
                                $firstLocation,

                            'status' =>
                                'waiting',
                        ]
                    );

                    $createdGlasses[] = [
                        'id' =>
                            $glassId,

                        'code' =>
                            $code,
                    ];
                }

                $db->commit();

                $success =
                    'Створено стекол: '
                    . count(
                        $createdGlasses
                    )
                    . '.';

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
                    'Не вдалося створити скло: '
                    . $exception
                        ->getMessage();
            }
        }
    }
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
        Додати скло — OPTIMA GLASS
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            font-family: Arial, sans-serif;
        }

        .page {
            max-width: 850px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .card {
            padding: 26px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        h1 {
            margin-top: 0;
        }

        .subtitle {
            margin-bottom: 24px;
            color: #6b7280;
        }

        .message {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 10px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .warning {
            margin-top: 12px;
            padding: 12px;
            border-radius: 8px;
            background: #fef3c7;
            color: #92400e;
        }

        .created-list {
            margin-top: 12px;
            padding: 12px;
            border-radius: 8px;
            background: #f9fafb;
        }

        .grid {
            display: grid;
            grid-template-columns:
                repeat(
                    2,
                    minmax(0, 1fr)
                );
            gap: 17px;
        }

        .field {
            margin-bottom: 17px;
        }

        .field-full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
        }

        input,
        select {
            width: 100%;
            min-height: 44px;
            padding: 0 11px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font: inherit;
        }

        .readonly {
            background: #f9fafb;
        }

        .route-preview {
            padding: 12px;
            border-radius: 8px;
            background: #f9fafb;
            line-height: 1.6;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border: 0;
            border-radius: 9px;
            background: #111827;
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
        }

        .button-secondary {
            background: #f3f4f6;
            color: #111827;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        @media (
            max-width: 650px
        ) {

            .grid {
                grid-template-columns: 1fr;
            }

            .field-full {
                grid-column: auto;
            }

            .actions {
                flex-direction: column;
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

    <section class="card">

        <h1>
            Додати скло
        </h1>

        <div class="subtitle">

            Створення замовлення
            та окремого QR-коду
            для кожного фізичного листа скла.

        </div>


        <?php if (
            $success !== ''
        ): ?>

            <div class="message success">

                <strong>
                    <?= e(
                        $success
                    ) ?>
                </strong>


                <?php if (
                    $orderCreated
                ): ?>

                    <div class="warning">

                        Нове замовлення №
                        <strong>
                            <?= e(
                                $orderNumber
                            ) ?>
                        </strong>

                        створено зі статусом «Нове».

                        <br><br>

                        Пріоритет:
                        <strong>
                            <?= e(
                                priorityLabel(
                                    $priority
                                )
                            ) ?>
                        </strong>

                    </div>

                <?php elseif (
                    $existingOrder
                    &&
                    $createdGlasses
                ): ?>

                    <div
                        style="margin-top:8px;"
                    >

                        Скло додано до
                        існуючого замовлення №
                        <?= e(
                            $orderNumber
                        ) ?>.

                        Поточний пріоритет:
                        <strong>
                            <?= e(
                                priorityLabel(
                                    $priority
                                )
                            ) ?>
                        </strong>

                    </div>

                <?php endif; ?>


                <?php if (
                    $createdGlasses
                ): ?>

                    <div class="created-list">

                        <?php foreach (
                            $createdGlasses
                            as $created
                        ): ?>

                            <div>

                                ✅
                                <?= e(
                                    $created[
                                        'code'
                                    ]
                                ) ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>


                <?php if (
                    $orderCreated
                    &&
                    $canStartProduction
                    &&
                    $createdOrderId !== null
                ): ?>

                    <form
                        method="post"
                        class="actions"
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
                            name="action"
                            value="start_production"
                        >

                        <input
                            type="hidden"
                            name="order_id"
                            value="<?= (int)
                                $createdOrderId ?>"
                        >

                        <button
                            type="submit"
                            class="button"
                        >
                            ▶ Запустити у виробництво
                        </button>

                    </form>

                <?php endif; ?>


                <?php if (
                    $startedOrder
                ): ?>

                    <div class="actions">

                        <a
                            href="/production.php"
                            class="button"
                        >
                            Перейти у виробництво
                        </a>

                        <a
                            href="/glass_create.php"
                            class="button button-secondary"
                        >
                            + Додати ще скло
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>


        <?php if (
            $error !== ''
        ): ?>

            <div class="message error">

                <?= e(
                    $error
                ) ?>

            </div>

        <?php endif; ?>


        <form method="post">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(
                    $csrfToken
                ) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="create_glass"
            >


            <div class="grid">

                <div class="field">

                    <label
                        for="order_number"
                    >
                        Номер замовлення
                    </label>

                    <input
                        type="text"
                        id="order_number"
                        name="order_number"
                        value="<?= e(
                            $orderNumber
                        ) ?>"
                        placeholder="Наприклад: 301"
                        required
                    >

                </div>


                <div class="field">

                    <label
                        for="customer_name"
                    >
                        Назва клієнта
                    </label>

                    <input
                        type="text"
                        id="customer_name"
                        name="customer_name"
                        value="<?= e(
                            $customerName
                        ) ?>"
                        placeholder="Назва клієнта"
                        required
                    >

                </div>


                <div class="field">

                    <label for="priority">
                        Пріоритет замовлення
                    </label>

                    <select
                        id="priority"
                        name="priority"
                        required
                    >

                        <option
                            value="1"
                            <?= $priority === 1
                                ? 'selected'
                                : '' ?>
                        >
                            🟢 Звичайний
                        </option>

                        <option
                            value="2"
                            <?= $priority === 2
                                ? 'selected'
                                : '' ?>
                        >
                            🟠 Терміновий
                        </option>

                        <option
                            value="3"
                            <?= $priority === 3
                                ? 'selected'
                                : '' ?>
                        >
                            🔴 Критичний
                        </option>

                    </select>

                </div>


                <div class="field">

                    <label>
                        Код / QR
                    </label>

                    <input
                        type="text"
                        class="readonly"
                        value="Генерується автоматично"
                        readonly
                    >

                </div>


                <div class="field">

                    <label
                        for="glass_type"
                    >
                        Тип скла
                    </label>

                    <select
                        id="glass_type"
                        name="glass_type"
                        required
                    >

                        <option value="">
                            Оберіть тип скла
                        </option>

                        <?php foreach (
                            $glassTypes
                            as $type
                        ): ?>

                            <?php

                            $typeName =
                                $type['name']
                                ?: $type['code'];

                            ?>

                            <option
                                value="<?= e(
                                    $typeName
                                ) ?>"
                                <?= $selectedGlassType ===
                                    $typeName
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e(
                                    $typeName
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="field">

                    <label
                        for="thickness"
                    >
                        Товщина
                    </label>

                    <select
                        id="thickness"
                        name="thickness"
                        required
                    >

                        <?php foreach (
                            [
                                4,
                                5,
                                6,
                                8,
                                10,
                                12,
                            ]
                            as $value
                        ): ?>

                            <option
                                value="<?= $value ?>"
                                <?= (float)
                                    $thickness
                                    ===
                                    (float)
                                    $value
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= $value ?> мм
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="field">

                    <label for="width">
                        Ширина, мм
                    </label>

                    <input
                        type="number"
                        id="width"
                        name="width"
                        min="1"
                        value="<?= $width > 0
                            ? $width
                            : '' ?>"
                        required
                    >

                </div>


                <div class="field">

                    <label for="height">
                        Висота, мм
                    </label>

                    <input
                        type="number"
                        id="height"
                        name="height"
                        min="1"
                        value="<?= $height > 0
                            ? $height
                            : '' ?>"
                        required
                    >

                </div>


                <div class="field">

                    <label for="quantity">
                        Кількість
                    </label>

                    <input
                        type="number"
                        id="quantity"
                        name="quantity"
                        min="1"
                        max="100"
                        value="<?= max(
                            1,
                            $quantity
                        ) ?>"
                        required
                    >

                </div>


                <div class="field">

                    <label for="route_id">
                        Маршрут
                    </label>

                    <select
                        id="route_id"
                        name="route_id"
                        required
                    >

                        <option value="">
                            Оберіть маршрут
                        </option>

                        <?php foreach (
                            $routes
                            as $route
                        ): ?>

                            <option
                                value="<?= (int)
                                    $route['id'] ?>"
                                <?= $routeId ===
                                    (int)
                                    $route['id']
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e(
                                    routeLabel(
                                        $route['name']
                                    )
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div
                    class="field field-full"
                >

                    <label>
                        Маршрут скла
                    </label>

                    <div
                        id="routePreview"
                        class="route-preview"
                    >
                        Оберіть маршрут
                    </div>

                </div>


                <div
                    class="field field-full"
                >

                    <label>
                        Початкова дільниця
                    </label>

                    <input
                        id="startStage"
                        type="text"
                        class="readonly"
                        value="—"
                        readonly
                    >

                </div>

            </div>


            <button
                type="submit"
                class="button"
            >
                + Створити скло
            </button>

        </form>

    </section>

</main>


<script>

const routeData =
<?= json_encode(
    array_map(
        static function (
            array $route
        ) use (
            $routeSteps
        ): array {

            $id =
                (int)
                $route['id'];

            return [
                'id' =>
                    $id,

                'steps' =>
                    array_map(
                        static fn (
                            array $step
                        ): string =>
                            stageLabel(
                                $step['name']
                            ),
                        $routeSteps[
                            $id
                        ]
                        ?? []
                    ),
            ];
        },
        $routes
    ),
    JSON_UNESCAPED_UNICODE
) ?>;

const routeSelect =
    document.getElementById(
        'route_id'
    );

const preview =
    document.getElementById(
        'routePreview'
    );

const startStage =
    document.getElementById(
        'startStage'
    );

function updateRoutePreview() {

    const id =
        Number(
            routeSelect.value
        );

    const route =
        routeData.find(
            item =>
                item.id === id
        );

    if (
        !route
        ||
        !route.steps.length
    ) {

        preview.textContent =
            'Оберіть маршрут';

        startStage.value =
            '—';

        return;
    }

    preview.textContent =
        route.steps.join(
            ' → '
        );

    startStage.value =
        route.steps[0];
}

routeSelect.addEventListener(
    'change',
    updateRoutePreview
);

updateRoutePreview();

</script>

</body>

</html>
