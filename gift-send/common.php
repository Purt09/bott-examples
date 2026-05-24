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

function gift_send_telegram_post_json($token, $method, array $params)
{
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
    if ($result === false) {
        return null;
    }
    $decoded = json_decode($result, true);

    return is_array($decoded) ? $decoded : null;
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
