<?php

require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Получить токен Telegram-бота
|--------------------------------------------------------------------------
*/

function telegramBotToken(): string
{
    static $token = null;

    if ($token !== null) {
        return $token;
    }

    $envFile = dirname(__DIR__) . '/.env';

    if (!is_file($envFile)) {
        $token = '';
        return $token;
    }

    $lines = file(
        $envFile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        $token = '';
        return $token;
    }

    foreach ($lines as $line) {

        $line = trim($line);

        if (
            $line === ''
            || str_starts_with($line, '#')
            || !str_contains($line, '=')
        ) {
            continue;
        }

        [$key, $value] = explode(
            '=',
            $line,
            2
        );

        $key = trim($key);

        if ($key !== 'TELEGRAM_BOT_TOKEN') {
            continue;
        }

        $value = trim(
            $value,
            " \t\n\r\0\x0B\"'"
        );

        $token = $value;

        return $token;
    }

    $token = '';

    return $token;
}


/*
|--------------------------------------------------------------------------
| Telegram Bot API запрос
|--------------------------------------------------------------------------
*/

function telegramApiRequest(
    string $method,
    array $params = []
): array {

    $token = telegramBotToken();

    if ($token === '') {

        return [
            'success' => false,
            'error' =>
                'TELEGRAM_BOT_TOKEN не настроен.',
        ];
    }

    $url =
        'https://api.telegram.org/bot'
        . $token
        . '/'
        . $method;

    $ch = curl_init($url);

    if ($ch === false) {

        return [
            'success' => false,
            'error' =>
                'Не удалось создать CURL-запрос.',
        ];
    }

    curl_setopt(
        $ch,
        CURLOPT_POST,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        http_build_query($params)
    );

    curl_setopt(
        $ch,
        CURLOPT_RETURNTRANSFER,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_CONNECTTIMEOUT,
        5
    );

    curl_setopt(
        $ch,
        CURLOPT_TIMEOUT,
        10
    );

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        [
            'Content-Type: application/x-www-form-urlencoded',
        ]
    );

    $response =
        curl_exec($ch);

    $curlError =
        curl_error($ch);

    $httpCode =
        (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    if ($response === false) {

        return [
            'success' => false,
            'error' =>
                'Ошибка CURL: '
                . $curlError,
        ];
    }

    $decoded =
        json_decode(
            $response,
            true
        );

    if (
        $httpCode >= 200
        &&
        $httpCode < 300
        &&
        is_array($decoded)
        &&
        ($decoded['ok'] ?? false) === true
    ) {

        return [
            'success' => true,
            'response' => $decoded,
        ];
    }

    $errorMessage =
        'Telegram API error.';

    if (
        is_array($decoded)
        &&
        isset(
            $decoded['description']
        )
    ) {

        $errorMessage =
            (string) $decoded['description'];
    }

    return [
        'success' => false,
        'error' =>
            $errorMessage,
        'response' =>
            $decoded,
        'http_code' =>
            $httpCode,
    ];
}


/*
|--------------------------------------------------------------------------
| Отправить сообщение
|--------------------------------------------------------------------------
*/

function sendTelegramMessage(
    string $chatId,
    string $message
): array {

    return telegramApiRequest(
        'sendMessage',
        [
            'chat_id' =>
                $chatId,

            'text' =>
                $message,

            'disable_web_page_preview' =>
                true,
        ]
    );
}


/*
|--------------------------------------------------------------------------
| Получить информацию о боте
|--------------------------------------------------------------------------
*/

function telegramGetMe(): array
{
    return telegramApiRequest(
        'getMe'
    );
}


/*
|--------------------------------------------------------------------------
| Получить обновления
|--------------------------------------------------------------------------
*/

function telegramGetUpdates(
    ?int $offset = null,
    int $timeout = 0
): array {

    $params = [
        'timeout' =>
            max(
                0,
                $timeout
            ),
    ];

    if ($offset !== null) {

        $params['offset'] =
            $offset;
    }

    return telegramApiRequest(
        'getUpdates',
        $params
    );
}


/*
|--------------------------------------------------------------------------
| Telegram-соединение пользователя
|--------------------------------------------------------------------------
*/

function telegramConnectionForUser(
    PDO $db,
    int $userId
): ?array {

    $stmt = $db->prepare("
        SELECT
            id,
            user_id,
            chat_id,
            username,
            active,
            connected_at,
            last_seen_at
        FROM telegram_connections
        WHERE user_id = :user_id
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' =>
            $userId,
    ]);

    $connection =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$connection) {
        return null;
    }

    return $connection;
}


/*
|--------------------------------------------------------------------------
| Разрешённая группа
|--------------------------------------------------------------------------
*/

function telegramGroup(
    PDO $db,
    string $chatId
): ?array {

    $stmt = $db->prepare("
        SELECT
            id,
            chat_id,
            title,
            active,
            created_at
        FROM telegram_groups
        WHERE chat_id = :chat_id
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        ':chat_id' =>
            $chatId,
    ]);

    $group =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$group) {
        return null;
    }

    return $group;
}


/*
|--------------------------------------------------------------------------
| Единственная активная группа
|--------------------------------------------------------------------------
*/

function activeTelegramGroup(
    PDO $db
): ?array {

    $stmt = $db->query("
        SELECT
            id,
            chat_id,
            title,
            active,
            created_at
        FROM telegram_groups
        WHERE active = 1
        ORDER BY id ASC
        LIMIT 1
    ");

    $group =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$group) {
        return null;
    }

    return $group;
}


/*
|--------------------------------------------------------------------------
| Отправить Telegram пользователю
|--------------------------------------------------------------------------
*/

function sendTelegramToUser(
    PDO $db,
    int $userId,
    string $message
): array {

    $connection =
        telegramConnectionForUser(
            $db,
            $userId
        );

    if (!$connection) {

        return [
            'success' => false,
            'sent' => false,
            'error' =>
                'Telegram не подключён.',
        ];
    }

    $result =
        sendTelegramMessage(
            $connection['chat_id'],
            $message
        );

    if (
        ($result['success'] ?? false)
        === true
    ) {

        $update =
            $db->prepare("
                UPDATE telegram_connections
                SET
                    last_seen_at =
                        CURRENT_TIMESTAMP
                WHERE user_id =
                    :user_id
            ");

        $update->execute([
            ':user_id' =>
                $userId,
        ]);
    }

    $result['sent'] =
        ($result['success'] ?? false)
        === true;

    return $result;
}


/*
|--------------------------------------------------------------------------
| Отправить сообщение в разрешённую группу
|--------------------------------------------------------------------------
*/

function sendTelegramToGroup(
    PDO $db,
    string $message
): array {

    $group =
        activeTelegramGroup(
            $db
        );

    if (!$group) {

        return [
            'success' => false,
            'sent' => false,
            'error' =>
                'Разрешённая Telegram-группа не настроена.',
        ];
    }

    $result =
        sendTelegramMessage(
            $group['chat_id'],
            $message
        );

    $result['sent'] =
        ($result['success'] ?? false)
        === true;

    $result['chat_id'] =
        (string) $group['chat_id'];

    $result['group_title'] =
        (string) $group['title'];

    return $result;
}


/*
|--------------------------------------------------------------------------
| Формат: стекло перешло на следующий участок
|--------------------------------------------------------------------------
*/

function formatTelegramGlassMoved(
    string $glassCode,
    string $orderNumber,
    string $fromStage,
    string $toStage,
    int $priority,
    ?int $width = null,
    ?int $height = null,
    ?float $areaM2 = null
): string {

    $priorityName = match ($priority) {
        3 => 'Критический',
        2 => 'Срочный',
        default => 'Обычный',
    };

    $message =
        "🔔 Новое стекло\n\n"
        . "Стекло: "
        . $glassCode
        . "\n"
        . "Заказ: "
        . $orderNumber
        . "\n\n"
        . $fromStage
        . " → "
        . $toStage
        . "\n\n"
        . "Приоритет: "
        . $priorityName;

    if (
        $width !== null
        &&
        $height !== null
    ) {

        $message .=
            "\n"
            . "Размер: "
            . $width
            . " × "
            . $height
            . " мм";
    }

    if ($areaM2 !== null) {

        $message .=
            "\n"
            . "Площадь: "
            . number_format(
                $areaM2,
                2,
                ',',
                ' '
            )
            . " м²";
    }

    return $message;
}


/*
|--------------------------------------------------------------------------
| Формат: брак
|--------------------------------------------------------------------------
*/

function formatTelegramGlassRejected(
    string $glassCode,
    string $orderNumber,
    string $stage
): string {

    return
        "⚠️ БРАК\n\n"
        . "Стекло: "
        . $glassCode
        . "\n"
        . "Заказ: "
        . $orderNumber
        . "\n"
        . "Участок: "
        . $stage;
}


/*
|--------------------------------------------------------------------------
| Формат: завершение партии
|--------------------------------------------------------------------------
*/

function formatTelegramBatchCompleted(
    int $batchId,
    string $orderNumber,
    string $stage,
    string $employeeName,
    int $completedCount,
    int $rejectedCount
): string {

    return
        "✅ Партия завершена\n\n"
        . "Партия: №"
        . $batchId
        . "\n"
        . "Заказ: "
        . $orderNumber
        . "\n"
        . "Участок: "
        . $stage
        . "\n"
        . "Исполнитель: "
        . $employeeName
        . "\n\n"
        . "Готово: "
        . $completedCount
        . "\n"
        . "Брак: "
        . $rejectedCount;
}


/*
|--------------------------------------------------------------------------
| Формат: критический заказ
|--------------------------------------------------------------------------
*/

function formatTelegramCriticalOrder(
    string $orderNumber,
    string $customerName,
    ?string $plannedDate
): string {

    $message =
        "🔴 КРИТИЧЕСКИЙ ЗАКАЗ\n\n"
        . "Заказ: "
        . $orderNumber;

    if ($customerName !== '') {

        $message .=
            "\n"
            . "Клиент: "
            . $customerName;
    }

    if (
        $plannedDate !== null
        &&
        $plannedDate !== ''
    ) {

        $message .=
            "\n"
            . "Плановая дата: "
            . $plannedDate;
    }

    return $message;
}


/*
|--------------------------------------------------------------------------
| Формат: заказ завершён
|--------------------------------------------------------------------------
*/

function formatTelegramOrderCompleted(
    string $orderNumber,
    int $totalGlasses
): string {

    return
        "✅ ЗАКАЗ ЗАВЕРШЁН\n\n"
        . "Заказ: "
        . $orderNumber
        . "\n"
        . "Стекол: "
        . $totalGlasses;
}


/*
|--------------------------------------------------------------------------
| Проверка: группа разрешена
|--------------------------------------------------------------------------
*/

function isAllowedTelegramGroup(
    PDO $db,
    string $chatId
): bool {

    return telegramGroup(
        $db,
        $chatId
    ) !== null;
}
