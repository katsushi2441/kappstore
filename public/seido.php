<?php
/**
 * Kurage App Store — 業務の制度ニュース（/seido・/seido-<分類>。.htaccess で seido.php へ）。2026-11-07 ユーザー指示。
 *
 * 府省の発表（省庁トラッカー exbridge.jp/shocho/ が毎日取り込む RSS）から、会社の業務に関わるもの
 * （労務・情報管理・補助金と支援・取引・税と手続）だけを並べ、分類ごとに「会社がやること」と
 * 「使える当社のアプリ」を出す。題名は府省の発表の題名のまま、公式ページへリンクする。要約・評価はしない。
 * データは scripts/build_news_data.py が kapp_data/seido.json に書く。
 */
require_once __DIR__ . '/kapp_boot.php';
$c = isset($_GET['c']) ? (string)$_GET['c'] : '';
kapp_handle_auth_links('seido.php' . ($c !== '' ? '?c=' . rawurlencode($c) : ''));

$BASE = 'https://kappstore.exbridge.jp/';
$CATS = array(
    'roumu' => array('name' => '労務', 'title' => '労務の制度ニュース｜最低賃金・年収の壁・労働時間と会社がやること',
        'lead' => '最低賃金・年収の壁・春闘・育休・労働時間など、会社の労務に関わる報道と、厚生労働省などの発表です。',
        'todo' => array('最低賃金の改定の時期と額を確かめ、時給・月給の最も低い人がそれを下回らないかを見る', '勤怠の記録（出退勤の時刻・残業）を、あとから確かめられる形で残しておく', '指針や公表事案を見て、自社の就業規則・36協定・健康診断の運用と食い違いがないかを確かめる'),
        'apps' => array('0c438fde80e5c346', 'f0f56c6e4da881be', 'b7e010bb0869cbde', 'bb4980bad6219e25')),
    'joho' => array('name' => '情報管理', 'title' => '情報管理の制度ニュース｜不正アクセス・個人情報の注意喚起と会社がやること',
        'lead' => '不正アクセス・個人情報の漏えいについての府省の注意喚起や発表です。',
        'todo' => array('注意喚起に書かれた対策（ソフトの更新、パスワードの使い回しの禁止、多要素認証など）を自社で1つずつ確かめる', '取引先・委託先に預けている個人情報と、その委託先の対策を洗い出す', '漏えいが起きたときの報告先（個人情報保護委員会など）と社内の連絡の順番を決めておく'),
        'apps' => array('a96e95e176b48800', '9dd7d5be638f9cb8', '4003999985c45854')),
    'shien' => array('name' => '補助金と支援', 'title' => '補助金・助成金の制度ニュース｜中小企業向けの公募・支援と会社がやること',
        'lead' => '中小企業・小規模事業者向けの補助金・助成金・支援策の公募や決定の発表です。',
        'todo' => array('公募要領の締切・対象経費・事業者の要件（業種・従業員数）を、自社に当てはめて確かめる', '申請に要る書類（決算書・見積書・事業計画）と gBizID の準備にかかる日数を見込んでおく', '採択されたあとに要る実績報告・証拠書類を、はじめから残せる形にしておく'),
        'apps' => array('0ad3f1528024edcd', '237974724fb41216', 'bb4980bad6219e25')),
    'torihiki' => array('name' => '取引', 'title' => '取引の制度ニュース｜取適法・下請取引・価格転嫁の発表と会社がやること',
        'lead' => '取適法（旧 下請法）・価格転嫁・取引の適正化についての発表です。',
        'todo' => array('自社が発注側・受注側のどちらで取適法の対象になるかを、資本金と従業員数で確かめる', '発注書（取引条件の明示）・支払期日・手形の扱いが新しい決まりに合っているかを見る', '原材料・人件費の上がった分の価格の交渉を、記録に残る形で行う'),
        'apps' => array('42866a2a798d09eb', '88acc4be142bd444')),
    'tetsuzuki' => array('name' => '税と手続', 'title' => '税と手続の制度ニュース｜消費税減税・給付付き税額控除・インボイスと会社がやること',
        'lead' => '消費税減税・給付付き税額控除・インボイス・電子帳簿保存・電子申請など、会社の税と手続きに関わる報道と発表です。',
        'todo' => array('消費税の税率が変わることが決まったら、レジ・請求書・値札の税率の設定と、切り替える日を確かめる', '請求書・領収書の保存の仕方が、電子帳簿保存法とインボイスの決まりに合っているかを確かめる', '施行日や手続きの期限を、制度のカレンダーなどで社内に共有しておく'),
        'apps' => array('15abb025dc2ee4f6', '5b55bb2c808eead4', 'bb4980bad6219e25')),
);

$data = @json_decode((string)@file_get_contents(__DIR__ . '/kapp_data/seido.json'), true);
$items = ($data && isset($data['items'])) ? $data['items'] : array();
// 制度に関わる報道の見出し（2026-11-08 追加。府省の発表だけだと件数が少なく、最低賃金・消費税減税などの動きが見えなかった）
$news = ($data && isset($data['news'])) ? $data['news'] : array();
$by = array(); foreach (kapp_apps_published() as $a) { $by[$a['id']] = $a; }
$updated = $data && isset($data['generated_at']) ? substr($data['generated_at'], 0, 10) : date('Y-m-d');
$ogslug = ($c !== '' && isset($CATS[$c])) ? 'seido-' . $c : 'seido';
$c_list = $c !== '' ? array_values(array_filter($items, function ($x) use ($c) { return in_array($c, $x['cats'], true); })) : $items;
$c_news = $c !== '' ? array_values(array_filter($news, function ($x) use ($c) { return in_array($c, $x['cats'], true); })) : $news;
$answer = 'Kurage App Store の「業務の制度ニュース」とは、府省の発表のうち、労務・情報管理・補助金と支援・取引・税と手続など会社の業務に関わるものを並べ、分類ごとに会社がやることと、使える当社の業務システムを紹介するページです。いま府省の発表' . count($items) . '件と、制度に関わる報道' . count($news) . '件を載せています。';
if ($c !== '' && isset($CATS[$c])) { $answer = $CATS[$c]['name'] . 'の発表を受けて会社がやることは、' . implode('、', array_map(function ($v) { return rtrim($v, '。'); }, $CATS[$c]['todo'])) . 'の3つです。' . $CATS[$c]['name'] . 'の府省の発表はいま' . count($c_list) . '件、関係する報道は' . count($c_news) . '件です。'; }
$faq = array(
    array('業務の制度ニュースとは何ですか？', 'Kurage App Store（株式会社エクスブリッジ）が、府省の発表のうち会社の業務に関わるもの（労務・情報管理・補助金と支援・取引・税と手続）を並べ、会社がやることと使える当社の業務システムを紹介しているページです。'),
    array('報道のニュースも載せていますか？', '載せています。報道各社が X に投稿したニュースの見出しのうち、最低賃金・社会保険・消費税減税・給付付き税額控除・インボイス・価格転嫁・補助金・個人情報の漏えいなど、会社の業務に関わる制度の見出しを分類して並べ、元の記事へリンクしています（直近60日）。本文の要約や評価はしていません。'),
    array('どの府省の発表を載せていますか？', 'デジタル庁・国土交通省・厚生労働省・農林水産省・文部科学省・総務省・内閣府・金融庁・中小企業庁の新着情報（RSS）です。題名・日付・リンクは各府省の発表のとおりで、内容は公式ページでご確認ください。国税庁・公正取引委員会・個人情報保護委員会の発表は、まだ取り込めていません。'),
    array('紹介している業務システムはどう使えますか？', '自社のサーバー（レンタルサーバーなど）に置いて使う買い切りの業務システムです。月額の利用料はかからず、ソースコードが付いています。価格は各商品のページに載っています。'),
);
if ($c !== '' && isset($CATS[$c])) { array_unshift($faq, array($CATS[$c]['name'] . 'の発表を受けて、会社は何をすればよいですか？', implode('。', array_map(function ($v) { return rtrim($v, '。'); }, $CATS[$c]['todo'])) . '。')); }

if ($c !== '' && !isset($CATS[$c])) {
    http_response_code(404);
    kapp_head('見つかりません | Kurage App Store', 'お探しのページは見つかりませんでした。', $BASE . 'seido', true);
    kapp_header('業務の制度ニュース', $logged_in, $user, $is_seller, $is_admin);
    echo '<main class="wrap narrow"><p class="empty-note">お探しのページは見つかりませんでした。<br><a href="seido">業務の制度ニュースへ</a></p></main>';
    kapp_footer();
    exit;
}
if ($c !== '') {
    $list = array_values(array_filter($items, function ($x) use ($c) { return in_array($c, $x['cats'], true); }));
    $ct = $CATS[$c];
    $title = $ct['title'] . '｜Kurage App Store';
    $desc = $ct['lead'] . '会社がやることと、使える当社の業務システムを並べます。';
    $canonical = $BASE . 'seido-' . $c;
    $h1 = $ct['name'] . 'の制度ニュース';
} else {
    $list = $items;
    $title = '業務の制度ニュース｜府省の発表と報道から会社がやることと使える業務システム｜Kurage App Store';
    $desc = '最低賃金・消費税減税・給付付き税額控除・価格転嫁などの報道と、府省の発表のうち、労務・情報管理・補助金と支援・取引・税と手続など会社の業務に関わるものを並べ、分類ごとに会社がやることと、自社のサーバーで使える当社の業務システムを紹介します。';
    $canonical = $BASE . 'seido';
    $h1 = '業務の制度ニュース';
}
$ld = array(
    array('@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'url' => $canonical, 'description' => $desc,
          'inLanguage' => 'ja', 'dateModified' => $updated,
          'isPartOf' => array('@type' => 'WebSite', 'name' => 'Kurage App Store', 'url' => $BASE),
          'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/')),
    array('@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $h1, 'numberOfItems' => count($list),
          'itemListElement' => array_map(function ($x, $i) { return array('@type' => 'ListItem', 'position' => $i + 1, 'name' => $x['title'], 'url' => $x['url']); },
                                         array_slice($list, 0, 50), array_keys(array_slice($list, 0, 50)))),
    array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_values(array_filter(array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => 'Kurage App Store', 'item' => $BASE),
        array('@type' => 'ListItem', 'position' => 2, 'name' => '業務の制度ニュース', 'item' => $BASE . 'seido'),
        $c !== '' ? array('@type' => 'ListItem', 'position' => 3, 'name' => $CATS[$c]['name'], 'item' => $canonical) : null)))),
);
$ld[] = array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($qa) {
    return array('@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $qa[1]));
}, $faq));
kapp_head($title, $desc, $canonical, false, json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $BASE . 'assets/og/' . $ogslug . '.png?v=' . (int)@filemtime(__DIR__ . '/assets/og/' . $ogslug . '.png'));  // 画像を作り直したら URL も変わるように（X は同じ URL のカードを覚えて古いまま出す）
kapp_header('業務の制度ニュース', $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap">
<section>
  <p style="font-size:12.5px"><a href="index.php">Kurage App Store</a> › <?php echo $c !== '' ? '<a href="seido">業務の制度ニュース</a> › ' . kapp_h($CATS[$c]['name']) : '業務の制度ニュース'; ?></p>
  <h1><?php echo kapp_h($h1); ?></h1>
  <?php echo kapp_news_share_box('Kurage App Store｜' . $h1, $c !== '' ? $CATS[$c]['lead'] : $desc, $canonical, $c !== '' ? 'seido-' . $c : 'seido'); ?>
  <p style="font-size:15px;line-height:1.85;overflow-wrap:anywhere;margin:0 0 6px"><?php echo kapp_h($answer); ?></p>
  <p class="lead" style="overflow-wrap:anywhere"><?php echo kapp_h($c !== '' ? $CATS[$c]['lead'] : $desc); ?>（更新 <?php echo kapp_h($updated); ?>）</p>
  <p style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn ghost" style="padding:6px 14px;font-size:13px" href="seido">すべて</a>
    <?php foreach ($CATS as $k => $ct2): ?>
      <a class="btn ghost" style="padding:6px 14px;font-size:13px<?php echo $k === $c ? ';background:var(--foam)' : ''; ?>" href="seido-<?php echo kapp_h($k); ?>"><?php echo kapp_h($ct2['name']); ?></a>
    <?php endforeach; ?>
  </p>
</section>

<?php if ($c !== ''): $ct = $CATS[$c]; ?>
<section style="max-width:760px;margin:0 auto 20px;background:var(--foam);border:1px solid var(--panel-line);border-radius:16px;padding:16px 20px">
  <h2 style="font-size:18px;margin:0 0 6px"><?php echo kapp_h($ct['name']); ?>の発表を受けて、会社がやること</h2>
  <ol style="margin:0;padding-left:20px;line-height:1.8"><?php foreach ($ct['todo'] as $t): ?><li><?php echo kapp_h($t); ?></li><?php endforeach; ?></ol>
</section>
<section>
  <h2 style="font-size:18px">この分類で使える当社の業務システム（自社のサーバー・買い切り・月額なし）</h2>
  <div class="grid">
  <?php foreach ($ct['apps'] as $id): if (!isset($by[$id])) { continue; } $app = $by[$id]; $p = kapp_price_parts($app['price']); ?>
    <article class="item"><div class="body">
      <h3><a href="app.php?id=<?php echo kapp_h($id); ?>&amp;ref=seido-<?php echo kapp_h($c); ?>"><?php echo kapp_h($app['name']); ?></a></h3>
      <p class="sum"><?php echo kapp_h(mb_strimwidth($app['summary'], 0, 110, '…', 'UTF-8')); ?></p>
      <div class="foot"><?php if ($p['total'] > 0): ?><span class="yen"><?php echo number_format($p['total']); ?>円<small>税込</small></span><?php endif; ?></div>
    </div></article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php $nshow = array_slice($c_news, 0, 30); if ($nshow): ?>
<section>
  <h2 style="font-size:18px"><?php echo $c !== '' ? kapp_h($CATS[$c]['name']) . 'に関係するニュース（' . count($c_news) . '件・新しい順）' : '制度に関わるニュース（' . count($c_news) . '件・新しい順）'; ?></h2>
  <?php foreach ($nshow as $x): ?>
    <article style="border-top:1px solid var(--panel-line);padding:10px 0;overflow-wrap:anywhere">
      <div style="font-size:12px;color:#5b6b70"><?php echo kapp_h($x['day'] . ' ' . $x['time'] . '　' . $x['source']); ?>　<?php foreach ($x['cats'] as $k): if (!isset($CATS[$k])) { continue; } ?><a href="seido-<?php echo kapp_h($k); ?>" style="font-size:11.5px;border:1px solid var(--panel-line);border-radius:99px;padding:1px 8px;margin-right:4px;text-decoration:none"><?php echo kapp_h($CATS[$k]['name']); ?></a><?php endforeach; ?></div>
      <?php /* 見出しは exbridge.jp のニュースのページ（見出し＋関係する国会の記録）へ。元の記事は2行目（2026-11-10 ユーザー指示「１をやって」: 自社の中を回るように） */ ?>
      <div style="font-size:15px;font-weight:700;line-height:1.6;margin:3px 0"><?php if ($x['ex'] !== ''): ?><a href="<?php echo kapp_h($x['ex']); ?>"><?php echo kapp_h($x['headline']); ?></a><?php elseif ($x['url'] !== ''): ?><a href="<?php echo kapp_h($x['url']); ?>" target="_blank" rel="noopener nofollow"><?php echo kapp_h($x['headline']); ?></a><?php else: echo kapp_h($x['headline']); endif; ?></div>
      <?php if ($x['ex'] !== ''): ?><div style="font-size:12.5px;color:#5b6b70">関係する国会の記録つき<?php if ($x['url'] !== ''): ?>　／　<a href="<?php echo kapp_h($x['url']); ?>" target="_blank" rel="noopener nofollow">元の記事を読む（<?php echo kapp_h($x['source'] !== '' ? $x['source'] : '報道'); ?>）</a><?php endif; ?></div><?php endif; ?>
      <?php echo kapp_news_app_line(isset($x['apps']) ? $x['apps'] : array(), $by, 'seido-news-item'); ?>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section>
  <h2 style="font-size:18px"><?php echo $c !== '' ? kapp_h($CATS[$c]['name']) . 'の府省の発表（' . count($list) . '件）' : '府省の発表（' . count($list) . '件）'; ?></h2>
  <?php foreach ($list as $x): ?>
    <article style="border-top:1px solid var(--panel-line);padding:10px 0;overflow-wrap:anywhere">
      <div style="font-size:12px;color:#5b6b70"><?php echo kapp_h($x['day'] . '　' . $x['ministry']); ?>　<?php foreach ($x['cats'] as $k): if (!isset($CATS[$k])) { continue; } ?><a href="seido-<?php echo kapp_h($k); ?>" style="font-size:11.5px;border:1px solid var(--panel-line);border-radius:99px;padding:1px 8px;margin-right:4px;text-decoration:none"><?php echo kapp_h($CATS[$k]['name']); ?></a><?php endforeach; ?></div>
      <div style="font-size:15px;font-weight:700;line-height:1.6;margin:3px 0"><a href="<?php echo kapp_h($x['url']); ?>" target="_blank" rel="noopener"><?php echo kapp_h($x['title']); ?></a></div>
      <?php if ($x['ex'] !== ''): ?><div style="font-size:12.5px"><a href="<?php echo kapp_h($x['ex']); ?>">この発表に関係する国会の記録</a></div><?php endif; ?>
      <?php echo kapp_news_app_line(isset($x['apps']) ? $x['apps'] : array(), $by, 'seido-item'); ?>
    </article>
  <?php endforeach; ?>
  <?php if (!$list): ?><p class="empty-note">この分類の発表は、まだありません。</p><?php endif; ?>
</section>

<?php if ($c === ''): ?>
<section>
  <h2 style="font-size:18px">分類ごとに、会社がやることと使える業務システム</h2>
  <div class="grid">
  <?php foreach ($CATS as $k => $ct2): ?>
    <article class="item"><div class="body"><h3><a href="seido-<?php echo kapp_h($k); ?>"><?php echo kapp_h($ct2['name']); ?>の制度ニュース</a></h3><p class="sum"><?php echo kapp_h($ct2['todo'][0]); ?>…</p></div></article>
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
  <p>ニュースの見出しは報道各社が X に投稿したもののとおりで、元の記事へリンクしています。府省の発表の題名・日付・リンクは各府省の新着情報（RSS）のとおりです（デジタル庁・国土交通省・厚生労働省・農林水産省・文部科学省・総務省・内閣府・金融庁・中小企業庁）。内容は必ず公式ページでご確認ください。分類は題名の語による機械的なものです。あわせて <a href="news">AI・IT・DX・防災ニュース</a> もご覧ください。運営：株式会社エクスブリッジ（Kurage App Store）。</p>
</section>
</main>
<?php kapp_footer(); ?>
