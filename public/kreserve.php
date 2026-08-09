<?php
/**
 * kreserve — お客様向けの予約ページ。
 *
 * 流れ: メニューを選ぶ → 日を選ぶ → 時間を選ぶ → お名前・連絡先 → 予約完了。
 * 1画面のままGETパラメータ(s=メニュー, d=日付, t=時刻)で段階を進める。
 * URLがそのまま状態なので、戻る・共有・やり直しが自然に効く。
 *
 * 予約の確定(POST)は、台帳の排他ロックの中で空き枠をもう一度確認してから
 * 書き込む。画面を見ている間に他の人が取っても、ここで必ず弾かれる
 * (ダブルブッキング防止の要)。
 *
 * キャンセルは ?cancel=トークン。予約ごとの推測できないURLで、
 * ログインなしで本人だけがキャンセルできる。
 */
require_once __DIR__ . '/kreserve_config.php';
require_once __DIR__ . '/kreserve_lib.php';

kres_session_start('KRESSESSID');

$services = kres_services();

/* ================= キャンセル画面 ================= */

if (isset($_GET['cancel'])) {
    $b = kres_find_by_token((string)$_GET['cancel']);
    kres_head('予約のキャンセル | ' . KRES_SHOP_NAME);
    if (!$b) {
        echo '<div class="err">この予約は見つかりませんでした。URLをご確認ください。</div>';
        kres_foot(); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kres_csrf_ok() && $b['status'] === 'confirmed') {
        if (!kres_can_cancel($b)) {
            echo '<div class="err">キャンセルの受付は開始' . KRES_CANCEL_HOURS_BEFORE
                . '時間前まででした。恐れ入りますが、お電話でご連絡ください。</div>';
        } else {
            list($ok, $res) = kres_update(function (&$bookings) use ($b) {
                foreach ($bookings as $i => $x) {
                    if ($x['id'] === $b['id']) {
                        if ($x['status'] !== 'confirmed') { return 'この予約は既にキャンセル済みです'; }
                        $bookings[$i]['status'] = 'cancelled';
                        $bookings[$i]['cancelled_at'] = date('c');
                        return $bookings[$i];
                    }
                }
                return '予約が見つかりません';
            });
            if ($ok) {
                $b = $res;
                kres_mail_shop($b, 'cancel');
                if ($b['email'] !== '') {
                    kres_mail($b['email'], '【' . KRES_SHOP_NAME . '】ご予約をキャンセルしました',
                        $b['name'] . " 様\n\n以下のご予約をキャンセルしました。\n\n"
                        . implode("\n", kres_booking_lines($b)) . "\n\nまたのご利用をお待ちしております。\n" . KRES_SHOP_NAME);
                }
                echo '<div class="ok">キャンセルしました。</div>';
            } else {
                echo '<div class="err">' . kres_h($res) . '</div>';
            }
        }
    }
    echo '<h2>ご予約内容</h2><div class="card">';
    foreach (kres_booking_lines($b) as $line) { echo kres_h($line) . '<br>'; }
    echo '<p class="muted" style="margin:8px 0 0">状態: <span class="st-' . kres_h($b['status']) . '">'
        . ($b['status'] === 'confirmed' ? '予約済み' : 'キャンセル済み') . '</span></p></div>';
    if ($b['status'] === 'confirmed') {
        if (kres_can_cancel($b)) {
            echo '<form method="post">' . kres_csrf_field()
                . '<button class="btn" onclick="return confirm(\'この予約をキャンセルします。よろしいですか？\')">この予約をキャンセルする</button></form>'
                . '<p class="muted">キャンセルは開始' . KRES_CANCEL_HOURS_BEFORE . '時間前まで可能です。</p>';
        } else {
            echo '<p class="muted">キャンセルの受付は開始' . KRES_CANCEL_HOURS_BEFORE
                . '時間前まででした。変更はお電話でご連絡ください。</p>';
        }
    }
    echo '<p style="margin-top:18px"><a class="btn ghost" href="' . kres_h(basename(__FILE__)) . '">予約ページへ戻る</a></p>';
    kres_foot(); exit;
}

/* ================= 完了画面 ================= */

if (isset($_GET['done'])) {
    $b = kres_find((string)$_GET['done']);
    kres_head('ご予約ありがとうございます | ' . KRES_SHOP_NAME);
    if ($b && $b['status'] === 'confirmed') {
        $cancel_url = kres_base_url() . '?cancel=' . $b['token'];
        echo '<div class="ok">ご予約を承りました。'
            . ($b['email'] !== '' ? '確認メールをお送りしました。' : '') . '</div>'
            . '<h2>ご予約内容</h2><div class="card">';
        foreach (kres_booking_lines($b) as $line) { echo kres_h($line) . '<br>'; }
        echo '</div><h2>キャンセルする場合</h2><div class="card">'
            . '<p class="muted" style="margin:0 0 6px">下のURLから開始' . KRES_CANCEL_HOURS_BEFORE
            . '時間前までキャンセルできます。このページを閉じる前に控えてください'
            . ($b['email'] !== '' ? '（確認メールにも記載しています）' : '') . '。</p>'
            . '<a href="?cancel=' . kres_h($b['token']) . '" style="word-break:break-all;font-size:12.5px">'
            . kres_h($cancel_url) . '</a></div>';
    } else {
        echo '<div class="err">予約が見つかりませんでした。</div>';
    }
    kres_foot(); exit;
}

/* ================= 予約の確定(POST) ================= */

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = isset($_POST['s']) ? (string)$_POST['s'] : '';
    $d = isset($_POST['d']) ? (string)$_POST['d'] : '';
    $t = isset($_POST['t']) ? (string)$_POST['t'] : '';
    $name  = trim((string)(isset($_POST['name'])  ? $_POST['name']  : ''));
    $phone = trim((string)(isset($_POST['phone']) ? $_POST['phone'] : ''));
    $email = trim((string)(isset($_POST['email']) ? $_POST['email'] : ''));
    $note  = trim((string)(isset($_POST['note'])  ? $_POST['note']  : ''));
    $sv = kres_service($s);

    if (!kres_csrf_ok()) { $err = '画面の有効期限が切れました。もう一度お試しください。'; }
    elseif (!$sv) { $err = 'メニューを選び直してください。'; }
    elseif (!kres_valid_date($d) || !preg_match('/^\d{2}:\d{2}$/', $t)) { $err = '日時を選び直してください。'; }
    elseif ($name === '' || mb_strlen($name) > 40) { $err = 'お名前を入力してください(40文字まで)。'; }
    elseif (!preg_match('/^[0-9+\-() ]{8,15}$/', $phone)) { $err = 'お電話番号を半角数字で入力してください。'; }
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'メールアドレスの形式が正しくありません。'; }
    elseif (mb_strlen($note) > 300) { $err = 'ご要望は300文字までにしてください。'; }
    else {
        $slots = kres_slots($d, $sv['minutes'], kres_load());
        if (!isset($slots[$t])) { $err = 'その時間は受付できません。別の時間をお選びください。'; }
    }

    if ($err === '') {
        $new = array(
            'id'      => strtoupper(kres_random_hex(4)),
            'token'   => kres_random_hex(16),
            'service' => $s,
            'date'    => $d,
            'time'    => $t,
            'minutes' => (int)$sv['minutes'],
            'name'    => $name,
            'phone'   => $phone,
            'email'   => $email,
            'note'    => $note,
            'status'  => 'confirmed',
            'created_at' => date('c'),
        );
        // ロックの中でもう一度空きを確認してから書く(ダブルブッキング防止の本丸)
        list($ok, $res) = kres_update(function (&$bookings) use ($new) {
            if (!kres_slot_free($new['date'], $new['time'], $new['minutes'], $bookings)) {
                return '申し訳ありません、その時間はたった今埋まりました。別の時間をお選びください。';
            }
            $bookings[] = $new;
            return $new;
        });
        if ($ok) {
            kres_mail_customer($new, kres_base_url() . '?cancel=' . $new['token']);
            kres_mail_shop($new, 'new');
            header('Location: ' . basename(__FILE__) . '?done=' . $new['id']);
            exit;
        }
        $err = $res;
    }
}

/* ================= 予約フォーム(段階表示) ================= */

function kres_base_url() {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($secure ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
        . strtok($_SERVER['REQUEST_URI'], '?');
}

$s = isset($_GET['s']) ? (string)$_GET['s'] : (isset($_POST['s']) ? (string)$_POST['s'] : '');
$d = isset($_GET['d']) ? (string)$_GET['d'] : (isset($_POST['d']) ? (string)$_POST['d'] : '');
$t = isset($_GET['t']) ? (string)$_GET['t'] : (isset($_POST['t']) ? (string)$_POST['t'] : '');
$sv = kres_service($s);
if (!$sv) { $s = ''; }
if ($s === '' || !kres_valid_date($d)) { $d = ''; }

kres_head('ご予約 | ' . KRES_SHOP_NAME);
if ($err !== '') { echo '<div class="err">' . kres_h($err) . '</div>'; }

$self = kres_h(basename(__FILE__));

/* --- ステップ1: メニュー --- */
echo '<h2>1. メニューを選ぶ</h2>';
foreach ($services as $key => $x) {
    $on = ($key === $s) ? ' on' : '';
    echo '<a class="svc' . $on . '" href="' . $self . '?s=' . kres_h($key) . '">'
        . '<span>約' . (int)$x['minutes'] . '分・' . number_format((int)$x['price']) . '円</span>'
        . '<b>' . kres_h($x['name']) . '</b></a>';
}

/* --- ステップ2: 日を選ぶ --- */
if ($sv) {
    echo '<h2>2. 日を選ぶ</h2><div class="days">';
    $bookings = kres_load();
    $w = array('日', '月', '火', '水', '木', '金', '土');
    for ($i = 0; $i <= KRES_DAYS_AHEAD; $i++) {
        $date = date('Y-m-d', strtotime('+' . $i . ' day'));
        $mark = kres_day_mark($date, $sv['minutes'], $bookings);
        $wd = (int)date('w', strtotime($date . ' 12:00:00'));
        $cls = 'day';
        if ($mark === '−' || $mark === '×') { $cls .= ' off'; }
        if ($date === $d) { $cls .= ' on'; }
        echo '<a class="' . $cls . '" href="' . $self . '?s=' . kres_h($s) . '&amp;d=' . $date . '">'
            . date('n/j', strtotime($date . ' 12:00:00')) . '<br>' . $w[$wd]
            . '<em>' . $mark . '</em></a>';
    }
    echo '</div><p class="muted">○=空きあり ／ △=残りわずか ／ ×=満枠 ／ −=休業日</p>';
}

/* --- ステップ3: 時間を選ぶ --- */
if ($sv && $d !== '') {
    echo '<h2>3. 時間を選ぶ</h2><div class="slots">';
    $slots = kres_slots($d, $sv['minutes'], kres_load());
    if (!$slots) { echo '</div><p class="muted">この日は受付できる時間がありません。</p>'; }
    else {
        foreach ($slots as $hm => $free) {
            $cls = 'slot' . ($free ? '' : ' x') . ($hm === $t ? ' on' : '');
            echo '<a class="' . $cls . '" href="' . $self . '?s=' . kres_h($s) . '&amp;d=' . kres_h($d)
                . '&amp;t=' . kres_h($hm) . '#form">' . kres_h($hm) . '</a>';
        }
        echo '</div>';
    }
}

/* --- ステップ4: お客様情報 --- */
if ($sv && $d !== '' && preg_match('/^\d{2}:\d{2}$/', $t)) {
    echo '<h2 id="form">4. お客様情報</h2>'
        . '<div class="crumbs"><b>' . kres_h($sv['name']) . '</b> ／ '
        . kres_h(date('n月j日', strtotime($d . ' 12:00:00'))) . ' <b>' . kres_h($t) . '</b> から約'
        . (int)$sv['minutes'] . '分</div>'
        . '<form method="post" class="card">' . kres_csrf_field()
        . '<input type="hidden" name="s" value="' . kres_h($s) . '">'
        . '<input type="hidden" name="d" value="' . kres_h($d) . '">'
        . '<input type="hidden" name="t" value="' . kres_h($t) . '">'
        . '<label>お名前 *</label><input name="name" required maxlength="40" value="'
        . kres_h(isset($_POST['name']) ? $_POST['name'] : '') . '">'
        . '<label>お電話番号 *</label><input name="phone" type="tel" required placeholder="09012345678" value="'
        . kres_h(isset($_POST['phone']) ? $_POST['phone'] : '') . '">'
        . '<label>メールアドレス（確認メールが必要な場合）</label><input name="email" type="email" value="'
        . kres_h(isset($_POST['email']) ? $_POST['email'] : '') . '">'
        . '<label>ご要望（任意）</label><textarea name="note" rows="3" maxlength="300">'
        . kres_h(isset($_POST['note']) ? $_POST['note'] : '') . '</textarea>'
        . '<p style="margin:16px 0 0"><button class="btn">この内容で予約する</button></p>'
        . '</form>';
}

kres_foot();
