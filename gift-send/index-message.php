<?php

/**
 * Вебхук «Сообщение — API (отправка запроса)» (BOT-T).
 *
 * Принимает исходящий HTTP-запрос от свободного сообщения типа «API» и отправляет
 * Telegram-подарок пользователю, у которого сработало это сообщение в сценарии.
 *
 * Документация BOT-T:
 * https://bott.readme.io/reference/сообщение-api-отправка-запроса
 *
 * ── Настройка свободного сообщения в ЛК BOT-T ────────────────────────────────
 *
 * 1. Создайте цепочку свободных сообщений (Сообщения → новое сообщение / очередь).
 *
 * 2. Добавьте сообщение с типом «API» (MessageType::API, type_id = 14).
 *    Пользователю в Telegram на этом шаге ничего не показывается — выполняется только HTTP-вызов.
 *
 * 3. В настройках API-сообщения укажите:
 *
 *    Хост API (полный URL, без дополнительного path):
 *    https://your-host/gift-send/index-message.php?bot_id=1&token=BOT_TOKEN&gift_id=GIFT_ID&admin_id=123456789
 *
 *    Тип запроса:  POST
 *    Формат:       application/json
 *
 *    Параметры body (ключ → значение с плейсхолдерами BOT-T):
 *      user_id      → {USER_ID}            — ID пользователя в боте (bots_bot_user.id)
 *      telegram_id  → {USER_TELEGRAM_ID}   — Telegram ID получателя подарка
 *      message_id   → {MESSAGE_ID}         — ID текущего сообщения (для защиты от повторов)
 *
 *    gift_id берётся из каталога Telegram (метод getAvailableGifts) и передаётся в query URL.
 *    admin_id — необязательный Telegram ID администратора для уведомлений в ЛС.
 *
 * 4. Ветвление по HTTP-коду ответа (в настройках API-сообщения):
 *      200–299 → success_message_id   — например, текст «Подарок отправлен!»
 *      400–499 → client_error_message_id
 *      500+    → server_error_message_id
 *
 * 5. Пример цепочки:
 *      [Текст «Сейчас отправим подарок…»] → [API → index-message.php] → [Текст «Готово!»]
 *
 * ── Параметры URL скрипта ────────────────────────────────────────────────────
 *
 *   bot_id   — ID бота в BOT-T
 *   token    — Bot API token
 *   gift_id  — ID подарка Telegram (sendGift)
 *   admin_id — необязательно, Telegram ID админа для уведомлений
 *
 * ── Тело входящего запроса (JSON от BOT-T) ───────────────────────────────────
 *
 *   user_id, telegram_id, message_id — поля из body-параметров API-сообщения (см. п. 3).
 *
 * Повторный вызов с тем же message_id + user_id не дублирует отправку (файл sent_msg_{message_id}_{user_id}.lock).
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$bot_id = $_GET['bot_id'] ?? null;
$token = $_GET['token'] ?? null;
$gift_id = $_GET['gift_id'] ?? null;
$admin_id = null;
if (isset($_GET['admin_id']) && $_GET['admin_id'] !== '' && preg_match('/^-?\d+$/', (string) $_GET['admin_id'])) {
    $admin_id = (string) $_GET['admin_id'];
}

if ($bot_id === null || $bot_id === '' || $token === null || $token === '' || $gift_id === null || $gift_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Required query: bot_id, token, gift_id']);
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

function adminNotify(?string $adminTelegramId, string $token, int $userId, ?int $telegramId, string $giftId, bool $success, string $reason = ''): void
{
    if ($adminTelegramId === null) {
        return;
    }

    $tgLine = $telegramId !== null ? "\nTelegram: {$telegramId}" : '';

    if ($success) {
        $text = "Подарок отправлен пользователю.\nПользователь бота: #{$userId}{$tgLine}\nПодарок: {$giftId}";
    } else {
        $text = "Не удалось отправить подарок.\nПользователь бота: #{$userId}{$tgLine}\nПодарок: {$giftId}\nПричина: {$reason}";
    }

    notifyAdminPm($token, $adminTelegramId, $text);
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

$user_id = (int) $user_id;
$message_id = (int) $message_id;
$telegram_id = ($telegram_id !== null && $telegram_id !== '' && preg_match('/^-?\d+$/', (string) $telegram_id))
    ? (int) $telegram_id
    : null;

$sentMarker = __DIR__ . '/sent_msg_' . $message_id . '_' . $user_id . '.lock';
if (is_file($sentMarker)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'already_sent']);
    exit;
}

$url = 'https://api.bot-t.com/v1/bot/user/send-request?token=' . rawurlencode($token);

$payload = [
    'bot_id' => $bot_id,
    'user_id' => $user_id,
    'method' => 'sendGift',
    'params' => [
        'gift_id' => (string) $gift_id,
    ],
];

$response = bottPostJson($url, $payload);

if ($response === null) {
    adminNotify($admin_id, $token, $user_id, $telegram_id, (string) $gift_id, false, 'BOT-T API request failed');
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'BOT-T API request failed']);
    exit;
}

if (empty($response['result'])) {
    $reason = $response['message'] ?? 'BOT-T API error';
    adminNotify($admin_id, $token, $user_id, $telegram_id, (string) $gift_id, false, $reason);
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $reason,
    ]);
    exit;
}

file_put_contents($sentMarker, date('c'));
adminNotify($admin_id, $token, $user_id, $telegram_id, (string) $gift_id, true);

http_response_code(200);
echo json_encode([
    'ok' => true,
    'user_id' => $user_id,
    'message_id' => $message_id,
]);
