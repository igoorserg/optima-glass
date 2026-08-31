<?php

require __DIR__ . '/../src/auth.php';

$user = require_user();

/*
|--------------------------------------------------------------------------
| Вспомогательные функции
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
        3 => '🔴 Критический',
        2 => '🟠 Срочный',
        default => '🟢 Обычный',
    };
}

function statusLabel(string $status): string
{
    return match ($status) {
        'created' => 'Создано',
        'waiting' => 'Ожидает',
        'in_progress' => 'В работе',
        'completed' => 'Готово',
        'rejected' => 'Брак',
        'rework' => 'Повторная обработка',
        default => $status,
    };
}

function executionModeLabel(string $mode): string
{
    return match ($mode) {
        'batch' => 'Партиями',
        'both' => 'Поштучно + партиями',
        default => 'Поштучно',
    };
}

/*
|--------------------------------------------------------------------------
| Права текущего пользователя
|--------------------------------------------------------------------------
*/

$role = $user['role'];

$canAccessAllStages = in_array(
    $role,
    [
        'superadmin',
        'admin',
        'manager',
    ],
    true
);

$canCreateBatch = in_array(
    $role,
    [
        'superadmin',
        'admin',
        'manager',
        'section_manager',
    ],
    true
);

$userStageId = current_stage_id($user);

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_production'])) {
    $_SESSION['csrf_production'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_production'];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Выбранный участок
|--------------------------------------------------------------------------
*/

$requestedStageId = isset($_GET['stage_id'])
    ? (int) $_GET['stage_id']
    : 0;

if ($canAccessAllStages) {

    $stageId = $requestedStageId;

} else {

    if ($userStageId === null) {
        http_response_code(403);
        exit(
            'У пользователя не назначен производственный участок.'
        );
    }

    $stageId = $userStageId;
}

/*
|--------------------------------------------------------------------------
| Получаем активные участки
|--------------------------------------------------------------------------
*/

$stageStmt = $db->query("
    SELECT
        id,
        name,
        active,
        execution_mode
    FROM production_stages
    WHERE active = 1
    ORDER BY id
");

$stages = $stageStmt->fetchAll(
    PDO::FETCH_ASSOC
);

$selectedStage = null;

foreach ($stages as $stage) {

    if ((int) $stage['id'] === $stageId) {
        $selectedStage = $stage;
        break;
    }
}

if (
    $stageId > 0 &&
    $selectedStage === null
) {
    http_response_code(404);
    exit(
        'Производственный участок не найден.'
    );
}

if (
    $stageId > 0 &&
    !$canAccessAllStages &&
    !can_access_stage(
        $stageId,
        $user
    )
) {
    http_response_code(403);
    exit(
        'Доступ к этому участку запрещён.'
    );
}

/*
|--------------------------------------------------------------------------
| Режим страницы
|--------------------------------------------------------------------------
*/

$mode = $_GET['mode'] ?? 'single';

if (
    !in_array(
        $mode,
        ['single', 'batch'],
        true
    )
) {
    $mode = 'single';
}

if (
    $selectedStage &&
    $selectedStage['execution_mode'] === 'single'
) {
    $mode = 'single';
}

/*
|--------------------------------------------------------------------------
| Создание партии
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $canCreateBatch
) {

    if (!hash_equals(
        $csrfToken,
        $_POST['csrf_token'] ?? ''
    )) {

        $error = 'Ошибка проверки безопасности.';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'create_batch') {

            $postStageId = (int) (
                $_POST['stage_id'] ?? 0
            );

            $orderId = (int) (
                $_POST['order_id'] ?? 0
            );

            $assignedEmployeeId = (int) (
                $_POST['assigned_employee_id'] ?? 0
            );

            $glassIds = $_POST['glass_ids'] ?? [];

            if (!is_array($glassIds)) {
                $glassIds = [];
            }

            $glassIds = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            $glassIds
                        ),
                        static function (
                            int $id
                        ): bool {
                            return $id > 0;
                        }
                    )
                )
            );

            if ($postStageId <= 0) {

                $error =
                    'Не выбран производственный участок.';

            } elseif (
                !$canAccessAllStages &&
                !can_access_stage(
                    $postStageId,
                    $user
                )
            ) {

                $error =
                    'Нет доступа к этому участку.';

            } elseif ($orderId <= 0) {

                $error =
                    'Не выбран заказ.';

            } elseif ($assignedEmployeeId <= 0) {

                $error =
                    'Выберите исполнителя.';

            } elseif (!$glassIds) {

                $error =
                    'Выберите хотя бы одно стекло.';

            } else {

                try {

                    $db->beginTransaction();

                    /*
                     * Проверяем участок.
                     */

                    $stageCheck = $db->prepare("
                        SELECT
                            id,
                            name,
                            execution_mode
                        FROM production_stages
                        WHERE id = :id
                          AND active = 1
                        LIMIT 1
                    ");

                    $stageCheck->execute([
                        ':id' => $postStageId,
                    ]);

                    $batchStage =
                        $stageCheck->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if (!$batchStage) {
                        throw new RuntimeException(
                            'Производственный участок не найден.'
                        );
                    }

                    if (
                        !in_array(
                            $batchStage['execution_mode'],
                            ['batch', 'both'],
                            true
                        )
                    ) {
                        throw new RuntimeException(
                            'Для этого участка партийный режим запрещён.'
                        );
                    }

                    /*
                     * Проверяем заказ.
                     */

                    $orderCheck = $db->prepare("
                        SELECT
                            id,
                            order_number,
                            customer_name,
                            status,
                            priority
                        FROM orders
                        WHERE id = :id
                        LIMIT 1
                    ");

                    $orderCheck->execute([
                        ':id' => $orderId,
                    ]);

                    $order =
                        $orderCheck->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if (!$order) {
                        throw new RuntimeException(
                            'Заказ не найден.'
                        );
                    }

                    if (
                        $order['status'] !==
                        'in_production'
                    ) {
                        throw new RuntimeException(
                            'Заказ не находится в производстве.'
                        );
                    }

                    /*
                     * Проверяем исполнителя.
                     */

                    $employeeCheck = $db->prepare("
                        SELECT
                            id,
                            name,
                            email,
                            role,
                            active,
                            stage_id
                        FROM users
                        WHERE id = :id
                          AND active = 1
                        LIMIT 1
                    ");

                    $employeeCheck->execute([
                        ':id' =>
                            $assignedEmployeeId,
                    ]);

                    $assignedEmployee =
                        $employeeCheck->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if (!$assignedEmployee) {
                        throw new RuntimeException(
                            'Исполнитель не найден или отключён.'
                        );
                    }

                    if (
                        !in_array(
                            $assignedEmployee['role'],
                            [
                                'employee',
                                'section_manager',
                            ],
                            true
                        )
                    ) {
                        throw new RuntimeException(
                            'Назначать в качестве исполнителя можно только сотрудника или начальника участка.'
                        );
                    }

                    /*
                     * Исполнитель обязан относиться
                     * к выбранному участку.
                     */

                    if (
                        (int) (
                            $assignedEmployee['stage_id']
                            ?? 0
                        ) !==
                        $postStageId
                    ) {
                        throw new RuntimeException(
                            'Исполнитель не относится к выбранному производственному участку.'
                        );
                    }

                    /*
                     * Начальник участка может
                     * назначать только людей своего участка.
                     */

                    if (
                        $role ===
                        'section_manager' &&
                        (int) (
                            $assignedEmployee['stage_id']
                            ?? 0
                        ) !==
                        (int) $userStageId
                    ) {
                        throw new RuntimeException(
                            'Можно назначать сотрудников только своего участка.'
                        );
                    }

                    /*
                     * Проверяем выбранные стекла.
                     */

                    $placeholders = implode(
                        ',',
                        array_fill(
                            0,
                            count($glassIds),
                            '?'
                        )
                    );

                    $glassSql = "
                        SELECT
                            g.id,
                            g.code,
                            g.order_id,
                            g.order_number,
                            g.status,
                            g.current_step_id,

                            rs.id AS route_step_id,
                            rs.route_id,
                            rs.step_number,
                            rs.name AS stage_name

                        FROM glasses g

                        JOIN route_steps rs
                            ON rs.id = g.current_step_id

                        WHERE g.id IN ($placeholders)
                          AND g.order_id = ?
                    ";

                    $glassStmt =
                        $db->prepare(
                            $glassSql
                        );

                    $params = $glassIds;
                    $params[] = $orderId;

                    $glassStmt->execute(
                        $params
                    );

                    $selectedGlasses =
                        $glassStmt->fetchAll(
                            PDO::FETCH_ASSOC
                        );

                    if (
                        count($selectedGlasses) !==
                        count($glassIds)
                    ) {
                        throw new RuntimeException(
                            'Одно или несколько выбранных стекол не принадлежат этому заказу.'
                        );
                    }

                    $routeStepId = null;

                    foreach (
                        $selectedGlasses as $glass
                    ) {

                        if (
                            $glass['stage_name'] !==
                            $batchStage['name']
                        ) {
                            throw new RuntimeException(
                                'Все стекла партии должны находиться на выбранном участке.'
                            );
                        }

                        if (
                            $glass['status'] !==
                            'waiting'
                        ) {
                            throw new RuntimeException(
                                'В новую партию можно добавить только стекла со статусом «Ожидает».'
                            );
                        }

                        $currentRouteStepId =
                            (int) $glass[
                                'route_step_id'
                            ];

                        if ($routeStepId === null) {

                            $routeStepId =
                                $currentRouteStepId;

                        } elseif (
                            $routeStepId !==
                            $currentRouteStepId
                        ) {

                            throw new RuntimeException(
                                'Все стекла партии должны находиться на одном шаге маршрута.'
                            );
                        }
                    }

                    /*
                     * Проверяем активные партии.
                     */

                    $activeBatchSql = "
                        SELECT
                            pbi.glass_id,
                            pb.id AS batch_id

                        FROM production_batch_items pbi

                        JOIN production_batches pb
                            ON pb.id = pbi.batch_id

                        WHERE pbi.glass_id IN ($placeholders)

                          AND pb.status IN (
                              'created',
                              'in_progress'
                          )
                    ";

                    $activeBatchStmt =
                        $db->prepare(
                            $activeBatchSql
                        );

                    $activeBatchStmt->execute(
                        $glassIds
                    );

                    $busyGlasses =
                        $activeBatchStmt->fetchAll(
                            PDO::FETCH_ASSOC
                        );

                    if ($busyGlasses) {

                        $busyIds = array_map(
                            'intval',
                            array_column(
                                $busyGlasses,
                                'glass_id'
                            )
                        );

                        $busyCodes = [];

                        foreach (
                            $selectedGlasses
                            as $glass
                        ) {

                            if (
                                in_array(
                                    (int) $glass['id'],
                                    $busyIds,
                                    true
                                )
                            ) {
                                $busyCodes[] =
                                    $glass['code'];
                            }
                        }

                        throw new RuntimeException(
                            'Эти стекла уже находятся в активной партии: ' .
                            implode(
                                ', ',
                                $busyCodes
                            )
                        );
                    }

                    /*
                     * Создаём партию.
                     *
                     * created_by = тот,
                     * кто создал партию.
                     *
                     * assigned_employee_id =
                     * непосредственный исполнитель.
                     */

                    $batchInsert = $db->prepare("
                        INSERT INTO production_batches (
                            order_id,
                            route_step_id,
                            employee_id,
                            status,
                            started_at,
                            created_by,
                            assigned_employee_id
                        )
                        VALUES (
                            :order_id,
                            :route_step_id,
                            :employee_id,
                            'in_progress',
                            CURRENT_TIMESTAMP,
                            :created_by,
                            :assigned_employee_id
                        )
                    ");

                    /*
                     * Старое employee_id пока сохраняем
                     * для совместимости и ставим туда
                     * фактического исполнителя.
                     */

                    $batchInsert->execute([
                        ':order_id' =>
                            $orderId,

                        ':route_step_id' =>
                            $routeStepId,

                        ':employee_id' =>
                            $assignedEmployeeId,

                        ':created_by' =>
                            (int) $user['id'],

                        ':assigned_employee_id' =>
                            $assignedEmployeeId,
                    ]);

                    $batchId =
                        (int) $db->lastInsertId();

                    /*
                     * Добавляем конкретные стекла.
                     */

                    $batchItemInsert = $db->prepare("
                        INSERT INTO production_batch_items (
                            batch_id,
                            glass_id,
                            status
                        )
                        VALUES (
                            :batch_id,
                            :glass_id,
                            'pending'
                        )
                    ");

                    foreach (
                        $glassIds as $glassId
                    ) {

                        $batchItemInsert->execute([
                            ':batch_id' =>
                                $batchId,

                            ':glass_id' =>
                                $glassId,
                        ]);
                    }

                    /*
                     * Стекла переходят в работу
                     * и получают исполнителя.
                     */

                    $glassUpdate = $db->prepare("
                        UPDATE glasses
                        SET
                            status = 'in_progress',
                            employee_id = :employee_id,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :glass_id
                    ");

                    foreach (
                        $glassIds as $glassId
                    ) {

                        $glassUpdate->execute([
                            ':employee_id' =>
                                $assignedEmployeeId,

                            ':glass_id' =>
                                $glassId,
                        ]);
                    }

                    /*
                     * Журнал действий.
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
                            'create_batch',
                            'batch',
                            :entity_id,
                            NULL,
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

                        ':new_value' =>
                            json_encode(
                                [
                                    'order_id' =>
                                        $orderId,

                                    'order_number' =>
                                        $order[
                                            'order_number'
                                        ],

                                    'route_step_id' =>
                                        $routeStepId,

                                    'stage_id' =>
                                        $postStageId,

                                    'created_by' =>
                                        (int) $user[
                                            'id'
                                        ],

                                    'assigned_employee_id' =>
                                        $assignedEmployeeId,

                                    'glass_ids' =>
                                        $glassIds,

                                    'quantity' =>
                                        count(
                                            $glassIds
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

                    $db->commit();

                    $success =
                        'Партия №' .
                        $batchId .
                        ' создана. Исполнитель: ' .
                        $assignedEmployee[
                            'name'
                        ] .
                        '. Стекол: ' .
                        count($glassIds) .
                        '.';

                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {
                        $db->rollBack();
                    }

                    $error =
                        $exception->getMessage();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Статистика участков
|--------------------------------------------------------------------------
*/

$stageStatsStmt = $db->query("
    SELECT
        ps.id,
        ps.name,
        ps.execution_mode,

        COUNT(
            DISTINCT CASE
                WHEN o.status = 'in_production'
                 AND g.status IN (
                     'waiting',
                     'in_progress'
                 )
                 AND NOT EXISTS (
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
                THEN g.id
            END
        ) AS queue_count,

        COALESCE(
            SUM(
                CASE
                    WHEN o.status = 'in_production'
                     AND g.status IN (
                         'waiting',
                         'in_progress'
                     )
                     AND NOT EXISTS (
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
                    THEN (
                        g.width *
                        g.height *
                        g.quantity
                    ) / 1000000.0
                    ELSE 0
                END
            ),
            0
        ) AS queue_area

    FROM production_stages ps

    LEFT JOIN route_steps rs
        ON rs.name = ps.name

    LEFT JOIN glasses g
        ON g.current_step_id = rs.id

    LEFT JOIN orders o
        ON o.id = g.order_id

    WHERE ps.active = 1

    GROUP BY
        ps.id,
        ps.name,
        ps.execution_mode

    ORDER BY ps.id
");

$stageStats =
    $stageStatsStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Очередь выбранного участка
|--------------------------------------------------------------------------
*/

$queue = [];

if ($selectedStage) {

    $queueStmt = $db->prepare("
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
            g.current_location,

            o.id AS order_id,
            o.customer_name,
            o.priority,
            o.planned_date,

            rs.id AS route_step_id,
            rs.step_number,
            rs.name AS stage_name,

            g.created_at,
            g.updated_at

        FROM glasses g

        JOIN orders o
            ON o.id = g.order_id

        JOIN route_steps rs
            ON rs.id = g.current_step_id

        JOIN production_stages ps
            ON ps.name = rs.name

        WHERE ps.id = :stage_id

          AND o.status = 'in_production'

          AND g.status IN (
              'waiting',
              'in_progress'
          )

          AND NOT EXISTS (
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

        ORDER BY
            o.priority DESC,

            CASE
                WHEN o.planned_date IS NULL THEN 1
                ELSE 0
            END,

            o.planned_date ASC,

            g.created_at ASC,

            g.id ASC
    ");

    $queueStmt->execute([
        ':stage_id' => $stageId,
    ]);

    $queue =
        $queueStmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}

/*
|--------------------------------------------------------------------------
| Группировка очереди по заказам
|--------------------------------------------------------------------------
*/

$ordersForBatch = [];

foreach ($queue as $item) {

    $ordersForBatch[
        (int) $item['order_id']
    ][] = $item;
}

/*
|--------------------------------------------------------------------------
| Исполнители выбранного участка
|--------------------------------------------------------------------------
*/

$employeesForStage = [];

if (
    $selectedStage &&
    $canCreateBatch
) {

    $employeeStmt = $db->prepare("
        SELECT
            id,
            name,
            email,
            role,
            stage_id
        FROM users
        WHERE active = 1
          AND stage_id = :stage_id
          AND role IN (
              'employee',
              'section_manager'
          )
        ORDER BY
            name ASC
    ");

    $employeeStmt->execute([
        ':stage_id' => $stageId,
    ]);

    $employeesForStage =
        $employeeStmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}

/*
|--------------------------------------------------------------------------
| Показатели
|--------------------------------------------------------------------------
*/

$queueCount = count($queue);

$queueArea = 0;

foreach ($queue as $item) {

    $queueArea += (
        (
            (int) $item['width'] *
            (int) $item['height'] *
            (int) $item['quantity']
        )
        / 1000000
    );
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
        Производство — OPTIMA GLASS
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>

        .production-page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .production-header {
            margin-bottom: 25px;
        }

        .production-stages {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stage-card {
            display: block;
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            color: inherit;
            text-decoration: none;
        }

        .stage-card.active {
            border-color: #111827;
        }

        .stage-card-name {
            font-weight: 700;
            margin-bottom: 8px;
        }

        .stage-card-meta {
            color: #6b7280;
            font-size: 13px;
        }

        .stage-card-count {
            margin-top: 12px;
            font-size: 26px;
            font-weight: 700;
        }

        .stage-card-queue {
            display: inline-block;
            margin-top: 10px;
            padding: 5px 8px;
            border-radius: 6px;
            background: #f3f4f6;
            font-size: 12px;
        }

        .summary {
            display: grid;
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .summary-value {
            display: block;
            margin-top: 5px;
            font-size: 26px;
            font-weight: 700;
        }

        .message {
            padding: 13px 16px;
            margin-bottom: 20px;
            border-radius: 9px;
        }

        .message-success {
            background: #dcfce7;
            color: #166534;
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .mode-switch {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }

        .mode-switch a {
            padding: 9px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
        }

        .mode-switch a.active {
            border-color: #111827;
            font-weight: 700;
        }

        .queue-card {
            margin-bottom: 25px;
            padding: 24px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .queue-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 20px;
        }

        .queue-table-wrap {
            overflow-x: auto;
        }

        .queue-table {
            width: 100%;
            min-width: 1000px;
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
            padding: 45px 20px;
            text-align: center;
        }

        .batch-order {
            margin-bottom: 20px;
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }

        .batch-order:last-child {
            margin-bottom: 0;
        }

        .batch-order-header {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .batch-meta {
            color: #6b7280;
            font-size: 13px;
        }

        .batch-glasses {
            display: grid;
            grid-template-columns:
                repeat(auto-fill, minmax(240px, 1fr));
            gap: 10px;
        }

        .batch-glass {
            display: flex;
            gap: 10px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 9px;
            cursor: pointer;
        }

        .batch-glass:hover {
            border-color: #9ca3af;
        }

        .batch-glass input {
            margin-top: 4px;
        }

        .batch-actions {
            display: grid;
            grid-template-columns:
                minmax(220px, 350px) auto;
            gap: 12px;
            align-items: end;
            margin-top: 18px;
        }

        .batch-actions select {
            width: 100%;
            min-height: 42px;
            padding: 9px 10px;
        }

        .batch-actions button {
            min-height: 42px;
            padding: 9px 16px;
        }

        .batch-actions-help {
            grid-column: 1 / -1;
            color: #6b7280;
            font-size: 13px;
        }

        .no-employees {
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            color: #6b7280;
        }

        @media (max-width: 800px) {

            .summary {
                grid-template-columns: 1fr;
            }

            .queue-header,
            .batch-order-header {
                flex-direction: column;
            }

            .batch-actions {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="production-page">

    <header class="production-header">

        <h1>Производство</h1>

        <p>

            <?= e($user['name']) ?>

            <?php if (!empty($user['stage_name'])): ?>

                · <?= e($user['stage_name']) ?>

            <?php endif; ?>

        </p>

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


    <?php if ($canAccessAllStages): ?>

        <section class="production-stages">

            <?php foreach (
                $stageStats as $stage
            ): ?>

                <a
                    class="stage-card <?= $stageId === (int) $stage['id'] ? 'active' : '' ?>"
                    href="/production.php?stage_id=<?= (int) $stage['id'] ?>"
                >

                    <div class="stage-card-name">
                        <?= e(
                            $stage['name']
                        ) ?>
                    </div>

                    <div class="stage-card-meta">
                        <?= e(
                            executionModeLabel(
                                $stage[
                                    'execution_mode'
                                ]
                            )
                        ) ?>
                    </div>

                    <span class="stage-card-queue">
                        В очереди:
                        <?= (int) $stage['queue_count'] ?>
                    </span>

                    <div class="stage-card-count">

                        <?= number_format(
                            (float)
                            $stage['queue_area'],
                            2,
                            ',',
                            ' '
                        ) ?>

                        м²

                    </div>

                </a>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>


    <?php if ($selectedStage): ?>

        <section class="summary">

            <div class="summary-card">

                <div>
                    Участок
                </div>

                <span class="summary-value">
                    <?= e(
                        $selectedStage['name']
                    ) ?>
                </span>

            </div>


            <div class="summary-card">

                <div>
                    Стекол в очереди
                </div>

                <span class="summary-value">
                    <?= $queueCount ?>
                </span>

            </div>


            <div class="summary-card">

                <div>
                    Площадь очереди
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

        </section>


        <?php if (
            in_array(
                $selectedStage[
                    'execution_mode'
                ],
                ['both', 'batch'],
                true
            )
        ): ?>

            <nav class="mode-switch">

                <a
                    href="/production.php?stage_id=<?= (int) $stageId ?>&mode=single"
                    class="<?= $mode === 'single' ? 'active' : '' ?>"
                >
                    Поштучно
                </a>

                <a
                    href="/production.php?stage_id=<?= (int) $stageId ?>&mode=batch"
                    class="<?= $mode === 'batch' ? 'active' : '' ?>"
                >
                    Партией
                </a>

            </nav>

        <?php endif; ?>


        <?php if ($mode === 'batch'): ?>

            <section class="queue-card">

                <div class="queue-header">

                    <div>

                        <h2>
                            Создание партии
                        </h2>

                        <p>
                            Выберите конкретные стекла
                            одного заказа и исполнителя.
                        </p>

                    </div>

                    <div>
                        <?= e(
                            executionModeLabel(
                                $selectedStage[
                                    'execution_mode'
                                ]
                            )
                        ) ?>
                    </div>

                </div>


                <?php if (!$canCreateBatch): ?>

                    <div class="empty">

                        У вас нет права создавать партии.

                        <br>

                        Можно только просматривать
                        производственную очередь.

                    </div>

                <?php elseif (!$ordersForBatch): ?>

                    <div class="empty">

                        Нет стекол, доступных
                        для создания партии.

                    </div>

                <?php else: ?>


                    <?php if (
                        !$employeesForStage
                    ): ?>

                        <div class="no-employees">

                            На участке
                            <strong>
                                <?= e(
                                    $selectedStage[
                                        'name'
                                    ]
                                ) ?>
                            </strong>

                            сейчас нет активных
                            сотрудников,
                            которым можно назначить партию.

                        </div>

                    <?php endif; ?>


                    <?php foreach (
                        $ordersForBatch
                        as $orderId => $orderGlasses
                    ): ?>

                        <?php

                        $orderArea = 0;

                        foreach (
                            $orderGlasses
                            as $glass
                        ) {

                            $orderArea += (
                                (
                                    (int)
                                    $glass['width']
                                    *
                                    (int)
                                    $glass['height']
                                    *
                                    (int)
                                    $glass['quantity']
                                )
                                / 1000000
                            );
                        }

                        ?>

                        <div class="batch-order">

                            <div class="batch-order-header">

                                <div>

                                    <h3>

                                        Заказ
                                        <?= e(
                                            $orderGlasses[
                                                0
                                            ][
                                                'order_number'
                                            ]
                                        ) ?>

                                    </h3>

                                    <?php if (
                                        $orderGlasses[
                                            0
                                        ][
                                            'customer_name'
                                        ]
                                    ): ?>

                                        <div class="batch-meta">

                                            <?= e(
                                                $orderGlasses[
                                                    0
                                                ][
                                                    'customer_name'
                                                ]
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </div>


                                <div class="batch-meta">

                                    Доступно:

                                    <?= count(
                                        $orderGlasses
                                    ) ?>

                                    стекол

                                    ·

                                    <?= number_format(
                                        $orderArea,
                                        2,
                                        ',',
                                        ' '
                                    ) ?>

                                    м²

                                </div>

                            </div>


                            <?php if (
                                $employeesForStage
                            ): ?>

                                <form
                                    method="post"
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
                                        value="create_batch"
                                    >

                                    <input
                                        type="hidden"
                                        name="stage_id"
                                        value="<?= (int) $stageId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= (int) $orderId ?>"
                                    >


                                    <div class="batch-glasses">

                                        <?php foreach (
                                            $orderGlasses
                                            as $item
                                        ): ?>

                                            <label
                                                class="batch-glass"
                                            >

                                                <input
                                                    type="checkbox"
                                                    name="glass_ids[]"
                                                    value="<?= (int) $item['glass_id'] ?>"
                                                >

                                                <span>

                                                    <strong>
                                                        <?= e(
                                                            $item[
                                                                'code'
                                                            ]
                                                        ) ?>
                                                    </strong>

                                                    <br>

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

                                                    <?php if (
                                                        $item[
                                                            'glass_type'
                                                        ]
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

                                                </span>

                                            </label>

                                        <?php endforeach; ?>

                                    </div>


                                    <div class="batch-actions">

                                        <div>

                                            <label
                                                for="employee_<?= (int) $orderId ?>"
                                            >
                                                Исполнитель
                                            </label>

                                            <select
                                                id="employee_<?= (int) $orderId ?>"
                                                name="assigned_employee_id"
                                                required
                                            >

                                                <option
                                                    value=""
                                                >
                                                    Выберите сотрудника
                                                </option>

                                                <?php foreach (
                                                    $employeesForStage
                                                    as $employee
                                                ): ?>

                                                    <option
                                                        value="<?= (int) $employee['id'] ?>"
                                                    >

                                                        <?= e(
                                                            $employee[
                                                                'name'
                                                            ]
                                                        ) ?>

                                                        <?php if (
                                                            $employee[
                                                                'role'
                                                            ] ===
                                                            'section_manager'
                                                        ): ?>

                                                            —
                                                            начальник участка

                                                        <?php endif; ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>


                                        <button
                                            type="submit"
                                            onclick="return confirm('Создать партию из выбранных стекол и назначить её исполнителю?');"
                                        >
                                            Создать партию
                                        </button>


                                        <div
                                            class="batch-actions-help"
                                        >

                                            Выберите конкретные
                                            номера стекол этого заказа.

                                        </div>

                                    </div>

                                </form>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </section>


        <?php else: ?>


            <section class="queue-card">

                <div class="queue-header">

                    <div>

                        <h2>
                            Очередь —
                            <?= e(
                                $selectedStage[
                                    'name'
                                ]
                            ) ?>
                        </h2>

                        <p>
                            Приоритет →
                            плановая дата →
                            время ожидания
                        </p>

                    </div>

                    <div>

                        <?= e(
                            executionModeLabel(
                                $selectedStage[
                                    'execution_mode'
                                ]
                            )
                        ) ?>

                    </div>

                </div>


                <?php if (!$queue): ?>

                    <div class="empty">

                        На этом участке сейчас
                        нет доступных работ.

                    </div>

                <?php else: ?>

                    <div class="queue-table-wrap">

                        <table class="queue-table">

                            <thead>

                                <tr>
                                    <th>#</th>
                                    <th>Стекло</th>
                                    <th>Заказ</th>
                                    <th>Приоритет</th>
                                    <th>Дата</th>
                                    <th>Размер</th>
                                    <th>Толщина</th>
                                    <th>Площадь</th>
                                    <th>Статус</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach (
                                $queue
                                as $index => $item
                            ): ?>

                                <tr>

                                    <td>
                                        <?= $index + 1 ?>
                                    </td>

                                    <td>

                                        <div
                                            class="glass-code"
                                        >
                                            <?= e(
                                                $item[
                                                    'code'
                                                ]
                                            ) ?>
                                        </div>

                                        <?php if (
                                            $item[
                                                'glass_type'
                                            ]
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
                                            $item[
                                                'customer_name'
                                            ]
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
                                                    ] *

                                                    (int)
                                                    $item[
                                                        'height'
                                                    ] *

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

                                    <td>

                                        <?= e(
                                            statusLabel(
                                                $item[
                                                    'status'
                                                ]
                                            )
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        <?php endif; ?>

    <?php elseif ($canAccessAllStages): ?>

        <section class="queue-card">

            <div class="empty">

                <h2>
                    Выберите производственный участок
                </h2>

                <p>
                    Для просмотра очереди
                    выберите участок выше.
                </p>

            </div>

        </section>

    <?php endif; ?>

</main>

</body>

</html>
