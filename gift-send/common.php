<?php

/**
 * Общие функции gift-send (PHP 5.6+).
 */

function gift_send_get($source, $key, $default = null)
{
    return isset($source[$key]) ? $source[$key] : $default;
}

function gift_send_bott_post_json($url, array $payload)
{
    $options = array(
        'http' => array(
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
            'timeout' => 30,
        ),
    );
    $result = @file_get_contents($url, false, stream_context_create($options));
    if ($result === false) {
        return null;
    }
    $decoded = json_decode($result, true);

    return is_array($decoded) ? $decoded : null;
}

function gift_send_telegram_reset_transport_error()
{
    $GLOBALS['_gift_send_telegram_transport'] = null;
}

function gift_send_telegram_set_transport_error(array $error)
{
    $GLOBALS['_gift_send_telegram_transport'] = $error;
}

function gift_send_http_status_from_response_headers($headers)
{
    if (!is_array($headers) || count($headers) === 0) {
        return null;
    }
    if (preg_match('/\s(\d{3})\s/', (string) $headers[0], $matches)) {
        return (int) $matches[1];
    }

    return null;
}

function gift_send_telegram_transport_log_context()
{
    if (!isset($GLOBALS['_gift_send_telegram_transport']) || !is_array($GLOBALS['_gift_send_telegram_transport'])) {
        return array('error' => 'Telegram API request failed');
    }

    $error = $GLOBALS['_gift_send_telegram_transport'];
    $context = array(
        'error' => isset($error['message']) ? (string) $error['message'] : 'Telegram API request failed',
    );
    if (isset($error['reason'])) {
        $context['transport_reason'] = (string) $error['reason'];
    }
    if (isset($error['http_code'])) {
        $context['http_code'] = $error['http_code'];
    }
    if (isset($error['raw_preview'])) {
        $context['raw_preview'] = (string) $error['raw_preview'];
    }

    return $context;
}

function gift_send_telegram_transport_message($default = 'Telegram API request failed')
{
    $context = gift_send_telegram_transport_log_context();

    return isset($context['error']) ? (string) $context['error'] : $default;
}

function gift_send_telegram_post_json($token, $method, array $params)
{
    gift_send_telegram_reset_transport_error();

    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $options = array(
        'http' => array(
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
            'timeout' => 30,
        ),
    );
    $result = @file_get_contents($url, false, stream_context_create($options));
    $httpCode = gift_send_http_status_from_response_headers(isset($http_response_header) ? $http_response_header : null);

    if ($result === false) {
        $message = 'Telegram API request failed';
        $phpError = error_get_last();
        if (is_array($phpError) && isset($phpError['message']) && $phpError['message'] !== '') {
            $message = (string) $phpError['message'];
        }
        gift_send_telegram_set_transport_error(array(
            'reason' => 'connection_failed',
            'message' => $message,
            'http_code' => $httpCode,
        ));

        return null;
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        $preview = preg_replace('/\s+/', ' ', trim(substr($result, 0, 200)));
        gift_send_telegram_set_transport_error(array(
            'reason' => 'invalid_json_response',
            'message' => 'Telegram API returned invalid JSON',
            'http_code' => $httpCode,
            'raw_preview' => $preview !== '' ? $preview : null,
        ));

        return null;
    }

    return $decoded;
}

function gift_send_telegram_succeeded(array $response)
{
    return isset($response['ok']) && $response['ok'] === true;
}

function gift_send_telegram_error(array $response, $default = 'Telegram API error')
{
    if (isset($response['description']) && $response['description'] !== '') {
        return (string) $response['description'];
    }

    return $default;
}

function gift_send_notify_admin_pm($token, $telegramId, $text)
{
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $options = array(
        'http' => array(
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query(array(
                'chat_id' => $telegramId,
                'text' => $text,
            )),
            'ignore_errors' => true,
            'timeout' => 30,
        ),
    );
    @file_get_contents($url, false, stream_context_create($options));
}

function gift_send_build_transfer_params($businessConnectionId, $ownedGiftId, $newOwnerChatId, $starCount)
{
    $params = array(
        'business_connection_id' => $businessConnectionId,
        'owned_gift_id' => $ownedGiftId,
        'new_owner_chat_id' => $newOwnerChatId,
    );
    if ($starCount !== null && $starCount > 0) {
        $params['star_count'] = $starCount;
    }

    return $params;
}

function gift_send_get_owned_gift_id()
{
    $ownedGiftId = gift_send_get($_GET, 'owned_gift_id');
    if ($ownedGiftId === null || $ownedGiftId === '') {
        $ownedGiftId = gift_send_get($_GET, 'gift_id');
    }

    return $ownedGiftId;
}
