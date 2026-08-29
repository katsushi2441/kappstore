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
$lines[] = '> 買い切りの業務システム、海外オープンソースの日本語導入キット、AI開発ツールのダウンロードストア。'
    . '業務アプリにはClaude Code等のAIエージェントが読める設計マニュアルと、MITライセンスのソースコードを同梱。'
    . '導入キット（EspoCRM・Krayin・FreeScout等）は、無料のOSS本体を共有レンタルサーバーに日本語で立てるための実測手順書＋ツール一式です。'
    . 'AI開発ツール（Kurage Architect等）は、AIと対話してシステム設計書を作るなど開発の前工程を助けるもので、Pythonが動くサーバーと利用者側のLLMが必要です。';
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
$lines[] = '## About';
$lines[] = '';
$lines[] = '業務システムの構築・導入はこれまでエンジニアだけのものでした。Kurage App Store はその敷居を下げる店です。'
    . '売っているのは完成品ではなく、買った会社の業務に合わせて「育てられる土台」——実際に動く業務システムのプロトタイプです。';
$lines[] = '';
$lines[] = '- すべての商品に、Claude Code等のAIエージェントが理解できる設計マニュアル(仕組み・データの持ち方・拡張の手引き)を同梱。'
    . 'コードが読めなくても「この項目を増やして」とAIに頼むだけで自分の業務に合わせて作り替えられます。';
$lines[] = '- コードは1,000〜2,000行程度の「全部読めるサイズ」に意図的に収めています。読めないコードは改変できないからです。';
$lines[] = '- すべてMIT License。商用利用・改変・再配布・再販が自由です。';
$lines[] = '- PHPが動くレンタルサーバーがあれば動きます。データベース・Composer・npm不要、FTPで上げるだけ。';
$lines[] = '- すべての商品にデモがあり、操作してから購入を判断できます。';
$lines[] = '- 販売しているのはプロトタイプで動作保証・サポートはありません。設置もつまずいたときの解決も、AIエージェントに相談しながら進める前提の商品です。';
$lines[] = '- 運営: Kurage Project (https://kurage.exbridge.jp/)。出品希望は sellers.php から。';
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
