<?php
// 支払明細書PDFの動作確認。外部ライブラリもネットワークも使わない。
//
// この書類は出品手数料に係る適格請求書を兼ねる。記載事項が1つでも欠けると
// 出品者が仕入税額控除を受けられないので、6項目の有無を機械的に確かめる。
// あわせて、明細の行数を切り詰めても合計がズレないことを見る。
//
// 実行: php scripts/check_statement.php

$tmp = sys_get_temp_dir() . '/kappstore-statement-' . getmypid();
@mkdir($tmp, 0700, true);
define('KAPP_DATA_DIR', $tmp);
define('KAPP_ADMIN', 'xb_bittensor');
define('KAPP_ADMIN_EMAIL', 'sysadmin@example.test');
define('KAPP_MAIL_FROM', 'noreply@example.test');

require_once __DIR__ . '/../public/kapp_lib.php';
require_once __DIR__ . '/../public/kapp_statement.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}

/**
 * PDFの中に文字列が描かれているか。
 *
 * 日本語は CIDフォントで UTF-16BE のhexとして、ASCIIは Helvetica のリテラルとして
 * 描かれる。混在した文字列は描画単位が分かれるので、一続きの並びにはならない
 * （'登録番号 T418…' は <767B…> と ( T418…) の2つになる）。
 * そこで同じ分け方で切ってから、それぞれが出てくるかを見る。
 * 並び順までは見ないぶん判定はゆるいが、記載漏れは確実に捕まえられる。
 */
function pdf_has($pdf, $text) {
    foreach (kapp_pdf_runs($text) as $run) {
        list($is_ascii, $chunk) = $run;
        if (trim($chunk) === '') { continue; }
        $needle = $is_ascii
            ? kapp_pdf_escape($chunk)
            : strtoupper(bin2hex(mb_convert_encoding($chunk, 'UTF-16BE', 'UTF-8')));
        if (strpos($pdf, $needle) === false) { return false; }
    }
    return true;
}

/* ---- 元になる売上を作る ---- */
$app = array(
    'id' => 'app0001', 'seller' => 'demo_maker', 'name' => '在庫管理プロトタイプ',
    'summary' => 'テスト', 'body' => '', 'demo_url' => '', 'price' => 100000,
    'file' => 'x.zip', 'filename' => 'x.zip', 'filesize' => 100, 'image' => '',
    'status' => 'published', 'created_at' => time(),
);
kapp_save_app($app);
kapp_register_seller('demo_maker', 'デモ製作所', 'https://example.test/',
    'maker@example.test', 'T1234567890123', '三井住友銀行 上前津支店 普通 1234567 デモセイサクジヨ');
$seller = kapp_find_seller('demo_maker');

check('登録番号を保存できる', $seller['invoice_no'], 'T1234567890123');
check('振込先を保存できる', strpos($seller['bank'], '普通 1234567') !== false, true);

/* ---- 登録番号の整え ---- */
check('全角で入れても直る',   kapp_norm_invoice_no('Ｔ１２３４５６７８９０１２３'), 'T1234567890123');
check('ハイフンを落とす',     kapp_norm_invoice_no('T-1234-5678-90123'), 'T1234567890123');
check('Tの付け忘れを補う',    kapp_norm_invoice_no('1234567890123'), 'T1234567890123');
check('空はそのまま空',       kapp_norm_invoice_no('  '), '');
check('桁数違いは弾く',
    kapp_register_seller('bad_user', 'だめ', '', 'b@example.test', 'T123')[0], false);
check('登録番号なしでも登録できる',
    kapp_register_seller('no_inv', '登録なし', '', 'n@example.test', '', '')[0], true);

/* ---- 支払いを1件つくる ---- */
$ids = array();
foreach (array('株式会社アリス', '株式会社ボブ') as $buyer) {
    $r = kapp_create_order(strtolower(substr(md5($buyer), 0, 6)), $app, $buyer, '', 'bank', 'x@example.test');
    kapp_admin_mark_paid($r[1], '');
    $ids[] = $r[1];
}
$res = kapp_record_payout('demo_maker', $ids, '2026-08-31 振込');
check('支払いを記録できる', $res[0], true);
$payout = $res[1];

/* ---- 明細の中身 ---- */
$d = kapp_statement_data($payout);
check('明細は2行',            count($d['rows']), 2);
check('売上合計(税抜)',       $d['sale'], 200000);
check('売上合計の消費税',     $d['sale_tax'], 20000);
check('手数料合計(税抜)',     $d['fee'], 100000);      // (10万×10%+4万)×2
check('手数料の消費税',       $d['fee_tax'], 10000);
check('お支払額(税込)',       $d['net_total'], 110000);
// 明細の合計が、支払い実績に控えた金額と一致すること
check('支払い実績と一致',     $d['net_total'], (int)$payout['total']);
// 売上 － 手数料 ＝ お支払額（1円もどこかへ消えない）
check('税抜の内訳が合う',     $d['sale'] - $d['fee'], $d['net']);
check('税込の内訳が合う',     $d['sale_total'] - $d['fee_total'], $d['net_total']);

/* ---- PDF ---- */
$pdf = kapp_statement_pdf($payout, $seller);
check('PDFで始まる',   substr($pdf, 0, 5), '%PDF-');
check('EOFで終わる',   substr($pdf, -5), '%%EOF');
check('1KB以上ある',   strlen($pdf) > 1000, true);

// --- 適格請求書の記載事項6項目 ---
check('① 発行者の名称',       pdf_has($pdf, '株式会社エクスブリッジ'), true);
check('① 発行者の登録番号',   pdf_has($pdf, 'T4180001056508'), true);
check('② 取引年月日',         pdf_has($pdf, '取引年月日'), true);
check('③ 取引内容',           pdf_has($pdf, '出品手数料'), true);
check('④ 適用税率',           pdf_has($pdf, '10%対象'), true);
check('⑤ 税率ごとの消費税額', pdf_has($pdf, '消費税額'), true);
check('⑥ 交付を受ける者の名称', pdf_has($pdf, 'デモ製作所'), true);

// --- 書類としての体裁 ---
check('表題',                 pdf_has($pdf, '支払明細書'), true);
check('適格請求書を兼ねる旨', pdf_has($pdf, '（兼 適格請求書）'), true);
check('相手方の登録番号',     pdf_has($pdf, 'T1234567890123'), true);
check('商品名が載る',         pdf_has($pdf, '在庫管理プロトタイプ'), true);
check('備考が載る',           pdf_has($pdf, '振込'), true);
check('明細番号は支払いIDで決まる',
    kapp_statement_no($payout), 'S' . date('Ymd', (int)$payout['paid_at']) . '-' . strtoupper(substr($payout['id'], 0, 6)));
check('再発行しても同じ番号', kapp_statement_no($payout), kapp_statement_no($payout));

// 購入者名は載せない（支払明細書に取引先の顧客名を書く必要がない）
check('購入者名は載せない', pdf_has($pdf, '株式会社アリス'), false);

// 登録番号・振込先が無い出品者でも壊れない
$bare = array('x' => 'no_inv', 'name' => '登録なし');
$pdf2 = kapp_statement_pdf($payout, $bare);
check('登録番号なしでもPDFになる', substr($pdf2, 0, 5), '%PDF-');
check('登録番号なしでも発行者の番号は載る', pdf_has($pdf2, 'T4180001056508'), true);

/* ---- 行数を切り詰めても合計がズレないこと ---- */
$many = array();
for ($i = 0; $i < KAPP_STATEMENT_ROWS + 5; $i++) {
    $r = kapp_create_order('buyer' . $i, $app, '購入者' . $i, '', 'bank', 'x@example.test');
    kapp_admin_mark_paid($r[1], '');
    $many[] = $r[1];
}
$res2 = kapp_record_payout('demo_maker', $many, '');
check('大量の売上も記録できる', $res2[0], true);
$d2 = kapp_statement_data($res2[1]);
$n  = KAPP_STATEMENT_ROWS + 5;
check('明細は全件ぶん',   count($d2['rows']), $n);
check('合計も全件ぶん',   $d2['sale'], 100000 * $n);
check('支払額も全件ぶん', $d2['net_total'], (int)$res2[1]['total']);

$pdf3 = kapp_statement_pdf($res2[1], $seller);
check('あふれ分をまとめる', pdf_has($pdf3, 'ほか '), true);
// 表示を切り詰めても、お支払額は全件ぶんのまま
check('切り詰めても支払額は全件ぶん',
    pdf_has($pdf3, number_format($d2['net_total']) . '-'), true);

/* 後片付け */
foreach (glob($tmp . '/*.json') as $f) { @unlink($f); }
@rmdir($tmp . '/files'); @rmdir($tmp);

echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
