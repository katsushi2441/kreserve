<?php
/**
 * kreserve 共通部品 — 台帳(JSON+flock)・空き枠計算・メール・画面部品。
 *
 * データベースは使わない。予約は kres_data/bookings.json 1ファイルに
 * flock付きで追記する(kbilling/kpaylinkと同じ方式)。更新は必ず
 * kres_update() を通すこと。空き枠の判定と予約の書き込みを同じロックの
 * 中で行うことで、同時アクセスでもダブルブッキングが起きない。
 *
 * PHP 5.6でも動く書き方(array()・??なし)にしている。レンタルサーバーの
 * 既定PHPが古くても、設置しただけで動くことを優先した。
 */

date_default_timezone_set('Asia/Tokyo');

if (!defined('KRES_DATA_DIR')) { define('KRES_DATA_DIR', __DIR__ . '/kres_data'); }
define('KRES_LEDGER', KRES_DATA_DIR . '/bookings.json');

function kres_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function kres_random_hex($bytes) {
    if (function_exists('random_bytes')) { return bin2hex(random_bytes($bytes)); }
    return bin2hex(openssl_random_pseudo_bytes($bytes));
}

function kres_is_demo() { return defined('KRES_DEMO') && KRES_DEMO; }

/* ---- 台帳 ---- */

function kres_load() {
    if (!file_exists(KRES_LEDGER)) { return array(); }
    $fp = fopen(KRES_LEDGER, 'rb');
    if (!$fp) { return array(); }
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($json, true);
    return (is_array($data) && isset($data['bookings']) && is_array($data['bookings']))
        ? $data['bookings'] : array();
}

/**
 * 台帳を排他ロックの中で更新する。$fn には予約配列が参照で渡り、
 * 文字列を返すとエラー(保存しない)、それ以外は保存して成功。
 * 戻り値: array(true, $fnの戻り値) または array(false, エラーメッセージ)
 */
function kres_update($fn) {
    if (!is_dir(KRES_DATA_DIR)) { @mkdir(KRES_DATA_DIR, 0775, true); }
    $fp = fopen(KRES_LEDGER, 'c+b');
    if (!$fp) { return array(false, '台帳を開けません'); }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return array(false, '台帳をロックできません'); }
    $json = stream_get_contents($fp);
    $data = json_decode($json, true);
    if (!is_array($data)) { $data = array('bookings' => array()); }
    if (!isset($data['bookings']) || !is_array($data['bookings'])) { $data['bookings'] = array(); }

    $result = $fn($data['bookings']);
    if (is_string($result)) {           // エラー: 保存しない
        flock($fp, LOCK_UN); fclose($fp);
        return array(false, $result);
    }
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    return array(true, $result);
}

function kres_find($id) {
    foreach (kres_load() as $b) { if ($b['id'] === $id) { return $b; } }
    return null;
}

function kres_find_by_token($token) {
    if ($token === '') { return null; }
    foreach (kres_load() as $b) {
        if (isset($b['token']) && hash_equals($b['token'], $token)) { return $b; }
    }
    return null;
}

/* ---- 設定の取り出し ---- */

function kres_service($key) {
    $s = kres_services();
    return isset($s[$key]) ? $s[$key] : null;
}

/* ---- 空き枠計算 ----
 * 考え方(Easy!Appointmentsの概念を1店舗ぶんに削ったもの):
 *   ・営業時間は曜日ごと(kres_hours)。臨時休業日(kres_closed_days)は丸ごと休み
 *   ・枠は KRES_SLOT_MINUTES 刻み。メニューの所要時間が営業時間内に収まる開始時刻だけ出す
 *   ・同じ時間帯に重なれる予約数 = KRES_CAPACITY(施術者・窓口の数)
 */

function kres_day_hours($date) {
    if (in_array($date, kres_closed_days(), true)) { return null; }
    $w = (int)date('w', strtotime($date . ' 12:00:00'));
    $h = kres_hours();
    return (isset($h[$w]) && is_array($h[$w])) ? $h[$w] : null;
}

function kres_valid_date($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
    $t = strtotime($date . ' 12:00:00');
    if ($t === false || date('Y-m-d', $t) !== $date) { return false; }
    $today = strtotime(date('Y-m-d') . ' 12:00:00');
    $diff = ($t - $today) / 86400;
    return $diff >= 0 && $diff <= KRES_DAYS_AHEAD;
}

function kres_overlap_count($date, $time, $minutes, $bookings, $ignore_id) {
    $s = strtotime($date . ' ' . $time);
    $e = $s + $minutes * 60;
    $n = 0;
    foreach ($bookings as $b) {
        if ($b['status'] !== 'confirmed' || $b['date'] !== $date) { continue; }
        if ($ignore_id !== '' && $b['id'] === $ignore_id) { continue; }
        $bs = strtotime($b['date'] . ' ' . $b['time']);
        $be = $bs + (int)$b['minutes'] * 60;
        if ($s < $be && $bs < $e) { $n++; }
    }
    return $n;
}

function kres_slot_free($date, $time, $minutes, $bookings, $ignore_id = '') {
    return kres_overlap_count($date, $time, $minutes, $bookings, $ignore_id) < KRES_CAPACITY;
}

/**
 * その日の予約可能な開始時刻の一覧。'HH:MM' => 空きなら true。
 * 予約ページに出すのは true/false 両方(埋まりは×で見せる)。
 */
function kres_slots($date, $minutes, $bookings) {
    $hours = kres_day_hours($date);
    if (!$hours) { return array(); }
    $open  = strtotime($date . ' ' . $hours[0]);
    $close = strtotime($date . ' ' . $hours[1]);
    $step  = KRES_SLOT_MINUTES * 60;
    $min_start = time() + KRES_MIN_HOURS_BEFORE * 3600;   // 直前予約の締切
    $out = array();
    for ($t = $open; $t + $minutes * 60 <= $close; $t += $step) {
        if ($t < $min_start) { continue; }
        $hm = date('H:i', $t);
        $out[$hm] = kres_slot_free($date, $hm, $minutes, $bookings);
    }
    return $out;
}

/** 日付一覧に出す空き状況: ○(余裕) / △(残りわずか) / ×(満枠) / −(休み) */
function kres_day_mark($date, $minutes, $bookings) {
    $slots = kres_slots($date, $minutes, $bookings);
    if (!$slots) { return kres_day_hours($date) ? '×' : '−'; }
    $free = 0;
    foreach ($slots as $ok) { if ($ok) { $free++; } }
    if ($free === 0) { return '×'; }
    return ($free <= 2) ? '△' : '○';
}

/* ---- キャンセル可否 ---- */

function kres_can_cancel($b) {
    if ($b['status'] !== 'confirmed') { return false; }
    $start = strtotime($b['date'] . ' ' . $b['time']);
    return time() <= $start - KRES_CANCEL_HOURS_BEFORE * 3600;
}

/* ---- メール ----
 * レンタルサーバー標準の mail() で送る(kbillingと同じ)。SMTP設定は不要。
 * デモでは送らない。宛先が空・不正でも黙って送らない。 */

function kres_mail($to, $subject, $body) {
    if (kres_is_demo()) { return true; }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    $from = KRES_MAIL_FROM;
    $headers = implode("\r\n", array(
        'From: ' . mb_encode_mimeheader(KRES_SHOP_NAME, 'UTF-8') . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Kurage Reserve',
    ));
    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8'),
                 rtrim(chunk_split(base64_encode($body))), $headers);
}

function kres_booking_lines($b) {
    $sv = kres_service($b['service']);
    $w = array('日', '月', '火', '水', '木', '金', '土');
    $date_label = date('Y年n月j日', strtotime($b['date'] . ' 12:00:00'))
        . '(' . $w[(int)date('w', strtotime($b['date'] . ' 12:00:00'))] . ') ' . $b['time'];
    return array(
        '予約番号: ' . $b['id'],
        '日時: ' . $date_label,
        'メニュー: ' . ($sv ? $sv['name'] : $b['service']) . '（約' . $b['minutes'] . '分）',
        'お名前: ' . $b['name'] . ' 様',
        'お電話: ' . $b['phone'],
    );
}

function kres_mail_customer($b, $cancel_url) {
    if ($b['email'] === '') { return true; }   // メール任意。無ければ画面案内のみ
    $body = $b['name'] . " 様\n\n"
        . KRES_SHOP_NAME . " のご予約を承りました。\n\n"
        . implode("\n", kres_booking_lines($b)) . "\n\n"
        . "■ 予約のキャンセル（" . KRES_CANCEL_HOURS_BEFORE . "時間前まで）\n"
        . $cancel_url . "\n\n"
        . "ご来店をお待ちしております。\n"
        . KRES_SHOP_NAME . (KRES_SHOP_TEL !== '' ? "\nTEL: " . KRES_SHOP_TEL : '');
    return kres_mail($b['email'], '【' . KRES_SHOP_NAME . '】ご予約を承りました', $body);
}

function kres_mail_shop($b, $kind) {
    if (KRES_NOTIFY_TO === '') { return true; }
    $label = ($kind === 'cancel') ? 'キャンセル' : '新規予約';
    $body = $label . "が入りました。\n\n" . implode("\n", kres_booking_lines($b))
        . "\nメール: " . ($b['email'] !== '' ? $b['email'] : '（未記入）')
        . ($b['note'] !== '' ? "\nご要望: " . $b['note'] : '');
    return kres_mail(KRES_NOTIFY_TO, '【予約' . $label . '】' . $b['date'] . ' ' . $b['time'] . ' ' . $b['name'] . '様', $body);
}

/* ---- 画面部品(スマホ前提の予約ページ) ---- */

function kres_head($title) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . kres_h($title) . '</title><style>'
        . ':root{--ink:#24313d;--muted:#6b7a88;--line:#dde4ea;--accent:' . KRES_ACCENT_COLOR . ';--bg:#f6f8fa}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);'
        . 'font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.75}'
        . '.wrap{max-width:640px;margin:0 auto;padding:18px 16px 48px}'
        . 'header.shop{padding:22px 16px;text-align:center;background:#fff;border-bottom:1px solid var(--line)}'
        . 'header.shop b{font-size:19px}header.shop small{display:block;color:var(--muted);font-size:12px;margin-top:2px}'
        . 'h2{font-size:16px;margin:26px 0 10px}'
        . '.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin:10px 0}'
        . '.svc{display:block;text-decoration:none;color:var(--ink);background:#fff;border:1.5px solid var(--line);'
        . 'border-radius:14px;padding:14px 16px;margin:8px 0}'
        . '.svc:hover,.svc.on{border-color:var(--accent)}'
        . '.svc b{font-size:15px}.svc span{float:right;color:var(--muted);font-size:12.5px;margin-top:2px}'
        . '.days{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}'
        . '.day{display:block;text-align:center;text-decoration:none;border:1.5px solid var(--line);border-radius:10px;'
        . 'padding:7px 0;background:#fff;color:var(--ink);font-size:12px;line-height:1.5}'
        . '.day em{display:block;font-style:normal;font-size:15px;font-weight:700}'
        . '.day.off{opacity:.4;pointer-events:none}.day.on{border-color:var(--accent);background:var(--accent);color:#fff}'
        . '.slots{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}'
        . '.slot{display:block;text-align:center;text-decoration:none;border:1.5px solid var(--line);border-radius:10px;'
        . 'padding:9px 0;background:#fff;color:var(--ink);font-weight:700;font-size:14px}'
        . '.slot.x{opacity:.35;pointer-events:none;text-decoration:line-through}'
        . '.slot.on{border-color:var(--accent);background:var(--accent);color:#fff}'
        . 'label{display:block;font-size:12.5px;color:var(--muted);font-weight:700;margin:12px 0 4px}'
        . 'input,textarea{width:100%;border:1.5px solid var(--line);border-radius:10px;padding:11px 12px;font:inherit;background:#fff}'
        . 'input:focus,textarea:focus{outline:none;border-color:var(--accent)}'
        . '.btn{display:inline-block;width:100%;border:0;border-radius:12px;padding:14px 0;font:700 15px inherit;'
        . 'background:var(--accent);color:#fff;cursor:pointer;text-align:center;text-decoration:none}'
        . '.btn.ghost{background:#fff;color:var(--ink);border:1.5px solid var(--line)}'
        . '.muted{color:var(--muted);font-size:12px}.err{background:#fdf1f1;border:1px solid #edc4c4;color:#a33;'
        . 'border-radius:10px;padding:10px 14px;font-size:13px;margin:10px 0}'
        . '.ok{background:#eef8f2;border:1px solid #bfe0cd;color:#1e6e46;border-radius:10px;padding:10px 14px;font-size:13px;margin:10px 0}'
        . '.crumbs{font-size:12px;color:var(--muted);margin:14px 0 2px}.crumbs b{color:var(--ink)}'
        . 'table{width:100%;border-collapse:collapse;background:#fff}'
        . 'td,th{border-bottom:1px solid var(--line);padding:8px 9px;font-size:13px;text-align:left;vertical-align:top}'
        . 'th{font-size:11px;color:var(--muted)}'
        . '.st-confirmed{color:#1e6e46;font-weight:700}.st-cancelled{color:#a33;text-decoration:line-through}'
        . 'footer{padding:22px;text-align:center;color:var(--muted);font-size:11px}'
        . '</style></head><body>'
        . '<header class="shop"><b>' . kres_h(KRES_SHOP_NAME) . '</b>'
        . '<small>ご予約' . (KRES_SHOP_TEL !== '' ? '（お電話 ' . kres_h(KRES_SHOP_TEL) . '）' : '') . '</small></header>'
        . '<div class="wrap">';
    if (kres_is_demo()) {
        $on_admin = (basename($_SERVER['SCRIPT_NAME']) === 'kreserve_admin.php');
        echo '<p class="ok">これはデモ環境です。予約してもメールは送信されません。データは定期的に消去されます。<br>'
            . ($on_admin
                ? '→ <a href="kreserve.php">お客様向けの予約ページを見る</a>'
                : '→ <a href="kreserve_admin.php">お店側の管理画面デモを見る</a>（パスワード: demo）')
            . '</p>';
    }
}

function kres_foot() {
    echo '</div><footer>powered by Kurage Reserve（kreserve）</footer>' . ((($_SERVER['HTTP_HOST'] ?? '') === 'proto.exbridge.jp') ? '<p style="text-align:center;font-size:13px;margin:14px 0;color:#5d6b7a">これはデモです。<a href="https://kappstore.exbridge.jp/app.php?id=362c94ab4e1384f2&amp;ref=kreserve" target="_blank" rel="noopener">この製品をオンプレミスで導入する（商品ページ）</a></p>' : '') . '</body></html>';
}

/* ---- CSRF ---- */

function kres_session_start($name) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name($name);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @session_set_cookie_params(0, '/', '', $secure, true);
        @session_start();
    }
    if (empty($_SESSION['kres_csrf'])) { $_SESSION['kres_csrf'] = kres_random_hex(16); }
}

function kres_csrf_field() {
    return '<input type="hidden" name="csrf" value="' . kres_h($_SESSION['kres_csrf']) . '">';
}

function kres_csrf_ok() {
    return isset($_POST['csrf']) && hash_equals($_SESSION['kres_csrf'], (string)$_POST['csrf']);
}
