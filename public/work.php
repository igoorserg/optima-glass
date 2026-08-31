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

/*
|--------------------------------------------------------------------------
| Доступ
|--------------------------------------------------------------------------
|
| Робочий екран вимагає право production.view.
| QR-операція додатково перевіряє glass.scan.
|
|--------------------------------------------------------------------------
*/

if (!can('production.view', $user)) {
    http_response_code(403);

    exit(
        'Доступ заборонено. Немає дозволу на перегляд виробництва.'
    );
}

$canScan = can(
    'glass.scan',
    $user
);

$canReject = can(
    'glass.reject',
    $user
);

$stageId = current_stage_id($user);

if ($stageId === null) {
    http_response_code(403);

    exit(
        'Користувачу не призначено виробничу дільницю.'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_work'])) {

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

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/

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
| QR-сканування
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    ($_POST['action'] ?? '') === 'scan_glass'
) {

    /*
     * Перевірка права.
     */

    if (!$canScan) {

        http_response_code(403);

        exit(
            'У вас немає дозволу на QR-сканування.'
        );
    }

    /*
     * CSRF.
     */

    if (!hash_equals(
        $csrfToken,
        $_POST['csrf_token'] ?? ''
    )) {

        $scanType =
            'error';

        $scanTitle =
            'Помилка безпеки';

        $scanMessage =
            'Перевірку запиту не пройдено.';

    } else {

        $code =
            trim(
                $_POST['code'] ?? ''
            );

        $scannedCode =
            $code;

        /*
         * Порожній код.
         */

        if ($code === '') {

            $scanType =
                'error';

            $scanTitle =
                'QR-код не вказано';

            $scanMessage =
                'Відскануйте QR-код скла.';

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

        } else {

            /*
             * ----------------------------------------------------------
             * Пошук скла
             * ----------------------------------------------------------
             */

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
                $glass['production_stage_id']
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
                        $glass[
                            'production_stage_name'
                        ]
                    )
                    . '», а ваша дільниця — «'
                    . e(
                        $user[
                            'stage_name'
                        ]
                        ?? 'не вказана'
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
                $glass['order_status']
                !==
                'in_production'
            ) {

                $scanType =
                    'warning';

                $scanTitle =
                    '⚠️ СКАНУВАННЯ НЕ ПРИЙНЯТО';

                $scanMessage =
                    'Замовлення «'
                    . e(
                        $glass[
                            'order_number'
                        ]
                    )
                    . '» зараз не перебуває у виробництві.';

                writeAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_denied',
                    'glass',
                    (int) $glass['id'],
                    null,
                    [
                        'reason' =>
                            'order_not_in_production',

                        'order_status' =>
                            $glass[
                                'order_status'
                            ],
                    ]
                );

            /*
             * Некоректний статус.
             */

            } elseif (
                !in_array(
                    $glass['status'],
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
                    'Скло «'
                    . e(
                        $glass[
                            'code'
                        ]
                    )
                    . '» має статус «'
                    . e(
                        $glass[
                            'status'
                        ]
                    )
                    . '» і не може бути оброблене.';

                writeAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_denied',
                    'glass',
                    (int) $glass['id'],
                    null,
                    [
                        'reason' =>
                            'invalid_status',

                        'status' =>
                            $glass[
                                'status'
                            ],
                    ]
                );

            } else {

                /*
                 * ------------------------------------------------------
                 * Перевіряємо активну партію.
                 * ------------------------------------------------------
                 */

                $batchStmt =
                    $db->prepare("
                        SELECT
                            pb.id,
                            pb.status

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

                $activeBatch =
                    $batchStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if ($activeBatch) {

                    $scanType =
                        'warning';

                    $scanTitle =
                        '⚠️ СКЛО ЗНАХОДИТЬСЯ У ПАРТІЇ';

                    $scanMessage =
                        'Скло «'
                        . e(
                            $glass['code']
                        )
                        . '» знаходиться в активній партії №'
                        . (int)
                        $activeBatch['id']
                        . '. '
                        . 'Завершіть його через сторінку партії.';

                    writeAudit(
                        $db,
                        (int) $user['id'],
                        'scan_glass_denied',
                        'glass',
                        (int) $glass['id'],
                        null,
                        [
                            'reason' =>
                                'active_batch',

                            'batch_id' =>
                                (int)
                                $activeBatch['id'],
                        ]
                    );

                } else {

                    /*
                     * ==================================================
                     * Єдина виробнича транзакція.
                     * ==================================================
                     */

                    try {

                        $db->beginTransaction();

                        /*
                         * Повторне читання скла.
                         */

                        $currentStmt =
                            $db->prepare("
                                SELECT
                                    g.id,
                                    g.code,
                                    g.order_id,
                                    g.status,

                                    g.width,
                                    g.height,
                                    g.quantity,

                                    g.current_step_id,
                                    g.current_location,
                                    g.route_id,

                                    o.status AS order_status,
                                    o.order_number,
                                    o.priority,

                                    rs.id AS route_step_id,
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

                        /*
                         * Повторна перевірка замовлення.
                         */

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

                        /*
                         * Повторна перевірка статусу.
                         */

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
                                'Скло вже оброблене іншим користувачем.'
                            );
                        }

                        /*
                         * Повторна перевірка дільниці.
                         */

                        $currentStageStmt =
                            $db->prepare("
                                SELECT
                                    ps.id,
                                    ps.name

                                FROM route_steps rs

                                JOIN production_stages ps
                                    ON ps.name =
                                        rs.name

                                WHERE rs.id =
                                    :route_step_id

                                LIMIT 1
                            ");

                        $currentStageStmt->execute([
                            ':route_step_id' =>
                                (int)
                                $currentGlass[
                                    'current_step_id'
                                ],
                        ]);

                        $currentStage =
                            $currentStageStmt->fetch(
                                PDO::FETCH_ASSOC
                            );

                        if (
                            !$currentStage
                            ||
                            (int)
                            $currentStage['id']
                            !==
                            $stageId
                        ) {

                            throw new RuntimeException(
                                'Скло вже знаходиться на іншій дільниці.'
                            );
                        }

                        /*
                         * Повторна перевірка активної партії.
                         */

                        $activeBatchStmt =
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

                        $activeBatchStmt->execute([
                            ':glass_id' =>
                                (int)
                                $currentGlass['id'],
                        ]);

                        if (
                            $activeBatchStmt
                                ->fetchColumn()
                            !== false
                        ) {

                            throw new RuntimeException(
                                'Скло знаходиться в активній партії.'
                            );
                        }

                        /*
                         * ------------------------------------------------
                         * Наступний етап маршруту.
                         * ------------------------------------------------
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

                        $nextStageId =
                            null;

                        /*
                         * Є наступний етап.
                         */

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
                                    SELECT
                                        id

                                    FROM production_stages

                                    WHERE name =
                                        :name

                                      AND active = 1

                                    LIMIT 1
                                ");

                            $nextStageStmt->execute([
                                ':name' =>
                                    $nextStep['name'],
                            ]);

                            $nextStageId =
                                $nextStageStmt
                                    ->fetchColumn();

                            if (
                                $nextStageId === false
                            ) {

                                throw new RuntimeException(
                                    'Наступну виробничу дільницю не знайдено.'
                                );
                            }

                            $nextStageId =
                                (int)
                                $nextStageId;

                        } else {

                            /*
                             * Останній етап.
                             */

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
                         * ------------------------------------------------
                         * 1. glass_operations
                         * ------------------------------------------------
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
                                    'Операцію завершено QR-скануванням.'
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
                        ]);

                        /*
                         * ------------------------------------------------
                         * 2. glass_history
                         * ------------------------------------------------
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
                                $nextStep
                                    ? 'QR: скло передано на наступну дільницю.'
                                    : 'QR: маршрут скла повністю завершено.',
                        ]);

                        /*
                         * ------------------------------------------------
                         * 3. glasses
                         * ------------------------------------------------
                         */

                        $glassUpdate =
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

                        $glassUpdate->execute([
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
                         * ------------------------------------------------
                         * 4. Внутрішні сповіщення
                         * ------------------------------------------------
                         */

                        $notificationIds =
                            [];

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
                                    . $currentGlass[
                                        'code'
                                    ]
                                    . ' із замовлення '
                                    . $currentGlass[
                                        'order_number'
                                    ]
                                    . ' надійшло на дільницю «'
                                    . $nextStep['name']
                                    . '». '
                                    . 'Попередня дільниця: «'
                                    . $currentGlass[
                                        'stage_name'
                                    ]
                                    . '». '
                                    . 'Пріоритет: '
                                    . (int)
                                    $currentGlass[
                                        'priority'
                                    ]
                                    . '.',
                                    'glass',
                                    (int)
                                    $currentGlass['id']
                                );
                        }

                        /*
                         * ------------------------------------------------
                         * 5. Аудит сканування
                         * ------------------------------------------------
                         */

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

                                'employee_id' =>
                                    (int)
                                    $user['id'],

                                'notification_ids' =>
                                    $notificationIds,
                            ]
                        );

                        /*
                         * ------------------------------------------------
                         * 6. Перевірка завершення замовлення
                         * ------------------------------------------------
                         */

                        $orderWasCompleted =
                            false;

                        if (
                            $newStatus ===
                            'completed'
                        ) {

                            $orderProgressStmt =
                                $db->prepare("
                                    SELECT
                                        COUNT(*)
                                            AS total_glasses,

                                        SUM(
                                            CASE
                                                WHEN status =
                                                    'completed'
                                                THEN 1
                                                ELSE 0
                                            END
                                        )
                                            AS completed_glasses

                                    FROM glasses

                                    WHERE order_id =
                                        :order_id
                                ");

                            $orderProgressStmt->execute([
                                ':order_id' =>
                                    (int)
                                    $currentGlass[
                                        'order_id'
                                    ],
                            ]);

                            $orderProgress =
                                $orderProgressStmt->fetch(
                                    PDO::FETCH_ASSOC
                                );

                            if (
                                (int) (
                                    $orderProgress[
                                        'total_glasses'
                                    ]
                                    ?? 0
                                ) > 0

                                &&

                                (int) (
                                    $orderProgress[
                                        'completed_glasses'
                                    ]
                                    ?? 0
                                )

                                ===

                                (int) (
                                    $orderProgress[
                                        'total_glasses'
                                    ]
                                    ?? 0
                                )
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

                                          AND status <>
                                            'completed'
                                    ");

                                $orderUpdate->execute([
                                    ':id' =>
                                        (int)
                                        $currentGlass[
                                            'order_id'
                                        ],
                                ]);

                                $orderWasCompleted =
                                    $orderUpdate->rowCount()
                                    > 0;

                                if (
                                    $orderWasCompleted
                                ) {

                                    writeAudit(
                                        $db,
                                        (int)
                                        $user['id'],
                                        'order_completed',
                                        'order',
                                        (int)
                                        $currentGlass[
                                            'order_id'
                                        ],
                                        null,
                                        [
                                            'status' =>
                                                'completed',

                                            'total_glasses' =>
                                                (int) (
                                                    $orderProgress[
                                                        'total_glasses'
                                                    ]
                                                    ?? 0
                                                ),
                                        ]
                                    );
                                }
                            }
                        }

                        /*
                         * ------------------------------------------------
                         * COMMIT
                         * ------------------------------------------------
                         */

                        $db->commit();

                        /*
                         * ------------------------------------------------
                         * Telegram
                         * ------------------------------------------------
                         *
                         * Надсилання відбувається після commit().
                         * Telegram не може скасувати виробничу операцію.
                         * ------------------------------------------------
                         */

                        $telegramResult = [
                            'success' =>
                                false,

                            'sent' =>
                                false,
                        ];

                        if (
                            $nextStep
                        ) {

                            try {

                                $width =
                                    isset(
                                        $currentGlass[
                                            'width'
                                        ]
                                    )
                                        ? (int)
                                        $currentGlass[
                                            'width'
                                        ]
                                        : null;

                                $height =
                                    isset(
                                        $currentGlass[
                                            'height'
                                        ]
                                    )
                                        ? (int)
                                        $currentGlass[
                                            'height'
                                        ]
                                        : null;

                                $quantity =
                                    isset(
                                        $currentGlass[
                                            'quantity'
                                        ]
                                    )
                                        ? max(
                                            1,
                                            (int)
                                            $currentGlass[
                                                'quantity'
                                            ]
                                        )
                                        : 1;

                                $areaM2 =
                                    null;

                                if (
                                    $width !== null
                                    &&
                                    $height !== null
                                ) {

                                    $areaM2 =
                                        (
                                            $width
                                            *
                                            $height
                                            *
                                            $quantity
                                        )
                                        / 1000000;
                                }

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

                                        $width,

                                        $height,

                                        $areaM2
                                    );

                                $telegramResult =
                                    sendTelegramToGroup(
                                        $db,
                                        $telegramMessage
                                    );

                            } catch (
                                Throwable
                                $telegramException
                            ) {

                                $telegramResult = [
                                    'success' =>
                                        false,

                                    'sent' =>
                                        false,

                                    'error' =>
                                        $telegramException
                                            ->getMessage(),
                                ];
                            }

                            /*
                             * Аудит доставки Telegram.
                             */

                            try {

                                writeAudit(
                                    $db,
                                    (int)
                                    $user['id'],
                                    'telegram_notification',
                                    'glass',
                                    (int)
                                    $currentGlass[
                                        'id'
                                    ],
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
                                $auditException
                            ) {
                                // Аудит Telegram не должен влиять на производство.
                            }
                        }

                        /*
                         * Результат.
                         */

                        $scanType =
                            'success';

                        $scanTitle =
                            '✅ СКАНУВАННЯ ПРИЙНЯТО';

                        if (
                            $orderWasCompleted
                        ) {

                            $scanMessage =
                                '<strong>'
                                . e(
                                    $currentGlass[
                                        'code'
                                    ]
                                )
                                . '</strong><br>'
                                . 'Замовлення повністю завершено.';

                        } elseif (
                            $nextStep
                        ) {

                            $scanMessage =
                                '<strong>'
                                . e(
                                    $currentGlass[
                                        'code'
                                    ]
                                )
                                . '</strong><br>'
                                . e(
                                    $currentGlass[
                                        'stage_name'
                                    ]
                                )
                                . ' → '
                                . e(
                                    $toStage
                                )
                                . '<br>'
                                . 'Скло передано на наступну дільницю.';

                            if (
                                (
                                    $telegramResult[
                                        'sent'
                                    ]
                                    ?? false
                                )
                            ) {

                                $scanMessage .=
                                    '<br>'
                                    . '📲 Повідомлення надіслано в Telegram.';
                            }

                        } else {

                            $scanMessage =
                                '<strong>'
                                . e(
                                    $currentGlass[
                                        'code'
                                    ]
                                )
                                . '</strong><br>'
                                . 'Маршрут скла повністю завершено.';
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
                                $exception->getMessage()
                            );

                        try {

                            writeAudit(
                                $db,
                                (int) $user['id'],
                                'scan_glass_error',
                                'glass',
                                isset(
                                    $glass['id']
                                )
                                    ? (int)
                                    $glass['id']
                                    : null,
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
                            // Помилка аудиту не змінює результат.
                        }
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Черга дільниці
|--------------------------------------------------------------------------
*/

$queue = [];

if (
    can(
        'production.view',
        $user
    )
) {

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
}

/*
|--------------------------------------------------------------------------
| Активні партії працівника
|--------------------------------------------------------------------------
*/

$batches = [];

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

foreach ($queue as $item) {

    $queueArea +=
        (
            (int) $item['width']
            *
            (int) $item['height']
            *
            (int) $item['quantity']
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

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>

        * {
            box-sizing: border-box;
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

        .work-meta {
            color: #6b7280;
        }

        .scan-card,
        .work-card {
            margin-bottom: 25px;
            padding: 25px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .scan-card h2,
        .work-card h2 {
            margin-top: 0;
        }

        .scan-description {
            margin-bottom: 20px;
            color: #6b7280;
            line-height: 1.5;
        }

        .scan-result {
            margin-bottom: 20px;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }

        .scan-result.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .scan-result.warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .scan-result.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .scan-result-title {
            margin-bottom: 8px;
            font-size: 21px;
            font-weight: 700;
        }

        .scan-result-message {
            line-height: 1.55;
        }

        .scan-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 18px;
            flex-wrap: wrap;
        }

        .action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 16px;
            border-radius: 9px;
            text-decoration: none;
            font-weight: 700;
        }

        .action-button-danger {
            background: #b91c1c;
            color: #fff;
        }

        .action-button-secondary {
            background: #f3f4f6;
            color: #111827;
        }

        .scan-form {
            display: flex;
            gap: 10px;
        }

        .scan-input {
            flex: 1;
            min-height: 56px;
            padding: 0 16px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 18px;
            outline: none;
        }

        .scan-input:focus {
            border-color: #111827;
        }

        .scan-submit {
            min-width: 160px;
            min-height: 56px;
            border: 0;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .scan-ready {
            margin-top: 14px;
            padding: 11px;
            border-radius: 9px;
            background: #f9fafb;
            color: #6b7280;
            text-align: center;
            font-size: 13px;
        }

        .work-summary {
            display: grid;
            grid-template-columns:
                repeat(
                    3,
                    minmax(0, 1fr)
                );

            gap: 14px;
            margin-bottom: 25px;
        }

        .summary-card {
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }

        .summary-value {
            display: block;
            margin-top: 6px;
            font-size: 25px;
            font-weight: 700;
        }

        .batch-list {
            display: grid;
            gap: 12px;
        }

        .batch-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 16px;
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

        .batch-open {
            padding: 9px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            white-space: nowrap;
        }

        .queue-table-wrap {
            overflow-x: auto;
        }

        .queue-table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        .queue-table th,
        .queue-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }

        .queue-table th {
            white-space: nowrap;
        }

        .glass-code {
            font-weight: 700;
        }

        .priority {
            font-weight: 600;
        }

        .empty {
            padding: 35px 15px;
            color: #6b7280;
            text-align: center;
        }

        @media (
            max-width: 700px
        ) {

            .scan-form {
                flex-direction: column;
            }

            .scan-submit {
                width: 100%;
            }

            .work-summary {
                grid-template-columns: 1fr;
            }

            .batch-item {
                flex-direction: column;
                align-items: stretch;
            }

            .batch-open {
                text-align: center;
            }

        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="work-page">

    <header class="work-header">

        <h1>
            Моя робота
        </h1>

        <div class="work-meta">

            <?= e(
                $user['name']
                ?? ''
            ) ?>

            ·

            <?= e(
                $user['stage_name']
                ?? 'Дільницю не вказано'
            ) ?>

        </div>

    </header>


    <section class="scan-card">

        <h2>
            Сканування
        </h2>

        <div class="scan-description">

            Відскануйте QR-код скла.
            Одне сканування завершує поточну операцію
            та автоматично передає скло на наступний етап.

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

                <div class="scan-result-message">

                    <?= $scanMessage ?>

                </div>


                <?php if (
                    $scanType === 'success'
                    &&
                    $scannedCode !== ''
                ): ?>

                    <div class="scan-actions">

                        <?php if (
                            $canReject
                        ): ?>

                            <a
                                href="/reject_glass.php?code=<?= urlencode(
                                    $scannedCode
                                ) ?>"
                                class="action-button action-button-danger"
                            >
                                ❌ Оформити брак
                            </a>

                        <?php endif; ?>

                        <a
                            href="/work.php"
                            class="action-button action-button-secondary"
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
                id="scanForm"
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
                    id="scanCode"
                    class="scan-input"
                    placeholder="Відскануйте QR-код"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    autofocus
                    required
                >

                <button
                    type="submit"
                    class="scan-submit"
                >
                    Сканувати
                </button>

            </form>


            <div class="scan-ready">

                Поле готове до наступного сканування.

            </div>

        <?php else: ?>

            <div class="empty">

                QR-сканування недоступне для вашого облікового запису.

            </div>

        <?php endif; ?>

    </section>


    <section class="work-summary">

        <div class="summary-card">

            <div>
                Скло в черзі
            </div>

            <span class="summary-value">

                <?= count($queue) ?>

            </span>

        </div>


        <div class="summary-card">

            <div>
                Площа черги
            </div>

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


        <div class="summary-card">

            <div>
                Мої активні партії
            </div>

            <span class="summary-value">

                <?= count($batches) ?>

            </span>

        </div>

    </section>


    <section class="work-card">

        <h2>
            Мої партії
        </h2>


        <?php if (
            !$batches
        ): ?>

            <div class="empty">

                Вам поки не призначено активних партій.

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

                                ·

                                Готово:
                                <?= $completed ?>

                                ·

                                Брак:
                                <?= $rejected ?>

                                ·

                                Залишилось:
                                <?= $remaining ?>

                            </div>

                        </div>


                        <a
                            href="/batch.php?id=<?= (int) $batch['id'] ?>"
                            class="batch-open"
                        >
                            Відкрити партію
                        </a>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <section class="work-card">

        <h2>

            Черга —
            <?= e(
                $user['stage_name']
                ?? ''
            ) ?>

        </h2>


        <?php if (
            !$queue
        ): ?>

            <div class="empty">

                На дільниці зараз немає доступних робіт.

            </div>

        <?php else: ?>

            <div class="queue-table-wrap">

                <table class="queue-table">

                    <thead>

                        <tr>

                            <th>
                                #
                            </th>

                            <th>
                                Скло
                            </th>

                            <th>
                                Замовлення
                            </th>

                            <th>
                                Пріоритет
                            </th>

                            <th>
                                Планова дата
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

                            <?php if (
                                $canReject
                            ): ?>

                                <th>
                                    Дія
                                </th>

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

                                <div class="glass-code">

                                    <?= e(
                                        $item['code']
                                    ) ?>

                                </div>

                                <?php if (
                                    !empty(
                                        $item[
                                            'glass_type'
                                        ]
                                    )
                                ): ?>

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

                                <strong>

                                    <?= e(
                                        $item[
                                            'order_number'
                                        ]
                                    ) ?>

                                </strong>

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


                            <td class="priority">

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

                                <?= $item[
                                    'planned_date'
                                ]
                                    ? e(
                                        $item[
                                            'planned_date'
                                        ]
                                    )
                                    : '—'
                                ?>

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

                                <?php if (
                                    $item[
                                        'thickness'
                                    ] !== null
                                ): ?>

                                    <?= e(
                                        (string)
                                        $item[
                                            'thickness'
                                        ]
                                    ) ?>

                                    мм

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>


                            <td>

                                <?= number_format(
                                    (
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
                                            (int)
                                            $item[
                                                'quantity'
                                            ]
                                        )
                                        / 1000000
                                    ),
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
                                            $item['code']
                                        ) ?>"
                                        class="batch-open"
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


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const input =
            document.getElementById(
                'scanCode'
            );

        const form =
            document.getElementById(
                'scanForm'
            );

        if (input) {
            input.focus();
        }

        /*
         * Після відправлення сторінки
         * поле знову готове до сканування.
         */

        if (
            input &&
            form
        ) {

            form.addEventListener(
                'submit',
                function () {

                    setTimeout(
                        function () {

                            input.value =
                                '';

                            input.focus();

                        },
                        100
                    );
                }
            );
        }

        /*
         * Автофокус після завантаження.
         */

        if (input) {

            setTimeout(
                function () {

                    input.focus();

                },
                150
            );
        }

        /*
         * Успішне сканування.
         */

        <?php if (
            $scanType === 'success'
        ): ?>

            try {

                const AudioContext =
                    window.AudioContext ||
                    window.webkitAudioContext;

                if (AudioContext) {

                    const audio =
                        new AudioContext();

                    const oscillator =
                        audio.createOscillator();

                    const gain =
                        audio.createGain();

                    oscillator.type =
                        'sine';

                    oscillator.frequency.value =
                        880;

                    gain.gain.value =
                        0.08;

                    oscillator.connect(
                        gain
                    );

                    gain.connect(
                        audio.destination
                    );

                    oscillator.start();

                    oscillator.stop(
                        audio.currentTime +
                        0.12
                    );
                }

            } catch (
                error
            ) {
                // Звук не впливає на виробництво.
            }

        <?php elseif (
            $scanType === 'warning'
            ||
            $scanType === 'error'
        ): ?>

            try {

                const AudioContext =
                    window.AudioContext ||
                    window.webkitAudioContext;

                if (AudioContext) {

                    const audio =
                        new AudioContext();

                    const oscillator =
                        audio.createOscillator();

                    const gain =
                        audio.createGain();

                    oscillator.type =
                        'square';

                    oscillator.frequency.value =
                        220;

                    gain.gain.value =
                        0.05;

                    oscillator.connect(
                        gain
                    );

                    gain.connect(
                        audio.destination
                    );

                    oscillator.start();

                    oscillator.stop(
                        audio.currentTime +
                        0.18
                    );
                }

            } catch (
                error
            ) {
                // Звук не впливає на виробництво.
            }

        <?php endif; ?>

    }
);

</script>

</body>

</html>
