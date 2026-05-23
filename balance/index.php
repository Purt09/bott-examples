<?php

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Начисляет покупателю баланс за каждую единицу товара в заказе: count × 100 копеек × coef,
 * затем зачисление через API add-balance (сумма в рублях).
 *
 * Параметры URL: bot_id, token, coef.
 * Тело вебхука: count, botUser[id].
 *
 * Пример URL в ЛК:
 * https://your-host/balance/index.php?bot_id=1&token=BOT_TOKEN&coef=1
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

$count = $_POST['count'] ?? null;
if ($count === null || $count === '') {
    app_log('error', ['reason' => 'missing_count', 'bot_id' => $bot_id]);
    exit('not found count');
}

$bot_user_id = $_POST['botUser']['id'] ?? null;
if ($bot_user_id === null || $bot_user_id === '') {
    app_log('error', ['reason' => 'missing_bot_user_id', 'bot_id' => $bot_id, 'count' => $count]);
    exit('not found bot_user_id');
}

$amountKopecks = (int) intval((int) $count * 100 * (float) $coef);

app_log('request', [
    'bot_id' => $bot_id,
    'coef' => $coef,
    'count' => $count,
    'recipient_user_id' => $bot_user_id,
    'amount_kopecks' => $amountKopecks,
]);

$url = 'https://api.bot-t.com/v1/bot/user/add-balance?token=' . $token;
$data = [
    'bot_id' => $bot_id,
    'user_id' => $bot_user_id,
    'sum' => round($amountKopecks / 100, 2),
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
    app_log('error', ['reason' => 'bott_api_request_failed', 'recipient_user_id' => $bot_user_id, 'count' => $count]);
    exit;
}

$decoded = json_decode($result, true);
if (is_array($decoded) && isset($decoded['result']) && !$decoded['result']) {
    app_log('error', [
        'reason' => 'bott_api_error',
        'recipient_user_id' => $bot_user_id,
        'count' => $count,
        'message' => $decoded['message'] ?? 'unknown',
    ]);
    exit;
}

app_log('success', [
    'recipient_user_id' => $bot_user_id,
    'count' => $count,
    'sum_rub' => $data['sum'],
]);
