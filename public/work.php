<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/notifications.php';
require __DIR__ . '/../src/telegram.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

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

function writeAudit(
    PDO $db,
    int $userId,
    string $action,
    ?string $entityType,
    ?int $entityId,
    ?array $oldValue = null,
    ?array $newValue = null
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
        ':user_id' => $userId,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,

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
| Доступ
|--------------------------------------------------------------------------
*/

require_permission(
    'production.view',
    $user
);

$canScan =
    can(
        'glass.scan',
        $user
    );

$canReject =
    can(
        'glass.reject',
        $user
    );

$canCompleteOrderStage =
    can(
        'production.complete_order_stage',
        $user
    );

$stageId =
    current_stage_id(
        $user
    );

if ($stageId === null) {

    http_response_code(403);

    exit(
        'Користувачу не призначено виробничу дільницю.'
    );
}

/*
|--------------------------------------------------------------------------
| Поточна дільниця
|--------------------------------------------------------------------------
*/

$stageStmt =
    $db->prepare("
        SELECT
            id,
            name,
            execution_mode
        FROM production_stages
        WHERE id = :id
          AND active = 1
        LIMIT 1
    ");

$stageStmt->execute([
    ':id' => $stageId,
]);

$currentStage =
    $stageStmt->fetch(
        PDO::FETCH_ASSOC
    );

if (!$currentStage) {

    http_response_code(403);

    exit(
        'Виробничу дільницю не знайдено.'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION['csrf_work']
    )
) {

    $_SESSION['csrf_work'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['csrf_work'];

/*
|--------------------------------------------------------------------------
| Результат операції
|--------------------------------------------------------------------------
*/

$scanType = '';
$scanTitle = '';
$scanMessage = '';
$scannedCode = '';

$orderQrPreview = null;
$orderQrGlasses = [];
$orderQrArea = 0.0;
$orderQrBatchCount = 0;
$orderQrRejectedCount = 0;

/*
|--------------------------------------------------------------------------
| Завантаження скла замовлення на поточній дільниці
|--------------------------------------------------------------------------
*/

function loadOrderStageGlasses(
    PDO $db,
    int $orderId,
    int $stageId
): array {

    $stmt =
        $db->prepare("
            SELECT
                g.id,
                g.code,
                g.order_id,
                g.order_number,
                g.status,
                g.width,
                g.height,
                g.quantity,
                g.route_id,
                g.current_step_id,
                g.current_location,

                rs.step_number,
                rs.name AS stage_name,

                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM production_batch_items pbi
                        JOIN production_batches pb
                            ON pb.id = pbi.batch_id
                        WHERE pbi.glass_id = g.id
                          AND pb.status IN (
                              'created',
                              'in_progress'
                          )
                    )
                    THEN 1
                    ELSE 0
                END AS in_active_batch

            FROM glasses g

            JOIN route_steps rs
                ON rs.id =
                    g.current_step_id

            JOIN production_stages ps
                ON ps.name =
                    rs.name

            WHERE g.order_id =
                :order_id

              AND ps.id =
                :stage_id

            ORDER BY g.id
        ");

    $stmt->execute([
        ':order_id' =>
            $orderId,

        ':stage_id' =>
            $stageId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

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
     * CSRF.
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

    $action =
        $_POST['action']
        ?? '';

    /*
    |--------------------------------------------------------------------------
    | Сканування QR
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'scan_glass'
    ) {

        require_permission(
            'glass.scan',
            $user
        );

        $code =
            trim(
                $_POST['code']
                ?? ''
            );

        $scannedCode =
            $code;

        /*
         * ---------------------------------------------------------------
         * Порожній код.
         * ---------------------------------------------------------------
         */

        if ($code === '') {

            $scanType =
                'error';

            $scanTitle =
                'QR-код не вказано';

            $scanMessage =
                'Відскануйте QR-код скла або замовлення.';

            writeAudit(
                $db,
                (int) $user['id'],
                'scan_empty',
                null,
                null,
                null,
                [
                    'code' => '',
                ]
            );

        /*
         * ---------------------------------------------------------------
         * Службовий QR замовлення.
         * ---------------------------------------------------------------
         */

        } elseif (
            str_starts_with(
                strtoupper($code),
                'ORDER-'
            )
        ) {

            require_permission(
                'production.complete_order_stage',
                $user
            );

            $orderNumber =
                trim(
                    substr(
                        $code,
                        6
                    )
                );

            if (
                $orderNumber === ''
            ) {

                $scanType =
                    'error';

                $scanTitle =
                    'Некоректний QR замовлення';

                $scanMessage =
                    'Не вдалося визначити номер замовлення.';

            } else {

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

                $orderQrPreview =
                    $orderStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (
                    !$orderQrPreview
                ) {

                    $scanType =
                        'error';

                    $scanTitle =
                        'Замовлення не знайдено';

                    $scanMessage =
                        'QR-код «'
                        . e($code)
                        . '» не відповідає жодному замовленню.';

                    writeAudit(
                        $db,
                        (int) $user['id'],
                        'scan_order_not_found',
                        'order',
                        null,
                        null,
                        [
                            'code' =>
                                $code,

                            'order_number' =>
                                $orderNumber,
                        ]
                    );

                } elseif (
                    $orderQrPreview[
                        'status'
                    ]
                    !==
                    'in_production'
                ) {

                    $scanType =
                        'warning';

                    $scanTitle =
                        'Замовлення не у виробництві';

                    $scanMessage =
                        'Замовлення №'
                        . e(
                            $orderQrPreview[
                                'order_number'
                            ]
                        )
                        . ' зараз не перебуває у виробництві.';

                } else {

                    $allOrderGlasses =
                        loadOrderStageGlasses(
                            $db,
                            (int)
                            $orderQrPreview['id'],
                            $stageId
                        );

                    foreach (
                        $allOrderGlasses
                        as $glass
                    ) {

                        if (
                            (int)
                            $glass[
                                'in_active_batch'
                            ]
                            === 1
                        ) {

                            $orderQrBatchCount++;

                            continue;
                        }

                        if (
                            $glass['status']
                            === 'rejected'
                        ) {

                            $orderQrRejectedCount++;

                            continue;
                        }

                        if (
                            !in_array(
                                $glass[
                                    'status'
                                ],
                                [
                                    'waiting',
                                    'in_progress',
                                ],
                                true
                            )
                        ) {

                            continue;
                        }

                        $orderQrGlasses[] =
                            $glass;

                        $orderQrArea +=
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
                                    $glass[
                                        'quantity'
                                    ]
                                )
                            )
                            / 1000000;
                    }

                    $scanType =
                        'order_preview';

                    $scanTitle =
                        'Замовлення №'
                        . $orderQrPreview[
                            'order_number'
                        ];

                    $scanMessage =
                        'Перевірте дані перед масовим завершенням.';
                }
            }

        /*
         * ---------------------------------------------------------------
         * Звичайний QR конкретного скла.
         * ---------------------------------------------------------------
         */

        } else {

            $stmt =
                $db->prepare("
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
                        g.route_id,

                        o.status AS order_status,
                        o.customer_name,
                        o.priority,
                        o.planned_date,

                        rs.id AS route_step_id,
                        rs.step_number,
                        rs.name AS stage_name,

                        ps.id AS production_stage_id,
                        ps.name AS production_stage_name

                    FROM glasses g

                    JOIN route_steps rs
                        ON rs.id =
                            g.current_step_id

                    JOIN production_stages ps
                        ON ps.name =
                            rs.name

                    LEFT JOIN orders o
                        ON o.id =
                            g.order_id

                    WHERE g.code =
                        :code

                    LIMIT 1
                ");

            $stmt->execute([
                ':code' =>
                    $code,
            ]);

            $glass =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            /*
             * Скло не знайдено.
             */

            if (!$glass) {

                $scanType =
                    'error';

                $scanTitle =
                    'Скло не знайдено';

                $scanMessage =
                    'QR-код «'
                    . e($code)
                    . '» відсутній у системі.';

                writeAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_not_found',
                    'glass',
                    null,
                    null,
                    [
                        'code' =>
                            $code,
                    ]
                );

            /*
             * Інша дільниця.
             */

            } elseif (
                (int)
                $glass[
                    'production_stage_id'
                ]
                !==
                $stageId
            ) {

                $scanType =
                    'warning';

                $scanTitle =
                    '⚠️ СКАНУВАННЯ НЕ ПРИЙНЯТО';

                $scanMessage =
                    'Скло зараз знаходиться на дільниці «'
                    . e(
                        stageLabel(
                            $glass[
                                'production_stage_name'
                            ]
                        )
                    )
                    . '», а ваша дільниця — «'
                    . e(
                        stageLabel(
                            $currentStage[
                                'name'
                            ]
                        )
                    )
                    . '».';

                writeAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_denied',
                    'glass',
                    (int) $glass['id'],
                    null,
                    [
                        'reason' =>
                            'wrong_stage',

                        'code' =>
                            $glass['code'],

                        'glass_stage_id' =>
                            (int)
                            $glass[
                                'production_stage_id'
                            ],

                        'employee_stage_id' =>
                            $stageId,
                    ]
                );

            /*
             * Замовлення не запущено.
             */

            } elseif (
                $glass[
                    'order_status'
                ]
                !==
                'in_production'
            ) {

                $scanType =
                    'warning';

                $scanTitle =
                    '⚠️ СКАНУВАННЯ НЕ ПРИЙНЯТО';

                $scanMessage =
                    'Замовлення №'
                    . e(
                        $glass[
                            'order_number'
                        ]
                    )
                    . ' не перебуває у виробництві.';

            /*
             * Некоректний статус.
             */

            } elseif (
                !in_array(
                    $glass[
                        'status'
                    ],
                    [
                        'waiting',
                        'in_progress',
                    ],
                    true
                )
            ) {

                $scanType =
                    'warning';

                $scanTitle =
                    '⚠️ СКАНУВАННЯ НЕ ПРИЙНЯТО';

                $scanMessage =
                    'Скло має статус «'
                    . e(
                        $glass[
                            'status'
                        ]
                    )
                    . '» і не може бути оброблене.';

            } else {

                /*
                 * Активна партія.
                 */

                $batchStmt =
                    $db->prepare("
                        SELECT
                            pb.id

                        FROM production_batch_items pbi

                        JOIN production_batches pb
                            ON pb.id =
                                pbi.batch_id

                        WHERE pbi.glass_id =
                            :glass_id

                          AND pb.status IN (
                              'created',
                              'in_progress'
                          )

                        LIMIT 1
                    ");

                $batchStmt->execute([
                    ':glass_id' =>
                        (int)
                        $glass['id'],
                ]);

                $activeBatchId =
                    $batchStmt
                        ->fetchColumn();

                if (
                    $activeBatchId !== false
                ) {

                    $scanType =
                        'warning';

                    $scanTitle =
                        '⚠️ СКЛО У ПАРТІЇ';

                    $scanMessage =
                        'Скло входить до активної партії №'
                        . (int)
                        $activeBatchId
                        . '. Завершіть його через сторінку партії.';

                } else {

                    try {

                        $db->beginTransaction();

                        /*
                         * Повторне читання.
                         */

                        $currentStmt =
                            $db->prepare("
                                SELECT
                                    g.id,
                                    g.code,
                                    g.order_id,
                                    g.order_number,
                                    g.status,
                                    g.width,
                                    g.height,
                                    g.quantity,
                                    g.current_step_id,
                                    g.current_location,
                                    g.route_id,

                                    o.status AS order_status,
                                    o.priority,

                                    rs.step_number,
                                    rs.name AS stage_name

                                FROM glasses g

                                JOIN route_steps rs
                                    ON rs.id =
                                        g.current_step_id

                                JOIN orders o
                                    ON o.id =
                                        g.order_id

                                WHERE g.id =
                                    :id

                                LIMIT 1
                            ");

                        $currentStmt->execute([
                            ':id' =>
                                (int)
                                $glass['id'],
                        ]);

                        $currentGlass =
                            $currentStmt->fetch(
                                PDO::FETCH_ASSOC
                            );

                        if (!$currentGlass) {

                            throw new RuntimeException(
                                'Скло більше не знайдено.'
                            );
                        }

                        if (
                            $currentGlass[
                                'order_status'
                            ]
                            !==
                            'in_production'
                        ) {

                            throw new RuntimeException(
                                'Замовлення більше не перебуває у виробництві.'
                            );
                        }

                        if (
                            !in_array(
                                $currentGlass[
                                    'status'
                                ],
                                [
                                    'waiting',
                                    'in_progress',
                                ],
                                true
                            )
                        ) {

                            throw new RuntimeException(
                                'Скло вже оброблене.'
                            );
                        }

                        /*
                         * Наступний етап.
                         */

                        $nextStmt =
                            $db->prepare("
                                SELECT
                                    id,
                                    step_number,
                                    name
                                FROM route_steps
                                WHERE route_id =
                                    :route_id
                                  AND step_number =
                                    :step_number
                                LIMIT 1
                            ");

                        $nextStmt->execute([
                            ':route_id' =>
                                (int)
                                $currentGlass[
                                    'route_id'
                                ],

                            ':step_number' =>
                                (int)
                                $currentGlass[
                                    'step_number'
                                ]
                                + 1,
                        ]);

                        $nextStep =
                            $nextStmt->fetch(
                                PDO::FETCH_ASSOC
                            );

                        $nextStageId = null;

                        if ($nextStep) {

                            $newStatus =
                                'waiting';

                            $newStepId =
                                (int)
                                $nextStep['id'];

                            $newLocation =
                                $nextStep['name'];

                            $toStage =
                                $nextStep['name'];

                            $nextStageStmt =
                                $db->prepare("
                                    SELECT id
                                    FROM production_stages
                                    WHERE name =
                                        :name
                                      AND active = 1
                                    LIMIT 1
                                ");

                            $nextStageStmt->execute([
                                ':name' =>
                                    $nextStep[
                                        'name'
                                    ],
                            ]);

                            $nextStageId =
                                $nextStageStmt
                                    ->fetchColumn();

                            if (
                                $nextStageId !== false
                            ) {

                                $nextStageId =
                                    (int)
                                    $nextStageId;

                            } else {

                                $nextStageId =
                                    null;
                            }

                        } else {

                            $newStatus =
                                'completed';

                            $newStepId =
                                (int)
                                $currentGlass[
                                    'current_step_id'
                                ];

                            $newLocation =
                                'Готово';

                            $toStage =
                                null;
                        }

                        /*
                         * glass_operations.
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
                                    'production',
                                    :from_stage,
                                    :to_stage,
                                    'completed',
                                    NULL,
                                    :comment
                                )
                            ");

                        $operationStmt->execute([
                            ':glass_id' =>
                                (int)
                                $currentGlass['id'],

                            ':employee_id' =>
                                (int)
                                $user['id'],

                            ':route_step_id' =>
                                (int)
                                $currentGlass[
                                    'current_step_id'
                                ],

                            ':from_stage' =>
                                $currentGlass[
                                    'stage_name'
                                ],

                            ':to_stage' =>
                                $toStage,

                            ':comment' =>
                                'Операцію завершено QR-скануванням.',
                        ]);

                        /*
                         * glass_history.
                         */

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
                                    :new_status,
                                    :old_location,
                                    :new_location,
                                    :comment
                                )
                            ");

                        $historyStmt->execute([
                            ':glass_id' =>
                                (int)
                                $currentGlass['id'],

                            ':employee_id' =>
                                (int)
                                $user['id'],

                            ':old_status' =>
                                $currentGlass[
                                    'status'
                                ],

                            ':new_status' =>
                                $newStatus,

                            ':old_location' =>
                                $currentGlass[
                                    'current_location'
                                ],

                            ':new_location' =>
                                $newLocation,

                            ':comment' =>
                                'QR-сканування.',
                        ]);

                        /*
                         * glasses.
                         */

                        $updateStmt =
                            $db->prepare("
                                UPDATE glasses
                                SET
                                    status =
                                        :status,

                                    current_step_id =
                                        :current_step_id,

                                    current_location =
                                        :current_location,

                                    employee_id =
                                        NULL,

                                    updated_at =
                                        CURRENT_TIMESTAMP

                                WHERE id =
                                    :id
                            ");

                        $updateStmt->execute([
                            ':status' =>
                                $newStatus,

                            ':current_step_id' =>
                                $newStepId,

                            ':current_location' =>
                                $newLocation,

                            ':id' =>
                                (int)
                                $currentGlass['id'],
                        ]);

                        /*
                         * Сповіщення.
                         */

                        $notificationIds = [];

                        if (
                            $nextStageId !== null
                        ) {

                            $notificationIds =
                                notifyStage(
                                    $db,
                                    $nextStageId,
                                    'glass_moved',
                                    'Нове скло надійшло на дільницю',
                                    'Скло '
                                    . $currentGlass['code']
                                    . ' із замовлення '
                                    . $currentGlass[
                                        'order_number'
                                    ]
                                    . ' надійшло на дільницю «'
                                    . $nextStep['name']
                                    . '».',
                                    'glass',
                                    (int)
                                    $currentGlass['id']
                                );
                        }

                        writeAudit(
                            $db,
                            (int) $user['id'],
                            'scan_glass',
                            'glass',
                            (int)
                            $currentGlass['id'],
                            [
                                'status' =>
                                    $currentGlass[
                                        'status'
                                    ],

                                'current_step_id' =>
                                    (int)
                                    $currentGlass[
                                        'current_step_id'
                                    ],

                                'current_location' =>
                                    $currentGlass[
                                        'current_location'
                                    ],
                            ],
                            [
                                'status' =>
                                    $newStatus,

                                'current_step_id' =>
                                    $newStepId,

                                'current_location' =>
                                    $newLocation,

                                'notification_ids' =>
                                    $notificationIds,
                            ]
                        );

                        $db->commit();

                        /*
                         * Telegram після commit.
                         */

                        if ($nextStep) {

                            try {

                                $areaM2 =
                                    (
                                        (int)
                                        $currentGlass[
                                            'width'
                                        ]
                                        *
                                        (int)
                                        $currentGlass[
                                            'height'
                                        ]
                                        *
                                        max(
                                            1,
                                            (int)
                                            $currentGlass[
                                                'quantity'
                                            ]
                                        )
                                    )
                                    / 1000000;

                                $telegramMessage =
                                    formatTelegramGlassMoved(
                                        $currentGlass[
                                            'code'
                                        ],
                                        $currentGlass[
                                            'order_number'
                                        ],
                                        $currentGlass[
                                            'stage_name'
                                        ],
                                        $toStage,
                                        (int)
                                        $currentGlass[
                                            'priority'
                                        ],
                                        (int)
                                        $currentGlass[
                                            'width'
                                        ],
                                        (int)
                                        $currentGlass[
                                            'height'
                                        ],
                                        $areaM2
                                    );

                                $telegramResult =
                                    sendTelegramToGroup(
                                        $db,
                                        $telegramMessage
                                    );

                                writeAudit(
                                    $db,
                                    (int)
                                    $user['id'],
                                    'telegram_notification',
                                    'glass',
                                    (int)
                                    $currentGlass['id'],
                                    null,
                                    [
                                        'sent' =>
                                            $telegramResult[
                                                'sent'
                                            ]
                                            ?? false,

                                        'group' =>
                                            $telegramResult[
                                                'group_title'
                                            ]
                                            ?? null,

                                        'error' =>
                                            $telegramResult[
                                                'error'
                                            ]
                                            ?? null,
                                    ]
                                );

                            } catch (
                                Throwable
                                $telegramException
                            ) {
                                // Telegram не впливає на виробництво.
                            }
                        }

                        $scanType =
                            'success';

                        $scanTitle =
                            '✅ СКАНУВАННЯ ПРИЙНЯТО';

                        if ($nextStep) {

                            $scanMessage =
                                '<strong>'
                                . e(
                                    $currentGlass[
                                        'code'
                                    ]
                                )
                                . '</strong><br>'
                                . e(
                                    stageLabel(
                                        $currentGlass[
                                            'stage_name'
                                        ]
                                    )
                                )
                                . ' → '
                                . e(
                                    stageLabel(
                                        $toStage
                                    )
                                );

                        } else {

                            $scanMessage =
                                '<strong>'
                                . e(
                                    $currentGlass[
                                        'code'
                                    ]
                                )
                                . '</strong><br>'
                                . 'Маршрут скла завершено.';
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

                        $scanType =
                            'error';

                        $scanTitle =
                            '❌ ОПЕРАЦІЮ НЕ ВИКОНАНО';

                        $scanMessage =
                            e(
                                $exception
                                    ->getMessage()
                            );

                        try {

                            writeAudit(
                                $db,
                                (int)
                                $user['id'],
                                'scan_glass_error',
                                'glass',
                                (int)
                                $glass['id'],
                                null,
                                [
                                    'code' =>
                                        $code,

                                    'error' =>
                                        $exception
                                            ->getMessage(),
                                ]
                            );

                        } catch (
                            Throwable
                            $auditException
                        ) {
                        }
                    }
                }
            }
        }

    /*
    |--------------------------------------------------------------------------
    | Масове завершення замовлення
    |--------------------------------------------------------------------------
    */

    } elseif (
        $action ===
        'complete_order_stage'
    ) {

        require_permission(
            'production.complete_order_stage',
            $user
        );

        $orderId =
            (int) (
                $_POST[
                    'order_id'
                ]
                ?? 0
            );

        if (
            $orderId <= 0
        ) {

            $scanType =
                'error';

            $scanTitle =
                'Замовлення не вказано';

            $scanMessage =
                'Не вдалося визначити замовлення.';

        } else {

            try {

                $db->beginTransaction();

                /*
                 * Замовлення.
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
                        WHERE id = :id
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
                    $order['status']
                    !==
                    'in_production'
                ) {

                    throw new RuntimeException(
                        'Замовлення не перебуває у виробництві.'
                    );
                }

                /*
                 * Повторно читаємо скло.
                 */

                $allGlasses =
                    loadOrderStageGlasses(
                        $db,
                        $orderId,
                        $stageId
                    );

                $completedIds = [];
                $completedCodes = [];
                $completedArea = 0.0;

                foreach (
                    $allGlasses
                    as $glass
                ) {

                    /*
                     * Скло в активній партії
                     * не чіпаємо.
                     */

                    if (
                        (int)
                        $glass[
                            'in_active_batch'
                        ]
                        === 1
                    ) {
                        continue;
                    }

                    /*
                     * Брак / готове / інше
                     * не чіпаємо.
                     */

                    if (
                        !in_array(
                            $glass['status'],
                            [
                                'waiting',
                                'in_progress',
                            ],
                            true
                        )
                    ) {
                        continue;
                    }

                    /*
                     * Наступний етап.
                     */

                    $nextStmt =
                        $db->prepare("
                            SELECT
                                id,
                                step_number,
                                name
                            FROM route_steps
                            WHERE route_id =
                                :route_id
                              AND step_number =
                                :step_number
                            LIMIT 1
                        ");

                    $nextStmt->execute([
                        ':route_id' =>
                            (int)
                            $glass[
                                'route_id'
                            ],

                        ':step_number' =>
                            (int)
                            $glass[
                                'step_number'
                            ]
                            + 1,
                    ]);

                    $nextStep =
                        $nextStmt->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if ($nextStep) {

                        $newStatus =
                            'waiting';

                        $newStepId =
                            (int)
                            $nextStep['id'];

                        $newLocation =
                            $nextStep['name'];

                        $toStage =
                            $nextStep['name'];

                    } else {

                        $newStatus =
                            'completed';

                        $newStepId =
                            (int)
                            $glass[
                                'current_step_id'
                            ];

                        $newLocation =
                            'Готово';

                        $toStage =
                            null;
                    }

                    /*
                     * operation.
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
                                'production',
                                :from_stage,
                                :to_stage,
                                'completed',
                                NULL,
                                :comment
                            )
                        ");

                    $operationStmt->execute([
                        ':glass_id' =>
                            (int)
                            $glass['id'],

                        ':employee_id' =>
                            (int)
                            $user['id'],

                        ':route_step_id' =>
                            (int)
                            $glass[
                                'current_step_id'
                            ],

                        ':from_stage' =>
                            $glass[
                                'stage_name'
                            ],

                        ':to_stage' =>
                            $toStage,

                        ':comment' =>
                            'Масове завершення QR-кодом замовлення.',
                    ]);

                    /*
                     * history.
                     */

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
                                :new_status,
                                :old_location,
                                :new_location,
                                :comment
                            )
                        ");

                    $historyStmt->execute([
                        ':glass_id' =>
                            (int)
                            $glass['id'],

                        ':employee_id' =>
                            (int)
                            $user['id'],

                        ':old_status' =>
                            $glass['status'],

                        ':new_status' =>
                            $newStatus,

                        ':old_location' =>
                            $glass[
                                'current_location'
                            ],

                        ':new_location' =>
                            $newLocation,

                        ':comment' =>
                            'Замовлення №'
                            . $order[
                                'order_number'
                            ]
                            . ': масове завершення.',
                    ]);

                    /*
                     * glasses.
                     */

                    $updateStmt =
                        $db->prepare("
                            UPDATE glasses
                            SET
                                status =
                                    :status,

                                current_step_id =
                                    :current_step_id,

                                current_location =
                                    :current_location,

                                employee_id =
                                    NULL,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id =
                                :id
                        ");

                    $updateStmt->execute([
                        ':status' =>
                            $newStatus,

                        ':current_step_id' =>
                            $newStepId,

                        ':current_location' =>
                            $newLocation,

                        ':id' =>
                            (int)
                            $glass['id'],
                    ]);

                    /*
                     * Сповіщення наступній дільниці.
                     */

                    if ($nextStep) {

                        $nextStageStmt =
                            $db->prepare("
                                SELECT id
                                FROM production_stages
                                WHERE name =
                                    :name
                                  AND active = 1
                                LIMIT 1
                            ");

                        $nextStageStmt->execute([
                            ':name' =>
                                $nextStep[
                                    'name'
                                ],
                        ]);

                        $nextStageId =
                            $nextStageStmt
                                ->fetchColumn();

                        if (
                            $nextStageId !== false
                        ) {

                            notifyStage(
                                $db,
                                (int)
                                $nextStageId,
                                'glass_moved',
                                'Нове скло надійшло на дільницю',
                                'Скло '
                                . $glass['code']
                                . ' із замовлення '
                                . $order[
                                    'order_number'
                                ]
                                . ' надійшло на дільницю «'
                                . $nextStep[
                                    'name'
                                ]
                                . '».',
                                'glass',
                                (int)
                                $glass['id']
                            );
                        }
                    }

                    $completedIds[] =
                        (int)
                        $glass['id'];

                    $completedCodes[] =
                        $glass['code'];

                    $completedArea +=
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
                                $glass[
                                    'quantity'
                                ]
                            )
                        )
                        / 1000000;
                }

                if (
                    !$completedIds
                ) {

                    throw new RuntimeException(
                        'На цій дільниці немає доступного скла для завершення.'
                    );
                }

                /*
                 * Один загальний audit.
                 */

                writeAudit(
                    $db,
                    (int)
                    $user['id'],
                    'complete_order_stage',
                    'order',
                    $orderId,
                    null,
                    [
                        'order_number' =>
                            $order[
                                'order_number'
                            ],

                        'stage_id' =>
                            $stageId,

                        'stage' =>
                            $currentStage[
                                'name'
                            ],

                        'completed_count' =>
                            count(
                                $completedIds
                            ),

                        'completed_area_m2' =>
                            round(
                                $completedArea,
                                3
                            ),

                        'glass_ids' =>
                            $completedIds,

                        'glass_codes' =>
                            $completedCodes,

                        'completed_by_user_id' =>
                            (int)
                            $user['id'],
                    ]
                );

                /*
                 * Перевірка завершення замовлення.
                 */

                $progressStmt =
                    $db->prepare("
                        SELECT
                            COUNT(*) AS total,

                            SUM(
                                CASE
                                    WHEN status =
                                        'completed'
                                    THEN 1
                                    ELSE 0
                                END
                            ) AS completed

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

                if (
                    (int)
                    ($progress['total'] ?? 0)
                    > 0
                    &&
                    (int)
                    ($progress['total'] ?? 0)
                    ===
                    (int)
                    ($progress['completed'] ?? 0)
                ) {

                    $orderUpdate =
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
                        ");

                    $orderUpdate->execute([
                        ':id' =>
                            $orderId,
                    ]);

                    writeAudit(
                        $db,
                        (int)
                        $user['id'],
                        'order_completed',
                        'order',
                        $orderId,
                        null,
                        [
                            'status' =>
                                'completed',

                            'total_glasses' =>
                                (int)
                                $progress['total'],
                        ]
                    );
                }

                $db->commit();

                /*
                 * Telegram.
                 */

                try {

                    $telegramMessage =
                        "✅ OPTIMA GLASS\n\n"
                        . "Замовлення №"
                        . $order[
                            'order_number'
                        ]
                        . "\n"
                        . "Працівник: "
                        . (
                            $user[
                                'name'
                            ]
                            ?? ''
                        )
                        . "\n"
                        . "Дільниця: "
                        . stageLabel(
                            $currentStage[
                                'name'
                            ]
                        )
                        . "\n"
                        . "Завершено стекол: "
                        . count(
                            $completedIds
                        )
                        . "\n"
                        . "Площа: "
                        . number_format(
                            $completedArea,
                            2,
                            '.',
                            ''
                        )
                        . " м²";

                    $telegramResult =
                        sendTelegramToGroup(
                            $db,
                            $telegramMessage
                        );

                    writeAudit(
                        $db,
                        (int)
                        $user['id'],
                        'telegram_notification',
                        'order',
                        $orderId,
                        null,
                        [
                            'event' =>
                                'order_stage_completed',

                            'sent' =>
                                $telegramResult[
                                    'sent'
                                ]
                                ?? false,

                            'group' =>
                                $telegramResult[
                                    'group_title'
                                ]
                                ?? null,

                            'error' =>
                                $telegramResult[
                                    'error'
                                ]
                                ?? null,
                        ]
                    );

                } catch (
                    Throwable
                    $telegramException
                ) {
                }

                $scanType =
                    'success';

                $scanTitle =
                    '✅ ЗАМОВЛЕННЯ ОБРОБЛЕНО';

                $scanMessage =
                    'Завершено стекол: '
                    . count(
                        $completedIds
                    )
                    . '. Площа: '
                    . number_format(
                        $completedArea,
                        2,
                        ',',
                        ' '
                    )
                    . ' м².';

            } catch (
                Throwable
                $exception
            ) {

                if (
                    $db->inTransaction()
                ) {
                    $db->rollBack();
                }

                $scanType =
                    'error';

                $scanTitle =
                    '❌ ОПЕРАЦІЮ НЕ ВИКОНАНО';

                $scanMessage =
                    e(
                        $exception
                            ->getMessage()
                    );
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Черга дільниці
|--------------------------------------------------------------------------
*/

$queueStmt =
    $db->prepare("
        SELECT
            g.id AS glass_id,
            g.code,
            g.order_number,
            g.glass_type,
            g.thickness,
            g.width,
            g.height,
            g.quantity,
            g.status,

            o.id AS order_id,
            o.customer_name,
            o.priority,
            o.planned_date

        FROM glasses g

        JOIN orders o
            ON o.id =
                g.order_id

        JOIN route_steps rs
            ON rs.id =
                g.current_step_id

        JOIN production_stages ps
            ON ps.name =
                rs.name

        WHERE ps.id =
            :stage_id

          AND o.status =
            'in_production'

          AND g.status =
            'waiting'

          AND NOT EXISTS (
              SELECT 1
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
          )

        ORDER BY
            o.priority DESC,

            CASE
                WHEN o.planned_date IS NULL
                THEN 1
                ELSE 0
            END,

            o.planned_date ASC,

            g.created_at ASC,

            g.id ASC
    ");

$queueStmt->execute([
    ':stage_id' =>
        $stageId,
]);

$queue =
    $queueStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Мої активні партії
|--------------------------------------------------------------------------
*/

$batchStmt =
    $db->prepare("
        SELECT
            pb.id,
            pb.status,
            pb.created_at,
            pb.started_at,

            o.order_number,
            o.customer_name,
            o.priority,
            o.planned_date,

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

        JOIN orders o
            ON o.id =
                pb.order_id

        JOIN production_batch_items pbi
            ON pbi.batch_id =
                pb.id

        WHERE pb.assigned_employee_id =
            :employee_id

          AND pb.status IN (
              'created',
              'in_progress'
          )

        GROUP BY
            pb.id,
            o.id

        ORDER BY
            o.priority DESC,
            o.planned_date ASC,
            pb.created_at ASC
    ");

$batchStmt->execute([
    ':employee_id' =>
        (int) $user['id'],
]);

$batches =
    $batchStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Площа черги
|--------------------------------------------------------------------------
*/

$queueArea = 0.0;

foreach (
    $queue
    as $item
) {

    $queueArea +=
        (
            (int)
            $item['width']
            *
            (int)
            $item['height']
            *
            max(
                1,
                (int)
                $item['quantity']
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
        Моя робота — OPTIMA GLASS
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

        .work-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .work-header {
            margin-bottom: 25px;
        }

        .work-header h1 {
            margin-bottom: 6px;
        }

        .muted {
            color: #6b7280;
        }

        .card {
            margin-bottom: 22px;
            padding: 24px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .scan-description {
            color: #6b7280;
            margin-bottom: 18px;
            line-height: 1.5;
        }

        .scan-form {
            display: flex;
            gap: 10px;
        }

        .scan-input {
            flex: 1;
            min-height: 56px;
            padding: 0 15px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            font-size: 18px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 16px;
            border: 0;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font-weight: 700;
        }

        .button-secondary {
            background: #f3f4f6;
            color: #111827;
        }

        .button-danger {
            background: #b91c1c;
        }

        .scan-result {
            margin-bottom: 20px;
            padding: 18px;
            border-radius: 10px;
        }

        .scan-result.success {
            background: #dcfce7;
            color: #166534;
        }

        .scan-result.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .scan-result.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .scan-result.order_preview {
            background: #eff6ff;
            color: #1e3a8a;
        }

        .scan-result-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .order-preview-grid {
            display: grid;
            grid-template-columns:
                repeat(4, 1fr);
            gap: 10px;
            margin-top: 18px;
        }

        .preview-box {
            padding: 13px;
            border-radius: 9px;
            background: rgba(255,255,255,.65);
        }

        .preview-value {
            display: block;
            margin-top: 4px;
            font-size: 21px;
            font-weight: 700;
        }

        .summary {
            display: grid;
            grid-template-columns:
                repeat(3, 1fr);
            gap: 12px;
        }

        .summary-box {
            padding: 16px;
            border-radius: 10px;
            background: #f9fafb;
        }

        .summary-value {
            display: block;
            margin-top: 5px;
            font-size: 24px;
            font-weight: 700;
        }

        .batch-list {
            display: grid;
            gap: 10px;
        }

        .batch-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }

        .batch-title {
            font-weight: 700;
        }

        .batch-meta {
            margin-top: 5px;
            color: #6b7280;
            font-size: 13px;
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
            vertical-align: top;
        }

        .empty {
            padding: 25px 10px;
            text-align: center;
            color: #6b7280;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        @media (
            max-width: 750px
        ) {

            .scan-form {
                flex-direction: column;
            }

            .summary,
            .order-preview-grid {
                grid-template-columns: 1fr 1fr;
            }

            .batch-item {
                flex-direction: column;
                align-items: stretch;
            }

        }

        @media (
            max-width: 500px
        ) {

            .summary,
            .order-preview-grid {
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

<main class="work-page">

    <header class="work-header">

        <h1>
            Моя робота
        </h1>

        <div class="muted">

            <?= e(
                $user['name']
                ?? ''
            ) ?>

            ·

            <?= e(
                stageLabel(
                    $currentStage[
                        'name'
                    ]
                )
            ) ?>

        </div>

    </header>


    <section class="card">

        <h2>
            Сканування
        </h2>

        <div class="scan-description">

            Відскануйте QR-код конкретного скла
            або службовий QR замовлення.

            <br>

            QR замовлення має формат:
            <strong>ORDER-номер</strong>.

        </div>


        <?php if (
            $scanType !== ''
        ): ?>

            <div
                class="scan-result <?= e(
                    $scanType
                ) ?>"
            >

                <div class="scan-result-title">

                    <?= e(
                        $scanTitle
                    ) ?>

                </div>

                <div>
                    <?= $scanMessage ?>
                </div>


                <?php if (
                    $scanType ===
                    'order_preview'
                    &&
                    $orderQrPreview
                ): ?>

                    <div
                        style="margin-top:12px;"
                    >

                        Клієнт:
                        <strong>
                            <?= e(
                                $orderQrPreview[
                                    'customer_name'
                                ]
                                ?? '—'
                            ) ?>
                        </strong>

                        <br>

                        Дільниця:
                        <strong>
                            <?= e(
                                stageLabel(
                                    $currentStage[
                                        'name'
                                    ]
                                )
                            ) ?>
                        </strong>

                        <br>

                        Пріоритет:
                        <strong>
                            <?= e(
                                priorityLabel(
                                    (int)
                                    $orderQrPreview[
                                        'priority'
                                    ]
                                )
                            ) ?>
                        </strong>

                    </div>


                    <div class="order-preview-grid">

                        <div class="preview-box">

                            Доступно

                            <span class="preview-value">
                                <?= count(
                                    $orderQrGlasses
                                ) ?>
                            </span>

                        </div>

                        <div class="preview-box">

                            Площа

                            <span class="preview-value">

                                <?= number_format(
                                    $orderQrArea,
                                    2,
                                    ',',
                                    ' '
                                ) ?>

                                м²

                            </span>

                        </div>

                        <div class="preview-box">

                            У партіях

                            <span class="preview-value">
                                <?= $orderQrBatchCount ?>
                            </span>

                        </div>

                        <div class="preview-box">

                            Брак

                            <span class="preview-value">
                                <?= $orderQrRejectedCount ?>
                            </span>

                        </div>

                    </div>


                    <?php if (
                        $orderQrGlasses
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
                                value="complete_order_stage"
                            >

                            <input
                                type="hidden"
                                name="order_id"
                                value="<?= (int)
                                    $orderQrPreview[
                                        'id'
                                    ] ?>"
                            >

                            <button
                                type="submit"
                                class="button"
                                onclick="return confirm('Завершити всі доступні стекла цього замовлення на поточній дільниці?');"
                            >

                                ✅ Завершити
                                <?= count(
                                    $orderQrGlasses
                                ) ?>
                                стекол

                            </button>

                            <a
                                href="/work.php"
                                class="button button-secondary"
                            >
                                Скасувати
                            </a>

                        </form>

                    <?php else: ?>

                        <div
                            style="margin-top:15px;"
                        >

                            На цій дільниці немає
                            доступного скла для масового завершення.

                        </div>

                    <?php endif; ?>

                <?php elseif (
                    $scanType ===
                    'success'
                    &&
                    $scannedCode !== ''
                    &&
                    !str_starts_with(
                        strtoupper(
                            $scannedCode
                        ),
                        'ORDER-'
                    )
                ): ?>

                    <div class="actions">

                        <?php if (
                            $canReject
                        ): ?>

                            <a
                                href="/reject_glass.php?code=<?= urlencode(
                                    $scannedCode
                                ) ?>"
                                class="button button-danger"
                            >
                                ❌ Оформити брак
                            </a>

                        <?php endif; ?>

                        <a
                            href="/work.php"
                            class="button button-secondary"
                        >
                            Готово
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>


        <?php if (
            $canScan
        ): ?>

            <form
                method="post"
                class="scan-form"
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
                    value="scan_glass"
                >

                <input
                    type="text"
                    name="code"
                    class="scan-input"
                    placeholder="QR скла або ORDER-номер"
                    autocomplete="off"
                    autofocus
                    required
                >

                <button
                    type="submit"
                    class="button"
                >
                    Сканувати
                </button>

            </form>

        <?php endif; ?>

    </section>


    <section class="card">

        <div class="summary">

            <div class="summary-box">

                Скло в черзі

                <span class="summary-value">
                    <?= count($queue) ?>
                </span>

            </div>

            <div class="summary-box">

                Площа черги

                <span class="summary-value">

                    <?= number_format(
                        $queueArea,
                        2,
                        ',',
                        ' '
                    ) ?>

                    м²

                </span>

            </div>

            <div class="summary-box">

                Мої активні партії

                <span class="summary-value">
                    <?= count(
                        $batches
                    ) ?>
                </span>

            </div>

        </div>

    </section>


    <section class="card">

        <h2>
            Мої партії
        </h2>

        <?php if (
            !$batches
        ): ?>

            <div class="empty">

                Вам не призначено активних партій.

            </div>

        <?php else: ?>

            <div class="batch-list">

                <?php foreach (
                    $batches
                    as $batch
                ): ?>

                    <?php

                    $total =
                        (int)
                        $batch[
                            'total_items'
                        ];

                    $completed =
                        (int)
                        $batch[
                            'completed_items'
                        ];

                    $rejected =
                        (int)
                        $batch[
                            'rejected_items'
                        ];

                    $remaining =
                        max(
                            0,
                            $total
                            -
                            $completed
                            -
                            $rejected
                        );

                    ?>

                    <div class="batch-item">

                        <div>

                            <div class="batch-title">

                                Партія №
                                <?= (int)
                                    $batch['id'] ?>

                                ·

                                <?= e(
                                    $batch[
                                        'order_number'
                                    ]
                                ) ?>

                                ·

                                <?= e(
                                    priorityLabel(
                                        (int)
                                        $batch[
                                            'priority'
                                        ]
                                    )
                                ) ?>

                            </div>

                            <div class="batch-meta">

                                Всього:
                                <?= $total ?>

                                · Готово:
                                <?= $completed ?>

                                · Брак:
                                <?= $rejected ?>

                                · Залишилось:
                                <?= $remaining ?>

                            </div>

                        </div>

                        <a
                            href="/batch.php?id=<?= (int)
                                $batch[
                                    'id'
                                ] ?>"
                            class="button button-secondary"
                        >
                            Відкрити партію
                        </a>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <section class="card">

        <h2>

            Черга —
            <?= e(
                stageLabel(
                    $currentStage[
                        'name'
                    ]
                )
            ) ?>

        </h2>

        <?php if (
            !$queue
        ): ?>

            <div class="empty">

                На дільниці немає доступного скла.

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>#</th>
                            <th>Скло</th>
                            <th>Замовлення</th>
                            <th>Пріоритет</th>
                            <th>Розмір</th>
                            <th>Товщина</th>
                            <th>Площа</th>

                            <?php if (
                                $canReject
                            ): ?>

                                <th>Дія</th>

                            <?php endif; ?>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach (
                        $queue
                        as $index =>
                            $item
                    ): ?>

                        <tr>

                            <td>
                                <?= $index + 1 ?>
                            </td>

                            <td>

                                <strong>
                                    <?= e(
                                        $item[
                                            'code'
                                        ]
                                    ) ?>
                                </strong>

                                <?php if (
                                    !empty(
                                        $item[
                                            'glass_type'
                                        ]
                                    )
                                ): ?>

                                    <br>

                                    <small>
                                        <?= e(
                                            $item[
                                                'glass_type'
                                            ]
                                        ) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?= e(
                                    $item[
                                        'order_number'
                                    ]
                                ) ?>

                                <?php if (
                                    !empty(
                                        $item[
                                            'customer_name'
                                        ]
                                    )
                                ): ?>

                                    <br>

                                    <small>
                                        <?= e(
                                            $item[
                                                'customer_name'
                                            ]
                                        ) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?= e(
                                    priorityLabel(
                                        (int)
                                        $item[
                                            'priority'
                                        ]
                                    )
                                ) ?>

                            </td>

                            <td>

                                <?= (int)
                                    $item[
                                        'width'
                                    ] ?>

                                ×

                                <?= (int)
                                    $item[
                                        'height'
                                    ] ?>

                                мм

                            </td>

                            <td>

                                <?= $item[
                                    'thickness'
                                ] !== null
                                    ? e(
                                        (string)
                                        $item[
                                            'thickness'
                                        ]
                                    ) . ' мм'
                                    : '—'
                                ?>

                            </td>

                            <td>

                                <?= number_format(
                                    (
                                        (int)
                                        $item[
                                            'width'
                                        ]
                                        *
                                        (int)
                                        $item[
                                            'height'
                                        ]
                                        *
                                        max(
                                            1,
                                            (int)
                                            $item[
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

                            <?php if (
                                $canReject
                            ): ?>

                                <td>

                                    <a
                                        href="/reject_glass.php?code=<?= urlencode(
                                            $item[
                                                'code'
                                            ]
                                        ) ?>"
                                        class="button button-danger"
                                    >
                                        ❌ Брак
                                    </a>

                                </td>

                            <?php endif; ?>

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
