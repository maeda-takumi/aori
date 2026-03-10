<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../config.php';

const API_TOKEN_ENV = 'AORI_FRIEND_SYNC_TOKEN';

function json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_string(mixed $value): string
{
    return trim((string)$value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, [
        'ok' => false,
        'message' => 'POSTのみ許可されています。',
    ]);
}

$expectedToken = getenv(API_TOKEN_ENV) ?: '';
if ($expectedToken !== '') {
    $requestToken = normalize_string($_SERVER['HTTP_X_API_TOKEN'] ?? '');
    if (!hash_equals($expectedToken, $requestToken)) {
        json_response(401, [
            'ok' => false,
            'message' => 'APIトークンが不正です。',
        ]);
    }
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    json_response(400, [
        'ok' => false,
        'message' => 'リクエストボディが空です。',
    ]);
}

try {
    $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    json_response(400, [
        'ok' => false,
        'message' => 'JSONの解析に失敗しました。',
        'error' => $e->getMessage(),
    ]);
}

$friends = $decoded['friends'] ?? null;
if (!is_array($friends)) {
    json_response(400, [
        'ok' => false,
        'message' => 'friends配列が必要です。',
    ]);
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$updateByLineDisplayName = $pdo->prepare(
    'UPDATE contacts
     SET friend_id = :friend_id,
         updated_at = NOW()
     WHERE line_display_name = :line_display_name'
);

$updateBySystemDisplayName = $pdo->prepare(
    'UPDATE contacts
     SET friend_id = :friend_id,
         updated_at = NOW()
     WHERE system_display_name = :line_display_name'
);

$updated = 0;
$notFound = [];
$invalid = [];

foreach ($friends as $idx => $friend) {
    if (!is_array($friend)) {
        $invalid[] = ['index' => $idx, 'reason' => 'objectではありません'];
        continue;
    }

    $friendId = normalize_string($friend['friend_id'] ?? '');
    $lineDisplayName = normalize_string($friend['line_display_name'] ?? '');

    if ($friendId === '' || $lineDisplayName === '') {
        $invalid[] = [
            'index' => $idx,
            'line_display_name' => $lineDisplayName,
            'friend_id' => $friendId,
            'reason' => 'line_display_name と friend_id は必須です',
        ];
        continue;
    }

    $updateByLineDisplayName->execute([
        ':friend_id' => $friendId,
        ':line_display_name' => $lineDisplayName,
    ]);
    $count = $updateByLineDisplayName->rowCount();

    if ($count === 0) {
        $updateBySystemDisplayName->execute([
            ':friend_id' => $friendId,
            ':line_display_name' => $lineDisplayName,
        ]);
        $count = $updateBySystemDisplayName->rowCount();
    }

    if ($count > 0) {
        $updated += $count;
        continue;
    }

    $notFound[] = [
        'index' => $idx,
        'line_display_name' => $lineDisplayName,
        'friend_id' => $friendId,
    ];
}

json_response(200, [
    'ok' => true,
    'message' => 'friend_id同期処理が完了しました。',
    'total' => count($friends),
    'updated_rows' => $updated,
    'invalid_count' => count($invalid),
    'not_found_count' => count($notFound),
    'invalid' => $invalid,
    'not_found' => $notFound,
]);
