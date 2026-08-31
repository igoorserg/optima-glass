<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/notifications.php';
require __DIR__ . '/../src/telegram.php';

$user = require_user();

/*
|--------------------------------------------------------------------------
| Helpers
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

function audit(
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
            $_SERVER['REMOTE_ADDR'] ?? null,

        ':user_agent' =>
            $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

/*
|--------------------------------------------------------------------------
| Доступ только сотруднику / начальнику участка
|--------------------------------------------------------------------------
*/

if (!in_array(
    $user['role'],
    [
        'employee',
        'section_manager',
    ],
    true
)) {
    http_response_code(403);
    exit('Доступ запрещён.');
}

/*
|--------------------------------------------------------------------------
| Участок пользователя
|--------------------------------------------------------------------------
*/

$stageId = current_stage_id($user);

if ($stageId === null) {
    http_response_code(403);
    exit(
        'У пользователя не назначен производственный участок.'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_reject'])) {
    $_SESSION['csrf_reject'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken =
    $_SESSION['csrf_reject'];

/*
|--------------------------------------------------------------------------
| Переменные формы
|--------------------------------------------------------------------------
*/

$code = trim(
    $_POST['code']
    ?? $_GET['code']
    ?? ''
);

$reason =
    trim(
        $_POST['reason'] ?? ''
    );

$comment =
    trim(
        $_POST['comment'] ?? ''
    );

$error = '';

/*
|--------------------------------------------------------------------------
| Причины брака
|--------------------------------------------------------------------------
*/

$rejectionReasons = [
    'Трещина',
    'Скол',
    'Царапина',
    'Размер не соответствует',
    'Повреждение поверхности',
    'Бой стекла',
    'Другая причина',
];

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
     * CSRF
     */

    if (!hash_equals(
        $csrfToken,
        $_POST['csrf_token'] ?? ''
    )) {

        http_response_code(403);

        exit(
            'Ошибка проверки безопасности.'
        );
    }

    if ($code === '') {

        $error =
            'QR-код стекла не указан.';

    } elseif (
        !in_array(
            $reason,
            $rejectionReasons,
            true
        )
    ) {

        $error =
            'Выберите причину брака.';

    } else {

        /*
         * Ищем стекло.
         */

        $stmt = $db->prepare("
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

                rs.name AS stage_name,

                ps.id AS production_stage_id,
                ps.name AS production_stage_name

            FROM glasses g

            JOIN route_steps rs
                ON rs.id = g.current_step_id

            JOIN production_stages ps
                ON ps.name = rs.name

            LEFT JOIN orders o
                ON o.id = g.order_id

            WHERE g.code = :code

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

        if (!$glass) {

            $error =
                'Стекло «'
                . $code
                . '» не найдено.';

            audit(
                $db,
                (int) $user['id'],
                'reject_glass_not_found',
                null,
                null,
                null,
                [
                    'code' =>
                        $code,
                ]
            );

        } elseif (
            (int) $glass[
                'production_stage_id'
            ] !== $stageId
        ) {

            $error =
                'Стекло находится на участке «'
                . $glass[
                    'production_stage_name'
                ]
                . '», а ваш участок — «'
                . (
                    $user['stage_name']
                    ?? 'не указан'
                )
                . '».';

            audit(
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
                ]
            );

        } elseif (
            $glass['order_status']
            !== 'in_production'
        ) {

            $error =
                'Заказ сейчас не находится в производстве.';

            audit(
                $db,
                (int) $user['id'],
                'reject_glass_denied',
                'glass',
                (int) $glass['id'],
                null,
                [
                    'reason' =>
                        'order_not_in_production',

                    'code' =>
                        $glass['code'],
                ]
            );

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

            $error =
                'Стекло нельзя оформить как брак из текущего статуса «'
                . $glass['status']
                . '».';

            audit(
                $db,
                (int) $user['id'],
                'reject_glass_denied',
                'glass',
                (int) $glass['id'],
                null,
                [
                    'reason' =>
                        'invalid_status',

                    'code' =>
                        $glass['code'],

                    'status' =>
                        $glass['status'],
                ]
            );

        } else {

            /*
             * Проверяем активную партию.
             */

            $batchStmt =
                $db->prepare("
                    SELECT
                        pb.id
                    FROM production_batch_items pbi
                    JOIN production_batches pb
                        ON pb.id = pbi.batch_id
                    WHERE pbi.glass_id = :glass_id
                      AND pb.status IN (
                          'created',
                          'in_progress'
                      )
                    LIMIT 1
                ");

            $batchStmt->execute([
                ':glass_id' =>
                    (int) $glass['id'],
            ]);

            $activeBatchId =
                $batchStmt->fetchColumn();

            if (
                $activeBatchId !== false
            ) {

                $error =
                    'Стекло находится в активной партии №'
                    . $activeBatchId
                    . '. '
                    . 'Брак по партии оформим через страницу партии.';

                audit(
                    $db,
                    (int) $user['id'],
                    'reject_glass_denied',
                    'glass',
                    (int) $glass['id'],
                    null,
                    [
                        'reason' =>
                            'active_batch',

                        'code' =>
                            $glass['code'],

                        'batch_id' =>
                            (int) $activeBatchId,
                    ]
                );

            } else {

                /*
                 * ==========================================================
                 * Транзакция
                 * ==========================================================
                 */

                try {

                    $db->beginTransaction();

                    /*
                     * Повторно читаем стекло.
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
                            (int) $glass['id'],
                    ]);

                    $currentGlass =
                        $currentStmt->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if (!$currentGlass) {

                        throw new RuntimeException(
                            'Стекло больше не найдено.'
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
                            'Заказ больше не находится в производстве.'
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
                            'Стекло уже обработано другим пользователем.'
                        );
                    }

                    /*
                     * Проверяем участок.
                     */

                    $currentStageStmt =
                        $db->prepare("
                            SELECT
                                ps.id,
                                ps.name
                            FROM route_steps rs
                            JOIN production_stages ps
                                ON ps.name = rs.name
                            WHERE rs.id =
                                :route_step_id
                            LIMIT 1
                        ");

                    $currentStageStmt->execute([
                        ':route_step_id' =>
                            (int) $currentGlass[
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
                        !== $stageId
                    ) {

                        throw new RuntimeException(
                            'Стекло уже находится на другом участке.'
                        );
                    }

                    /*
                     * Проверяем активную партию ещё раз.
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
                            (int) $currentGlass['id'],
                    ]);

                    if (
                        $activeBatchStmt
                            ->fetchColumn()
                        !== false
                    ) {

                        throw new RuntimeException(
                            'Стекло находится в активной партии.'
                        );
                    }

                    /*
                     * Текст причины.
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
                     * ------------------------------------------------------
                     * glass_operations
                     * ------------------------------------------------------
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
                            (int) $currentGlass['id'],

                        ':employee_id' =>
                            (int) $user['id'],

                        ':route_step_id' =>
                            (int) $currentGlass[
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
                     * ------------------------------------------------------
                     * glass_history
                     * ------------------------------------------------------
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
                            (int) $currentGlass['id'],

                        ':employee_id' =>
                            (int) $user['id'],

                        ':old_status' =>
                            $currentGlass['status'],

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
                     * ------------------------------------------------------
                     * glasses
                     * ------------------------------------------------------
                     *
                     * current_step_id НЕ меняем.
                     * Это позволит позже вернуть стекло
                     * на переработку с того же этапа.
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
                            (int) $currentGlass['id'],
                    ]);

                    /*
                     * ------------------------------------------------------
                     * Внутренние уведомления руководству
                     * ------------------------------------------------------
                     */

                    $notificationIds =
                        notifyManagement(
                            $db,

                            'glass_rejected',

                            'Оформлен брак стекла',

                            'Стекло '
                            . $currentGlass['code']
                            . ' по заказу '
                            . $currentGlass['order_number']
                            . ' оформлено как брак на участке «'
                            . $currentGlass['stage_name']
                            . '». Причина: '
                            . $fullComment,

                            'glass',

                            (int) $currentGlass['id']
                        );

                    /*
                     * ------------------------------------------------------
                     * Audit
                     * ------------------------------------------------------
                     */

                    audit(
                        $db,
                        (int) $user['id'],
                        'reject_glass',
                        'glass',
                        (int) $currentGlass['id'],
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
                                (int) $user['id'],

                            'notification_ids' =>
                                $notificationIds,
                        ]
                    );

                    /*
                     * ------------------------------------------------------
                     * COMMIT
                     * ------------------------------------------------------
                     */

                    $db->commit();

                    /*
                     * ------------------------------------------------------
                     * Telegram — только после commit()
                     * ------------------------------------------------------
                     */

                    $telegramResult = [
                        'success' => false,
                        'sent' => false,
                    ];

                    try {

                        $telegramMessage =
                            formatTelegramGlassRejected(
                                $currentGlass['code'],
                                $currentGlass['order_number'],
                                $currentGlass['stage_name']
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
                     * Telegram audit.
                     */

                    try {

                        audit(
                            $db,
                            (int) $user['id'],
                            'telegram_notification',
                            'glass',
                            (int) $currentGlass['id'],
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
                        Throwable $auditException
                    ) {
                        // Аудит Telegram не должен ломать результат брака.
                    }

                    /*
                     * Результат.
                     */

                    $scanType =
                        'success';

                    $scanTitle =
                        '❌ БРАК ОФОРМЛЕН';

                    $scanMessage =
                        '<strong>'
                        . e(
                            $currentGlass[
                                'code'
                            ]
                        )
                        . '</strong><br>'
                        . 'Участок: '
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

                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {
                        $db->rollBack();
                    }

                    $scanType =
                        'error';

                    $scanTitle =
                        '❌ БРАК НЕ ОФОРМЛЕН';

                    $scanMessage =
                        e(
                            $exception->getMessage()
                        );

                    try {

                        audit(
                            $db,
                            (int) $user['id'],
                            'reject_glass_error',
                            'glass',
                            isset($glass['id'])
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
                        Throwable $auditException
                    ) {
                        // Ничего не делаем.
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| HTML
|--------------------------------------------------------------------------
*/

?>
<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Брак — OPTIMA GLASS
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 20px;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
        }

        .reject-page {
            max-width: 650px;
            margin: 0 auto;
        }

        .reject-card {
            padding: 30px;
            border-radius: 16px;
            background: #fff;
            border: 1px solid #e5e7eb;
        }

        h1 {
            margin-top: 0;
        }

        .subtitle {
            margin-bottom: 25px;
            color: #6b7280;
        }

        .message {
            margin-bottom: 20px;
            padding: 18px;
            border-radius: 12px;
            line-height: 1.5;
        }

        .message.success {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 700;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            font: inherit;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border: 0;
            border-radius: 9px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 700;
        }

        .button-danger {
            background: #b91c1c;
            color: #fff;
        }

        .button-secondary {
            background: #f3f4f6;
            color: #111827;
        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="reject-page">

    <section class="reject-card">

        <h1>
            Оформление брака
        </h1>

        <div class="subtitle">

            <?= e($user['name']) ?>

            ·

            <?= e(
                $user['stage_name']
                ?? 'Участок не указан'
            ) ?>

        </div>


        <?php if ($scanType !== ''): ?>

            <div
                class="message <?= e(
                    $scanType === 'success'
                        ? 'success'
                        : 'error'
                ) ?>"
            >

                <strong>
                    <?= e($scanTitle) ?>
                </strong>

                <br><br>

                <?= $scanMessage ?>

            </div>

        <?php endif; ?>


        <form
            method="post"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >


            <div class="form-group">

                <label for="code">
                    QR-код стекла
                </label>

                <input
                    id="code"
                    name="code"
                    value="<?= e($code) ?>"
                    placeholder="Отсканируйте QR"
                    autocomplete="off"
                    autofocus
                    required
                >

            </div>


            <div class="form-group">

                <label for="reason">
                    Причина брака
                </label>

                <select
                    id="reason"
                    name="reason"
                    required
                >

                    <option value="">
                        Выберите причину
                    </option>

                    <?php foreach (
                        $rejectionReasons
                        as $rejectionReason
                    ): ?>

                        <option
                            value="<?= e(
                                $rejectionReason
                            ) ?>"
                            <?= $reason === $rejectionReason
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
                    Дополнительный комментарий
                </label>

                <textarea
                    id="comment"
                    name="comment"
                    placeholder="Например: скол 20 мм по кромке"
                ><?= e($comment) ?></textarea>

            </div>


            <div class="buttons">

                <button
                    type="submit"
                    class="button button-danger"
                >
                    ❌ Оформить брак
                </button>

                <a
                    href="/work.php"
                    class="button button-secondary"
                >
                    Отмена
                </a>

            </div>

        </form>

    </section>

</main>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const input =
            document.getElementById(
                'code'
            );

        if (input) {
            input.focus();
        }

    }
);

</script>

</body>

</html>
