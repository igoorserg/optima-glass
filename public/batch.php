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

function priorityLabel(int $priority): string
{
    return match ($priority) {
        3 => '🔴 Критический',
        2 => '🟠 Срочный',
        default => '🟢 Обычный',
    };
}

function batchStatusLabel(string $status): string
{
    return match ($status) {
        'created' => 'Создана',
        'in_progress' => 'В работе',
        'completed' => 'Завершена',
        'cancelled' => 'Отменена',
        default => $status,
    };
}

function itemStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Ожидает',
        'completed' => 'Готово',
        'rejected' => 'Брак',
        'cancelled' => 'Отменено',
        default => $status,
    };
}

/*
|--------------------------------------------------------------------------
| ID партии
|--------------------------------------------------------------------------
*/

$batchId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (int) ($_POST['batch_id'] ?? 0);

if ($batchId <= 0) {
    http_response_code(400);
    exit('Партия не указана.');
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_batch'])) {
    $_SESSION['csrf_batch'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_batch'];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Загружаем партию
|--------------------------------------------------------------------------
*/

$batchStmt = $db->prepare("
    SELECT
        pb.id,
        pb.order_id,
        pb.route_step_id,
        pb.employee_id,
        pb.created_by,
        pb.assigned_employee_id,
        pb.status,
        pb.created_at,
        pb.started_at,
        pb.completed_at,

        o.order_number,
        o.customer_name,
        o.priority,
        o.planned_date,

        rs.step_number,
        rs.name AS stage_name,

        ps.id AS stage_id,
        ps.execution_mode,

        creator.name AS creator_name,
        assigned.name AS assigned_employee_name

    FROM production_batches pb

    JOIN orders o
        ON o.id = pb.order_id

    JOIN route_steps rs
        ON rs.id = pb.route_step_id

    JOIN production_stages ps
        ON ps.name = rs.name

    LEFT JOIN users creator
        ON creator.id = pb.created_by

    LEFT JOIN users assigned
        ON assigned.id = pb.assigned_employee_id

    WHERE pb.id = :id

    LIMIT 1
");

$batchStmt->execute([
    ':id' => $batchId,
]);

$batch = $batchStmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$batch) {
    http_response_code(404);
    exit('Партия не найдена.');
}

/*
|--------------------------------------------------------------------------
| Права
|--------------------------------------------------------------------------
*/

$role = $user['role'];

$canManageAll = in_array(
    $role,
    [
        'superadmin',
        'admin',
        'manager',
    ],
    true
);

$isSectionManagerForBatch =
    $role === 'section_manager'
    && (int) ($user['stage_id'] ?? 0)
    === (int) $batch['stage_id'];

$isAssignedEmployee =
    in_array(
        $role,
        [
            'employee',
            'section_manager',
        ],
        true
    )
    &&
    (int) (
        $batch['assigned_employee_id'] ?? 0
    ) === (int) $user['id'];

$canManageBatch =
    $canManageAll
    || $isSectionManagerForBatch
    || $isAssignedEmployee;

if (!$canManageBatch) {
    http_response_code(403);
    exit('У вас нет доступа к этой партии.');
}

/*
|--------------------------------------------------------------------------
| Загружаем элементы партии
|--------------------------------------------------------------------------
*/

function loadBatchItems(
    PDO $db,
    int $batchId
): array {
    $stmt = $db->prepare("
        SELECT
            pbi.id AS batch_item_id,
            pbi.batch_id,
            pbi.glass_id,
            pbi.status AS item_status,
            pbi.created_at AS item_created_at,
            pbi.completed_at AS item_completed_at,

            g.code,
            g.order_number,
            g.glass_type,
            g.thickness,
            g.width,
            g.height,
            g.quantity,
            g.status AS glass_status,
            g.current_step_id,

            rs.id AS route_step_id,
            rs.step_number,
            rs.name AS stage_name

        FROM production_batch_items pbi

        JOIN glasses g
            ON g.id = pbi.glass_id

        LEFT JOIN route_steps rs
            ON rs.id = g.current_step_id

        WHERE pbi.batch_id = :batch_id

        ORDER BY
            g.id ASC
    ");

    $stmt->execute([
        ':batch_id' => $batchId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

$items = loadBatchItems(
    $db,
    $batchId
);

/*
|--------------------------------------------------------------------------
| Завершение партии
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    ($_POST['action'] ?? '') === 'complete_batch'
) {

    if (!hash_equals(
        $csrfToken,
        $_POST['csrf_token'] ?? ''
    )) {

        $error = 'Ошибка проверки безопасности.';

    } elseif (
        $batch['status'] !== 'in_progress'
    ) {

        $error =
            'Эта партия уже завершена или отменена.';

    } else {

        $submittedResults =
            $_POST['results'] ?? [];

        if (!is_array($submittedResults)) {
            $submittedResults = [];
        }

        try {

            $db->beginTransaction();

            /*
             * Повторно загружаем актуальную партию.
             */

            $lockStmt = $db->prepare("
                SELECT
                    pb.*,
                    o.order_number,
                    o.customer_name,
                    o.priority
                FROM production_batches pb
                JOIN orders o
                    ON o.id = pb.order_id
                WHERE pb.id = :id
                LIMIT 1
            ");

            $lockStmt->execute([
                ':id' => $batchId,
            ]);

            $currentBatch =
                $lockStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$currentBatch) {
                throw new RuntimeException(
                    'Партия не найдена.'
                );
            }

            if (
                $currentBatch['status']
                !== 'in_progress'
            ) {
                throw new RuntimeException(
                    'Партия уже не находится в работе.'
                );
            }

            /*
             * Фактический исполнитель.
             *
             * ВАЖНО:
             * он берётся из assigned_employee_id,
             * а не из текущей сессии.
             */

            $assignedEmployeeId =
                (int) (
                    $currentBatch[
                        'assigned_employee_id'
                    ]
                    ??
                    $currentBatch[
                        'employee_id'
                    ]
                    ??
                    0
                );

            if ($assignedEmployeeId <= 0) {
                throw new RuntimeException(
                    'У партии не назначен исполнитель.'
                );
            }

            /*
             * Проверяем, что исполнитель существует
             * и активен.
             */

            $assignedCheck = $db->prepare("
                SELECT
                    id,
                    name,
                    role,
                    active,
                    stage_id
                FROM users
                WHERE id = :id
                  AND active = 1
                LIMIT 1
            ");

            $assignedCheck->execute([
                ':id' => $assignedEmployeeId,
            ]);

            $assignedEmployee =
                $assignedCheck->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$assignedEmployee) {
                throw new RuntimeException(
                    'Назначенный исполнитель не найден или отключён.'
                );
            }

            /*
             * Получаем элементы партии.
             */

            $itemsStmt = $db->prepare("
                SELECT
                    pbi.id AS batch_item_id,
                    pbi.glass_id,
                    pbi.status AS item_status,

                    g.code,
                    g.status AS glass_status,
                    g.current_step_id,
                    g.current_location,
                    g.route_id,

                    rs.id AS route_step_id,
                    rs.step_number,
                    rs.name AS stage_name

                FROM production_batch_items pbi

                JOIN glasses g
                    ON g.id = pbi.glass_id

                JOIN route_steps rs
                    ON rs.id = g.current_step_id

                WHERE pbi.batch_id = :batch_id

                ORDER BY pbi.id ASC
            ");

            $itemsStmt->execute([
                ':batch_id' => $batchId,
            ]);

            $currentItems =
                $itemsStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

            if (!$currentItems) {
                throw new RuntimeException(
                    'В партии нет стекол.'
                );
            }

            /*
             * Запросы.
             */

            $operationInsert = $db->prepare("
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
                    :operation_type,
                    :from_stage,
                    :to_stage,
                    :result,
                    :batch_id,
                    :comment
                )
            ");

            $historyInsert = $db->prepare("
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

            $itemUpdate = $db->prepare("
                UPDATE production_batch_items
                SET
                    status = :status,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");

            $glassUpdate = $db->prepare("
                UPDATE glasses
                SET
                    status = :status,
                    current_step_id = :current_step_id,
                    current_location = :current_location,
                    employee_id = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");

            $completedCount = 0;
            $rejectedCount = 0;

            foreach ($currentItems as $item) {

                $itemId =
                    (int) $item['batch_item_id'];

                $glassId =
                    (int) $item['glass_id'];

                $result =
                    $submittedResults[
                        (string) $itemId
                    ] ?? '';

                if (
                    !in_array(
                        $result,
                        [
                            'completed',
                            'rejected',
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Не указан результат для ' .
                        $item['code'] .
                        '.'
                    );
                }

                $currentRouteStepId =
                    (int) $item['route_step_id'];

                /*
                 * Готово.
                 */

                if (
                    $result === 'completed'
                ) {

                    $nextStmt = $db->prepare("
                        SELECT
                            rs2.id,
                            rs2.step_number,
                            rs2.name
                        FROM route_steps rs1

                        JOIN route_steps rs2
                            ON rs2.route_id = :route_id
                           AND rs2.step_number =
                               rs1.step_number + 1

                        WHERE rs1.id =
                            :current_step_id

                        LIMIT 1
                    ");

                    $nextStmt->execute([
                        ':route_id' =>
                            (int) $item['route_id'],

                        ':current_step_id' =>
                            $currentRouteStepId,
                    ]);

                    $nextStep =
                        $nextStmt->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if ($nextStep) {

                        $nextStepId =
                            (int) $nextStep['id'];

                        $newStatus =
                            'waiting';

                        $newLocation =
                            $nextStep['name'];

                        $toStage =
                            $nextStep['name'];

                    } else {

                        $nextStepId =
                            $currentRouteStepId;

                        $newStatus =
                            'completed';

                        $newLocation =
                            'Готово';

                        $toStage =
                            null;
                    }

                    /*
                     * Производственная операция
                     * записывается на Ивана,
                     * а не на пользователя,
                     * который нажал кнопку.
                     */

                    $operationInsert->execute([
                        ':glass_id' =>
                            $glassId,

                        ':employee_id' =>
                            $assignedEmployeeId,

                        ':route_step_id' =>
                            $currentRouteStepId,

                        ':operation_type' =>
                            'production',

                        ':from_stage' =>
                            $item['stage_name'],

                        ':to_stage' =>
                            $toStage,

                        ':result' =>
                            'completed',

                        ':batch_id' =>
                            $batchId,

                        ':comment' =>
                            'Операция завершена в составе партии.',
                    ]);

                    /*
                     * История стекла.
                     */

                    $historyInsert->execute([
                        ':glass_id' =>
                            $glassId,

                        ':employee_id' =>
                            $assignedEmployeeId,

                        ':old_status' =>
                            $item['glass_status'],

                        ':new_status' =>
                            $newStatus,

                        ':old_location' =>
                            $item['current_location'],

                        ':new_location' =>
                            $newLocation,

                        ':comment' =>
                            $nextStep
                                ? 'Стекло передано на следующий этап.'
                                : 'Маршрут стекла полностью завершён.',
                    ]);

                    /*
                     * Обновляем стекло.
                     */

                    $glassUpdate->execute([
                        ':status' =>
                            $newStatus,

                        ':current_step_id' =>
                            $nextStepId,

                        ':current_location' =>
                            $newLocation,

                        ':id' =>
                            $glassId,
                    ]);

                    $itemUpdate->execute([
                        ':status' =>
                            'completed',

                        ':id' =>
                            $itemId,
                    ]);

                    $completedCount++;

                } else {

                    /*
                     * Брак.
                     */

                    $operationInsert->execute([
                        ':glass_id' =>
                            $glassId,

                        ':employee_id' =>
                            $assignedEmployeeId,

                        ':route_step_id' =>
                            $currentRouteStepId,

                        ':operation_type' =>
                            'production',

                        ':from_stage' =>
                            $item['stage_name'],

                        ':to_stage' =>
                            $item['stage_name'],

                        ':result' =>
                            'rejected',

                        ':batch_id' =>
                            $batchId,

                        ':comment' =>
                            'Стекло отмечено как брак в партии.',
                    ]);

                    $historyInsert->execute([
                        ':glass_id' =>
                            $glassId,

                        ':employee_id' =>
                            $assignedEmployeeId,

                        ':old_status' =>
                            $item['glass_status'],

                        ':new_status' =>
                            'rejected',

                        ':old_location' =>
                            $item['current_location'],

                        ':new_location' =>
                            $item['current_location'],

                        ':comment' =>
                            'Стекло отмечено как брак.',
                    ]);

                    $glassUpdate->execute([
                        ':status' =>
                            'rejected',

                        ':current_step_id' =>
                            $currentRouteStepId,

                        ':current_location' =>
                            $item['current_location'],

                        ':id' =>
                            $glassId,
                    ]);

                    $itemUpdate->execute([
                        ':status' =>
                            'rejected',

                        ':id' =>
                            $itemId,
                    ]);

                    $rejectedCount++;
                }
            }

            /*
             * Закрываем партию.
             */

            $batchUpdate = $db->prepare("
                UPDATE production_batches
                SET
                    status = 'completed',
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");

            $batchUpdate->execute([
                ':id' => $batchId,
            ]);

            /*
             * В audit_log записываем именно пользователя,
             * который нажал кнопку.
             *
             * Это может быть менеджер, начальник участка
             * или сам Иван.
             */

            $audit = $db->prepare("
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
                    'complete_batch',
                    'batch',
                    :entity_id,
                    :old_value,
                    :new_value,
                    :ip_address,
                    :user_agent
                )
            ");

            $audit->execute([
                ':user_id' =>
                    (int) $user['id'],

                ':entity_id' =>
                    $batchId,

                ':old_value' =>
                    json_encode(
                        [
                            'status' =>
                                'in_progress',

                            'assigned_employee_id' =>
                                $assignedEmployeeId,
                        ],
                        JSON_UNESCAPED_UNICODE
                    ),

                ':new_value' =>
                    json_encode(
                        [
                            'status' =>
                                'completed',

                            'completed_count' =>
                                $completedCount,

                            'rejected_count' =>
                                $rejectedCount,

                            'completed_by_user_id' =>
                                (int) $user['id'],
                        ],
                        JSON_UNESCAPED_UNICODE
                    ),

                ':ip_address' =>
                    $_SERVER[
                        'REMOTE_ADDR'
                    ] ?? null,

                ':user_agent' =>
                    $_SERVER[
                        'HTTP_USER_AGENT'
                    ] ?? null,
            ]);

            /*
             * Проверяем завершение заказа.
             */

            $orderCheckStmt = $db->prepare("
                SELECT
                    COUNT(*) AS total_glasses,

                    SUM(
                        CASE
                            WHEN status = 'completed'
                            THEN 1
                            ELSE 0
                        END
                    ) AS completed_glasses

                FROM glasses

                WHERE order_id = :order_id
            ");

            $orderCheckStmt->execute([
                ':order_id' =>
                    (int) $currentBatch[
                        'order_id'
                    ],
            ]);

            $orderProgress =
                $orderCheckStmt->fetch(
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

                $orderComplete = $db->prepare("
                    UPDATE orders
                    SET
                        status = 'completed',
                        production_completed_at =
                            CURRENT_TIMESTAMP,
                        updated_at =
                            CURRENT_TIMESTAMP
                    WHERE id = :id
                      AND status <> 'completed'
                ");

                $orderComplete->execute([
                    ':id' =>
                        (int) $currentBatch[
                            'order_id'
                        ],
                ]);

                $orderAudit = $db->prepare("
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
                        NULL,
                        'order_completed',
                        'order',
                        :entity_id,
                        NULL,
                        :new_value,
                        :ip_address,
                        :user_agent
                    )
                ");

                $orderAudit->execute([
                    ':entity_id' =>
                        (int) $currentBatch[
                            'order_id'
                        ],

                    ':new_value' =>
                        json_encode(
                            [
                                'status' =>
                                    'completed',

                                'total_glasses' =>
                                    (int) (
                                        $orderProgress[
                                            'total_glasses'
                                        ] ?? 0
                                    ),
                            ],
                            JSON_UNESCAPED_UNICODE
                        ),

                    ':ip_address' =>
                        $_SERVER[
                            'REMOTE_ADDR'
                        ] ?? null,

                    ':user_agent' =>
                        $_SERVER[
                            'HTTP_USER_AGENT'
                        ] ?? null,
                ]);
            }

            $db->commit();

            $success =
                'Партия №' .
                $batchId .
                ' завершена. ' .
                'Готово: ' .
                $completedCount .
                ', брак: ' .
                $rejectedCount .
                '. ' .
                'Исполнитель: ' .
                $assignedEmployee['name'] .
                '.';

            $batch['status'] =
                'completed';

            $items = loadBatchItems(
                $db,
                $batchId
            );

        } catch (Throwable $exception) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $error =
                $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Статистика партии
|--------------------------------------------------------------------------
*/

$totalItems = count($items);

$completedItems = 0;
$rejectedItems = 0;
$pendingItems = 0;

foreach ($items as $item) {

    if (
        $item['item_status'] ===
        'completed'
    ) {
        $completedItems++;

    } elseif (
        $item['item_status'] ===
        'rejected'
    ) {
        $rejectedItems++;

    } else {
        $pendingItems++;
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
        Партия №<?= (int) $batchId ?>
        — OPTIMA GLASS
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>

        .batch-page {
            max-width: 1300px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .batch-header {
            margin-bottom: 25px;
        }

        .batch-title {
            margin-bottom: 8px;
        }

        .batch-meta {
            color: #6b7280;
        }

        .batch-summary {
            display: grid;
            grid-template-columns:
                repeat(5, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 25px;
        }

        .summary-card {
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }

        .summary-number {
            display: block;
            margin-top: 5px;
            font-size: 24px;
            font-weight: 700;
        }

        .message {
            padding: 13px 16px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .message-success {
            color: #166534;
            background: #dcfce7;
        }

        .message-error {
            color: #991b1b;
            background: #fee2e2;
        }

        .batch-card {
            padding: 24px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .batch-table-wrap {
            overflow-x: auto;
        }

        .batch-table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        .batch-table th,
        .batch-table td {
            padding: 13px 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: middle;
        }

        .batch-table th {
            white-space: nowrap;
        }

        .glass-code {
            font-weight: 700;
        }

        .result-select {
            min-width: 140px;
            padding: 8px 10px;
        }

        .batch-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-top: 22px;
            flex-wrap: wrap;
        }

        .batch-warning {
            color: #6b7280;
            font-size: 13px;
        }

        .finish-button {
            min-height: 44px;
            padding: 10px 18px;
        }

        .batch-completed {
            padding: 20px;
            background: #f9fafb;
            border-radius: 10px;
        }

        @media (max-width: 900px) {

            .batch-summary {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

        }

        @media (max-width: 600px) {

            .batch-summary {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="batch-page">

    <header class="batch-header">

        <h1 class="batch-title">
            Партия №<?= (int) $batchId ?>
        </h1>

        <div class="batch-meta">

            Заказ:
            <strong>
                <?= e($batch['order_number']) ?>
            </strong>

            ·

            Участок:
            <strong>
                <?= e($batch['stage_name']) ?>
            </strong>

            ·

            Исполнитель:
            <strong>
                <?= e(
                    $batch[
                        'assigned_employee_name'
                    ]
                    ??
                    'Не назначен'
                ) ?>
            </strong>

            <?php if (
                !empty($batch['creator_name'])
            ): ?>

                ·

                Создал:
                <strong>
                    <?= e(
                        $batch[
                            'creator_name'
                        ]
                    ) ?>
                </strong>

            <?php endif; ?>

        </div>

    </header>


    <?php if ($error !== ''): ?>

        <div class="message message-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($success !== ''): ?>

        <div class="message message-success">
            <?= e($success) ?>
        </div>

    <?php endif; ?>


    <section class="batch-summary">

        <div class="summary-card">

            <div>
                Приоритет
            </div>

            <span class="summary-number">

                <?= e(
                    priorityLabel(
                        (int) $batch['priority']
                    )
                ) ?>

            </span>

        </div>


        <div class="summary-card">

            <div>
                Всего
            </div>

            <span class="summary-number">
                <?= $totalItems ?>
            </span>

        </div>


        <div class="summary-card">

            <div>
                Готово
            </div>

            <span class="summary-number">
                <?= $completedItems ?>
            </span>

        </div>


        <div class="summary-card">

            <div>
                Брак
            </div>

            <span class="summary-number">
                <?= $rejectedItems ?>
            </span>

        </div>


        <div class="summary-card">

            <div>
                Ожидает
            </div>

            <span class="summary-number">
                <?= $pendingItems ?>
            </span>

        </div>

    </section>


    <section class="batch-card">

        <h2>
            Стекла партии
        </h2>


        <?php if (!$items): ?>

            <div class="batch-completed">
                В партии нет стекол.
            </div>

        <?php elseif (
            $batch['status'] === 'in_progress'
        ): ?>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="complete_batch"
                >

                <input
                    type="hidden"
                    name="batch_id"
                    value="<?= (int) $batchId ?>"
                >


                <div class="batch-table-wrap">

                    <table class="batch-table">

                        <thead>

                            <tr>
                                <th>#</th>
                                <th>Стекло</th>
                                <th>Размер</th>
                                <th>Толщина</th>
                                <th>Этап</th>
                                <th>Результат</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $items
                            as $index => $item
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
                                        $item['glass_type']
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

                                    <?= (int)
                                        $item['width'] ?>

                                    ×

                                    <?= (int)
                                        $item['height'] ?>

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
                                    <?= e(
                                        $item[
                                            'stage_name'
                                        ]
                                    ) ?>
                                </td>

                                <td>

                                    <select
                                        class="result-select"
                                        name="results[<?= (int) $item['batch_item_id'] ?>]"
                                    >

                                        <option value="completed">
                                            ✅ Готово
                                        </option>

                                        <option value="rejected">
                                            ❌ Брак
                                        </option>

                                    </select>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <div class="batch-footer">

                    <div class="batch-warning">

                        Исполнитель:
                        <strong>
                            <?= e(
                                $batch[
                                    'assigned_employee_name'
                                ]
                                ??
                                'Не назначен'
                            ) ?>
                        </strong>

                        <br>

                        После подтверждения
                        производственные операции
                        будут записаны на этого исполнителя.

                    </div>


                    <button
                        type="submit"
                        class="finish-button"
                        onclick="return confirm('Завершить всю партию с указанными результатами?');"
                    >
                        Завершить партию
                    </button>

                </div>

            </form>

        <?php else: ?>

            <div class="batch-completed">

                <strong>
                    Статус партии:
                </strong>

                <?= e(
                    batchStatusLabel(
                        $batch['status']
                    )
                ) ?>

                <?php if (
                    $batch['completed_at']
                ): ?>

                    <br>

                    <small>
                        Завершена:
                        <?= e(
                            $batch[
                                'completed_at'
                            ]
                        ) ?>
                    </small>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>

</html>
