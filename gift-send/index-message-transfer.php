<?php

/**
 * Вебхук «Сообщение — API (отправка запроса)» (BOT-T) — передача коллекционного подарка.
 *
 * Принимает исходящий HTTP-запрос от свободного сообщения типа «API» и передаёт
 * уже имеющийся коллекционный подарок пользователю, у которого сработало сообщение.
 * Не покупает новый подарок (sendGift), а передаёт owned_gift_id через transferGift.
 *
 * Документация BOT-T:
 * https://bott.readme.io/reference/сообщение-api-отправка-запроса
 *
 * ── Настройка свободного сообщения в ЛК BOT-T ────────────────────────────────
 *
 * 1. Создайте цепочку свободных сообщений.
 *
 * 2. Добавьте сообщение с типом «API» (MessageType::API, type_id = 14).
 *
 * 3. В настройках API-сообщения укажите:
 *
 *    Хост API (полный URL):
 *    https://your-host/gift-send/index-message-transfer.php?bot_id=1&token=BOT_TOKEN&owned_gift_id=OWNED_GIFT_ID&business_connection_id=BC_ID&admin_id=123456789
 *
 *    Тип запроса:  POST
 *    Формат:       application/json
 *
 *    Параметры body:
 *      user_id      → {USER_ID}
 *      telegram_id  → {USER_TELEGRAM_ID}   — получатель (new_owner_chat_id)
 *      message_id   → {MESSAGE_ID}
 *
 *    owned_gift_id — ID подарка в инвентаре business-аккаунта (getBusinessAccountGifts).
 *    business_connection_id — ID business-подключения бота.
 *    star_count — необязательно в query, если передача платная (Stars).
 *
 * 4. Ветвление по HTTP-коду: 200 → success, 4xx → client error, 5xx → server error.
 *
 * 5. Пример цепочки:
 *      [Текст «Передаём коллекционный подарок…»] → [API] → [Текст «Готово!»]
 *
 * ── Параметры URL ────────────────────────────────────────────────────────────
 *
 *   bot_id, token, owned_gift_id, business_connection_id — обязательные;
 *   admin_id, star_count — необязательные.
 *
 * Повторный вызов с тем же message_id + user_id не дублирует передачу
 * (файл sent_msg_transfer_{message_id}_{user_id}.lock).
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$bot_id = $_GET['bot_id'] ?? null;
$token = $_GET['token'] ?? null;
$owned_gift_id = $_GET['owned_gift_id'] ?? null;
$business_connection_id = $_GET['business_connection_id'] ?? null;
$admin_id = null;
if (isset($_GET['admin_id']) && $_GET['admin_id'] !== '' && preg_match('/^-?\d+$/', (string) $_GET['admin_id'])) {
    $admin_id = (string) $_GET['admin_id'];
}
$star_count = null;
if (isset($_GET['star_count']) && $_GET['star_count'] !== '' && preg_match('/^\d+$/', (string) $_GET['star_count'])) {
    $star_count = (int) $_GET['star_count'];
}

if (
    $bot_id === null || $bot_id === ''
    || $token === null || $token === ''
    || $owned_gift_id === null || $owned_gift_id === ''
    || $business_connection_id === null || $business_connection_id === ''
) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Required query: bot_id, token, owned_gift_id, business_connection_id']);
    exit;
}

$bot_id = (int) $bot_id;

/**
 * @return array<string, mixed>|null
 */
function bottPostJson(string $url, array $payload): ?array
{
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ];
    $result = @file_get_contents($url, false, stream_context_create($options));
    if ($result === false) {
        return null;
    }
    $decoded = json_decode($result, true);

    return is_array($decoded) ? $decoded : null;
}

function notifyAdminPm(string $token, string $telegramId, string $text): void
{
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query([
                'chat_id' => $telegramId,
                'text' => $text,
            ]),
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ];
    @file_get_contents($url, false, stream_context_create($options));
}

function adminNotify(?string $adminTelegramId, string $token, int $userId, int $telegramId, string $ownedGiftId, bool $success, string $reason = ''): void
{
    if ($adminTelegramId === null) {
        return;
    }

    if ($success) {
        $text = "Коллекционный подарок передан пользователю.\nПользователь бота: #{$userId}\nTelegram: {$telegramId}\nowned_gift_id: {$ownedGiftId}";
    } else {
        $text = "Не удалось передать коллекционный подарок.\nПользователь бота: #{$userId}\nTelegram: {$telegramId}\nowned_gift_id: {$ownedGiftId}\nПричина: {$reason}";
    }

    notifyAdminPm($token, $adminTelegramId, $text);
}

/**
 * @return array<string, mixed>
 */
function buildTransferParams(string $businessConnectionId, string $ownedGiftId, int $newOwnerChatId, ?int $starCount): array
{
    $params = [
        'business_connection_id' => $businessConnectionId,
        'owned_gift_id' => $ownedGiftId,
        'new_owner_chat_id' => $newOwnerChatId,
    ];
    if ($starCount !== null && $starCount > 0) {
        $params['star_count'] = $starCount;
    }

    return $params;
}

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$user_id = $body['user_id'] ?? null;
$telegram_id = $body['telegram_id'] ?? null;
$message_id = $body['message_id'] ?? null;

if ($user_id === null || $user_id === '' || !preg_match('/^\d+$/', (string) $user_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid user_id in body']);
    exit;
}

if ($message_id === null || $message_id === '' || !preg_match('/^\d+$/', (string) $message_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid message_id in body']);
    exit;
}

if ($telegram_id === null || $telegram_id === '' || !preg_match('/^-?\d+$/', (string) $telegram_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid telegram_id in body']);
    exit;
}

$user_id = (int) $user_id;
$message_id = (int) $message_id;
$new_owner_chat_id = (int) $telegram_id;

$sentMarker = __DIR__ . '/sent_msg_transfer_' . $message_id . '_' . $user_id . '.lock';
if (is_file($sentMarker)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'already_sent']);
    exit;
}

$url = 'https://api.bot-t.com/v1/bot/user/send-request?token=' . rawurlencode($token);

$payload = [
    'bot_id' => $bot_id,
    'user_id' => $user_id,
    'method' => 'transferGift',
    'params' => buildTransferParams(
        (string) $business_connection_id,
        (string) $owned_gift_id,
        $new_owner_chat_id,
        $star_count
    ),
];

$response = bottPostJson($url, $payload);

if ($response === null) {
    adminNotify($admin_id, $token, $user_id, $new_owner_chat_id, (string) $owned_gift_id, false, 'BOT-T API request failed');
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'BOT-T API request failed']);
    exit;
}

if (empty($response['result'])) {
    $reason = $response['message'] ?? 'BOT-T API error';
    adminNotify($admin_id, $token, $user_id, $new_owner_chat_id, (string) $owned_gift_id, false, $reason);
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $reason,
    ]);
    exit;
}

file_put_contents($sentMarker, date('c'));
adminNotify($admin_id, $token, $user_id, $new_owner_chat_id, (string) $owned_gift_id, true);

http_response_code(200);
echo json_encode([
    'ok' => true,
    'user_id' => $user_id,
    'message_id' => $message_id,
]);
