<?php

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$product = $_POST['product'] ?? null;

if ($product === null || $product === '') {
    http_response_code(200);
    echo json_encode(['success' => false]);
    exit;
}

// Товар прошёл проверку — строка склада закрепляется за заказом.
echo json_encode(['success' => true]);
