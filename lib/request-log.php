<?php

/**
 * Безопасное логирование в log.txt в каталоге скрипта.
 * Не записывает token, telegram_id, admin_id и прочие чувствительные поля.
 * Совместимо с PHP 5.6+.
 */

/** @var string|null */
$GLOBALS['_app_log_dir'] = null;

/** @var string */
$GLOBALS['_app_log_script'] = 'unknown';

/** @var string|null */
$GLOBALS['_app_log_file'] = null;

/** @var int */
$GLOBALS['_app_log_step'] = 0;

/** @var string */
$GLOBALS['_app_log_trace'] = '';

/** Максимум строк в log.txt; при превышении удаляются только самые старые. */
define('APP_LOG_MAX_LINES', 200);

function app_log_init($dir, $script = null)
{
    $GLOBALS['_app_log_dir'] = $dir;
    if ($script !== null) {
        $GLOBALS['_app_log_script'] = $script;
    } else {
        $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : 'unknown';
        $GLOBALS['_app_log_script'] = basename($scriptFile);
    }
    $logFile = $dir . DIRECTORY_SEPARATOR . 'log.txt';
    $GLOBALS['_app_log_file'] = $logFile;

    if (!is_dir($dir)) {
        error_log('app_log_init: directory not found: ' . $dir);
        return;
    }

    if (!is_file($logFile)) {
        app_log_create_file($logFile);
    }
}

/**
 * Создаёт log.txt с первой служебной строкой.
 *
 * @param string $logFile
 * @return bool
 */
function app_log_create_file($logFile)
{
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        return false;
    }

    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }

    $line = date('Y-m-d H:i:s') . " [init] log file created\n";
    if (@file_put_contents($logFile, $line, LOCK_EX) !== false) {
        @chmod($logFile, 0666);
        return true;
    }

    if (@touch($logFile)) {
        @chmod($logFile, 0666);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        return is_file($logFile);
    }

    error_log('app_log_init: cannot create log file: ' . $logFile);

    return false;
}

/**
 * @param string $event
 * @param array $context
 */
function app_log($event, array $context = array())
{
    $logFile = isset($GLOBALS['_app_log_file']) ? $GLOBALS['_app_log_file'] : null;
    if ($logFile === null) {
        return;
    }

    $context['script'] = $GLOBALS['_app_log_script'];
    $safe = app_log_sanitize($context);
    $payload = $safe === array()
        ? ''
        : ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE);
    $line = date('Y-m-d H:i:s') . " [{$event}]{$payload}\n";

    if (!is_file($logFile)) {
        app_log_create_file($logFile);
    }

    $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        @chmod(dirname($logFile), 0777);
        @chmod($logFile, 0666);
        $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    if ($written === false) {
        error_log('app_log: cannot write to ' . $logFile);
        return;
    }

    app_log_trim_excess($logFile);
}

/**
 * Оставляет в файле не более APP_LOG_MAX_LINES строк (последние по времени).
 *
 * @param string $logFile
 */
function app_log_trim_excess($logFile)
{
    $maxLines = APP_LOG_MAX_LINES;
    if ($maxLines < 1) {
        return;
    }

    $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    $count = count($lines);
    if ($count <= $maxLines) {
        return;
    }

    $kept = array_slice($lines, $count - $maxLines);
    @file_put_contents($logFile, implode("\n", $kept) . "\n", LOCK_EX);
}

/**
 * Логирует ответ BOT-T API без токенов и персональных данных.
 *
 * @param string $event
 * @param array $response
 * @param array $extra
 */
function app_log_bott_response($event, array $response, array $extra = array())
{
    app_log($event, array_merge($extra, app_log_bott_summary($response)));
}

/**
 * @param array $response
 * @return array
 */
function app_log_bott_summary(array $response)
{
    $summary = array(
        'api_result' => isset($response['result']) ? $response['result'] : null,
        'message' => isset($response['message']) ? (string) $response['message'] : null,
        'code' => isset($response['code'])
            ? $response['code']
            : (isset($response['error_code']) ? $response['error_code'] : null),
    );

    if (isset($response['data']) && is_array($response['data'])) {
        $summary['data'] = app_log_sanitize($response['data']);
    }

    return $summary;
}

function app_log_begin()
{
    $GLOBALS['_app_log_step'] = 0;
    if (function_exists('random_bytes')) {
        $GLOBALS['_app_log_trace'] = bin2hex(random_bytes(4));
    } else {
        $GLOBALS['_app_log_trace'] = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
    }
}

/**
 * @param array $context
 * @return array
 */
function app_log_trace(array $context)
{
    $trace = isset($GLOBALS['_app_log_trace']) ? $GLOBALS['_app_log_trace'] : null;

    return array_merge(array('trace' => $trace), $context);
}

/**
 * @param array $context
 */
function app_log_incoming(array $context)
{
    app_log('request', app_log_trace($context));
}

/**
 * @param string $name
 * @param array $context
 */
function app_log_step($name, array $context = array())
{
    $step = isset($GLOBALS['_app_log_step']) ? (int) $GLOBALS['_app_log_step'] : 0;
    $GLOBALS['_app_log_step'] = $step + 1;
    app_log('step', app_log_trace(array_merge(array(
        'step' => $GLOBALS['_app_log_step'],
        'step_name' => $name,
    ), $context)));
}

/**
 * @param string $name
 * @param array $context
 */
function app_log_step_skip($name, array $context = array())
{
    $step = isset($GLOBALS['_app_log_step']) ? (int) $GLOBALS['_app_log_step'] : 0;
    $GLOBALS['_app_log_step'] = $step + 1;
    app_log('skip', app_log_trace(array_merge(array(
        'step' => $GLOBALS['_app_log_step'],
        'step_name' => $name,
    ), $context)));
}

/**
 * @param string $name
 * @param array $context
 */
function app_log_step_error($name, array $context = array())
{
    $step = isset($GLOBALS['_app_log_step']) ? (int) $GLOBALS['_app_log_step'] : 0;
    $GLOBALS['_app_log_step'] = $step + 1;
    app_log('error', app_log_trace(array_merge(array(
        'step' => $GLOBALS['_app_log_step'],
        'step_name' => $name,
    ), $context)));
}

/**
 * @param string $name
 * @param array $response
 * @param array $extra
 */
function app_log_step_bott($name, array $response, array $extra = array())
{
    $step = isset($GLOBALS['_app_log_step']) ? (int) $GLOBALS['_app_log_step'] : 0;
    $GLOBALS['_app_log_step'] = $step + 1;
    $level = bott_api_succeeded($response) ? 'step' : 'error';
    app_log($level, app_log_trace(array_merge(array(
        'step' => $GLOBALS['_app_log_step'],
        'step_name' => $name,
    ), $extra, app_log_bott_summary($response))));
}

/**
 * @return array
 */
function app_log_webhook_post_summary()
{
    $recipientTelegramId = null;
    if (isset($_POST['botUser']['user']['telegram_id'])) {
        $recipientTelegramId = $_POST['botUser']['user']['telegram_id'];
    }

    return array(
        'order_id' => isset($_POST['id']) ? $_POST['id'] : null,
        'status' => isset($_POST['status']) ? $_POST['status'] : null,
        'has_recipient_telegram' => $recipientTelegramId !== null
            && $recipientTelegramId !== ''
            && preg_match('/^-?\d+$/', (string) $recipientTelegramId) === 1,
    );
}

/**
 * @param array $body
 * @return array
 */
function app_log_json_body_summary(array $body)
{
    $telegramId = isset($body['telegram_id']) ? $body['telegram_id'] : null;

    return array(
        'user_id' => isset($body['user_id']) ? $body['user_id'] : null,
        'message_id' => isset($body['message_id']) ? $body['message_id'] : null,
        'has_telegram_id' => $telegramId !== null
            && $telegramId !== ''
            && preg_match('/^-?\d+$/', (string) $telegramId) === 1,
    );
}

/**
 * @param array $data
 * @return array
 */
function app_log_sanitize(array $data)
{
    $denyExact = array(
        'token',
        'password',
        'secret',
        'authorization',
        'chat_id',
        'telegram_id',
        'admin_id',
        'business_connection_id',
        'new_owner_chat_id',
        'botuser',
        'user',
        'email',
        'phone',
        'first_name',
        'last_name',
        'username',
    );

    $out = array();
    foreach ($data as $key => $value) {
        $lk = strtolower((string) $key);
        if (in_array($lk, $denyExact, true) || strpos($lk, 'token') !== false || strpos($lk, 'password') !== false) {
            continue;
        }
        if (is_array($value)) {
            $nested = app_log_sanitize($value);
            if ($nested !== array()) {
                $out[$key] = $nested;
            }
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            $out[$key] = $value;
            continue;
        }
        $out[$key] = (string) $value;
    }

    return $out;
}

/**
 * @param array $query
 * @return array
 */
function app_log_query_params(array $query)
{
    return app_log_sanitize($query);
}

/**
 * @param array $response
 * @return bool
 */
function bott_api_succeeded(array $response)
{
    if (!array_key_exists('result', $response)) {
        return false;
    }

    $result = $response['result'];

    return !($result === false || $result === 0 || $result === '0' || $result === null || $result === '');
}

/**
 * Подключает lib/request-log.php и инициализирует log.txt в каталоге скрипта.
 *
 * @param string $scriptDir
 */
function app_log_load($scriptDir)
{
    if (!function_exists('app_log')) {
        $shared = dirname($scriptDir) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'request-log.php';
        if (is_file($shared)) {
            require_once $shared;
        }
    }

    if (function_exists('app_log_init')) {
        app_log_init($scriptDir);
    }
}
