<?php

$message = json_encode($_POST);

echo json_encode([
    'result' => true,
    'data' => [
        'is_repeat' => true,
        'message' => 'Пример answer:' . $message,
    ]
]);