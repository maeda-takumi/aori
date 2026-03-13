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

  <main class="container main-content">