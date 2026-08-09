<?php
/**
 * AIエージェント向けの機械可読カタログ。/catalog.json で配信される(.htaccessでrewrite)。
 *
 * 「AIエージェントが見つけて、導入し、AIエージェントで育てる業務システム」の
 * 入口。HTMLを1ページずつ読まなくても、ここ1回で全商品と買い方・導入方法が分かる。
 * 事実に無いこと(自律決済など)は書かない — 決済は人間が行う。
 */
require_once __DIR__ . '/kapp_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Robots-Tag: noindex');  // 検索結果にJSONを出さない(発見はllms.txt/sitemap側で)

$base = 'https://kappstore.exbridge.jp/';
$products = array();
foreach (kapp_apps_published() as $app) {
    $p = kapp_price_parts(isset($app['price']) ? $app['price'] : 0);
    $products[] = array(
        'id'          => $app['id'],
        'name'        => $app['name'],
        'url'         => $base . 'app.php?id=' . rawurlencode($app['id']),
        'summary'     => isset($app['summary']) ? $app['summary'] : '',
        'image'       => !empty($app['image']) ? $base . 'kapp_media/' . rawurlencode($app['image']) : null,
        'price_jpy'   => $p['total'],
        'price_note'  => '税込(本体' . number_format($p['amount']) . '円+消費税)',
        'demo_url'    => !empty($app['demo_url']) ? $app['demo_url'] : null,
        'license'     => 'MIT',
        'includes'    => array('source_code', 'claude_code_manual', 'install_guide'),
        'requires'    => 'PHP rental server (no DB, no Composer, no npm)',
        'published_at'=> date('c', isset($app['created_at']) ? (int)$app['created_at'] : 0),
        'updated_at'  => date('c', isset($app['updated_at']) ? (int)$app['updated_at']
                                   : (isset($app['created_at']) ? (int)$app['created_at'] : 0)),
    );
}

echo json_encode(array(
    'name'    => 'Kurage App Store',
    'url'     => $base,
    'concept' => 'AIエージェントが見つけて、導入し、AIエージェントで育てる業務システムのダウンロードストア。',
    'description' => '業務システムのプロトタイプを販売。全商品にClaude Code等のAIエージェントが読める'
        . '設計マニュアルとMITライセンスのソースコードを同梱。コードが読めなくても、AIに頼んで'
        . '自分の業務に合わせて改変・拡張できる。',
    'updated' => date('c'),
    'for_ai_agents' => array(
        'discover' => '全商品はこの catalog.json と sitemap.xml に載っています。各商品ページには Product 型のJSON-LDがあります。',
        'purchase' => '決済は人間が行います(PayPal または銀行振込)。各商品ページの購入ボタンから注文してください。エージェント向けの自律決済レールは未提供です。',
        'install'  => '購入するとソースコード一式と、Claude Code等が読める設計マニュアル(仕組み・データの持ち方・拡張の手引き)が届きます。AIエージェントに渡せば設置まで進みます。',
        'grow'     => 'すべてMIT License。項目追加・画面変更・機能拡張は、同梱マニュアルを読ませたAIエージェントとの対話で行えます。1,000〜2,000行に収めた「全部読めるサイズ」です。',
    ),
    'payment_methods' => array('paypal', 'bank_transfer'),
    'product_count' => count($products),
    'products' => $products,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
