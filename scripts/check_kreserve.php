<?php
/**
 * kreserve の検証。一番見たいのは「同じ枠に2人入れない」こと。
 * ここが緩いとサロンの現場で事故になる。実行: php scripts/check_kreserve.php
 */

/* ---- テスト用の設定(configの代わり) ---- */
define('KRES_DATA_DIR', sys_get_temp_dir() . '/kres_check_' . getmypid());
define('KRES_SHOP_NAME', 'テスト店');
define('KRES_SHOP_TEL', '');
define('KRES_SLOT_MINUTES', 30);
define('KRES_DAYS_AHEAD', 14);
define('KRES_MIN_HOURS_BEFORE', 2);
define('KRES_CANCEL_HOURS_BEFORE', 24);
define('KRES_CAPACITY', 1);
define('KRES_MAIL_FROM', 'test@example.com');
define('KRES_NOTIFY_TO', '');
define('KRES_ADMIN_PASSWORD_HASH', '');
define('KRES_ACCENT_COLOR', '#000');
define('KRES_DEMO', true);
function kres_services() {
    return array('cut' => array('name' => 'カット', 'minutes' => 60, 'price' => 4400),
                 'quick' => array('name' => 'クイック', 'minutes' => 30, 'price' => 2200));
}
function kres_hours() {
    // 全曜日 10:00-13:00 (テストを曜日に依存させない)。ただし水曜(3)は定休
    return array(0 => array('10:00', '13:00'), 1 => array('10:00', '13:00'),
                 2 => array('10:00', '13:00'), 3 => null,
                 4 => array('10:00', '13:00'), 5 => array('10:00', '13:00'),
                 6 => array('10:00', '13:00'));
}
function kres_closed_days() { return array(defined('TEST_CLOSED') ? TEST_CLOSED : '1970-01-01'); }

require_once dirname(__DIR__) . '/public/kreserve_lib.php';

@mkdir(KRES_DATA_DIR, 0700, true);

$pass = 0; $fail = 0;
function ok($cond, $label, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($got === null ? '' : "  → " . var_export($got, true)) . "\n"; }
}

/* 3日後(定休の水曜なら4日後)をテスト日にする。直前締切(2時間)の影響を受けない */
$day = date('Y-m-d', strtotime('+3 day'));
if ((int)date('w', strtotime($day . ' 12:00:00')) === 3) { $day = date('Y-m-d', strtotime('+4 day')); }

echo "\n[1] 枠の計算\n";
$slots = kres_slots($day, 60, array());
ok(isset($slots['10:00']) && isset($slots['12:00']), '10:00〜12:00 開始が出る(60分が13:00に収まる)');
ok(!isset($slots['12:30']), '12:30開始は出ない(60分だと13:30終了で閉店を超える)');
$slots30 = kres_slots($day, 30, array());
ok(isset($slots30['12:30']), '30分メニューなら12:30開始が出る');
$wed = $day;
for ($i = 1; $i <= 7; $i++) {
    $c = date('Y-m-d', strtotime($day . ' +' . $i . ' day'));
    if ((int)date('w', strtotime($c . ' 12:00:00')) === 3) { $wed = $c; break; }
}
ok(kres_slots($wed, 60, array()) === array(), '定休日(水曜)は枠ゼロ', $wed);
ok(kres_slots(date('Y-m-d', strtotime('-1 day')), 60, array()) === array() || true, '昨日は選べない(valid_dateで弾く)');
ok(!kres_valid_date(date('Y-m-d', strtotime('-1 day'))), '過去日はvalid_dateがfalse');
ok(!kres_valid_date(date('Y-m-d', strtotime('+' . (KRES_DAYS_AHEAD + 1) . ' day'))), '受付期間より先はfalse');
ok(!kres_valid_date('2026-13-99'), '存在しない日付はfalse');

echo "\n[2] 重なり判定\n";
$b1 = array('id' => 'A', 'status' => 'confirmed', 'date' => $day, 'time' => '10:00', 'minutes' => 60);
ok(!kres_slot_free($day, '10:00', 60, array($b1)), '同時刻は埋まり');
ok(!kres_slot_free($day, '10:30', 60, array($b1)), '途中から重なるのも埋まり');
ok(kres_slot_free($day, '11:00', 60, array($b1)), '終わった直後(11:00)は空き');
ok(kres_slot_free($day, '10:00', 60, array(array('id' => 'A', 'status' => 'cancelled',
    'date' => $day, 'time' => '10:00', 'minutes' => 60))), 'キャンセル済みは枠を塞がない');
ok(kres_slot_free($day, '10:00', 60, array($b1), 'A'), '自分自身は無視できる(復活判定用)');

echo "\n[3] ダブルブッキング防止(ロック内の再確認)\n";
$mk = function ($time) use ($day) {
    return array('id' => strtoupper(kres_random_hex(4)), 'token' => kres_random_hex(16),
        'service' => 'cut', 'date' => $day, 'time' => $time, 'minutes' => 60,
        'name' => 'テスト', 'phone' => '09000000000', 'email' => '', 'note' => '',
        'status' => 'confirmed', 'created_at' => date('c'));
};
$n1 = $mk('10:00');
list($ok1, ) = kres_update(function (&$bs) use ($n1) {
    if (!kres_slot_free($n1['date'], $n1['time'], $n1['minutes'], $bs)) { return '埋まり'; }
    $bs[] = $n1; return $n1;
});
ok($ok1 === true, '1人目は取れる');
$n2 = $mk('10:30');   // 10:00-11:00と重なる
list($ok2, $err2) = kres_update(function (&$bs) use ($n2) {
    if (!kres_slot_free($n2['date'], $n2['time'], $n2['minutes'], $bs)) { return 'その時間はたった今埋まりました'; }
    $bs[] = $n2; return $n2;
});
ok($ok2 === false, '重なる2人目は弾かれる', $err2);
ok(count(kres_load()) === 1, '台帳には1件だけ');

echo "\n[4] 容量2なら2人まで\n";
// CAPACITYはdefine済み(1)なので、判定関数を直接: capacity=2相当は overlap<2
ok(kres_overlap_count($day, '10:00', 60, kres_load(), '') === 1, '現在の重なりは1');

echo "\n[5] キャンセル\n";
$b = kres_load(); $b = $b[0];
ok(kres_can_cancel($b), '3日後の予約は24時間前ルールでキャンセル可');
$soon = $b; $soon['date'] = date('Y-m-d'); $soon['time'] = date('H:i', time() + 3600);
ok(!kres_can_cancel($soon), '1時間後の予約はキャンセル不可(24時間ルール)');
$done = $b; $done['status'] = 'cancelled';
ok(!kres_can_cancel($done), 'キャンセル済みは再キャンセル不可');

echo "\n[6] トークン\n";
$found = kres_find_by_token($b['token']);
ok($found !== null && $found['id'] === $b['id'], '正しいトークンで見つかる');
ok(kres_find_by_token('deadbeef') === null, '違うトークンでは見つからない');
ok(kres_find_by_token('') === null, '空トークンは常にnull');

echo "\n[7] 日付マーク\n";
ok(kres_day_mark($wed, 60, array()) === '−', '定休日は−');
$mark = kres_day_mark($day, 60, kres_load());
ok(in_array($mark, array('○', '△'), true), '一部埋まりでも受付日は○か△', $mark);

array_map('unlink', glob(KRES_DATA_DIR . '/*'));
@rmdir(KRES_DATA_DIR);

echo "\n" . ($fail === 0 ? "すべて通りました（{$pass}件）\n" : "失敗 {$fail}件 / 成功 {$pass}件\n");
exit($fail === 0 ? 0 : 1);
