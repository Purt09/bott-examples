<?php

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Отправляет покупателю Telegram-подарок через Telegram Bot API (sendGift).
 * Параметры URL: bot_id, token, gift_id, admin_id (необязательно).
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

function adminNotifyOrderGift($adminTelegramId, $token, $orderId, $giftId, $success, $reason)
{
    if ($adminTelegramId === null) {
        return;
    }

    if ($success) {
        $text = "Подарок отправлен покупателю.\nЗаказ: #{$orderId}\nПодарок: {$giftId}";
    } else {
        $text = "Не удалось отправить подарок.\nЗаказ: #{$orderId}\nПодарок: {$giftId}\nПричина: {$reason}";
    }

    gift_send_notify_admin_pm($token, $adminTelegramId, $text);
}

$order_id = gift_send_get($_POST, 'id');
if ($order_id === null || $order_id === '') {
    app_log_step_error('parse_webhook', array('reason' => 'missing_order_id', 'bot_id' => $bot_id, 'gift_id' => $gift_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing order id in webhook'));
    exit;
}

$order_id = (int) $order_id;
$status = (int) gift_send_get($_POST, 'status', -1);

$recipientTelegramId = null;
if (isset($_POST['botUser']['user']['telegram_id'])) {
    $recipientTelegramId = $_POST['botUser']['user']['telegram_id'];
}

$hasRecipientTelegram = $recipientTelegramId !== null
    && $recipientTelegramId !== ''
    && preg_match('/^-?\d+$/', (string) $recipientTelegramId);

app_log_step('parse_webhook', array(
    'ok' => true,
    'order_id' => $order_id,
    'status' => $status,
    'has_recipient_telegram' => $hasRecipientTelegram,
));

if ($status !== 1) {
    app_log_step_skip('check_payment_status', array('reason' => 'status_not_paid', 'order_id' => $order_id, 'status' => $status));
    http_response_code(200);
    echo json_encode(array('ok' => true, 'skipped' => true, 'reason' => 'status_not_paid'));
    exit;
}

app_log_step('check_payment_status', array('ok' => true, 'order_id' => $order_id, 'status' => $status));

if (!$hasRecipientTelegram) {
    app_log_step_error('validate_recipient', array('reason' => 'missing_recipient_telegram_id', 'order_id' => $order_id));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing botUser[user][telegram_id] in webhook'));
    exit;
}

app_log_step('validate_recipient', array('ok' => true, 'order_id' => $order_id, 'has_recipient_telegram' => true));

$sentMarker = __DIR__ . '/sent_' . $order_id . '.lock';
if (is_file($sentMarker)) {
    app_log_step_skip('deduplication_lock', array('reason' => 'already_sent', 'order_id' => $order_id));
    http_response_code(200);
    echo json_encode(array('ok' => true, 'skipped' => true, 'reason' => 'already_sent'));
    exit;
}

app_log_step('deduplication_lock', array('ok' => true, 'order_id' => $order_id));

$telegramParams = array(
    'gift_id' => (string) $gift_id,
    'user_id' => (int) $recipientTelegramId,
);

app_log_step('prepare_telegram_payload', array(
    'method' => 'sendGift',
    'order_id' => $order_id,
));

app_log_step('telegram_api_request', array('method' => 'sendGift', 'order_id' => $order_id));

$response = gift_send_telegram_post_json($token, 'sendGift', $telegramParams);

if ($response === null) {
    $transportError = gift_send_telegram_transport_message();
    app_log_step_error('telegram_api_transport', array_merge(array(
        'reason' => 'telegram_api_request_failed',
        'order_id' => $order_id,
        'method' => 'sendGift',
    ), gift_send_telegram_transport_log_context()));
    adminNotifyOrderGift($admin_id, $token, $order_id, (string) $gift_id, false, $transportError);
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => $transportError));
    exit;
}

app_log_step_telegram('telegram_api_response', $response, array('order_id' => $order_id, 'method' => 'sendGift'));

if (!gift_send_telegram_succeeded($response)) {
    $reason = gift_send_telegram_error($response);
    adminNotifyOrderGift($admin_id, $token, $order_id, (string) $gift_id, false, $reason);
    http_response_code(502);
    echo json_encode(array(
        'ok' => false,
        'error' => $reason,
    ));
    exit;
}

file_put_contents($sentMarker, date('c'));
app_log_step('write_lock_file', array('ok' => true, 'order_id' => $order_id));

adminNotifyOrderGift($admin_id, $token, $order_id, (string) $gift_id, true, '');
app_log_step('notify_admin', array('ok' => true, 'order_id' => $order_id, 'sent' => $admin_id !== null));

app_log_step('finish', array('ok' => true, 'order_id' => $order_id, 'method' => 'sendGift', 'gift_id' => (string) $gift_id));

http_response_code(200);
echo json_encode(array('ok' => true, 'order_id' => $order_id));
