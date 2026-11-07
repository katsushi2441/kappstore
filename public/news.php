<?php
/**
 * Kurage App Store — AI・IT・DX・防災ニュース（/news・/news-<話題>・/news-<日付>。.htaccess で news.php へ）。2026-11-07。
 *
 * ChatGPT などのAI検索が店を読みに来ないので、更新の多い、店に合った入口として作る。
 * 時事ニュース全部ではなく、AI・セキュリティ・DXとクラウド・防災の4つの話題だけにして、
 * 話題ごとに「会社が確かめること」と「使える当社のアプリ」を出す（ニュースを読んで終わりにしない）。
 * データは scripts/build_news_data.py が kapp_data/news.json に書く（元は exbridge.jp/news/ と同じニュースの見出し）。
 * 見出しは各媒体の投稿の見出しをそのまま出し、元記事へリンクする。本文の要約はしない。
 */
require_once __DIR__ . '/kapp_boot.php';
$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
$d = isset($_GET['d']) ? (string)$_GET['d'] : '';
kapp_handle_auth_links('news.php' . ($t !== '' ? '?t=' . rawurlencode($t) : ($d !== '' ? '?d=' . rawurlencode($d) : '')));

$BASE = 'https://kappstore.exbridge.jp/';
$TOPICS = array(
    'ai' => array(
        'name' => 'AI', 'title' => 'AIニュース｜会社がAIを使うときに確かめること',
        'lead' => '生成AI・AIエージェント・AIの規制のニュースです。会社でAIを使うときに先に決めておくことと、自社のサーバーで使えるAIの業務システムを並べます。',
        'check' => array(
            '社内でAIを使うときの決まり（入力してはいけない情報、使ってよいツール）を文書にする',
            '使っているAIツールの契約で、入力したデータが学習に使われるか、どこに保存されるかを確かめる',
            '問い合わせ対応・書類の読み取り・議事録など、効果の見えやすい業務1つから小さく試す',
        ),
        'apps' => array('224e141f77bd07a8', 'ef52a62e1c6bbe7a', 'cd1eda3248c87920', 'cfb7c4a69621600f', '6e3c38c7a92a5da8'),
    ),
    'security' => array(
        'name' => 'セキュリティ', 'title' => 'セキュリティニュース｜不正アクセス・情報漏えいのあとに会社が確かめること',
        'lead' => '不正アクセス・情報漏えい・ランサムウェア・脆弱性のニュースです。よそで起きた事故を自社の点検に変えるための確認項目と、守りに使える当社のシステムを並べます。',
        'check' => array(
            '使っているソフト・サーバー・ホームページの部品（PHP・WordPressなど）に更新が出ていないか確かめ、当てる',
            '取引先・委託先の事故が自社に及ばないか（共有しているアカウント、預けているデータ）を洗い出す',
            'ホームページが外から見てどう守られているか、改ざんされていないかを定期的に点検する',
        ),
        'apps' => array('a96e95e176b48800', '9dd7d5be638f9cb8', '4003999985c45854', '719429354f079793'),
    ),
    'dx' => array(
        'name' => 'DX・クラウド', 'title' => 'DX・クラウドニュース｜SaaSとシステムの見直しで会社が確かめること',
        'lead' => 'DX・クラウド・SaaS・基幹システムの刷新・データセンターのニュースです。毎月の利用料や、紙とFAXの業務を見直すときの確認項目と、買い切りで使える当社の業務システムを並べます。',
        'check' => array(
            '毎月払っているSaaSの費用を人数×月額で合計し、やめたときにデータを持ち出せるかを確かめる',
            '紙・FAX・電話で回している業務を1つ選び、WEBの受付やデータの入力に置き換えられないかを見る',
            'システムが止まったときの代わりの手順（手書き・電話・別の端末）を決めておく',
        ),
        'apps' => array('88acc4be142bd444', '63000166082c7a26', 'c1864cba4ab726b0', 'f0f56c6e4da881be', '15abb025dc2ee4f6'),
    ),
    // 防災（2026-11-08 ユーザー「大雨とか地震とか、ニュースに関連づくプロダクトあるからね」）
    'bousai' => array(
        'name' => '防災', 'title' => '防災ニュース｜地震・大雨・台風のあとに会社が確かめること',
        'lead' => '地震・津波・大雨・台風・土砂災害・停電・避難のニュースです。よそで起きた災害を自社の備えに変えるための確認項目と、住所から危険と避難先を調べられる当社の防災システムを並べます。',
        'check' => array(
            '従業員と家族の安否を確かめる手順（連絡の方法・集まる場所）を決め、連絡先の一覧を紙でも持っておく',
            '事業所・倉庫・取引先の所在地が、洪水・土砂・津波の区域にかかっていないかをハザードマップで確かめる',
            '被災したときに使える国の支援（罹災証明書・助成金・融資など）と、申請に要る書類を先に調べておく',
        ),
        'apps' => array('cf817fdce13ee120', '162f155897390072', '51649180bea0fd57', '41a09acc163dcb7d', 'a2ddb1f2961a74d8', '01d71e3bd7f717a8'),
    ),
);

$data = @json_decode((string)@file_get_contents(__DIR__ . '/kapp_data/news.json'), true);
$items = ($data && isset($data['items'])) ? $data['items'] : array();
$by = array(); foreach (kapp_apps_published() as $a) { $by[$a['id']] = $a; }

function kn_not_found() {
    http_response_code(404);
    kapp_head('見つかりません | Kurage App Store', 'お探しのページは見つかりませんでした。', 'https://kappstore.exbridge.jp/news', true);
    kapp_header('AI・IT・DX・防災ニュース', $GLOBALS['logged_in'], $GLOBALS['user'], $GLOBALS['is_seller'], $GLOBALS['is_admin']);
    echo '<main class="wrap narrow"><p class="empty-note">お探しのページは見つかりませんでした。<br><a href="news">AI・IT・DX・防災ニュースへ</a></p></main>';
    kapp_footer();
    exit;
}

if ($t !== '' && !isset($TOPICS[$t])) { kn_not_found(); }
if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { kn_not_found(); }

if ($t !== '') {
    $list = array_values(array_filter($items, function ($x) use ($t) { return in_array($t, $x['topics'], true); }));
    $tp = $TOPICS[$t];
    $title = $tp['title'] . '｜Kurage App Store';
    $desc = $tp['lead'];
    $canonical = $BASE . 'news-' . $t;
    $h1 = $tp['name'] . 'のニュース';
    $crumb = $tp['name'];
} elseif ($d !== '') {
    $list = array_values(array_filter($items, function ($x) use ($d) { return $x['day'] === $d; }));
    if (!$list) { kn_not_found(); }
    $ts = strtotime($d);
    $dj = date('Y年n月j日', $ts);
    $title = $dj . 'のAI・IT・DX・防災ニュース｜Kurage App Store';
    $desc = $dj . 'に報じられた、AI・セキュリティ・DXとクラウド・防災のニュース' . count($list) . '件と、会社が確かめること。';
    $canonical = $BASE . 'news-' . $d;
    $h1 = $dj . 'のAI・IT・DX・防災ニュース';
    $crumb = $dj;
} else {
    $list = array_slice($items, 0, 60);
    $title = 'AI・IT・DX・防災ニュース｜会社が確かめることと使える業務システム｜Kurage App Store';
    $desc = 'AI・セキュリティ・DXとクラウド・防災のニュースを毎日まとめ、話題ごとに会社が確かめることと、自社のサーバーで使える当社の業務システム（買い切り・月額なし）を並べます。';
    $canonical = $BASE . 'news';
    $h1 = 'AI・IT・DX・防災ニュース';
    $crumb = '';
}
$updated = $data && isset($data['generated_at']) ? substr($data['generated_at'], 0, 10) : date('Y-m-d');
$ogslug = $t !== '' ? 'news-' . $t : 'news';
$answer = 'Kurage App Store の「AI・IT・DX・防災ニュース」とは、AI・セキュリティ・DXとクラウド・防災（地震・大雨・台風など）についての報道を毎日まとめ、話題ごとに会社が確かめることと、自社のサーバーで使える当社の業務システム（買い切り・月額なし・ソースコード付き）を並べるページです。直近60日で' . count($items) . '件を載せています。';
if ($t !== '') { $answer = $TOPICS[$t]['name'] . 'のニュースを受けて会社が最初に確かめることは、' . implode('、', array_map(function ($v) { return rtrim($v, '。'); }, $TOPICS[$t]['check'])) . 'の3つです。直近60日の' . $TOPICS[$t]['name'] . 'のニュースは' . count($list) . '件です。'; }
$faq = array(
    array('AI・IT・DX・防災ニュースとは何ですか？', 'Kurage App Store（株式会社エクスブリッジ）が、AI・セキュリティ・DXとクラウド・防災（地震・大雨・台風など）についての報道を毎日まとめ、話題ごとに会社が確かめることと、使える当社の業務システムを並べているページです。'),
    array('どのニュースを載せていますか？', '報道各社が X に投稿したニュースの見出しを、見出しの語でAI・セキュリティ・DXとクラウド・防災に分けて載せ、元の記事へリンクしています。本文の要約や評価はしていません。'),
    array('紹介している業務システムはどう使えますか？', '自社のサーバー（レンタルサーバーなど）に置いて使う買い切りの業務システムです。月額の利用料はかからず、ソースコードが付いています。価格は各商品のページに載っています。'),
);
if ($t !== '') { array_unshift($faq, array($TOPICS[$t]['name'] . 'のニュースを受けて、会社は何を確かめればよいですか？', implode('。', array_map(function ($v) { return rtrim($v, '。'); }, $TOPICS[$t]['check'])) . '。')); }

$ld = array(
    array('@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'url' => $canonical, 'description' => $desc,
          'inLanguage' => 'ja', 'dateModified' => $updated,
          'isPartOf' => array('@type' => 'WebSite', 'name' => 'Kurage App Store', 'url' => $BASE),
          'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/')),
    array('@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $h1, 'numberOfItems' => count($list),
          'itemListElement' => array_map(function ($x, $i) {
              return array('@type' => 'ListItem', 'position' => $i + 1, 'name' => $x['headline'], 'url' => $x['url'] !== '' ? $x['url'] : ($x['ex'] !== '' ? $x['ex'] : 'https://kappstore.exbridge.jp/news'));
          }, array_slice($list, 0, 50), array_keys(array_slice($list, 0, 50)))),
    array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_values(array_filter(array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => 'Kurage App Store', 'item' => $BASE),
        array('@type' => 'ListItem', 'position' => 2, 'name' => 'AI・IT・DX・防災ニュース', 'item' => $BASE . 'news'),
        $crumb !== '' ? array('@type' => 'ListItem', 'position' => 3, 'name' => $crumb, 'item' => $canonical) : null)))),
);
$ld[] = array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($qa) {
    return array('@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $qa[1]));
}, $faq));
kapp_head($title, $desc, $canonical, false, json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $BASE . 'assets/og/' . $ogslug . '.png?v=' . (int)@filemtime(__DIR__ . '/assets/og/' . $ogslug . '.png'));  // 画像を作り直したら URL も変わるように（X は同じ URL のカードを覚えて古いまま出す）
kapp_header('AI・IT・DX・防災ニュース', $logged_in, $user, $is_seller, $is_admin);

function kn_topic_label($k, $TOPICS) { return isset($TOPICS[$k]) ? $TOPICS[$k]['name'] : $k; }
function kn_app_cards($ids, $by, $ref) {
    $o = '<div class="grid">';
    foreach ($ids as $id) {
        if (!isset($by[$id])) { continue; }
        $app = $by[$id]; $p = kapp_price_parts($app['price']);
        $o .= '<article class="item"><div class="body"><h3><a href="app.php?id=' . kapp_h($id) . '&amp;ref=' . kapp_h($ref) . '">' . kapp_h($app['name']) . '</a></h3>'
            . '<p class="sum">' . kapp_h(mb_strimwidth($app['summary'], 0, 110, '…', 'UTF-8')) . '</p>'
            . '<div class="foot">' . ($p['total'] > 0 ? '<span class="yen">' . number_format($p['total']) . '円<small>税込</small></span>' : '') . '</div></div></article>';
    }
    return $o . '</div>';
}
?>
<main class="wrap">
<section>
  <p style="font-size:12.5px"><a href="index.php">Kurage App Store</a> › <?php echo $crumb !== '' ? '<a href="news">AI・IT・DX・防災ニュース</a> › ' . kapp_h($crumb) : 'AI・IT・DX・防災ニュース'; ?></p>
  <h1><?php echo kapp_h($h1); ?></h1>
  <?php echo kapp_news_share_box('Kurage App Store｜' . $h1, $t !== '' ? $TOPICS[$t]['lead'] : $desc, $canonical, $t !== '' ? 'news-' . $t : ($d !== '' ? 'news-day' : 'news')); ?>
  <?php if ($d === ''): ?><p style="font-size:15px;line-height:1.85;overflow-wrap:anywhere;margin:0 0 6px"><?php echo kapp_h($answer); ?></p><?php endif; ?>
  <p class="lead" style="overflow-wrap:anywhere"><?php echo kapp_h($t !== '' ? $TOPICS[$t]['lead'] : $desc); ?>（更新 <?php echo kapp_h($updated); ?>）</p>
  <p style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn ghost" style="padding:6px 14px;font-size:13px" href="news">すべて</a>
    <?php foreach ($TOPICS as $k => $tp2): ?>
      <a class="btn ghost" style="padding:6px 14px;font-size:13px<?php echo $k === $t ? ';background:var(--foam)' : ''; ?>" href="news-<?php echo kapp_h($k); ?>"><?php echo kapp_h($tp2['name']); ?></a>
    <?php endforeach; ?>
  </p>
</section>

<?php if ($t !== ''): $tp = $TOPICS[$t]; ?>
<section style="max-width:760px;margin:0 auto 20px;background:var(--foam);border:1px solid var(--panel-line);border-radius:16px;padding:16px 20px">
  <h2 style="font-size:18px;margin:0 0 6px"><?php echo kapp_h($tp['name']); ?>のニュースを受けて、会社が確かめること</h2>
  <ol style="margin:0;padding-left:20px;line-height:1.8">
  <?php foreach ($tp['check'] as $c): ?><li><?php echo kapp_h($c); ?></li><?php endforeach; ?>
  </ol>
</section>
<section>
  <h2 style="font-size:18px">この話題で使える当社の業務システム（自社のサーバー・買い切り・月額なし）</h2>
  <?php echo kn_app_cards($tp['apps'], $by, 'news-' . $t); ?>
</section>
<?php endif; ?>

<section>
  <h2 style="font-size:18px"><?php echo $t !== '' ? kapp_h($TOPICS[$t]['name']) . 'のニュース（' . count($list) . '件）' : ($d !== '' ? 'この日のニュース（' . count($list) . '件）' : '新しいニュース'); ?></h2>
  <?php $cur = ''; foreach ($list as $x): if ($d === '' && $x['day'] !== $cur): $cur = $x['day']; ?>
    <h3 style="font-size:14px;margin:16px 0 6px;color:var(--muted, #5b6b70)"><a href="news-<?php echo kapp_h($x['day']); ?>"><?php echo kapp_h(date('Y年n月j日', strtotime($x['day']))); ?></a></h3>
  <?php endif; ?>
    <article style="border-top:1px solid var(--panel-line);padding:10px 0;overflow-wrap:anywhere">
      <div style="font-size:12px;color:#5b6b70"><?php echo kapp_h($x['time'] . '　' . $x['source']); ?>　<?php foreach ($x['topics'] as $k): ?><a href="news-<?php echo kapp_h($k); ?>" style="font-size:11.5px;border:1px solid var(--panel-line);border-radius:99px;padding:1px 8px;margin-right:4px;text-decoration:none"><?php echo kapp_h(kn_topic_label($k, $TOPICS)); ?></a><?php endforeach; ?></div>
      <div style="font-size:15px;font-weight:700;line-height:1.6;margin:3px 0"><?php if ($x['url'] !== ''): ?><a href="<?php echo kapp_h($x['url']); ?>" target="_blank" rel="noopener nofollow"><?php echo kapp_h($x['headline']); ?></a><?php else: echo kapp_h($x['headline']); endif; ?></div>
      <?php if ($x['ex'] !== ''): ?><div style="font-size:12.5px"><a href="<?php echo kapp_h($x['ex']); ?>">このニュースに関係する国会の記録</a></div><?php endif; ?>
      <?php echo kapp_news_app_line(isset($x['apps']) ? $x['apps'] : array(), $by, 'news-item'); ?>
    </article>
  <?php endforeach; ?>
  <?php if (!$list): ?><p class="empty-note">まだありません。</p><?php endif; ?>
</section>

<?php if ($t === ''): ?>
<section>
  <h2 style="font-size:18px">話題ごとに、会社が確かめることと使える業務システム</h2>
  <div class="grid">
  <?php foreach ($TOPICS as $k => $tp2): ?>
    <article class="item"><div class="body"><h3><a href="news-<?php echo kapp_h($k); ?>"><?php echo kapp_h($tp2['name']); ?>のニュース</a></h3><p class="sum"><?php echo kapp_h($tp2['check'][0]); ?>…</p></div></article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section style="max-width:760px;margin:24px auto 0;background:var(--foam);border:1px solid var(--panel-line);border-radius:16px;padding:18px 20px">
  <h2 style="font-size:18px;margin:0 0 6px">よくある質問</h2>
  <?php foreach ($faq as $qa): ?>
    <div style="border-top:1px solid var(--panel-line);padding:11px 0"><h3 style="font-size:15px;font-weight:800;margin:0"><?php echo kapp_h($qa[0]); ?></h3><p style="margin:6px 0 0;line-height:1.7;overflow-wrap:anywhere"><?php echo kapp_h($qa[1]); ?></p></div>
  <?php endforeach; ?>
</section>

<section style="max-width:760px;margin:24px auto 0;font-size:13px;color:#5b6b70;line-height:1.8">
  <p>見出しは各報道機関の X の投稿の見出しをそのまま載せ、元の記事へリンクしています。本文の要約や評価はしていません。話題の分け方は見出しの語による機械的なものです。運営：株式会社エクスブリッジ（Kurage App Store）。</p>
</section>
</main>
<?php kapp_footer(); ?>
