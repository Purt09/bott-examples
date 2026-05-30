<?php

require_once dirname(__DIR__) . '/lib/request-log.php';
app_log_init(__DIR__);

app_log('request', array(
    'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
));

echo json_encode(array(
    'result' => true,
    'data' => array(
        'is_repeat' => true,
        'message' => 'Пример answer: webhook received',
    ),
));

app_log('success', array('result' => true, 'is_repeat' => true));
