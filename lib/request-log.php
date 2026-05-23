<?php

/**
 * Безопасное логирование в log.txt в каталоге скрипта.
 * Не записывает token, telegram_id, admin_id и прочие чувствительные поля.
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
const APP_LOG_MAX_LINES = 200;

function app_log_init(string $dir, ?string $script = null): void
{
    $GLOBALS['_app_log_dir'] = $dir;
    $GLOBALS['_app_log_script'] = $script ?? basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown');
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
 */
function app_log_create_file(string $logFile): bool
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
 * @param array<string, mixed> $context
 */
function app_log(string $event, array $context = []): void
{
    $logFile = $GLOBALS['_app_log_file'] ?? null;
    if ($logFile === null) {
        return;
    }

    $context['script'] = $GLOBALS['_app_log_script'];
    $safe = app_log_sanitize($context);
    $payload = $safe === []
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
 */
function app_log_trim_excess(string $logFile): void
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
 * @param array<string, mixed> $response
 * @param array<string, mixed> $extra
 */
function app_log_bott_response(string $event, array $response, array $extra = []): void
{
    app_log($event, array_merge($extra, app_log_bott_summary($response)));
}

/**
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function app_log_bott_summary(array $response): array
{
    $summary = [
        'api_result' => $response['result'] ?? null,
        'message' => isset($response['message']) ? (string) $response['message'] : null,
        'code' => $response['code'] ?? $response['error_code'] ?? null,
    ];

    if (isset($response['data']) && is_array($response['data'])) {
        $summary['data'] = app_log_sanitize($response['data']);
    }

    return $summary;
}

function app_log_begin(): void
{
    $GLOBALS['_app_log_step'] = 0;
    if (function_exists('random_bytes')) {
        $GLOBALS['_app_log_trace'] = bin2hex(random_bytes(4));
    } else {
        $GLOBALS['_app_log_trace'] = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
    }
}

/**
 * @param array<string, mixed> $context
 */
function app_log_trace(array $context): array
{
    return array_merge(['trace' => $GLOBALS['_app_log_trace'] ?? null], $context);
}

/**
 * @param array<string, mixed> $context
 */
function app_log_incoming(array $context): void
{
    app_log('request', app_log_trace($context));
}

/**
 * @param array<string, mixed> $context
 */
function app_log_step(string $name, array $context = []): void
{
    $GLOBALS['_app_log_step'] = (int) ($GLOBALS['_app_log_step'] ?? 0) + 1;
    app_log('step', app_log_trace(array_merge([
        'step' => $GLOBALS['_app_log_step'],
        'step_name' => $name,
    ], $context)));
}

/**
 * @param array<string, mixed> $context
 */
function app_log_step_skip(string $name, array $context = []): void
{
    $GLOBALS['_app_log_step'] = (int) ($GLOBALS['_app_log_step'] ?? 0) + 1;
    app_log('skip', app_log_trace(array_merge([
        'step' => $GLOBALS['_app_log_step'],
        'step_name' => $name,
    ], $context)));
}

/**
 * @param array<string, mixed> $context
 */
function app_log_step_error(string $name, array $context = []): void
{
    $GLOBALS['_app_log_step'] = (int) ($GLOBALS['_app_log_step'] ?? 0) + 1;
    app_log('error', app_log_trace(array_merge([
        'step' => $GLOBALS['_app_log_step'],
        'step_name' => $name,
    ], $context)));
}

/**
 * @param array<string, mixed> $response
 * @param array<string, mixed> $extra
 */
function app_log_step_bott(string $name, array $response, array $extra = []): void
{
    $GLOBALS['_app_log_step'] = (int) ($GLOBALS['_app_log_step'] ?? 0) + 1;
    $level = bott_api_succeeded($response) ? 'step' : 'error';
    app_log($level, app_log_trace(array_merge([
        'step' => $GLOBALS['_app_log_step'],
        'step_name' => $name,
    ], $extra, app_log_bott_summary($response))));
}

/**
 * @return array<string, mixed>
 */
function app_log_webhook_post_summary(): array
{
    $recipientTelegramId = $_POST['botUser']['user']['telegram_id'] ?? null;

    return [
        'order_id' => $_POST['id'] ?? null,
        'status' => $_POST['status'] ?? null,
        'has_recipient_telegram' => $recipientTelegramId !== null
            && $recipientTelegramId !== ''
            && preg_match('/^-?\d+$/', (string) $recipientTelegramId) === 1,
    ];
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function app_log_json_body_summary(array $body): array
{
    $telegramId = $body['telegram_id'] ?? null;

    return [
        'user_id' => $body['user_id'] ?? null,
        'message_id' => $body['message_id'] ?? null,
        'has_telegram_id' => $telegramId !== null
            && $telegramId !== ''
            && preg_match('/^-?\d+$/', (string) $telegramId) === 1,
    ];
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function app_log_sanitize(array $data): array
{
    $denyExact = [
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
    ];

    $out = [];
    foreach ($data as $key => $value) {
        $lk = strtolower((string) $key);
        if (in_array($lk, $denyExact, true) || strpos($lk, 'token') !== false || strpos($lk, 'password') !== false) {
            continue;
        }
        if (is_array($value)) {
            $nested = app_log_sanitize($value);
            if ($nested !== []) {
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
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function app_log_query_params(array $query): array
{
    return app_log_sanitize($query);
}

/**
 * @param array<string, mixed> $response
 */
function bott_api_succeeded(array $response): bool
{
    if (!array_key_exists('result', $response)) {
        return false;
    }

    $result = $response['result'];

    return !($result === false || $result === 0 || $result === '0' || $result === null || $result === '');
}

/**
 * Подключает lib/request-log.php и инициализирует log.txt в каталоге скрипта.
 */
function app_log_load(string $scriptDir): void
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
