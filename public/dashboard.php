<?php
/**
 * ダッシュボードの入口。
 *
 * それ自体は画面を持たず、役割ごとの入口へ送るだけ。中身のある画面を
 * もう1枚作ると、注文一覧を2箇所で組み立てることになり、片方だけ直した
 * ときに数字が食い違う。送り先で2段目（kapp_subnav）を出すので、
 * 押した人から見れば「ダッシュボードを開いたら役割のメニューが出た」になる。
 */
require_once __DIR__ . '/kapp_boot.php';
kapp_handle_auth_links('dashboard.php');

if (!$logged_in) {
    kapp_head('ダッシュボード | Kurage App Store', 'ダッシュボード。',
        'https://kappstore.exbridge.jp/dashboard.php', true);
    kapp_header('ダッシュボード', $logged_in, $user, $is_seller, $is_admin);
    echo '<main class="wrap narrow"><section><h1>ダッシュボード</h1>'
       . '<p class="lead">ご購入・ご出品の状況をご覧いただくには、𝕏 でログインしてください。</p>'
       . '<p><a class="btn" href="?login=1">𝕏 でログイン</a></p></section></main>';
    kapp_footer();
    exit;
}

// 管理者はまず入金確認、出品者は自分の販売、それ以外は買ったもの
if ($is_admin)                              { $to = 'admin.php'; }
elseif ($is_seller)                         { $to = 'sales.php'; }
elseif (kapp_seller_can_complete($user))    { $to = 'sellers.php'; }
else                                        { $to = 'orders.php'; }

header('Location: ' . $to, true, 302);
exit;
