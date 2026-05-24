<?php

/**
 * Вебхук «Сообщение — API (отправка запроса)» (BOT-T) — передача коллекционного подарка.
 *
 * Повторная передача тому же user_id того же owned_gift_id блокируется на 2 минуты.
 *
 * Параметры URL: bot_id, token, owned_gift_id (или gift_id), business_connection_id, admin_id, star_count.
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
$owned_gift_id = gift_send_get_owned_gift_id();
$business_connection_id = gift_send_get($_GET, 'business_connection_id');
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
    app_log_step_error('validate_query', array('reason' => 'missing_query_params', 'query' => app_log_query_params($_GET)));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Required query: bot_id, token, owned_gift_id (or gift_id), business_connection_id'));
    exit;
}

$bot_id = (int) $bot_id;
app_log_step('validate_query', array(
    'ok' => true,
    'bot_id' => $bot_id,
    'owned_gift_id' => (string) $owned_gift_id,
    'star_count' => $star_count,
    'has_admin_notify' => $admin_id !== null,
));

function adminNotifyMessageTransfer($adminTelegramId, $token, $userId, $telegramId, $ownedGiftId, $success, $reason)
{
    if ($adminTelegramId === null) {
        return;
    }

    if ($success) {
        $text = "Коллекционный подарок передан пользователю.\nПользователь бота: #{$userId}\nTelegram: {$telegramId}\nowned_gift_id: {$ownedGiftId}";
    } else {
        $text = "Не удалось передать коллекционный подарок.\nПользователь бота: #{$userId}\nTelegram: {$telegramId}\nowned_gift_id: {$ownedGiftId}\nПричина: {$reason}";
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

if ($telegram_id === null || $telegram_id === '' || !preg_match('/^-?\d+$/', (string) $telegram_id)) {
    app_log_step_error('validate_body', array('reason' => 'missing_recipient_telegram_id', 'user_id' => $user_id, 'message_id' => $message_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing or invalid telegram_id in body'));
    exit;
}

$user_id = (int) $user_id;
$message_id = (int) $message_id;
$new_owner_chat_id = (int) $telegram_id;

app_log_step('validate_body', array(
    'ok' => true,
    'user_id' => $user_id,
    'message_id' => $message_id,
    'has_recipient' => true,
));

$sentMarker = gift_send_dedup_acquire(__DIR__, 'sent_transfer', $user_id, (string) $owned_gift_id);
if ($sentMarker['action'] === 'skip') {
    app_log_step_skip('deduplication_lock', array(
        'reason' => 'recently_sent',
        'user_id' => $user_id,
        'owned_gift_id' => (string) $owned_gift_id,
        'message_id' => $message_id,
        'locked_at' => $sentMarker['locked_at'],
        'ttl_sec' => $sentMarker['ttl'],
        'remaining_sec' => $sentMarker['remaining'],
    ));
    http_response_code(200);
    echo json_encode(array('ok' => true, 'skipped' => true, 'reason' => 'recently_sent'));
    exit;
}

app_log_step('deduplication_lock', array(
    'ok' => true,
    'user_id' => $user_id,
    'owned_gift_id' => (string) $owned_gift_id,
    'message_id' => $message_id,
    'ttl_sec' => $sentMarker['ttl'],
));

$dedupLockPath = $sentMarker['path'];

$telegramParams = gift_send_build_transfer_params(
    (string) $business_connection_id,
    (string) $owned_gift_id,
    $new_owner_chat_id,
    $star_count
);

app_log_step('prepare_telegram_payload', array('method' => 'transferGift', 'user_id' => $user_id, 'message_id' => $message_id));

app_log_step('telegram_api_request', array('method' => 'transferGift', 'user_id' => $user_id, 'message_id' => $message_id));

$response = gift_send_telegram_post_json($token, 'transferGift', $telegramParams);

if ($response === null) {
    gift_send_dedup_release($dedupLockPath);
    $transportError = gift_send_telegram_transport_message();
    app_log_step_error('telegram_api_transport', array_merge(array(
        'reason' => 'telegram_api_request_failed',
        'user_id' => $user_id,
        'message_id' => $message_id,
        'method' => 'transferGift',
    ), gift_send_telegram_transport_log_context()));
    adminNotifyMessageTransfer($admin_id, $token, $user_id, $new_owner_chat_id, (string) $owned_gift_id, false, $transportError);
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => $transportError));
    exit;
}

app_log_step_telegram('telegram_api_response', $response, array('user_id' => $user_id, 'message_id' => $message_id, 'method' => 'transferGift'));

if (!gift_send_telegram_succeeded($response)) {
    gift_send_dedup_release($dedupLockPath);
    $reason = gift_send_telegram_error($response);
    adminNotifyMessageTransfer($admin_id, $token, $user_id, $new_owner_chat_id, (string) $owned_gift_id, false, $reason);
    http_response_code(502);
    echo json_encode(array(
        'ok' => false,
        'error' => $reason,
    ));
    exit;
}

app_log_step('write_lock_file', array('ok' => true, 'user_id' => $user_id, 'owned_gift_id' => (string) $owned_gift_id, 'message_id' => $message_id, 'ttl_sec' => gift_send_dedup_ttl()));

adminNotifyMessageTransfer($admin_id, $token, $user_id, $new_owner_chat_id, (string) $owned_gift_id, true, '');
app_log_step('notify_admin', array('ok' => true, 'user_id' => $user_id, 'sent' => $admin_id !== null));

app_log_step('finish', array('ok' => true, 'user_id' => $user_id, 'message_id' => $message_id, 'method' => 'transferGift'));

http_response_code(200);
echo json_encode(array(
    'ok' => true,
    'user_id' => $user_id,
    'message_id' => $message_id,
));
