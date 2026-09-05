<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

require_permission('orders.start_production', $user);

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
        'new' => 'Новый',
        'planning' => 'Планирование',
        'planned' => 'Запланирован',
        'in_production' => 'В производстве',
        'partially_completed' => 'Частично готов',
        'completed' => 'Готов',
        'ready_for_shipping' => 'Готов к отгрузке',
        'shipped' => 'Отгружен',
        'installed' => 'Установлен',
        'cancelled' => 'Отменён',
        default => $status,
    };
}

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_manager'])) {
    $_SESSION['csrf_manager'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_manager'];

/*
|--------------------------------------------------------------------------
| Обработка действий
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $csrfToken,
        $_POST['csrf_token'] ?? ''
    )) {
        $error = 'Ошибка проверки безопасности.';
    } else {

        $action = $_POST['action'] ?? '';

        /*
         * Запуск заказа в производство
         */
        if ($action === 'start_production') {

            $orderId = (int) ($_POST['order_id'] ?? 0);

            if ($orderId <= 0) {

                $error = 'Некорректный заказ.';

            } else {

                try {

                    $db->beginTransaction();

                    $orderStmt = $db->prepare("
                        SELECT
                            id,
                            order_number,
                            status,
                            priority,
                            planned_date
                        FROM orders
                        WHERE id = :id
                        LIMIT 1
                    ");

                    $orderStmt->execute([
                        ':id' => $orderId,
                    ]);

                    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$order) {
                        throw new RuntimeException(
                            'Заказ не найден.'
                        );
                    }

                    if (in_array(
                        $order['status'],
                        [
                            'in_production',
                            'partially_completed',
                            'completed',
                            'ready_for_shipping',
                            'shipped',
                            'installed',
                            'cancelled',
                        ],
                        true
                    )) {
                        throw new RuntimeException(
                            'Этот заказ нельзя запустить в производство из текущего статуса.'
                        );
                    }

                    $oldStatus = $order['status'];

                    /*
                     * Переводим заказ в производство.
                     */
                    $updateOrder = $db->prepare("
                        UPDATE orders
                        SET
                            status = 'in_production',
                            production_started_at = CURRENT_TIMESTAMP,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");

                    $updateOrder->execute([
                        ':id' => $orderId,
                    ]);

                    /*
                     * Доступные производственные стекла
                     * переводим в состояние ожидания работы.
                     *
                     * Складской учёт здесь не ведём.
                     */
                    $updateGlasses = $db->prepare("
                        UPDATE glasses
                        SET
                            status = 'waiting',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE order_id = :order_id
                          AND current_step_id IS NOT NULL
                          AND status IN ('created', 'waiting')
                    ");

                    $updateGlasses->execute([
                        ':order_id' => $orderId,
                    ]);

                    /*
                     * Аудит.
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
                            'start_production',
                            'order',
                            :entity_id,
                            :old_value,
                            :new_value,
                            :ip_address,
                            :user_agent
                        )
                    ");

                    $audit->execute([
                        ':user_id' => (int) $user['id'],
                        ':entity_id' => $orderId,
                        ':old_value' => json_encode([
                            'status' => $oldStatus,
                        ], JSON_UNESCAPED_UNICODE),
                        ':new_value' => json_encode([
                            'status' => 'in_production',
                            'production_started_at' => date(
                                'Y-m-d H:i:s'
                            ),
                        ], JSON_UNESCAPED_UNICODE),
                        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ]);

                    $db->commit();

                    $success =
                        'Заказ ' .
                        e($order['order_number']) .
                        ' запущен в производство.';

                } catch (Throwable $exception) {

                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    $error = $exception->getMessage();
                }
            }
        }

        /*
         * Изменение приоритета.
         */
        if ($action === 'change_priority') {

            $orderId = (int) ($_POST['order_id'] ?? 0);
            $newPriority = (int) ($_POST['priority'] ?? 1);

            if (
                $orderId <= 0 ||
                !in_array($newPriority, [1, 2, 3], true)
            ) {
                $error = 'Некорректные данные.';
            } else {

                try {

                    $db->beginTransaction();

                    $stmt = $db->prepare("
                        SELECT id, order_number, priority
                        FROM orders
                        WHERE id = :id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        ':id' => $orderId,
                    ]);

                    $order = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$order) {
                        throw new RuntimeException(
                            'Заказ не найден.'
                        );
                    }

                    $oldPriority = (int) $order['priority'];

                    if ($oldPriority !== $newPriority) {

                        $update = $db->prepare("
                            UPDATE orders
                            SET
                                priority = :priority,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");

                        $update->execute([
                            ':priority' => $newPriority,
                            ':id' => $orderId,
                        ]);

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
                                'change_priority',
                                'order',
                                :entity_id,
                                :old_value,
                                :new_value,
                                :ip_address,
                                :user_agent
                            )
                        ");

                        $audit->execute([
                            ':user_id' => (int) $user['id'],
                            ':entity_id' => $orderId,
                            ':old_value' => (string) $oldPriority,
                            ':new_value' => (string) $newPriority,
                            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]);
                    }

                    $db->commit();

                    $success =
                        'Приоритет заказа ' .
                        e($order['order_number']) .
                        ' изменён.';

                } catch (Throwable $exception) {

                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    $error = $exception->getMessage();
                }
            }
        }

        /*
         * Изменение плановой даты.
         */
        if ($action === 'change_date') {

            $orderId = (int) ($_POST['order_id'] ?? 0);
            $plannedDate = trim($_POST['planned_date'] ?? '');

            if ($orderId <= 0) {

                $error = 'Некорректный заказ.';

            } elseif (
                $plannedDate !== '' &&
                !preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $plannedDate
                )
            ) {

                $error = 'Некорректная дата.';

            } else {

                try {

                    $stmt = $db->prepare("
                        SELECT id, order_number, planned_date
                        FROM orders
                        WHERE id = :id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        ':id' => $orderId,
                    ]);

                    $order = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$order) {
                        throw new RuntimeException(
                            'Заказ не найден.'
                        );
                    }

                    $oldDate = $order['planned_date'];

                    $update = $db->prepare("
                        UPDATE orders
                        SET
                            planned_date = :planned_date,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");

                    $update->execute([
                        ':planned_date' =>
                            $plannedDate !== ''
                                ? $plannedDate
                                : null,
                        ':id' => $orderId,
                    ]);

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
                            'change_planned_date',
                            'order',
                            :entity_id,
                            :old_value,
                            :new_value,
                            :ip_address,
                            :user_agent
                        )
                    ");

                    $audit->execute([
                        ':user_id' => (int) $user['id'],
                        ':entity_id' => $orderId,
                        ':old_value' => $oldDate,
                        ':new_value' =>
                            $plannedDate !== ''
                                ? $plannedDate
                                : null,
                        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ]);

                    $success =
                        'Плановая дата заказа ' .
                        e($order['order_number']) .
                        ' изменена.';

                } catch (Throwable $exception) {

                    $error = $exception->getMessage();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Фильтр
|--------------------------------------------------------------------------
*/

$statusFilter = $_GET['status'] ?? 'all';

$allowedStatuses = [
    'all',
    'new',
    'planning',
    'planned',
    'in_production',
    'partially_completed',
    'completed',
    'ready_for_shipping',
    'shipped',
    'installed',
    'cancelled',
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

/*
|--------------------------------------------------------------------------
| Статистика
|--------------------------------------------------------------------------
*/

$statsStmt = $db->query("
    SELECT
        SUM(CASE WHEN priority = 3 THEN 1 ELSE 0 END)
            AS critical_count,

        SUM(CASE WHEN priority = 2 THEN 1 ELSE 0 END)
            AS urgent_count,

        SUM(CASE WHEN priority = 1 THEN 1 ELSE 0 END)
            AS normal_count,

        SUM(
            CASE
                WHEN status = 'in_production'
                THEN 1
                ELSE 0
            END
        ) AS production_count,

        SUM(
            CASE
                WHEN status NOT IN (
                    'completed',
                    'cancelled',
                    'installed',
                    'shipped'
                )
                THEN 1
                ELSE 0
            END
        ) AS active_count

    FROM orders
");

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Заказы
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        o.id,
        o.order_number,
        o.customer_name,
        o.comment,
        o.status,
        o.priority,
        o.planned_date,
        o.production_started_at,
        o.production_completed_at,
        o.created_at,

        COUNT(DISTINCT g.id) AS glass_count,

        COALESCE(
            (
                SELECT SUM(
                    oi.width *
                    oi.height *
                    oi.quantity
                ) / 1000000.0
                FROM order_items oi
                WHERE oi.order_id = o.id
            ),
            0
        ) AS area_m2

    FROM orders o

    LEFT JOIN glasses g
        ON g.order_id = o.id
";

$params = [];

if ($statusFilter !== 'all') {
    $sql .= " WHERE o.status = :status ";
    $params[':status'] = $statusFilter;
}

$sql .= "
    GROUP BY o.id

    ORDER BY
        o.priority DESC,

        CASE
            WHEN o.planned_date IS NULL THEN 1
            ELSE 0
        END,

        o.planned_date ASC,

        o.id DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Производство — OPTIMA GLASS</title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>
.orders-table th,
.order-number {
            font-weight: 700;
        }

        .order-controls {
            display: grid;
            gap: 8px;
            min-width: 190px;
        }

        .order-controls form {
            display: flex;
            gap: 6px;
        }

        .order-controls select,
        .order-controls input {
            min-width: 0;
            width: 100%;
            padding: 7px 8px;
        }
@media (max-width: 900px) {
}

        @media (max-width: 600px) {
}

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="manager-page">

    <header class="manager-header">

        <h1>Производство</h1>

        <p>
            Заказы и управление производственным планом.
        </p>

    </header>


    <?php if ($error !== ''): ?>

        <div class="manager-message manager-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($success !== ''): ?>

        <div class="manager-message manager-success">
            <?= e($success) ?>
        </div>

    <?php endif; ?>


    <section class="manager-stats">

        <div class="manager-stat">
            Критические
            <span class="manager-stat-number">
                <?= (int) ($stats['critical_count'] ?? 0) ?>
            </span>
        </div>

        <div class="manager-stat">
            Срочные
            <span class="manager-stat-number">
                <?= (int) ($stats['urgent_count'] ?? 0) ?>
            </span>
        </div>

        <div class="manager-stat">
            Обычные
            <span class="manager-stat-number">
                <?= (int) ($stats['normal_count'] ?? 0) ?>
            </span>
        </div>

        <div class="manager-stat">
            В производстве
            <span class="manager-stat-number">
                <?= (int) ($stats['production_count'] ?? 0) ?>
            </span>
        </div>

        <div class="manager-stat">
            Активные
            <span class="manager-stat-number">
                <?= (int) ($stats['active_count'] ?? 0) ?>
            </span>
        </div>

    </section>


    <section class="manager-card">

        <h2>Заказы</h2>

        <div class="manager-filters">

            <?php foreach ($allowedStatuses as $status): ?>

                <a
                    href="/manager.php?status=<?= urlencode($status) ?>"
                    class="<?= $statusFilter === $status ? 'active' : '' ?>"
                >
                    <?= e(
                        $status === 'all'
                            ? 'Все'
                            : statusLabel($status)
                    ) ?>
                </a>

            <?php endforeach; ?>

        </div>


        <?php if (!$orders): ?>

            <div class="empty-state">
                Заказов пока нет.
            </div>

        <?php else: ?>

            <div class="orders-table-wrap">

                <table class="orders-table">

                    <thead>

                        <tr>
                            <th>Заказ</th>
                            <th>Клиент</th>
                            <th>Статус</th>
                            <th>Приоритет</th>
                            <th>Плановая дата</th>
                            <th>Количество</th>
                            <th>Площадь</th>
                            <th>Управление</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($orders as $order): ?>

                        <tr>

                            <td>

                                <div class="order-number">
                                    <?= e($order['order_number']) ?>
                                </div>

                                <?php if (
                                    $order['comment']
                                ): ?>

                                    <small>
                                        <?= e($order['comment']) ?>
                                    </small>

                                <?php endif; ?>

                            </td>


                            <td>
                                <?= e(
                                    $order['customer_name']
                                ) ?>
                            </td>


                            <td>

                                <span class="status">
                                    <?= e(
                                        statusLabel(
                                            $order['status']
                                        )
                                    ) ?>
                                </span>

                                <?php if (
                                    $order['production_started_at']
                                ): ?>

                                    <br>

                                    <small>
                                        Запущен:
                                        <?= e(
                                            $order[
                                                'production_started_at'
                                            ]
                                        ) ?>
                                    </small>

                                <?php endif; ?>

                            </td>


                            <td>

                                <div class="order-controls">

                                    <form method="post">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="change_priority"
                                        >

                                        <input
                                            type="hidden"
                                            name="order_id"
                                            value="<?= (int) $order['id'] ?>"
                                        >

                                        <select name="priority">

                                            <option
                                                value="3"
                                                <?= (int) $order['priority'] === 3
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                🔴 Критический
                                            </option>

                                            <option
                                                value="2"
                                                <?= (int) $order['priority'] === 2
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                🟠 Срочный
                                            </option>

                                            <option
                                                value="1"
                                                <?= (int) $order['priority'] === 1
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                🟢 Обычный
                                            </option>

                                        </select>

                                        <button type="submit">
                                            Сохранить
                                        </button>

                                    </form>

                                </div>

                            </td>


                            <td>

                                <div class="order-controls">

                                    <form method="post">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="change_date"
                                        >

                                        <input
                                            type="hidden"
                                            name="order_id"
                                            value="<?= (int) $order['id'] ?>"
                                        >

                                        <input
                                            type="date"
                                            name="planned_date"
                                            value="<?= e(
                                                $order['planned_date']
                                            ) ?>"
                                        >

                                        <button type="submit">
                                            Сохранить
                                        </button>

                                    </form>

                                </div>

                            </td>


                            <td>
                                <?= (int) $order['glass_count'] ?>
                            </td>


                            <td>
                                <?= number_format(
                                    (float) $order['area_m2'],
                                    2,
                                    ',',
                                    ' '
                                ) ?>
                                м²
                            </td>


                            <td>

                                <div class="order-actions">

                                    <?php if (
                                        in_array(
                                            $order['status'],
                                            [
                                                'new',
                                                'planning',
                                                'planned',
                                            ],
                                            true
                                        )
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
                                                value="start_production"
                                            >

                                            <input
                                                type="hidden"
                                                name="order_id"
                                                value="<?= (int) $order['id'] ?>"
                                            >

                                            <button type="submit">
                                                Запустить в производство
                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <a
                                        href="/order.php?id=<?= (int) $order['id'] ?>"
                                    >
                                        Открыть
                                    </a>

                                </div>

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
