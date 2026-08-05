<?php
/**
 * Kurage App Store — アプリ一覧（トップ）。
 *
 * 買い手はここから探し、デモを触ってから注文する。ログイン不要で閲覧できる
 * （何を売っているか見せてからログインさせる。vibe-prototype.php と同じ方針）。
 */
require_once __DIR__ . '/kapp_boot.php';
kapp_handle_auth_links('index.php');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$apps = kapp_apps_published();
if ($q !== '') {
    $hit = array();
    foreach ($apps as $app) {
        $hay = $app['name'] . ' ' . $app['summary'] . ' ' . (isset($app['body']) ? $app['body'] : '');
        if (mb_stripos($hay, $q, 0, 'UTF-8') !== false) { $hit[] = $app; }
    }
    $apps = $hit;
}

$sellers = array();
foreach (kapp_sellers() as $s) { $sellers[kapp_norm_user($s['x'])] = $s; }

kapp_head(
    'Kurage App Store — AIが設置できる業務システムのダウンロード販売',
    'デモを触ってから買える、業務システムのダウンロード販売。購入後はAIエージェントに渡すだけで設置まで進められます。ソースは改変・再配布自由。',
    'https://kappstore.exbridge.jp/'
);
kapp_header('AIが設置できる業務システム', $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap">
<section>
  <h1>触ってから買える、業務システムのお店</h1>
  <p class="lead">
    デモサイトで実際に動かしてからご購入いただけます。購入後はダウンロードしたファイルを
    <b>AIエージェント（Claude Code など）に渡すだけ</b>で、設置とカスタマイズまで進められます。<br>
    ソースコードが手元に残るので、あとからご自身で育てられます。
  </p>

  <form method="get" style="margin-bottom:24px;display:flex;gap:10px;flex-wrap:wrap">
    <input type="text" name="q" value="<?php echo kapp_h($q); ?>" placeholder="やりたいことで探す（例：見積書、勤怠、予約）"
           style="flex:1;min-width:240px">
    <button type="submit" class="btn">探す</button>
  </form>

<?php if (!$apps): ?>
  <p class="empty-note">
    <?php echo $q !== '' ? '「' . kapp_h($q) . '」に一致するアプリはありませんでした。' : 'まだ公開中のアプリがありません。'; ?>
  </p>
<?php else: ?>
  <div class="grid">
  <?php foreach ($apps as $app): $p = kapp_price_parts($app['price']); ?>
    <article class="item">
      <a class="thumb<?php echo empty($app['image']) ? ' empty' : ''; ?>"
         href="app.php?id=<?php echo kapp_h($app['id']); ?>">
        <?php if (!empty($app['image'])): ?>
          <img src="kapp_media/<?php echo kapp_h($app['image']); ?>" alt="<?php echo kapp_h($app['name']); ?>" loading="lazy">
        <?php else: ?>🪼<?php endif; ?>
      </a>
      <div class="body">
        <h3><a href="app.php?id=<?php echo kapp_h($app['id']); ?>"><?php echo kapp_h($app['name']); ?></a></h3>
        <p class="sum"><?php echo kapp_h(mb_strimwidth($app['summary'], 0, 84, '…', 'UTF-8')); ?></p>
        <div class="foot">
          <?php if ($p['total'] === 0): ?>
            <span class="yen">無料</span><span class="tag free">FREE</span>
          <?php else: ?>
            <span class="yen"><?php echo number_format($p['total']); ?>円<small>税込</small></span>
          <?php endif; ?>
          <?php if (!empty($app['demo_url'])): ?><span class="tag">デモあり</span><?php endif; ?>
        </div>
        <p style="font-size:11.5px;color:var(--abyss-soft)">
          販売：<?php
            $sk = kapp_norm_user($app['seller']);
            echo kapp_h(isset($sellers[$sk]) ? $sellers[$sk]['name'] : '@' . $app['seller']);
          ?>
        </p>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>

<section>
  <div class="card plain">
    <h2>ご購入からご利用までの流れ</h2>
    <ul>
      <li><b>デモを触る</b> — 各アプリのデモサイトで、実際の動きをご確認ください。</li>
      <li><b>注文して決済</b> — 𝕏 でログインし、銀行振込または PayPal でお支払いいただきます。請求書PDFを発行できます。</li>
      <li><b>ダウンロード</b> — お支払い後、この画面からダウンロードできます。</li>
      <li><b>AIに渡して設置</b> — ダウンロードした一式を Claude Code などのAIエージェントに渡すと、
        設置手順書と設計書が同梱されているので、そのまま構築まで進められます。</li>
    </ul>
    <p class="hint">動作環境・設置方法は、アプリごとの詳細画面に記載しています。</p>
  </div>
</section>
</main>
<?php kapp_footer(); ?>
