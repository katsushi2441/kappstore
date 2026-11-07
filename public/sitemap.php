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
// 分野ページ（2026-10-01）。中身は商品の追加で変わるので、載っている商品の最新の更新日を lastmod にする
require_once __DIR__ . '/kapp_categories.php';
$upd = array(); foreach (kapp_apps_published() as $a) { $upd[$a['id']] = (int)(isset($a['updated_at']) ? $a['updated_at'] : $a['created_at']); }
foreach (kapp_categories() as $cs => $cc) {
    $m = 0; foreach ($cc['ids'] as $i) { if (isset($upd[$i]) && $upd[$i] > $m) { $m = $upd[$i]; } }
    echo "  <url><loc>" . htmlspecialchars(kapp_category_url($cs), ENT_QUOTES, 'UTF-8') . "</loc>"
       . ($m ? "<lastmod>" . date('Y-m-d', $m) . "</lastmod>" : '') . "<changefreq>weekly</changefreq><priority>0.9</priority></url>\n";
}
// 買い手の質問と答え（kapp_answers.php）を足した商品は、そのファイルを置いた日も更新日に数える（2026-11-02）。
// 答えは台帳の外にあるので、台帳の updated_at だけだと Google・Gemini に「変わった」と伝わらなかった
require_once __DIR__ . '/kapp_answers.php';
$ans_m = (int)@filemtime(__DIR__ . '/kapp_answers.php');
foreach (kapp_apps_published() as $app) {
    $loc = $base . 'app.php?id=' . rawurlencode($app['id']);
    $t = isset($app['updated_at']) ? (int)$app['updated_at'] : (int)$app['created_at'];
    if ($ans_m > $t && kapp_answers_of($app['id'])) { $t = $ans_m; }
    $mod = date('Y-m-d', $t);
    echo "  <url><loc>" . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . "</loc>"
       . "<lastmod>{$mod}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
}
// AI・IT・DXニュース（2026-11-07）。lastmod はデータを作り直した日・日ごとのページはその日
$nw = @json_decode((string)@file_get_contents(__DIR__ . '/kapp_data/news.json'), true);
if ($nw && !empty($nw['items'])) {
    $nmod = substr((string)$nw['generated_at'], 0, 10);
    foreach (array('news', 'news-ai', 'news-security', 'news-dx', 'news-bousai') as $nu) {
        echo "  <url><loc>{$base}{$nu}</loc><lastmod>{$nmod}</lastmod><changefreq>daily</changefreq><priority>0.8</priority></url>\n";
    }
    $days = array(); foreach ($nw['items'] as $x) { $days[$x['day']] = 1; }
    foreach (array_keys($days) as $dd) {
        echo "  <url><loc>{$base}news-{$dd}</loc><lastmod>{$dd}</lastmod><changefreq>monthly</changefreq><priority>0.5</priority></url>\n";
    }
}
// 業務の制度ニュース（2026-11-07）
$sd = @json_decode((string)@file_get_contents(__DIR__ . '/kapp_data/seido.json'), true);
if ($sd && !empty($sd['items'])) {
    $smod = substr((string)$sd['generated_at'], 0, 10);
    foreach (array('seido', 'seido-roumu', 'seido-joho', 'seido-shien', 'seido-torihiki', 'seido-tetsuzuki') as $su) {
        echo "  <url><loc>{$base}{$su}</loc><lastmod>{$smod}</lastmod><changefreq>daily</changefreq><priority>0.8</priority></url>\n";
    }
}
echo '</urlset>';
