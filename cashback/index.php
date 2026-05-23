<?php

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Начисляет покупателю cashback на внутренний баланс бота: сумма заказа (amount, копейки)
 * умножается на coef и зачисляется через API add-balance.
 *
 * Параметры URL: bot_id, token, coef.
 * Тело вебхука: id (заказ), amount, botUser[id].
 *
 * Пример URL в ЛК:
 * https://your-host/cashback/index.php?bot_id=1&token=BOT_TOKEN&coef=0.05
 */

require_once dirname(__DIR__) . '/lib/request-log.php';
app_log_init(__DIR__);

$bot_id = $_GET['bot_id'] ?? null;
$token = $_GET['token'] ?? null;
$coef = $_GET['coef'] ?? null;

if ($bot_id === null || $bot_id === '' || $token === null || $token === '' || $coef === null || $coef === '') {
    app_log('error', ['reason' => 'missing_query_params', 'query' => app_log_query_params($_GET)]);
    exit('missing query params');
}

$amount = $_POST['amount'] ?? null;
$order_id = $_POST['id'] ?? null;
if ($order_id === null || $order_id === '') {
    app_log('error', ['reason' => 'missing_order_id', 'bot_id' => $bot_id]);
    exit('not found order_id');
}

$bot_user_id = $_POST['botUser']['id'] ?? null;
if ($bot_user_id === null || $bot_user_id === '') {
    app_log('error', ['reason' => 'missing_bot_user_id', 'order_id' => $order_id, 'bot_id' => $bot_id]);
    exit('not found bot_user_id');
}

$amountKopecks = (int) intval((int) $amount * (float) $coef);

app_log('request', [
    'bot_id' => $bot_id,
    'coef' => $coef,
    'order_id' => $order_id,
    'recipient_user_id' => $bot_user_id,
    'amount_kopecks' => $amountKopecks,
]);

$url = 'https://api.bot-t.com/v1/bot/user/add-balance?token=' . $token;
$data = [
    'bot_id' => $bot_id,
    'user_id' => $bot_user_id,
    'sum' => round($amountKopecks / 100, 2),
    'comment' => 'Начисление cashback системы от заказа' . $order_id,
];

$options = [
    'http' => [
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => http_build_query($data),
        'ignore_errors' => true,
        'timeout' => 30,
    ],
];
$context = stream_context_create($options);
$result = @file_get_contents($url, false, $context);

if ($result === false) {
    app_log('error', ['reason' => 'bott_api_request_failed', 'order_id' => $order_id, 'recipient_user_id' => $bot_user_id]);
    exit;
}

$decoded = json_decode($result, true);
if (is_array($decoded) && isset($decoded['result']) && !$decoded['result']) {
    app_log('error', [
        'reason' => 'bott_api_error',
        'order_id' => $order_id,
        'recipient_user_id' => $bot_user_id,
        'message' => $decoded['message'] ?? 'unknown',
    ]);
    exit;
}

app_log('success', [
    'order_id' => $order_id,
    'recipient_user_id' => $bot_user_id,
    'sum_rub' => $data['sum'],
]);
