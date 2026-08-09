<?php
/**
 * AIエージェント向けの llms.txt。/llms.txt で配信される(.htaccessでrewrite)。
 * 商品一覧は台帳から動的生成なので、出品が増えても手入れ不要。
 */
require_once __DIR__ . '/kapp_lib.php';

header('Content-Type: text/plain; charset=utf-8');

$base = 'https://kappstore.exbridge.jp/';
$lines = array();
$lines[] = '# Kurage App Store';
$lines[] = '';
$lines[] = '> AIエージェントが見つけて、導入し、AIエージェントで育てる業務システムのダウンロードストア。'
    . '全商品にClaude Code等のAIエージェントが読める設計マニュアルと、MITライセンスのソースコードを同梱。'
    . 'コードが読めなくても、AIに頼んで自分の業務に合わせて改変・拡張できます。';
$lines[] = '';
$lines[] = '## Products';
$lines[] = '';
foreach (kapp_apps_published() as $app) {
    $url = $base . 'app.php?id=' . rawurlencode($app['id']);
    $summary = trim(preg_replace('/\s+/', ' ', isset($app['summary']) ? $app['summary'] : ''));
    $p = kapp_price_parts(isset($app['price']) ? $app['price'] : 0);
    $lines[] = '- [' . $app['name'] . '](' . $url . '): ' . $summary
        . '（税込' . number_format($p['total']) . '円）';
}
$lines[] = '';
$lines[] = '## For AI Agents';
$lines[] = '';
$lines[] = '- [Machine-readable catalog](' . $base . 'catalog.json): 全商品と買い方・導入方法をまとめたJSONカタログ。';
$lines[] = '- 決済は人間が行います(PayPal/銀行振込)。購入後の設置・改変は、同梱の設計マニュアルを読ませたAIエージェントで行えます。';
$lines[] = '- すべてMIT License・PHPレンタルサーバーで動作(DB/Composer/npm不要)・購入前にデモを操作できます。';
$lines[] = '';
$lines[] = '## Optional';
$lines[] = '';
$lines[] = '- [Sitemap](' . $base . 'sitemap.xml)';
$lines[] = '- [出品者向けページ](' . $base . 'sellers.php)';
$lines[] = '- [Kurage Project](https://kurage.exbridge.jp/llms.txt)';

echo implode("\n", $lines) . "\n";
