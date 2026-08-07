<?php
/**
 * 精算 — 出品者ごとの売上・手数料・未払残を出す。
 *
 * 【設計の要】残高を別に持たない。
 * 出品者ごとの「残高」という数字を台帳に持つと、注文の取り消しや入金の
 * 訂正のたびに更新が要り、必ずどこかでズレる。ズレたときに正しいのは
 * 注文台帳のほうなので、**残高は常に注文台帳から計算し直す**。
 * 台帳に持つのは「いつ・いくら払ったか」という支払い実績だけにする。
 *
 *   未払残 = 入金済み注文の出品者取り分の合計 － 支払い済みの合計
 *
 * 【手数料】
 *   手数料(税別) = 販売価格(税抜) × KAPP_FEE_RATE ＋ KAPP_FEE_FIXED
 * 税抜で積み上げてから消費税を足す。インボイス制度では税抜金額と消費税を
 * 分けて記録する必要があり、あとから支払明細を出すときに困らないため。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kapp_lib.php';

// 出品手数料。vibe-prototype の案内と同じ値にすること（食い違うと請求で揉める）。
if (!defined('KAPP_FEE_RATE'))  { define('KAPP_FEE_RATE', 0.10); }
if (!defined('KAPP_FEE_FIXED')) { define('KAPP_FEE_FIXED', 40000); }

define('KAPP_PAYOUTS', KAPP_DATA_DIR . '/payouts.json');

/**
 * 1件の注文から、手数料と出品者の取り分を出す。
 *
 * @return array 税抜・消費税・税込をそれぞれ持つ内訳
 */
function kapp_payout_parts($order) {
    $amount = (int)$order['amount'];          // 販売価格(税抜)
    $fee    = (int)floor($amount * KAPP_FEE_RATE) + (int)KAPP_FEE_FIXED;  // 手数料(税別)
    if ($fee > $amount) { $fee = $amount; }   // 取り分をマイナスにしない（最低価格を割った出品の保険）
    $net    = $amount - $fee;                 // 出品者の取り分(税抜)

    return array(
        'sale_amount' => $amount,
        'sale_tax'    => (int)$order['tax'],
        'sale_total'  => (int)$order['total'],
        'fee'         => $fee,
        'fee_tax'     => (int)floor($fee * KAPP_TAX_RATE),
        'fee_total'   => $fee + (int)floor($fee * KAPP_TAX_RATE),
        'net'         => $net,
        'net_tax'     => (int)floor($net * KAPP_TAX_RATE),
        'net_total'   => $net + (int)floor($net * KAPP_TAX_RATE),
    );
}

/**
 * 自社の出品かどうか。
 *
 * 店の運営者自身が出品したものは、売れてもお金は動かない（自分から自分へ
 * 払うことになる）。精算の対象に混ぜると、画面に「自分への未払残」が出て、
 * 誤って振り込む事故につながる。売上そのものは注文管理で見られる。
 */
function kapp_is_own_listing($seller) {
    return defined('KAPP_ADMIN') && KAPP_ADMIN !== ''
        && kapp_norm_user($seller) === kapp_norm_user(KAPP_ADMIN);
}

/* ---------------- 支払い実績 ---------------- */

function kapp_payouts() { return kapp_ledger_load(KAPP_PAYOUTS, 'payouts'); }

/** ある出品者への支払い実績。 */
function kapp_seller_payouts($seller) {
    $key = kapp_norm_user($seller);
    $out = array();
    foreach (kapp_payouts() as $p) {
        if (kapp_norm_user($p['seller']) === $key) { $out[] = $p; }
    }
    return array_reverse($out);
}

/**
 * 支払いを記録する。振込を実行したあとに押す。
 *
 * 対象の注文IDを控えるのが要点。「どの売上に対する支払いか」が
 * 残っていないと、あとから食い違ったときに突き合わせられない。
 */
function kapp_record_payout($seller, $order_ids, $note = '') {
    $seller = kapp_norm_user($seller);
    if ($seller === '') { return array(false, '出品者が指定されていません'); }
    if (kapp_is_own_listing($seller)) { return array(false, '自社の出品は精算の対象外です'); }
    if (!is_array($order_ids) || !$order_ids) { return array(false, '対象の注文がありません'); }

    // 金額は注文台帳から引き直す。画面から渡された数字は信用しない。
    $amount = 0; $tax = 0; $valid = array();
    foreach ($order_ids as $oid) {
        $order = kapp_find_order_any((string)$oid);
        if (!$order || $order['status'] !== 'paid') { continue; }
        if (kapp_norm_user($order['seller']) !== $seller) { continue; }
        if (kapp_order_paid_out((string)$oid)) { continue; }   // 二重払いの防止
        $p = kapp_payout_parts($order);
        $amount += $p['net'];
        $tax    += $p['net_tax'];
        $valid[] = (string)$oid;
    }
    if (!$valid) { return array(false, '未払いの対象がありませんでした'); }

    return kapp_ledger_update(KAPP_PAYOUTS, 'payouts', function (&$data) use ($seller, $valid, $amount, $tax, $note) {
        $payout = array(
            'id'        => kapp_random_hex(8),
            'seller'    => $seller,
            'order_ids' => $valid,
            'amount'    => $amount,          // 税抜
            'tax'       => $tax,
            'total'     => $amount + $tax,   // 実際に振り込む額
            'note'      => (string)$note,
            'paid_at'   => time(),
        );
        $data['payouts'][] = $payout;
        return array(true, $payout);
    });
}

/** その注文は支払い済みか。 */
function kapp_order_paid_out($order_id) {
    foreach (kapp_payouts() as $p) {
        if (in_array((string)$order_id, $p['order_ids'], true)) { return true; }
    }
    return false;
}

/* ---------------- 集計 ---------------- */

/**
 * 出品者ごとの精算状況。注文台帳から毎回計算する。
 *
 * @return array seller => 集計
 */
function kapp_payout_summary() {
    $sum = array();
    foreach (kapp_all_orders() as $order) {
        if ($order['status'] !== 'paid') { continue; }          // 入金済みだけが精算の対象
        $seller = kapp_norm_user($order['seller']);
        if ($seller === '') { continue; }
        if (kapp_is_own_listing($seller)) { continue; }         // 自社出品は精算しない

        if (!isset($sum[$seller])) {
            $sum[$seller] = array(
                'seller' => $seller, 'count' => 0,
                'sale_total' => 0, 'fee' => 0, 'fee_total' => 0,
                'net' => 0, 'net_total' => 0,
                'paid_total' => 0, 'unpaid_total' => 0,
                'unpaid_orders' => array(),
            );
        }
        $p = kapp_payout_parts($order);
        $s = &$sum[$seller];
        $s['count']++;
        $s['sale_total'] += $p['sale_total'];
        $s['fee']        += $p['fee'];
        $s['fee_total']  += $p['fee_total'];
        $s['net']        += $p['net'];
        $s['net_total']  += $p['net_total'];
        if (!kapp_order_paid_out($order['id'])) {
            $s['unpaid_total'] += $p['net_total'];
            $s['unpaid_orders'][] = $order['id'];
        }
        unset($s);
    }
    // 支払い済みの合計を足す
    foreach (kapp_payouts() as $p) {
        $seller = kapp_norm_user($p['seller']);
        if (isset($sum[$seller])) { $sum[$seller]['paid_total'] += (int)$p['total']; }
    }
    return $sum;
}

/** 1人ぶんの精算状況。 */
function kapp_seller_summary($seller) {
    $all = kapp_payout_summary();
    $key = kapp_norm_user($seller);
    return isset($all[$key]) ? $all[$key] : null;
}

/** 未払いの注文（明細表示用）。 */
function kapp_unpaid_orders($seller) {
    $key = kapp_norm_user($seller);
    $out = array();
    foreach (kapp_all_orders() as $order) {
        if ($order['status'] !== 'paid') { continue; }
        if (kapp_norm_user($order['seller']) !== $key) { continue; }
        if (kapp_order_paid_out($order['id'])) { continue; }
        $out[] = array_merge($order, kapp_payout_parts($order));
    }
    return $out;
}
