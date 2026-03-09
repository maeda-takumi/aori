<?php
$pageTitle = 'LMessage 管理ツール | 煽り対象一覧';
require __DIR__ . '/config.php';

$messages = [];
$errors = [];

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $columnCheckStmt = $pdo->query("SHOW COLUMNS FROM contents LIKE 'send_at'");
    $hasSendAt = $columnCheckStmt->fetch() !== false;
    if (!$hasSendAt) {
        $pdo->exec('ALTER TABLE contents ADD COLUMN send_at DATETIME NULL AFTER last_message_received_at');
        $messages[] = 'contentsテーブルにsend_atカラムを追加しました。';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content_id'])) {
        $contentId = filter_input(INPUT_POST, 'content_id', FILTER_VALIDATE_INT);

        if ($contentId === false || $contentId === null) {
            $errors[] = '更新対象のIDが不正です。';
        } else {
            $updateStmt = $pdo->prepare('UPDATE contents SET send_at = NOW() WHERE id = :id');
            $updateStmt->execute(['id' => $contentId]);

            if ($updateStmt->rowCount() > 0) {
                $messages[] = 'send_atを現在時刻で更新しました。';
            } else {
                $errors[] = '対象データを更新できませんでした。';
            }
        }
    }

    $listStmt = $pdo->query(
        "SELECT
            id,
            line_display_name,
            system_display_name,
            line_user_id,
            support_mark,
            last_message_received_at,
            send_at,
            chat_url,
            friend_id
        FROM contents
        WHERE
            last_message_received_at IS NOT NULL
            AND last_message_received_at <= (NOW() - INTERVAL 7 DAY)
            AND (send_at IS NULL OR send_at <= (NOW() - INTERVAL 7 DAY))
            AND (support_mark IS NULL OR support_mark NOT LIKE '%サポート終了%')
        ORDER BY last_message_received_at ASC"
    );
    $rows = $listStmt->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'データの取得または更新に失敗しました: ' . $e->getMessage();
    $rows = [];
}

require __DIR__ . '/header.php';
?>

<section class="hero-card glass aori-card">
  <h2>煽り対象一覧</h2>
  <p>最終受信日・送信日・サポートマークの条件で抽出したユーザーを表示しています。</p>

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
            <span>最終受信日時: <?= htmlspecialchars((string)($row['last_message_received_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span>前回送信日時: <?= htmlspecialchars((string)($row['send_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>

          <div class="aori-actions">
            <?php if (!empty($row['chat_url'])): ?>
              <a class="btn aori-link" href="<?= htmlspecialchars((string)$row['chat_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">チャットを開く</a>
            <?php elseif (!empty($row['friend_id'])): ?>
              <a class="btn aori-link" href="https://step.lme.jp/basic/chat-v3?friend_id=<?= urlencode((string)$row['friend_id']); ?>" target="_blank" rel="noopener noreferrer">チャットを開く</a>
            <?php endif; ?>

            <form method="post" class="aori-form">
              <input type="hidden" name="content_id" value="<?= (int)$row['id']; ?>">
              <button class="btn" type="submit">チャットボタン（send_at更新）</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php'; ?>
