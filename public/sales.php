<?php
/**
 * Kurage App Store — 販売履歴（出品者向け）。
 *
 * 出品者が「自分は何をいくつ売ったか」を見る画面。精算（payout.php）は
 * お金の話に絞ってあり、支払い済みの売上は明細から消える。売った実績そのものは
 * こちらで通して見られるようにする。
 *
 * 見えるのは自分が出品したものだけ。管理者は全件を admin.php で見る。
 */
require_once __DIR__ . '/kapp_boot.php';
require_once __DIR__ . '/kapp_payout.php';
kapp_handle_auth_links('sales.php');

$rows = array();
$sum_count = 0; $sum_sale = 0; $sum_net = 0; $sum_unpaid = 0;

if ($logged_in) {
    foreach (kapp_all_orders() as $o) {
        if (kapp_norm_user($o['seller']) !== $user) { continue; }
        $p = kapp_payout_parts($o);
        $paid = ($o['status'] === 'paid');
        $out  = $paid && kapp_order_paid_out($o['id']);
        $rows[] = array_merge($o, $p, array('is_paid' => $paid, 'paid_out' => $out));
        if ($paid) {
            $sum_count++;
            $sum_sale += $p['sale_total'];
            $sum_net  += $p['net_total'];
            if (!$out) { $sum_unpaid += $p['net_total']; }
        }
    }
}

kapp_head('販売履歴 | Kurage App Store', 'ご出品いただいた商品の販売履歴。',
    'https://kappstore.exbridge.jp/sales.php', true);
kapp_header('販売履歴', $logged_in, $user, $is_seller, $is_admin);
if ($logged_in) { kapp_subnav('sales.php', $user, $is_seller, $is_admin); }
?>
<main class="wrap">
<section>
  <h1>販売履歴</h1>

<?php if (!$logged_in): ?>
  <p class="lead">ご確認には 𝕏 でのログインが必要です。</p>
  <p><a class="btn" href="?login=1">𝕏 でログイン</a></p>

<?php elseif (!$is_seller): ?>
  <p class="lead">まだご出品がありません。<a href="sellers.php">出品者のページ</a>からお進みください。</p>

<?php else: ?>
  <div class="card">
    <div style="display:flex;gap:26px;flex-wrap:wrap;font-size:14px">
      <div>販売数<b style="font-size:24px;display:block;line-height:1.2"><?php echo (int)$sum_count; ?></b></div>
      <div>売上（税込）<b style="font-size:24px;display:block;line-height:1.2"><?php echo number_format($sum_sale); ?>円</b></div>
      <div>お受け取り合計<b style="font-size:24px;display:block;line-height:1.2"><?php echo number_format($sum_net); ?>円</b></div>
      <div>未払残<b style="font-size:24px;display:block;line-height:1.2;color:var(--teal-deep)"><?php echo number_format($sum_unpaid); ?>円</b></div>
    </div>
    <p class="hint" style="margin-top:12px">
      入金が確認できたご注文だけを集計しています。お支払いの内訳は<a href="payout.php">精算</a>でご確認ください。</p>
  </div>

  <?php if (!$rows): ?>
    <p class="empty-note">まだご注文がありません。</p>
  <?php else: ?>
  <div class="card plain">
    <div class="scroll">
    <table class="kv" style="width:100%">
      <tr>
        <th>ご注文日</th><th>商品</th><th>購入者</th>
        <th style="text-align:right;white-space:nowrap">売上(税込)</th>
        <th style="text-align:right;white-space:nowrap">お受け取り</th>
        <th style="white-space:nowrap">状態</th>
      </tr>
      <?php foreach ($rows as $o): ?>
      <tr>
        <td style="white-space:nowrap"><?php echo date('Y/n/j', (int)$o['created_at']); ?></td>
        <td><?php echo kapp_h(mb_strimwidth($o['app_name'], 0, 28, '…', 'UTF-8')); ?></td>
        <td style="font-size:12.5px;color:var(--abyss-soft)"><?php echo kapp_h($o['billing_name']); ?></td>
        <td style="text-align:right;white-space:nowrap"><?php echo number_format($o['sale_total']); ?>円</td>
        <td style="text-align:right;white-space:nowrap">
          <?php echo $o['is_paid'] ? '<b>' . number_format($o['net_total']) . '円</b>' : '—'; ?></td>
        <td style="white-space:nowrap">
          <?php if (!$o['is_paid']): ?>
            <span class="tag draft">入金待ち</span>
          <?php elseif ($o['paid_out']): ?>
            <span class="tag">お支払い済み</span>
          <?php else: ?>
            <span class="tag paid">お支払い予定</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </div>
  <p class="hint">「入金待ち」は購入者からのご入金を確認できていないご注文です。
    確認できしだい、お受け取り額が確定します。</p>
  <?php endif; ?>
<?php endif; ?>
</section>
</main>
<?php kapp_footer(); ?>
