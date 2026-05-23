<?php

require_once dirname(__DIR__) . '/lib/request-log.php';
app_log_init(__DIR__);

app_log('request', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'has_post' => $_POST !== [],
    'has_get' => $_GET !== [],
]);

file_put_contents(
    'post.txt',
    json_encode($_POST)
);
file_put_contents(
    'get.txt',
    json_encode($_GET)
);

app_log('success', ['saved' => ['post.txt', 'get.txt']]);
