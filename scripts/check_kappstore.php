<?php
// kappstore の台帳・認可・請求書PDFの動作確認。決済APIは呼ばない。
// 実行: php scripts/check_kappstore.php

$tmp = sys_get_temp_dir() . '/kappstore-check-' . getmypid();
@mkdir($tmp, 0700, true);
define('KAPP_DATA_DIR', $tmp);
define('KAPP_ADMIN', 'xb_bittensor');
define('KAPP_ADMIN_EMAIL', 'sysadmin@example.test');
define('KAPP_MAIL_FROM', 'noreply@example.test');

require_once __DIR__ . '/../public/kapp_lib.php';
require_once __DIR__ . '/../public/kapp_invoice.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}

/* ---- 販売店 ---- */
check('最初は販売店0件', count(kapp_sellers()), 0);

$r = kapp_register_seller('xb_bittensor', '株式会社エクスブリッジ', 'https://exbridge.jp/', 'info@exbridge.test');
check('管理者は登録できる', $r[0], true);
check('管理者は自動承認', kapp_is_approved_seller('xb_bittensor'), true);

$r = kapp_register_seller('someone', 'サムワン商店', '');
check('一般は登録できる', $r[0], true);
check('一般は審査待ち（出品不可）', kapp_is_approved_seller('someone'), false);

check('大文字小文字を同一視', kapp_is_approved_seller('XB_BitTensor'), true);
check('@付きでも同一視', kapp_is_approved_seller('@xb_bittensor'), true);

$r = kapp_register_seller('bad', 'ダメ商店', 'javascript:alert(1)');
check('不正なURLは弾く', $r[0], false);

kapp_approve_seller('someone', true);
check('承認すると出品できる', kapp_is_approved_seller('someone'), true);

/* ---- アプリ ---- */
$app = array(
    'id' => 'app0001', 'seller' => 'xb_bittensor', 'name' => '見積書作成システム',
    'summary' => '見積書をPDFで出すだけの小さなシステム', 'body' => 'PHP 7以上。DB不要。',
    'demo_url' => 'https://demo.example.com/', 'price' => 3000,
    'file' => 'stored.zip', 'filename' => 'mitsumori.zip', 'filesize' => 20480,
    'image' => '', 'status' => 'published', 'created_at' => time(),
);
kapp_save_app($app);

$free = $app;
$free['id'] = 'app0002'; $free['name'] = '無料サンプル'; $free['price'] = 0;
kapp_save_app($free);

$draft = $app;
$draft['id'] = 'app0003'; $draft['name'] = '下書き'; $draft['status'] = 'draft';
kapp_save_app($draft);

check('公開中は2件（下書きは出ない）', count(kapp_apps_published()), 2);
check('全件では3件', count(kapp_apps_all()), 3);
check('IDで引ける', kapp_find_app('app0001')['name'], '見積書作成システム');
check('販売者で引ける', count(kapp_seller_apps('xb_bittensor')), 3);
check('別の販売者では0件', count(kapp_seller_apps('someone')), 0);

// 同じIDで保存すると増えずに置き換わる（二重登録の防止）
$app['name'] = '見積書作成システム v2';
kapp_save_app($app);
check('同じIDは上書き（増えない）', count(kapp_apps_all()), 3);
check('上書きされている', kapp_find_app('app0001')['name'], '見積書作成システム v2');

/* ---- 価格 ---- */
$p = kapp_price_parts(3000);
check('税額10%', $p['tax'], 300);
check('税込', $p['total'], 3300);
check('0円は0円のまま', kapp_price_parts(0)['total'], 0);
// 端数は切り捨て（請求書と画面でずれないこと）
check('端数は切り捨て', kapp_price_parts(999)['tax'], 99);

/* ---- 注文と購入判定 ---- */
check('最初は注文0件', count(kapp_user_orders('alice')), 0);
check('未購入ならダウンロード不可', kapp_has_paid('alice', 'app0001'), false);

$r = kapp_create_order('alice', kapp_find_app('app0001'), '株式会社アリス', '山田', 'bank');
check('注文できる', $r[0], true);
$order_id = $r[1];
$order = kapp_find_order('alice', $order_id);
check('請求書番号の連番', substr($order['invoice_no'], -4), '0001');
check('税込3,300円', $order['total'], 3300);
check('初期は未入金', $order['status'], 'unpaid');
check('未入金ではまだ落とせない', kapp_has_paid('alice', 'app0001'), false);

kapp_mark_paid('alice', $order_id, 'PAYPAL-XYZ');
check('入金を記録できる', kapp_find_order('alice', $order_id)['status'], 'paid');
check('入金後は落とせる', kapp_has_paid('alice', 'app0001'), true);
check('別アプリは落とせない', kapp_has_paid('alice', 'app0003'), false);

// 無料アプリは注文と同時に購入済みになる
$r = kapp_create_order('alice', kapp_find_app('app0002'), '@alice', '', 'free');
check('無料は即購入済み', kapp_find_order('alice', $r[1])['status'], 'paid');
check('無料は落とせる', kapp_has_paid('alice', 'app0002'), true);

// 他人の購入で自分が落とせてはいけない（ここが破れると売上が消える）
check('bobはaliceの購入で落とせない', kapp_has_paid('bob', 'app0001'), false);
check('bobの注文は0件', count(kapp_user_orders('bob')), 0);
check('bobからaliceの注文は引けない', kapp_find_order('bob', $order_id), null);

// 他人になりすまして入金済みにできない
$r = kapp_mark_paid('bob', $order_id, 'FAKE');
check('他人の注文は入金にできない', $r[0], false);

$r = kapp_create_order('bob', kapp_find_app('app0001'), '株式会社ボブ', '', 'bank');
check('連番が進む', substr(kapp_find_order('bob', $r[1])['invoice_no'], -4), '0003');

/* ---- 請求書PDF ---- */
$pdf = kapp_invoice_pdf(kapp_find_order('alice', $order_id));
check('PDFのヘッダ', substr($pdf, 0, 8), '%PDF-1.4');
check('PDFの終端', substr($pdf, -5), '%%EOF');
check('中身がある', strlen($pdf) > 3000, true);
// ASCIIとCJKは別々に描画されるので、それぞれの断片で確認する
check('品目にアプリ名が入る', strpos($pdf, kapp_pdf_hex('見積書作成システム')) !== false, true);
check('銀行名が入っている', strpos($pdf, kapp_pdf_hex('三井住友銀行')) !== false, true);
check('口座番号が入っている', strpos($pdf, '7312531') !== false, true);
check('税込金額が入っている', strpos($pdf, '3,300') !== false, true);



/* ---- 通知の宛先（ここが空だと注文に気づけない）---- */
check('販売店のメールを保存している', kapp_find_seller('xb_bittensor')['email'], 'info@exbridge.test');
check('販売店の通知先を引ける', kapp_seller_email('xb_bittensor'), 'info@exbridge.test');
// メール未登録の販売店は、管理者へ落とす（通知が消えるのが一番まずい）
check('メール未登録なら管理者へ落ちる', kapp_seller_email('someone'), 'sysadmin@example.test');
check('存在しない販売店でも管理者へ落ちる', kapp_seller_email('nobody'), 'sysadmin@example.test');
check('不正な形式のメールは登録できない',
    kapp_register_seller('baddr', 'ダメ商店', '', 'not-an-email')[0], false);

/* ---- 銀行振込の一連（ここが壊れると入金しても永久に落とせない）---- */
$bank_app = kapp_find_app('app0001');
$r = kapp_create_order('carol', $bank_app, '株式会社キャロル', '', 'bank', 'Keiri@Carol.co.JP');
$cid = $r[1];
check('振込注文は未入金で始まる', kapp_find_order('carol', $cid)['status'], 'unpaid');
check('振込直後は落とせない', kapp_has_paid('carol', 'app0001'), false);
check('メールアドレスを保存している', kapp_find_order('carol', $cid)['email'], 'keiri@carol.co.jp');

// 管理者は、購入者本人でなくても入金を記録できる
$res = kapp_admin_mark_paid($cid, '2026-08-05 振込確認');
check('管理者が入金を記録できる', $res[0], true);
check('入金後は落とせる', kapp_has_paid('carol', 'app0001'), true);
check('手動である印が残る', kapp_find_order('carol', $cid)['paid_by'], 'admin');
check('メモが残る', kapp_find_order('carol', $cid)['paid_note'], '2026-08-05 振込確認');

// 二重に押しても壊れない
check('入金済みを再度押しても増えない', kapp_admin_mark_paid($cid, '')[0], false);

// 取り消し
check('取り消せる', kapp_admin_unmark_paid($cid)[0], true);
check('取り消すと落とせない', kapp_has_paid('carol', 'app0001'), false);

// PayPal決済済みは取り消させない（決済の記録と食い違うため）
$r2 = kapp_create_order('dave', $bank_app, '株式会社デイブ', '', 'paypal', 'dave@example.test');
kapp_mark_paid('dave', $r2[1], 'PAYPAL-ABC');
check('PayPal決済済みは取り消せない', kapp_admin_unmark_paid($r2[1])[0], false);
check('PayPal決済済みのままである', kapp_find_order('dave', $r2[1])['status'], 'paid');

// 全注文は管理者用。ここが本人限定だと入金確認ができない
check('全注文を引ける', count(kapp_all_orders()) >= 2, true);
check('IDだけで引ける（本人でなくても）', kapp_find_order_any($cid)['billing_name'], '株式会社キャロル');

// 宛先が無ければ送らない（誤送信しない）
check('宛先なしでは送信しない', kapp_send_paid_mail(array('email' => '')), false);
check('不正な宛先では送信しない', kapp_send_paid_mail(array('email' => 'not-an-email')), false);

/* 後片付け */
foreach (array('sellers.json', 'apps.json', 'orders.json') as $f) { @unlink($tmp . '/' . $f); }
@rmdir($tmp . '/files');
@rmdir($tmp);

echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
