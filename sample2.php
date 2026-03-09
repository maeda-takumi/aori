<?php

function getLineProfile(string $userId, string $channelAccessToken): ?array
{
    $url = 'https://api.line.me/v2/bot/profile/' . urlencode($userId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $channelAccessToken,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

$userId = 'U2867133f28344ba5038335da30ffca8a';
$token  = 'gVL27Qkzaqx8wMgfZkOSTnp+A3N3vkel2074lPUd/6pN0QN98rgiXyZvDos5vVh1llMHxgBKFKHa9AJwLRLZ1uaxrZ9IQSfpRw+e86nAzJHqMCusBEKJ2dqLdEaxGAcDCRK1e4a7Gk3CT53tYGF+YQdB04t89/1O/w1cDnyilFU=';

$profile = getLineProfile($userId, $token);

if ($profile) {
    echo $profile['pictureUrl'] ?? 'pictureUrlなし';
} else {
    echo '取得失敗';
}