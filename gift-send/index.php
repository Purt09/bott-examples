<?php



/**

 * Вебхук «уведомление после оплаты заказа» (BOT-T).

 *

 * Отправляет покупателю Telegram-подарок через BOT-T API (method sendGift).

 * chat_id подставляется из заказа; gift_id — из каталога Telegram (getAvailableGifts).

 * Повторный вебхук с тем же id заказа не дублирует отправку (файл sent_{id}.lock).

 *

 * Параметры URL: bot_id, token, gift_id, admin_id (необязательно — Telegram ID админа).

 * Тело вебхука: id (заказ), status (обрабатывается только status=1 — оплачен).

 *

 * Пример URL в ЛК:

 * https://your-host/gift-send/index.php?bot_id=1&token=BOT_TOKEN&gift_id=GIFT_ID&admin_id=123456789

 */



require_once dirname(__DIR__) . '/lib/request-log.php';

app_log_init(__DIR__);

app_log_begin();



header('Content-Type: application/json; charset=utf-8');



app_log_incoming([

    'http_method' => $_SERVER['REQUEST_METHOD'] ?? '',

    'query' => app_log_query_params($_GET),

    'webhook' => app_log_webhook_post_summary(),

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



function adminNotify(?string $adminTelegramId, string $token, int $orderId, string $giftId, bool $success, string $reason = ''): void

{

    if ($adminTelegramId === null) {

        return;

    }



    if ($success) {

        $text = "Подарок отправлен покупателю.\nЗаказ: #{$orderId}\nПодарок: {$giftId}";

    } else {

        $text = "Не удалось отправить подарок.\nЗаказ: #{$orderId}\nПодарок: {$giftId}\nПричина: {$reason}";

    }



    notifyAdminPm($token, $adminTelegramId, $text);

}



$order_id = $_POST['id'] ?? null;

if ($order_id === null || $order_id === '') {

    app_log_step_error('parse_webhook', ['reason' => 'missing_order_id', 'bot_id' => $bot_id, 'gift_id' => $gift_id]);

    http_response_code(400);

    echo json_encode(['ok' => false, 'error' => 'Missing order id in webhook']);

    exit;

}



$order_id = (int) $order_id;

$status = (int) ($_POST['status'] ?? -1);



$recipientTelegramId = $_POST['botUser']['user']['telegram_id'] ?? null;

$hasRecipientTelegram = $recipientTelegramId !== null

    && $recipientTelegramId !== ''

    && preg_match('/^-?\d+$/', (string) $recipientTelegramId);



app_log_step('parse_webhook', [

    'ok' => true,

    'order_id' => $order_id,

    'status' => $status,

    'has_recipient_telegram' => $hasRecipientTelegram,

]);



if ($status !== 1) {

    app_log_step_skip('check_payment_status', ['reason' => 'status_not_paid', 'order_id' => $order_id, 'status' => $status]);

    http_response_code(200);

    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'status_not_paid']);

    exit;

}



app_log_step('check_payment_status', ['ok' => true, 'order_id' => $order_id, 'status' => $status]);



$sentMarker = __DIR__ . '/sent_' . $order_id . '.lock';

if (is_file($sentMarker)) {

    app_log_step_skip('deduplication_lock', ['reason' => 'already_sent', 'order_id' => $order_id]);

    http_response_code(200);

    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'already_sent']);

    exit;

}



app_log_step('deduplication_lock', ['ok' => true, 'order_id' => $order_id]);



$giftParams = [

    'gift_id' => (string) $gift_id,

];

if ($hasRecipientTelegram) {

    $giftParams['user_id'] = (int) $recipientTelegramId;

}



$payload = [

    'bot_id' => $bot_id,

    'order_id' => $order_id,

    'method' => 'sendGift',

    'params' => $giftParams,

];



app_log_step('prepare_bott_payload', [

    'method' => 'sendGift',

    'order_id' => $order_id,

    'params_has_user_id' => isset($giftParams['user_id']),

]);



$url = 'https://api.bot-t.com/v1/shop/order/send-request?token=' . rawurlencode($token);



app_log_step('bott_api_request', ['method' => 'sendGift', 'order_id' => $order_id]);



$response = bottPostJson($url, $payload);



if ($response === null) {

    app_log_step_error('bott_api_transport', [

        'reason' => 'bott_api_request_failed',

        'order_id' => $order_id,

        'method' => 'sendGift',

    ]);

    adminNotify($admin_id, $token, $order_id, (string) $gift_id, false, 'BOT-T API request failed');

    http_response_code(502);

    echo json_encode(['ok' => false, 'error' => 'BOT-T API request failed']);

    exit;

}



app_log_step_bott('bott_api_response', $response, ['order_id' => $order_id, 'method' => 'sendGift']);



if (!bott_api_succeeded($response)) {

    $reason = $response['message'] ?? 'BOT-T API error';

    adminNotify($admin_id, $token, $order_id, (string) $gift_id, false, $reason);

    http_response_code(502);

    echo json_encode([

        'ok' => false,

        'error' => $reason,

    ]);

    exit;

}



file_put_contents($sentMarker, date('c'));

app_log_step('write_lock_file', ['ok' => true, 'order_id' => $order_id]);



adminNotify($admin_id, $token, $order_id, (string) $gift_id, true);

app_log_step('notify_admin', ['ok' => true, 'order_id' => $order_id, 'sent' => $admin_id !== null]);



app_log_step('finish', ['ok' => true, 'order_id' => $order_id, 'method' => 'sendGift', 'gift_id' => (string) $gift_id]);



http_response_code(200);

echo json_encode(['ok' => true, 'order_id' => $order_id]);


