<?php
/**
 * Kurage App Store — 台帳（販売店 / アプリ / 注文）。
 *
 * heteml にDBを置かない方針（kgeo/karchitect/vibe-prototype と同じ）。
 * JSONファイルを flock で直列化して読み書きする。更新は必ず
 * kapp_ledger_update() を通すこと。直接 file_put_contents すると
 * 並行アクセスで連番と注文を取り違える。
 *
 * PHP 5.x でも動く構文だけを使う（新規サブドメインのPHPバージョンが
 * 事前に確認できないため。exbridge.jp が 5.6 で `??` が構文エラーに
 * なった事例がある）。
 */

if (!defined('KAPP_DATA_DIR')) {
    define('KAPP_DATA_DIR', __DIR__ . '/kapp_data');
}
define('KAPP_SELLERS', KAPP_DATA_DIR . '/sellers.json');
define('KAPP_APPS',    KAPP_DATA_DIR . '/apps.json');
define('KAPP_ORDERS',  KAPP_DATA_DIR . '/orders.json');
define('KAPP_FILES',   KAPP_DATA_DIR . '/files');

// 最初の販売者。マーケットプレイス化までは、この人以外は審査待ちで止める。
if (!defined('KAPP_ADMIN')) { define('KAPP_ADMIN', 'xb_bittensor'); }

define('KAPP_TAX_RATE', 0.10);

/* ---------------- 基盤 ---------------- */

/** random_bytes は PHP 7 以降。5.x でも動くように退避経路を持つ。 */
function kapp_random_hex($bytes) {
    if (function_exists('random_bytes')) { return bin2hex(random_bytes($bytes)); }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
    $out = '';
    for ($i = 0; $i < $bytes * 2; $i++) { $out .= dechex(mt_rand(0, 15)); }
    return $out;
}

function kapp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * 台帳を排他ロックして更新する。
 * $callback(&$data) の戻り値をそのまま返す。
 */
function kapp_ledger_update($path, $key, $callback) {
    if (!is_dir(KAPP_DATA_DIR) && !@mkdir(KAPP_DATA_DIR, 0705, true)) {
        return array(false, '台帳ディレクトリを作成できません');
    }
    $fp = @fopen($path, 'c+');
    if (!$fp) { return array(false, '台帳を開けません'); }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return array(false, '台帳をロックできません'); }
    rewind($fp);
    $data = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($data)) { $data = array(); }
    if (!isset($data[$key]) || !is_array($data[$key])) { $data[$key] = array(); }
    if (!isset($data['seq'])) { $data['seq'] = 0; }
    $result = $callback($data);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    @chmod($path, 0600);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function kapp_ledger_load($path, $key) {
    if (!file_exists($path)) { return array(); }
    $fp = @fopen($path, 'r');
    if (!$fp) { return array(); }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) { return array(); }
    return $data[$key];
}

/* ---------------- 販売店 ---------------- */

/** Xのユーザー名は大文字小文字を区別しない。台帳側で正規化しておく。 */
function kapp_norm_user($user) { return strtolower(ltrim(trim((string)$user), '@')); }

function kapp_sellers() { return kapp_ledger_load(KAPP_SELLERS, 'sellers'); }

function kapp_find_seller($user) {
    $user = kapp_norm_user($user);
    foreach (kapp_sellers() as $seller) {
        if (kapp_norm_user($seller['x']) === $user) { return $seller; }
    }
    return null;
}

/** 承認済みの販売店だけが出品できる。 */
function kapp_is_approved_seller($user) {
    $seller = kapp_find_seller($user);
    return $seller !== null && !empty($seller['approved']);
}

function kapp_is_admin($user) {
    return $user !== '' && kapp_norm_user($user) === kapp_norm_user(KAPP_ADMIN);
}

/**
 * 販売店を登録する。管理者は自動承認、それ以外は審査待ち。
 * マーケットプレイス化したらここの既定を変える。
 */
/**
 * 適格請求書発行事業者の登録番号を整える。
 *
 * 「T」＋13桁が正。全角や空白、ハイフン混じりで入力されがちなので直してから
 * 検証する。形が違うものを通すと支払明細書に嘘の番号が載る。
 */
function kapp_norm_invoice_no($no) {
    $no = mb_convert_kana(trim((string)$no), 'as', 'UTF-8');   // 全角英数→半角
    $no = preg_replace('/[\s\-‐−―ー]/u', '', $no);
    $no = strtoupper($no);
    if ($no === '') { return ''; }
    if (preg_match('/^[0-9]{13}$/', $no)) { $no = 'T' . $no; } // Tの付け忘れ
    return $no;
}

function kapp_register_seller($user, $name, $url, $email = '', $invoice_no = '', $bank = '') {
    $user = kapp_norm_user($user);
    if ($user === '') { return array(false, 'ログインが必要です'); }
    if (trim($name) === '') { return array(false, '販売者名をご入力ください'); }
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        return array(false, 'URLは http:// または https:// で始めてください');
    }
    $email = strtolower(trim((string)$email));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array(false, 'メールアドレスの形式が正しくありません');
    }
    $invoice_no = kapp_norm_invoice_no($invoice_no);
    if ($invoice_no !== '' && !preg_match('/^T[0-9]{13}$/', $invoice_no)) {
        return array(false, '登録番号は「T」＋13桁の数字でご入力ください');
    }
    $bank = trim((string)$bank);
    if (mb_strlen($bank, 'UTF-8') > 200) { return array(false, 'お振込先が長すぎます'); }

    return kapp_ledger_update(KAPP_SELLERS, 'sellers', function (&$data) use ($user, $name, $url, $email, $invoice_no, $bank) {
        foreach ($data['sellers'] as $i => $seller) {
            if (kapp_norm_user($seller['x']) === $user) {
                // 再登録は上書き。承認状態は維持する（登録し直しで承認が外れないように）
                $data['sellers'][$i]['name'] = $name;
                $data['sellers'][$i]['url'] = $url;
                if ($email !== '') { $data['sellers'][$i]['email'] = $email; }
                $data['sellers'][$i]['invoice_no'] = $invoice_no;
                $data['sellers'][$i]['bank'] = $bank;
                $data['sellers'][$i]['updated_at'] = time();
                return array(true, '販売店情報を更新しました');
            }
        }
        $data['sellers'][] = array(
            'x'          => $user,
            'name'       => $name,
            'url'        => $url,
            // 注文が入ったときの通知先。無ければ管理者へ送る
            'email'      => $email,
            // 適格請求書発行事業者の登録番号。支払明細書に相手方として印字する
            'invoice_no' => $invoice_no,
            // 売上をお振り込みする口座
            'bank'       => $bank,
            'approved'   => kapp_is_admin($user),
            'created_at' => time(),
            'updated_at' => time(),
        );
        return array(true, kapp_is_admin($user)
            ? '販売店を登録しました'
            : '販売店の登録を受け付けました。審査後に出品できるようになります');
    });
}

function kapp_approve_seller($user, $approved) {
    $user = kapp_norm_user($user);
    return kapp_ledger_update(KAPP_SELLERS, 'sellers', function (&$data) use ($user, $approved) {
        foreach ($data['sellers'] as $i => $seller) {
            if (kapp_norm_user($seller['x']) === $user) {
                $data['sellers'][$i]['approved'] = (bool)$approved;
                return array(true, $approved ? '承認しました' : '承認を取り消しました');
            }
        }
        return array(false, '販売店が見つかりません');
    });
}

/* ---------------- アプリ ---------------- */

function kapp_apps_all() { return kapp_ledger_load(KAPP_APPS, 'apps'); }

/** 公開中のものだけ。新しい順。 */
function kapp_apps_published() {
    $out = array();
    foreach (kapp_apps_all() as $app) {
        if (isset($app['status']) && $app['status'] === 'published') { $out[] = $app; }
    }
    usort($out, 'kapp_cmp_created_desc');
    return $out;
}

function kapp_cmp_created_desc($a, $b) {
    $x = isset($a['created_at']) ? (int)$a['created_at'] : 0;
    $y = isset($b['created_at']) ? (int)$b['created_at'] : 0;
    if ($x === $y) { return 0; }
    return $x < $y ? 1 : -1;
}

function kapp_find_app($id) {
    foreach (kapp_apps_all() as $app) {
        if ($app['id'] === $id) { return $app; }
    }
    return null;
}

function kapp_seller_apps($user) {
    $user = kapp_norm_user($user);
    $out = array();
    foreach (kapp_apps_all() as $app) {
        if (kapp_norm_user($app['seller']) === $user) { $out[] = $app; }
    }
    usort($out, 'kapp_cmp_created_desc');
    return $out;
}

/** 税別価格から税額と税込を出す。表示・請求書・PayPalでずれないよう一箇所に集約。 */
function kapp_price_parts($amount) {
    $amount = (int)$amount;
    $tax = (int)floor($amount * KAPP_TAX_RATE);
    return array('amount' => $amount, 'tax' => $tax, 'total' => $amount + $tax);
}

function kapp_save_app($app) {
    return kapp_ledger_update(KAPP_APPS, 'apps', function (&$data) use ($app) {
        foreach ($data['apps'] as $i => $existing) {
            if ($existing['id'] === $app['id']) {
                $data['apps'][$i] = $app;
                return array(true, $app['id']);
            }
        }
        $data['apps'][] = $app;
        return array(true, $app['id']);
    });
}

/* ---------------- 注文 ---------------- */

function kapp_orders_all() { return kapp_ledger_load(KAPP_ORDERS, 'orders'); }

/** 本人の注文だけ返す。他人の請求書が見えると取引先名と金額が漏れる。 */
function kapp_user_orders($user) {
    $user = kapp_norm_user($user);
    $out = array();
    foreach (kapp_orders_all() as $order) {
        if (kapp_norm_user($order['user']) === $user) { $out[] = $order; }
    }
    return array_reverse($out);
}

function kapp_find_order($user, $id) {
    foreach (kapp_user_orders($user) as $order) {
        if ($order['id'] === $id) { return $order; }
    }
    return null;
}

/** 購入済みか。ダウンロードの可否はここだけで判定する。 */
function kapp_has_paid($user, $app_id) {
    foreach (kapp_user_orders($user) as $order) {
        if ($order['app_id'] === $app_id && $order['status'] === 'paid') { return true; }
    }
    return false;
}

function kapp_create_order($user, $app, $billing, $contact, $method, $email = '') {
    $price = kapp_price_parts(isset($app['price']) ? $app['price'] : 0);
    return kapp_ledger_update(KAPP_ORDERS, 'orders',
        function (&$data) use ($user, $app, $billing, $contact, $method, $email, $price) {
            $data['seq'] = (int)$data['seq'] + 1;
            $order = array(
                'id'           => kapp_random_hex(8),
                'invoice_no'   => 'KAS-' . date('Ymd') . '-' . sprintf('%04d', $data['seq']),
                'user'         => kapp_norm_user($user),
                'app_id'       => $app['id'],
                'app_name'     => $app['name'],
                'seller'       => $app['seller'],
                'billing_name' => $billing,
                'contact'      => $contact,
                // 銀行振込のとき、入金確認をお知らせする宛先
                'email'        => strtolower(trim((string)$email)),
                'method'       => $method,
                'amount'       => $price['amount'],
                'tax'          => $price['tax'],
                'total'        => $price['total'],
                // 無料アプリは注文と同時に購入済みにする（決済を挟まない）
                'status'       => $price['total'] === 0 ? 'paid' : 'unpaid',
                'created_at'   => time(),
                'agreed_terms' => true,
            );
            if ($order['status'] === 'paid') { $order['paid_at'] = time(); }
            $data['orders'][] = $order;
            return array(true, $order['id']);
        });
}

/** 全注文（管理者用）。新しい順。 */
function kapp_all_orders() { return array_reverse(kapp_orders_all()); }

function kapp_find_order_any($id) {
    foreach (kapp_orders_all() as $o) { if ($o['id'] === $id) { return $o; } }
    return null;
}

/**
 * 管理者が入金を確認して購入済みにする。
 * 銀行振込には、これ以外に購入済みへ変える経路が無い
 * （PayPalは kapp_mark_paid が決済完了時に呼ばれる）。
 */
function kapp_admin_mark_paid($order_id, $note = '') {
    return kapp_ledger_update(KAPP_ORDERS, 'orders', function (&$data) use ($order_id, $note) {
        foreach ($data['orders'] as $i => $order) {
            if ($order['id'] === $order_id) {
                if ($order['status'] === 'paid') { return array(false, 'すでに入金済みです'); }
                $data['orders'][$i]['status'] = 'paid';
                $data['orders'][$i]['paid_at'] = time();
                $data['orders'][$i]['paid_by'] = 'admin';
                if ($note !== '') { $data['orders'][$i]['paid_note'] = $note; }
                return array(true, $order);
            }
        }
        return array(false, '注文が見つかりません');
    });
}

/** 入金の記録を取り消す（間違えて押したとき） */
function kapp_admin_unmark_paid($order_id) {
    return kapp_ledger_update(KAPP_ORDERS, 'orders', function (&$data) use ($order_id) {
        foreach ($data['orders'] as $i => $order) {
            // PayPalで決済済みのものは取り消さない（決済の記録と食い違うため）
            if ($order['id'] === $order_id) {
                if (!empty($order['paypal_order_id'])) { return array(false, 'PayPal決済済みのため取り消せません'); }
                $data['orders'][$i]['status'] = 'unpaid';
                unset($data['orders'][$i]['paid_at'], $data['orders'][$i]['paid_by']);
                return array(true, '未入金に戻しました');
            }
        }
        return array(false, '注文が見つかりません');
    });
}

function kapp_mark_paid($user, $order_id, $paypal_id) {
    $user = kapp_norm_user($user);
    return kapp_ledger_update(KAPP_ORDERS, 'orders', function (&$data) use ($user, $order_id, $paypal_id) {
        foreach ($data['orders'] as $i => $order) {
            if ($order['id'] === $order_id && kapp_norm_user($order['user']) === $user) {
                $data['orders'][$i]['status'] = 'paid';
                $data['orders'][$i]['paid_at'] = time();
                $data['orders'][$i]['paypal_order_id'] = $paypal_id;
                return array(true, 'お支払いを受け付けました');
            }
        }
        return array(false, '注文が見つかりません');
    });
}

/* ---------------- 通知メール ---------------- */

/**
 * 入金確認をお知らせして、ダウンロードURLを渡す。
 *
 * 共有サーバーから送るので、From は SPF にそのサーバーが入っている
 * ドメインを使うこと。入っていないと受信側に捨てられ、mail() は
 * それでも true を返す（kinvoice で実際に不達を起こした）。
 */
function kapp_mail_from() {
    return defined('KAPP_MAIL_FROM') ? KAPP_MAIL_FROM : 'info@exbridge.jp';
}

/** システム管理者。すべてのメールの控えがここに届く。 */
function kapp_admin_email() {
    return defined('KAPP_ADMIN_EMAIL') ? trim(KAPP_ADMIN_EMAIL) : '';
}

/** 販売店の通知先。未登録なら管理者へ落とす（通知が消えるのが一番まずい）。 */
function kapp_seller_email($seller_x) {
    $s = kapp_find_seller($seller_x);
    if ($s && !empty($s['email']) && filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
        return $s['email'];
    }
    return kapp_admin_email();
}

/**
 * メール送信の共通口。
 *
 * すべてのメールの控えをシステム管理者へ Bcc で送る。To/Cc にすると
 * 購入者に管理者のアドレスが見えてしまうため Bcc を使う。
 *
 * From は SPF にこのサーバーが入っているドメインを使うこと。入っていないと
 * 受信側に捨てられ、mail() はそれでも true を返す（kinvoice で実際に不達）。
 */
function kapp_mail($to, $subject, $body) {
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    $from = kapp_mail_from();
    $headers = array(
        'From: Kurage App Store <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Kurage App Store',
    );
    $bcc = kapp_admin_email();
    if ($bcc !== '' && strtolower($bcc) !== strtolower($to)) {
        $headers[] = 'Bcc: ' . $bcc;
    }
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=',
                 chunk_split(base64_encode($body)), implode("\r\n", $headers));
}

/** 注文が入ったことを販売店へ知らせる。銀行振込はこれが入金待ちの合図。 */
function kapp_send_order_mail($order) {
    $to = kapp_seller_email(isset($order['seller']) ? $order['seller'] : '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    $method = $order['method'] === 'paypal' ? 'PayPal'
            : ($order['method'] === 'free' ? '無料' : '銀行振込');
    $paid = $order['status'] === 'paid';

    $subject = '【Kurage App Store】新しいご注文（' . $order['invoice_no'] . '）'
             . ($paid ? '' : ' ※入金待ち');

    $body = "新しいご注文が入りました。\n\n"
          . "──────────────────────────\n"
          . "  注文番号 : " . $order['invoice_no'] . "\n"
          . "  商品     : " . $order['app_name'] . "\n"
          . "  金額     : " . number_format((int)$order['total']) . " 円（税込）\n"
          . "  支払方法 : " . $method . "\n"
          . "  状態     : " . ($paid ? '入金済み' : '入金待ち') . "\n"
          . "──────────────────────────\n"
          . "  請求先   : " . $order['billing_name'] . " 様\n"
          . (empty($order['contact']) ? '' : "  ご担当   : " . $order['contact'] . "\n")
          . "  購入者   : @" . $order['user'] . "\n"
          . "  連絡先   : " . (empty($order['email']) ? '（未登録）' : $order['email']) . "\n"
          . "──────────────────────────\n\n";

    if (!$paid) {
        $body .= "▼ 銀行振込です。入金を確認したら、下記から「入金を確認」を押してください。\n"
               . "  押すと、購入者へダウンロード案内が自動で送られます。\n\n";
    }
    $body .= "https://kappstore.exbridge.jp/admin.php\n\n"
           . "──────────────────────────\n"
           . "Kurage App Store — 株式会社エクスブリッジ\n";
    return kapp_mail($to, $subject, $body);
}

/** 購入者へ注文内容の控えを送る。画面を閉じても振込先が分かるように。 */
function kapp_send_buyer_order_mail($order) {
    $to = isset($order['email']) ? trim((string)$order['email']) : '';
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    $bank = ($order['method'] === 'bank' && (int)$order['total'] > 0);

    $subject = '【Kurage App Store】ご注文を承りました（' . $order['invoice_no'] . '）';
    $body = $order['billing_name'] . " 様\n\n"
          . "ご注文ありがとうございます。内容は下記のとおりです。\n\n"
          . "──────────────────────────\n"
          . "  注文番号 : " . $order['invoice_no'] . "\n"
          . "  商品     : " . $order['app_name'] . "\n"
          . "  金額     : " . number_format((int)$order['total']) . " 円（税込）\n"
          . "──────────────────────────\n\n";

    if ($bank) {
        $body .= "▼ お振込先\n"
               . "  三井住友銀行 上前津支店 普通 7312531\n"
               . "  カ）エクスブリッジ（株式会社エクスブリッジ）\n"
               . "  金額 " . number_format((int)$order['total']) . " 円（税込）\n\n"
               . "  振込手数料はお客様のご負担でお願いいたします。\n"
               . "  お振込みの際は注文番号（" . $order['invoice_no'] . "）をご記入ください。\n\n"
               . "  ご入金を確認しましたら、ダウンロードのご案内をお送りします。\n\n";
    } elseif ($order['status'] === 'paid') {
        $body .= "▼ ダウンロードはこちら\n"
               . "  https://kappstore.exbridge.jp/download.php?id=" . rawurlencode($order['app_id']) . "\n\n";
    }

    $body .= "ご注文の状況は購入履歴からご確認いただけます。\n"
           . "  https://kappstore.exbridge.jp/orders.php\n\n"
           . "──────────────────────────\n"
           . "Kurage App Store — 株式会社エクスブリッジ\n"
           . kapp_mail_from() . "\n";
    return kapp_mail($to, $subject, $body);
}

function kapp_send_paid_mail($order) {
    $to = isset($order['email']) ? trim((string)$order['email']) : '';
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    $dl = 'https://kappstore.exbridge.jp/download.php?id=' . rawurlencode($order['app_id']);
    $subject = '【Kurage App Store】ご入金を確認しました（' . $order['invoice_no'] . '）';

    $body = $order['billing_name'] . " 様\n\n"
          . "いつもお世話になっております。Kurage App Store です。\n"
          . "ご入金を確認しました。ありがとうございます。\n\n"
          . "──────────────────────────\n"
          . "  注文番号 : " . $order['invoice_no'] . "\n"
          . "  商品     : " . $order['app_name'] . "\n"
          . "  金額     : " . number_format((int)$order['total']) . " 円（税込）\n"
          . "──────────────────────────\n\n"
          . "▼ ダウンロードはこちら\n"
          . $dl . "\n\n"
          . "ダウンロードには、ご注文時の 𝕏 アカウント（@" . $order['user'] . "）での\n"
          . "ログインが必要です。購入履歴からいつでも再ダウンロードいただけます。\n"
          . "  https://kappstore.exbridge.jp/orders.php\n\n"
          . "──────────────────────────\n"
          . "Kurage App Store — 株式会社エクスブリッジ\n"
          . kapp_mail_from() . "\n";
    return kapp_mail($to, $subject, $body);
}
