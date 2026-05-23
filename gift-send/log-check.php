<?php

/**
 * Проверка записи log.txt (откройте в браузере один раз, затем удалите файл с сервера).
 */

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
    }
    echo "FATAL: {$err['message']}\n{$err['file']}:{$err['line']}\n";
});

header('Content-Type: text/plain; charset=utf-8');

echo 'log-check: start' . "\n";
echo 'php: ' . PHP_VERSION . "\n";
echo 'dir: ' . __DIR__ . "\n";

$logLib = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'request-log.php';

if (!is_file($logLib)) {
    http_response_code(500);
    echo "ERROR: lib/request-log.php not found\n";
    echo "expected: {$logLib}\n";
    echo "\nUpload lib/request-log.php to the project root (next to gift-send/).\n";
    exit;
}

echo "log lib: {$logLib}\n";

require_once $logLib;

if (!function_exists('app_log_init')) {
    http_response_code(500);
    echo "ERROR: app_log_init() missing after require\n";
    exit;
}

app_log_init(__DIR__);
app_log_begin();

$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'log.txt';

app_log_incoming([
    'http_method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'CLI',
    'source' => 'log-check.php',
]);

app_log_step('self_test', ['ok' => true]);

$exists = is_file($logFile);
$writableDir = is_writable(__DIR__);
$writableFile = $exists && is_writable($logFile);

$phpUser = get_current_user() ? get_current_user() : '?';
if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $pw = posix_getpwuid(posix_geteuid());
    if (is_array($pw) && isset($pw['name']) && $pw['name'] !== '') {
        $phpUser = (string) $pw['name'];
    }
}

echo "log file: {$logFile}\n";
echo 'exists: ' . ($exists ? 'yes' : 'no') . "\n";
echo 'dir writable: ' . ($writableDir ? 'yes' : 'no') . "\n";
echo 'file writable: ' . ($writableFile ? 'yes' : 'no') . "\n";
echo 'php user: ' . $phpUser . "\n";

if ($exists) {
    echo "\n--- last 5 lines of log.txt ---\n";
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        echo implode("\n", array_slice($lines, -5));
    }
}
