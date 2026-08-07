<?php
/**
 * 請求書PDFの生成。外部ライブラリを使わない。
 *
 * kurage_web/vibe_invoice.php から継承（関数名を kapp_ に変更、品目を
 * 購入アプリ名に差し替え）。PDFの組み立て方を直したときは、両方に反映すること。
 *
 * heteml（共有サーバー）にはPDF拡張もComposerも無い。TCPDF等を持ち込むと
 * 数十MBのvendorを抱えることになるため、必要な機能だけを直接書いている。
 *
 * 日本語は Adobe-Japan1 の CID フォント（KozMinPro-Regular-Acro）を
 * UniJIS-UCS2-H 符号化で参照する。フォントファイルは埋め込まず、閲覧側の
 * 代替フォントで表示される。これが共有サーバーで日本語PDFを出す一番軽い方法。
 *
 * PHP 5.x でも動く構文だけを使う。
 */

if (!defined('KAPP_INVOICE_INTERNAL')) { define('KAPP_INVOICE_INTERNAL', true); }

/** UTF-8 を UTF-16BE のhex文字列にする（UniJIS-UCS2-H が要求する形）。 */
function kapp_pdf_hex($text) {
    $utf16 = mb_convert_encoding((string)$text, 'UTF-16BE', 'UTF-8');
    return strtoupper(bin2hex($utf16));
}

/** Helvetica の字幅（AFM標準・1000分率）。ASCIIの送り幅を出すのに使う。 */
function kapp_pdf_helvetica_widths() {
    static $w = null;
    if ($w !== null) { return $w; }
    $widths = array(
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    );
    $w = array();
    foreach ($widths as $i => $width) { $w[32 + $i] = $width; }
    return $w;
}

/** 文字列を ASCII の並びと非ASCII（日本語）の並びに切り分ける。 */
function kapp_pdf_runs($text) {
    $runs = array();
    $len = mb_strlen($text, 'UTF-8');
    $buf = ''; $ascii = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        $is_ascii = (strlen($ch) === 1 && ord($ch) >= 32 && ord($ch) <= 126);
        if ($ascii === null) { $ascii = $is_ascii; }
        if ($is_ascii !== $ascii) {
            $runs[] = array($ascii, $buf);
            $buf = ''; $ascii = $is_ascii;
        }
        $buf .= $ch;
    }
    if ($buf !== '') { $runs[] = array($ascii, $buf); }
    return $runs;
}

/**
 * テキスト描画。$size はpt、$x/$y はページ左下原点。
 *
 * ASCIIは Helvetica(F2)、日本語は CIDフォント(F1) で描く。CIDフォントは
 * ASCIIのグリフを持たないため、全部F1で描くと英数字が豆腐になる（実測）。
 * 送り幅を自前で積んで、runをつなげて配置する。
 */
function kapp_pdf_text($x, $y, $size, $text) {
    $out = "BT\n";
    $cursor = $x;
    $widths = kapp_pdf_helvetica_widths();
    foreach (kapp_pdf_runs((string)$text) as $run) {
        list($is_ascii, $chunk) = $run;
        if ($chunk === '') { continue; }
        if ($is_ascii) {
            $out .= sprintf("/F2 %s Tf 1 0 0 1 %s %s Tm (%s) Tj\n",
                $size, round($cursor, 2), $y, kapp_pdf_escape($chunk));
            $advance = 0;
            for ($i = 0; $i < strlen($chunk); $i++) {
                $code = ord($chunk[$i]);
                $advance += isset($widths[$code]) ? $widths[$code] : 556;
            }
            $cursor += $advance * $size / 1000;
        } else {
            $out .= sprintf("/F1 %s Tf 1 0 0 1 %s %s Tm <%s> Tj\n",
                $size, round($cursor, 2), $y, kapp_pdf_hex($chunk));
            // 全角は1em。半角カナ等の例外はこの請求書では使わない。
            $cursor += mb_strlen($chunk, 'UTF-8') * $size;
        }
    }
    return $out . "ET\n";
}

/** Helvetica用。PDF文字列リテラルのエスケープ。 */
function kapp_pdf_escape($text) {
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $text);
}

/** 描いたときの送り幅(pt)。右揃えに使う。kapp_pdf_text と同じ計算にすること。 */
function kapp_pdf_width($size, $text) {
    $widths = kapp_pdf_helvetica_widths();
    $w = 0;
    foreach (kapp_pdf_runs((string)$text) as $run) {
        list($is_ascii, $chunk) = $run;
        if ($is_ascii) {
            for ($i = 0; $i < strlen($chunk); $i++) {
                $code = ord($chunk[$i]);
                $w += (isset($widths[$code]) ? $widths[$code] : 556) * $size / 1000;
            }
        } else {
            $w += mb_strlen($chunk, 'UTF-8') * $size;   // 全角は1em
        }
    }
    return $w;
}

/** 右揃え。$x が右端。金額欄は桁が揃っていないと読めない。 */
function kapp_pdf_text_right($x, $y, $size, $text) {
    return kapp_pdf_text($x - kapp_pdf_width($size, $text), $y, $size, $text);
}

function kapp_pdf_line($x1, $y1, $x2, $y2, $width = 0.5) {
    return sprintf("%s w %s %s m %s %s l S\n", $width, $x1, $y1, $x2, $y2);
}

function kapp_pdf_rect_fill($x, $y, $w, $h, $gray = 0.94) {
    return sprintf("%s g %s %s %s %s re f 0 g\n", $gray, $x, $y, $w, $h);
}

/**
 * 請求書PDFのバイト列を返す。
 *
 * @param array $order 注文（id, invoice_no, billing_name, contact, created_at, amount, tax, total, method）
 */
/**
 * 発行元。請求書と支払明細書で同じものを使う（食い違うと問い合わせになる）。
 *
 * invoice_no は適格請求書発行事業者の登録番号。これが無いと購入者は
 * 仕入税額控除を受けられないので、請求書・支払明細書の双方に必ず印字する。
 */
function kapp_issuer() {
    return array(
        'name'       => '株式会社エクスブリッジ',
        'invoice_no' => 'T4180001056508',
        // 建物名と電話・FAXは載せない。請求書・支払明細書に必要なのは
        // 発行者名・登録番号・連絡の取れる宛先で、建物名や電話番号は要件ではない。
        // 特定商取引法に基づく表記（tokusho.php）には法定で必要なので、そちらには残す。
        'zip'        => '〒467-0853',
        'addr'       => '愛知県名古屋市瑞穂区内浜町34-9 305',
        'mail'       => 'info@exdirect.net',
        'bank'       => '三井住友銀行 上前津支店　普通 7312531',
        'holder'     => 'カ）エクスブリッジ',
    );
}

function kapp_invoice_pdf($order) {
    $issuer = kapp_issuer();

    $no      = isset($order['invoice_no']) ? $order['invoice_no'] : '';
    $to      = isset($order['billing_name']) ? $order['billing_name'] : '';
    $contact = isset($order['contact']) ? $order['contact'] : '';
    $issued  = isset($order['created_at']) ? date('Y年n月j日', (int)$order['created_at']) : date('Y年n月j日');
    $due     = isset($order['created_at'])
        ? date('Y年n月j日', strtotime('+30 days', (int)$order['created_at']))
        : date('Y年n月j日', strtotime('+30 days'));
    $amount  = isset($order['amount']) ? (int)$order['amount'] : 0;
    $tax     = isset($order['tax']) ? (int)$order['tax'] : 0;
    $total   = isset($order['total']) ? (int)$order['total'] : ($amount + $tax);
    $method  = (isset($order['method']) && $order['method'] === 'paypal') ? 'PayPal' : '銀行振込';
    // 品目は購入したアプリ名。明細欄の幅(約300pt)に収まるところで切る。
    $item    = isset($order['app_name']) ? (string)$order['app_name'] : 'Kurage App Store 商品';
    $item    = mb_strimwidth($item, 0, 56, '…', 'UTF-8');
    $seller  = isset($order['seller']) ? (string)$order['seller'] : '';

    // A4 = 595.28 x 841.89pt。左右マージン 57pt。
    $L = 57; $R = 538; $c = '';

    $c .= kapp_pdf_text($L, 780, 22, '請求書');
    $c .= kapp_pdf_line($L, 772, $L + 62, 772, 1.2);

    $c .= kapp_pdf_text(400, 782, 9, '請求書番号: ' . $no);
    $c .= kapp_pdf_text(400, 768, 9, '発行日: ' . $issued);

    // 宛先
    $c .= kapp_pdf_text($L, 724, 14, $to . ' 御中');
    $c .= kapp_pdf_line($L, 716, 330, 716, 0.8);
    if ($contact !== '') {
        $c .= kapp_pdf_text($L, 700, 9, 'ご担当: ' . $contact . ' 様');
    }

    // 発行元。登録番号は適格請求書の必須記載事項（無いと購入者が仕入税額控除を受けられない）
    $c .= kapp_pdf_text(360, 724, 11, $issuer['name']);
    $c .= kapp_pdf_text(360, 711, 8,   '登録番号 ' . $issuer['invoice_no']);
    $c .= kapp_pdf_text(360, 700, 7.5, $issuer['zip'] . ' ' . $issuer['addr']);
    $c .= kapp_pdf_text(360, 690, 7.5, $issuer['mail']);

    // 合計
    $c .= kapp_pdf_rect_fill($L, 626, 300, 34);
    $c .= kapp_pdf_text($L + 10, 638, 12, 'ご請求金額（税込）');
    $c .= kapp_pdf_text($L + 175, 636, 16, '￥' . number_format($total) . '-');

    // 明細
    $ty = 588;
    $c .= kapp_pdf_rect_fill($L, $ty, $R - $L, 22, 0.90);
    $c .= kapp_pdf_text($L + 8,   $ty + 7, 9, '品目');
    $c .= kapp_pdf_text($L + 300, $ty + 7, 9, '数量');
    $c .= kapp_pdf_text($L + 350, $ty + 7, 9, '単価');
    $c .= kapp_pdf_text($L + 430, $ty + 7, 9, '金額');

    $ry = $ty - 26;
    $c .= kapp_pdf_text($L + 8,   $ry + 7, 9.5, $item);
    $c .= kapp_pdf_text($L + 305, $ry + 7, 9.5, '1');
    $c .= kapp_pdf_text($L + 350, $ry + 7, 9.5, number_format($amount));
    $c .= kapp_pdf_text($L + 430, $ry + 7, 9.5, number_format($amount));
    $c .= kapp_pdf_line($L, $ry, $R, $ry, 0.5);

    $c .= kapp_pdf_text($L + 8, $ry - 18, 8.5,
        'ソフトウェアのダウンロード販売（Kurage App Store）'
        . ($seller !== '' ? '　開発元: @' . $seller : ''));

    // 小計・消費税・合計
    $sy = $ry - 46;
    // 適用税率と税率ごとの消費税額を明示する（適格請求書の必須記載事項）
    $rows = array(
        array('10%対象 小計', number_format($amount)),
        array('消費税（10%）', number_format($tax)),
        array('合計', number_format($total)),
    );
    foreach ($rows as $i => $row) {
        $y = $sy - ($i * 20);
        $c .= kapp_pdf_text($L + 330, $y, 9.5, $row[0]);
        $c .= kapp_pdf_text($L + 430, $y, 9.5, '￥' . $row[1]);
        if ($i === 1) { $c .= kapp_pdf_line($L + 325, $y - 6, $R, $y - 6, 0.5); }
    }

    // お支払いについて
    $py = $sy - 84;
    $c .= kapp_pdf_line($L, $py + 14, $R, $py + 14, 0.5);
    $c .= kapp_pdf_text($L, $py, 10, 'お支払いについて');
    $c .= kapp_pdf_text($L, $py - 18, 9, 'お支払期限: ' . $due);
    $c .= kapp_pdf_text($L, $py - 34, 9, 'お支払方法: ' . $method);
    $c .= kapp_pdf_text($L, $py - 54, 9, '【お振込先】' . $issuer['bank']);
    $c .= kapp_pdf_text($L, $py - 70, 9, '　　　　　　口座名義: ' . $issuer['holder'] . '（' . $issuer['name'] . '）');
    $c .= kapp_pdf_text($L, $py - 86, 8, '・振込手数料はお客様のご負担でお願いいたします。');
    $c .= kapp_pdf_text($L, $py - 100, 8, '・PayPalでお支払いの場合、本請求書はお控えとしてご利用ください。');

    // 脚注
    $c .= kapp_pdf_text($L, 96, 8, 'ダウンロード商品のため、ご購入後の返品・返金はお受けしておりません。ライセンスは同梱の LICENSE によります。');
    $c .= kapp_pdf_text($L, 84, 8, 'https://kurage.exbridge.jp/terms.html');
    $c .= kapp_pdf_line($L, 70, $R, 70, 0.5);
    $c .= kapp_pdf_text($L, 56, 8, $issuer['name'] . '　' . $issuer['zip'] . ' ' . $issuer['addr']);

    return kapp_pdf_document($c);
}

/** オブジェクトを組み立ててPDFのバイト列にする。 */
function kapp_pdf_document($content) {
    $objects = array();
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] "
                . "/Resources << /Font << /F1 5 0 R /F2 8 0 R >> >> /Contents 4 0 R >>";
    $objects[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    // Adobe-Japan1 の CID フォント。フォントファイルは埋め込まない。
    $objects[5] = "<< /Type /Font /Subtype /Type0 /BaseFont /KozMinPro-Regular-Acro "
                . "/Encoding /UniJIS-UCS2-H /DescendantFonts [6 0 R] >>";
    $objects[6] = "<< /Type /Font /Subtype /CIDFontType0 /BaseFont /KozMinPro-Regular-Acro "
                . "/CIDSystemInfo << /Registry (Adobe) /Ordering (Japan1) /Supplement 2 >> "
                . "/FontDescriptor 7 0 R /DW 1000 /W [1 [250] 231 632 500] >>";
    $objects[7] = "<< /Type /FontDescriptor /FontName /KozMinPro-Regular-Acro /Flags 6 "
                . "/FontBBox [-437 -340 1147 1317] /ItalicAngle 0 /Ascent 1317 /Descent -349 "
                . "/CapHeight 742 /StemV 80 >>";
    // 英数字用。PDFの標準14フォントなので埋め込み不要。
    $objects[8] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = array();
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}
