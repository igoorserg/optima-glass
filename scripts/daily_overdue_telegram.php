<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/telegram.php';

/*
|--------------------------------------------------------------------------
| OPTIMA GLASS
| Щоденний Telegram-звіт про прострочені замовлення
|--------------------------------------------------------------------------
|
| Звичайний запуск:
|   php scripts/daily_overdue_telegram.php
|
| Перегляд без Telegram:
|   php scripts/daily_overdue_telegram.php --preview
|
| Примусова тестова відправка зараз:
|   php scripts/daily_overdue_telegram.php --force
|
*/

$timezone = new DateTimeZone('Europe/Kyiv');
$now = new DateTimeImmutable('now', $timezone);

$today = $now->format('Y-m-d');
$currentTime = $now->format('H:i');

$args = $argv ?? [];

$isPreview = in_array(
    '--preview',
    $args,
    true
);

$isForce = in_array(
    '--force',
    $args,
    true
);


/*
|--------------------------------------------------------------------------
| Таблиця журналу щоденних звітів
|--------------------------------------------------------------------------
*/

$db->exec("
    CREATE TABLE IF NOT EXISTS telegram_daily_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,

        report_type TEXT NOT NULL,
        report_date TEXT NOT NULL,

        sent_at TEXT,
        success INTEGER NOT NULL DEFAULT 0,

        items_count INTEGER NOT NULL DEFAULT 0,

        error_message TEXT,

        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

        UNIQUE (
            report_type,
            report_date
        )
    )
");

$db->exec("
    CREATE INDEX IF NOT EXISTS
    idx_telegram_daily_reports_date
    ON telegram_daily_reports(report_date)
");


/*
|--------------------------------------------------------------------------
| Перевірка часу
|--------------------------------------------------------------------------
|
| Cron запускає файл о 09:15.
| Додаткова перевірка захищає від випадкового запуску в інший час.
|
*/

if (
    !$isPreview
    && !$isForce
    && $currentTime !== '09:15'
) {

    echo sprintf(
        "[%s] Пропуск. Київський час зараз %s, звіт заплановано на 09:15.\n",
        $now->format('Y-m-d H:i:s'),
        $currentTime
    );

    exit(0);
}


/*
|--------------------------------------------------------------------------
| Чи вже відправляли сьогодні
|--------------------------------------------------------------------------
*/

$alreadySentStmt = $db->prepare("
    SELECT
        id,
        sent_at,
        success
    FROM telegram_daily_reports
    WHERE report_type = 'overdue_orders'
      AND report_date = :report_date
      AND success = 1
    LIMIT 1
");

$alreadySentStmt->execute([
    ':report_date' => $today,
]);

$alreadySent =
    $alreadySentStmt->fetch(
        PDO::FETCH_ASSOC
    );

if (
    $alreadySent
    && !$isPreview
    && !$isForce
) {

    echo sprintf(
        "Звіт за %s вже відправлено: %s\n",
        $today,
        $alreadySent['sent_at'] ?? '—'
    );

    exit(0);
}


/*
|--------------------------------------------------------------------------
| Прострочені замовлення
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        o.id,
        o.order_number,
        o.customer_name,
        o.status,
        o.priority,
        o.planned_date,

        CAST(
            julianday(:today)
            - julianday(DATE(o.planned_date))
            AS INTEGER
        ) AS overdue_days,

        COUNT(g.id) AS glass_count,

        SUM(
            CASE
                WHEN g.status = 'rejected'
                THEN 1
                ELSE 0
            END
        ) AS rejected_count

    FROM orders o

    LEFT JOIN glasses g
        ON g.order_id = o.id

    WHERE o.planned_date IS NOT NULL
      AND o.planned_date != ''
      AND DATE(o.planned_date) < DATE(:today)
      AND o.status != 'completed'

    GROUP BY o.id

    ORDER BY
        DATE(o.planned_date) ASC,
        o.priority DESC,
        o.id ASC
");

$stmt->execute([
    ':today' => $today,
]);

$orders =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Підписи
|--------------------------------------------------------------------------
*/

function overdueOrderStatusLabel(
    string $status
): string {

    return match ($status) {
        'new' =>
            'Нове',

        'in_production' =>
            'У виробництві',

        'completed' =>
            'Завершено',

        default =>
            $status,
    };
}


function overduePriorityLabel(
    int $priority
): string {

    return match (true) {
        $priority >= 3 =>
            '⚡ Терміновий',

        $priority === 2 =>
            'Підвищений',

        default =>
            'Звичайний',
    };
}


/*
|--------------------------------------------------------------------------
| Формування Telegram-повідомлення
|--------------------------------------------------------------------------
*/

$message = "⚠️ OPTIMA GLASS — ПРОСТРОЧЕНІ ЗАМОВЛЕННЯ\n\n";

$message .=
    "📅 Станом на: "
    . $now->format('d.m.Y')
    . "\n";

$message .=
    "⏰ Час звіту: "
    . $now->format('H:i')
    . "\n";

$message .=
    "🔴 Прострочених: "
    . count($orders)
    . "\n";


if (!$orders) {

    $message .=
        "\n✅ Прострочених замовлень немає.";

} else {

    foreach (
        $orders as $index => $order
    ) {

        $number =
            (string) (
                $order['order_number']
                ?? ''
            );

        $customer =
            trim(
                (string) (
                    $order['customer_name']
                    ?? ''
                )
            );

        if ($customer === '') {
            $customer = 'Клієнт не вказаний';
        }

        $plannedDate =
            (string) (
                $order['planned_date']
                ?? ''
            );

        $plannedFormatted =
            $plannedDate;

        if ($plannedDate !== '') {

            try {

                $planned =
                    new DateTimeImmutable(
                        $plannedDate,
                        $timezone
                    );

                $plannedFormatted =
                    $planned->format(
                        'd.m.Y'
                    );

            } catch (Throwable) {
                // Залишаємо дату як є.
            }
        }

        $days =
            max(
                1,
                (int) (
                    $order['overdue_days']
                    ?? 0
                )
            );

        $glassCount =
            (int) (
                $order['glass_count']
                ?? 0
            );

        $rejectedCount =
            (int) (
                $order['rejected_count']
                ?? 0
            );

        $message .= "\n\n";
        $message .=
            "━━━━━━━━━━━━━━━━━━━━\n";

        $message .=
            "🔴 №"
            . $number
            . " — "
            . $customer
            . "\n";

        $message .=
            "📅 План: "
            . $plannedFormatted
            . "\n";

        $message .=
            "⏳ Прострочено: "
            . $days
            . " "
            . (
                $days === 1
                    ? 'день'
                    : 'дн.'
            )
            . "\n";

        $message .=
            "📌 Статус: "
            . overdueOrderStatusLabel(
                (string) $order['status']
            )
            . "\n";

        $message .=
            "🚨 Пріоритет: "
            . overduePriorityLabel(
                (int) $order['priority']
            )
            . "\n";

        $message .=
            "🪟 Скло: "
            . $glassCount
            . "\n";

        if ($rejectedCount > 0) {

            $message .=
                "❌ Брак: "
                . $rejectedCount
                . "\n";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Preview
|--------------------------------------------------------------------------
*/

if ($isPreview) {

    echo "\n";
    echo "===== TELEGRAM PREVIEW =====\n\n";
    echo $message;
    echo "\n\n";
    echo "===== END PREVIEW =====\n";

    exit(0);
}


/*
|--------------------------------------------------------------------------
| Telegram
|--------------------------------------------------------------------------
*/

try {

    $result =
        sendTelegramToGroup(
            $db,
            $message
        );

} catch (Throwable $e) {

    $result = [
        'success' => false,
        'sent' => false,
        'error' => $e->getMessage(),
    ];
}


$success =
    ($result['success'] ?? false)
    === true
    &&
    ($result['sent'] ?? false)
    === true;


/*
|--------------------------------------------------------------------------
| Запис результату
|--------------------------------------------------------------------------
*/

$saveStmt = $db->prepare("
    INSERT INTO telegram_daily_reports (
        report_type,
        report_date,
        sent_at,
        success,
        items_count,
        error_message
    )
    VALUES (
        'overdue_orders',
        :report_date,
        :sent_at,
        :success,
        :items_count,
        :error_message
    )

    ON CONFLICT(
        report_type,
        report_date
    )
    DO UPDATE SET
        sent_at = excluded.sent_at,
        success = excluded.success,
        items_count = excluded.items_count,
        error_message = excluded.error_message
");

$saveStmt->execute([
    ':report_date' =>
        $today,

    ':sent_at' =>
        $now->format(
            'Y-m-d H:i:s'
        ),

    ':success' =>
        $success
            ? 1
            : 0,

    ':items_count' =>
        count($orders),

    ':error_message' =>
        $success
            ? null
            : (
                (string) (
                    $result['error']
                    ?? 'Невідома помилка Telegram.'
                )
            ),
]);


if (!$success) {

    fwrite(
        STDERR,
        "❌ Telegram не відправлено: "
        . (
            $result['error']
            ?? 'невідома помилка'
        )
        . PHP_EOL
    );

    exit(1);
}


echo sprintf(
    "✅ Telegram-звіт відправлено.\n"
    . "Група: %s\n"
    . "Прострочених замовлень: %d\n"
    . "Дата: %s\n",
    $result['group_title']
        ?? 'Telegram',

    count($orders),

    $now->format(
        'd.m.Y H:i:s'
    )
);

exit(0);
