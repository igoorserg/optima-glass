<?php

require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Доступные события уведомлений
|--------------------------------------------------------------------------
*/

function notificationEventTypes(): array
{
    return [
        'glass_moved' => 'Стекло перешло на следующий участок',

        'glass_rejected' => 'Брак',

        'critical_order' => 'Критический заказ',

        'batch_assigned' => 'Назначена партия',

        'batch_completed' => 'Партия завершена',

        'production_delay' => 'Задержка производства',

        'order_completed' => 'Заказ завершён',
    ];
}

/*
|--------------------------------------------------------------------------
| Создание уведомления
|--------------------------------------------------------------------------
*/

function createNotification(
    PDO $db,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null,
    string $channel = 'in_app'
): int {

    $stmt = $db->prepare("
        INSERT INTO notifications (
            user_id,
            type,
            title,
            message,
            entity_type,
            entity_id,
            channel,
            status,
            created_at
        )
        VALUES (
            :user_id,
            :type,
            :title,
            :message,
            :entity_type,
            :entity_id,
            :channel,
            'unread',
            CURRENT_TIMESTAMP
        )
    ");

    $stmt->execute([
        ':user_id' =>
            $userId,

        ':type' =>
            $type,

        ':title' =>
            $title,

        ':message' =>
            $message,

        ':entity_type' =>
            $entityType,

        ':entity_id' =>
            $entityId,

        ':channel' =>
            $channel,
    ]);

    return (int) $db->lastInsertId();
}

/*
|--------------------------------------------------------------------------
| Настройки пользователя для конкретного события
|--------------------------------------------------------------------------
*/

function getNotificationSettings(
    PDO $db,
    int $userId,
    string $eventType
): array {

    $stmt = $db->prepare("
        SELECT
            in_app,
            telegram,
            email,
            quiet_hours_start,
            quiet_hours_end
        FROM notification_settings
        WHERE user_id = :user_id
          AND event_type = :event_type
        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' =>
            $userId,

        ':event_type' =>
            $eventType,
    ]);

    $settings =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    /*
     * Если пользователь ещё не имеет
     * настройки события — используем
     * безопасные значения по умолчанию.
     */

    if (!$settings) {

        return [
            'in_app' =>
                1,

            'telegram' =>
                0,

            'email' =>
                0,

            'quiet_hours_start' =>
                null,

            'quiet_hours_end' =>
                null,
        ];
    }

    return $settings;
}

/*
|--------------------------------------------------------------------------
| Проверка тихих часов
|--------------------------------------------------------------------------
*/

function notificationInsideQuietHours(
    ?string $start,
    ?string $end
): bool {

    if (
        !$start ||
        !$end
    ) {
        return false;
    }

    $now =
        date('H:i');

    /*
     * Обычный диапазон:
     * 09:00 → 18:00
     */
    if ($start < $end) {

        return
            $now >= $start &&
            $now < $end;
    }

    /*
     * Диапазон через полночь:
     * 22:00 → 07:00
     */
    return
        $now >= $start ||
        $now < $end;
}

/*
|--------------------------------------------------------------------------
| Уведомить конкретного пользователя
|--------------------------------------------------------------------------
|
| Внутреннее уведомление создаём только если
| пользователь разрешил in_app.
|
| Telegram пока НЕ отправляется здесь.
| Мы подключим отдельный Telegram-сервис,
| чтобы ошибка Telegram никогда не ломала
| производственную операцию.
|
*/

function notifyUser(
    PDO $db,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null
): ?int {

    /*
     * Проверяем, что тип события существует.
     */

    $eventTypes =
        notificationEventTypes();

    if (
        !array_key_exists(
            $type,
            $eventTypes
        )
    ) {
        throw new InvalidArgumentException(
            'Неизвестный тип уведомления: '
            . $type
        );
    }

    /*
     * Получаем настройки.
     */

    $settings =
        getNotificationSettings(
            $db,
            $userId,
            $type
        );

    /*
     * Внутренние уведомления отключены.
     */

    if (
        (int) $settings['in_app']
        !== 1
    ) {
        return null;
    }

    /*
     * Создаём уведомление.
     */

    return createNotification(
        $db,
        $userId,
        $type,
        $title,
        $message,
        $entityType,
        $entityId,
        'in_app'
    );
}

/*
|--------------------------------------------------------------------------
| Получатели участка
|--------------------------------------------------------------------------
|
| Получают:
| - сотрудники участка;
| - начальник участка;
| - админ;
| - менеджер;
| - суперадмин.
|
*/

function notificationRecipientsForStage(
    PDO $db,
    int $stageId
): array {

    $stmt = $db->prepare("
        SELECT
            id,
            name,
            email,
            role,
            active,
            stage_id
        FROM users
        WHERE active = 1
          AND (
              stage_id = :stage_id
              OR role IN (
                  'superadmin',
                  'admin',
                  'manager'
              )
          )
        ORDER BY
            CASE
                WHEN role = 'superadmin' THEN 1
                WHEN role = 'admin' THEN 2
                WHEN role = 'manager' THEN 3
                WHEN role = 'section_manager' THEN 4
                ELSE 5
            END,
            name ASC
    ");

    $stmt->execute([
        ':stage_id' =>
            $stageId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

/*
|--------------------------------------------------------------------------
| Уведомить пользователей участка
|--------------------------------------------------------------------------
*/

function notifyStage(
    PDO $db,
    int $stageId,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null
): array {

    $recipients =
        notificationRecipientsForStage(
            $db,
            $stageId
        );

    $notificationIds = [];

    foreach ($recipients as $recipient) {

        $notificationId =
            notifyUser(
                $db,
                (int) $recipient['id'],
                $type,
                $title,
                $message,
                $entityType,
                $entityId
            );

        if ($notificationId !== null) {

            $notificationIds[] =
                $notificationId;
        }
    }

    return $notificationIds;
}

/*
|--------------------------------------------------------------------------
| Уведомление исполнителю партии
|--------------------------------------------------------------------------
*/

function notifyAssignedEmployee(
    PDO $db,
    int $employeeId,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null
): ?int {

    return notifyUser(
        $db,
        $employeeId,
        $type,
        $title,
        $message,
        $entityType,
        $entityId
    );
}

/*
|--------------------------------------------------------------------------
| Уведомление пользователям управления
|--------------------------------------------------------------------------
|
| Для критических заказов, задержек и других
| событий, которые должны видеть руководители.
|
*/

function notificationManagementRecipients(
    PDO $db
): array {

    $stmt = $db->query("
        SELECT
            id,
            name,
            email,
            role,
            active
        FROM users
        WHERE active = 1
          AND role IN (
              'superadmin',
              'admin',
              'manager'
          )
        ORDER BY
            CASE
                WHEN role = 'superadmin' THEN 1
                WHEN role = 'admin' THEN 2
                ELSE 3
            END,
            name ASC
    ");

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

/*
|--------------------------------------------------------------------------
| Уведомить управление
|--------------------------------------------------------------------------
*/

function notifyManagement(
    PDO $db,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null
): array {

    $recipients =
        notificationManagementRecipients(
            $db
        );

    $notificationIds = [];

    foreach ($recipients as $recipient) {

        $notificationId =
            notifyUser(
                $db,
                (int) $recipient['id'],
                $type,
                $title,
                $message,
                $entityType,
                $entityId
            );

        if ($notificationId !== null) {

            $notificationIds[] =
                $notificationId;
        }
    }

    return $notificationIds;
}

/*
|--------------------------------------------------------------------------
| Установить / обновить настройку
|--------------------------------------------------------------------------
*/

function setNotificationSetting(
    PDO $db,
    int $userId,
    string $eventType,
    bool $inApp,
    bool $telegram = false,
    bool $email = false,
    ?string $quietHoursStart = null,
    ?string $quietHoursEnd = null
): void {

    $eventTypes =
        notificationEventTypes();

    if (
        !array_key_exists(
            $eventType,
            $eventTypes
        )
    ) {
        throw new InvalidArgumentException(
            'Неизвестный тип события: '
            . $eventType
        );
    }

    $stmt = $db->prepare("
        INSERT INTO notification_settings (
            user_id,
            event_type,
            in_app,
            telegram,
            email,
            quiet_hours_start,
            quiet_hours_end,
            created_at,
            updated_at
        )
        VALUES (
            :user_id,
            :event_type,
            :in_app,
            :telegram,
            :email,
            :quiet_hours_start,
            :quiet_hours_end,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )

        ON CONFLICT(
            user_id,
            event_type
        )

        DO UPDATE SET
            in_app =
                excluded.in_app,

            telegram =
                excluded.telegram,

            email =
                excluded.email,

            quiet_hours_start =
                excluded.quiet_hours_start,

            quiet_hours_end =
                excluded.quiet_hours_end,

            updated_at =
                CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':user_id' =>
            $userId,

        ':event_type' =>
            $eventType,

        ':in_app' =>
            $inApp ? 1 : 0,

        ':telegram' =>
            $telegram ? 1 : 0,

        ':email' =>
            $email ? 1 : 0,

        ':quiet_hours_start' =>
            $quietHoursStart,

        ':quiet_hours_end' =>
            $quietHoursEnd,
    ]);
}

/*
|--------------------------------------------------------------------------
| Получить количество непрочитанных
|--------------------------------------------------------------------------
*/

function unreadNotificationCount(
    PDO $db,
    int $userId
): int {

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE user_id = :user_id
          AND status = 'unread'
    ");

    $stmt->execute([
        ':user_id' =>
            $userId,
    ]);

    return (int) $stmt->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| Отметить уведомление прочитанным
|--------------------------------------------------------------------------
*/

function markNotificationRead(
    PDO $db,
    int $userId,
    int $notificationId
): bool {

    $stmt = $db->prepare("
        UPDATE notifications
        SET
            status = 'read',
            read_at = CURRENT_TIMESTAMP
        WHERE id = :id
          AND user_id = :user_id
          AND status <> 'read'
    ");

    $stmt->execute([
        ':id' =>
            $notificationId,

        ':user_id' =>
            $userId,
    ]);

    return $stmt->rowCount() > 0;
}

/*
|--------------------------------------------------------------------------
| Отметить все прочитанными
|--------------------------------------------------------------------------
*/

function markAllNotificationsRead(
    PDO $db,
    int $userId
): int {

    $stmt = $db->prepare("
        UPDATE notifications
        SET
            status = 'read',
            read_at = CURRENT_TIMESTAMP
        WHERE user_id = :user_id
          AND status = 'unread'
    ");

    $stmt->execute([
        ':user_id' =>
            $userId,
    ]);

    return $stmt->rowCount();
}
