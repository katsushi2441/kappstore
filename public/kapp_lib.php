<?php
/**
 * Kurage App Store — 台帳（出品者 / アプリ / 注文）。
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

// 最初の出品者。マーケットプレイス化までは、この人以外は審査待ちで止める。
if (!defined('KAPP_ADMIN')) { define('KAPP_ADMIN', 'xb_bittensor'); }

define('KAPP_TAX_RATE', 0.10);

/**
 * 紹介した販売代理店へ支払う手数料の率。
 *
 * reseller.html / auto-monetization.html / exbridge.jp の recruit.html で
 * 「Kurage App Store 商品は成約額の10%」と公開している。ここを変えるときは
 * その3ページも直すこと（食い違うと支払いで揉める）。
 */
if (!defined('KAPP_AGENT_RATE')) { define('KAPP_AGENT_RATE', 0.10); }

/**
 * 代理店の台帳。kurage 側（vibe）と同じものを1つだけ持つ。
 *
 * 代理店の登録・審査は vibe-agent.php で行うので、ここでは読むだけにする。
 * 二重に持つと、片方で解除した代理店へ払い続ける事故になる。
 */
if (!defined('KAPP_AGENTS_JSON')) {
    define('KAPP_AGENTS_JSON', __DIR__ . '/../kurage_exbridge_jp/vibe_data/agents.json');
}

// 出品条件の版。文面を変えたらここを上げる。過去に同意した人が
// 「どの条件に同意したのか」を後から辿れなくなるため。
if (!defined('KAPP_SELLER_TERMS_VERSION')) { define('KAPP_SELLER_TERMS_VERSION', '2026-08-07'); }

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

/* ---------------- 出品者 ---------------- */

/** Xのユーザー名は大文字小文字を区別しない。台帳側で正規化しておく。 */
function kapp_norm_user($user) { return strtolower(ltrim(trim((string)$user), '@')); }

/**
 * 紹介した販売代理店を、kurage 側の台帳から引く。
 *
 * 有効（active）な代理店だけを返す。審査待ち・停止中を返すと、
 * 支払えない相手に手数料が積み上がる。
 * 台帳が読めないときは null（＝紹介なし扱い）。紹介の記録が消えるより、
 * 誤って手数料を付けるほうが後で揉めるため、安全側に倒す。
 */
function kapp_find_agent($handle) {
    $handle = kapp_norm_user($handle);
    if ($handle === '' || !preg_match('/^[0-9a-z_]{1,20}$/', $handle)) { return null; }
    if (!is_readable(KAPP_AGENTS_JSON)) { return null; }
    $raw = @file_get_contents(KAPP_AGENTS_JSON);
    if ($raw === false) { return null; }
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) { return null; }
    $list = isset($d['agents']) && is_array($d['agents']) ? $d['agents'] : $d;
    if (!is_array($list)) { return null; }
    foreach ($list as $a) {
        if (!is_array($a) || !isset($a['x'])) { continue; }
        if (kapp_norm_user($a['x']) !== $handle) { continue; }
        $status = isset($a['status']) ? (string)$a['status'] : '';
        return ($status === 'active') ? $a : null;
    }
    return null;
}

function kapp_sellers() { return kapp_ledger_load(KAPP_SELLERS, 'sellers'); }

function kapp_find_seller($user) {
    $user = kapp_norm_user($user);
    foreach (kapp_sellers() as $seller) {
        if (kapp_norm_user($seller['x']) === $user) { return $seller; }
    }
    return null;
}

/* ============================================================
 * 出品者の状態
 *
 * 入り口が2つある。
 *
 *   管理者が招待する  invited  ─┐
 *                              ├─▶ 詳細登録 ─▶ active（出品可）
 *   本人が応募する    applied ─▶ approved ─┘
 *
 * 段階を分けたのは、聞く情報の重さが違うから。応募の時点で銀行口座まで
 * 求めると応募されない。承認して取引すると決めてから聞く。
 * 逆に招待は当方から声を掛けているので、承認の段階を挟まない。
 * ========================================================== */

/** 出品できる状態。ここを通らないと register.php は使えない。 */
function kapp_seller_status($seller) {
    if (!$seller) { return ''; }
    if (!empty($seller['status'])) { return (string)$seller['status']; }
    // 旧レコード（approved の真偽しか持たない）からの読み替え
    return !empty($seller['approved']) ? 'active' : 'applied';
}

/** 詳細登録が済んでいるか。振込先が無いと売上を払えないので必須にする。 */
function kapp_seller_details_ready($seller) {
    return $seller
        && trim((string)(isset($seller['name']) ? $seller['name'] : '')) !== ''
        && trim((string)(isset($seller['bank']) ? $seller['bank'] : '')) !== '';
}

/** 出品者として出品できるか。 */
function kapp_is_approved_seller($user) {
    return kapp_seller_status(kapp_find_seller($user)) === 'active';
}

/** 詳細登録の画面を出してよい状態か（招待された／承認された）。 */
function kapp_seller_can_complete($user) {
    $st = kapp_seller_status(kapp_find_seller($user));
    return $st === 'invited' || $st === 'approved';
}

function kapp_seller_status_label($status) {
    $map = array(
        'invited'   => 'ご案内済み',
        'applied'   => '審査待ち',
        'approved'  => '承認済み（詳細未登録）',
        'active'    => '出品可',
        'suspended' => '停止中',
    );
    return isset($map[$status]) ? $map[$status] : $status;
}

function kapp_is_admin($user) {
    return $user !== '' && kapp_norm_user($user) === kapp_norm_user(KAPP_ADMIN);
}

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

/** 空の出品者レコード。どの入り口から作っても形を揃える。 */
function kapp_seller_blank($user, $status) {
    return array(
        'x'          => kapp_norm_user($user),
        'status'     => $status,
        'name'       => '',   // 購入者に見せる開発元名
        'company'    => '',   // 法人名（契約・支払明細書に使う）
        'contact'    => '',   // ご担当者名
        'tel'        => '',   // 管理用。購入者には出さない
        'email'      => '',   // 注文通知の宛先
        'url'        => '',
        'addr'       => '',
        'bank'       => '',
        'invoice_no' => '',
        'token'      => kapp_random_hex(16),   // 詳細登録の案内URLに使う
        'approved'   => false,                 // 旧実装との互換
        'created_at' => time(),
        'updated_at' => time(),
    );
}

/** 案内URLのトークンから引く。 */
function kapp_find_seller_by_token($token) {
    $token = trim((string)$token);
    if ($token === '') { return null; }
    foreach (kapp_sellers() as $seller) {
        if (!empty($seller['token']) && hash_equals((string)$seller['token'], $token)) { return $seller; }
    }
    return null;
}

/** 詳細登録の案内URL。管理者がこれを出品者へ送る。 */
function kapp_seller_invite_url($seller) {
    return 'https://kappstore.exbridge.jp/sellers.php?t=' . rawurlencode((string)$seller['token']);
}

/**
 * 管理者が出品者を招待する（経路A）。
 *
 * こちらから声を掛けている相手なので、審査の段階を挟まない。
 * 𝕏アカウントだけで枠を作り、残りは本人に埋めてもらう。
 */
function kapp_invite_seller($x, $email = '', $memo = '') {
    $x = kapp_norm_user($x);
    if ($x === '') { return array(false, '𝕏 アカウントをご入力ください'); }
    if (!preg_match('/^[a-z0-9_]{1,15}$/', $x)) {
        return array(false, '𝕏 アカウントは半角英数字とアンダースコアのみです');
    }
    $email = strtolower(trim((string)$email));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array(false, 'メールアドレスの形式が正しくありません');
    }
    return kapp_ledger_update(KAPP_SELLERS, 'sellers', function (&$data) use ($x, $email, $memo) {
        foreach ($data['sellers'] as $seller) {
            if (kapp_norm_user($seller['x']) === $x) {
                return array(false, '@' . $x . ' はすでに登録されています');
            }
        }
        $rec = kapp_seller_blank($x, 'invited');
        $rec['email'] = $email;
        $rec['memo']  = (string)$memo;
        $data['sellers'][] = $rec;
        return array(true, $rec);
    });
}

/**
 * 本人が応募する（経路B）。
 *
 * ここで聞くのは連絡が取れる情報だけ。銀行口座は承認後に聞く。
 * 応募の時点で口座まで求めると、応募そのものが来なくなる。
 */
function kapp_apply_seller($user, $company, $contact, $tel, $email) {
    $user = kapp_norm_user($user);
    if ($user === '') { return array(false, 'ログインが必要です'); }
    $company = trim((string)$company);
    $contact = trim((string)$contact);
    $tel     = trim((string)$tel);
    $email   = strtolower(trim((string)$email));
    if ($company === '') { return array(false, '会社名（屋号）をご入力ください'); }
    if ($contact === '') { return array(false, 'ご担当者名をご入力ください'); }
    if (!preg_match('/^[0-9０-９\-‐ー－\(\)\s]{9,20}$/u', $tel)) {
        return array(false, 'お電話番号をご入力ください');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array(false, 'メールアドレスをご入力ください');
    }
    return kapp_ledger_update(KAPP_SELLERS, 'sellers', function (&$data) use ($user, $company, $contact, $tel, $email) {
        foreach ($data['sellers'] as $i => $seller) {
            if (kapp_norm_user($seller['x']) === $user) {
                $st = kapp_seller_status($seller);
                if ($st !== 'applied') {
                    return array(false, 'すでに登録済みです（' . kapp_seller_status_label($st) . '）');
                }
                // 審査待ちの間は書き直せる
                $data['sellers'][$i] = array_merge($seller, array(
                    'company' => $company, 'contact' => $contact,
                    'tel' => $tel, 'email' => $email, 'updated_at' => time(),
                ));
                return array(true, 'お申し込みの内容を更新しました');
            }
        }
        // 管理者が自分を承認する段取りは意味が無いので、審査を飛ばす。
        // ただし出品可にはしない。振込先の登録は管理者にも通す。
        $rec = kapp_seller_blank($user, kapp_is_admin($user) ? 'approved' : 'applied');
        $rec['company'] = $company; $rec['contact'] = $contact;
        $rec['tel'] = $tel;         $rec['email'] = $email;
        $data['sellers'][] = $rec;
        return array(true, 'お申し込みを受け付けました');
    });
}

/** 管理者の審査。承認すると詳細登録に進める。 */
function kapp_approve_seller($user, $approved) {
    $user = kapp_norm_user($user);
    return kapp_ledger_update(KAPP_SELLERS, 'sellers', function (&$data) use ($user, $approved) {
        foreach ($data['sellers'] as $i => $seller) {
            if (kapp_norm_user($seller['x']) !== $user) { continue; }
            if ($approved) {
                // 詳細が埋まっているなら、そのまま出品可へ戻す
                $next = kapp_seller_details_ready($seller) ? 'active' : 'approved';
                $data['sellers'][$i]['status']   = $next;
                $data['sellers'][$i]['approved'] = true;
                return array(true, $next === 'active' ? '承認しました' : '承認しました（詳細登録待ち）');
            }
            $data['sellers'][$i]['status']   = 'suspended';
            $data['sellers'][$i]['approved'] = false;
            return array(true, '出品を停止しました');
        }
        return array(false, '出品者が見つかりません');
    });
}

/**
 * 本人が詳細を登録する。ここまで済んで初めて出品できる。
 *
 * 振込先を必須にしているのは、売上をお振り込みできない状態で
 * 商品を並べさせないため。
 */
function kapp_complete_seller($user, $f) {
    // 出品条件への同意。控えるのは「同意した事実と時刻と版」で、
    // あとから条件を変えても、その人が何に同意したかが分かるようにする。
    $agreed = !empty($f['agree']);
    $user = kapp_norm_user($user);
    if ($user === '') { return array(false, 'ログインが必要です'); }
    $seller = kapp_find_seller($user);
    if (!$seller) { return array(false, '出品者の登録が見つかりません'); }
    $st = kapp_seller_status($seller);
    if ($st === 'applied')   { return array(false, '審査が済むまでお待ちください'); }
    if ($st === 'suspended') { return array(false, '現在ご出品いただけません'); }

    $name    = trim((string)$f['name']);
    $company = trim((string)$f['company']);
    $contact = trim((string)$f['contact']);
    $tel     = trim((string)$f['tel']);
    $email   = strtolower(trim((string)$f['email']));
    $url     = trim((string)$f['url']);
    $addr    = trim((string)$f['addr']);
    $bank    = trim((string)$f['bank']);
    $inv     = kapp_norm_invoice_no($f['invoice_no']);

    if ($name === '')    { return array(false, '開発元名をご入力ください'); }
    if ($company === '') { return array(false, '会社名（屋号）をご入力ください'); }
    if ($contact === '') { return array(false, 'ご担当者名をご入力ください'); }
    if (!preg_match('/^[0-9０-９\-‐ー－\(\)\s]{9,20}$/u', $tel)) {
        return array(false, 'お電話番号をご入力ください');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array(false, 'メールアドレスをご入力ください');
    }
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        return array(false, 'URLは http:// または https:// で始めてください');
    }
    if (!$agreed)        { return array(false, '出品条件へのご同意が必要です'); }
    if ($bank === '')    { return array(false, '売上のお振込先をご入力ください'); }
    if (mb_strlen($bank, 'UTF-8') > 200) { return array(false, 'お振込先が長すぎます'); }
    if ($inv !== '' && !preg_match('/^T[0-9]{13}$/', $inv)) {
        return array(false, '登録番号は「T」＋13桁の数字でご入力ください');
    }

    return kapp_ledger_update(KAPP_SELLERS, 'sellers', function (&$data) use (
        $user, $name, $company, $contact, $tel, $email, $url, $addr, $bank, $inv) {
        foreach ($data['sellers'] as $i => $seller) {
            if (kapp_norm_user($seller['x']) !== $user) { continue; }
            $data['sellers'][$i] = array_merge($seller, array(
                'name' => $name, 'company' => $company, 'contact' => $contact,
                'tel' => $tel, 'email' => $email, 'url' => $url, 'addr' => $addr,
                'bank' => $bank, 'invoice_no' => $inv,
                'agreed_terms' => true,
                'agreed_at' => time(),
                'agreed_version' => KAPP_SELLER_TERMS_VERSION,
                'status' => 'active', 'approved' => true, 'updated_at' => time(),
            ));
            return array(true, '出品者情報を登録しました。出品できます');
        }
        return array(false, '出品者が見つかりません');
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
/**
 * 出品名から短い呼び名を取り出す。「顔打刻つき勤怠システム（Kurage Kintai）」→
 * 「Kurage Kintai」。定義文の主語に使う。
 */
function kapp_short_name($app) {
    $name = isset($app['name']) ? (string)$app['name'] : '';
    if (preg_match('/[（(]([^）)]+)[）)]\s*$/u', $name, $m)) {
        $inner = trim($m[1]);
        if ($inner !== '') { return $inner; }
    }
    return $name;
}

/**
 * 「○○とは、〜です。」の述部を作る。AI検索は定義文の形をした1文を引用するため、
 * 要約の第1文をそのまま使い、体言止めなら「〜です。」を補う。
 * （kgeoのAEO監査で definitions が0点だったことへの対策）
 */
function kapp_definition_sentence($app) {
    $summary = isset($app['summary']) ? trim((string)$app['summary']) : '';
    if ($summary === '') {
        return '業務システムです。';
    }
    $parts = preg_split('/(?<=。)/u', $summary, 2);
    $first = isset($parts[0]) ? trim($parts[0]) : $summary;
    if (function_exists('mb_strimwidth')) {
        $first = mb_strimwidth($first, 0, 220, '…', 'UTF-8');
    }
    if ($first === '') { return '業務システムです。'; }
    $last = function_exists('mb_substr') ? mb_substr($first, -1, 1, 'UTF-8') : substr($first, -1);
    if ($last !== '。') { $first .= 'です。'; }
    return $first;
}

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

function kapp_create_order($user, $app, $billing, $contact, $method, $email = '', $agent = '') {
    $price = kapp_price_parts(isset($app['price']) ? $app['price'] : 0);
    // 紹介した代理店と、そのときの率を注文に控える。あとから率を変えても
    // 過去の成約の手数料が動かないようにする（vibe 側と同じ考え方）。
    $a = kapp_find_agent($agent);
    $agent_key  = $a ? kapp_norm_user($a['x']) : '';
    $agent_rate = $a ? (float)KAPP_AGENT_RATE : 0.0;
    $agent_type = ($a && isset($a['type'])) ? (string)$a['type'] : '';
    // 渡された紹介元は、照合できなくても必ず控える。
    // 台帳が読めない・審査待ちだった、という取りこぼしを後から拾えるようにする。
    $agent_ref  = kapp_norm_user($agent);
    // ログインしない購入者を識別する鍵。注文完了メールのダウンロードURLに使う。
    $token = kapp_random_hex(16);
    return kapp_ledger_update(KAPP_ORDERS, 'orders',
        function (&$data) use ($user, $app, $billing, $contact, $method, $email, $price,
                               $agent_key, $agent_rate, $agent_type, $agent_ref, $token) {
            $data['seq'] = (int)$data['seq'] + 1;
            $order = array(
                'id'           => kapp_random_hex(8),
                'invoice_no'   => 'KAS-' . date('Ymd') . '-' . sprintf('%04d', $data['seq']),
                // 𝕏 でログインした場合のみ入る。未ログインの購入では空。
                'user'         => kapp_norm_user($user),
                // 未ログインの購入者はこの鍵で自分の注文だけを開ける
                'token'        => $token,
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
                // 紹介した代理店（有効な登録がある場合のみ）と、そのときの率
                'agent'        => $agent_key,
                'agent_rate'   => $agent_rate,
                'agent_type'   => $agent_type,
                // 照合できたかに関わらず、渡された紹介元を控える
                'agent_ref'    => $agent_ref,
                // 無料アプリは注文と同時に購入済みにする（決済を挟まない）
                'status'       => $price['total'] === 0 ? 'paid' : 'unpaid',
                'created_at'   => time(),
                'agreed_terms' => true,
            );
            if ($order['status'] === 'paid') { $order['paid_at'] = time(); }
            $data['orders'][] = $order;
            // 3つ目にトークンを返す。未ログインの購入者は、これが無いと
            // 自分の注文（＝ダウンロード）へ戻れなくなる。
            return array(true, $order['id'], $order['token']);
        });
}

/**
 * 注文をトークンで引く。𝕏 にログインしない購入者の唯一の経路。
 *
 * id とトークンの両方が一致したときだけ返す。トークンは注文ごとに
 * 発行した32桁で、当てずっぽうでは引けない。
 */
function kapp_find_order_by_token($id, $token) {
    $id = (string)$id; $token = (string)$token;
    if ($id === '' || $token === '') { return null; }
    foreach (kapp_orders_all() as $o) {
        if ((string)$o['id'] !== $id) { continue; }
        if (!isset($o['token']) || !is_string($o['token']) || $o['token'] === '') { return null; }
        return hash_equals($o['token'], $token) ? $o : null;
    }
    return null;
}

/** 購入済みの注文をトークンで引く。ダウンロードの可否判定に使う。 */
function kapp_paid_order_by_token($token, $app_id) {
    $token = (string)$token;
    if ($token === '') { return null; }
    foreach (kapp_orders_all() as $o) {
        if (!isset($o['token']) || !is_string($o['token']) || $o['token'] === '') { continue; }
        if (!hash_equals($o['token'], $token)) { continue; }
        if ((string)$o['app_id'] !== (string)$app_id) { return null; }
        return ($o['status'] === 'paid') ? $o : null;
    }
    return null;
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

/**
 * PayPal の決済完了を記録する。
 *
 * $user はログインしている購入者、$token は未ログインの購入者。
 * どちらか一方がその注文のものと一致すれば購入済みにする。
 */
function kapp_mark_paid($user, $order_id, $paypal_id, $token = '') {
    $user = kapp_norm_user($user);
    $token = (string)$token;
    return kapp_ledger_update(KAPP_ORDERS, 'orders', function (&$data) use ($user, $order_id, $paypal_id, $token) {
        foreach ($data['orders'] as $i => $order) {
            $by_user  = ($user !== '' && kapp_norm_user($order['user']) === $user);
            $by_token = ($token !== '' && isset($order['token']) && is_string($order['token'])
                         && $order['token'] !== '' && hash_equals($order['token'], $token));
            if ($order['id'] === $order_id && ($by_user || $by_token)) {
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
 * 購入者に渡すダウンロードURL。
 *
 * 𝕏 にログインしない購入者も受け取れるよう、注文ごとのトークンを付ける。
 * トークンが無い古い注文は、従来どおりログイン前提のURLになる。
 */
function kapp_download_url($order) {
    $u = 'https://kappstore.exbridge.jp/download.php?id=' . rawurlencode($order['app_id']);
    if (!empty($order['token'])) { $u .= '&t=' . rawurlencode($order['token']); }
    return $u;
}

/** 注文の確認画面のURL（請求書PDF・支払い状況）。 */
function kapp_order_url($order) {
    $u = 'https://kappstore.exbridge.jp/order.php?order=' . rawurlencode($order['id']);
    if (!empty($order['token'])) { $u .= '&t=' . rawurlencode($order['token']); }
    return $u;
}

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
/**
 * システム管理者。カンマ区切りで複数を指定できる。
 *
 * 1つしか送れないと、その1つが受け取れなかったときに気づく人がいなくなる。
 * 注文や出品の申し込みは放置されるのが一番まずいので、複数へ送る。
 */
function kapp_admin_emails() {
    if (!defined('KAPP_ADMIN_EMAIL')) { return array(); }
    $out = array();
    foreach (explode(',', KAPP_ADMIN_EMAIL) as $a) {
        $a = trim($a);
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) { $out[] = $a; }
    }
    return $out;
}

/** 先頭の1つ。宛先を1つだけ書きたい場面（Bcc・出品者の代替宛先）で使う。 */
function kapp_admin_email() {
    $a = kapp_admin_emails();
    return $a ? $a[0] : '';
}

/** 出品者の通知先。未登録なら管理者へ落とす（通知が消えるのが一番まずい）。 */
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
    // 控えは管理者全員へ。宛先と重複するものだけ除く
    $bcc = array();
    foreach (kapp_admin_emails() as $a) {
        if (strtolower($a) !== strtolower($to)) { $bcc[] = $a; }
    }
    if ($bcc) { $headers[] = 'Bcc: ' . implode(', ', $bcc); }
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=',
                 chunk_split(base64_encode($body)), implode("\r\n", $headers));
}

/** 注文が入ったことを出品者へ知らせる。銀行振込はこれが入金待ちの合図。 */
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
               . "  " . kapp_download_url($order) . "\n\n"
               . "  このURLはお客様専用です。何度でもダウンロードいただけます。\n\n";
    }

    $body .= "▼ ご注文の確認・請求書PDF\n"
           . "  " . kapp_order_url($order) . "\n\n"
           . "  このURLは大切に保管してください。ログインなしでご確認いただけます。\n\n"
           . "──────────────────────────\n"
           . "Kurage App Store — 株式会社エクスブリッジ\n"
           . kapp_mail_from() . "\n";
    return kapp_mail($to, $subject, $body);
}

function kapp_send_paid_mail($order) {
    $to = isset($order['email']) ? trim((string)$order['email']) : '';
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    $dl = kapp_download_url($order);
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
          . "  " . $dl . "\n\n"
          . "  このURLはお客様専用です。何度でもダウンロードいただけます。\n"
          . "  他の方に転送しないでください。\n\n"
          . (kapp_norm_user(isset($order['user']) ? $order['user'] : '') !== ''
              ? "𝕏 アカウント（@" . $order['user'] . "）でログインすると、購入履歴からも取得できます。\n"
                . "  https://kappstore.exbridge.jp/orders.php\n\n"
              : "")
          . "──────────────────────────\n"
          . "Kurage App Store — 株式会社エクスブリッジ\n"
          . kapp_mail_from() . "\n";
    return kapp_mail($to, $subject, $body);
}

/* ---------------- 出品者まわりの通知 ----------------
 *
 * 応募も承認も、相手が画面を見に来ないと気づけない。放置されるのが
 * 一番まずいので、状態が変わったら必ずメールで知らせる。
 */

/** 管理者全員へ送る。1人でも送れたら成功とみなす。 */
function kapp_mail_admins($subject, $body) {
    $ok = false;
    foreach (kapp_admin_emails() as $to) {
        if (kapp_mail($to, $subject, $body)) { $ok = true; }
    }
    return $ok;
}

/** 応募が入ったことを管理者へ。これが無いと審査待ちが溜まったまま気づけない。 */
function kapp_notify_seller_applied($seller) {
    if (!kapp_admin_emails()) { return false; }
    $body = "出品者のお申し込みが届きました。\n\n"
          . "  𝕏        @" . $seller['x'] . "\n"
          . "  会社名   " . $seller['company'] . "\n"
          . "  ご担当   " . $seller['contact'] . "\n"
          . "  電話     " . $seller['tel'] . "\n"
          . "  メール   " . $seller['email'] . "\n\n"
          . "▼ 審査はこちらから\n"
          . "https://kappstore.exbridge.jp/sellers.php?admin=1\n\n"
          . "──────────────────────────\n"
          . "Kurage App Store\n";
    return kapp_mail_admins('[kappstore] 出品者のお申し込み @' . $seller['x'], $body);
}

/** 招待・承認を本人へ。詳細登録の入口URLを必ず入れる。 */
function kapp_notify_seller_invited($seller, $approved = false) {
    $to = isset($seller['email']) ? (string)$seller['email'] : '';
    if ($to === '') { return false; }
    $head = $approved
        ? "出品者のお申し込みを承認いたしました。\n\nお手数ですが、下記より残りの情報をご登録ください。"
        : "Kurage App Store への出品をご案内いたします。\n\n下記より出品者情報をご登録ください。";
    $body = $head . "\n\n"
          . kapp_seller_invite_url($seller) . "\n\n"
          . "※ @" . $seller['x'] . " で 𝕏 にログインしてお進みください。\n"
          . "  お振込先のご登録まで済みますと、ご出品いただけます。\n\n"
          . "出品手数料は 販売価格（税抜）の10％ ＋ 40,000円（税別）です。\n"
          . "初期費用はいただかず、売れたときだけ発生します。\n\n"
          . "──────────────────────────\n"
          . "Kurage App Store — 株式会社エクスブリッジ\n"
          . kapp_mail_from() . "\n";
    $subject = $approved ? '[kappstore] 出品者のご承認と情報登録のお願い'
                         : '[kappstore] 出品者ご登録のご案内';
    return kapp_mail($to, $subject, $body);
}

/** 詳細登録が済んで出品可になったことを管理者へ。 */
function kapp_notify_seller_active($seller) {
    if (!kapp_admin_emails()) { return false; }
    $body = "出品者の情報登録が完了しました。出品可能になっています。\n\n"
          . "  𝕏        @" . $seller['x'] . "\n"
          . "  開発元名 " . $seller['name'] . "\n"
          . "  会社名   " . $seller['company'] . "\n"
          . "  振込先   " . $seller['bank'] . "\n"
          . "  登録番号 " . ($seller['invoice_no'] !== '' ? $seller['invoice_no'] : '（未登録）') . "\n\n"
          . "https://kappstore.exbridge.jp/sellers.php?admin=1\n";
    return kapp_mail_admins('[kappstore] 出品者の情報登録が完了 @' . $seller['x'], $body);
}
