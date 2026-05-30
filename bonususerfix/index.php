<?php

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Начисляет другому пользователю бота фиксированную сумму (amount в копейках из URL),
 * не зависящую от суммы заказа. Номер заказа попадает только в комментарий.
 *
 * Параметры URL: bot_id, token, bot_user_id, amount (копейки).
 * Тело вебхука: id (заказ).
 *
 * Пример URL в ЛК:
 * https://your-host/bonususerfix/index.php?bot_id=1&token=BOT_TOKEN&bot_user_id=42&amount=5000
 */

require_once dirname(__DIR__) . '/lib/request-log.php';
app_log_init(__DIR__);

$bot_id = isset($_GET['bot_id']) ? $_GET['bot_id'] : null;
$token = isset($_GET['token']) ? $_GET['token'] : null;
$bot_user_id = isset($_GET['bot_user_id']) ? $_GET['bot_user_id'] : null;
$amount = isset($_GET['amount']) ? $_GET['amount'] : null;

if ($bot_id === null || $bot_id === '' || $token === null || $token === '' || $bot_user_id === null || $bot_user_id === '' || $amount === null || $amount === '') {
    app_log('error', array('reason' => 'missing_query_params', 'query' => app_log_query_params($_GET)));
    exit('missing query params');
}

$order_id = isset($_POST['id']) ? $_POST['id'] : null;
if ($order_id === null || $order_id === '') {
    app_log('error', array('reason' => 'missing_order_id', 'bot_id' => $bot_id, 'recipient_user_id' => $bot_user_id));
    exit('not found order_id');
}

$amountKopecks = (int) $amount;

app_log('request', array(
    'bot_id' => $bot_id,
    'order_id' => $order_id,
    'recipient_user_id' => $bot_user_id,
    'amount_kopecks' => $amountKopecks,
));

$url = 'https://api.bot-t.com/v1/bot/user/add-balance?token=' . $token;
$data = array(
    'bot_id' => $bot_id,
    'user_id' => $bot_user_id,
    'sum' => round($amountKopecks / 100, 2),
    'comment' => 'Начисление отчисления от заказа фиксированного, номер заказа: ' . $order_id,
);

$options = array(
    'http' => array(
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => http_build_query($data),
        'ignore_errors' => true,
        'timeout' => 30,
    ),
);
$context = stream_context_create($options);
$result = @file_get_contents($url, false, $context);

if ($result === false) {
    app_log('error', array('reason' => 'bott_api_request_failed', 'order_id' => $order_id, 'recipient_user_id' => $bot_user_id));
    exit;
}

$decoded = json_decode($result, true);
if (is_array($decoded) && isset($decoded['result']) && !$decoded['result']) {
    app_log('error', array(
        'reason' => 'bott_api_error',
        'order_id' => $order_id,
        'recipient_user_id' => $bot_user_id,
        'message' => isset($decoded['message']) ? $decoded['message'] : 'unknown',
    ));
    exit;
}

app_log('success', array(
    'order_id' => $order_id,
    'recipient_user_id' => $bot_user_id,
    'sum_rub' => $data['sum'],
));
