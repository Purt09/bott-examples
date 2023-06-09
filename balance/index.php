<?php

$bot_id = $_GET['bot_id'];
$token = $_GET['token'];
$coef = $_GET['coef'];

$count = $_POST['count'];
if(is_null($count)) {
    exit('not found count');
}
$bot_user_id = $_POST['botUser']['id'];
// в копейки
$amount = $count * 100;
//
$amount = intval($amount * $coef);

$url = 'https://api.bot-t.com/v1/bot/user/add-balance?token=' . $token;
$data = [
    'bot_id' => $bot_id,
    'user_id' => $bot_user_id,
    'sum' => round($amount / 100, 2),
];

// use key 'http' even if you send the request to https://...
$options = array(
    'http' => array(
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query($data)
    )
);
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);
if ($result === FALSE) { /* Handle error */ }
