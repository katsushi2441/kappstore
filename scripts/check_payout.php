<?php
// 精算（出品者への支払い）の動作確認。決済APIもネットワークも使わない。
//
// お金を扱うので、ここが破れると実害が出る。とくに
//   ・端数（合計が1円ずれる）
//   ・二重払い（同じ売上を2回払う）
//   ・他人の売上を混ぜる
// の3つを重点的に見る。
//
// 実行: php scripts/check_payout.php

$tmp = sys_get_temp_dir() . '/kappstore-payout-' . getmypid();
@mkdir($tmp, 0700, true);
define('KAPP_DATA_DIR', $tmp);
define('KAPP_ADMIN', 'xb_bittensor');
define('KAPP_ADMIN_EMAIL', 'sysadmin@example.test');
define('KAPP_MAIL_FROM', 'noreply@example.test');

require_once __DIR__ . '/../public/kapp_lib.php';
require_once __DIR__ . '/../public/kapp_payout.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}

/* ============================================================
 * 1. 手数料の計算
 *   手数料(税別) = 販売価格(税抜) × 10% ＋ 40,000
 * ========================================================== */
$order = array('amount' => 100000, 'tax' => 10000, 'total' => 110000);
$p = kapp_payout_parts($order);
check('手数料(税別)',      $p['fee'],       50000);   // 10万×10% + 4万
check('手数料の消費税',    $p['fee_tax'],   5000);
check('手数料(税込)',      $p['fee_total'], 55000);
check('出品者の取り分(税抜)', $p['net'],       50000);
check('出品者の消費税',    $p['net_tax'],   5000);
check('出品者へ振り込む額', $p['net_total'], 55000);

// 手数料＋取り分が、販売価格と一致すること（1円もどこかへ消えない）
check('税抜の内訳が合う', $p['fee'] + $p['net'], $p['sale_amount']);

// 20万円の商品
$p2 = kapp_payout_parts(array('amount' => 200000, 'tax' => 20000, 'total' => 220000));
check('20万: 手数料',       $p2['fee'],       60000);   // 20万×10% + 4万
check('20万: 取り分(税込)', $p2['net_total'], 154000);
check('20万: 内訳が合う',   $p2['fee'] + $p2['net'], 200000);

// 端数が出る価格
$p3 = kapp_payout_parts(array('amount' => 123456, 'tax' => 12345, 'total' => 135801));
check('端数: 手数料',     $p3['fee'], 52345);           // floor(12345.6) + 40000
check('端数: 内訳が合う', $p3['fee'] + $p3['net'], 123456);

// 最低価格(10万円)を割った出品への保険。取り分をマイナスにしない
$p4 = kapp_payout_parts(array('amount' => 30000, 'tax' => 3000, 'total' => 33000));
check('低額でも手数料は売価を超えない', $p4['fee'] <= 30000, true);
check('低額でも取り分は0以上',          $p4['net'] >= 0, true);

/* ============================================================
 * 2. 集計（未入金は精算しない）
 * ========================================================== */
$app = array(
    'id' => 'app0001', 'seller' => 'someone', 'name' => 'テスト商品',
    'summary' => 'テスト', 'body' => '', 'demo_url' => '', 'price' => 100000,
    'file' => 'x.zip', 'filename' => 'x.zip', 'filesize' => 100, 'image' => '',
    'status' => 'published', 'created_at' => time(),
);
kapp_save_app($app);

check('最初は精算対象なし', count(kapp_payout_summary()), 0);

$r1 = kapp_create_order('alice', $app, '株式会社アリス', '', 'bank', 'a@example.test');
$oid1 = $r1[1];
check('未入金は精算に入らない', count(kapp_payout_summary()), 0);

kapp_admin_mark_paid($oid1, '入金確認');
$sum = kapp_payout_summary();
check('入金済みで精算対象になる', count($sum), 1);
check('出品者で引ける', isset($sum['someone']), true);
check('売上(税込)', $sum['someone']['sale_total'],  110000);
check('手数料(税込)', $sum['someone']['fee_total'], 55000);
check('未払残',      $sum['someone']['unpaid_total'], 55000);
check('支払済',      $sum['someone']['paid_total'], 0);

// 2件目
$r2 = kapp_create_order('bob', $app, '株式会社ボブ', '', 'bank', 'b@example.test');
$oid2 = $r2[1];
kapp_admin_mark_paid($oid2, '');
$sum = kapp_payout_summary();
check('2件で件数2', $sum['someone']['count'], 2);
check('2件で未払残が倍', $sum['someone']['unpaid_total'], 110000);

/* ============================================================
 * 3. 他人の売上を混ぜない
 * ========================================================== */
$app2 = $app;
$app2['id'] = 'app0002'; $app2['seller'] = 'another'; $app2['name'] = '別の人の商品';
kapp_save_app($app2);
$r3 = kapp_create_order('carol', $app2, '株式会社キャロル', '', 'bank', 'c@example.test');
kapp_admin_mark_paid($r3[1], '');

$sum = kapp_payout_summary();
check('出品者が2人になる', count($sum), 2);
check('someoneの件数は増えない', $sum['someone']['count'], 2);
check('anotherは1件',           $sum['another']['count'], 1);
check('未払いの明細も混ざらない', count(kapp_unpaid_orders('someone')), 2);
check('anotherの未払いは1件',     count(kapp_unpaid_orders('another')), 1);

/* ============================================================
 * 4. 支払いの記録（ここが一番きわどい）
 * ========================================================== */
$res = kapp_record_payout('someone', array($oid1, $oid2), '2026-08-31 振込');
check('支払いを記録できる', $res[0], true);
check('振り込む額', $res[1]['total'], 110000);
check('対象の注文を控える', count($res[1]['order_ids']), 2);

$sum = kapp_payout_summary();
check('支払済に反映', $sum['someone']['paid_total'], 110000);
check('未払残が0になる', $sum['someone']['unpaid_total'], 0);
check('未払い明細も空', count(kapp_unpaid_orders('someone')), 0);

// 二重払いの防止
check('同じ注文は2度払えない', kapp_record_payout('someone', array($oid1, $oid2), '')[0], false);
check('二重払い後も支払済は変わらない', kapp_payout_summary()['someone']['paid_total'], 110000);

// 他人の注文を指定しても払えない
check('他人の売上は払えない', kapp_record_payout('another', array($oid1), '')[0], false);

// 未入金の注文は払えない
$r4 = kapp_create_order('dave', $app, '株式会社デイブ', '', 'bank', 'd@example.test');
check('未入金は払えない', kapp_record_payout('someone', array($r4[1]), '')[0], false);

// 存在しない注文
check('存在しない注文は払えない', kapp_record_payout('someone', array('deadbeef'), '')[0], false);
check('出品者が空なら払えない',   kapp_record_payout('', array($oid1), '')[0], false);
check('対象が空なら払えない',     kapp_record_payout('someone', array(), '')[0], false);

/* ============================================================
 * 5. 支払い後に新しい売上が立ったとき
 * ========================================================== */
kapp_admin_mark_paid($r4[1], '');
$sum = kapp_payout_summary();
check('新しい売上が未払残になる', $sum['someone']['unpaid_total'], 55000);
check('支払済はそのまま',         $sum['someone']['paid_total'], 110000);
check('件数は3件',                $sum['someone']['count'], 3);
// 残高を別に持たず注文台帳から計算しているので、ここが必ず一致する
check('未払残＝取り分合計－支払済',
    $sum['someone']['unpaid_total'], $sum['someone']['net_total'] - $sum['someone']['paid_total']);

/* ============================================================
 * 6. 自社出品は精算しない
 *
 * 店の運営者自身の出品が精算対象に混ざると、画面に「自分への未払残」が
 * 出て、誤って振り込む事故につながる。
 * ========================================================== */
$own = $app;
$own['id'] = 'app0003'; $own['seller'] = KAPP_ADMIN; $own['name'] = '自社商品';
kapp_save_app($own);
$r5 = kapp_create_order('erin', $own, '株式会社エリン', '', 'bank', 'e@example.test');
kapp_admin_mark_paid($r5[1], '');

check('自社出品は判定できる', kapp_is_own_listing(KAPP_ADMIN), true);
check('大文字でも自社と判定', kapp_is_own_listing('XB_BitTensor'), true);
check('他人は自社ではない',   kapp_is_own_listing('someone'), false);
check('自社は精算一覧に出ない', isset(kapp_payout_summary()[kapp_norm_user(KAPP_ADMIN)]), false);
check('自社には支払えない',     kapp_record_payout(KAPP_ADMIN, array($r5[1]), '')[0], false);
// 他の出品者の集計は影響を受けない
check('他の出品者は変わらず', kapp_payout_summary()['someone']['count'], 3);

/* ============================================================
 * 7. 支払い実績の履歴
 * ========================================================== */
check('履歴を引ける', count(kapp_seller_payouts('someone')), 1);
check('他人の履歴は混ざらない', count(kapp_seller_payouts('another')), 0);
check('大文字小文字を同一視', count(kapp_seller_payouts('SomeOne')), 1);
check('@付きでも引ける',      count(kapp_seller_payouts('@someone')), 1);
check('1人ぶんの集計を引ける', kapp_seller_summary('someone')['count'], 3);
check('いない出品者はnull',    kapp_seller_summary('nobody'), null);

/* 後片付け */
foreach (glob($tmp . '/*.json') as $f) { @unlink($f); }
@rmdir($tmp . '/files'); @rmdir($tmp);

echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
