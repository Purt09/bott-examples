<?php

require_once dirname(__DIR__) . '/lib/request-log.php';
app_log_init(__DIR__);

app_log('request', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);

echo json_encode([
    'result' => false,
    'message' => 'Пример answer: webhook received',
]);

app_log('success', ['result' => false]);
