<?php
/**
 * 精算画面。1つのファイルで2つの顔を持つ。
 *
 *   管理者      … 全出品者の未払一覧を見て、振込後に支払いを記録する
 *   出品者本人  … 自分の売上・手数料・未払残・支払い履歴を見る
 *
 * 分けずに1枚にしたのは、見せる数字が同じだから。分けると計算が二重になり、
 * 「管理画面と出品者画面で金額が違う」という一番まずい状態を作りかねない。
 * 見せる範囲だけを権限で変える。
 */
require_once __DIR__ . '/kapp_boot.php';
require_once __DIR__ . '/kapp_payout.php';
kapp_handle_auth_links('payout.php');

$notice = ''; $error = '';

/* ---- 支払いの記録（管理者のみ）----
 * 振込を実行したあとに押す。ここでお金が動くわけではなく、
 * 「振り込んだ」という事実を記録するだけ。 */
if ($is_admin && isset($_POST['pay'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } else {
        $seller = (string)$_POST['pay'];
        $ids    = isset($_POST['orders']) && is_array($_POST['orders']) ? $_POST['orders'] : array();
        $note   = trim((string)(isset($_POST['note']) ? $_POST['note'] : ''));
        $res = kapp_record_payout($seller, $ids, $note);
        if (empty($res[0])) {
            $error = $res[1];
        } else {
            $notice = '@' . kapp_h($seller) . ' への ' . number_format($res[1]['total'])
                    . '円（' . count($res[1]['order_ids']) . '件）を支払い済みとして記録しました。';
        }
    }
}

// 管理者は全員ぶん、出品者は自分のぶんだけ
$all      = $is_admin ? kapp_payout_summary() : array();
$mine     = $logged_in ? kapp_seller_summary($user) : null;
$sellers  = array();
foreach (kapp_sellers() as $s) { $sellers[kapp_norm_user($s['x'])] = $s; }

function payout_seller_name($seller, $sellers) {
    $k = kapp_norm_user($seller);
    return isset($sellers[$k]) ? $sellers[$k]['name'] : '@' . $seller;
}

kapp_head('精算 | Kurage App Store', '売上と手数料、未払残の確認。',
    'https://kappstore.exbridge.jp/payout.php', true);
kapp_header('精算', $logged_in, $user, $is_seller, $is_admin);
if ($logged_in) { kapp_subnav('payout.php', $user, $is_seller, $is_admin); }
?>
<main class="wrap">
<section>
  <h1>精算</h1>

<?php if (!$logged_in): ?>
  <p class="lead">売上と未払残を確認するには、𝕏 でログインしてください。</p>
  <p><a class="btn" href="?login=1">𝕏 でログイン</a></p>

<?php else: ?>
  <?php if ($notice !== ''): ?><p class="ok"><?php echo $notice; ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?php echo kapp_h($error); ?></p><?php endif; ?>

  <?php /* ================= 出品者本人の画面 ================= */ ?>
  <?php if ($mine): ?>
  <div class="card">
    <h2>あなたの売上</h2>
    <div style="display:flex;gap:26px;flex-wrap:wrap;font-size:14px;margin:14px 0">
      <div>販売数<b style="font-size:24px;display:block;line-height:1.2"><?php echo (int)$mine['count']; ?></b></div>
      <div>売上（税込）<b style="font-size:24px;display:block;line-height:1.2"><?php echo number_format($mine['sale_total']); ?>円</b></div>
      <div>出品手数料（税込）<b style="font-size:24px;display:block;line-height:1.2;color:var(--abyss-soft)"><?php echo number_format($mine['fee_total']); ?>円</b></div>
      <div>お支払い済み<b style="font-size:24px;display:block;line-height:1.2"><?php echo number_format($mine['paid_total']); ?>円</b></div>
      <div>未払残<b style="font-size:24px;display:block;line-height:1.2;color:var(--teal-deep)"><?php echo number_format($mine['unpaid_total']); ?>円</b></div>
    </div>
    <p class="hint">
      出品手数料は <b>販売価格（税抜）の<?php echo (int)(KAPP_FEE_RATE * 100); ?>％ ＋ <?php echo number_format(KAPP_FEE_FIXED); ?>円（税別）</b>です。
      デモサイトの構築、導入・設定マニュアルを同梱したパッケージング、出品登録の費用が含まれます。
      お振り込み後に支払明細書（兼 適格請求書）を発行しますので、下記の履歴からお受け取りください。</p>
    <?php $me = kapp_find_seller($user); ?>
    <?php if (empty($me['bank'])): ?>
    <p class="err" style="margin-top:12px">
      <b>お振込先が未登録です。</b>お売り上げをお振り込みできませんので、
      <a href="sellers.php">出品者情報</a>にご登録ください。</p>
    <?php endif; ?>
  </div>

  <?php $my_unpaid = kapp_unpaid_orders($user); ?>
  <?php if ($my_unpaid): ?>
  <div class="card plain">
    <h2>お支払い予定（<?php echo count($my_unpaid); ?>件）</h2>
    <div class="scroll">
    <table class="kv" style="width:100%">
      <tr><th>販売日</th><th>商品</th><th style="text-align:right;white-space:nowrap">売上(税込)</th>
          <th style="text-align:right;white-space:nowrap">手数料(税込)</th><th style="text-align:right;white-space:nowrap">お支払額</th></tr>
      <?php foreach ($my_unpaid as $o): ?>
      <tr>
        <td><?php echo date('Y/n/j', (int)$o['paid_at']); ?></td>
        <td><?php echo kapp_h(mb_strimwidth($o['app_name'], 0, 30, '…', 'UTF-8')); ?></td>
        <td style="text-align:right;white-space:nowrap"><?php echo number_format($o['sale_total']); ?>円</td>
        <td style="text-align:right;white-space:nowrap;color:var(--abyss-soft)">-<?php echo number_format($o['fee_total']); ?>円</td>
        <td style="text-align:right;white-space:nowrap"><b><?php echo number_format($o['net_total']); ?>円</b></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <?php $my_payouts = kapp_seller_payouts($user); ?>
  <?php if ($my_payouts): ?>
  <div class="card plain">
    <h2>お支払い履歴</h2>
    <?php foreach ($my_payouts as $p): ?>
    <div class="row">
      <div class="grow">
        <b><?php echo date('Y年n月j日', (int)$p['paid_at']); ?></b>
        <span style="color:var(--abyss-soft);font-size:12.5px">　<?php echo count($p['order_ids']); ?>件ぶん
          <?php if (!empty($p['note'])): ?>　<?php echo kapp_h($p['note']); ?><?php endif; ?></span>
      </div>
      <b><?php echo number_format($p['total']); ?>円</b>
      <a class="btn ghost" style="padding:6px 14px;font-size:12.5px;margin-left:12px"
         href="statement.php?id=<?php echo kapp_h($p['id']); ?>" target="_blank" rel="noopener">支払明細書</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php elseif (!$is_admin): ?>
  <p class="lead">まだ出品がありません。<a href="sellers.php">出品者登録</a>からお進みください。</p>
  <?php endif; ?>

  <?php /* ================= 管理者の画面 ================= */ ?>
  <?php if ($is_admin): ?>
  <h2 style="margin-top:34px">出品者への支払い（管理者）</h2>
  <?php if (!$all): ?>
    <p class="empty-note">入金済みの売上がまだありません。</p>
  <?php else: ?>
    <?php foreach ($all as $seller => $s): $unpaid = kapp_unpaid_orders($seller); ?>
    <div class="card">
      <h3 style="font-size:16px;margin-bottom:8px">
        <?php echo kapp_h(payout_seller_name($seller, $sellers)); ?>
        <span style="font-size:12px;color:var(--abyss-soft)">@<?php echo kapp_h($seller); ?></span>
      </h3>
      <div style="display:flex;gap:22px;flex-wrap:wrap;font-size:13.5px;margin-bottom:10px">
        <span>販売 <b><?php echo (int)$s['count']; ?></b>件</span>
        <span>売上 <b><?php echo number_format($s['sale_total']); ?></b>円</span>
        <span>手数料 <b><?php echo number_format($s['fee_total']); ?></b>円</span>
        <span>支払済 <b><?php echo number_format($s['paid_total']); ?></b>円</span>
        <span>未払残 <b style="color:var(--teal-deep)"><?php echo number_format($s['unpaid_total']); ?></b>円</span>
      </div>

      <?php $sk = kapp_norm_user($seller); $sinfo = isset($sellers[$sk]) ? $sellers[$sk] : array(); ?>
      <p class="hint" style="margin-bottom:12px">
        <?php if (!empty($sinfo['bank'])): ?>
          お振込先: <b><?php echo kapp_h($sinfo['bank']); ?></b>
        <?php else: ?>
          <b style="color:#c0392b">お振込先が未登録です。</b>出品者に出品者情報の更新をご案内してください。
        <?php endif; ?>
        <?php if (!empty($sinfo['invoice_no'])): ?>　登録番号 <?php echo kapp_h($sinfo['invoice_no']); ?><?php endif; ?>
      </p>

      <?php if (!$unpaid): ?>
        <p class="hint">未払いはありません。</p>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
          <div class="scroll">
          <table class="kv" style="width:100%">
            <tr><th style="width:34px"></th><th>販売日</th><th>商品</th><th>購入者</th>
                <th style="text-align:right;white-space:nowrap">お支払額</th></tr>
            <?php foreach ($unpaid as $o): ?>
            <tr>
              <td><input type="checkbox" name="orders[]" value="<?php echo kapp_h($o['id']); ?>" checked
                         style="width:17px;height:17px"></td>
              <td><?php echo date('n/j', (int)$o['paid_at']); ?></td>
              <td><?php echo kapp_h(mb_strimwidth($o['app_name'], 0, 26, '…', 'UTF-8')); ?></td>
              <td style="font-size:12.5px;color:var(--abyss-soft)"><?php echo kapp_h($o['billing_name']); ?></td>
              <td style="text-align:right;white-space:nowrap"><b><?php echo number_format($o['net_total']); ?>円</b></td>
            </tr>
            <?php endforeach; ?>
          </table>
          </div>
          <p style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="text" name="note" placeholder="メモ（例：2026-08-31 振込）"
                   style="flex:1;min-width:200px">
            <button type="submit" name="pay" value="<?php echo kapp_h($seller); ?>" class="btn"
                    onclick="return confirm('チェックした売上を支払い済みとして記録します。先に振込を済ませてから押してください。')">
              支払い済みにする</button>
          </p>
          <p class="hint">
            <b>この操作でお金は動きません。</b>実際の振込を済ませてから記録してください。
            記録すると未払残から外れ、取り消しはできません。支払明細書は記録と同時に発行されます。</p>
        </form>
      <?php endif; ?>

      <?php $past = kapp_seller_payouts($seller); ?>
      <?php if ($past): ?>
        <p class="hint" style="margin-top:14px;margin-bottom:4px">お支払い済み</p>
        <?php foreach ($past as $p): ?>
        <div class="row" style="padding:6px 0">
          <div class="grow" style="font-size:13px">
            <?php echo date('Y/n/j', (int)$p['paid_at']); ?>
            <span style="color:var(--abyss-soft)">　<?php echo count($p['order_ids']); ?>件
              <?php if (!empty($p['note'])): ?>　<?php echo kapp_h($p['note']); ?><?php endif; ?></span>
          </div>
          <span style="font-size:13px"><?php echo number_format($p['total']); ?>円</span>
          <a style="font-size:12.5px;margin-left:12px"
             href="statement.php?id=<?php echo kapp_h($p['id']); ?>" target="_blank" rel="noopener">支払明細書</a>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</section>
</main>
<?php kapp_footer(); ?>
