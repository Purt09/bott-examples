<?php

/**
 * Проверка записи log.txt (откройте в браузере один раз, затем удалите файл с сервера).
 */

header('Content-Type: text/plain; charset=utf-8');

$logLibCandidates = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'request-log.php',
    __DIR__ . DIRECTORY_SEPARATOR . 'request-log.php',
];

$logLib = null;
foreach ($logLibCandidates as $candidate) {
    if (is_file($candidate)) {
        $logLib = $candidate;
        break;
    }
}

if ($logLib === null) {
    http_response_code(500);
    echo "ERROR: request-log.php not found\n";
    echo 'checked:' . "\n";
    foreach ($logLibCandidates as $candidate) {
        echo "  - {$candidate}\n";
    }
    exit;
}

require_once $logLib;
app_log_init(__DIR__);
app_log_begin();

$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'log.txt';

app_log_incoming([
    'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'source' => 'log-check.php',
]);

app_log_step('self_test', ['ok' => true]);

$exists = is_file($logFile);
$writableDir = is_writable(__DIR__);
$writableFile = $exists && is_writable($logFile);

$phpUser = get_current_user() ?: '?';
if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $pw = posix_getpwuid(posix_geteuid());
    if (is_array($pw) && isset($pw['name']) && $pw['name'] !== '') {
        $phpUser = (string) $pw['name'];
    }
}

echo "log lib: {$logLib}\n";
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
