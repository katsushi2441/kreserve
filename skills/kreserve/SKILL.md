---
name: kreserve
description: 予約・受付システム kreserve の設定変更・改造を安全に行う。メニュー/営業時間/定休日/受付ルールの変更、項目追加、予約データの扱いはこのスキルの手順に従う。
---

# kreserve — 予約・受付システムの運用・改造スキル

kreserve はサロン・クリニック・士業向けの予約ページ＋管理画面。
PHP のみ・DBなし(JSON台帳)・レンタルサーバーで動く。

## ファイル構成（触っていい場所）

| ファイル | 役割 | 編集 |
|---|---|---|
| `kreserve_config.php` | 店名・メニュー・営業時間・受付ルール | **設定変更はここだけ** |
| `kreserve.php` | お客様向け予約ページ | 改造時のみ |
| `kreserve_admin.php` | 管理画面(予約をさばく) | 改造時のみ |
| `kreserve_lib.php` | 台帳・枠計算・メール | 改造時のみ |
| `kres_data/bookings.json` | 予約台帳(個人情報) | **直接編集禁止** |

## よくある依頼と正しいやり方

### 「カットを70分にして」「メニューを増やして」
`kreserve_config.php` の `kres_services()` を編集する。
```php
'cut' => array('name' => 'カット', 'minutes' => 70, 'price' => 4400),
'perm' => array('name' => 'パーマ', 'minutes' => 120, 'price' => 8800),  // 追加
```
- **キー('cut'等)は運用開始後に変えない**。過去の予約との対応が切れる。
- 追加は自由。削除は、その予約が残っている間は名前だけ画面でキーに退化する(壊れはしない)。

### 「水曜も営業することにした」「盆休みを入れたい」
`kres_hours()`(曜日ごと、nullが定休) と `kres_closed_days()`(臨時休業のYYYY-MM-DD配列)を編集。

### 「同時に2人まで受けたい」(スタッフが増えた)
`KRES_CAPACITY` を 2 に。枠の重なり判定が自動で2人まで許すようになる。

### 「予約の枠を15分刻みにしたい」
`KRES_SLOT_MINUTES` を 15 に。所要時間(minutes)は刻みの倍数でなくてよい。

### 「前日キャンセルは電話のみにしたい」
`KRES_CANCEL_HOURS_BEFORE` を大きくする(例: 48)。締切を過ぎたキャンセルURLは
「お電話でご連絡ください」の案内に自動で変わる。

## 改造時の鉄則

1. **枠の空き判定と予約の書き込みは、必ず `kres_update()` のロックの中で行う。**
   `kreserve.php` のPOST処理がその形になっている。ロックの外で判定してから書くと
   ダブルブッキングが起きる。この構造だけは崩さない。
2. 予約レコードに項目を足すときは、(1)フォーム → (2)POSTの検証 → (3)`$new` 配列 →
   (4)管理画面の表とCSV、の4か所を揃える。既存レコードに無い項目は
   `isset()` で守る(古い予約が読めなくなるため)。
3. 出力は必ず `kres_h()` を通す(XSS)。POSTは必ず `kres_csrf_ok()` を確認する。
4. `kres_data/` はWebから読めない(.htaccess)。この deny は絶対に外さない。
5. 変更後は `php scripts/check_kreserve.php` を実行し、25件全部通ることを確認する。

## 動作確認のしかた

```bash
php -l kreserve.php && php -l kreserve_admin.php && php -l kreserve_lib.php
php scripts/check_kreserve.php   # 枠計算・ダブルブッキング防止の検証25件
```
