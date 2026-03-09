# L Message業務改善ツール 仕様書

## 概要

L Messageを顧客管理・チャット送信の正本として使い続けながら、
外部の業務改善ツールで以下を効率化する。

- L Messageの顧客CSVを取り込む
- 送信対象者を抽出する
- 個別文面を生成・表示する
- 対象ユーザーのL Messageチャット画面へ直接遷移する

ただし、L Messageのチャット履歴を残すことを最優先とするため、
実際の送信処理はL Message上で行う。

---

## 背景

現在の業務フローは以下の通り。

1. L Messageから顧客情報CSVをダウンロード
2. CSVから送信対象者を抽出
3. L Messageの友だちリストで対象ユーザーを検索
4. 個別文面を確認して送信

課題は以下。

- 送信対象者が数十人になると、友だち検索の手間が大きい
- 人によって送信文面が変わるため、一斉送信とは相性が悪い
- Messaging APIで直接送るとL Message上に履歴が残らない
- スクレイピングによる送信自動化は、UI変更・ログイン維持・スマホ非対応などの問題が大きい

このため、
**送信そのものはL Messageで行い、検索・抽出・遷移を外部ツールで効率化する** 方針とする。

---

## 方針

### 基本方針

- 顧客管理とチャット履歴の正本はL Messageとする
- 外部ツールは送信対象者の抽出と、チャット画面への遷移補助を担当する
- メッセージ送信自体はL Messageのチャット画面から行う

### 採用しない方針

#### Messaging APIによる直接送信
不採用理由:

- L Message上に送信履歴が残らない
- 現場運用で履歴が分断されて分かりにくい

#### 常時スクレイピングによる自動送信
不採用理由:

- UI変更に弱い
- ローカルPC依存が強い
- スマホ操作に不向き
- ログイン維持が難しい
- Re:robotログイン対策への対応が重い

---

## システム構成

### 1. 管理ツール（Web / PHP）

役割:

- L MessageのCSV取込
- 送信対象者抽出
- 個別メッセージ候補の表示
- 保存済みfriend_idを使ったL MessageチャットURL生成
- 対象ユーザーのチャット画面へのリダイレクト
- 本文コピー補助

配置:

- スターレンタルサーバー上にPHPで構築

### 2. friend_id回収ツール（補助用スクレイピングツール）

役割:

- 新規友だちなど、未紐付けユーザーのfriend_idを取得
- 取得したfriend_idをレンタルサーバー側へ送信

位置づけ:

- 日常運用の主役ではない
- 未紐付けユーザーが増えたときだけ使う保守用ツール

### 3. PHP API

役割:

- スクレイピングツールから friend_id を受け取る
- DBに保存・更新する

---

## friend_idについて

L MessageのチャットURLは以下形式で開ける。

```text
https://step.lme.jp/basic/chat-v3?friend_id=28067136
```

この `friend_id` はL Message内部の独自管理IDと考えられる。
L MessageのCSV左1列目とは一致せず、CSVの既存情報だけから自動生成はできない。

そのため、`friend_id` は別途取得し、外部DBに紐付け保存する必要がある。

---

## データ管理方針

### 主キー

外部管理上の主キーは `line_user_id` を基本とする。

理由:

- L MessageのCSVに含まれている
- Messaging APIや外部処理でも扱いやすい
- 一意性が高い

### 紐付け情報

保持する情報例:

- line_user_id
- friend_id
- chat_url
- name
- updated_at

### テーブル例

```sql
CREATE TABLE lmessage_mapping (
  id INT AUTO_INCREMENT PRIMARY KEY,
  line_user_id VARCHAR(64) NOT NULL UNIQUE,
  friend_id BIGINT NOT NULL,
  chat_url VARCHAR(255) DEFAULT NULL,
  name VARCHAR(255) DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## 管理ツールの主要機能

### 1. CSV取込

- L Messageから出力した顧客CSVをアップロード
- CSVを読み込み、必要な顧客情報を抽出

### 2. 送信対象者抽出

- 条件に応じて送信対象者を絞り込み
- 人ごとのステータスや属性をもとに抽出

### 3. 個別文面表示

- 人ごとに個別メッセージを生成または表示
- 一斉送信ではなく個別対応前提

### 4. チャットURL生成

保存済みfriend_idがある場合、以下URLを生成する。

```text
https://step.lme.jp/basic/chat-v3?friend_id={friend_id}
```

### 5. リダイレクト / リンク遷移

管理ツール上で「チャットを開く」ボタンを押すと、
対象ユーザーのL Messageチャット画面へ遷移する。

実装方法:

- aタグリンク
- JavaScriptの `window.open()`
- PHPの `header('Location: ...')`

### 6. 本文コピー補助

- 個別メッセージ本文をワンタップでコピーできるようにする
- ユーザーはL Messageチャット画面で貼り付けて送信する

---

## 画面イメージ

```text
山田太郎 | 要対応 | [チャットを開く] [本文をコピー]
佐藤花子 | 再案内 | [チャットを開く] [本文をコピー]
鈴木一郎 | 未紐付け | [friend_id登録待ち]
```

### 表示ルール

- friend_idあり: 「チャットを開く」表示
- friend_idなし: 「未紐付け」と表示
- 未紐付けユーザーは回収対象として明示する

---

## friend_id回収ツールの役割

### 利用タイミング

- 新規友だち追加後
- 未紐付けユーザーが一定数たまったとき
- 必要に応じて手動で実行

### やること

- L Message画面から対象ユーザーのチャットURLまたはfriend_idを取得
- `line_user_id` と `friend_id` をセットでPHP APIへ送信

### 運用方針

- 常時稼働しない
- 日常業務のメインツールにしない
- 補修・保守用途に限定する

---

## PHP API仕様

### 役割

- `line_user_id` と `friend_id` を受け取りDBへ保存
- 既存レコードがあれば更新
- chat_urlも同時生成して保存

### 推奨仕様

- POSTで受け取る
- APIキー認証をつける
- friend_idは数字チェック
- line_user_idは形式チェック
- upsert対応

### 受信項目

- line_user_id
- friend_id

### レスポンス例

```json
{
  "ok": true,
  "line_user_id": "Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "friend_id": "28067136",
  "chat_url": "https://step.lme.jp/basic/chat-v3?friend_id=28067136"
}
```

---

## 運用フロー

### 日常運用

1. L MessageからCSVを出力
2. 管理ツールへCSVアップロード
3. 送信対象者を抽出
4. 対象者ごとの文面を確認
5. 「チャットを開く」でL Messageチャット画面へ移動
6. 本文をコピーしてL Message上で送信

### 補修運用

1. 未紐付けユーザーを確認
2. friend_id回収ツールを必要時のみ実行
3. friend_idを取得
4. PHP APIへ送信
5. DBに保存
6. 次回以降は管理ツールから直接遷移可能

---

## この構成のメリット

- L Message上にチャット履歴を残せる
- Messaging API送信による履歴分断を避けられる
- スクレイピングを常用せずに済む
- スマホ運用を残しやすい
- スターレンタルサーバーと相性が良い
- 新規ユーザーは補修フローで徐々に対応可能

---

## この構成のデメリット

- friend_idを公開機能だけで一括取得できない
- 新規ユーザーは最初だけ紐付けが必要
- 完全ワンボタン送信ではなく、最終送信は手動
- friend_id回収ツールの保守が必要

---

## 最終結論

現時点で最も現実的な仕様は以下。

- 管理ツールはPHPで構築する
- 管理ツールは送信対象者の抽出、文面表示、チャットURLへの遷移までを担当する
- 実際の送信はL Messageチャット画面上で行う
- friend_idはL Message内部IDのため、別途取得してDBに保存する
- friend_id取得はスクレイピング補助ツールで行う
- スクレイピング補助ツールは常時運用せず、新規友だち対応時のみ使用する
- friend_id保存はデスクトップアプリからPHP APIを叩いて行う

この方針により、
**L Messageの履歴一元管理を維持しつつ、検索・抽出・遷移の手間を大きく削減する** ことを目指す。
