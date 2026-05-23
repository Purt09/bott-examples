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

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$bot_id = $_GET['bot_id'] ?? null;
$token = $_GET['token'] ?? null;
$gift_id = $_GET['gift_id'] ?? null;
$admin_id = null;
if (isset($_GET['admin_id']) && $_GET['admin_id'] !== '' && preg_match('/^-?\d+$/', (string) $_GET['admin_id'])) {
    $admin_id = (string) $_GET['admin_id'];
}

if ($bot_id === null || $bot_id === '' || $token === null || $token === '' || $gift_id === null || $gift_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Required query: bot_id, token, gift_id']);
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
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing order id in webhook']);
    exit;
}

$order_id = (int) $order_id;
$status = (int) ($_POST['status'] ?? -1);

// После оплаты BOT-T обычно присылает status=1 (оплачен).
if ($status !== 1) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'status_not_paid']);
    exit;
}

$sentMarker = __DIR__ . '/sent_' . $order_id . '.lock';
if (is_file($sentMarker)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'already_sent']);
    exit;
}

$url = 'https://api.bot-t.com/v1/shop/order/send-request?token=' . rawurlencode($token);

$payload = [
    'bot_id' => $bot_id,
    'order_id' => $order_id,
    'method' => 'sendGift',
    'params' => [
        'gift_id' => (string) $gift_id,
    ],
];

$response = bottPostJson($url, $payload);

if ($response === null) {
    adminNotify($admin_id, $token, $order_id, (string) $gift_id, false, 'BOT-T API request failed');
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'BOT-T API request failed']);
    exit;
}

if (empty($response['result'])) {
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
adminNotify($admin_id, $token, $order_id, (string) $gift_id, true);

http_response_code(200);
echo json_encode(['ok' => true, 'order_id' => $order_id]);
