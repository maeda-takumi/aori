<?php
$pageTitle = 'Bull-Fight | ダッシュボード';
require __DIR__ . '/header.php';
?>

<section class="hero-card glass">
  <h2>顧客管理ダッシュボード</h2>
  <p>
    CSV取込・送信対象抽出・文面確認・L Messageチャット遷移を
    効率化するためのベース画面です。
  </p>
</section>

<section class="hero-card glass" style="margin-top: 18px;">
  <h2>このツールでできること</h2>
  <p>ヘッダーのアイコンから、次の3つの画面へすぐに移動できます。</p>

  <ul>
    <li style="margin-top: 10px;">
      <img src="img/home.png" alt="ホームアイコン" width="20" height="20" style="vertical-align: middle; margin-right: 6px;">
      <strong>ホーム</strong>：全体の流れ・使い方を確認します。
    </li>
    <li style="margin-top: 10px;">
      <img src="img/import.png" alt="インポートアイコン" width="20" height="20" style="vertical-align: middle; margin-right: 6px;">
      <strong>インポート</strong>：CSVを取り込み、対象データを準備します。
    </li>
    <li style="margin-top: 10px;">
      <img src="img/aori.png" alt="煽りアイコン" width="20" height="20" style="vertical-align: middle; margin-right: 6px;">
      <strong>煽り</strong>：送信文面の確認・編集、チャット遷移を行います。
    </li>
    <li style="margin-top: 10px;">
      <img src="img/log.png" alt="ログアイコン" width="20" height="20" style="vertical-align: middle; margin-right: 6px;">
      <strong>ログ</strong>：完了した記録を確認できます。
    </li>
  </ul>

  <h3 style="margin-top: 20px;">おすすめの利用手順</h3>
  <ol>
    <li>まず <strong>ホーム</strong> で操作の流れを確認する</li>
    <li><strong>インポート</strong> でCSVファイルを読み込む</li>
    <li><strong>煽り</strong> 画面で対象者・文面を確認して対応する</li>
    <li><strong>ログ</strong> 画面で対象者・文面を確認して対応する</li>
    <li>必要に応じて各画面を往復し、内容を再調整する</li>
  </ol>
</section>

<?php require __DIR__ . '/footer.php'; ?>