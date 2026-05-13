<?php
$pageTitle = 'Bull-Fight | 煽り対象一覧';
require __DIR__ . '/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$messages = [];
$errors = [];
$rows = [];
$blankSupportMarkToken = '__BLANK__';
$aoriLabelOptions = [
    '返信が返ってきた',
    '返信が帰って来ない',
    'FBを送っているが返信が帰って来ない',
    '荒れている',
    '普通',
    '返金、クーリングオフ',
    '会話継続',
    'サポート面談',
    'ライティング',
    '楽天ROOM',
    '塗り絵',
    '無在庫',
    '返金',
    '定期的に煽ってる',
    'カリキュラムを一度もやっていない',
    '不安がっている',
];

$curriculumStatusOptions = [
    'カリキュラム進行中',
    '旧カリキュラム止まっている',
    'クラウドワークス進行なし',
    'week1停止',
    'week2停止',
    'week3停止',
    'week4停止',
    'week5停止',
    'week6停止',
    'week7停止',
    'week8停止',
    'week9停止',
    'week10停止',
    'week11停止',
    'week12停止',
];
$ownerOptions = [
    'all' => 'すべて',
    'hirabayashi' => '平林',
    'manpuku' => '万福',
    'shimazaki' => '島崎',
    'hasegawa' => '長谷川',
];
$geminiModelOptions = [
    'gemini-3.1-flash-lite-preview' => [
        'label' => '🏆 Gemini 3.1 Flash-Lite Preview',
        'short_label' => 'Gemini 3.1 Flash-Lite Preview',
        'badge' => '性能最優先',
        'limit' => '1日上限: 要AI Studio確認（Preview）',
        'feature' => '3モデル中ベンチマーク最上位。高速・低遅延で進捗確認文の品質も狙いやすいが、Previewのため挙動や上限が変わる可能性があります。',
        'rank' => '一番優秀',
    ],
    'gemini-2.5-flash' => [
        'label' => '⚖️ Gemini 2.5 Flash',
        'short_label' => 'Gemini 2.5 Flash',
        'badge' => '安定・バランス',
        'limit' => '無料枠: 250回/日・10回/分',
        'feature' => '安定版で、品質・速度・使いやすさのバランスが良いモデル。Previewを避けたい通常運用に向いています。',
        'rank' => '安定運用',
    ],
    'gemini-2.5-flash-lite' => [
        'label' => '⚡ Gemini 2.5 Flash-Lite',
        'short_label' => 'Gemini 2.5 Flash-Lite',
        'badge' => '上限多め・低コスト',
        'limit' => '無料枠: 1,000回/日・15回/分',
        'feature' => '3モデル中もっとも軽量で、日次上限に余裕があります。大量生成や簡単な文面作成を優先するときに向いています。',
        'rank' => '大量生成',
    ],
];

function send_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_lstep_pdo(): PDO
{
    $dbPath = __DIR__ . '/data/lstep_users.db';
    if (!is_file($dbPath)) {
        throw new RuntimeException('やり取りユーザDBが見つかりません。');
    }

    $sqlitePdo = new PDO('sqlite:' . $dbPath);
    $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlitePdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $sqlitePdo;
}

function fetch_lstep_user_options(string $lineDisplayName = ''): array
{
    $sqlitePdo = get_lstep_pdo();
    $stmt = $sqlitePdo->query(
        'SELECT
            users.id,
            users.line_name,
            users.support,
            users.href,
            users.new_message_date,
            COUNT(messages.id) AS message_count,
            MAX(messages.time_sent) AS last_message_at
         FROM users
         LEFT JOIN messages ON messages.user_id = users.id
         GROUP BY users.id, users.line_name, users.support, users.href, users.new_message_date
         ORDER BY CASE WHEN users.line_name = ' . $sqlitePdo->quote($lineDisplayName) . ' THEN 0 ELSE 1 END,
                  users.line_name COLLATE NOCASE ASC,
                  users.id ASC'
    );
    $users = $stmt->fetchAll();

    $matchedUserIds = [];
    foreach ($users as &$user) {
        $isExactMatch = $lineDisplayName !== '' && (string)($user['line_name'] ?? '') === $lineDisplayName;
        $user['id'] = (int)$user['id'];
        $user['message_count'] = (int)$user['message_count'];
        $user['is_exact_match'] = $isExactMatch;
        if ($isExactMatch) {
            $matchedUserIds[] = (int)$user['id'];
        }
    }
    unset($user);

    return [$users, $matchedUserIds];
}

function fetch_lstep_conversation(int $lstepUserId): array
{
    $sqlitePdo = get_lstep_pdo();
    $userStmt = $sqlitePdo->prepare('SELECT id, line_name, support, href, new_message_date FROM users WHERE id = :id');
    $userStmt->execute(['id' => $lstepUserId]);
    $user = $userStmt->fetch();
    if ($user === false) {
        throw new RuntimeException('選択されたやり取りユーザが見つかりません。');
    }

    $messageStmt = $sqlitePdo->prepare(
        'SELECT id, sender_name, sender, message, time_sent
         FROM messages
         WHERE user_id = :user_id
         ORDER BY time_sent ASC, id ASC'
    );
    $messageStmt->execute(['user_id' => $lstepUserId]);
    $messages = $messageStmt->fetchAll();

    return [$user, $messages];
}

function build_conversation_text(array $messages, int $maxChars = 16000): string
{
    $lines = [];
    foreach ($messages as $message) {
        $sender = (string)($message['sender'] ?? '');
        $senderLabel = $sender === 'you' ? 'ユーザ' : ($sender === 'me' ? '担当者' : '不明');
        $senderName = trim((string)($message['sender_name'] ?? ''));
        if ($senderName !== '') {
            $senderLabel .= '(' . $senderName . ')';
        }
        $text = trim((string)($message['message'] ?? ''));
        if ($text === '') {
            continue;
        }
        $lines[] = '[' . (string)($message['time_sent'] ?? '') . '] ' . $senderLabel . ': ' . $text;
    }

    $result = '';
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $candidate = $lines[$i] . "\n" . $result;
        if (mb_strlen($candidate) > $maxChars) {
            break;
        }
        $result = $candidate;
    }

    return trim($result);
}

function call_gemini_api(string $model, string $prompt): string
{
    if (!defined('GEMINI_API_KEY') || trim((string)GEMINI_API_KEY) === '') {
        throw new RuntimeException('Gemini APIキーが未設定です。config.phpのGEMINI_API_KEYを設定してください。');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL拡張が有効ではありません。');
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode((string)GEMINI_API_KEY);
    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'topP' => 0.9,
            'maxOutputTokens' => 512,
        ],
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Gemini API呼び出しの初期化に失敗しました。');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false || $curlError !== '') {
        throw new RuntimeException('Gemini APIへの接続に失敗しました。');
    }

    $decoded = json_decode((string)$responseBody, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $apiMessage = is_array($decoded) ? (string)($decoded['error']['message'] ?? '') : '';
        throw new RuntimeException($apiMessage !== '' ? 'Gemini APIエラー: ' . $apiMessage : 'Gemini APIエラーが発生しました。');
    }

    $text = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if ($text === '') {
        throw new RuntimeException('Gemini APIから生成文が返りませんでした。');
    }

    return $text;
}

function get_default_ai_prompt_instruction(): string
{
    return "あなたはLINEで学習・作業進捗をサポートする担当者です。\n"
        . "以下の情報と会話ログから、ユーザの直近状況に合わせた自然な進捗確認メッセージを1通だけ作成してください。\n"
        . "条件:\n"
        . "- 日本語で、LINEにそのまま貼り付けられる文面にする\n"
        . "- 相手を責めず、前向きで返信しやすい文面にする\n"
        . "- 直近でユーザが行っていること、困っていること、止まっている箇所があれば具体的に触れる\n"
        . "- 180文字以内を目安にする\n"
        . "- 件名、説明、候補リスト、引用符は付けず、送信文のみを返す";
}

function build_ai_prompt(array $contact, array $lstepUser, array $messages, string $customPromptInstruction = ''): string
{
    $conversationText = build_conversation_text($messages);
    if ($conversationText === '') {
        $conversationText = '会話ログがありません。';
    }

    $promptInstruction = trim($customPromptInstruction);
    if ($promptInstruction === '') {
        $promptInstruction = get_default_ai_prompt_instruction();
    }

    return $promptInstruction . "\n\n"
        . "【煽り一覧側ユーザ】" . (string)($contact['line_display_name'] ?? '') . "\n"
        . "【システム表示名】" . (string)($contact['system_display_name'] ?? '') . "\n"
        . "【対応マーク】" . (string)($contact['support_mark'] ?? '') . "\n"
        . "【状態ラベル】" . (string)($contact['aori_labels'] ?? '') . "\n"
        . "【カリキュラム状況】" . (string)($contact['curriculum_status'] ?? '') . "\n"
        . "【やり取りユーザ】" . (string)($lstepUser['line_name'] ?? '') . "\n"
        . "【やり取り担当】" . (string)($lstepUser['support'] ?? '') . "\n\n"
        . "【会話ログ】\n" . $conversationText;
}

$selectedOwner = $_GET['owner_filter'] ?? 'all';
if (!array_key_exists($selectedOwner, $ownerOptions)) {
    $selectedOwner = 'all';
}

$lastMessageStaleEnabled = !isset($_GET['last_message_stale']) || $_GET['last_message_stale'] === '1';
$sendAtStaleEnabled = !isset($_GET['send_at_stale']) || $_GET['send_at_stale'] === '1';
$lastMessageDate = trim((string)($_GET['last_message_date'] ?? ''));
if ($lastMessageDate !== '') {
    $date = DateTime::createFromFormat('Y-m-d', $lastMessageDate);
    if ($date === false || $date->format('Y-m-d') !== $lastMessageDate) {
        $lastMessageDate = '';
    }
}

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $columnCheckStmt = $pdo->query("SHOW COLUMNS FROM contacts LIKE 'send_at'");
    $hasSendAt = $columnCheckStmt->fetch() !== false;
    if (!$hasSendAt) {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN send_at DATETIME NULL AFTER last_message_received_at');
        $messages[] = 'contactsテーブルにsend_atカラムを追加しました。';
    }

    $currentLogIdColumnStmt = $pdo->query("SHOW COLUMNS FROM contacts LIKE 'current_send_log_id'");
    $hasCurrentSendLogId = $currentLogIdColumnStmt->fetch() !== false;
    if (!$hasCurrentSendLogId) {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN current_send_log_id BIGINT UNSIGNED NULL AFTER send_at');
        $messages[] = 'contactsテーブルにcurrent_send_log_idカラムを追加しました。';
    }

    $sendLogTableExists = $pdo->query("SHOW TABLES LIKE 'aori_send_logs'")->fetch() !== false;
    if (!$sendLogTableExists) {
        $pdo->exec(
            'CREATE TABLE aori_send_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contact_id INT UNSIGNED NOT NULL,
                sent_at DATETIME NOT NULL,
                reverted_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_aori_send_logs_contact_sent (contact_id, sent_at),
                INDEX idx_aori_send_logs_contact_active (contact_id, reverted_at, sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $messages[] = 'aori_send_logsテーブルを作成しました。';
    }

    $needsBackfill = (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE send_at IS NOT NULL AND send_at <> "0000-00-00 00:00:00" AND current_send_log_id IS NULL')->fetchColumn() > 0;
    if ($needsBackfill) {
        $pdo->beginTransaction();
        $legacySendRows = $pdo->query(
            'SELECT id, send_at
             FROM contacts
             WHERE send_at IS NOT NULL
               AND send_at <> "0000-00-00 00:00:00"
               AND current_send_log_id IS NULL'
        )->fetchAll();
        $insertLegacyLogStmt = $pdo->prepare('INSERT INTO aori_send_logs (contact_id, sent_at, reverted_at, created_at, updated_at) VALUES (:contact_id, :sent_at, NULL, NOW(), NOW())');
        $updateCurrentLegacyLogStmt = $pdo->prepare('UPDATE contacts SET current_send_log_id = :current_send_log_id WHERE id = :id');

        foreach ($legacySendRows as $legacySendRow) {
            $insertLegacyLogStmt->execute([
                'contact_id' => (int)$legacySendRow['id'],
                'sent_at' => (string)$legacySendRow['send_at'],
            ]);
            $updateCurrentLegacyLogStmt->execute([
                'current_send_log_id' => (int)$pdo->lastInsertId(),
                'id' => (int)$legacySendRow['id'],
            ]);
        }
        $pdo->commit();
        $messages[] = '既存の前回煽り送信日時をaori_send_logsへ移行しました。';
    }
    $contactManagementExists = $pdo->query("SHOW TABLES LIKE 'contact_management'")->fetch() !== false;
    if (!$contactManagementExists) {
        $pdo->exec(
            'CREATE TABLE contact_management (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                line_user_id VARCHAR(64) NOT NULL,
                aori_labels TEXT NULL,
                curriculum_status VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_contact_management_line_user_id (line_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $messages[] = 'contact_managementテーブルを作成しました。';
    } else {
        $contactIdColumnStmt = $pdo->query("SHOW COLUMNS FROM contact_management LIKE 'contact_id'");
        $hasContactId = $contactIdColumnStmt->fetch() !== false;
        $lineUserIdColumnStmt = $pdo->query("SHOW COLUMNS FROM contact_management LIKE 'line_user_id'");
        $hasLineUserId = $lineUserIdColumnStmt->fetch() !== false;

        if (!$hasContactId && $hasLineUserId) {
            $pdo->exec('ALTER TABLE contact_management ADD COLUMN contact_id INT UNSIGNED NULL AFTER id');
            $pdo->exec(
                'UPDATE contact_management cm
                 INNER JOIN contacts c ON c.line_user_id = cm.line_user_id
                 SET cm.contact_id = c.id
                 WHERE cm.contact_id IS NULL'
            );
            $messages[] = 'contact_managementテーブルにcontact_idカラムを追加しました。';
            $hasContactId = true;
        }
        if (!$hasLineUserId && $hasContactId) {
            $pdo->exec('ALTER TABLE contact_management ADD COLUMN line_user_id VARCHAR(64) NULL AFTER id');
            $pdo->exec(
                'UPDATE contact_management cm
                 INNER JOIN contacts c ON c.id = cm.contact_id
                 SET cm.line_user_id = c.line_user_id
                 WHERE cm.line_user_id IS NULL OR cm.line_user_id = ""'
            );
            $messages[] = 'contact_managementテーブルにline_user_idカラムを追加しました。';
            $hasLineUserId = true;
        }
        $managementColumnStmt = $pdo->query("SHOW COLUMNS FROM contact_management LIKE 'aori_labels'");
        if ($managementColumnStmt->fetch() === false) {
            if ($hasContactId) {
                $pdo->exec('ALTER TABLE contact_management ADD COLUMN aori_labels TEXT NULL AFTER contact_id');
            } elseif ($hasLineUserId) {
                $pdo->exec('ALTER TABLE contact_management ADD COLUMN aori_labels TEXT NULL AFTER line_user_id');
            } else {
                $pdo->exec('ALTER TABLE contact_management ADD COLUMN aori_labels TEXT NULL');
            }
            $messages[] = 'contact_managementテーブルにaori_labelsカラムを追加しました。';
        }
        $curriculumStatusColumnStmt = $pdo->query("SHOW COLUMNS FROM contact_management LIKE 'curriculum_status'");
        if ($curriculumStatusColumnStmt->fetch() === false) {
            $pdo->exec('ALTER TABLE contact_management ADD COLUMN curriculum_status VARCHAR(255) NULL AFTER aori_labels');
            $messages[] = 'contact_managementテーブルにcurriculum_statusカラムを追加しました。';
        }
        $lstepUserIdColumnStmt = $pdo->query("SHOW COLUMNS FROM contact_management LIKE 'lstep_user_id'");
        if ($lstepUserIdColumnStmt->fetch() === false) {
            $pdo->exec('ALTER TABLE contact_management ADD COLUMN lstep_user_id INT NULL AFTER curriculum_status');
            $messages[] = 'contact_managementテーブルにlstep_user_idカラムを追加しました。';
        }
        $aiModelColumnStmt = $pdo->query("SHOW COLUMNS FROM contact_management LIKE 'ai_model'");
        if ($aiModelColumnStmt->fetch() === false) {
            $pdo->exec('ALTER TABLE contact_management ADD COLUMN ai_model VARCHAR(128) NULL AFTER lstep_user_id');
            $messages[] = 'contact_managementテーブルにai_modelカラムを追加しました。';
        }
        $aiGeneratedMessageColumnStmt = $pdo->query("SHOW COLUMNS FROM contact_management LIKE 'ai_generated_message'");
        if ($aiGeneratedMessageColumnStmt->fetch() === false) {
            $pdo->exec('ALTER TABLE contact_management ADD COLUMN ai_generated_message TEXT NULL AFTER ai_model');
            $messages[] = 'contact_managementテーブルにai_generated_messageカラムを追加しました。';
        }
        $aiGeneratedAtColumnStmt = $pdo->query("SHOW COLUMNS FROM contact_management LIKE 'ai_generated_at'");
        if ($aiGeneratedAtColumnStmt->fetch() === false) {
            $pdo->exec('ALTER TABLE contact_management ADD COLUMN ai_generated_at DATETIME NULL AFTER ai_generated_message');
            $messages[] = 'contact_managementテーブルにai_generated_atカラムを追加しました。';
        }


        if ($hasContactId) {
            $contactIdUniqueStmt = $pdo->query("SHOW INDEX FROM contact_management WHERE Key_name = 'uniq_contact_id'");
            if ($contactIdUniqueStmt->fetch() === false) {
                $pdo->exec('ALTER TABLE contact_management ADD UNIQUE KEY uniq_contact_id (contact_id)');
            }
        }

        if ($hasLineUserId) {
            $lineUserIdUniqueStmt = $pdo->query("SHOW INDEX FROM contact_management WHERE Key_name = 'uq_contact_management_line_user_id'");
            if ($lineUserIdUniqueStmt->fetch() === false) {
                $pdo->exec('ALTER TABLE contact_management ADD UNIQUE KEY uq_contact_management_line_user_id (line_user_id)');
            }
        }
    }
    $aiLogTableExists = $pdo->query("SHOW TABLES LIKE 'aori_ai_message_logs'")->fetch() !== false;
    if (!$aiLogTableExists) {
        $pdo->exec(
            'CREATE TABLE aori_ai_message_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contact_id INT UNSIGNED NOT NULL,
                line_user_id VARCHAR(64) NOT NULL,
                lstep_user_id INT NOT NULL,
                lstep_line_name VARCHAR(255) NULL,
                model VARCHAR(128) NOT NULL,
                prompt MEDIUMTEXT NULL,
                generated_message TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_aori_ai_logs_contact_created (contact_id, created_at),
                INDEX idx_aori_ai_logs_line_user_created (line_user_id, created_at),
                INDEX idx_aori_ai_logs_lstep_user_created (lstep_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $messages[] = 'aori_ai_message_logsテーブルを作成しました。';
    }

    $supportMarkStmt = $pdo->query(
        "SELECT DISTINCT support_mark
        FROM contacts
        WHERE support_mark IS NOT NULL AND support_mark <> ''
        ORDER BY support_mark ASC"
    );
    $supportMarkOptions = array_map(
        static fn(array $item): string => (string)$item['support_mark'],
        $supportMarkStmt->fetchAll()
    );
    $supportMarkOptions = array_merge([$blankSupportMarkToken], $supportMarkOptions);

    $selectedSupportMarks = $_GET['support_mark'] ?? null;
    if (!is_array($selectedSupportMarks)) {
        $selectedSupportMarks = null;
    }

    if ($selectedSupportMarks === null) {
        $selectedSupportMarks = array_values(array_filter(
            $supportMarkOptions,
            static fn(string $value): bool => mb_strpos($value, 'サポート終了') === false
                && mb_strpos($value, '返金案件') === false
                && mb_strpos($value, 'クーリングオフ') === false
                && mb_strpos($value, 'サポート期間休止中') === false
        ));
    } else {
        $selectedSupportMarks = array_values(array_intersect($supportMarkOptions, $selectedSupportMarks));
    }

    $filterQueryParams = [
        'owner_filter' => $selectedOwner,
        'last_message_stale' => $lastMessageStaleEnabled ? '1' : '0',
        'send_at_stale' => $sendAtStaleEnabled ? '1' : '0',
        'last_message_date' => $lastMessageDate,
    ];

    foreach ($selectedSupportMarks as $supportMark) {
        $filterQueryParams['support_mark'][] = $supportMark;
    }

    $filterQuery = http_build_query($filterQueryParams);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'record_send_at')) {
        header('Content-Type: application/json; charset=UTF-8');

        $contentId = filter_input(INPUT_POST, 'content_id', FILTER_VALIDATE_INT);

        if ($contentId === false || $contentId === null) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => '更新対象のIDが不正です。',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $contactExistsStmt = $pdo->prepare('SELECT id FROM contacts WHERE id = :id FOR UPDATE');
            $contactExistsStmt->execute(['id' => $contentId]);
            if ($contactExistsStmt->fetch() === false) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'message' => '対象データを更新できませんでした。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $insertLogStmt = $pdo->prepare('INSERT INTO aori_send_logs (contact_id, sent_at, reverted_at, created_at, updated_at) VALUES (:contact_id, NOW(), NULL, NOW(), NOW())');
            $insertLogStmt->execute(['contact_id' => $contentId]);
            $newLogId = (int)$pdo->lastInsertId();

            $latestSentAtStmt = $pdo->prepare('SELECT sent_at FROM aori_send_logs WHERE id = :id');
            $latestSentAtStmt->execute(['id' => $newLogId]);
            $latestSentAt = (string)$latestSentAtStmt->fetchColumn();

            $updateStmt = $pdo->prepare('UPDATE contacts SET send_at = :send_at, current_send_log_id = :current_send_log_id WHERE id = :id');
            $updateStmt->execute([
                'send_at' => $latestSentAt,
                'current_send_log_id' => $newLogId,
                'id' => $contentId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        echo json_encode([
            'status' => 'ok',
            'message' => '前回煽り送信日時を記録しました。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_aori_labels')) {
        header('Content-Type: application/json; charset=UTF-8');

        $lineUserId = trim((string)($_POST['line_user_id'] ?? ''));
        $labelMode = trim((string)($_POST['label_mode'] ?? 'aori'));
        if (!in_array($labelMode, ['aori', 'curriculum'], true)) {
            $labelMode = 'aori';
        }
        $labels = $_POST['labels'] ?? [];
        if (!is_array($labels)) {
            $labels = [];
        }

        $curriculumStatus = trim((string)($_POST['curriculum_status'] ?? ''));
        if ($lineUserId === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => '更新対象のLINEユーザーIDが不正です。',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $normalizedLabels = [];
        foreach ($labels as $label) {
            $label = trim((string)$label);
            if ($label !== '' && in_array($label, $aoriLabelOptions, true)) {
                $normalizedLabels[] = $label;
            }
        }
        $normalizedLabels = array_values(array_unique($normalizedLabels));
        $normalizedCurriculumStatus = '';
        if ($curriculumStatus !== '' && in_array($curriculumStatus, $curriculumStatusOptions, true)) {
            $normalizedCurriculumStatus = $curriculumStatus;
        }

        $storedAoriLabelText = implode('|', $normalizedLabels);
        $storedCurriculumStatus = $normalizedCurriculumStatus;

        $upsertStmt = $pdo->prepare(
            'INSERT INTO contact_management (line_user_id, aori_labels, curriculum_status, created_at, updated_at)
             VALUES (:line_user_id, :aori_labels, :curriculum_status, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
             aori_labels = VALUES(aori_labels),
             curriculum_status = VALUES(curriculum_status),
             updated_at = NOW()'
        );
        $upsertStmt->execute([
            'line_user_id' => $lineUserId,
            'aori_labels' => $storedAoriLabelText,
            'curriculum_status' => $storedCurriculumStatus,
        ]);

        echo json_encode([
            'status' => 'ok',
            'message' => '状態を保存しました。',
            'label_mode' => $labelMode,
            'labels' => $normalizedLabels,
            'curriculum_status' => $normalizedCurriculumStatus,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'list_lstep_users')) {
        $lineDisplayName = trim((string)($_POST['line_display_name'] ?? ''));
        try {
            [$lstepUsers, $matchedUserIds] = fetch_lstep_user_options($lineDisplayName);
            send_json_response([
                'status' => 'ok',
                'users' => $lstepUsers,
                'matched_user_ids' => $matchedUserIds,
            ]);
        } catch (Throwable $e) {
            send_json_response([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'generate_ai_message')) {
        $contactId = filter_input(INPUT_POST, 'contact_id', FILTER_VALIDATE_INT);
        $lineUserId = trim((string)($_POST['line_user_id'] ?? ''));
        $lstepUserId = filter_input(INPUT_POST, 'lstep_user_id', FILTER_VALIDATE_INT);
        $model = trim((string)($_POST['model'] ?? ''));
        $customPromptInstruction = trim((string)($_POST['prompt_instruction'] ?? ''));

        if ($contactId === false || $contactId === null || $contactId <= 0) {
            send_json_response(['status' => 'error', 'message' => '更新対象のIDが不正です。'], 400);
        }
        if ($lineUserId === '') {
            send_json_response(['status' => 'error', 'message' => 'LINEユーザーIDが不正です。'], 400);
        }
        if ($lstepUserId === false || $lstepUserId === null || $lstepUserId <= 0) {
            send_json_response(['status' => 'error', 'message' => 'やり取りユーザを選択してください。'], 400);
        }
        if (!array_key_exists($model, $geminiModelOptions)) {
            send_json_response(['status' => 'error', 'message' => 'Geminiモデルが不正です。'], 400);
        }
        if (mb_strlen($customPromptInstruction) > 8000) {
            send_json_response(['status' => 'error', 'message' => 'プロンプトは8000文字以内で入力してください。'], 400);
        }
        try {
            $contactStmt = $pdo->prepare(
                'SELECT
                    contacts.id,
                    contacts.line_user_id,
                    contacts.line_display_name,
                    contacts.system_display_name,
                    contacts.support_mark,
                    contacts.lmessage_personal_memo,
                    cm.aori_labels,
                    cm.curriculum_status
                 FROM contacts
                 LEFT JOIN contact_management cm ON cm.line_user_id = contacts.line_user_id
                 WHERE contacts.id = :id AND contacts.line_user_id = :line_user_id'
            );
            $contactStmt->execute([
                'id' => $contactId,
                'line_user_id' => $lineUserId,
            ]);
            $contact = $contactStmt->fetch();
            if ($contact === false) {
                send_json_response(['status' => 'error', 'message' => '対象データが見つかりません。'], 404);
            }

            [$lstepUser, $conversationMessages] = fetch_lstep_conversation($lstepUserId);
            $prompt = build_ai_prompt($contact, $lstepUser, $conversationMessages, $customPromptInstruction);
            $generatedMessage = call_gemini_api($model, $prompt);

            $pdo->beginTransaction();
            $upsertAiStmt = $pdo->prepare(
                'INSERT INTO contact_management (line_user_id, lstep_user_id, ai_model, ai_generated_message, ai_generated_at, created_at, updated_at)
                 VALUES (:line_user_id, :lstep_user_id, :ai_model, :ai_generated_message, NOW(), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                 lstep_user_id = VALUES(lstep_user_id),
                 ai_model = VALUES(ai_model),
                 ai_generated_message = VALUES(ai_generated_message),
                 ai_generated_at = VALUES(ai_generated_at),
                 updated_at = NOW()'
            );
            $upsertAiStmt->execute([
                'line_user_id' => $lineUserId,
                'lstep_user_id' => $lstepUserId,
                'ai_model' => $model,
                'ai_generated_message' => $generatedMessage,
            ]);

            $insertAiLogStmt = $pdo->prepare(
                'INSERT INTO aori_ai_message_logs (contact_id, line_user_id, lstep_user_id, lstep_line_name, model, prompt, generated_message, created_at)
                 VALUES (:contact_id, :line_user_id, :lstep_user_id, :lstep_line_name, :model, :prompt, :generated_message, NOW())'
            );
            $insertAiLogStmt->execute([
                'contact_id' => $contactId,
                'line_user_id' => $lineUserId,
                'lstep_user_id' => $lstepUserId,
                'lstep_line_name' => (string)($lstepUser['line_name'] ?? ''),
                'model' => $model,
                'prompt' => $prompt,
                'generated_message' => $generatedMessage,
            ]);
            $pdo->commit();

            send_json_response([
                'status' => 'ok',
                'message' => 'AIメッセージを生成しました。',
                'generated_message' => $generatedMessage,
                'model' => $model,
                'model_label' => $geminiModelOptions[$model]['short_label'],
                'lstep_user_id' => $lstepUserId,
                'lstep_line_name' => (string)($lstepUser['line_name'] ?? ''),
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            send_json_response([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    $conditions = [];
    $params = [];

    if ($lastMessageStaleEnabled) {
        $conditions[] = 'last_message_received_at IS NOT NULL';
        $conditions[] = 'DATE(last_message_received_at) <= (CURDATE() - INTERVAL 7 DAY)';
    }

    if ($lastMessageDate !== '') {
        $conditions[] = 'DATE(last_message_received_at) = :last_message_date';
        $params[':last_message_date'] = $lastMessageDate;
    }

    if ($sendAtStaleEnabled) {
        $conditions[] = '(send_at IS NULL OR send_at <= (NOW() - INTERVAL 7 DAY))';
    }

    $includeBlankSupportMark = in_array($blankSupportMarkToken, $selectedSupportMarks, true);
    $selectedSupportMarksForCondition = array_values(array_filter(
        $selectedSupportMarks,
        static fn(string $value): bool => $value !== $blankSupportMarkToken
    ));

    $supportMarkConditions = [];

    if (!empty($selectedSupportMarksForCondition)) {
        $supportPlaceholders = [];
        foreach ($selectedSupportMarksForCondition as $index => $supportMark) {
            $key = ':support_mark_' . $index;
            $supportPlaceholders[] = $key;
            $params[$key] = $supportMark;
        }
        $supportMarkConditions[] = 'support_mark IN (' . implode(', ', $supportPlaceholders) . ')';
    }

    if ($includeBlankSupportMark) {
        $supportMarkConditions[] = "support_mark IS NULL OR support_mark = ''";
    }

    if (!empty($supportMarkConditions)) {
        $conditions[] = '(' . implode(' OR ', $supportMarkConditions) . ')';
    } else {
        $conditions[] = '1 = 0';
    }

    if ($selectedOwner === 'hirabayashi') {
        $conditions[] = 'tag_hirabayashi = 1';
    } elseif ($selectedOwner === 'manpuku') {
        $conditions[] = 'tag_manpuku = 1';
    } elseif ($selectedOwner === 'shimazaki') {
        $conditions[] = 'tag_shimazaki = 1';
    }

    $sql = "SELECT
            contacts.id,
            contacts.line_display_name,
            contacts.system_display_name,
            contacts.support_mark,
            contacts.last_message_received_at,
            contacts.send_at,
            contacts.tag_hirabayashi,
            contacts.tag_shimazaki,
            contacts.tag_manpuku,
            contacts.lmessage_personal_memo,
            contacts.line_user_id,
            contacts.chat_url,
            contacts.friend_id,
            cm.aori_labels,
            cm.curriculum_status,
            cm.lstep_user_id,
            cm.ai_model,
            cm.ai_generated_message,
            cm.ai_generated_at
        FROM contacts
        LEFT JOIN contact_management cm ON cm.line_user_id = contacts.line_user_id";

    if (!empty($conditions)) {
        $sql .= "\nWHERE " . implode("\n  AND ", $conditions);
    }

    $sql .= "\nORDER BY last_message_received_at DESC";

    $listStmt = $pdo->prepare($sql);
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'データの取得または更新に失敗しました: ' . $e->getMessage();
    $rows = [];
    $supportMarkOptions = [];
    $selectedSupportMarks = [];
    $filterQuery = '';
}

require __DIR__ . '/header.php';
?>

<section class="hero-card glass aori-card">
  <h2>煽り対象一覧</h2>
  <p>上部の絞り込み設定で表示対象を変更できます。</p>

  <form method="get" class="aori-filter-form glass">
    <div class="aori-filter-grid">
      <label class="aori-filter-field">
        担当者
        <select name="owner_filter">
          <?php foreach ($ownerOptions as $ownerKey => $ownerLabel): ?>
            <option value="<?= htmlspecialchars($ownerKey, ENT_QUOTES, 'UTF-8'); ?>" <?= $selectedOwner === $ownerKey ? 'selected' : ''; ?>>
              <?= htmlspecialchars($ownerLabel, ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="aori-filter-field">
        対応マーク（複数選択可）
        <select name="support_mark[]" multiple size="5">
          <?php foreach ($supportMarkOptions as $supportMark): ?>
            <option value="<?= htmlspecialchars($supportMark, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($supportMark, $selectedSupportMarks, true) ? 'selected' : ''; ?>>
              <?= $supportMark === $blankSupportMarkToken ? '空欄' : htmlspecialchars($supportMark, ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="aori-check">
        <input type="hidden" name="last_message_stale" value="0">
        <input type="checkbox" name="last_message_stale" value="1" <?= $lastMessageStaleEnabled ? 'checked' : ''; ?>>
        最終受信日が1週間以上前のみ
      </label>

      <label class="aori-check">
        <input type="hidden" name="send_at_stale" value="0">
        <input type="checkbox" name="send_at_stale" value="1" <?= $sendAtStaleEnabled ? 'checked' : ''; ?>>
        前回煽り送信日が1週間以上前 または 空欄のみ
      </label>
      <label class="aori-filter-field">
        最終受信日（年月日）
        <input type="date" name="last_message_date" value="<?= htmlspecialchars($lastMessageDate, ENT_QUOTES, 'UTF-8'); ?>">
      </label>
    </div>

    <button class="btn" type="submit">絞り込む</button>
  </form>

  <div id="aori-results">
    <?php foreach ($messages as $message): ?>
      <p class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endforeach; ?>

    <?php foreach ($errors as $error): ?>
      <p class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endforeach; ?>

    <?php if (empty($rows)): ?>
      <p class="aori-empty">条件に一致するデータはありません。</p>
    <?php else: ?>
      <ul class="aori-list">
        <?php foreach ($rows as $row): ?>
          <li class="aori-item glass">
            <div class="aori-meta">
              <?php
                $savedLabels = [];
                if (!empty($row['aori_labels'])) {
                    $savedLabels = array_values(array_filter(array_map('trim', explode('|', (string)$row['aori_labels']))));
                }
              ?>
              <strong class="aori-name-row">
                <?= htmlspecialchars((string)($row['line_display_name'] ?: '名称未設定'), ENT_QUOTES, 'UTF-8'); ?>
                <button
                  class="aori-edit-icon-btn"
                  type="button"
                  data-aori-edit-button
                  data-line-user-id="<?= htmlspecialchars((string)($row['line_user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                  data-current-labels="<?= htmlspecialchars(json_encode($savedLabels, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                  data-current-curriculum-status="<?= htmlspecialchars((string)($row['curriculum_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                  aria-label="状態ラベルを編集"
                >
                  <img src="img/edit2.png" alt="編集">
                </button>
              </strong>
              <?php if (!empty($savedLabels)): ?>
                <div class="aori-label-badges">
                  <?php foreach ($savedLabels as $savedLabel): ?>
                    <span class="aori-label-badge aori-label-<?= abs(crc32($savedLabel)) % 8; ?>"><?= htmlspecialchars($savedLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($row['curriculum_status'])): ?>
                <div class="aori-label-badges">
                  <span class="aori-label-badge aori-label-curriculum"><?= htmlspecialchars((string)$row['curriculum_status'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($row['ai_generated_message'])): ?>
                <div class="aori-ai-draft">
                  <span class="aori-ai-draft__meta">
                    AI下書き<?= !empty($row['ai_generated_at']) ? '（' . htmlspecialchars((string)$row['ai_generated_at'], ENT_QUOTES, 'UTF-8') . '）' : ''; ?>
                    <?= !empty($row['ai_model']) ? ' / ' . htmlspecialchars((string)($geminiModelOptions[(string)$row['ai_model']]['short_label'] ?? $row['ai_model']), ENT_QUOTES, 'UTF-8') : ''; ?>
                  </span>
                  <p><?= nl2br(htmlspecialchars((string)$row['ai_generated_message'], ENT_QUOTES, 'UTF-8')); ?></p>
                </div>
              <?php endif; ?>
              <span>システム表示名: <?= htmlspecialchars((string)($row['system_display_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
              <span>対応マーク: <?= htmlspecialchars((string)($row['support_mark'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
              <span>最終受信日時: <?= htmlspecialchars((string)($row['last_message_received_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
              <span>前回煽り送信日時: <?= htmlspecialchars((string)((isset($row['send_at']) && $row['send_at'] !== null && $row['send_at'] !== '0000-00-00 00:00:00') ? $row['send_at'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="aori-owner-row">担当者:
                <?php if ((int)($row['tag_hirabayashi'] ?? 0) === 1): ?><span class="aori-owner-badge aori-owner-hirabayashi">平林</span><?php endif; ?>
                <?php if ((int)($row['tag_shimazaki'] ?? 0) === 1): ?><span class="aori-owner-badge aori-owner-shimazaki">島崎</span><?php endif; ?>
                <?php if ((int)($row['tag_manpuku'] ?? 0) === 1): ?><span class="aori-owner-badge aori-owner-manpuku">万福</span><?php endif; ?>
                <?php if ((int)($row['tag_hirabayashi'] ?? 0) !== 1 && (int)($row['tag_shimazaki'] ?? 0) !== 1 && (int)($row['tag_manpuku'] ?? 0) !== 1): ?>-<?php endif; ?>
              </span>
              <span class="aori-memo">メモ: <?= nl2br(htmlspecialchars((string)($row['lmessage_personal_memo'] ?? '-'), ENT_QUOTES, 'UTF-8')); ?></span>
            </div>

            <div class="aori-actions">
              <?php if (!empty($row['chat_url'])): ?>
                <!-- <a class="btn aori-link" href="<?= htmlspecialchars((string)$row['chat_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">チャットを開く</a> -->
              <?php elseif (!empty($row['friend_id'])): ?>
                <!-- <a class="btn aori-link" href="https://step.lme.jp/basic/chat-v3?friend_id=<?= urlencode((string)$row['friend_id']); ?>" target="_blank" rel="noopener noreferrer">チャットを開く</a> -->
              <?php endif; ?>

              <button
                class="btn js-chat-button"
                type="button"
                data-friend-id="<?= htmlspecialchars((string)($row['friend_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
              >
                チャット
              </button>

              <button
                class="btn js-ai-button"
                type="button"
                data-contact-id="<?= (int)$row['id']; ?>"
                data-line-user-id="<?= htmlspecialchars((string)($row['line_user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-line-display-name="<?= htmlspecialchars((string)($row['line_display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-selected-lstep-user-id="<?= htmlspecialchars((string)($row['lstep_user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-ai-model="<?= htmlspecialchars((string)($row['ai_model'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
              >
                <?= !empty($row['ai_generated_message']) ? 'AI再生成' : 'AI生成'; ?>
              </button>

              <button
                class="btn js-complete-button"
                type="button"
                data-content-id="<?= (int)$row['id']; ?>"
              >
                完了
              </button>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<div id="chat-confirm-modal" class="chat-modal" hidden>
  <div class="chat-modal__backdrop" data-chat-modal-close></div>
  <div class="chat-modal__dialog glass" role="dialog" aria-modal="true" aria-labelledby="chat-modal-title">
    <div class="chat-modal__warning" id="chat-warning-icon" hidden>
      <img src="img/signal.png" alt="警告アイコン">
    </div>
    <h3 id="chat-modal-title">確認</h3>
    <p id="chat-modal-message"></p>
    <p id="chat-modal-status" class="chat-modal__status" hidden></p>
    <div class="chat-modal__actions">
      <button type="button" class="btn chat-modal__cancel" data-chat-modal-cancel>キャンセル</button>
      <button type="button" class="btn" id="chat-modal-ok">OK</button>
    </div>
  </div>
</div>
<div id="aori-label-modal" class="chat-modal" hidden>
  <div class="chat-modal__backdrop" data-aori-modal-close></div>
  <div class="chat-modal__dialog glass" role="dialog" aria-modal="true" aria-labelledby="aori-label-modal-title">
    <h3 id="aori-label-modal-title">状態ラベル編集</h3>
    <div class="aori-label-mode-switch" role="radiogroup" aria-label="編集対象ラベル">
      <label class="aori-label-mode-option">
        <input type="radio" name="label_mode" value="aori" checked>
        <span>状態ラベル</span>
      </label>
      <label class="aori-label-mode-option">
        <input type="radio" name="label_mode" value="curriculum">
        <span>カリキュラム状況</span>
      </label>
    </div>
    <form id="aori-label-form" class="aori-label-form">
      <div data-label-panel="aori">
        <?php foreach ($aoriLabelOptions as $label): ?>
          <label class="aori-label-option">
            <input type="checkbox" name="labels[]" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div data-label-panel="curriculum" hidden>
        <label class="aori-label-option">
          <input type="radio" name="curriculum_status" value="" checked>
          <span>未設定（解除）</span>
        </label>
        <?php foreach ($curriculumStatusOptions as $status): ?>
          <label class="aori-label-option">
            <input type="radio" name="curriculum_status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
            <span><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </form>
    <p id="aori-label-modal-status" class="chat-modal__status" hidden></p>
    <div class="chat-modal__actions">
      <button type="button" class="btn chat-modal__cancel" data-aori-modal-cancel>キャンセル</button>
      <button type="button" class="btn" id="aori-label-save">保存</button>
    </div>
  </div>
</div>
<div id="aori-ai-modal" class="chat-modal" hidden>
  <div class="chat-modal__backdrop" data-ai-modal-close></div>
  <div class="chat-modal__dialog glass aori-ai-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="aori-ai-modal-title">
    <h3 id="aori-ai-modal-title">AIメッセージ生成</h3>
    <p class="aori-ai-modal__lead">Geminiモデルと参照するやり取りユーザを選択して、進捗確認メッセージの下書きを生成します。</p>
    <form id="aori-ai-form" class="aori-ai-form">
      <label class="aori-filter-field">
        Geminiモデル
        <select id="aori-ai-model" name="model">
          <?php foreach ($geminiModelOptions as $modelValue => $modelInfo): ?>
            <option
              value="<?= htmlspecialchars($modelValue, ENT_QUOTES, 'UTF-8'); ?>"
              data-badge="<?= htmlspecialchars($modelInfo['badge'], ENT_QUOTES, 'UTF-8'); ?>"
              data-limit="<?= htmlspecialchars($modelInfo['limit'], ENT_QUOTES, 'UTF-8'); ?>"
              data-feature="<?= htmlspecialchars($modelInfo['feature'], ENT_QUOTES, 'UTF-8'); ?>"
              data-rank="<?= htmlspecialchars($modelInfo['rank'], ENT_QUOTES, 'UTF-8'); ?>"
            ><?= htmlspecialchars($modelInfo['label'] . '｜' . $modelInfo['badge'] . '｜' . $modelInfo['limit'], ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
        <div class="aori-ai-model-card" id="aori-ai-model-card" aria-live="polite">
          <span class="aori-ai-model-card__badge" id="aori-ai-model-badge"></span>
          <span class="aori-ai-model-card__limit" id="aori-ai-model-limit"></span>
          <span class="aori-ai-model-card__feature" id="aori-ai-model-feature"></span>
        </div>
      </label>
      <label class="aori-filter-field">
        やり取りユーザ
        <select id="aori-ai-lstep-user" name="lstep_user_id"></select>
      </label>
      <p id="aori-ai-match-note" class="aori-ai-match-note"></p>
    </form>
    <p id="aori-ai-modal-status" class="chat-modal__status" hidden></p>
    <label class="aori-ai-result">
      生成結果
      <textarea id="aori-ai-result" rows="6" readonly></textarea>
    </label>
    <div class="chat-modal__actions">
      <button type="button" class="btn chat-modal__cancel" data-ai-modal-cancel>キャンセル</button>
      <button type="button" class="btn" id="aori-ai-generate">生成する</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
