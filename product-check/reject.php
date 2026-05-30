<?php

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'error' => 'Method not allowed'));
    exit;
}

$product = isset($_POST['product']) ? $_POST['product'] : null;

if ($product === null || $product === '') {
    http_response_code(200);
    echo json_encode(array('success' => false));
    exit;
}

// Товар не прошёл проверку — BOT-T снимет строку с продажи и возьмёт следующую.
echo json_encode(array('success' => false));
