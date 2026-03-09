<?php
$pageTitle = 'LMessage 管理ツール | 煽り対象一覧';
require __DIR__ . '/config.php';

$messages = [];
$errors = [];
$rows = [];

$ownerOptions = [
    'all' => 'すべて',
    'hirabayashi' => '平林',
    'manpuku' => '万福',
    'shimazaki' => '島崎',
];

$selectedOwner = $_GET['owner_filter'] ?? 'all';
if (!array_key_exists($selectedOwner, $ownerOptions)) {
    $selectedOwner = 'all';
}

$lastMessageStaleEnabled = !isset($_GET['last_message_stale']) || $_GET['last_message_stale'] === '1';
$sendAtStaleEnabled = !isset($_GET['send_at_stale']) || $_GET['send_at_stale'] === '1';

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

    $excludedSupportMarks = $_GET['support_mark'] ?? null;
    if (!is_array($excludedSupportMarks)) {
        $excludedSupportMarks = null;
    }

    if ($excludedSupportMarks === null) {
        $excludedSupportMarks = array_values(array_filter(
            $supportMarkOptions,
            static fn(string $value): bool => $value === 'サポート期間休止中（お客様都合）' || mb_strpos($value, 'サポート終了') !== false
        ));
    } else {
        $excludedSupportMarks = array_values(array_intersect($supportMarkOptions, $excludedSupportMarks));
    }

    $filterQueryParams = [
        'owner_filter' => $selectedOwner,
        'last_message_stale' => $lastMessageStaleEnabled ? '1' : '0',
        'send_at_stale' => $sendAtStaleEnabled ? '1' : '0',
    ];

    foreach ($excludedSupportMarks as $supportMark) {
        $filterQueryParams['support_mark'][] = $supportMark;
    }

    $filterQuery = http_build_query($filterQueryParams);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content_id'])) {
        $contentId = filter_input(INPUT_POST, 'content_id', FILTER_VALIDATE_INT);

        if ($contentId === false || $contentId === null) {
            $errors[] = '更新対象のIDが不正です。';
        } else {
            $updateStmt = $pdo->prepare('UPDATE contacts SET send_at = NOW() WHERE id = :id');
            $updateStmt->execute(['id' => $contentId]);

            if ($updateStmt->rowCount() > 0) {
                $messages[] = 'send_atを現在時刻で更新しました。';
            } else {
                $errors[] = '対象データを更新できませんでした。';
            }
        }
    }

    $conditions = [];
    $params = [];

    if ($lastMessageStaleEnabled) {
        $conditions[] = 'last_message_received_at IS NOT NULL';
        $conditions[] = 'last_message_received_at <= (NOW() - INTERVAL 7 DAY)';
    }

    if ($sendAtStaleEnabled) {
        $conditions[] = '(send_at IS NULL OR send_at <= (NOW() - INTERVAL 7 DAY))';
    }

    if (!empty($excludedSupportMarks)) {
        $supportPlaceholders = [];
        foreach ($excludedSupportMarks as $index => $supportMark) {
            $key = ':support_mark_' . $index;
            $supportPlaceholders[] = $key;
            $params[$key] = $supportMark;
        }
        $conditions[] = 'support_mark NOT IN (' . implode(', ', $supportPlaceholders) . ')';
    }

    if ($selectedOwner === 'hirabayashi') {
        $conditions[] = 'tag_hirabayashi = 1';
    } elseif ($selectedOwner === 'manpuku') {
        $conditions[] = 'tag_manpuku = 1';
    } elseif ($selectedOwner === 'shimazaki') {
        $conditions[] = 'tag_shimazaki = 1';
    }

    $sql = "SELECT
            id,
            line_display_name,
            system_display_name,
            line_user_id,
            support_mark,
            last_message_received_at,
            send_at,
            chat_url,
            friend_id
        FROM contacts";

    if (!empty($conditions)) {
        $sql .= "\nWHERE " . implode("\n  AND ", $conditions);
    }

    $sql .= "\nORDER BY last_message_received_at ASC";

    $listStmt = $pdo->prepare($sql);
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'データの取得または更新に失敗しました: ' . $e->getMessage();
    $rows = [];
    $supportMarkOptions = [];
    $excludedSupportMarks = [];
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
            <option value="<?= htmlspecialchars($supportMark, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($supportMark, $excludedSupportMarks, true) ? 'selected' : ''; ?>>
              <?= htmlspecialchars($supportMark, ENT_QUOTES, 'UTF-8'); ?>
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
        前回送信日が1週間以上前 または 空欄のみ
      </label>
    </div>

    <button class="btn" type="submit">絞り込む</button>
  </form>

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
            <strong><?= htmlspecialchars((string)($row['line_display_name'] ?: '名称未設定'), ENT_QUOTES, 'UTF-8'); ?></strong>
            <span>システム表示名: <?= htmlspecialchars((string)($row['system_display_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span>LINEユーザーID: <?= htmlspecialchars((string)($row['line_user_id'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span>support_mark: <?= htmlspecialchars((string)($row['support_mark'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span>最終受信日時: <?= htmlspecialchars((string)($row['last_message_received_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span>前回送信日時: <?= htmlspecialchars((string)($row['send_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>

          <div class="aori-actions">
            <?php if (!empty($row['chat_url'])): ?>
              <a class="btn aori-link" href="<?= htmlspecialchars((string)$row['chat_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">チャットを開く</a>
            <?php elseif (!empty($row['friend_id'])): ?>
              <a class="btn aori-link" href="https://step.lme.jp/basic/chat-v3?friend_id=<?= urlencode((string)$row['friend_id']); ?>" target="_blank" rel="noopener noreferrer">チャットを開く</a>
            <?php endif; ?>

            <form method="post" action="?<?= htmlspecialchars($filterQuery, ENT_QUOTES, 'UTF-8'); ?>" class="aori-form">
              <input type="hidden" name="content_id" value="<?= (int)$row['id']; ?>">
              <button class="btn" type="submit">チャット</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php'; ?>
