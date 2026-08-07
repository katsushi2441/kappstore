<?php
/**
 * Kurage App Store — ダウンロード配布。
 *
 * 配布ファイルは kapp_data/files/ に置き、.htaccess で直接アクセスを塞ぐ。
 * ここを通さないと落とせないようにして、購入判定を1箇所に集約する
 * （URLを知っていれば誰でも落とせる状態を作らない）。
 */
require_once __DIR__ . '/kapp_boot.php';

$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
kapp_handle_auth_links('download.php?id=' . rawurlencode($id));

function kapp_download_deny($code, $message, $link_label, $link_href) {
    global $logged_in, $user, $is_seller, $is_admin;
    http_response_code($code);
    kapp_head('ダウンロードできません | Kurage App Store', 'ダウンロードできません。',
        'https://kappstore.exbridge.jp/download.php', true);
    kapp_header('ダウンロード', $logged_in, $user, $is_seller, $is_admin);
    echo '<main class="wrap narrow"><p class="empty-note">' . kapp_h($message) . '<br>'
       . '<a href="' . kapp_h($link_href) . '">' . kapp_h($link_label) . '</a></p></main>';
    kapp_footer();
    exit;
}

$app = kapp_find_app($id);
if (!$app) { kapp_download_deny(404, 'アプリが見つかりません。', 'アプリ一覧へ', 'index.php'); }

if (!$logged_in) {
    kapp_download_deny(401, 'ダウンロードには 𝕏 でのログインが必要です。',
        '𝕏 でログイン', '?login=1&id=' . rawurlencode($id));
}

$p = kapp_price_parts($app['price']);
$is_seller_of_app = (kapp_norm_user($app['seller']) === $user);

// 落とせるのは「購入済み」「無料」「出品者本人」「管理者」だけ。
$allowed = kapp_has_paid($user, $app['id']) || $p['total'] === 0 || $is_seller_of_app || $is_admin;
if (!$allowed) {
    kapp_download_deny(403, 'このアプリはまだご購入いただいていません。',
        '購入手続きへ', 'order.php?app=' . rawurlencode($app['id']));
}

if (empty($app['file'])) {
    kapp_download_deny(404, '配布ファイルがまだ登録されていません。',
        'アプリの説明へ', 'app.php?id=' . rawurlencode($app['id']));
}

// ファイル名は台帳の値をそのまま結合せず、basename で外へ出られないようにする。
$path = KAPP_FILES . '/' . basename((string)$app['file']);
if (!is_file($path)) {
    kapp_download_deny(404, '配布ファイルが見つかりません。お手数ですが開発元へお問い合わせください。',
        'アプリの説明へ', 'app.php?id=' . rawurlencode($app['id']));
}

$name = !empty($app['filename']) ? basename((string)$app['filename']) : basename($path);
// ヘッダーインジェクションを防ぐため、改行と " を落とす
$name = str_replace(array("\r", "\n", '"'), '', $name);

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
readfile($path);
exit;
