<?php
/**
 * 出品レコードを組み立てて、本番の台帳へ反映するための下ごしらえ。
 *
 * 出品画面(register.php)はXログインを前提にしているため、手元から自動で
 * 出品するときはこのスクリプトで台帳を作り、FTPで置き換える。
 *
 * 使い方:
 *   php scripts/list_app.php <apps.json> <name> <summary-file> <body-file> \
 *       <demo_url> <price(税抜)> <zip> <image>
 *
 * 出力: 更新後のJSONを標準出力へ。配布ファイル名・画像ファイル名も stderr に出す。
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if ($argc < 9) {
    fwrite(STDERR, "引数が足りません。\n");
    exit(1);
}
list(, $apps_json, $name, $summary_file, $body_file, $demo_url, $price, $zip, $image) = $argv;

foreach (array($apps_json, $summary_file, $body_file, $zip, $image) as $f) {
    if (!file_exists($f)) { fwrite(STDERR, "見つかりません: $f\n"); exit(1); }
}

$data = json_decode((string)file_get_contents($apps_json), true);
if (!is_array($data) || !isset($data['apps'])) {
    fwrite(STDERR, "台帳を読めません: $apps_json\n"); exit(1);
}

// 同じ名前が既にあれば更新、無ければ追加（二重出品を防ぐ）
$now = time();
$stored_zip   = bin2hex(random_bytes(12)) . '.zip';
$stored_image = bin2hex(random_bytes(12)) . '.png';

$app = array(
    'id'        => bin2hex(random_bytes(8)),
    'seller'    => 'xb_bittensor',
    'name'      => $name,
    'summary'   => trim((string)file_get_contents($summary_file)),
    'body'      => trim((string)file_get_contents($body_file)),
    'demo_url'  => $demo_url,
    'price'     => (int)$price,
    'file'      => $stored_zip,
    'filename'  => basename($zip),
    'filesize'  => filesize($zip),
    'image'     => $stored_image,
    'status'    => 'published',
    'created_at'=> $now,
    'updated_at'=> $now,
);

$replaced = false;
foreach ($data['apps'] as $i => $a) {
    if (isset($a['name']) && $a['name'] === $name) {
        $app['id']         = $a['id'];
        $app['created_at'] = $a['created_at'];
        $data['apps'][$i]  = $app;
        $replaced = true;
        break;
    }
}
if (!$replaced) { $data['apps'][] = $app; }

fwrite(STDERR, ($replaced ? "更新" : "追加") . ": {$name}\n");
fwrite(STDERR, "  配布ファイル: {$stored_zip}  ← " . basename($zip) . "\n");
fwrite(STDERR, "  商品画像    : {$stored_image}  ← " . basename($image) . "\n");
fwrite(STDERR, "  価格        : 税抜" . number_format((int)$price)
    . "円 / 税込" . number_format((int)$price + (int)floor($price * 0.1)) . "円\n");

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
