<?php

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T) — передача коллекционного подарка.
 *
 * Передаёт уже имеющийся коллекционный подарок покупателю через BOT-T API (method transferGift).
 * Не покупает новый подарок (sendGift), а передаёт owned_gift_id из инвентаря business-аккаунта.
 *
 * Параметры URL:
 *   bot_id, token, owned_gift_id, business_connection_id — обязательные;
 *   admin_id — необязательно, Telegram ID админа;
 *   star_count — необязательно, Stars за платную передачу.
 *
 * Тело вебхука: id (заказ), status (только status=1), botUser[user][telegram_id] — получатель.
 *
 * owned_gift_id — ID подарка в инвентаре (getBusinessAccountGifts / OwnedGiftUnique.owned_gift_id).
 * business_connection_id — ID business-подключения бота в Telegram.
 *
 * Пример URL в ЛК:
 * https://your-host/gift-send/index-transfer.php?bot_id=1&token=BOT_TOKEN&owned_gift_id=OWNED_GIFT_ID&business_connection_id=BC_ID&admin_id=123456789
 *
 * Повторный вебхук с тем же id заказа не дублирует передачу (файл sent_transfer_{id}.lock).
 */

require_once dirname(__DIR__) . '/lib/request-log.php';
app_log_init(__DIR__);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_log('error', ['reason' => 'method_not_allowed', 'method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$bot_id = $_GET['bot_id'] ?? null;
$token = $_GET['token'] ?? null;
$owned_gift_id = $_GET['owned_gift_id'] ?? null;
$business_connection_id = $_GET['business_connection_id'] ?? null;
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
    app_log('error', ['reason' => 'missing_query_params', 'query' => app_log_query_params($_GET)]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Required query: bot_id, token, owned_gift_id, business_connection_id']);
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

function adminNotify(?string $adminTelegramId, string $token, int $orderId, string $ownedGiftId, bool $success, string $reason = ''): void
{
    if ($adminTelegramId === null) {
        return;
    }

    if ($success) {
        $text = "Коллекционный подарок передан покупателю.\nЗаказ: #{$orderId}\nowned_gift_id: {$ownedGiftId}";
    } else {
        $text = "Не удалось передать коллекционный подарок.\nЗаказ: #{$orderId}\nowned_gift_id: {$ownedGiftId}\nПричина: {$reason}";
    }

    notifyAdminPm($token, $adminTelegramId, $text);
}

/**
 * @return array<string, mixed>
 */
function buildTransferParams(string $businessConnectionId, string $ownedGiftId, int $newOwnerChatId, ?int $starCount): array
{
    $params = [
        'business_connection_id' => $businessConnectionId,
        'owned_gift_id' => $ownedGiftId,
        'new_owner_chat_id' => $newOwnerChatId,
    ];
    if ($starCount !== null && $starCount > 0) {
        $params['star_count'] = $starCount;
    }

    return $params;
}

$order_id = $_POST['id'] ?? null;
if ($order_id === null || $order_id === '') {
    app_log('error', ['reason' => 'missing_order_id', 'bot_id' => $bot_id, 'owned_gift_id' => (string) $owned_gift_id]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing order id in webhook']);
    exit;
}

$order_id = (int) $order_id;
$status = (int) ($_POST['status'] ?? -1);

app_log('request', [
    'bot_id' => $bot_id,
    'owned_gift_id' => (string) $owned_gift_id,
    'order_id' => $order_id,
    'status' => $status,
    'star_count' => $star_count,
]);

if ($status !== 1) {
    app_log('skip', ['reason' => 'status_not_paid', 'order_id' => $order_id, 'status' => $status]);
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'status_not_paid']);
    exit;
}

$telegram_id = $_POST['botUser']['user']['telegram_id'] ?? null;
if ($telegram_id === null || $telegram_id === '' || !preg_match('/^-?\d+$/', (string) $telegram_id)) {
    app_log('error', ['reason' => 'missing_recipient_telegram_id', 'order_id' => $order_id]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing botUser[user][telegram_id] in webhook']);
    exit;
}

$new_owner_chat_id = (int) $telegram_id;

$sentMarker = __DIR__ . '/sent_transfer_' . $order_id . '.lock';
if (is_file($sentMarker)) {
    app_log('skip', ['reason' => 'already_sent', 'order_id' => $order_id]);
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'already_sent']);
    exit;
}

$url = 'https://api.bot-t.com/v1/shop/order/send-request?token=' . rawurlencode($token);

$payload = [
    'bot_id' => $bot_id,
    'order_id' => $order_id,
    'method' => 'transferGift',
    'params' => buildTransferParams(
        (string) $business_connection_id,
        (string) $owned_gift_id,
        $new_owner_chat_id,
        $star_count
    ),
];

$response = bottPostJson($url, $payload);

if ($response === null) {
    app_log('error', ['reason' => 'bott_api_request_failed', 'order_id' => $order_id, 'method' => 'transferGift']);
    adminNotify($admin_id, $token, $order_id, (string) $owned_gift_id, false, 'BOT-T API request failed');
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'BOT-T API request failed']);
    exit;
}

if (empty($response['result'])) {
    $reason = $response['message'] ?? 'BOT-T API error';
    app_log('error', ['reason' => 'bott_api_error', 'order_id' => $order_id, 'method' => 'transferGift', 'message' => $reason]);
    adminNotify($admin_id, $token, $order_id, (string) $owned_gift_id, false, $reason);
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $reason,
    ]);
    exit;
}

file_put_contents($sentMarker, date('c'));
adminNotify($admin_id, $token, $order_id, (string) $owned_gift_id, true);
app_log('success', ['order_id' => $order_id, 'method' => 'transferGift', 'owned_gift_id' => (string) $owned_gift_id]);

http_response_code(200);
echo json_encode(['ok' => true, 'order_id' => $order_id]);
