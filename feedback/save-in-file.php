<?php

require_once dirname(__DIR__) . '/lib/request-log.php';
app_log_init(__DIR__);

app_log('request', array(
    'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
    'has_post' => $_POST !== array(),
    'has_get' => $_GET !== array(),
));

file_put_contents(
    'post.txt',
    json_encode($_POST)
);
file_put_contents(
    'get.txt',
    json_encode($_GET)
);

app_log('success', array('saved' => array('post.txt', 'get.txt')));
