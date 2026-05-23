<?php

/**
 * Проверка записи log.txt (откройте в браузере один раз, затем удалите файл с сервера).
 */

require_once dirname(__DIR__) . '/lib/request-log.php';
app_log_init(__DIR__);
app_log_begin();

header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/log.txt';

app_log_incoming([
    'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'source' => 'log-check.php',
]);

app_log_step('self_test', ['ok' => true]);

$exists = is_file($logFile);
$writableDir = is_writable(__DIR__);
$writableFile = $exists && is_writable($logFile);

echo "log file: {$logFile}\n";
echo 'exists: ' . ($exists ? 'yes' : 'no') . "\n";
echo 'dir writable: ' . ($writableDir ? 'yes' : 'no') . "\n";
echo 'file writable: ' . ($writableFile ? 'yes' : 'no') . "\n";
echo 'php user: ' . (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : (get_current_user() ?: '?')) . "\n";

if ($exists) {
    echo "\n--- last 5 lines of log.txt ---\n";
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        echo implode("\n", array_slice($lines, -5));
    }
}
