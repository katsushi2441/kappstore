<?php
// サイトマップ。公開中のアプリを全部載せる。
// 「Claude Codeが探してくる」導線が前提なので、機械可読な入口を最初から用意する。
require_once __DIR__ . '/kapp_lib.php';
header('Content-Type: application/xml; charset=utf-8');
$base = 'https://kappstore.exbridge.jp/';
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
$fixed = array(array('', '1.0', 'daily'), array('sellers.php', '0.5', 'weekly'));
foreach ($fixed as $f) {
    echo "  <url><loc>" . htmlspecialchars($base . $f[0], ENT_QUOTES, 'UTF-8') . "</loc>"
       . "<changefreq>{$f[2]}</changefreq><priority>{$f[1]}</priority></url>\n";
}
foreach (kapp_apps_published() as $app) {
    $loc = $base . 'app.php?id=' . rawurlencode($app['id']);
    $mod = date('Y-m-d', isset($app['updated_at']) ? (int)$app['updated_at'] : (int)$app['created_at']);
    echo "  <url><loc>" . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . "</loc>"
       . "<lastmod>{$mod}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
}
echo '</urlset>';
