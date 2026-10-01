<?php
/**
 * Kurage App Store — 分野ページ（/c-<slug>。.htaccess で c.php?c=<slug> へ）。
 * 分野の語で引かれる受け皿と、商品どうしの内部リンクを作る（kapp_categories.php に理由）。
 */
require_once __DIR__ . '/kapp_boot.php';
require_once __DIR__ . '/kapp_categories.php';
$slug = isset($_GET['c']) ? (string)$_GET['c'] : '';
kapp_handle_auth_links('c.php?c=' . rawurlencode($slug));
$cats = kapp_categories();
if (!isset($cats[$slug])) {
    http_response_code(404);
    kapp_head('見つかりません | Kurage App Store', 'お探しの分野は見つかりませんでした。', 'https://kappstore.exbridge.jp/', true);
    kapp_header('分野', $logged_in, $user, $is_seller, $is_admin);
    echo '<main class="wrap narrow"><p class="empty-note">お探しの分野は見つかりませんでした。<br><a href="index.php">アプリ一覧へ戻る</a></p></main>';
    kapp_footer();
    exit;
}
$cat = $cats[$slug];
$by = array();
foreach (kapp_apps_published() as $a) { $by[$a['id']] = $a; }
$apps = array();
foreach ($cat['ids'] as $id) { if (isset($by[$id])) { $apps[] = $by[$id]; } }
$canonical = kapp_category_url($slug);

$ld = array(
    array('@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $cat['title'], 'url' => $canonical,
          'description' => $cat['desc'], 'inLanguage' => 'ja',
          'isPartOf' => array('@type' => 'WebSite', 'name' => 'Kurage App Store', 'url' => 'https://kappstore.exbridge.jp/')),
    array('@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $cat['name'], 'numberOfItems' => count($apps),
          'itemListElement' => array_map(function ($a, $i) {
              return array('@type' => 'ListItem', 'position' => $i + 1, 'name' => $a['name'],
                           'url' => 'https://kappstore.exbridge.jp/app.php?id=' . $a['id']);
          }, $apps, array_keys($apps))),
    array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => 'Kurage App Store', 'item' => 'https://kappstore.exbridge.jp/'),
        array('@type' => 'ListItem', 'position' => 2, 'name' => $cat['name'], 'item' => $canonical))),
    array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($qa) {
        return array('@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $qa[1]));
    }, $cat['faq'])),
);
kapp_head($cat['title'], $cat['desc'], $canonical, false,
          json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
kapp_header($cat['name'], $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap">
<section>
  <p style="font-size:12.5px"><a href="index.php">Kurage App Store</a> › <?php echo kapp_h($cat['name']); ?></p>
  <h1><?php echo kapp_h($cat['h1']); ?></h1>
  <p class="lead" style="overflow-wrap:anywhere"><?php echo kapp_h($cat['lead']); ?></p>
</section>

<section>
  <h2 style="font-size:18px"><?php echo kapp_h($cat['name']); ?>の商品（<?php echo count($apps); ?>件）</h2>
  <div class="grid">
  <?php foreach ($apps as $app): $p = kapp_price_parts($app['price']); ?>
    <article class="item">
      <a class="thumb<?php echo empty($app['image']) ? ' empty' : ''; ?>" href="app.php?id=<?php echo kapp_h($app['id']); ?>&amp;ref=kapp-c-<?php echo kapp_h($slug); ?>">
        <?php if (!empty($app['image'])): ?>
          <img src="kapp_media/<?php echo kapp_h($app['image']); ?>" alt="<?php echo kapp_h($app['name']); ?>" loading="lazy">
        <?php else: ?>🪼<?php endif; ?>
      </a>
      <div class="body">
        <h3><a href="app.php?id=<?php echo kapp_h($app['id']); ?>&amp;ref=kapp-c-<?php echo kapp_h($slug); ?>"><?php echo kapp_h($app['name']); ?></a></h3>
        <p class="sum"><?php echo kapp_h(mb_strimwidth($app['summary'], 0, 120, '…', 'UTF-8')); ?></p>
        <div class="foot">
          <?php if (!empty($app['dev'])): ?>
            <span class="yen"><?php echo kapp_h($app['dev']); ?></span><span class="tag">要お問い合わせ</span>
          <?php elseif ($p['total'] === 0): ?>
            <span class="yen">無料</span><span class="tag free">FREE</span>
          <?php else: ?>
            <span class="yen"><?php echo number_format($p['total']); ?>円<small>税込</small></span>
          <?php endif; ?>
          <?php if (!empty($app['demo_url']) && empty($app['dev'])): ?><span class="tag">デモあり</span><?php endif; ?>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>

<section style="max-width:760px;margin:24px auto 0;background:var(--foam);border:1px solid var(--panel-line);border-radius:16px;padding:18px 20px">
  <h2 style="font-size:18px;margin:0 0 6px"><?php echo kapp_h($cat['name']); ?>のよくある質問</h2>
  <?php foreach ($cat['faq'] as $qa): ?>
    <div style="border-top:1px solid var(--panel-line);padding:11px 0">
      <h3 style="font-size:15px;font-weight:800;margin:0"><?php echo kapp_h($qa[0]); ?></h3>
      <p style="margin:6px 0 0;line-height:1.7;overflow-wrap:anywhere"><?php echo kapp_h($qa[1]); ?></p>
    </div>
  <?php endforeach; ?>
</section>

<section style="margin-top:24px">
  <h2 style="font-size:18px">ほかの分野から探す</h2>
  <p style="display:flex;gap:8px;flex-wrap:wrap">
  <?php foreach ($cats as $s => $c): if ($s === $slug) { continue; } ?>
    <a class="btn ghost" style="padding:6px 14px;font-size:13px" href="c-<?php echo kapp_h($s); ?>"><?php echo kapp_h($c['name']); ?></a>
  <?php endforeach; ?>
    <a class="btn ghost" style="padding:6px 14px;font-size:13px" href="index.php">すべての商品</a>
  </p>
</section>
</main>
<?php kapp_footer(); ?>
