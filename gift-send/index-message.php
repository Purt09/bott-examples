<?php

/**
 * Вебхук «Сообщение — API (отправка запроса)» (BOT-T).
 *
 * Отправляет Telegram-подарок пользователю из сценария (Telegram Bot API sendGift).
 * Повторный вызов с тем же message_id + user_id не дублирует отправку.
 */

require_once dirname(__DIR__) . '/lib/request-log.php';
require_once __DIR__ . '/common.php';

app_log_init(__DIR__);
app_log_begin();

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody ? $rawBody : '', true);
$bodyForLog = is_array($body) ? app_log_json_body_summary($body) : array('json_valid' => false);

app_log_incoming(array(
    'http_method' => gift_send_get($_SERVER, 'REQUEST_METHOD', ''),
    'query' => app_log_query_params($_GET),
    'body' => $bodyForLog,
));

$requestMethod = gift_send_get($_SERVER, 'REQUEST_METHOD', '');
if ($requestMethod !== 'POST') {
    app_log_step_error('http_method', array('reason' => 'method_not_allowed', 'method' => $requestMethod));
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Method not allowed'));
    exit;
}

app_log_step('http_method', array('ok' => true, 'method' => 'POST'));

$bot_id = gift_send_get($_GET, 'bot_id');
$token = gift_send_get($_GET, 'token');
$gift_id = gift_send_get($_GET, 'gift_id');
$admin_id = null;
if (isset($_GET['admin_id']) && $_GET['admin_id'] !== '' && preg_match('/^-?\d+$/', (string) $_GET['admin_id'])) {
    $admin_id = (string) $_GET['admin_id'];
}

if ($bot_id === null || $bot_id === '' || $token === null || $token === '' || $gift_id === null || $gift_id === '') {
    app_log_step_error('validate_query', array('reason' => 'missing_query_params', 'query' => app_log_query_params($_GET)));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Required query: bot_id, token, gift_id'));
    exit;
}

$bot_id = (int) $bot_id;
app_log_step('validate_query', array('ok' => true, 'bot_id' => $bot_id, 'gift_id' => (string) $gift_id, 'has_admin_notify' => $admin_id !== null));

function adminNotifyMessageGift($adminTelegramId, $token, $userId, $telegramId, $giftId, $success, $reason)
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

    gift_send_notify_admin_pm($token, $adminTelegramId, $text);
}

if (!is_array($body)) {
    app_log_step_error('parse_json_body', array('reason' => 'invalid_json_body', 'bot_id' => $bot_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Invalid JSON body'));
    exit;
}

app_log_step('parse_json_body', array('ok' => true, 'body' => app_log_json_body_summary($body)));

$user_id = gift_send_get($body, 'user_id');
$telegram_id = gift_send_get($body, 'telegram_id');
$message_id = gift_send_get($body, 'message_id');

if ($user_id === null || $user_id === '' || !preg_match('/^\d+$/', (string) $user_id)) {
    app_log_step_error('validate_body', array('reason' => 'missing_user_id', 'bot_id' => $bot_id, 'message_id' => $message_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing or invalid user_id in body'));
    exit;
}

if ($message_id === null || $message_id === '' || !preg_match('/^\d+$/', (string) $message_id)) {
    app_log_step_error('validate_body', array('reason' => 'missing_message_id', 'bot_id' => $bot_id, 'user_id' => $user_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing or invalid message_id in body'));
    exit;
}

$user_id = (int) $user_id;
$message_id = (int) $message_id;
$telegram_id = ($telegram_id !== null && $telegram_id !== '' && preg_match('/^-?\d+$/', (string) $telegram_id))
    ? (int) $telegram_id
    : null;

if ($telegram_id === null) {
    app_log_step_error('validate_body', array('reason' => 'missing_telegram_id', 'user_id' => $user_id, 'message_id' => $message_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing or invalid telegram_id in body'));
    exit;
}

app_log_step('validate_body', array(
    'ok' => true,
    'user_id' => $user_id,
    'message_id' => $message_id,
    'has_telegram_id' => true,
));

$sentMarker = __DIR__ . '/sent_msg_' . $message_id . '_' . $user_id . '.lock';
if (is_file($sentMarker)) {
    app_log_step_skip('deduplication_lock', array('reason' => 'already_sent', 'user_id' => $user_id, 'message_id' => $message_id));
    http_response_code(200);
    echo json_encode(array('ok' => true, 'skipped' => true, 'reason' => 'already_sent'));
    exit;
}

app_log_step('deduplication_lock', array('ok' => true, 'user_id' => $user_id, 'message_id' => $message_id));

$telegramParams = array(
    'gift_id' => (string) $gift_id,
    'user_id' => $telegram_id,
);

app_log_step('prepare_telegram_payload', array(
    'method' => 'sendGift',
    'user_id' => $user_id,
    'message_id' => $message_id,
));

app_log_step('telegram_api_request', array('method' => 'sendGift', 'user_id' => $user_id, 'message_id' => $message_id));

$response = gift_send_telegram_post_json($token, 'sendGift', $telegramParams);

if ($response === null) {
    $transportError = gift_send_telegram_transport_message();
    app_log_step_error('telegram_api_transport', array_merge(array(
        'reason' => 'telegram_api_request_failed',
        'user_id' => $user_id,
        'message_id' => $message_id,
        'method' => 'sendGift',
    ), gift_send_telegram_transport_log_context()));
    adminNotifyMessageGift($admin_id, $token, $user_id, $telegram_id, (string) $gift_id, false, $transportError);
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => $transportError));
    exit;
}

app_log_step_telegram('telegram_api_response', $response, array('user_id' => $user_id, 'message_id' => $message_id, 'method' => 'sendGift'));

if (!gift_send_telegram_succeeded($response)) {
    $reason = gift_send_telegram_error($response);
    adminNotifyMessageGift($admin_id, $token, $user_id, $telegram_id, (string) $gift_id, false, $reason);
    http_response_code(502);
    echo json_encode(array(
        'ok' => false,
        'error' => $reason,
    ));
    exit;
}

file_put_contents($sentMarker, date('c'));
app_log_step('write_lock_file', array('ok' => true, 'user_id' => $user_id, 'message_id' => $message_id));

adminNotifyMessageGift($admin_id, $token, $user_id, $telegram_id, (string) $gift_id, true, '');
app_log_step('notify_admin', array('ok' => true, 'user_id' => $user_id, 'sent' => $admin_id !== null));

app_log_step('finish', array('ok' => true, 'user_id' => $user_id, 'message_id' => $message_id, 'method' => 'sendGift'));

http_response_code(200);
echo json_encode(array(
    'ok' => true,
    'user_id' => $user_id,
    'message_id' => $message_id,
));
