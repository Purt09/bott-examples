<?php

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T) — передача коллекционного подарка.
 *
 * Передаёт owned_gift_id через Telegram Bot API (transferGift).
 * Параметры URL: bot_id, token, owned_gift_id (или gift_id), business_connection_id, admin_id, star_count.
 */

require_once dirname(__DIR__) . '/lib/request-log.php';
require_once __DIR__ . '/common.php';

app_log_init(__DIR__);
app_log_begin();

header('Content-Type: application/json; charset=utf-8');

app_log_incoming(array(
    'http_method' => gift_send_get($_SERVER, 'REQUEST_METHOD', ''),
    'query' => app_log_query_params($_GET),
    'webhook' => app_log_webhook_post_summary(),
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

function adminNotifyOrderTransfer($adminTelegramId, $token, $orderId, $ownedGiftId, $success, $reason)
{
    if ($adminTelegramId === null) {
        return;
    }

    if ($success) {
        $text = "Коллекционный подарок передан покупателю.\nЗаказ: #{$orderId}\nowned_gift_id: {$ownedGiftId}";
    } else {
        $text = "Не удалось передать коллекционный подарок.\nЗаказ: #{$orderId}\nowned_gift_id: {$ownedGiftId}\nПричина: {$reason}";
    }

    gift_send_notify_admin_pm($token, $adminTelegramId, $text);
}

$order_id = gift_send_get($_POST, 'id');
if ($order_id === null || $order_id === '') {
    app_log_step_error('parse_webhook', array('reason' => 'missing_order_id', 'bot_id' => $bot_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing order id in webhook'));
    exit;
}

$order_id = (int) $order_id;
$status = (int) gift_send_get($_POST, 'status', -1);

app_log_step('parse_webhook', array('ok' => true, 'order_id' => $order_id, 'status' => $status));

if ($status !== 1) {
    app_log_step_skip('check_payment_status', array('reason' => 'status_not_paid', 'order_id' => $order_id, 'status' => $status));
    http_response_code(200);
    echo json_encode(array('ok' => true, 'skipped' => true, 'reason' => 'status_not_paid'));
    exit;
}

app_log_step('check_payment_status', array('ok' => true, 'order_id' => $order_id, 'status' => $status));

$telegram_id = null;
if (isset($_POST['botUser']['user']['telegram_id'])) {
    $telegram_id = $_POST['botUser']['user']['telegram_id'];
}

if ($telegram_id === null || $telegram_id === '' || !preg_match('/^-?\d+$/', (string) $telegram_id)) {
    app_log_step_error('validate_recipient', array('reason' => 'missing_recipient_telegram_id', 'order_id' => $order_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing botUser[user][telegram_id] in webhook'));
    exit;
}

$new_owner_chat_id = (int) $telegram_id;
app_log_step('validate_recipient', array('ok' => true, 'order_id' => $order_id, 'has_recipient' => true));

$sentMarker = __DIR__ . '/sent_transfer_' . $order_id . '.lock';
if (is_file($sentMarker)) {
    app_log_step_skip('deduplication_lock', array('reason' => 'already_sent', 'order_id' => $order_id));
    http_response_code(200);
    echo json_encode(array('ok' => true, 'skipped' => true, 'reason' => 'already_sent'));
    exit;
}

app_log_step('deduplication_lock', array('ok' => true, 'order_id' => $order_id));

$telegramParams = gift_send_build_transfer_params(
    (string) $business_connection_id,
    (string) $owned_gift_id,
    $new_owner_chat_id,
    $star_count
);

app_log_step('prepare_telegram_payload', array('method' => 'transferGift', 'order_id' => $order_id));

app_log_step('telegram_api_request', array('method' => 'transferGift', 'order_id' => $order_id));

$response = gift_send_telegram_post_json($token, 'transferGift', $telegramParams);

if ($response === null) {
    $transportError = gift_send_telegram_transport_message();
    app_log_step_error('telegram_api_transport', array_merge(array(
        'reason' => 'telegram_api_request_failed',
        'order_id' => $order_id,
        'method' => 'transferGift',
    ), gift_send_telegram_transport_log_context()));
    adminNotifyOrderTransfer($admin_id, $token, $order_id, (string) $owned_gift_id, false, $transportError);
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => $transportError));
    exit;
}

app_log_step_telegram('telegram_api_response', $response, array('order_id' => $order_id, 'method' => 'transferGift'));

if (!gift_send_telegram_succeeded($response)) {
    $reason = gift_send_telegram_error($response);
    adminNotifyOrderTransfer($admin_id, $token, $order_id, (string) $owned_gift_id, false, $reason);
    http_response_code(502);
    echo json_encode(array(
        'ok' => false,
        'error' => $reason,
    ));
    exit;
}

file_put_contents($sentMarker, date('c'));
app_log_step('write_lock_file', array('ok' => true, 'order_id' => $order_id));

adminNotifyOrderTransfer($admin_id, $token, $order_id, (string) $owned_gift_id, true, '');
app_log_step('notify_admin', array('ok' => true, 'order_id' => $order_id, 'sent' => $admin_id !== null));

app_log_step('finish', array('ok' => true, 'order_id' => $order_id, 'method' => 'transferGift'));

http_response_code(200);
echo json_encode(array('ok' => true, 'order_id' => $order_id));
