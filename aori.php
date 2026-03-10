<?php
$pageTitle = 'Bull-Fight | 煽り対象一覧';
require __DIR__ . '/config.php';

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

    $contactManagementExists = $pdo->query("SHOW TABLES LIKE 'contact_management'")->fetch() !== false;
    if (!$contactManagementExists) {
        $pdo->exec(
            'CREATE TABLE contact_management (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                line_user_id VARCHAR(64) NOT NULL,
                aori_labels TEXT NULL,
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
                'message' => '前回煽り送信日時を記録しました。',
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_aori_labels')) {
        header('Content-Type: application/json; charset=UTF-8');

        $lineUserId = trim((string)($_POST['line_user_id'] ?? ''));
        $labels = $_POST['labels'] ?? [];
        if (!is_array($labels)) {
            $labels = [];
        }

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
        $labelText = implode('|', $normalizedLabels);

        $upsertStmt = $pdo->prepare(
            'INSERT INTO contact_management (line_user_id, aori_labels, created_at, updated_at)
             VALUES (:line_user_id, :aori_labels, NOW(), NOW())
             ON DUPLICATE KEY UPDATE aori_labels = VALUES(aori_labels), updated_at = NOW()'
        );
        $upsertStmt->execute([
            'line_user_id' => $lineUserId,
            'aori_labels' => $labelText,
        ]);

        echo json_encode([
            'status' => 'ok',
            'message' => '状態を保存しました。',
            'labels' => $normalizedLabels,
        ], JSON_UNESCAPED_UNICODE);
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
            cm.aori_labels
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
    <form id="aori-label-form" class="aori-label-form">
      <?php foreach ($aoriLabelOptions as $label): ?>
        <label class="aori-label-option">
          <input type="checkbox" name="labels[]" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
          <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
        </label>
      <?php endforeach; ?>
    </form>
    <p id="aori-label-modal-status" class="chat-modal__status" hidden></p>
    <div class="chat-modal__actions">
      <button type="button" class="btn chat-modal__cancel" data-aori-modal-cancel>キャンセル</button>
      <button type="button" class="btn" id="aori-label-save">保存</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
