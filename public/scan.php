<?php

require __DIR__ . '/../src/auth.php';

$user = require_user();

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function logAudit(
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
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_scan'])) {
    $_SESSION['csrf_scan'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_scan'];

/*
|--------------------------------------------------------------------------
| Результат операции
|--------------------------------------------------------------------------
*/

$flashType = '';
$flashTitle = '';
$flashMessage = '';

$scannedCode = '';

/*
|--------------------------------------------------------------------------
| POST — QR сканирование
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $scannedCode = trim(
        $_POST['code'] ?? ''
    );

    /*
     * CSRF
     */
    if (!hash_equals(
        $csrfToken,
        $_POST['csrf_token'] ?? ''
    )) {

        $flashType = 'error';
        $flashTitle = 'Ошибка безопасности';
        $flashMessage = 'Проверка запроса не пройдена.';

    /*
     * Пустой QR
     */
    } elseif ($scannedCode === '') {

        $flashType = 'error';
        $flashTitle = 'QR-код не указан';
        $flashMessage =
            'Отсканируйте QR-код стекла.';

        logAudit(
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
         * У пользователя должен быть участок.
         */
        $stageId = current_stage_id($user);

        if ($stageId === null) {

            http_response_code(403);

            $flashType = 'error';
            $flashTitle = 'Участок не назначен';
            $flashMessage =
                'У пользователя отсутствует производственный участок.';

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
                    g.status,
                    g.current_step_id,
                    g.current_location,
                    g.route_id,

                    o.customer_name,
                    o.status AS order_status,
                    o.priority,
                    o.planned_date,

                    rs.id AS route_step_id,
                    rs.step_number,
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
                ':code' => $scannedCode,
            ]);

            $glass = $stmt->fetch(
                PDO::FETCH_ASSOC
            );

            /*
             * Стекло не найдено.
             */
            if (!$glass) {

                $flashType = 'error';
                $flashTitle = 'Стекло не найдено';
                $flashMessage =
                    'QR-код «'
                    . $scannedCode
                    . '» отсутствует в системе.';

                logAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_not_found',
                    null,
                    null,
                    null,
                    [
                        'code' =>
                            $scannedCode,
                    ]
                );

            /*
             * Неверный участок.
             */
            } elseif (
                (int) $glass[
                    'production_stage_id'
                ] !== $stageId
            ) {

                $flashType = 'warning';
                $flashTitle =
                    'Сканирование не принято';

                $flashMessage =
                    'Стекло уже находится на участке «'
                    . $glass[
                        'production_stage_name'
                    ]
                    . '». '
                    . 'Ваш участок — «'
                    . (
                        $user['stage_name']
                        ?? 'не указан'
                    )
                    . '».';

                logAudit(
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
                            (int) $glass[
                                'production_stage_id'
                            ],

                        'glass_stage' =>
                            $glass[
                                'production_stage_name'
                            ],

                        'employee_stage_id' =>
                            $stageId,
                    ]
                );

            /*
             * Заказ не в производстве.
             */
            } elseif (
                $glass['order_status']
                !== 'in_production'
            ) {

                $flashType = 'warning';
                $flashTitle =
                    'Сканирование не принято';

                $flashMessage =
                    'Заказ «'
                    . $glass['order_number']
                    . '» сейчас не находится в производстве.';

                logAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_denied',
                    'glass',
                    (int) $glass['id'],
                    null,
                    [
                        'reason' =>
                            'order_not_in_production',

                        'code' =>
                            $glass['code'],

                        'order_status' =>
                            $glass['order_status'],
                    ]
                );

            /*
             * Стекло уже завершено / брак / другой статус.
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

                $flashType = 'warning';
                $flashTitle =
                    'Сканирование не принято';

                $flashMessage =
                    'Стекло «'
                    . $glass['code']
                    . '» уже имеет статус «'
                    . $glass['status']
                    . '».';

                logAudit(
                    $db,
                    (int) $user['id'],
                    'scan_glass_denied',
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

                $batchStmt = $db->prepare("
                    SELECT
                        pb.id,
                        pb.status
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

                $activeBatch =
                    $batchStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if ($activeBatch) {

                    $flashType = 'warning';
                    $flashTitle =
                        'Стекло находится в партии';

                    $flashMessage =
                        'Стекло «'
                        . $glass['code']
                        . '» находится в активной партии №'
                        . $activeBatch['id']
                        . '. '
                        . 'Завершите работу через страницу партии.';

                    logAudit(
                        $db,
                        (int) $user['id'],
                        'scan_glass_denied',
                        'glass',
                        (int) $glass['id'],
                        null,
                        [
                            'reason' =>
                                'active_batch',

                            'code' =>
                                $glass['code'],

                            'batch_id' =>
                                (int) $activeBatch[
                                    'id'
                                ],
                        ]
                    );

                } else {

                    /*
                     * ==============================================
                     * Выполняем операцию
                     * ==============================================
                     */

                    try {

                        $db->beginTransaction();

                        /*
                         * Повторная загрузка стекла.
                         */
                        $currentStmt = $db->prepare("
                            SELECT
                                g.id,
                                g.code,
                                g.order_id,
                                g.status,
                                g.current_step_id,
                                g.current_location,
                                g.route_id,

                                o.status AS order_status,
                                o.order_number,

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

                            WHERE g.id = :id

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
                            ] !== 'in_production'
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
                         * Проверяем, что этап всё ещё принадлежит
                         * участку текущего сотрудника.
                         */
                        $stepStageStmt =
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

                        $stepStageStmt->execute([
                            ':route_step_id' =>
                                (int) $currentGlass[
                                    'current_step_id'
                                ],
                        ]);

                        $stepStage =
                            $stepStageStmt->fetch(
                                PDO::FETCH_ASSOC
                            );

                        if (
                            !$stepStage ||
                            (int) $stepStage['id']
                            !== $stageId
                        ) {
                            throw new RuntimeException(
                                'Стекло уже находится на другом участке.'
                            );
                        }

                        /*
                         * Проверяем активную партию второй раз.
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
                                (int) $currentGlass[
                                    'id'
                                ],
                        ]);

                        if (
                            $activeBatchStmt->fetchColumn()
                            !== false
                        ) {
                            throw new RuntimeException(
                                'Стекло уже находится в активной партии.'
                            );
                        }

                        /*
                         * Ищем следующий этап.
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
                                (int) $currentGlass[
                                    'route_id'
                                ],

                            ':step_number' =>
                                (int) $currentGlass[
                                    'step_number'
                                ] + 1,
                        ]);

                        $nextStep =
                            $nextStmt->fetch(
                                PDO::FETCH_ASSOC
                            );

                        /*
                         * Следующий этап существует.
                         */

                        if ($nextStep) {

                            $newStatus =
                                'waiting';

                            $newStepId =
                                (int) $nextStep['id'];

                            $newLocation =
                                $nextStep['name'];

                            $toStage =
                                $nextStep['name'];

                        } else {

                            /*
                             * Это последний этап.
                             */
                            $newStatus =
                                'completed';

                            $newStepId =
                                (int) $currentGlass[
                                    'current_step_id'
                                ];

                            $newLocation =
                                'Готово';

                            $toStage = null;
                        }

                        /*
                         * Производственная операция.
                         */
                        $operationInsert =
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
                                    'Операция завершена QR-сканированием.'
                                )
                            ");

                        $operationInsert->execute([
                            ':glass_id' =>
                                (int) $currentGlass[
                                    'id'
                                ],

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

                            ':to_stage' =>
                                $toStage,
                        ]);

                        /*
                         * История.
                         */
                        $historyInsert =
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

                        $historyInsert->execute([
                            ':glass_id' =>
                                (int) $currentGlass[
                                    'id'
                                ],

                            ':employee_id' =>
                                (int) $user['id'],

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
                                    ? 'QR: операция завершена и стекло передано на следующий участок.'
                                    : 'QR: маршрут стекла полностью завершён.',
                        ]);

                        /*
                         * Обновляем стекло.
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
                                (int) $currentGlass[
                                    'id'
                                ],
                        ]);

                        /*
                         * Аудит успешного сканирования.
                         */
                        logAudit(
                            $db,
                            (int) $user['id'],
                            'scan_glass',
                            'glass',
                            (int) $currentGlass['id'],
                            [
                                'status' =>
                                    $currentGlass[
                                        'status'
                                    ],

                                'current_step_id' =>
                                    (int) $currentGlass[
                                        'current_step_id'
                                    ],

                                'location' =>
                                    $currentGlass[
                                        'current_location'
                                    ],
                            ],
                            [
                                'status' =>
                                    $newStatus,

                                'current_step_id' =>
                                    $newStepId,

                                'location' =>
                                    $newLocation,

                                'employee_id' =>
                                    (int) $user['id'],
                            ]
                        );

                        /*
                         * Проверяем, завершён ли весь заказ.
                         */
                        if (
                            $newStatus ===
                            'completed'
                        ) {

                            $orderProgressStmt =
                                $db->prepare("
                                    SELECT
                                        COUNT(*) AS total_glasses,

                                        SUM(
                                            CASE
                                                WHEN status =
                                                    'completed'
                                                THEN 1
                                                ELSE 0
                                            END
                                        ) AS completed_glasses

                                    FROM glasses

                                    WHERE order_id =
                                        :order_id
                                ");

                            $orderProgressStmt->execute([
                                ':order_id' =>
                                    (int) $currentGlass[
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
                                    ] ?? 0
                                ) > 0
                                &&
                                (int) (
                                    $orderProgress[
                                        'completed_glasses'
                                    ] ?? 0
                                )
                                ===
                                (int) (
                                    $orderProgress[
                                        'total_glasses'
                                    ] ?? 0
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
                                        (int) $currentGlass[
                                            'order_id'
                                        ],
                                ]);

                                logAudit(
                                    $db,
                                    (int) $user['id'],
                                    'order_completed',
                                    'order',
                                    (int) $currentGlass[
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
                                                ] ?? 0
                                            ),
                                    ]
                                );
                            }
                        }

                        $db->commit();

                        /*
                         * Успешный результат.
                         */
                        $flashType =
                            'success';

                        $flashTitle =
                            '✅ Сканирование принято';

                        $flashMessage =
                            '<strong>'
                            . e(
                                $currentGlass['code']
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
                                ?? 'Готово'
                            );

                    } catch (
                        Throwable $exception
                    ) {

                        if (
                            $db->inTransaction()
                        ) {
                            $db->rollBack();
                        }

                        $flashType =
                            'error';

                        $flashTitle =
                            '❌ Операция не выполнена';

                        $flashMessage =
                            e(
                                $exception->getMessage()
                            );

                        logAudit(
                            $db,
                            (int) $user['id'],
                            'scan_glass_error',
                            'glass',
                            isset($glass['id'])
                                ? (int) $glass['id']
                                : null,
                            null,
                            [
                                'code' =>
                                    $scannedCode,

                                'error' =>
                                    $exception->getMessage(),
                            ]
                        );
                    }
                }
            }
        }
    }
}

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
        QR-сканирование — OPTIMA GLASS
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

        .scan-page {
            width: 100%;
            max-width: 760px;
            margin: 0 auto;
        }

        .scan-card {
            padding: 30px;
            border-radius: 18px;
            background: #fff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .05);
        }

        .scan-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .scan-header h1 {
            margin: 0 0 8px;
        }

        .scan-header p {
            margin: 0;
            color: #6b7280;
        }

        .scan-result {
            margin-bottom: 20px;
            padding: 22px;
            border-radius: 14px;
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
            font-size: 16px;
            line-height: 1.5;
        }

        .scan-form {
            display: flex;
            gap: 10px;
        }

        .scan-form input {
            flex: 1;
            min-height: 54px;
            padding: 0 16px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 18px;
            outline: none;
        }

        .scan-form input:focus {
            border-color: #111827;
        }

        .scan-form button {
            min-width: 150px;
            min-height: 54px;
            padding: 0 18px;
            border: 0;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
        }

        .scan-hint {
            margin-top: 15px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
        }

        .scan-ready {
            margin-top: 22px;
            padding: 13px;
            border-radius: 10px;
            background: #f9fafb;
            color: #4b5563;
            text-align: center;
            font-size: 14px;
        }

        @media (max-width: 600px) {

            body {
                padding: 15px;
            }

            .scan-card {
                padding: 20px;
            }

            .scan-form {
                flex-direction: column;
            }

            .scan-form button {
                width: 100%;
            }

        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="scan-page">

    <section class="scan-card">

        <div class="scan-header">

            <h1>
                QR-сканирование
            </h1>

            <p>

                <?= e($user['name']) ?>

                <?php if (
                    !empty(
                        $user['stage_name']
                    )
                ): ?>

                    ·
                    <?= e(
                        $user['stage_name']
                    ) ?>

                <?php endif; ?>

            </p>

        </div>


        <?php if ($flashType !== ''): ?>

            <div
                class="scan-result <?= e(
                    $flashType
                ) ?>"
            >

                <div class="scan-result-title">
                    <?= e($flashTitle) ?>
                </div>

                <div class="scan-result-message">
                    <?= $flashMessage ?>
                </div>

            </div>

        <?php endif; ?>


        <form
            method="post"
            class="scan-form"
            id="scanForm"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <input
                type="text"
                name="code"
                id="scanCode"
                value=""
                placeholder="Отсканируйте QR-код"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                required
            >

            <button type="submit">
                Сканировать
            </button>

        </form>


        <div class="scan-hint">

            Один QR-код завершает текущую операцию.
            После успешного сканирования стекло автоматически
            переходит на следующий этап маршрута.

        </div>


        <div class="scan-ready">

            Поле готово к следующему сканированию.

        </div>

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
         * После отправки оставляем курсор
         * в поле для следующего сканирования.
         */
        if (input && form) {

            form.addEventListener(
                'submit',
                function () {

                    setTimeout(
                        function () {

                            input.value = '';
                            input.focus();

                        },
                        100
                    );

                }
            );

        }

        /*
         * Звуковой сигнал.
         *
         * Используем Web Audio API,
         * чтобы не хранить отдельные mp3-файлы.
         */

        <?php if ($flashType === 'success'): ?>

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

                    oscillator.frequency.value =
                        880;

                    oscillator.type =
                        'sine';

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
                        audio.currentTime + 0.12
                    );

                }

            } catch (error) {
                // Звук не должен мешать работе сканера.
            }

        <?php elseif (
            $flashType === 'warning'
            || $flashType === 'error'
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

                    oscillator.frequency.value =
                        220;

                    oscillator.type =
                        'square';

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
                        audio.currentTime + 0.18
                    );

                }

            } catch (error) {
                // Звук не должен мешать работе сканера.
            }

        <?php endif; ?>

    }
);

</script>

</body>

</html>
