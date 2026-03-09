# LMessage 管理ツール 設計メモ

作成日: 2026-03-09

## 1. 目的

- L Message を顧客管理とチャット履歴の正本として使い続ける
- 外部の管理ツールで、CSV取込・送信対象抽出・個別文面確認・対象チャットへの遷移を効率化する
- 実際の送信は L Message 上で行い、履歴も L Message に残す

---

## 2. 現状の前提と方針

### 2-1. 前提

- L Message の顧客情報 CSV を管理ツールへ取り込む
- ユーザーごとに送信文面が変わるため、一斉送信とは相性が悪い
- Messaging API で直接送信すると、L Message 上に履歴が残らない
- スクレイピングでの送信自動化は、UI変更・ログイン維持・スマホ非対応・ローカルPC依存などの理由から主軸にはしない

### 2-2. 採用方針

- 管理ツール本体は Web / PHP で構築する
- 管理ツールは以下を担当する
  - L Message 顧客CSVの取込
  - 送信対象者の抽出
  - 個別文面候補の表示
  - friend_id を使ったチャットURL生成
  - L Message チャット画面へのリダイレクト
  - 本文コピー補助
- 実際の送信は L Message 上で手動で行う
- friend_id は別途取得して DB に保存する
- friend_id 取得用のスクレイピングは、日常運用ではなく補修用・保守用の位置づけにする

---

## 3. チャット遷移仕様

L Message のチャットURLは以下形式で遷移可能。

```text
https://step.lme.jp/basic/chat-v3?friend_id=XXXX
```

### 方針

- friend_id が保存済みのユーザーは、管理ツール上のボタンから上記URLへ遷移する
- 遷移はサーバー側でブラウザ操作するのではなく、Web画面上のリンクやリダイレクトでユーザーのブラウザを開く

---

## 4. friend_id の考え方

### 4-1. 現状判明していること

- friend_id は L Message 独自の内部管理IDと考えられる
- CSV左1列目の値とは一致しない
- CSVの既存値から friend_id を自動生成することは難しい

### 4-2. 運用方針

- friend_id は別途取得し、contacts テーブルへ保存する
- 新規友だちで friend_id 未取得のユーザーは、未紐付けとして管理する
- 必要に応じて、friend_id 回収用の補助スクレイピングツールで取得し、PHP API経由で保存する

---

## 5. 日常運用フロー

1. L Message から顧客CSVをダウンロード
2. 管理ツールへCSVアップロード
3. 管理ツールで送信対象者を抽出
4. 対象者ごとの文面候補を確認
5. friend_id があるユーザーは「チャットを開く」で L Message チャット画面へ遷移
6. 「本文コピー」で文面をコピー
7. L Message 上で手動送信

---

## 6. 補修運用フロー

1. friend_id 未登録ユーザーを確認
2. 必要なタイミングだけ friend_id 回収用ツールを実行
3. friend_id を取得
4. PHP API に送信
5. DB 更新
6. 次回以降は管理ツールから直接チャット遷移可能

---

## 7. DB設計方針

### 7-1. 基本方針

- 基本は CSV と同じ構造を `contacts` テーブルで持つ
- ただし管理ツール運用上必要な `friend_id` と `chat_url` も `contacts` に持たせる
- 管理ツール独自のステータスや内部メモは `contact_management` テーブルで管理する
- CSV取込時は、`line_user_id` をキーにして上書き更新する
- 新しい `line_user_id` の場合は insert する

---

## 8. テーブル設計

## 8-1. contacts

L Message 顧客CSV由来の本体テーブル。

### 役割

- CSVの最新状態を保持する
- `line_user_id` を外部管理上の主キーとして扱う
- `friend_id` と `chat_url` を保持する
- CSV再取込時は upsert する

### カラム案

| カラム名 | 型 | 内容 |
|---|---|---|
| id | BIGINT AUTO_INCREMENT PK | 内部ID |
| lmessage_csv_id | VARCHAR(64) | CSV上のID |
| line_user_id | VARCHAR(64) UNIQUE | LINEユーザーID |
| line_display_name | VARCHAR(255) | LINE表示名 |
| support_mark | VARCHAR(255) | 対応マーク |
| last_message_received_at | DATETIME | 最終メッセージ受信日時 |
| tag_shimazaki | TINYINT(1) | タグ_島崎 |
| tag_hirabayashi | TINYINT(1) | タグ_平林 |
| tag_manpuku | TINYINT(1) | タグ_万福 |
| system_display_name | VARCHAR(255) | システム表示名 |
| lmessage_personal_memo | TEXT | L Message側の個別メモ |
| friend_id | BIGINT NULL | L Message チャット遷移用 friend_id |
| chat_url | VARCHAR(255) NULL | `https://step.lme.jp/basic/chat-v3?friend_id=...` |
| imported_at | DATETIME | 最終取込日時 |
| updated_at | DATETIME | 更新日時 |

### MySQL DDL案

```sql
CREATE TABLE contacts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lmessage_csv_id VARCHAR(64) DEFAULT NULL,
  line_user_id VARCHAR(64) NOT NULL,
  line_display_name VARCHAR(255) DEFAULT NULL,
  support_mark VARCHAR(255) DEFAULT NULL,
  last_message_received_at DATETIME DEFAULT NULL,
  tag_shimazaki TINYINT(1) NOT NULL DEFAULT 0,
  tag_hirabayashi TINYINT(1) NOT NULL DEFAULT 0,
  tag_manpuku TINYINT(1) NOT NULL DEFAULT 0,
  system_display_name VARCHAR(255) DEFAULT NULL,
  lmessage_personal_memo TEXT DEFAULT NULL,
  friend_id BIGINT DEFAULT NULL,
  chat_url VARCHAR(255) DEFAULT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contacts_line_user_id (line_user_id),
  KEY idx_contacts_friend_id (friend_id),
  KEY idx_contacts_last_message_received_at (last_message_received_at)
);
```

### CSV取込時の更新ルール

- `line_user_id` が既存なら update
- `line_user_id` が未登録なら insert
- `friend_id` / `chat_url` が既にある場合、CSV由来ではないため基本は上書きしない
  - ただし friend_id 回収API経由では更新可能

---

## 8-2. contact_management

管理ツール独自の運用情報を持つテーブル。

### 役割

- 管理ツール上での対応状態を持つ
- 社内用メモを持つ
- 顧客ごとの優先度・担当・送信下書きなどを持つ

### カラム案

| カラム名 | 型 | 内容 |
|---|---|---|
| id | BIGINT AUTO_INCREMENT PK | 内部ID |
| line_user_id | VARCHAR(64) UNIQUE | LINEユーザーID |
| management_status | VARCHAR(100) | 管理ステータス |
| priority_rank | INT | 優先度 |
| assignee | VARCHAR(100) | 担当者 |
| internal_memo | TEXT | 管理ツール側内部メモ |
| last_sent_message_draft | TEXT | 最後に作成した文面案 |
| last_contacted_at | DATETIME | 最終対応日時 |
| needs_friend_id_recovery | TINYINT(1) | friend_id 回収要否 |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |

### MySQL DDL案

```sql
CREATE TABLE contact_management (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  line_user_id VARCHAR(64) NOT NULL,
  management_status VARCHAR(100) DEFAULT NULL,
  priority_rank INT DEFAULT NULL,
  assignee VARCHAR(100) DEFAULT NULL,
  internal_memo TEXT DEFAULT NULL,
  last_sent_message_draft TEXT DEFAULT NULL,
  last_contacted_at DATETIME DEFAULT NULL,
  needs_friend_id_recovery TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact_management_line_user_id (line_user_id)
);
```

---

## 9. PHP API 方針

### 用途

- 補助ツールから `line_user_id` と `friend_id` を受け取る
- `contacts` テーブルの `friend_id` と `chat_url` を更新する

### 想定仕様

- 認証: APIキー
- 入力値:
  - `line_user_id`
  - `friend_id`
- 処理:
  - `friend_id` が数字か検証
  - `line_user_id` が有効か検証
  - `chat_url` を生成
  - `contacts` を update

---

## 10. 管理ツール画面で必要な主な機能

### 10-1. CSV取込

- CSVアップロード
- `line_user_id` をキーに upsert
- 取込件数表示

### 10-2. 対象抽出

- タグ
- 最終受信日時
- 対応マーク
- 管理ステータス
- friend_id 有無

### 10-3. 一覧表示

- LINE表示名
- システム表示名
- LINEユーザーID
- friend_id 有無
- 管理ステータス
- 文面候補
- 操作ボタン
  - チャットを開く
  - 本文コピー
  - メモ編集

### 10-4. 未紐付け管理

- friend_id 未登録ユーザー一覧
- 回収対象フラグ管理

---

## 11. 現時点の最終整理

### 決定事項

- `friend_id` は `contacts` テーブルに持たせる
- `contacts` は CSVベースの本体テーブルとする
- `contact_management` は管理ツール独自の運用情報を持つ
- CSV取込は `line_user_id` ベースで上書き更新、未登録は insert
- 管理ツールは L Message チャットURLへ遷移するまでを担当する
- 実送信は L Message 上で行う
- 新規友だち対応のため、必要に応じて friend_id 回収用の補助スクレイピングを行う

### 今後詰める項目

- CSV列名と DBカラム名の正式マッピング
- 管理ステータスの候補一覧
- CSV取込処理の詳細仕様
- friend_id 回収API仕様
- 一覧画面と検索UI設計

