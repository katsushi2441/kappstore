<?php
/**
 * Kurage App Store — 共通のガワ（head / ヘッダー / フッター / CSS）。
 * 配色とコンポーネントは kurage.exbridge.jp の既存ページから継承する。
 * 画面ごとにCSSを複製すると、直したつもりが1画面だけ古いままになる。
 */

/**
 * @param string $jsonld 構造化データ(JSON-LD)。検索結果での見え方に効くので、
 *                       トップと商品ページでは入れる。
 * @param string $image  OGP画像のURL。商品ページでは必ず商品画像を渡すこと。
 *                       ここを共通画像のままにすると、出品者が自分の商品を
 *                       SNSで紹介しても店のロゴしか出ず、宣伝にならない。
 */
function kapp_head($title, $description, $canonical, $noindex = false, $jsonld = '', $image = '') {
    $t = kapp_h($title);
    $d = kapp_h($description);
    $c = kapp_h($canonical);
    $img = kapp_h($image !== '' ? $image : 'https://kappstore.exbridge.jp/assets/ogp.png');
    $robots = $noindex ? 'noindex, follow' : 'index, follow';
    echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t}</title>
<meta name="description" content="{$d}">
<meta name="robots" content="{$robots}">
<link rel="canonical" href="{$c}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Kurage App Store">
<meta property="og:title" content="{$t}">
<meta property="og:description" content="{$d}">
<meta property="og:url" content="{$c}">
<meta property="og:image" content="{$img}">
<meta name="twitter:card" content="summary_large_image">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{
  --abyss:#12202f; --abyss-soft:#55697a; --foam:#f5fbfb; --panel:#e7f3f2; --panel-line:#cde5e2;
  --teal:#12a99f; --teal-deep:#0a726b; --gold:#c98a1e; --gold-bg:#fbf2db; --gold-line:#ecd9a8;
  --shadow:0 14px 40px rgba(10,40,45,.10);
}
/* ダークテーマは使わない（サイト全体でライト固定）。端末の設定で色が変わると
   スクリーンショットや資料と食い違い、確認ができなくなるため。 */
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--abyss);background:var(--foam);line-height:1.9;
  font-family:"Zen Kaku Gothic New","Hiragino Sans","Yu Gothic",Meiryo,sans-serif}
a{color:var(--teal-deep);text-decoration:none}
a:hover{text-decoration:underline}
img{max-width:100%}
h1,h2,h3{font-family:"Zen Maru Gothic","Zen Kaku Gothic New",sans-serif}
.wrap{max-width:1000px;margin:0 auto;padding:0 22px}
.wrap.narrow{max-width:820px}
header.site{border-bottom:1px solid var(--panel-line);background:var(--panel)}
header.site .wrap{display:flex;align-items:center;gap:12px;padding:12px 22px;flex-wrap:wrap}
.hbrand{display:flex;gap:11px;align-items:center;color:inherit}
.hbrand:hover{text-decoration:none}
.hbrand .ico{width:38px;height:38px;border-radius:50%;overflow:hidden;border:2px solid var(--teal);flex:none}
.hbrand .ico img{width:100%;height:100%;object-fit:cover;display:block}
.hbrand strong{font-size:14.5px;font-weight:900;display:block;line-height:1.25}
.hbrand span{font-size:11px;color:var(--abyss-soft)}
.hnav{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
/* ダッシュボードの2段目。上段より一段落ち着いた見た目にして、
   店のヘッダーと作業用のメニューを視覚的に分ける */
.subnav{background:var(--foam);border-bottom:1px solid var(--panel-line)}
.subnav .wrap{display:flex;gap:4px;padding:0 22px;flex-wrap:wrap;overflow-x:auto}
.subnav a.sub{font-size:13px;font-weight:700;color:var(--abyss-soft);padding:13px 14px;
  border-bottom:3px solid transparent;white-space:nowrap}
.subnav a.sub:hover{color:var(--abyss);text-decoration:none}
.subnav a.sub.on{color:var(--teal-deep);border-bottom-color:var(--teal)}
.subnav a.sub b{display:inline-block;min-width:18px;padding:1px 5px;margin-left:3px;border-radius:9px;
  background:var(--gold);color:#fff;font-size:11px;text-align:center;line-height:1.5}
.chip{font-size:12px;font-weight:700;color:var(--abyss-soft);border:1px solid var(--panel-line);
  border-radius:999px;padding:4px 12px;background:var(--foam)}
a.chip:hover{text-decoration:none;border-color:var(--teal);color:var(--teal-deep)}
.btn{border:0;border-radius:999px;padding:11px 22px;font-weight:900;font-size:13.5px;cursor:pointer;
  display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,var(--teal),var(--teal-deep));
  color:#fff;box-shadow:0 10px 24px rgba(18,169,159,.28);font-family:inherit}
.btn:hover{color:#fff;text-decoration:none}
.btn.ghost{background:transparent;color:var(--abyss-soft);border:1.5px solid var(--panel-line);box-shadow:none}
.btn:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
section{padding:30px 0}
h1{font-size:clamp(22px,3.6vw,30px);font-weight:900;line-height:1.35;margin-bottom:10px}
h2{font-size:19px;font-weight:900;margin-bottom:8px}
h3{font-size:15px;font-weight:900;margin-bottom:6px}
.lead{font-size:14.5px;color:var(--abyss-soft);margin-bottom:20px}
.concept{font-size:15.5px;color:var(--jelly,#0b7285);font-weight:500;margin:-2px 0 14px}
.agent-strip{font-size:12px;color:var(--abyss-soft);line-height:1.9;margin:-10px 0 26px;
  padding:12px 16px;border:1.5px dashed var(--panel-line,#d7e5e5);border-radius:12px;background:#fbfdfd}
.pillars{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:6px 0 26px}
@media(max-width:720px){.pillars{grid-template-columns:1fr}}
.pillar{background:var(--panel,#eef6f5);border:1.5px solid var(--panel-line,#d7e5e5);border-radius:14px;padding:16px 18px}
.pillar>b{display:block;font-size:14.5px;margin-bottom:6px}
.pillar>span{font-size:12.5px;color:var(--abyss-soft);line-height:1.85}
.card{background:var(--panel);border:1.5px solid var(--panel-line);border-radius:18px;
  padding:26px;box-shadow:var(--shadow);margin-bottom:18px}
.card.plain{background:var(--foam)}
.gate{background:var(--gold-bg);border:1.5px solid var(--gold-line);border-radius:18px;padding:24px;margin-bottom:18px}
.price{font-size:clamp(26px,4.4vw,36px);font-weight:900;font-family:"Zen Maru Gothic",sans-serif}
.price small{font-size:14px;font-weight:700;color:var(--abyss-soft);margin-left:8px}
ul{padding-left:22px;font-size:14px}
li{margin-bottom:8px}
label{display:block;font-size:13.5px;font-weight:700;margin:16px 0 6px}
input[type=text],input[type=url],input[type=email],input[type=tel],input[type=number],textarea,select{width:100%;font:inherit;font-size:15px;
  color:inherit;background:var(--foam);border:1.5px solid var(--panel-line);border-radius:10px;padding:11px 13px}
textarea{min-height:130px;line-height:1.8;resize:vertical}
input:focus,textarea:focus,select:focus{outline:2px solid var(--teal);border-color:var(--teal)}
input[type=file]{font-size:13px;margin-top:6px}
.hint{font-size:12px;color:var(--abyss-soft);margin-top:5px}
.err{background:#fdecea;border:1.5px solid #f5c6c2;color:#a3261b;border-radius:10px;
  padding:12px 14px;font-size:13.5px;margin-bottom:16px}

.ok{background:var(--gold-bg);border:1.5px solid var(--gold-line);border-radius:12px;padding:14px 16px;font-size:13.5px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(268px,1fr));gap:18px}
.item{background:var(--panel);border:1.5px solid var(--panel-line);border-radius:16px;overflow:hidden;
  display:flex;flex-direction:column;box-shadow:var(--shadow)}
.item:hover{border-color:var(--teal)}
.item .thumb{aspect-ratio:16/9;background:var(--foam);overflow:hidden;border-bottom:1px solid var(--panel-line)}
.item .thumb img{width:100%;height:100%;object-fit:cover;object-position:center;display:block}
.item .thumb.empty{display:flex;align-items:center;justify-content:center;color:var(--abyss-soft);font-size:34px}
.item .body{padding:15px 17px 17px;display:flex;flex-direction:column;gap:6px;flex:1}
.item .body h3{font-size:15.5px;margin:0;line-height:1.45}
.item .body h3 a{color:inherit}
.item .sum{font-size:12.8px;color:var(--abyss-soft);line-height:1.75;flex:1}
.item .foot{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:6px}
.item .yen{font-weight:900;font-family:"Zen Maru Gothic",sans-serif;font-size:17px}
.item .yen small{font-size:11px;font-weight:700;color:var(--abyss-soft);margin-left:3px}
.tag{font-size:11px;font-weight:800;border-radius:999px;padding:2px 10px;border:1.5px solid var(--panel-line);color:var(--abyss-soft)}
.tag.paid,.tag.free{border-color:var(--teal);color:var(--teal-deep)}
.tag.draft{border-color:var(--gold-line);color:var(--gold)}
table.kv{width:100%;border-collapse:collapse;font-size:13.5px;margin-top:10px}
table.kv th,table.kv td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--panel-line);vertical-align:top}
table.kv td{overflow-wrap:anywhere}
table.kv th{width:32%;color:var(--abyss-soft);font-size:12px;white-space:nowrap}
.row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--panel-line);padding:12px 2px}
/* 明細表。スマホでは横スクロールさせる（列を減らすと突き合わせができなくなる） */
.scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
table.kv{border-collapse:collapse;font-size:13.5px;min-width:520px}
table.kv th,table.kv td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--panel-line);vertical-align:top}
table.kv th{color:var(--abyss-soft);font-size:12px;white-space:nowrap;font-weight:700}
.row:first-child{border-top:0}
.row .grow{flex:1;min-width:180px}
.empty-note{text-align:center;color:var(--abyss-soft);font-size:14px;padding:50px 20px}
footer.site{text-align:center;color:var(--abyss-soft);font-size:12.5px;padding:36px 20px 48px;
  border-top:1px solid var(--panel-line);margin-top:20px;line-height:2.2}

/* 商品説明のMarkdown描画（kapp_md）。見出しはページのh2/h3より控えめにする。 */
.md-body{font-size:14.5px;overflow-wrap:anywhere;margin-top:12px;line-height:1.85}
.md-body .md-p{margin:10px 0}
.md-body .md-h{margin:22px 0 8px;font-size:16.5px;font-weight:800;padding-left:10px;border-left:4px solid #0a9a8f}
.md-body h5.md-h{font-size:15.5px}
.md-body h6.md-h{font-size:15px}
.md-body .md-ul,.md-body .md-ol{margin:8px 0;padding-left:22px}
.md-body .md-ul li,.md-body .md-ol li{margin:4px 0}
.md-body .md-q{margin:10px 0;padding:8px 14px;border-left:4px solid #cfe0e4;background:#f6fafb;color:#4a5b6a}
.md-body .md-hr{border:0;border-top:1px solid #e3eaee;margin:18px 0}
.md-body code{background:#f1f5f7;border-radius:4px;padding:1px 5px;font-size:13px}
.md-table-wrap{overflow-x:auto;margin:12px 0}
.md-table{width:100%;border-collapse:collapse;font-size:13.5px}
.md-table th,.md-table td{border:1px solid #e3eaee;padding:7px 10px;text-align:left;vertical-align:top}
.md-table th{background:#f5f9fa;font-weight:700;white-space:nowrap}
</style>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<?php /* 計測タグは自ドメインの simpletrack.php を叩く。kurage.exbridge.jp のものを
       貼るとkappstoreの流入がkurage側のログに混ざる（実際に混ざっていた）。 */ ?>
<script>(function(){var s=document.createElement('script');s.src='https://kappstore.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
</head>
<body>
HTML;
    // 構造化データは heredoc の外で出す（JSONの波括弧が変数展開と衝突するため）
    if ($jsonld !== '') {
        echo '<script type="application/ld+json">' . $jsonld . '</script>' . "\n";
    }
}

function kapp_header($subtitle, $logged_in, $user, $is_seller = false, $is_admin = false) {
    echo '<header class="site"><div class="wrap">';
    echo '<a class="hbrand" href="index.php"><span class="ico">'
       . '<img src="assets/kurage-face-192.webp" alt="Kurage"></span>'
       . '<div><strong>Kurage App Store</strong><span>' . kapp_h($subtitle) . '</span></div></a>';
    // 上段はお店として誰にでも要るものだけ。役割ごとの画面は
    // ダッシュボードの下（kapp_subnav）へ寄せる。上段に全部並べると、
    // 買いに来た人にまで管理用のメニューが見えて店に見えなくなる。
    echo '<nav class="hnav">';
    echo '<a class="chip" href="index.php">アプリ一覧</a>';
    echo '<a class="chip" href="sellers.php">開発元一覧</a>';
    if ($logged_in) {
        $badge = kapp_dashboard_badge($user, $is_admin);
        echo '<a class="chip" href="dashboard.php">ダッシュボード'
           . ($badge > 0 ? ' <b style="color:var(--gold)">' . $badge . '</b>' : '') . '</a>';
        echo '<span class="chip">@' . kapp_h($user) . '</span>';
        echo '<a class="chip" href="?logout=1">ログアウト</a>';
    } else {
        echo '<a class="chip" href="?login=1">𝕏 でログイン</a>';
    }
    echo '</nav></div></header>';
}

/**
 * ダッシュボードに出す「手を動かす必要がある件数」。
 *
 * 上段の1箇所にまとめる。押す前に分からないと、審査待ちや入金待ちが
 * 溜まっていることに気づかないまま放置される。
 */
function kapp_dashboard_badge($user, $is_admin) {
    $n = 0;
    if ($is_admin) {
        foreach (kapp_sellers() as $s) {
            if (kapp_seller_status($s) === 'applied') { $n++; }
        }
        foreach (kapp_all_orders() as $o) {
            if ($o['status'] !== 'paid') { $n++; }     // 入金確認待ち
        }
    }
    if (kapp_seller_can_complete($user)) { $n++; }      // 自分の詳細登録が未了
    return $n;
}

/**
 * ダッシュボードの2段目。役割ごとに出すものを変える。
 *
 * $current は今いる画面のファイル名。押せない自分自身を目立たせて、
 * どこにいるか分かるようにする。
 */
function kapp_subnav($current, $user, $is_seller, $is_admin) {
    $items = array();

    if ($is_admin) {
        $waiting = 0;
        foreach (kapp_all_orders() as $o) { if ($o['status'] !== 'paid') { $waiting++; } }
        $items[] = array('admin.php', '注文管理', $waiting);

        $applied = 0;
        foreach (kapp_sellers() as $s) { if (kapp_seller_status($s) === 'applied') { $applied++; } }
        $items[] = array('sellers.php?admin=1', '出品者管理', $applied);
    }
    if ($is_seller) {
        $items[] = array('sales.php', '販売履歴', 0);
        $items[] = array('register.php', '出品する', 0);
    } elseif (kapp_seller_can_complete($user)) {
        // 招待・承認されたが詳細登録が済んでいない人。ここに導線が無いと
        // 案内メールを閉じた時点で戻る道が分からなくなる
        $items[] = array('sellers.php', '出品者登録', 1);
    }
    if ($is_seller || $is_admin) { $items[] = array('payout.php', '精算', 0); }
    $items[] = array('orders.php', '購入履歴', 0);

    echo '<div class="subnav"><div class="wrap">';
    foreach ($items as $it) {
        list($href, $label, $n) = $it;
        $on = (strpos($href, $current) === 0
               && (strpos($current, '?') !== false || strpos($href, '?') === false));
        echo '<a class="' . ($on ? 'sub on' : 'sub') . '" href="' . kapp_h($href) . '">'
           . kapp_h($label)
           . ($n > 0 ? ' <b>' . (int)$n . '</b>' : '') . '</a>';
    }
    echo '</div></div>';
}

function kapp_footer() {
    echo '<footer class="site"><div class="wrap">'
       . 'Kurage App Store — <a href="https://exbridge.jp/">株式会社エクスブリッジ</a><br>'
       . '<a href="https://kurage.exbridge.jp/terms.html">利用規約</a> · '
       . '<a href="https://kurage.exbridge.jp/tokusho.php">特定商取引法に基づく表記</a> · '
       . '<a href="https://kurage.exbridge.jp/reseller.html">販売代理店募集</a> · '
       . '<a href="https://kurage.exbridge.jp/">Kurage シリーズ</a> · '
       . '<a href="https://exbridge.jp/contact.php">お問い合わせ</a>'
       . '</div></footer>'
       . '<a href="https://kurage.exbridge.jp/chat.php" id="kai-fab" aria-label="Kurage.AIに相談"><img src="https://kurage.exbridge.jp/kurage-face-384.webp" alt="Kurage.AI"><span class="kai-txt"><b>Kurage.AI に相談</b><i>AIが何でも答えます</i></span></a>'
       . '<style>#kai-fab{position:fixed;right:16px;bottom:16px;z-index:99999;display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#12a99f,#0a726b);color:#fff;text-decoration:none;border-radius:999px;padding:8px 18px 8px 8px;box-shadow:0 12px 30px rgba(10,80,75,.35);font-family:"Hiragino Sans","Noto Sans JP",sans-serif;animation:kaiPulse 2.6s ease-in-out infinite}#kai-fab img{width:44px;height:44px;border-radius:50%;object-fit:cover;object-position:50% 12%;border:2px solid rgba(255,255,255,.85);background:#fff;flex:none}#kai-fab .kai-txt{display:flex;flex-direction:column;line-height:1.2}#kai-fab .kai-txt b{font-size:14px;font-weight:900}#kai-fab .kai-txt i{font-size:10.5px;font-style:normal;opacity:.92}#kai-fab:hover{transform:translateY(-2px)}@keyframes kaiPulse{0%,100%{box-shadow:0 12px 30px rgba(10,80,75,.35)}50%{box-shadow:0 14px 44px rgba(18,169,159,.6)}}@media(max-width:520px){#kai-fab .kai-txt i{display:none}#kai-fab{padding:7px 15px 7px 7px}#kai-fab img{width:40px;height:40px}}</style>'
       . '</body></html>';
}

/**
 * ログイン/ログアウトの入口。各画面の先頭で1回呼ぶ。
 * OAuth自体は aiknowledgecms.exbridge.jp が受け持つ（Cookieは .exbridge.jp 共有）。
 */
function kapp_handle_auth_links($self) {
    if (isset($_GET['login'])) {
        header('Location: ' . url2ai_auth_login_url('https://kappstore.exbridge.jp/' . $self));
        exit;
    }
    if (isset($_GET['logout'])) {
        header('Location: ' . url2ai_auth_logout_url('https://kappstore.exbridge.jp/' . $self));
        exit;
    }
}
