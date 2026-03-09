<?php
$pageTitle = 'LMessage 管理ツール | CSVインポート';
require __DIR__ . '/header.php';
require __DIR__ . '/config.php';

$messages = [];
$errors = [];
$previewRows = [];
$importedCount = 0;

function canonicalize_key(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[\s_\-　]+/u', '', $value) ?? '';
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
}

function parse_bool($value): int
{
    if ($value === null) {
        return 0;
    }

    $normalized = mb_strtolower(trim((string)$value), 'UTF-8');
    if ($normalized === '') {
        return 0;
    }

    $truthy = ['1', 'true', 'yes', 'y', 'on', '有', 'あり', '○', '◯', '✓', '✔'];
    return in_array($normalized, $truthy, true) ? 1 : 0;
}

function parse_datetime(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('.', '/', $value);

    $formats = [
        'Y/m/d H:i:s',
        'Y/m/d H:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y/m/d',
        'Y-m-d',
        'm/d/Y H:i:s',
        'm/d/Y H:i',
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('Y-m-d H:i:s', $timestamp);
    }

    return null;
}

function resolve_column_indexes(array $headerRow, array $aliases): array
{
    $headerMap = [];
    foreach ($headerRow as $idx => $name) {
        $canonical = canonicalize_key((string)$name);
        if ($canonical === '') {
            continue;
        }
        $headerMap[$canonical] = $idx;
    }

    $resolvedIndex = [];
    foreach ($aliases as $column => $keys) {
        $resolvedIndex[$column] = null;
        foreach ($keys as $key) {
            $canonical = canonicalize_key($key);
            if (array_key_exists($canonical, $headerMap)) {
                $resolvedIndex[$column] = $headerMap[$canonical];
                break;
            }

            foreach ($headerMap as $headerCanonical => $headerIdx) {
                if (
                    str_contains($headerCanonical, $canonical)
                    || str_contains($canonical, $headerCanonical)
                ) {
                    $resolvedIndex[$column] = $headerIdx;
                    break 2;
                }
            }
        }
    }

    return $resolvedIndex;
}

function merge_header_rows(array $upper, array $lower): array
{
    $length = max(count($upper), count($lower));
    $merged = [];
    for ($i = 0; $i < $length; $i++) {
        $upperValue = trim((string)($upper[$i] ?? ''));
        $lowerValue = trim((string)($lower[$i] ?? ''));
        $merged[$i] = $lowerValue !== '' ? $lowerValue : $upperValue;
    }

    return $merged;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'CSVファイルのアップロードに失敗しました。';
    } else {
        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $originalName = $_FILES['csv_file']['name'] ?? 'unknown.csv';

        if (!is_uploaded_file($tmpPath)) {
            $errors[] = 'アップロードされたファイルを確認できませんでした。';
        } else {
            $handle = fopen($tmpPath, 'rb');
            if ($handle === false) {
                $errors[] = 'CSVファイルを開けませんでした。';
            } else {
                $firstLine = fgets($handle);
                rewind($handle);

                if ($firstLine !== false && str_starts_with($firstLine, "\xEF\xBB\xBF")) {
                    $firstLine = substr($firstLine, 3);
                }

                $headerRows = [];
                for ($i = 0; $i < 5; $i++) {
                    $row = fgetcsv($handle);
                    if ($row === false) {
                        break;
                    }
                    if ($i === 0 && isset($row[0])) {
                        $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
                    }
                    $headerRows[] = $row;
                }

                if (empty($headerRows)) {
                    $errors[] = 'CSVヘッダーを読み込めませんでした。';
                } else {

                    $aliases = [
                        'lmessage_csv_id' => ['id', 'csvid', 'lmessageid'],
                        'line_user_id' => ['lineuserid', 'lineユーザーid', 'lineユーザー', 'lineid', 'lineのid', 'ユーザーid', 'ユーザーID'],
                        'line_display_name' => ['line表示名', '表示名', 'line名'],
                        'support_mark' => ['対応マーク', 'サポートマーク'],
                        'last_message_received_at' => ['最終メッセージ受信日時', '最終受信日時', 'lastmessagereceivedat'],
                        'tag_shimazaki' => ['タグ島崎', 'tag島崎'],
                        'tag_hirabayashi' => ['タグ平林', 'tag平林'],
                        'tag_manpuku' => ['タグ万福', 'tag万福'],
                        'system_display_name' => ['システム表示名', 'system表示名'],
                        'lmessage_personal_memo' => ['lmessage側の個別メモ', 'lmessage個別メモ', '個別メモ'],
                    ];

                    $bestResolvedIndex = null;
                    $bestHeaderRowPos = null;
                    $bestScore = -1;

                    foreach ($headerRows as $idx => $candidateHeader) {
                        $resolved = resolve_column_indexes($candidateHeader, $aliases);
                        $score = count(array_filter($resolved, static fn($v) => $v !== null));
                        if ($resolved['line_user_id'] !== null && $score > $bestScore) {
                            $bestResolvedIndex = $resolved;
                            $bestHeaderRowPos = $idx;
                            $bestScore = $score;
                        }

                        if ($idx > 0) {
                            $mergedHeader = merge_header_rows($headerRows[$idx - 1], $candidateHeader);
                            $resolvedMerged = resolve_column_indexes($mergedHeader, $aliases);
                            $mergedScore = count(array_filter($resolvedMerged, static fn($v) => $v !== null));
                            if ($resolvedMerged['line_user_id'] !== null && $mergedScore > $bestScore) {
                                $bestResolvedIndex = $resolvedMerged;
                                $bestHeaderRowPos = $idx;
                                $bestScore = $mergedScore;
                            }
                        }
                    }

                    $resolvedIndex = $bestResolvedIndex ?? [];

                    if (($resolvedIndex['line_user_id'] ?? null) === null) {
                        $errors[] = '必須列「line_user_id（LINEユーザーID）」が見つかりません。';
                    } else {
                        $bufferedDataRows = array_slice($headerRows, (int)$bestHeaderRowPos + 1);
                        try {
                            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
                            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            ]);

                            $sql = "INSERT INTO contacts (
                                lmessage_csv_id,
                                line_user_id,
                                line_display_name,
                                support_mark,
                                last_message_received_at,
                                tag_shimazaki,
                                tag_hirabayashi,
                                tag_manpuku,
                                system_display_name,
                                lmessage_personal_memo,
                                imported_at,
                                updated_at
                            ) VALUES (
                                :lmessage_csv_id,
                                :line_user_id,
                                :line_display_name,
                                :support_mark,
                                :last_message_received_at,
                                :tag_shimazaki,
                                :tag_hirabayashi,
                                :tag_manpuku,
                                :system_display_name,
                                :lmessage_personal_memo,
                                NOW(),
                                NOW()
                            )
                            ON DUPLICATE KEY UPDATE
                                lmessage_csv_id = VALUES(lmessage_csv_id),
                                line_display_name = VALUES(line_display_name),
                                support_mark = VALUES(support_mark),
                                last_message_received_at = VALUES(last_message_received_at),
                                tag_shimazaki = VALUES(tag_shimazaki),
                                tag_hirabayashi = VALUES(tag_hirabayashi),
                                tag_manpuku = VALUES(tag_manpuku),
                                system_display_name = VALUES(system_display_name),
                                lmessage_personal_memo = VALUES(lmessage_personal_memo),
                                imported_at = NOW(),
                                updated_at = NOW()";

                            $stmt = $pdo->prepare($sql);

                            foreach ($bufferedDataRows as $row) {
                                $lineUserId = trim((string)($row[$resolvedIndex['line_user_id']] ?? ''));

                                if ($lineUserId === '') {
                                    continue;
                                }

                                $payload = [
                                    'lmessage_csv_id' => $resolvedIndex['lmessage_csv_id'] !== null ? trim((string)($row[$resolvedIndex['lmessage_csv_id']] ?? '')) : null,
                                    'line_user_id' => $lineUserId,
                                    'line_display_name' => $resolvedIndex['line_display_name'] !== null ? trim((string)($row[$resolvedIndex['line_display_name']] ?? '')) : null,
                                    'support_mark' => $resolvedIndex['support_mark'] !== null ? trim((string)($row[$resolvedIndex['support_mark']] ?? '')) : null,
                                    'last_message_received_at' => $resolvedIndex['last_message_received_at'] !== null ? parse_datetime($row[$resolvedIndex['last_message_received_at']] ?? null) : null,
                                    'tag_shimazaki' => $resolvedIndex['tag_shimazaki'] !== null ? parse_bool($row[$resolvedIndex['tag_shimazaki']] ?? null) : 0,
                                    'tag_hirabayashi' => $resolvedIndex['tag_hirabayashi'] !== null ? parse_bool($row[$resolvedIndex['tag_hirabayashi']] ?? null) : 0,
                                    'tag_manpuku' => $resolvedIndex['tag_manpuku'] !== null ? parse_bool($row[$resolvedIndex['tag_manpuku']] ?? null) : 0,
                                    'system_display_name' => $resolvedIndex['system_display_name'] !== null ? trim((string)($row[$resolvedIndex['system_display_name']] ?? '')) : null,
                                    'lmessage_personal_memo' => $resolvedIndex['lmessage_personal_memo'] !== null ? trim((string)($row[$resolvedIndex['lmessage_personal_memo']] ?? '')) : null,
                                ];

                                foreach (['lmessage_csv_id', 'line_display_name', 'support_mark', 'system_display_name', 'lmessage_personal_memo'] as $nullableColumn) {
                                    if ($payload[$nullableColumn] === '') {
                                        $payload[$nullableColumn] = null;
                                    }
                                }

                                $stmt->execute($payload);
                                $importedCount++;

                                if (count($previewRows) < 10) {
                                    $previewRows[] = [
                                        'line_user_id' => $payload['line_user_id'],
                                        'line_display_name' => $payload['line_display_name'] ?? '',
                                        'system_display_name' => $payload['system_display_name'] ?? '',
                                        'support_mark' => $payload['support_mark'] ?? '',
                                    ];
                                }
                            }
                            while (($row = fgetcsv($handle)) !== false) {
                                $lineUserId = $resolvedIndex['line_user_id'] !== null ? trim((string)($row[$resolvedIndex['line_user_id']] ?? '')) : '';

                                if ($lineUserId === '') {
                                    continue;
                                }

                                $payload = [
                                    'lmessage_csv_id' => $resolvedIndex['lmessage_csv_id'] !== null ? trim((string)($row[$resolvedIndex['lmessage_csv_id']] ?? '')) : null,
                                    'line_user_id' => $lineUserId,
                                    'line_display_name' => $resolvedIndex['line_display_name'] !== null ? trim((string)($row[$resolvedIndex['line_display_name']] ?? '')) : null,
                                    'support_mark' => $resolvedIndex['support_mark'] !== null ? trim((string)($row[$resolvedIndex['support_mark']] ?? '')) : null,
                                    'last_message_received_at' => $resolvedIndex['last_message_received_at'] !== null ? parse_datetime($row[$resolvedIndex['last_message_received_at']] ?? null) : null,
                                    'tag_shimazaki' => $resolvedIndex['tag_shimazaki'] !== null ? parse_bool($row[$resolvedIndex['tag_shimazaki']] ?? null) : 0,
                                    'tag_hirabayashi' => $resolvedIndex['tag_hirabayashi'] !== null ? parse_bool($row[$resolvedIndex['tag_hirabayashi']] ?? null) : 0,
                                    'tag_manpuku' => $resolvedIndex['tag_manpuku'] !== null ? parse_bool($row[$resolvedIndex['tag_manpuku']] ?? null) : 0,
                                    'system_display_name' => $resolvedIndex['system_display_name'] !== null ? trim((string)($row[$resolvedIndex['system_display_name']] ?? '')) : null,
                                    'lmessage_personal_memo' => $resolvedIndex['lmessage_personal_memo'] !== null ? trim((string)($row[$resolvedIndex['lmessage_personal_memo']] ?? '')) : null,
                                ];

                                foreach (['lmessage_csv_id', 'line_display_name', 'support_mark', 'system_display_name', 'lmessage_personal_memo'] as $nullableColumn) {
                                    if ($payload[$nullableColumn] === '') {
                                        $payload[$nullableColumn] = null;
                                    }
                                }

                                $stmt->execute($payload);
                                $importedCount++;

                                if (count($previewRows) < 10) {
                                    $previewRows[] = [
                                        'line_user_id' => $payload['line_user_id'],
                                        'line_display_name' => $payload['line_display_name'] ?? '',
                                        'system_display_name' => $payload['system_display_name'] ?? '',
                                        'support_mark' => $payload['support_mark'] ?? '',
                                    ];
                                }
                            }

                            $messages[] = sprintf('%s の取込が完了しました。%d 件を保存しました。', htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8'), $importedCount);
                        } catch (Throwable $e) {
                            $errors[] = 'DB保存中にエラーが発生しました: ' . $e->getMessage();
                        }
                    }
                }

                fclose($handle);
            }
        }
    }
}
?>

<section class="hero-card glass import-card">
  <h2>CSVインポート</h2>
  <p>L Messageの顧客CSVをアップロードして、contactsテーブルへ保存します（line_user_idでupsert）。</p>

  <?php foreach ($messages as $message): ?>
    <div class="notice success"><?= $message; ?></div>
  <?php endforeach; ?>

  <?php foreach ($errors as $error): ?>
    <div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endforeach; ?>

  <form action="import.php" method="post" enctype="multipart/form-data" id="import-form" class="import-form">
    <label for="csv-file" class="drop-zone" id="drop-zone">
      <span class="drop-main">ここにCSVをドラッグ＆ドロップ</span>
      <span class="drop-sub">またはクリックしてファイル選択</span>
      <input type="file" name="csv_file" id="csv-file" accept=".csv,text/csv" required>
    </label>
    <div class="import-actions">
      <button class="btn" type="submit">取り込む</button>
      <span id="selected-file">未選択</span>
    </div>
  </form>
</section>

<?php if (!empty($previewRows)): ?>
  <section class="glass preview-card">
    <h3>取込プレビュー（先頭10件）</h3>
    <table class="preview-table">
      <thead>
        <tr>
          <th>LINEユーザーID</th>
          <th>LINE表示名</th>
          <th>システム表示名</th>
          <th>対応マーク</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($previewRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['line_user_id'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?= htmlspecialchars($row['line_display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?= htmlspecialchars($row['system_display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?= htmlspecialchars($row['support_mark'], ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
