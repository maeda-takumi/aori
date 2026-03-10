# friend_id 取得・同期（macro）

## 1) Seleniumでfriend_id取得

`friend_id_scraper.py` は、Lステップの友だち一覧ページのリンク
`/basic/friendlist/my_page/{friend_id}` から `friend_id` を抽出します。

- 一覧ページのみで完結（詳細ページ遷移なし）
- 重複 `friend_id` は後勝ちで更新扱い
- JSON形式でAPIへPOST可能

### 実行

```bash
python3 macro/friend_id_scraper.py
```

実行中に以下を行います。

1. ログイン画面にメールアドレス/パスワードが自動入力される
2. 必要ならログイン操作後 Enter
3. 取得データを固定API URLへ自動送信

送信JSON形式:

```json
{
  "friends": [
    {
      "line_display_name": "山田 太郎",
      "href": "/basic/friendlist/my_page/123456",
      "friend_id": "123456"
    }
  ]
}
```

## 2) PHP APIでcontacts.friend_idへ反映

`friend_id_sync_api.php` は JSON を受け、`contacts.friend_id` を更新します。

- `line_display_name` 一致で更新
- 見つからない場合は `system_display_name` 一致で再試行
- `friend_id` は文字列として保存

### APIエンドポイント（本スクリプトの固定送信先）

```text
https://totalappworks.com/support_aori/macro/friend_id_sync_api.php
```

### リクエスト（POST / application/json）

```json
{
  "friends": [
    { "line_display_name": "山田 太郎", "friend_id": "123456" }
  ]
}
```

### トークン認証

本スクリプトはAPIトークンを送信しません。

