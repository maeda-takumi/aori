<?php
$pageTitle = 'LMessage 管理ツール | 煽り対象一覧';
require __DIR__ . '/config.php';

$messages = [];
$errors = [];
$rows = [];
$blankSupportMarkToken = '__BLANK__';

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

        $updateStmt = $pdo->prepare('UPDATE contacts SET send_at = NOW() WHERE id = :id');
        $updateStmt->execute(['id' => $contentId]);

        if ($updateStmt->rowCount() > 0) {
            echo json_encode([
                'status' => 'ok',
                'message' => '前回送信日時を記録しました。',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => '対象データを更新できませんでした。',
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
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

    $includeBlankSupportMark = in_array($blankSupportMarkToken, $selectedSupportMarks, true);
    $selectedSupportMarks = array_values(array_filter(
        $selectedSupportMarks,
        static fn(string $value): bool => $value !== $blankSupportMarkToken
    ));

    $supportMarkConditions = [];

    if (!empty($selectedSupportMarks)) {
        $supportPlaceholders = [];
        foreach ($selectedSupportMarks as $index => $supportMark) {
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
            id,
            line_display_name,
            system_display_name,
            support_mark,
            last_message_received_at,
            send_at,
            tag_hirabayashi,
            tag_shimazaki,
            tag_manpuku,
            lmessage_personal_memo,
            chat_url,
            friend_id
        FROM contacts";

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
            <span>対応マーク: <?= htmlspecialchars((string)($row['support_mark'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span>最終受信日時: <?= htmlspecialchars((string)($row['last_message_received_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span>前回送信日時: <?= htmlspecialchars((string)((isset($row['send_at']) && $row['send_at'] !== null && $row['send_at'] !== '0000-00-00 00:00:00') ? $row['send_at'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
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
              data-content-id="<?= (int)$row['id']; ?>"
              data-friend-id="<?= htmlspecialchars((string)($row['friend_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
            >
              チャット
            </button>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
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
<?php require __DIR__ . '/footer.php'; ?>
