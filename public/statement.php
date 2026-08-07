<?php
/**
 * 支払明細書PDFの配布。
 *
 * 取れるのは「その支払いを受けた出品者本人」と「管理者」だけ。
 * 明細番号は支払いIDから決まるので推測されうる前提で、必ずここで持ち主を照合する。
 * 金額と取引先が載る書類なので、IDを知っていれば取れる状態を作らない。
 */
require_once __DIR__ . '/kapp_boot.php';
require_once __DIR__ . '/kapp_statement.php';

$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
kapp_handle_auth_links('statement.php?id=' . rawurlencode($id));

function kapp_statement_deny($code, $message, $link_label, $link_href) {
    global $logged_in, $user, $is_seller, $is_admin;
    http_response_code($code);
    kapp_head('支払明細書を表示できません | Kurage App Store', '支払明細書を表示できません。',
        'https://kappstore.exbridge.jp/statement.php', true);
    kapp_header('支払明細書', $logged_in, $user, $is_seller, $is_admin);
    echo '<main class="wrap narrow"><p class="empty-note">' . kapp_h($message) . '<br>'
       . '<a href="' . kapp_h($link_href) . '">' . kapp_h($link_label) . '</a></p></main>';
    kapp_footer();
    exit;
}

if (!$logged_in) {
    kapp_statement_deny(401, '支払明細書のご確認には 𝕏 でのログインが必要です。',
        '𝕏 でログイン', '?login=1&id=' . rawurlencode($id));
}

$payout = null;
foreach (kapp_payouts() as $p) {
    if ((string)$p['id'] === $id) { $payout = $p; break; }
}
if (!$payout) {
    kapp_statement_deny(404, '支払明細書が見つかりません。', '精算へ', 'payout.php');
}

if (!$is_admin && kapp_norm_user($payout['seller']) !== $user) {
    kapp_statement_deny(403, 'この支払明細書はご覧いただけません。', '精算へ', 'payout.php');
}

$seller = kapp_find_seller($payout['seller']);
if (!$seller) { $seller = array('x' => $payout['seller'], 'name' => ''); }

$pdf  = kapp_statement_pdf($payout, $seller);
$name = kapp_statement_no($payout) . '.pdf';

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
echo $pdf;
exit;
