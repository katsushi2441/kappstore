<?php
/**
 * Kurage App Store — 購入履歴。
 * 購入済みのアプリはここからいつでも再ダウンロードできる。
 */
require_once __DIR__ . '/kapp_boot.php';
kapp_handle_auth_links('orders.php');

kapp_head('購入履歴 | Kurage App Store', 'ご購入いただいたアプリの一覧とダウンロード。',
    'https://kappstore.exbridge.jp/orders.php', true);
kapp_header('購入履歴', $logged_in, $user, $is_seller, $is_admin);
if ($logged_in) { kapp_subnav('orders.php', $user, $is_seller, $is_admin); }
?>
<main class="wrap narrow">
<section>
  <h1>購入履歴</h1>

<?php if (!$logged_in): ?>
  <p class="lead">購入履歴のご確認には 𝕏 でのログインが必要です。</p>
  <p><a class="btn" href="?login=1">𝕏 でログイン</a></p>
<?php else:
  $orders = kapp_user_orders($user);
  if (!$orders): ?>
  <p class="empty-note">まだご購入がありません。<br><a href="index.php">アプリ一覧を見る</a></p>
<?php else: ?>
  <p class="lead">ご購入済みのアプリは、何度でもダウンロードいただけます。</p>
  <div class="card">
  <?php foreach ($orders as $order): $paid = ($order['status'] === 'paid'); ?>
    <div class="row">
      <div class="grow">
        <b><a href="app.php?id=<?php echo kapp_h($order['app_id']); ?>"><?php echo kapp_h($order['app_name']); ?></a></b><br>
        <span style="color:var(--abyss-soft);font-size:12.5px">
          <?php echo kapp_h($order['invoice_no']); ?> ·
          <?php echo date('Y/n/j H:i', (int)$order['created_at']); ?> ·
          <?php echo (int)$order['total'] === 0 ? '無料' : number_format((int)$order['total']) . '円（税込）'; ?>
        </span>
      </div>
      <span class="tag <?php echo $paid ? 'paid' : ''; ?>"><?php echo $paid ? '購入済' : '未入金'; ?></span>
      <?php if ($paid): ?>
        <a class="chip" href="download.php?id=<?php echo kapp_h($order['app_id']); ?>">ダウンロード</a>
      <?php else: ?>
        <a class="chip" href="order.php?order=<?php echo kapp_h($order['id']); ?>">お支払いへ</a>
      <?php endif; ?>
      <?php if ((int)$order['total'] > 0): ?>
        <a class="chip" href="order.php?invoice=<?php echo kapp_h($order['id']); ?>">請求書</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; endif; ?>
</section>
</main>
<?php kapp_footer(); ?>
