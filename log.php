<?php
$pageTitle = 'Bull-Fight | ログ';
require __DIR__ . '/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$messages = [];
$errors = [];
$logs = [];
$perPage = 25;
$currentPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$currentPage = ($currentPage !== false && $currentPage !== null && $currentPage > 0) ? $currentPage : 1;
$totalLogs = 0;
$totalPages = 1;
$showReverted = isset($_GET['show_reverted']) && $_GET['show_reverted'] === '1';

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $sendAtColumnStmt = $pdo->query("SHOW COLUMNS FROM contacts LIKE 'send_at'");
    if ($sendAtColumnStmt->fetch() === false) {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN send_at DATETIME NULL AFTER last_message_received_at');
        $messages[] = 'contactsテーブルにsend_atカラムを追加しました。';
    }

    $currentLogIdColumnStmt = $pdo->query("SHOW COLUMNS FROM contacts LIKE 'current_send_log_id'");
    if ($currentLogIdColumnStmt->fetch() === false) {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN current_send_log_id BIGINT UNSIGNED NULL AFTER send_at');
        $messages[] = 'contactsテーブルにcurrent_send_log_idカラムを追加しました。';
    }

    $sendLogTableExists = $pdo->query("SHOW TABLES LIKE 'aori_send_logs' ")->fetch() !== false;
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'undo_send_log')) {
        $logId = filter_input(INPUT_POST, 'log_id', FILTER_VALIDATE_INT);

        if ($logId === false || $logId === null || $logId <= 0) {
            $errors[] = '解除対象のログIDが不正です。';
        } else {
            try {
                $pdo->beginTransaction();

                $targetLogStmt = $pdo->prepare('SELECT id, contact_id, reverted_at FROM aori_send_logs WHERE id = :id FOR UPDATE');
                $targetLogStmt->execute(['id' => $logId]);
                $targetLog = $targetLogStmt->fetch();

                if ($targetLog === false) {
                    throw new RuntimeException('対象ログが見つかりません。');
                }
                if (!empty($targetLog['reverted_at'])) {
                    throw new RuntimeException('このログはすでに解除済みです。');
                }

                $latestActiveStmt = $pdo->prepare(
                    'SELECT id, sent_at
                     FROM aori_send_logs
                     WHERE contact_id = :contact_id
                       AND reverted_at IS NULL
                     ORDER BY sent_at DESC, id DESC
                     LIMIT 1
                     FOR UPDATE'
                );
                $latestActiveStmt->execute(['contact_id' => (int)$targetLog['contact_id']]);
                $latestActiveLog = $latestActiveStmt->fetch();

                if ($latestActiveLog === false || (int)$latestActiveLog['id'] !== (int)$targetLog['id']) {
                    throw new RuntimeException('最新の有効ログのみ解除できます。');
                }

                $revertStmt = $pdo->prepare('UPDATE aori_send_logs SET reverted_at = NOW(), updated_at = NOW() WHERE id = :id');
                $revertStmt->execute(['id' => (int)$targetLog['id']]);

                $prevActiveStmt = $pdo->prepare(
                    'SELECT id, sent_at
                     FROM aori_send_logs
                     WHERE contact_id = :contact_id
                       AND reverted_at IS NULL
                     ORDER BY sent_at DESC, id DESC
                     LIMIT 1'
                );
                $prevActiveStmt->execute(['contact_id' => (int)$targetLog['contact_id']]);
                $prevActiveLog = $prevActiveStmt->fetch();

                if ($prevActiveLog !== false) {
                    $syncContactStmt = $pdo->prepare('UPDATE contacts SET current_send_log_id = :current_send_log_id, send_at = :send_at WHERE id = :contact_id');
                    $syncContactStmt->execute([
                        'current_send_log_id' => (int)$prevActiveLog['id'],
                        'send_at' => (string)$prevActiveLog['sent_at'],
                        'contact_id' => (int)$targetLog['contact_id'],
                    ]);
                } else {
                    $syncContactStmt = $pdo->prepare('UPDATE contacts SET current_send_log_id = NULL, send_at = NULL WHERE id = :contact_id');
                    $syncContactStmt->execute([
                        'contact_id' => (int)$targetLog['contact_id'],
                    ]);
                }

                $pdo->commit();
                $messages[] = 'ログを解除しました。';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }

    $latestActiveMap = [];
    $latestRowsStmt = $pdo->query(
        'SELECT l.contact_id, MAX(l.id) AS latest_active_id
         FROM aori_send_logs l
         INNER JOIN (
             SELECT contact_id, MAX(sent_at) AS max_sent_at
             FROM aori_send_logs
             WHERE reverted_at IS NULL
             GROUP BY contact_id
         ) latest ON latest.contact_id = l.contact_id AND latest.max_sent_at = l.sent_at
         WHERE l.reverted_at IS NULL
         GROUP BY l.contact_id'
    );
    foreach ($latestRowsStmt->fetchAll() as $latestRow) {
        $latestActiveMap[(int)$latestRow['contact_id']] = (int)$latestRow['latest_active_id'];
    }

    $countSql = 'SELECT COUNT(*)
            FROM aori_send_logs logs
            LEFT JOIN contacts ON contacts.id = logs.contact_id';

    if (!$showReverted) {
        $countSql .= ' WHERE logs.reverted_at IS NULL';
    }

    $totalLogs = (int)$pdo->query($countSql)->fetchColumn();
    $totalPages = max(1, (int)ceil($totalLogs / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;

    $sql = 'SELECT
                logs.id,
                logs.contact_id,
                logs.sent_at,
                logs.reverted_at,
                logs.created_at,
                contacts.line_display_name,
                contacts.system_display_name,
                contacts.send_at AS current_send_at,
                contacts.current_send_log_id
            FROM aori_send_logs logs
            LEFT JOIN contacts ON contacts.id = logs.contact_id';

    if (!$showReverted) {
        $sql .= ' WHERE logs.reverted_at IS NULL';
    }

    $sql .= ' ORDER BY logs.sent_at DESC, logs.id DESC LIMIT :limit OFFSET :offset';

    $logsStmt = $pdo->prepare($sql);
    $logsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $logsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $logsStmt->execute();
    $logs = $logsStmt->fetchAll();

    foreach ($logs as $index => $logRow) {
        $contactId = (int)$logRow['contact_id'];
        $isReverted = !empty($logRow['reverted_at']);
        $logs[$index]['can_undo'] = !$isReverted
            && isset($latestActiveMap[$contactId])
            && $latestActiveMap[$contactId] === (int)$logRow['id'];
    }
} catch (Throwable $e) {
    $errors[] = 'ログの取得または更新に失敗しました: ' . $e->getMessage();
}

require __DIR__ . '/header.php';
?>

<section class="hero-card glass aori-card">
  <h2>煽り送信ログ</h2>
  <p>基本は有効ログのみ表示しています。解除済みログはフィルタで表示できます。</p>

  <?php foreach ($messages as $message): ?>
    <div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endforeach; ?>

  <?php foreach ($errors as $error): ?>
    <div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endforeach; ?>

  <form method="get" class="aori-filter-form glass log-filter-form">
    <label class="aori-check">
      <input type="hidden" name="show_reverted" value="0">
      <input type="checkbox" name="show_reverted" value="1" <?= $showReverted ? 'checked' : ''; ?>>
      解除済みログも表示する
    </label>
    <button class="btn" type="submit">更新</button>
  </form>

  <div class="log-table-wrap glass">
    <?php if (empty($logs)): ?>
      <p class="aori-empty">ログがありません。</p>
    <?php else: ?>
      <table class="log-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>対象</th>
            <th>送信日時</th>
            <th>状態</th>
            <th>現在の前回煽り送信日時</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <?php $isReverted = !empty($log['reverted_at']); ?>
            <tr class="<?= $isReverted ? 'log-row-reverted' : ''; ?>">
              <td><?= (int)$log['id']; ?></td>
              <td>
                <?= htmlspecialchars((string)($log['line_display_name'] ?: $log['system_display_name'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?>
                <div class="log-subtext">contact_id: <?= (int)$log['contact_id']; ?></div>
              </td>
              <td><?= htmlspecialchars((string)$log['sent_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <span class="log-status-badge <?= $isReverted ? 'reverted' : 'active'; ?>">
                  <?= $isReverted ? '解除済み' : '有効'; ?>
                </span>
              </td>
              <td><?= htmlspecialchars((string)($log['current_send_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <?php if ((bool)$log['can_undo']): ?>
                  <form method="post" onsubmit="return confirm('このログを解除します。よろしいですか？');">
                    <input type="hidden" name="action" value="undo_send_log">
                    <input type="hidden" name="log_id" value="<?= (int)$log['id']; ?>">
                    <button class="btn" type="submit">解除</button>
                  </form>
                <?php else: ?>
                  <span class="log-subtext"><?= $isReverted ? '解除済み' : '最新のみ解除可'; ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php if ($totalPages > 1): ?>
    <nav class="log-pagination" aria-label="ログ一覧のページャー">
      <?php if ($currentPage > 1): ?>
        <a class="btn" href="?show_reverted=<?= $showReverted ? '1' : '0'; ?>&page=<?= $currentPage - 1; ?>">前へ</a>
      <?php endif; ?>
      <span><?= $currentPage; ?> / <?= $totalPages; ?> ページ（全<?= $totalLogs; ?>件）</span>
      <?php if ($currentPage < $totalPages): ?>
        <a class="btn" href="?show_reverted=<?= $showReverted ? '1' : '0'; ?>&page=<?= $currentPage + 1; ?>">次へ</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php';
