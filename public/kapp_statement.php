<?php
/**
 * 支払明細書PDFの生成。
 *
 * 出品者へ売上をお振り込みしたときに発行する。PDFの組み立て部品は
 * kapp_invoice.php のものをそのまま使う（フォントや座標の癖が同じなので、
 * 片方を直したらもう片方も確認すること）。
 *
 * 【この書類が「兼 適格請求書」である理由】
 * 出品手数料は当社が出品者に対して提供する役務の対価、つまり当社の課税売上。
 * 出品者が手数料ぶんの仕入税額控除を受けるには、当社が発行した適格請求書が要る。
 * 手数料は売上から差し引いて精算するので請求書を別に送る場面が無く、
 * この支払明細書に必要事項を載せて兼ねさせるのが実務上の定石。
 *
 * したがって当社の登録番号・税率ごとの対価の額・税率ごとの消費税額を必ず印字する。
 * 出品者の登録番号は控えとして載せるだけで、無くても支払いには影響しない。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kapp_invoice.php';
require_once __DIR__ . '/kapp_payout.php';

// 1ページに収める明細の行数。超えたぶんは「ほか N 件」にまとめる。
// 行数で切っても合計は必ず全件ぶんになるようにする（合計が合わないと問い合わせになる）。
if (!defined('KAPP_STATEMENT_ROWS')) { define('KAPP_STATEMENT_ROWS', 8); }

/** 支払明細書の番号。支払い実績のIDから決まるので、再発行しても同じ番号になる。 */
function kapp_statement_no($payout) {
    return 'S' . date('Ymd', (int)$payout['paid_at']) . '-'
         . strtoupper(substr((string)$payout['id'], 0, 6));
}

/**
 * 支払い実績から、明細に出す中身を組み立てる。
 *
 * 金額は payout に控えてある数字をそのまま使わず、注文台帳から引き直す。
 * 台帳が正で、payout は「いつ・いくら払ったか」の記録という位置づけのため。
 * ただし合計は payout の値と一致するはずなので、ズレたら台帳側を出す。
 */
function kapp_statement_data($payout) {
    $rows = array();
    $sale = 0; $sale_tax = 0; $fee = 0; $fee_tax = 0; $net = 0; $net_tax = 0;

    foreach ($payout['order_ids'] as $oid) {
        $order = kapp_find_order_any((string)$oid);
        if (!$order) { continue; }
        $p = kapp_payout_parts($order);
        $rows[] = array(
            'date'  => (int)$order['paid_at'],
            'name'  => (string)$order['app_name'],
            'sale'  => $p['sale_amount'], 'sale_tax' => $p['sale_tax'],
            'total' => $p['sale_total'],
        );
        $sale += $p['sale_amount']; $sale_tax += $p['sale_tax'];
        $fee  += $p['fee'];         $fee_tax  += $p['fee_tax'];
        $net  += $p['net'];         $net_tax  += $p['net_tax'];
    }
    return array(
        'rows' => $rows,
        'sale' => $sale, 'sale_tax' => $sale_tax, 'sale_total' => $sale + $sale_tax,
        'fee'  => $fee,  'fee_tax'  => $fee_tax,  'fee_total'  => $fee + $fee_tax,
        'net'  => $net,  'net_tax'  => $net_tax,  'net_total'  => $net + $net_tax,
    );
}

/**
 * 支払明細書PDFのバイト列を返す。
 *
 * @param array $payout 支払い実績（kapp_record_payout が作ったもの）
 * @param array $seller 出品者（name / x / invoice_no / bank）
 */
function kapp_statement_pdf($payout, $seller) {
    $issuer = kapp_issuer();
    $d      = kapp_statement_data($payout);

    $to      = isset($seller['name']) && $seller['name'] !== ''
             ? (string)$seller['name'] : '@' . $payout['seller'];
    $handle  = isset($seller['x']) ? (string)$seller['x'] : (string)$payout['seller'];
    $to_inv  = isset($seller['invoice_no']) ? (string)$seller['invoice_no'] : '';
    $bank    = isset($seller['bank']) ? (string)$seller['bank'] : '';
    $paid_at = (int)$payout['paid_at'];

    // 対象期間は明細の販売日から出す。手で決めると実態とズレる。
    $dates = array();
    foreach ($d['rows'] as $r) { $dates[] = $r['date']; }
    $period = $dates
        ? (date('Y年n月j日', min($dates)) . ' 〜 ' . date('Y年n月j日', max($dates)))
        : '—';

    // A4 = 595.28 x 841.89pt。左右マージン 57pt。
    $L = 57; $R = 538; $c = '';

    $c .= kapp_pdf_text($L, 780, 20, '支払明細書');
    $c .= kapp_pdf_text($L + 108, 782, 8.5, '（兼 適格請求書）');
    $c .= kapp_pdf_line($L, 772, $L + 102, 772, 1.2);

    $c .= kapp_pdf_text(400, 782, 9, '明細番号: ' . kapp_statement_no($payout));
    $c .= kapp_pdf_text(400, 768, 9, '発行日: ' . date('Y年n月j日', $paid_at));

    // 宛先
    $c .= kapp_pdf_text($L, 724, 14, $to . ' 御中');
    $c .= kapp_pdf_line($L, 716, 330, 716, 0.8);
    // 𝕏(U+1D54F)はBMP外でUniJIS-UCS2-Hに載らない。PDFでは使わない
    $c .= kapp_pdf_text($L, 701, 8.5, 'アカウント @' . $handle);
    if ($to_inv !== '') {
        $c .= kapp_pdf_text($L, 689, 8.5, '登録番号 ' . $to_inv);
    }

    // 発行元
    $c .= kapp_pdf_text(360, 724, 11, $issuer['name']);
    $c .= kapp_pdf_text(360, 711, 8,   '登録番号 ' . $issuer['invoice_no']);
    $c .= kapp_pdf_text(360, 700, 7.5, $issuer['zip'] . ' ' . $issuer['addr']);
    $c .= kapp_pdf_text(360, 690, 7.5, $issuer['mail']);

    // お支払額
    $c .= kapp_pdf_rect_fill($L, 626, 300, 34);
    $c .= kapp_pdf_text($L + 10, 638, 12, 'お支払額（税込）');
    $c .= kapp_pdf_text($L + 160, 636, 16, '￥' . number_format($d['net_total']) . '-');
    $c .= kapp_pdf_text($L, 610, 8.5, '対象期間: ' . $period
        . '　／　お売り上げ ' . count($d['rows']) . ' 件');

    /* ---- お売り上げの明細 ----
     * 明細の枠は KAPP_STATEMENT_ROWS 行ぶんを常に確保し、以降の欄は固定位置に置く。
     * 行数に合わせて下を詰めると、売れた件数によって書類の見た目が変わってしまう。 */
    $ty = 566;
    $c .= kapp_pdf_text($L, $ty + 30, 10, 'お売り上げの明細');
    $c .= kapp_pdf_rect_fill($L, $ty, $R - $L, 22, 0.90);
    $c .= kapp_pdf_text($L + 8, $ty + 7, 8.5, '販売日');
    $c .= kapp_pdf_text($L + 62, $ty + 7, 8.5, '商品名');
    $c .= kapp_pdf_text_right($L + 340, $ty + 7, 8.5, '販売価格(税抜)');
    $c .= kapp_pdf_text_right($L + 400, $ty + 7, 8.5, '消費税');
    $c .= kapp_pdf_text_right($L + 473, $ty + 7, 8.5, '販売価格(税込)');

    $shown = $d['rows'];
    $extra = null;
    if (count($shown) > KAPP_STATEMENT_ROWS) {
        // あふれたぶんを1行にまとめる。合計は全件ぶんのまま変えない。
        $rest  = array_slice($shown, KAPP_STATEMENT_ROWS - 1);
        $shown = array_slice($shown, 0, KAPP_STATEMENT_ROWS - 1);
        $extra = array('n' => count($rest), 'sale' => 0, 'sale_tax' => 0, 'total' => 0);
        foreach ($rest as $r) {
            $extra['sale'] += $r['sale']; $extra['sale_tax'] += $r['sale_tax'];
            $extra['total'] += $r['total'];
        }
    }

    $y = $ty;
    foreach ($shown as $r) {
        $y -= 22;
        $c .= kapp_pdf_text($L + 8, $y + 7, 9, date('Y/n/j', $r['date']));
        $c .= kapp_pdf_text($L + 62, $y + 7, 9, mb_strimwidth($r['name'], 0, 44, '…', 'UTF-8'));
        $c .= kapp_pdf_text_right($L + 340, $y + 7, 9, number_format($r['sale']));
        $c .= kapp_pdf_text_right($L + 400, $y + 7, 9, number_format($r['sale_tax']));
        $c .= kapp_pdf_text_right($L + 473, $y + 7, 9, number_format($r['total']));
        $c .= kapp_pdf_line($L, $y, $R, $y, 0.4);
    }
    if ($extra) {
        $y -= 22;
        $c .= kapp_pdf_text($L + 62, $y + 7, 9, 'ほか ' . $extra['n'] . ' 件');
        $c .= kapp_pdf_text_right($L + 340, $y + 7, 9, number_format($extra['sale']));
        $c .= kapp_pdf_text_right($L + 400, $y + 7, 9, number_format($extra['sale_tax']));
        $c .= kapp_pdf_text_right($L + 473, $y + 7, 9, number_format($extra['total']));
        $c .= kapp_pdf_line($L, $y, $R, $y, 0.4);
    }
    if (!$shown) {
        $y -= 22;
        $c .= kapp_pdf_text($L + 8, $y + 7, 9, '（明細がありません）');
        $c .= kapp_pdf_line($L, $y, $R, $y, 0.4);
    }

    // 明細欄の下端。行が少なくても枠はここまで
    $bottom = $ty - 22 * KAPP_STATEMENT_ROWS;
    $c .= kapp_pdf_line($L, $bottom, $R, $bottom, 0.5);

    /* ---- お支払いの計算 ---- */
    $sy = $bottom - 28;
    $calc = array(
        array('お売り上げ合計（税抜）', $d['sale'], false),
        array('消費税（10%）', $d['sale_tax'], false),
        array('出品手数料（税抜）', -$d['fee'], false),
        array('消費税（10%）', -$d['fee_tax'], false),
        array('お支払額（税込）', $d['net_total'], true),
    );
    foreach ($calc as $i => $row) {
        $ry = $sy - ($i * 17);
        $size = $row[2] ? 10.5 : 9.5;
        $c .= kapp_pdf_text($L + 300, $ry, $size, $row[0]);
        $c .= kapp_pdf_text_right($R, $ry, $size,
            ($row[1] < 0 ? '-￥' . number_format(-$row[1]) : '￥' . number_format($row[1])));
        if ($i === 3) { $c .= kapp_pdf_line($L + 295, $ry - 6, $R, $ry - 6, 0.6); }
    }

    /* ---- 出品手数料の内訳（ここが適格請求書の本体）---- */
    $fy = $sy - 108;
    $c .= kapp_pdf_line($L, $fy + 30, $R, $fy + 30, 0.5);
    $c .= kapp_pdf_text($L, $fy + 16, 10, '出品手数料の内訳');
    $c .= kapp_pdf_text($L, $fy + 3, 8,
        '取引内容: 出品手数料（Kurage App Store における出品・販売代行）　取引年月日: ' . $period);
    $c .= kapp_pdf_text($L, $fy - 16, 9,
        '10%対象　お取引金額（税抜）￥' . number_format($d['fee'])
        . '　　消費税額 ￥' . number_format($d['fee_tax'])
        . '　　合計（税込）￥' . number_format($d['fee_total']));
    // 1行に詰めると右マージンを越えるので2行に分ける
    $c .= kapp_pdf_text($L, $fy - 32, 8,
        '出品手数料は 販売価格（税抜）の ' . (int)(KAPP_FEE_RATE * 100) . '％ ＋ '
        . number_format(KAPP_FEE_FIXED) . '円（税別）です。');
    $c .= kapp_pdf_text($L, $fy - 44, 8,
        'デモサイトの構築、導入・設定マニュアルを同梱したパッケージング、出品登録の費用を含みます。');

    /* ---- お振込み ---- */
    $py = $fy - 74;
    $c .= kapp_pdf_line($L, $py + 16, $R, $py + 16, 0.5);
    $c .= kapp_pdf_text($L, $py, 9, 'お振込日: ' . date('Y年n月j日', $paid_at));
    if ($bank !== '') {
        $c .= kapp_pdf_text($L, $py - 15, 9, 'お振込先: ' . mb_strimwidth($bank, 0, 76, '…', 'UTF-8'));
    }
    if (!empty($payout['note'])) {
        $c .= kapp_pdf_text($L, $py - 30, 8.5, '備考: ' . mb_strimwidth($payout['note'], 0, 76, '…', 'UTF-8'));
    }

    // 脚注
    $c .= kapp_pdf_text($L, 108, 8,
        '・本書は出品手数料に係る適格請求書を兼ねています。消費税額は税率ごとに1回だけ端数処理しています。');
    $c .= kapp_pdf_text($L, 96, 8,
        '・記載内容にお心当たりのない点がございましたら、お手数ですが下記までご連絡ください。');
    $c .= kapp_pdf_text($L, 84, 8, 'https://kappstore.exbridge.jp/payout.php　' . $issuer['mail']);
    $c .= kapp_pdf_line($L, 70, $R, 70, 0.5);
    $c .= kapp_pdf_text($L, 56, 8,
        $issuer['name'] . '　登録番号 ' . $issuer['invoice_no'] . '　' . $issuer['zip'] . ' ' . $issuer['addr']);

    return kapp_pdf_document($c);
}
