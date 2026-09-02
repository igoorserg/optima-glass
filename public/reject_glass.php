<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/notifications.php';
require __DIR__ . '/../src/telegram.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

/*
|--------------------------------------------------------------------------
| Доступ
|--------------------------------------------------------------------------
*/

require_permission(
    'glass.reject',
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
| Виробнича дільниця користувача
|--------------------------------------------------------------------------
*/

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
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION['csrf_reject']
    )
) {

    $_SESSION['csrf_reject'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['csrf_reject'];

/*
|--------------------------------------------------------------------------
| Дані форми
|--------------------------------------------------------------------------
*/

$code =
    trim(
        $_POST['code']
        ?? $_GET['code']
        ?? ''
    );

$reason =
    trim(
        $_POST['reason']
        ?? ''
    );

$comment =
    trim(
        $_POST['comment']
        ?? ''
    );

$messageType = '';
$messageTitle = '';
$messageText = '';

/*
|--------------------------------------------------------------------------
| Причини браку
|--------------------------------------------------------------------------
*/

$rejectionReasons = [
    'Тріщина',
    'Скол',
    'Подряпина',
    'Невідповідність розміру',
    'Пошкодження поверхні',
    'Бій скла',
    'Інша причина',
];

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
     * Повторна серверна перевірка права.
     */

    require_permission(
        'glass.reject',
        $user
    );

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

    /*
     * Валідація.
     */

    if ($code === '') {

        $messageType =
            'error';

        $messageTitle =
            'QR-код не вказано';

        $messageText =
            'Відскануйте або введіть QR-код скла.';

    } elseif (
        !in_array(
            $reason,
            $rejectionReasons,
            true
        )
    ) {

        $messageType =
            'error';

        $messageTitle =
            'Причину браку не вказано';

        $messageText =
            'Оберіть причину браку зі списку.';

    } else {

        /*
         * --------------------------------------------------------------
         * Пошук скла.
         * --------------------------------------------------------------
         */

        $stmt =
            $db->prepare("
                SELECT
                    g.id,
                    g.code,
                    g.order_id,
                    g.order_number,
                    g.status,
                    g.current_step_id,
                    g.current_location,
                    g.route_id,

                    o.status AS order_status,
                    o.priority,

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
         * Не знайдено.
         */

        if (!$glass) {

            $messageType =
                'error';

            $messageTitle =
                'Скло не знайдено';

            $messageText =
                'QR-код «'
                . e($code)
                . '» відсутній у системі.';

            writeAudit(
                $db,
                (int) $user['id'],
                'reject_glass_not_found',
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

            $messageType =
                'error';

            $messageTitle =
                'Брак не оформлено';

            $messageText =
                'Скло знаходиться на дільниці «'
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
                'reject_glass_denied',
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
         * Замовлення не у виробництві.
         */

        } elseif (
            $glass[
                'order_status'
            ]
            !==
            'in_production'
        ) {

            $messageType =
                'error';

            $messageTitle =
                'Брак не оформлено';

            $messageText =
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
                'reject_glass_denied',
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

            $messageType =
                'error';

            $messageTitle =
                'Брак не оформлено';

            $messageText =
                'Скло «'
                . e(
                    $glass['code']
                )
                . '» має статус «'
                . e(
                    $glass['status']
                )
                . '» і не може бути оформлене як брак.';

            writeAudit(
                $db,
                (int) $user['id'],
                'reject_glass_denied',
                'glass',
                (int) $glass['id'],
                null,
                [
                    'reason' =>
                        'invalid_status',

                    'status' =>
                        $glass['status'],
                ]
            );

        } else {

            /*
             * ----------------------------------------------------------
             * Активна партія.
             * ----------------------------------------------------------
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
                $batchStmt->fetchColumn();

            if (
                $activeBatchId !== false
            ) {

                $messageType =
                    'error';

                $messageTitle =
                    'Скло знаходиться у партії';

                $messageText =
                    'Скло «'
                    . e(
                        $glass['code']
                    )
                    . '» входить до активної партії №'
                    . (int)
                    $activeBatchId
                    . '. '
                    . 'Брак у партії потрібно оформлювати через сторінку партії.';

                writeAudit(
                    $db,
                    (int) $user['id'],
                    'reject_glass_denied',
                    'glass',
                    (int) $glass['id'],
                    null,
                    [
                        'reason' =>
                            'active_batch',

                        'batch_id' =>
                            (int)
                            $activeBatchId,
                    ]
                );

            } else {

                /*
                 * ======================================================
                 * Транзакція браку
                 * ======================================================
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
                                g.order_number,
                                g.status,
                                g.current_step_id,
                                g.current_location,

                                o.status AS order_status,

                                rs.name AS stage_name,

                                ps.id AS production_stage_id

                            FROM glasses g

                            JOIN route_steps rs
                                ON rs.id =
                                    g.current_step_id

                            JOIN production_stages ps
                                ON ps.name =
                                    rs.name

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

                    if (
                        !$currentGlass
                    ) {

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

                    if (
                        (int)
                        $currentGlass[
                            'production_stage_id'
                        ]
                        !==
                        $stageId
                    ) {

                        throw new RuntimeException(
                            'Скло вже знаходиться на іншій дільниці.'
                        );
                    }

                    /*
                     * Повторна перевірка партії.
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
                     * Причина + коментар.
                     */

                    $fullComment =
                        $reason;

                    if (
                        $comment !== ''
                    ) {

                        $fullComment .=
                            ': '
                            . $comment;
                    }

                    /*
                     * --------------------------------------------------
                     * glass_operations
                     * --------------------------------------------------
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
                                'rejection',
                                :from_stage,
                                NULL,
                                'rejected',
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

                        ':comment' =>
                            $fullComment,
                    ]);

                    /*
                     * --------------------------------------------------
                     * glass_history
                     * --------------------------------------------------
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
                                'rejected',
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

                        ':old_location' =>
                            $currentGlass[
                                'current_location'
                            ],

                        ':new_location' =>
                            'Брак — '
                            . $currentGlass[
                                'stage_name'
                            ],

                        ':comment' =>
                            $fullComment,
                    ]);

                    /*
                     * --------------------------------------------------
                     * glasses
                     * --------------------------------------------------
                     *
                     * current_step_id НЕ змінюємо.
                     * Це знадобиться для майбутньої переробки.
                     */

                    $glassUpdate =
                        $db->prepare("
                            UPDATE glasses
                            SET
                                status =
                                    'rejected',

                                current_location =
                                    :current_location,

                                employee_id =
                                    NULL,

                                comment =
                                    :comment,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id =
                                :id
                        ");

                    $glassUpdate->execute([
                        ':current_location' =>
                            'Брак — '
                            . $currentGlass[
                                'stage_name'
                            ],

                        ':comment' =>
                            $fullComment,

                        ':id' =>
                            (int)
                            $currentGlass['id'],
                    ]);

                    /*
                     * --------------------------------------------------
                     * Внутрішні сповіщення керівництву
                     * --------------------------------------------------
                     */

                    $notificationIds =
                        notifyManagement(
                            $db,
                            'glass_rejected',
                            'Оформлено брак скла',
                            'Скло '
                            . $currentGlass[
                                'code'
                            ]
                            . ' із замовлення '
                            . $currentGlass[
                                'order_number'
                            ]
                            . ' оформлено як брак на дільниці «'
                            . $currentGlass[
                                'stage_name'
                            ]
                            . '». Причина: '
                            . $fullComment,
                            'glass',
                            (int)
                            $currentGlass['id']
                        );

                    /*
                     * --------------------------------------------------
                     * Audit
                     * --------------------------------------------------
                     */

                    writeAudit(
                        $db,
                        (int)
                        $user['id'],
                        'reject_glass',
                        'glass',
                        (int)
                        $currentGlass['id'],
                        [
                            'status' =>
                                $currentGlass[
                                    'status'
                                ],

                            'current_location' =>
                                $currentGlass[
                                    'current_location'
                                ],
                        ],
                        [
                            'status' =>
                                'rejected',

                            'current_location' =>
                                'Брак — '
                                . $currentGlass[
                                    'stage_name'
                                ],

                            'reason' =>
                                $fullComment,

                            'employee_id' =>
                                (int)
                                $user['id'],

                            'notification_ids' =>
                                $notificationIds,
                        ]
                    );

                    /*
                     * --------------------------------------------------
                     * COMMIT
                     * --------------------------------------------------
                     */

                    $db->commit();

                    /*
                     * --------------------------------------------------
                     * Telegram після commit()
                     * --------------------------------------------------
                     */

                    $telegramResult = [
                        'success' =>
                            false,

                        'sent' =>
                            false,
                    ];

                    try {

                        $telegramMessage =
                            formatTelegramGlassRejected(
                                $currentGlass[
                                    'code'
                                ],
                                $currentGlass[
                                    'order_number'
                                ],
                                $currentGlass[
                                    'stage_name'
                                ]
                            );

                        $telegramMessage .=
                            "\nПричина: "
                            . $fullComment;

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
                     * Аудит Telegram.
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
                                'event' =>
                                    'glass_rejected',

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
                        // Аудит Telegram не впливає на оформлення браку.
                    }

                    /*
                     * --------------------------------------------------
                     * Результат
                     * --------------------------------------------------
                     */

                    $messageType =
                        'success';

                    $messageTitle =
                        '❌ БРАК ОФОРМЛЕНО';

                    $messageText =
                        '<strong>'
                        . e(
                            $currentGlass[
                                'code'
                            ]
                        )
                        . '</strong><br>'
                        . 'Дільниця: '
                        . e(
                            $currentGlass[
                                'stage_name'
                            ]
                        )
                        . '<br>'
                        . 'Причина: '
                        . e(
                            $fullComment
                        );

                    if (
                        $telegramResult[
                            'sent'
                        ]
                        ?? false
                    ) {

                        $messageText .=
                            '<br>'
                            . '📲 Повідомлення надіслано в Telegram.';
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

                    $messageType =
                        'error';

                    $messageTitle =
                        '❌ БРАК НЕ ОФОРМЛЕНО';

                    $messageText =
                        e(
                            $exception
                                ->getMessage()
                        );

                    try {

                        writeAudit(
                            $db,
                            (int)
                            $user['id'],
                            'reject_glass_error',
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

                                'reason' =>
                                    $reason,

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
        Оформлення браку — OPTIMA GLASS
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        .reject-page {
            max-width: 700px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .reject-card {
            padding: 28px;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #fff;
        }

        .reject-card h1 {
            margin-top: 0;
            margin-bottom: 8px;
        }

        .reject-meta {
            margin-bottom: 25px;
            color: #6b7280;
        }

        .message {
            margin-bottom: 22px;
            padding: 18px;
            border-radius: 12px;
            line-height: 1.55;
        }

        .message.success {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .message.error {
            background: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
        }

        .message-title {
            margin-bottom: 8px;
            font-size: 18px;
            font-weight: 700;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 700;
        }

        .form-control {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            font: inherit;
        }

        textarea.form-control {
            min-height: 110px;
            resize: vertical;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-height: 46px;
            padding: 0 18px;
            border: 0;
            border-radius: 9px;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }

        .button-danger {
            background: #b91c1c;
            color: #fff;
        }

        .button-secondary {
            background: #f3f4f6;
            color: #111827;
        }

        @media (
            max-width: 600px
        ) {

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

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="reject-page">

    <section class="reject-card">

        <h1>
            Оформлення браку
        </h1>

        <div class="reject-meta">

            <?= e(
                $user['name']
                ?? ''
            ) ?>

            ·

            <?= e(
                $user[
                    'stage_name'
                ]
                ?? 'Дільницю не вказано'
            ) ?>

        </div>


        <?php if (
            $messageType !== ''
        ): ?>

            <div
                class="message <?= e(
                    $messageType
                ) ?>"
            >

                <div class="message-title">

                    <?= e(
                        $messageTitle
                    ) ?>

                </div>

                <div>
                    <?= $messageText ?>
                </div>

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


            <div class="form-group">

                <label for="code">
                    QR-код скла
                </label>

                <input
                    type="text"
                    id="code"
                    name="code"
                    class="form-control"
                    value="<?= e($code) ?>"
                    placeholder="Відскануйте QR-код"
                    autocomplete="off"
                    autofocus
                    required
                >

            </div>


            <div class="form-group">

                <label for="reason">
                    Причина браку
                </label>

                <select
                    id="reason"
                    name="reason"
                    class="form-control"
                    required
                >

                    <option value="">
                        Оберіть причину
                    </option>

                    <?php foreach (
                        $rejectionReasons
                        as $rejectionReason
                    ): ?>

                        <option
                            value="<?= e(
                                $rejectionReason
                            ) ?>"
                            <?= $reason ===
                                $rejectionReason
                                    ? 'selected'
                                    : '' ?>
                        >
                            <?= e(
                                $rejectionReason
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="form-group">

                <label for="comment">
                    Додатковий коментар
                </label>

                <textarea
                    id="comment"
                    name="comment"
                    class="form-control"
                    placeholder="Наприклад: скол 20 мм по кромці"
                ><?= e($comment) ?></textarea>

            </div>


            <div class="actions">

                <button
                    type="submit"
                    class="button button-danger"
                >
                    ❌ Оформити брак
                </button>

                <a
                    href="/work.php"
                    class="button button-secondary"
                >
                    Повернутися до роботи
                </a>

            </div>

        </form>

    </section>

</main>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const codeInput =
            document.getElementById(
                'code'
            );

        if (codeInput) {
            codeInput.focus();
        }

    }
);

</script>

</body>

</html>
