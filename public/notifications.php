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

function notificationTypeLabel(string $type): string
{
    return match ($type) {
        'glass_moved' => 'Стекло перемещено',
        'glass_rejected' => 'Брак',
        'critical_order' => 'Критический заказ',
        'batch_assigned' => 'Назначена партия',
        'batch_completed' => 'Партия завершена',
        'production_delay' => 'Задержка производства',
        'order_completed' => 'Заказ завершён',
        default => 'Уведомление',
    };
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_notifications'])) {
    $_SESSION['csrf_notifications'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_notifications'];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Действия
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

        if ($action === 'read') {

            $notificationId = (int) (
                $_POST['notification_id'] ?? 0
            );

            if ($notificationId > 0) {

                $stmt = $db->prepare("
                    UPDATE notifications
                    SET
                        status = 'read',
                        read_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                      AND user_id = :user_id
                ");

                $stmt->execute([
                    ':id' => $notificationId,
                    ':user_id' => (int) $user['id'],
                ]);
            }

        } elseif ($action === 'read_all') {

            $stmt = $db->prepare("
                UPDATE notifications
                SET
                    status = 'read',
                    read_at = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
                  AND status = 'unread'
            ");

            $stmt->execute([
                ':user_id' => (int) $user['id'],
            ]);

            $success = 'Все уведомления отмечены как прочитанные.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Фильтр
|--------------------------------------------------------------------------
*/

$filter = $_GET['filter'] ?? 'all';

if (!in_array(
    $filter,
    ['all', 'unread', 'read'],
    true
)) {
    $filter = 'all';
}

/*
|--------------------------------------------------------------------------
| Количество непрочитанных
|--------------------------------------------------------------------------
*/

$unreadStmt = $db->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE user_id = :user_id
      AND status = 'unread'
");

$unreadStmt->execute([
    ':user_id' => (int) $user['id'],
]);

$unreadCount = (int) $unreadStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Уведомления
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        type,
        title,
        message,
        entity_type,
        entity_id,
        channel,
        status,
        read_at,
        sent_at,
        created_at
    FROM notifications
    WHERE user_id = :user_id
";

$params = [
    ':user_id' => (int) $user['id'],
];

if ($filter === 'unread') {

    $sql .= "
        AND status = 'unread'
    ";

} elseif ($filter === 'read') {

    $sql .= "
        AND status = 'read'
    ";
}

$sql .= "
    ORDER BY
        CASE
            WHEN status = 'unread' THEN 0
            ELSE 1
        END,
        created_at DESC,
        id DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$notifications = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);

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
        Уведомления — OPTIMA GLASS
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >

    <style>

        .notifications-page {
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 25px;
        }

        .notifications-header h1 {
            margin-bottom: 6px;
        }

        .notifications-subtitle {
            color: #6b7280;
        }

        .notifications-count {
            display: inline-block;
            margin-top: 8px;
            padding: 5px 9px;
            border-radius: 7px;
            background: #111827;
            color: #fff;
            font-size: 12px;
        }

        .notification-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .notification-actions a,
        .notification-actions button {
            padding: 9px 13px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .notification-actions .active {
            background: #111827;
            color: #fff;
            border-color: #111827;
        }

        .notification-list {
            display: grid;
            gap: 12px;
        }

        .notification {
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }

        .notification.unread {
            border-left: 4px solid #111827;
        }

        .notification-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
        }

        .notification-title {
            font-weight: 700;
            font-size: 17px;
        }

        .notification-type {
            margin-top: 4px;
            color: #6b7280;
            font-size: 12px;
        }

        .notification-message {
            margin-top: 12px;
            line-height: 1.5;
        }

        .notification-date {
            margin-top: 12px;
            color: #6b7280;
            font-size: 12px;
        }

        .notification-read {
            flex: 0 0 auto;
        }

        .notification-read button {
            padding: 7px 10px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            background: #fff;
            cursor: pointer;
        }

        .empty {
            padding: 50px 20px;
            text-align: center;
            color: #6b7280;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }

        .message {
            margin-bottom: 20px;
            padding: 13px 15px;
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

        @media (max-width: 700px) {

            .notifications-header {
                flex-direction: column;
            }

            .notification-top {
                flex-direction: column;
            }

        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="notifications-page">

    <header class="notifications-header">

        <div>

            <h1>
                Уведомления
            </h1>

            <div class="notifications-subtitle">

                <?= e($user['name']) ?>

                <?php if (!empty($user['stage_name'])): ?>

                    · <?= e($user['stage_name']) ?>

                <?php endif; ?>

            </div>

            <?php if ($unreadCount > 0): ?>

                <span class="notifications-count">

                    Непрочитанных:
                    <?= $unreadCount ?>

                </span>

            <?php endif; ?>

        </div>


        <?php if ($notifications): ?>

            <div class="notification-actions">

                <a
                    href="/notifications.php?filter=all"
                    class="<?= $filter === 'all'
                        ? 'active'
                        : '' ?>"
                >
                    Все
                </a>

                <a
                    href="/notifications.php?filter=unread"
                    class="<?= $filter === 'unread'
                        ? 'active'
                        : '' ?>"
                >
                    Непрочитанные
                </a>

                <a
                    href="/notifications.php?filter=read"
                    class="<?= $filter === 'read'
                        ? 'active'
                        : '' ?>"
                >
                    Прочитанные
                </a>

                <?php if ($unreadCount > 0): ?>

                    <form method="post">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="read_all"
                        >

                        <button type="submit">
                            Прочитать все
                        </button>

                    </form>

                <?php endif; ?>

            </div>

        <?php endif; ?>

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


    <?php if (!$notifications): ?>

        <div class="empty">

            <?php if ($filter === 'unread'): ?>

                Непрочитанных уведомлений нет.

            <?php elseif ($filter === 'read'): ?>

                Прочитанных уведомлений нет.

            <?php else: ?>

                Уведомлений пока нет.

            <?php endif; ?>

        </div>

    <?php else: ?>

        <div class="notification-list">

            <?php foreach (
                $notifications
                as $notification
            ): ?>

                <article
                    class="notification <?= $notification['status'] === 'unread'
                        ? 'unread'
                        : '' ?>"
                >

                    <div class="notification-top">

                        <div>

                            <div class="notification-title">
                                <?= e(
                                    $notification[
                                        'title'
                                    ]
                                ) ?>
                            </div>

                            <div class="notification-type">

                                <?= e(
                                    notificationTypeLabel(
                                        $notification[
                                            'type'
                                        ]
                                    )
                                ) ?>

                            </div>

                        </div>


                        <?php if (
                            $notification['status']
                            === 'unread'
                        ): ?>

                            <form
                                method="post"
                                class="notification-read"
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
                                    value="read"
                                >

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?= (int) $notification['id'] ?>"
                                >

                                <button type="submit">
                                    Прочитать
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>


                    <div class="notification-message">

                        <?= nl2br(
                            e(
                                $notification[
                                    'message'
                                ]
                            )
                        ) ?>

                    </div>


                    <div class="notification-date">

                        <?= e(
                            $notification[
                                'created_at'
                            ]
                        ) ?>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>

</body>

</html>
