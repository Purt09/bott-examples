<?php

$message = json_encode($_POST);

echo json_encode([
    'result' => false,
    'message' => 'Пример answer:' . $message
]);