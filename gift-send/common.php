<?php

/**
 * Общие функции gift-send (PHP 5.6+).
 */

require_once dirname(__DIR__) . '/lib/env.php';

function gift_send_get($source, $key, $default = null)
{
    return isset($source[$key]) ? $source[$key] : $default;
}

function gift_send_proxy_config()
{
    static $config = false;
    static $resolved = false;

    if ($resolved) {
        return $config;
    }
    $resolved = true;

    $host = trim((string) app_env('TELEGRAM_PROXY_HOST', ''));
    if ($host === '') {
        return $config;
    }

    $type = strtolower(trim((string) app_env('TELEGRAM_PROXY_TYPE', 'http')));
    if ($type !== 'socks5') {
        $type = 'http';
    }

    $port = trim((string) app_env('TELEGRAM_PROXY_PORT', ''));
    if ($port === '') {
        $port = $type === 'socks5' ? '64615' : '64614';
    }

    $config = array(
        'host' => $host,
        'port' => (int) $port,
        'type' => $type,
        'user' => trim((string) app_env('TELEGRAM_PROXY_USER', '')),
        'password' => trim((string) app_env('TELEGRAM_PROXY_PASSWORD', '')),
    );

    return $config;
}

function gift_send_apply_curl_proxy($ch, array $proxy)
{
    curl_setopt($ch, CURLOPT_PROXY, $proxy['host'] . ':' . $proxy['port']);
    if ($proxy['type'] === 'socks5') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    } else {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    }
    if ($proxy['user'] !== '') {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['user'] . ':' . $proxy['password']);
    }
}

function gift_send_http_request($url, $method, $body, array $headers = array())
{
    $proxy = gift_send_proxy_config();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        if (is_array($proxy)) {
            gift_send_apply_curl_proxy($ch, $proxy);
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return array(
            'body' => $result === false ? false : $result,
            'http_code' => $httpCode ? (int) $httpCode : null,
            'error' => $result === false ? ($curlError !== '' ? $curlError : 'HTTP request failed') : null,
        );
    }

    if (is_array($proxy) && $proxy['type'] === 'socks5') {
        return array(
            'body' => false,
            'http_code' => null,
            'error' => 'SOCKS5 proxy requires PHP cURL extension',
        );
    }

    $headerLines = $headers;
    $httpOptions = array(
        'method' => $method,
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 30,
    );

    if (is_array($proxy)) {
        $httpOptions['proxy'] = 'tcp://' . $proxy['host'] . ':' . $proxy['port'];
        $httpOptions['request_fulluri'] = true;
        if ($proxy['user'] !== '') {
            $headerLines[] = 'Proxy-Authorization: Basic ' . base64_encode($proxy['user'] . ':' . $proxy['password']);
        }
    }

    $httpOptions['header'] = implode("\r\n", $headerLines) . "\r\n";
    $result = @file_get_contents($url, false, stream_context_create(array('http' => $httpOptions)));
    $httpCode = gift_send_http_status_from_response_headers(isset($http_response_header) ? $http_response_header : null);

    if ($result === false) {
        $message = 'HTTP request failed';
        $phpError = error_get_last();
        if (is_array($phpError) && isset($phpError['message']) && $phpError['message'] !== '') {
            $message = (string) $phpError['message'];
        }

        return array(
            'body' => false,
            'http_code' => $httpCode,
            'error' => $message,
        );
    }

    return array(
        'body' => $result,
        'http_code' => $httpCode,
        'error' => null,
    );
}

function gift_send_bott_post_json($url, array $payload)
{
    $response = gift_send_http_request(
        $url,
        'POST',
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json')
    );
    if ($response['body'] === false) {
        return null;
    }
    $decoded = json_decode($response['body'], true);

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
    $response = gift_send_http_request(
        $url,
        'POST',
        json_encode($params, JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json')
    );
    $httpCode = $response['http_code'];

    if ($response['body'] === false) {
        gift_send_telegram_set_transport_error(array(
            'reason' => 'connection_failed',
            'message' => $response['error'] !== null ? (string) $response['error'] : 'Telegram API request failed',
            'http_code' => $httpCode,
        ));

        return null;
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        $preview = preg_replace('/\s+/', ' ', trim(substr($response['body'], 0, 200)));
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
    if (isset($response['ok']) && $response['ok'] === true) {
        return true;
    }

    return gift_send_telegram_is_duplicate_submit($response);
}

function gift_send_telegram_is_duplicate_submit(array $response)
{
    if (!isset($response['description'])) {
        return false;
    }

    return stripos((string) $response['description'], 'FORM_SUBMIT_DUPLICATE') !== false;
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
    gift_send_http_request(
        $url,
        'POST',
        http_build_query(array(
            'chat_id' => $telegramId,
            'text' => $text,
        )),
        array('Content-Type: application/x-www-form-urlencoded')
    );
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

function gift_send_dedup_ttl()
{
    return 120;
}

function gift_send_lock_dir($baseDir)
{
    $lockDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'lock';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }

    return $lockDir;
}

function gift_send_dedup_lock_path($baseDir, $prefix, $userId, $giftId)
{
    $safeUser = preg_replace('/[^0-9-]/', '', (string) $userId);
    $safeGift = preg_replace('/[^0-9a-zA-Z_-]/', '', (string) $giftId);
    $lockDir = gift_send_lock_dir($baseDir);

    return $lockDir . DIRECTORY_SEPARATOR . $prefix . '_' . $safeUser . '_' . $safeGift . '.lock';
}

function gift_send_dedup_processing_timeout()
{
    return 45;
}

function gift_send_dedup_parse_state($content)
{
    $content = trim((string) $content);
    if ($content === '') {
        return null;
    }
    if (preg_match('/^([pd]):(\d+)$/', $content, $matches)) {
        return array(
            'state' => $matches[1] === 'd' ? 'done' : 'processing',
            'at' => (int) $matches[2],
        );
    }
    if (preg_match('/^\d+$/', $content)) {
        return array(
            'state' => 'done',
            'at' => (int) $content,
        );
    }

    return null;
}

function gift_send_dedup_read_state($path, $fp = null)
{
    $content = '';
    if (is_resource($fp)) {
        rewind($fp);
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                break;
            }
            $content .= $chunk;
        }
    } elseif (is_readable($path)) {
        $content = (string) @file_get_contents($path);
    }

    return gift_send_dedup_parse_state($content);
}

function gift_send_dedup_write_state($path, $state, $timestamp = null)
{
    $timestamp = $timestamp !== null ? (int) $timestamp : time();
    $prefix = $state === 'done' ? 'd' : 'p';
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return false;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $prefix . ':' . $timestamp);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

function gift_send_dedup_wait_for_peer($path, $maxWaitSeconds)
{
    $deadline = time() + (int) $maxWaitSeconds;
    while (time() < $deadline) {
        usleep(500000);
        if (!is_file($path)) {
            return 'released';
        }
        $state = gift_send_dedup_read_state($path);
        if ($state !== null && $state['state'] === 'done') {
            return 'done';
        }
        if ($state !== null && $state['state'] === 'processing') {
            $age = time() - $state['at'];
            if ($age >= gift_send_dedup_processing_timeout()) {
                return 'stale';
            }
        }
    }

    return 'timeout';
}

function gift_send_dedup_skip_result($path, array $state, $ttl)
{
    $remaining = $ttl - (time() - $state['at']);
    if ($remaining < 0) {
        $remaining = 0;
    }

    return array(
        'action' => 'skip',
        'path' => $path,
        'reason' => 'already_sent',
        'locked_at' => $state['at'],
        'ttl' => $ttl,
        'remaining' => $remaining,
    );
}

function gift_send_dedup_try_acquire_once($path, $ttl, $processingTimeout)
{
    $now = time();
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return array(
            'action' => 'error',
            'path' => $path,
            'ttl' => $ttl,
        );
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return array(
            'action' => 'error',
            'path' => $path,
            'ttl' => $ttl,
        );
    }

    $state = gift_send_dedup_read_state($path, $fp);
    if ($state !== null) {
        $age = $now - $state['at'];
        if ($state['state'] === 'done' && $age < $ttl) {
            flock($fp, LOCK_UN);
            fclose($fp);

            return gift_send_dedup_skip_result($path, $state, $ttl);
        }
        if ($state['state'] === 'processing' && $age < $processingTimeout) {
            flock($fp, LOCK_UN);
            fclose($fp);

            return array(
                'action' => 'wait',
                'path' => $path,
                'ttl' => $ttl,
                'locked_at' => $state['at'],
            );
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, 'p:' . $now);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return array(
        'action' => 'proceed',
        'path' => $path,
        'locked_at' => $now,
        'ttl' => $ttl,
    );
}

/**
 * Блокирует повторную отправку одному user_id того же gift_id на gift_send_dedup_ttl() секунд.
 * Lock ставится до вызова Telegram API; параллельные запросы ждут завершения первого.
 *
 * @return array action: proceed|skip|error, path, locked_at, ttl, remaining, reason
 */
function gift_send_dedup_acquire($dir, $prefix, $userId, $giftId)
{
    $ttl = gift_send_dedup_ttl();
    $processingTimeout = gift_send_dedup_processing_timeout();
    $path = gift_send_dedup_lock_path($dir, $prefix, $userId, $giftId);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $result = gift_send_dedup_try_acquire_once($path, $ttl, $processingTimeout);
        if ($result['action'] !== 'wait') {
            return $result;
        }

        $waitResult = gift_send_dedup_wait_for_peer($path, $processingTimeout + 5);
        if ($waitResult === 'done') {
            $state = gift_send_dedup_read_state($path);
            if ($state !== null) {
                return gift_send_dedup_skip_result($path, $state, $ttl);
            }

            return gift_send_dedup_skip_result($path, array('state' => 'done', 'at' => time()), $ttl);
        }
    }

    return gift_send_dedup_try_acquire_once($path, $ttl, 0);
}

function gift_send_dedup_mark_done($path)
{
    gift_send_dedup_write_state($path, 'done');
}

function gift_send_dedup_release($path)
{
    if ($path !== null && $path !== '' && is_file($path)) {
        @unlink($path);
    }
}
