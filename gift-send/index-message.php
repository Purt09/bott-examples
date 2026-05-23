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
 * Повторный вызов с тем же message_id + user_id не дублирует отправку (файл sent_msg_{message_id}_{user_id}.lock).
 */

require_once __DIR__ . '/request-log.php';
app_log_init(__DIR__);
app_log_begin();

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody ?: '', true);
$bodyForLog = is_array($body) ? app_log_json_body_summary($body) : ['json_valid' => false];

app_log_incoming([
    'http_method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'query' => app_log_query_params($_GET),
    'body' => $bodyForLog,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_log_step_error('http_method', ['reason' => 'method_not_allowed', 'method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

app_log_step('http_method', ['ok' => true, 'method' => 'POST']);

$bot_id = $_GET['bot_id'] ?? null;
$token = $_GET['token'] ?? null;
$gift_id = $_GET['gift_id'] ?? null;
$admin_id = null;
if (isset($_GET['admin_id']) && $_GET['admin_id'] !== '' && preg_match('/^-?\d+$/', (string) $_GET['admin_id'])) {
    $admin_id = (string) $_GET['admin_id'];
}

if ($bot_id === null || $bot_id === '' || $token === null || $token === '' || $gift_id === null || $gift_id === '') {
    app_log_step_error('validate_query', ['reason' => 'missing_query_params', 'query' => app_log_query_params($_GET)]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Required query: bot_id, token, gift_id']);
    exit;
}

$bot_id = (int) $bot_id;
app_log_step('validate_query', ['ok' => true, 'bot_id' => $bot_id, 'gift_id' => (string) $gift_id, 'has_admin_notify' => $admin_id !== null]);

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

if (!is_array($body)) {
    app_log_step_error('parse_json_body', ['reason' => 'invalid_json_body', 'bot_id' => $bot_id]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

app_log_step('parse_json_body', ['ok' => true, 'body' => app_log_json_body_summary($body)]);

$user_id = $body['user_id'] ?? null;
$telegram_id = $body['telegram_id'] ?? null;
$message_id = $body['message_id'] ?? null;

if ($user_id === null || $user_id === '' || !preg_match('/^\d+$/', (string) $user_id)) {
    app_log_step_error('validate_body', ['reason' => 'missing_user_id', 'bot_id' => $bot_id, 'message_id' => $message_id]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid user_id in body']);
    exit;
}

if ($message_id === null || $message_id === '' || !preg_match('/^\d+$/', (string) $message_id)) {
    app_log_step_error('validate_body', ['reason' => 'missing_message_id', 'bot_id' => $bot_id, 'user_id' => $user_id]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid message_id in body']);
    exit;
}

$user_id = (int) $user_id;
$message_id = (int) $message_id;
$telegram_id = ($telegram_id !== null && $telegram_id !== '' && preg_match('/^-?\d+$/', (string) $telegram_id))
    ? (int) $telegram_id
    : null;

app_log_step('validate_body', [
    'ok' => true,
    'user_id' => $user_id,
    'message_id' => $message_id,
    'has_telegram_id' => $telegram_id !== null,
]);

$sentMarker = __DIR__ . '/sent_msg_' . $message_id . '_' . $user_id . '.lock';
if (is_file($sentMarker)) {
    app_log_step_skip('deduplication_lock', ['reason' => 'already_sent', 'user_id' => $user_id, 'message_id' => $message_id]);
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'already_sent']);
    exit;
}

app_log_step('deduplication_lock', ['ok' => true, 'user_id' => $user_id, 'message_id' => $message_id]);

$payload = [
    'bot_id' => $bot_id,
    'user_id' => $user_id,
    'method' => 'sendGift',
    'params' => [
        'gift_id' => (string) $gift_id,
    ],
];

app_log_step('prepare_bott_payload', ['method' => 'sendGift', 'user_id' => $user_id, 'message_id' => $message_id]);

$url = 'https://api.bot-t.com/v1/bot/user/send-request?token=' . rawurlencode($token);

app_log_step('bott_api_request', ['method' => 'sendGift', 'user_id' => $user_id, 'message_id' => $message_id]);

$response = bottPostJson($url, $payload);

if ($response === null) {
    app_log_step_error('bott_api_transport', [
        'reason' => 'bott_api_request_failed',
        'user_id' => $user_id,
        'message_id' => $message_id,
        'method' => 'sendGift',
    ]);
    adminNotify($admin_id, $token, $user_id, $telegram_id, (string) $gift_id, false, 'BOT-T API request failed');
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'BOT-T API request failed']);
    exit;
}

app_log_step_bott('bott_api_response', $response, ['user_id' => $user_id, 'message_id' => $message_id, 'method' => 'sendGift']);

if (!bott_api_succeeded($response)) {
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
app_log_step('write_lock_file', ['ok' => true, 'user_id' => $user_id, 'message_id' => $message_id]);

adminNotify($admin_id, $token, $user_id, $telegram_id, (string) $gift_id, true);
app_log_step('notify_admin', ['ok' => true, 'user_id' => $user_id, 'sent' => $admin_id !== null]);

app_log_step('finish', ['ok' => true, 'user_id' => $user_id, 'message_id' => $message_id, 'method' => 'sendGift']);

http_response_code(200);
echo json_encode([
    'ok' => true,
    'user_id' => $user_id,
    'message_id' => $message_id,
]);
