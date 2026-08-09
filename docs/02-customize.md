# kreserve 改造レシピ集（AIエージェント向け）

「設定でできること」は SKILL.md を先に見る。ここは**コードを触る改造**の手引き。
どの改造でも、終わったら `php scripts/check_kreserve.php` で25件通ることを確認する。

## レシピ1: 予約フォームに項目を足す（例:「ご来店回数」）

4か所を揃える。

1. **フォーム** (`kreserve.php` ステップ4): `<select name="visits">` 等を追加
2. **POST検証** (同ファイルの確定処理): 許す値を検証してから
3. **レコード** `$new` 配列にキーを追加: `'visits' => $visits,`
4. **管理画面** (`kreserve_admin.php` の `kres_admin_table` とCSV出力) に表示列を追加。
   古い予約にはこの項目が無いので、必ず `isset($b['visits']) ? $b['visits'] : ''` で読む

## レシピ2: スタッフ指名制にする

考え方: 「スタッフ」= 容量の内訳。最小の実装は
- `kres_services()` と同様に `kres_staff()` を config に追加
- 予約レコードに `staff` キーを追加(レシピ1と同じ4点セット)
- 空き判定を「同一スタッフの重なりだけ数える」に変える:
  `kres_overlap_count()` に `$staff` 引数を足し、`$b['staff'] !== $staff` は数えない。
  `KRES_CAPACITY` は 1 のまま(スタッフごとに1件)

## レシピ3: リマインダーメール(前日通知)

`scripts/remind.php` を新設し、cron で1日1回叩く:
```php
require config + lib;
foreach (kres_load() as $b) {
    if ($b['status'] === 'confirmed' && $b['date'] === date('Y-m-d', strtotime('+1 day'))
        && $b['email'] !== '' && empty($b['reminded_at'])) {
        kres_mail($b['email'], '【'.KRES_SHOP_NAME.'】明日のご予約のお知らせ', ...);
        // reminded_at を kres_update() で記録(二重送信防止)
    }
}
```
cronが使えないサーバーでは、管理画面ログイン時に同じ処理を走らせる方式でもよい
(その場合も `reminded_at` の記録は必須)。

## レシピ4: 定休日を「第2・第4火曜」のような規則にする

`kres_day_hours()`(lib)は `kres_closed_days()` と曜日表しか見ない。
規則が要るなら config の `kres_closed_days()` を関数として拡張:
```php
function kres_closed_days() {
    $out = array('2026-12-31');
    // 第2・第4火曜を90日ぶん追加
    for ($i = 0; $i < 90; $i++) {
        $d = date('Y-m-d', strtotime('+' . $i . ' day'));
        $w = (int)date('w', strtotime($d . ' 12:00'));
        $nth = (int)ceil((int)date('j', strtotime($d)) / 7);
        if ($w === 2 && ($nth === 2 || $nth === 4)) { $out[] = $d; }
    }
    return $out;
}
```
libは配列を受け取るだけなので、libの変更は不要。

## レシピ5: 見た目を店に合わせる

- テーマ色: `KRES_ACCENT_COLOR`(config)
- ロゴ・写真: `kres_head()`(lib)の `<header class="shop">` に `<img>` を足す
- 文言: 予約ページの文言は `kreserve.php`、メール文面は lib の `kres_mail_customer()` 等

## やってはいけないこと

- 空き判定を `kres_update()` のロックの外に出す(ダブルブッキングする)
- `kres_data/` の .htaccess deny を外す(予約＝個人情報が丸見えになる)
- `bookings.json` を直接手で編集する(壊れたら全予約が読めなくなる。必ず管理画面かkres_update経由)
- 運用開始後にメニューのキー名を変える(過去予約との対応が切れる)
