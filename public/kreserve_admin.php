<?php
/**
 * kreserve — 管理画面(お店側)。
 *
 * できること: 日ごとの予約一覧 / 今後の予約一覧 / キャンセル・復活 /
 * 電話予約などの手動追加 / 名前・電話での検索 / 月別CSV書き出し。
 *
 * メニューや営業時間の変更は、ここではなく kreserve_config.php を
 * 編集する(AIエージェントに頼める。docs/ 参照)。日々の運用と設定を
 * 分けることで、管理画面を「予約を見る・さばく」ことに集中させている。
 */
require_once __DIR__ . '/kreserve_config.php';
require_once __DIR__ . '/kreserve_lib.php';

kres_session_start('KRESADMSESSID');

$logged_in = !empty($_SESSION['kres_admin']);

/* ---- ログイン/ログアウト ---- */

if (isset($_GET['logout'])) {
    $_SESSION['kres_admin'] = false;
    header('Location: ' . basename(__FILE__)); exit;
}
$login_err = '';
if (!$logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (KRES_ADMIN_PASSWORD_HASH !== '' && kres_csrf_ok()
        && password_verify((string)$_POST['password'], KRES_ADMIN_PASSWORD_HASH)) {
        $_SESSION['kres_admin'] = true;
        session_regenerate_id(true);
        header('Location: ' . basename(__FILE__)); exit;
    }
    $login_err = 'パスワードが違います。';
    sleep(1);   // 総当たりを鈍らせる
}
if (!$logged_in) {
    kres_head('管理ログイン | ' . KRES_SHOP_NAME);
    if (KRES_ADMIN_PASSWORD_HASH === '') {
        echo '<div class="err">管理パスワードが未設定です。kreserve_config.php の KRES_ADMIN_PASSWORD_HASH を設定してください'
            . '（作り方: php scripts/make_password_hash.php）。</div>';
    }
    if ($login_err !== '') { echo '<div class="err">' . kres_h($login_err) . '</div>'; }
    echo '<h2>管理ログイン</h2><form method="post" class="card">' . kres_csrf_field()
        . '<label>パスワード</label><input type="password" name="password" required autofocus>'
        . '<p style="margin:16px 0 0"><button class="btn">ログイン</button></p></form>';
    if (kres_is_demo()) { echo '<p class="muted">デモ環境のパスワード: demo</p>'; }
    kres_foot(); exit;
}

/* ---- CSV書き出し(?csv=YYYY-MM) ---- */

if (isset($_GET['csv']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['csv'])) {
    $month = (string)$_GET['csv'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kreserve-' . $month . '.csv"');
    echo "\xEF\xBB\xBF";   // ExcelのためのBOM
    $out = fopen('php://output', 'w');
    fputcsv($out, array('予約番号', '日付', '時刻', 'メニュー', '分', 'お名前', '電話', 'メール', '状態', 'ご要望', '受付日時'));
    foreach (kres_load() as $b) {
        if (strpos($b['date'], $month) !== 0) { continue; }
        $sv = kres_service($b['service']);
        fputcsv($out, array($b['id'], $b['date'], $b['time'], $sv ? $sv['name'] : $b['service'],
            $b['minutes'], $b['name'], $b['phone'], $b['email'], $b['status'], $b['note'], $b['created_at']));
    }
    fclose($out); exit;
}

/* ---- 操作(POST): キャンセル/復活/手動追加 ---- */

$notice = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!kres_csrf_ok()) { $err = '画面の有効期限が切れました。もう一度お試しください。'; }
    elseif ($_POST['action'] === 'cancel' || $_POST['action'] === 'restore') {
        $id = (string)(isset($_POST['id']) ? $_POST['id'] : '');
        $to = ($_POST['action'] === 'cancel') ? 'cancelled' : 'confirmed';
        list($ok, $res) = kres_update(function (&$bookings) use ($id, $to) {
            foreach ($bookings as $i => $x) {
                if ($x['id'] !== $id) { continue; }
                if ($to === 'confirmed'
                    && !kres_slot_free($x['date'], $x['time'], $x['minutes'], $bookings, $x['id'])) {
                    return '復活できません: その時間帯は既に他の予約で埋まっています';
                }
                $bookings[$i]['status'] = $to;
                $bookings[$i][$to === 'cancelled' ? 'cancelled_at' : 'restored_at'] = date('c');
                return $bookings[$i];
            }
            return '予約が見つかりません';
        });
        if ($ok) { $notice = $res['id'] . ' を' . ($to === 'cancelled' ? 'キャンセル' : '復活') . 'しました。'; }
        else { $err = $res; }
    }
    elseif ($_POST['action'] === 'add') {
        $s = (string)(isset($_POST['s']) ? $_POST['s'] : '');
        $d = (string)(isset($_POST['d']) ? $_POST['d'] : '');
        $t = (string)(isset($_POST['t']) ? $_POST['t'] : '');
        $name  = trim((string)(isset($_POST['name'])  ? $_POST['name']  : ''));
        $phone = trim((string)(isset($_POST['phone']) ? $_POST['phone'] : ''));
        $force = !empty($_POST['force']);
        $sv = kres_service($s);
        if (!$sv) { $err = 'メニューを選んでください。'; }
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !preg_match('/^\d{2}:\d{2}$/', $t)) { $err = '日付と時刻を入力してください。'; }
        elseif ($name === '') { $err = 'お名前を入力してください。'; }
        else {
            $new = array(
                'id' => strtoupper(kres_random_hex(4)), 'token' => kres_random_hex(16),
                'service' => $s, 'date' => $d, 'time' => $t, 'minutes' => (int)$sv['minutes'],
                'name' => $name, 'phone' => $phone, 'email' => '', 'note' => '（管理画面から追加）',
                'status' => 'confirmed', 'created_at' => date('c'),
            );
            list($ok, $res) = kres_update(function (&$bookings) use ($new, $force) {
                if (!$force && !kres_slot_free($new['date'], $new['time'], $new['minutes'], $bookings)) {
                    return 'その時間帯は埋まっています（「枠を無視して追加」にチェックすると重ねられます）';
                }
                $bookings[] = $new;
                return $new;
            });
            if ($ok) { $notice = $d . ' ' . $t . ' に ' . $name . ' 様の予約を追加しました。'; }
            else { $err = $res; }
        }
    }
}

/* ---- 表示 ---- */

$view_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
    ? (string)$_GET['date'] : date('Y-m-d');
$q = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));

$all = kres_load();
usort($all, 'kres_admin_sort');
function kres_admin_sort($a, $b) {
    $x = $a['date'] . $a['time']; $y = $b['date'] . $b['time'];
    if ($x === $y) { return 0; }
    return ($x < $y) ? -1 : 1;
}

kres_head('予約管理 | ' . KRES_SHOP_NAME);
echo '<p style="margin:4px 0 0;display:flex;gap:8px;flex-wrap:wrap;font-size:12.5px">'
    . '<a href="' . kres_h(basename(__FILE__)) . '">今日</a> ・ '
    . '<a href="kreserve.php">予約ページを見る</a> ・ '
    . '<a href="?csv=' . date('Y-m') . '">今月CSV</a> ・ '
    . '<a href="?logout=1">ログアウト</a></p>';
if ($notice !== '') { echo '<div class="ok">' . kres_h($notice) . '</div>'; }
if ($err !== '') { echo '<div class="err">' . kres_h($err) . '</div>'; }

/* --- 検索 --- */
echo '<form method="get" style="display:flex;gap:8px;margin:14px 0 0">'
    . '<input name="q" value="' . kres_h($q) . '" placeholder="お名前・電話で検索">'
    . '<button class="btn" style="width:auto;padding:0 22px">検索</button></form>';

if ($q !== '') {
    echo '<h2>「' . kres_h($q) . '」の検索結果</h2>';
    $hit = array();
    foreach ($all as $b) {
        if (mb_stripos($b['name'] . ' ' . $b['phone'], $q, 0, 'UTF-8') !== false) { $hit[] = $b; }
    }
    kres_admin_table($hit);
    kres_foot(); exit;
}

/* --- 日ナビ --- */
$w = array('日', '月', '火', '水', '木', '金', '土');
echo '<h2>日別の予約</h2><div class="days">';
for ($i = 0; $i <= 13; $i++) {
    $date = date('Y-m-d', strtotime('+' . $i . ' day'));
    $n = 0;
    foreach ($all as $b) { if ($b['date'] === $date && $b['status'] === 'confirmed') { $n++; } }
    $cls = 'day' . ($date === $view_date ? ' on' : '') . (kres_day_hours($date) ? '' : ' off');
    echo '<a class="' . $cls . '" style="pointer-events:auto" href="?date=' . $date . '">'
        . date('n/j', strtotime($date . ' 12:00:00')) . '<br>' . $w[(int)date('w', strtotime($date . ' 12:00:00'))]
        . '<em>' . ($n > 0 ? $n . '件' : '−') . '</em></a>';
}
echo '</div>';

$day = array();
foreach ($all as $b) { if ($b['date'] === $view_date) { $day[] = $b; } }
echo '<h2>' . kres_h(date('Y年n月j日', strtotime($view_date . ' 12:00:00')))
    . '(' . $w[(int)date('w', strtotime($view_date . ' 12:00:00'))] . ') — 予約 '
    . count($day) . '件</h2>';
kres_admin_table($day);

/* --- 手動追加 --- */
echo '<h2>予約を手動で追加（電話予約など）</h2><form method="post" class="card">' . kres_csrf_field()
    . '<input type="hidden" name="action" value="add">'
    . '<label>メニュー</label><select name="s" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:11px 12px;font:inherit;background:#fff">';
foreach (kres_services() as $key => $x) {
    echo '<option value="' . kres_h($key) . '">' . kres_h($x['name']) . '（' . (int)$x['minutes'] . '分）</option>';
}
echo '</select>'
    . '<label>日付</label><input type="date" name="d" value="' . kres_h($view_date) . '" required>'
    . '<label>時刻</label><input type="time" name="t" required step="' . (KRES_SLOT_MINUTES * 60) . '">'
    . '<label>お名前</label><input name="name" required maxlength="40">'
    . '<label>お電話番号（任意）</label><input name="phone" maxlength="15">'
    . '<label style="display:flex;align-items:center;gap:8px;margin-top:14px;color:var(--ink)">'
    . '<input type="checkbox" name="force" value="1" style="width:auto">枠を無視して追加する</label>'
    . '<p style="margin:14px 0 0"><button class="btn">追加する</button></p></form>';

echo '<p class="muted" style="margin-top:22px">メニュー・営業時間・受付ルールの変更は kreserve_config.php を編集します。'
    . 'Claude Code などのAIエージェントに「docs を読んでカットを70分にして」のように頼めます。</p>';

kres_foot();

/* ---- 一覧表 ---- */
function kres_admin_table($rows) {
    if (!$rows) { echo '<p class="muted">予約はありません。</p>'; return; }
    echo '<div style="overflow-x:auto"><table><tr><th>時刻</th><th>メニュー</th><th>お名前</th><th>連絡先</th><th>状態</th><th></th></tr>';
    foreach ($rows as $b) {
        $sv = kres_service($b['service']);
        echo '<tr><td><b>' . kres_h($b['date'] === date('Y-m-d') ? $b['time'] : $b['date'] . ' ' . $b['time'])
            . '</b><br><span class="muted">' . (int)$b['minutes'] . '分</span></td>'
            . '<td>' . kres_h($sv ? $sv['name'] : $b['service']) . '</td>'
            . '<td>' . kres_h($b['name']) . ' 様'
            . ($b['note'] !== '' ? '<br><span class="muted">' . kres_h(mb_strimwidth($b['note'], 0, 60, '…', 'UTF-8')) . '</span>' : '')
            . '</td>'
            . '<td class="muted">' . kres_h($b['phone'])
            . ($b['email'] !== '' ? '<br>' . kres_h($b['email']) : '') . '</td>'
            . '<td class="st-' . kres_h($b['status']) . '">' . ($b['status'] === 'confirmed' ? '予約' : '取消') . '</td>'
            . '<td><form method="post">' . kres_csrf_field()
            . '<input type="hidden" name="id" value="' . kres_h($b['id']) . '">'
            . '<input type="hidden" name="action" value="' . ($b['status'] === 'confirmed' ? 'cancel' : 'restore') . '">'
            . '<button class="btn ghost" style="width:auto;padding:6px 12px;font-size:12px">'
            . ($b['status'] === 'confirmed' ? '取消' : '復活') . '</button></form></td></tr>';
    }
    echo '</table></div>';
}
