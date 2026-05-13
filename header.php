<?php
// header.php
$latestImportCompletedAt = null;
$importStatusPath = __DIR__ . '/storage/import_status.json';
if (is_file($importStatusPath) && is_readable($importStatusPath)) {
    $rawImportStatus = file_get_contents($importStatusPath);
    if ($rawImportStatus !== false) {
        $decodedImportStatus = json_decode($rawImportStatus, true);
        if (is_array($decodedImportStatus) && isset($decodedImportStatus['completed_at_display'])) {
            $latestImportCompletedAt = (string)$decodedImportStatus['completed_at_display'];
        }
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : '煽り管理'; ?></title>

  <!-- ブラウザタブ用アイコン -->
  <link rel="icon" type="image/png" href="img/aori.png">

  <!-- iPhoneでホーム画面に追加したときのアイコン -->
  <link rel="apple-touch-icon" href="img/aori.png">

  <!-- 毎回読み込み（キャッシュバスター） -->
  <link rel="stylesheet" href="style/style.css?v=<?= time(); ?>">
</head>
<body>
  <header class="site-header glass">
    <div class="container header-inner">
      <div class="site-brand">
        <h1 class="site-title">煽り管理</h1>
        <?php if ($latestImportCompletedAt !== null): ?>
          <p class="import-completed-at">最終インポート完了: <?= htmlspecialchars($latestImportCompletedAt, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      </div>
      <button
        type="button"
        class="nav-toggle"
        aria-label="ナビゲーションメニューを開閉"
        aria-controls="header-nav-links"
        aria-expanded="false"
      >
        <span></span>
        <span></span>
        <span></span>
      </button>
      <div class="nav-links" id="header-nav-links">
        <!-- <button type="button" class="import-icon-btn ai-prompt-header-btn" id="ai-prompt-open" aria-label="AIプロンプトを変更">AI</button> -->
        <a href="index.php" class="import-icon-btn" aria-label="ホーム画面へ移動">
            <img src="img/home.png" alt="ホーム">
        </a>
        <a href="import.php" class="import-icon-btn" aria-label="インポート画面へ移動">
            <img src="img/import.png" alt="インポート">
        </a>
        <a href="aori.php" class="import-icon-btn import-icon-btn2" aria-label="煽り画面へ移動">
            <img src="img/aori.png" alt="煽り">
        </a>
        <a href="log.php" class="import-icon-btn import-icon-btn2" aria-label="ログ画面へ移動">
            <img src="img/log.png" alt="ログ">
        </a>
      </div>
    </div>
  </header>

  <div id="ai-prompt-modal" class="chat-modal" hidden>
    <div class="chat-modal__backdrop" data-ai-prompt-close></div>
    <div class="chat-modal__dialog glass ai-prompt-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ai-prompt-modal-title">
      <h3 id="ai-prompt-modal-title">AIプロンプト設定</h3>
      <p class="ai-prompt-modal__lead">AI生成ボタンで下書きを作るときの指示文を変更できます。保存内容はこのブラウザに保存されます。</p>
      <label class="ai-prompt-field">
        プロンプト
        <textarea id="ai-prompt-text" rows="12"></textarea>
      </label>
      <p id="ai-prompt-status" class="chat-modal__status" hidden></p>
      <div class="chat-modal__actions">
        <button type="button" class="btn chat-modal__cancel" id="ai-prompt-reset">初期値に戻す</button>
        <button type="button" class="btn chat-modal__cancel" data-ai-prompt-cancel>キャンセル</button>
        <button type="button" class="btn" id="ai-prompt-save">保存する</button>
      </div>
    </div>
  </div>
  <main class="container main-content">