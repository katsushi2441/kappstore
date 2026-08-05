<?php
/**
 * Kurage App Store — アプリ詳細。
 * 未購入なら注文へ、購入済みならダウンロードへ導く。
 */
require_once __DIR__ . '/kapp_boot.php';
$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
kapp_handle_auth_links('app.php?id=' . rawurlencode($id));

$app = kapp_find_app($id);
if (!$app || (isset($app['status']) && $app['status'] !== 'published'
        && !($logged_in && (kapp_norm_user($app['seller']) === $user || $is_admin)))) {
    http_response_code(404);
    kapp_head('見つかりません | Kurage App Store', 'お探しのアプリは見つかりませんでした。',
        'https://kappstore.exbridge.jp/app.php', true);
    kapp_header('アプリ詳細', $logged_in, $user, $is_seller, $is_admin);
    echo '<main class="wrap narrow"><p class="empty-note">お探しのアプリは見つかりませんでした。<br>'
       . '<a href="index.php">アプリ一覧へ戻る</a></p></main>';
    kapp_footer();
    exit;
}

$p = kapp_price_parts($app['price']);
$seller = kapp_find_seller($app['seller']);
$owned = $logged_in && ($p['total'] === 0 ? false : kapp_has_paid($user, $app['id']));
$canonical = 'https://kappstore.exbridge.jp/app.php?id=' . rawurlencode($app['id']);

kapp_head(
    $app['name'] . ' | Kurage App Store',
    mb_strimwidth($app['summary'], 0, 110, '…', 'UTF-8'),
    $canonical
);
kapp_header('アプリ詳細', $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap narrow">
<section>
  <p style="font-size:12.5px"><a href="index.php">← アプリ一覧</a></p>
  <h1><?php echo kapp_h($app['name']); ?></h1>
  <p class="lead"><?php echo nl2br(kapp_h($app['summary'])); ?></p>

<?php if (isset($app['status']) && $app['status'] !== 'published'): ?>
  <p class="ok">この出品は<b>下書き</b>です。公開するまで一覧には表示されません。</p>
<?php endif; ?>

<?php if (!empty($app['image'])): ?>
  <p style="margin-bottom:18px">
    <img src="kapp_media/<?php echo kapp_h($app['image']); ?>" alt="<?php echo kapp_h($app['name']); ?>"
         style="border-radius:16px;border:1.5px solid var(--panel-line);display:block">
  </p>
<?php endif; ?>

  <div class="gate">
    <?php if ($p['total'] === 0): ?>
      <p class="price">無料<small>ダウンロードいただけます</small></p>
    <?php else: ?>
      <p class="price"><?php echo number_format($p['total']); ?>円<small>税込</small></p>
      <p style="font-size:13.5px;margin-top:6px">
        本体 <?php echo number_format($p['amount']); ?>円 ＋ 消費税 <?php echo number_format($p['tax']); ?>円</p>
    <?php endif; ?>

    <p style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
      <?php if (!empty($app['demo_url'])): ?>
        <a class="btn ghost" href="<?php echo kapp_h($app['demo_url']); ?>" target="_blank" rel="noopener">
          デモを触ってみる</a>
      <?php endif; ?>
      <?php if ($owned): ?>
        <a class="btn" href="download.php?id=<?php echo kapp_h($app['id']); ?>">ダウンロード</a>
      <?php elseif ($p['total'] === 0): ?>
        <a class="btn" href="order.php?app=<?php echo kapp_h($app['id']); ?>">無料で受け取る</a>
      <?php else: ?>
        <a class="btn" href="order.php?app=<?php echo kapp_h($app['id']); ?>">購入する</a>
      <?php endif; ?>
    </p>
    <?php if ($owned): ?>
      <p class="hint">ご購入済みです。何度でもダウンロードいただけます。</p>
    <?php elseif (!empty($app['demo_url'])): ?>
      <p class="hint">デモでご確認のうえ、ご納得いただいてからご購入ください。</p>
    <?php endif; ?>
  </div>

<?php if (!empty($app['body'])): ?>
  <div class="card">
    <h2>このアプリについて</h2>
    <p style="font-size:14px"><?php echo nl2br(kapp_h($app['body'])); ?></p>
  </div>
<?php endif; ?>

  <div class="card plain">
    <h2>販売情報</h2>
    <table class="kv">
      <tr><th>販売者</th><td>
        <?php if ($seller): ?>
          <?php echo kapp_h($seller['name']); ?>
          <?php if (!empty($seller['url'])): ?><br>
            <a href="<?php echo kapp_h($seller['url']); ?>" target="_blank" rel="noopener nofollow">
              <?php echo kapp_h($seller['url']); ?></a>
          <?php endif; ?>
        <?php else: ?>@<?php echo kapp_h($app['seller']); ?><?php endif; ?>
      </td></tr>
      <tr><th>𝕏 アカウント</th><td>
        <a href="https://x.com/<?php echo kapp_h($app['seller']); ?>" target="_blank" rel="noopener nofollow">@<?php echo kapp_h($app['seller']); ?></a>
      </td></tr>
      <?php if (!empty($app['demo_url'])): ?>
      <tr><th>デモサイト</th><td>
        <a href="<?php echo kapp_h($app['demo_url']); ?>" target="_blank" rel="noopener"><?php echo kapp_h($app['demo_url']); ?></a>
      </td></tr>
      <?php endif; ?>
      <?php if (!empty($app['filename'])): ?>
      <tr><th>配布ファイル</th><td><?php echo kapp_h($app['filename']); ?>
        <?php if (!empty($app['filesize'])): ?>
          （<?php echo number_format($app['filesize'] / 1024, 0); ?> KB）<?php endif; ?></td></tr>
      <?php endif; ?>
      <tr><th>公開日</th><td><?php echo date('Y年n月j日', (int)$app['created_at']); ?></td></tr>
    </table>
  </div>

  <div class="card plain">
    <h2>ご購入前にご確認ください</h2>
    <ul>
      <li><b>デモで動作をご確認ください。</b>ダウンロード商品の性質上、ご購入後の返品・返金はお受けできません。</li>
      <li>設置には、PHPが動作するレンタルサーバー等のご用意が必要です。詳細は各アプリの説明をご確認ください。</li>
      <li>ソースコードは改変・再配布が可能です。ライセンスは同梱の LICENSE をご確認ください。</li>
      <li>設置作業は、ダウンロードした一式をAIエージェントに渡すことを想定しています。個別のサポートは含みません。</li>
    </ul>
    <p class="hint">
      <a href="https://kurage.exbridge.jp/terms.html">利用規約</a> ／
      <a href="https://kurage.exbridge.jp/tokusho.php">特定商取引法に基づく表記</a></p>
  </div>
</section>
</main>
<?php kapp_footer(); ?>
