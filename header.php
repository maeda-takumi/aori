<?php
// header.php
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : '煽り管理'; ?></title>

  <!-- 毎回読み込み（キャッシュバスター） -->
  <link rel="stylesheet" href="style/style.css?v=<?= time(); ?>">
</head>
<body>
  <header class="site-header glass">
    <div class="container header-inner">
      <h1 class="site-title">煽り管理</h1>
      <div class="nav-links">
        <a href="index.php" class="import-icon-btn" aria-label="ホーム画面へ移動">
            <img src="img/home.png" alt="ホーム">
        </a>
        <a href="aori.php" class="import-icon-btn import-icon-btn2" aria-label="煽り画面へ移動">
            <img src="img/aori.png" alt="煽り">
        </a>
        <a href="import.php" class="import-icon-btn" aria-label="インポート画面へ移動">
            <img src="img/import.png" alt="インポート">
        </a>
      </div>
    </div>
  </header>

  <main class="container main-content">