<?php
/**
 * Kurage App Store — 管理画面（全注文と入金確認）。
 *
 * 銀行振込には、注文を「購入済み」に変える経路がここしか無い。
 * PayPal は決済完了時に order.php が記録するが、振込は人が確認して
 * ここで押すまで unpaid のまま＝ダウンロードできない。
 *
 * 入金を記録すると、注文時のメールアドレスへダウンロード案内を送る。
 */
require_once __DIR__ . '/kapp_boot.php';
kapp_handle_auth_links('admin.php');

$notice = '';
$error  = '';

/* ---- 入金を確認する ---- */
if ($is_admin && isset($_POST['mark_paid'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。';
    } else {
        $res = kapp_admin_mark_paid((string)$_POST['mark_paid'],
            trim((string)(isset($_POST['note']) ? $_POST['note'] : '')));
        if (empty($res[0])) {
            $error = isset($res[1]) ? $res[1] : '記録できませんでした。';
        } else {
            $order = $res[1];
            $sent = kapp_send_paid_mail(kapp_find_order_any($order['id']));
            $notice = $order['invoice_no'] . ' を入金済みにしました。'
                    . ($sent ? 'ダウンロード案内を送信しました。'
                             : 'ただしメールを送信できませんでした（宛先未登録か送信失敗）。');
            if (!$sent) { $error = $notice; $notice = ''; }
        }
    }
}

/* ---- 入金の取り消し ---- */
if ($is_admin && isset($_POST['unmark_paid'])) {
    if (kapp_csrf_ok($csrf)) {
        $res = kapp_admin_unmark_paid((string)$_POST['unmark_paid']);
        if (empty($res[0])) { $error = isset($res[1]) ? $res[1] : ''; }
        else { $notice = isset($res[1]) ? $res[1] : ''; }
    }
}

/* ---- 案内メールの再送 ---- */
if ($is_admin && isset($_POST['resend'])) {
    if (kapp_csrf_ok($csrf)) {
        $order = kapp_find_order_any((string)$_POST['resend']);
        if ($order && $order['status'] === 'paid') {
            $ok = kapp_send_paid_mail($order);
            if ($ok) { $notice = $order['invoice_no'] . ' の案内を再送しました。'; }
            else { $error = $order['invoice_no'] . ' の再送に失敗しました。'; }
        }
    }
}

$orders = $is_admin ? kapp_all_orders() : array();
$unpaid = array();
foreach ($orders as $o) { if ($o['status'] !== 'paid') { $unpaid[] = $o; } }

kapp_head('注文管理 | Kurage App Store', '全注文の一覧と入金確認。',
    'https://kappstore.exbridge.jp/admin.php', true);
kapp_header('注文管理', $logged_in, $user, $is_seller, $is_admin);
if ($logged_in) { kapp_subnav('admin.php', $user, $is_seller, $is_admin); }
?>
<main class="wrap">
<section>
  <h1>注文管理</h1>

<?php if (!$is_admin): ?>
  <p class="lead">この画面は管理者専用です。</p>
  <?php if (!$logged_in): ?><p><a class="btn" href="?login=1">𝕏 でログイン</a></p><?php endif; ?>

<?php else: ?>
  <p class="lead">
    <b>銀行振込は、ここで入金を確認するまでダウンロードできません。</b>
    入金を記録すると、ご注文時のメールアドレスへダウンロード案内を送ります。
  </p>

  <?php if ($notice !== ''): ?><p class="ok"><?php echo kapp_h($notice); ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?php echo kapp_h($error); ?></p><?php endif; ?>

  <?php if ($unpaid): ?>
  <div class="card">
    <h2>入金待ち（<?php echo count($unpaid); ?>件）</h2>
    <?php foreach ($unpaid as $o): ?>
    <div class="row">
      <div class="grow">
        <b><?php echo kapp_h($o['invoice_no']); ?></b>　<?php echo kapp_h($o['billing_name']); ?> 様<br>
        <span style="color:var(--abyss-soft);font-size:12.5px">
          <?php echo kapp_h($o['app_name']); ?> ·
          <b><?php echo number_format((int)$o['total']); ?>円</b>（税込） ·
          <?php echo $o['method'] === 'paypal' ? 'PayPal' : '銀行振込'; ?> ·
          @<?php echo kapp_h($o['user']); ?> ·
          <?php echo date('Y/n/j H:i', (int)$o['created_at']); ?><br>
          <?php echo kapp_h(isset($o['email']) && $o['email'] !== '' ? $o['email'] : '（メール未登録）'); ?>
        </span>
      </div>
      <form method="post" style="margin:0;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
        <input type="text" name="note" placeholder="入金日・振込名義など（任意）"
               style="width:200px;font-size:12.5px;padding:6px 9px">
        <button type="submit" name="mark_paid" value="<?php echo kapp_h($o['id']); ?>"
                class="btn" style="padding:7px 16px;font-size:12.5px">入金を確認</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <p class="ok">入金待ちの注文はありません。</p>
  <?php endif; ?>

  <h2 style="margin-top:28px">すべての注文（<?php echo count($orders); ?>件）</h2>
  <?php if (!$orders): ?>
    <p class="empty-note">まだ注文がありません。</p>
  <?php else: ?>
  <div class="card plain">
    <?php foreach ($orders as $o): $paid = ($o['status'] === 'paid'); ?>
    <div class="row">
      <div class="grow">
        <b><?php echo kapp_h($o['invoice_no']); ?></b>　<?php echo kapp_h($o['billing_name']); ?> 様<br>
        <span style="color:var(--abyss-soft);font-size:12.5px">
          <?php echo kapp_h($o['app_name']); ?> ·
          <?php echo (int)$o['total'] === 0 ? '無料' : number_format((int)$o['total']) . '円（税込）'; ?> ·
          <?php echo $o['method'] === 'paypal' ? 'PayPal' : ($o['method'] === 'free' ? '無料' : '銀行振込'); ?> ·
          @<?php echo kapp_h($o['user']); ?>
          <?php if ($paid && !empty($o['paid_at'])): ?>
            · 入金 <?php echo date('n/j H:i', (int)$o['paid_at']); ?>
            <?php if (!empty($o['paid_by'])): ?>（手動）<?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($o['paid_note'])): ?><br>メモ: <?php echo kapp_h($o['paid_note']); ?><?php endif; ?>
        </span>
      </div>
      <span class="tag <?php echo $paid ? 'paid' : ''; ?>"><?php echo $paid ? '入金済' : '未入金'; ?></span>
      <?php if ($paid && !empty($o['email'])): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
        <button type="submit" name="resend" value="<?php echo kapp_h($o['id']); ?>"
                class="btn ghost" style="padding:5px 13px;font-size:12px">案内を再送</button>
      </form>
      <?php endif; ?>
      <?php if ($paid && empty($o['paypal_order_id']) && (int)$o['total'] > 0): ?>
      <form method="post" style="margin:0"
            onsubmit="return confirm('入金の記録を取り消します。ダウンロードもできなくなります。よろしいですか。')">
        <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
        <button type="submit" name="unmark_paid" value="<?php echo kapp_h($o['id']); ?>"
                class="btn ghost" style="padding:5px 13px;font-size:12px">取り消し</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
</section>
</main>
<?php kapp_footer(); ?>
