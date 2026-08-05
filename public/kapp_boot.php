<?php
/**
 * 全画面の共通初期化。認証・台帳・ガワをまとめて読み込む。
 *
 * 認証は kurage.exbridge.jp と同じ auth_common.php をそのまま使う。
 * OAuth は aiknowledgecms.exbridge.jp が受け持ち、セッションCookieは
 * .exbridge.jp でサブドメイン共有されるので、この配下に X の鍵は要らない。
 */
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/kapp_lib.php';
require_once __DIR__ . '/kapp_ui.php';
if (file_exists(__DIR__ . '/kapp_config.php')) { require_once __DIR__ . '/kapp_config.php'; }

$auth      = url2ai_auth_bootstrap();
$logged_in = !empty($auth['logged_in']);
$user      = $logged_in ? kapp_norm_user($auth['session_user']) : '';
$is_admin  = $logged_in && kapp_is_admin($user);
$is_seller = $logged_in && kapp_is_approved_seller($user);

if (empty($_SESSION['kapp_csrf'])) {
    $_SESSION['kapp_csrf'] = kapp_random_hex(24);
}
$csrf = (string)$_SESSION['kapp_csrf'];

/** POSTのCSRFトークンを検証する。 */
function kapp_csrf_ok($csrf) {
    $sent = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    return $sent !== '' && hash_equals($csrf, $sent);
}
