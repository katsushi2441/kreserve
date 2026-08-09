# kreserve のしくみ（AIエージェント向け設計マニュアル）

これは、予約・受付システム kreserve を **AIエージェント（Claude Code等）が理解して
設置・改造するための設計書** です。人間が読んでも分かるように書いています。

## 何をするものか

サロン・クリニック・士業事務所の「予約ページ」と「予約管理」。

- お客様: `kreserve.php` — メニュー → 日 → 時間 → 名前・電話 の4ステップで予約。
  完了時にキャンセル用URLを発行(メールにも記載)
- お店: `kreserve_admin.php` — パスワードでログインし、日別一覧・検索・取消/復活・
  電話予約の手動追加・月別CSV
- 設定: `kreserve_config.php` — メニュー・営業時間・定休日・受付ルール。
  管理画面ではなく設定ファイルに置いてあるのは、**AIエージェントに頼んで変える**前提だから

## 設計の要点

### データの持ち方
- DBは使わない。予約は `kres_data/bookings.json` 1ファイル。
- 予約レコード: `id`(表示用8桁hex) / `token`(キャンセルURL用16bytes hex) /
  `service`(メニューキー) / `date` / `time` / `minutes` / `name` / `phone` /
  `email`(任意) / `note` / `status`(confirmed|cancelled) / `created_at`
- 書き込みは必ず `kres_update(callback)` を通す。flock(排他)の中でcallbackが
  台帳を書き換え、文字列を返すと「エラー・保存しない」になる。

### ダブルブッキングが起きない理由（最重要）
空き枠の判定を2回行う。

1. 画面表示時(参考情報。この時点の空きを見せる)
2. **予約確定のPOSTで、`kres_update()` の排他ロックの中でもう一度**
   `kres_slot_free()` を呼び、空いていれば同じロックの中で書き込む

2人が同じ枠を同時に押しても、ロックを先に取った方だけが書き込め、
後の方はcallbackがエラー文字列を返して「たった今埋まりました」になる。
**この構造(判定と書き込みを同じロック内で行う)だけは、どんな改造でも崩さないこと。**

### 空き枠の計算 (`kres_slots`)
- 営業時間は曜日ごと(`kres_hours`)。`null` の曜日と `kres_closed_days()` の日は休み
- 開始時刻は `KRES_SLOT_MINUTES` 刻みで、開店から「閉店−所要時間」まで
- 直前予約の締切: 現在時刻+`KRES_MIN_HOURS_BEFORE` 時間より前の枠は出さない
- 埋まり判定: その開始時刻から `minutes` 分の区間と重なる confirmed 予約の数が
  `KRES_CAPACITY` 未満なら空き。**「枠単位」でなく「区間の重なり」で数える**ので、
  60分メニューと30分メニューが混ざっても正しく判定される

### キャンセル
- 予約ごとの `token` を使ったURL(`?cancel=トークン`)。ログイン不要・推測不能
- `KRES_CANCEL_HOURS_BEFORE` 時間前を過ぎたら画面が「お電話ください」に変わる
- 照合は `hash_equals`(タイミング攻撃対策)

### メール
- レンタルサーバー標準の `mail()`。SMTP設定不要。件名・本文はbase64(文字化け対策)
- お客様(email入力時のみ)と店(`KRES_NOTIFY_TO`設定時のみ)へ送る。デモでは送らない

### セキュリティ
- 全出力 `kres_h()`(htmlspecialchars) / 全POST CSRFトークン / 管理は `password_hash`
- `kres_data/` は .htaccess で全deny(予約=個人情報を直接読ませない)
- 管理ログイン失敗時は `sleep(1)`(総当たり対策)

## 設置手順

1. `kreserve_config.php.example` を `kreserve_config.php` にコピーして編集
   （店名・メニュー・営業時間・`KRES_MAIL_FROM`・管理パスワードハッシュ）
2. パスワードハッシュ: `php scripts/make_password_hash.php`
3. `public/` の中身をサーバーへFTPアップロード（`kres_data/.htaccess` も忘れずに）
4. `https://あなたのドメイン/kreserve.php` が予約ページ、
   `kreserve_admin.php` が管理画面
5. 検証: `php scripts/check_kreserve.php`（25件全部OKになること）

PHP 5.6以上で動く（8.3まで確認済み）。DB・Composer・npm 不要。
