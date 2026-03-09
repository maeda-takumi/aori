<?php

$channelAccessToken = 'gVL27Qkzaqx8wMgfZkOSTnp+A3N3vkel2074lPUd/6pN0QN98rgiXyZvDos5vVh1llMHxgBKFKHa9AJwLRLZ1uaxrZ9IQSfpRw+e86nAzJHqMCusBEKJ2dqLdEaxGAcDCRK1e4a7Gk3CT53tYGF+YQdB04t89/1O/w1cDnyilFU=';
$userId = 'U2867133f28344ba5038335da30ffca8a'; // 例: Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

$url = 'https://api.line.me/v2/bot/message/push';

$payload = [
    'to' => $userId,
    'messages' => [
        [
            'type' => 'text',
            'text' => 'テスト送信です'
        ]
    ]
];

$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $channelAccessToken
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo 'cURL Error: ' . curl_error($ch) . PHP_EOL;
}

curl_close($ch);

echo 'HTTP Code: ' . $httpCode . PHP_EOL;
echo 'Response: ' . $response . PHP_EOL;