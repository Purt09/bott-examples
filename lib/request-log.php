<?php

/**
 * Безопасное логирование в log.txt в каталоге скрипта.
 * Не записывает token, telegram_id, admin_id и прочие чувствительные поля.
 */

/** @var string|null */
$GLOBALS['_app_log_dir'] = null;

/** @var string */
$GLOBALS['_app_log_script'] = 'unknown';

function app_log_init(string $dir, ?string $script = null): void
{
    $GLOBALS['_app_log_dir'] = $dir;
    $GLOBALS['_app_log_script'] = $script ?? basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown');
}

/**
 * @param array<string, mixed> $context
 */
function app_log(string $event, array $context = []): void
{
    $dir = $GLOBALS['_app_log_dir'] ?? null;
    if ($dir === null) {
        return;
    }

    $context['script'] = $GLOBALS['_app_log_script'];
    $safe = app_log_sanitize($context);
    $payload = $safe === []
        ? ''
        : ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $line = date('Y-m-d H:i:s') . " [{$event}]{$payload}\n";
    @file_put_contents($dir . '/log.txt', $line, FILE_APPEND | LOCK_EX);
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
        if (in_array($lk, $denyExact, true) || str_contains($lk, 'token') || str_contains($lk, 'password')) {
            continue;
        }
        if (is_array($value)) {
            $nested = app_log_sanitize($value);
            if ($nested !== []) {
                $out[$key] = $nested;
            }
            continue;
        }
        $out[$key] = $value;
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
