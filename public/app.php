<?php
/**
 * Kurage App Store — アプリ詳細。
 * 未購入なら注文へ、購入済みならダウンロードへ導く。
 */
require_once __DIR__ . '/kapp_boot.php';
$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
kapp_handle_auth_links('app.php?id=' . rawurlencode($id));

// 紹介した販売代理店。?agent=<Xのアカウント> で来たら注文ページまで引き継ぐ。
// これが無いと、代理店が紹介した成約に手数料を付けられない。
$agent_q = isset($_GET['agent']) ? (string)$_GET['agent'] : '';
$agent_q = preg_match('/^@?[0-9A-Za-z_]{1,20}$/', $agent_q) ? kapp_norm_user($agent_q) : '';
if ($agent_q !== '' && session_status() === PHP_SESSION_ACTIVE) { $_SESSION['kapp_agent'] = $agent_q; }
$order_qs = function ($id) use ($agent_q) {
    return 'order.php?app=' . rawurlencode($id) . ($agent_q !== '' ? '&amp;agent=' . rawurlencode($agent_q) : '');
};

$app = kapp_find_app($id);
if (!$app || (isset($app['status']) && $app['status'] !== 'published'
        && !($logged_in && (kapp_norm_user($app['seller']) === $user || $is_admin)))) {
    http_response_code(404);
    kapp_head('見つかりません | Kurage App Store', 'お探しのアプリは見つかりませんでした。',
        'https://kappstore.exbridge.jp/app.php', true);
    kapp_header('アプリ詳細', $logged_in, $user, $is_seller, $is_admin);
    echo '<main class="wrap narrow"><p class="empty-note">お探しのアプリは見つかりませんでした。<br>'
       . '<a href="index.php">アプリ一覧へ戻る</a></p></main>';
    kapp_footer();
    exit;
}

$p = kapp_price_parts($app['price']);
$seller = kapp_find_seller($app['seller']);
$owned = $logged_in && ($p['total'] === 0 ? false : kapp_has_paid($user, $app['id']));
$canonical = 'https://kappstore.exbridge.jp/app.php?id=' . rawurlencode($app['id']);

// 商品ごとの構造化データ。検索結果に価格と在庫が出る。
$p_head = kapp_price_parts($app['price']);
$product_ld = array(
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $app['name'],
    'description' => mb_strimwidth($app['summary'], 0, 300, '…', 'UTF-8'),
    'url'         => $canonical,
    'image'       => !empty($app['image'])
        ? 'https://kappstore.exbridge.jp/kapp_media/' . $app['image'] : null,
    'brand'       => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ'),
    // 鮮度の申告。台帳の updated_at をそのまま使う（無ければ created_at）。
    'dateModified' => date('c', (int)(!empty($app['updated_at']) ? $app['updated_at']
                                     : (!empty($app['created_at']) ? $app['created_at'] : time()))),
    'offers'      => array(
        '@type'         => 'Offer',
        'price'         => (string)$p_head['total'],
        'priceCurrency' => 'JPY',
        'availability'  => 'https://schema.org/InStock',
        'url'           => $canonical,
        'seller'        => array('@type' => 'Organization', 'name' => 'Kurage App Store'),
    ),
);
// 商品データから正確なFAQを組む（可視のFAQ節と一致させる＝ガイドライン準拠）。
$faq_items = array();
// 「どんなときに使うか」。人が検索するのは商品名ではなく困りごとなので、
// その言葉が本文に1度も無いと検索にも引っかからず、AIも用途を答えられない。
// 用途の一覧は kapp_usecases.php に1か所だけ持つ（llms.txtと同じもの）。
require_once __DIR__ . '/kapp_usecases.php';
$use_case = kapp_use_case_of($app['id']);
if ($use_case !== '') {
    $faq_items[] = array('どんなときに使いますか？',
        $use_case . '——そんなときのための商品です。'
        . 'デモを操作してから購入をご判断ください。');
}
$faq_items[] = array('ライセンスは？',
    (!empty($app['license']) ? $app['license'] : 'MIT') . 'ライセンスです。ソースコードを同梱し、商用利用・改変・再配布が自由に行えます。');
if (!empty($app['requires'])) {
    $faq_items[] = array('動作環境・設置方法は？',
        '設置先は「' . $app['requires'] . '」です。FTPでファイルを置くだけで動きます。');
}
if (!empty($app['demo_url'])) {
    $faq_items[] = array('購入前に試せますか？',
        'はい。デモで実際に触って、気に入ってから購入できます（' . $app['demo_url'] . '）。');
}
$faq_items[] = array('AIエージェントで改造・拡張できますか？',
    'できます。Claude Code等のAIエージェント向けの設計マニュアルが付属し、触れてよい範囲を宣言したうえで安全に変更を頼めます。');
$faq_items[] = array('買い切りですか？月額はありますか？',
    ((int)$p_head['total'] === 0 ? '無料です。月額料金はありません。' : '買い切りです。月額料金はありません。'));
$faq_ld = array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array());
foreach ($faq_items as $qa) {
    $faq_ld['mainEntity'][] = array(
        '@type' => 'Question', 'name' => $qa[0],
        'acceptedAnswer' => array('@type' => 'Answer', 'text' => $qa[1]));
}
$video_ld = null;
if (!empty($app['video_url'])) {
    $video_ld = array(
        '@context' => 'https://schema.org', '@type' => 'VideoObject',
        'name' => $app['name'] . ' 紹介動画（15秒）',
        'description' => mb_strimwidth($app['summary'], 0, 200, '…', 'UTF-8'),
        'contentUrl' => $app['video_url'],
        'thumbnailUrl' => !empty($app['image'])
            ? 'https://kappstore.exbridge.jp/kapp_media/' . $app['image'] : null,
        'uploadDate' => date('c', (int)(!empty($app['updated_at']) ? $app['updated_at'] : time())),
    );
}
$breadcrumb_ld = array(
    '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
    'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => 'Kurage App Store',
              'item' => 'https://kappstore.exbridge.jp/'),
        array('@type' => 'ListItem', 'position' => 2, 'name' => $app['name'],
              'item' => $canonical),
    ),
);
$ld_items = array($product_ld, $faq_ld, $breadcrumb_ld);
if ($video_ld) { $ld_items[] = $video_ld; }
$jsonld = json_encode($ld_items,
                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// OGPは商品画像を使う。出品者がSNSで紹介したとき、店のロゴではなく
// その商品が出るようにする（宣伝してもらう前提の店なので、ここは要）。
$ogp = !empty($app['image'])
    ? 'https://kappstore.exbridge.jp/kapp_media/' . $app['image'] : '';

// title/description は台帳の seo_title / seo_desc を優先する。
// 商品名は「何であるか」を表す名前で、検索で打たれている語とは限らない。
// 実際に検索されている語(キーワードプランナー実測)を先頭に置いた文言を
// 台帳側に持たせ、無ければ従来どおり商品名と要約から組む。
$seo_title = !empty($app['seo_title'])
    ? $app['seo_title'] : $app['name'] . ' | Kurage App Store';
$seo_desc  = !empty($app['seo_desc'])
    ? $app['seo_desc'] : mb_strimwidth($app['summary'], 0, 110, '…', 'UTF-8');

kapp_head(
    $seo_title,
    $seo_desc,
    $canonical,
    false,
    $jsonld,
    $ogp
);
kapp_header('アプリ詳細', $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap narrow">
<section>
  <p style="font-size:12.5px"><a href="index.php">← アプリ一覧</a></p>
  <h1><?php echo kapp_h($app['name']); ?></h1>
  <?php /* 「○○とは、〜です」の定義文。AI検索はこの形の1文を引用するため、
           要約の先頭文から機械的に組む（kgeo監査の definitions が0点だった対策）。 */ ?>
  <p class="lead" style="overflow-wrap:anywhere"><?php echo nl2br(kapp_h($app['summary'])); ?></p>

<?php if (isset($app['status']) && $app['status'] !== 'published'): ?>
  <p class="ok">この出品は<b>下書き</b>です。公開するまで一覧には表示されません。</p>
<?php endif; ?>

<?php if (!empty($app['video_url'])): ?>
  <?php /* 15秒PV。台帳の video_url に絶対URLを入れると画像の代わりに出す。
           自動再生はしない（ナレーション付きなので、音を消して勝手に流す意味がない）。 */ ?>
  <p style="margin-bottom:18px">
    <video src="<?php echo kapp_h($app['video_url']); ?>" controls playsinline preload="metadata"
           <?php /* 台帳の video_poster(絶対URL・製品名入りサムネ)を優先。無ければ商品画像。 */ ?>
           <?php if (!empty($app['video_poster'])): ?>poster="<?php echo kapp_h($app['video_poster']); ?>"<?php elseif (!empty($app['image'])): ?>poster="kapp_media/<?php echo kapp_h($app['image']); ?>"<?php endif; ?>
           style="width:100%;border-radius:16px;border:1.5px solid var(--panel-line);display:block;background:#000"></video>
  </p>
<?php elseif (!empty($app['image'])): ?>
  <p style="margin-bottom:18px">
    <img src="kapp_media/<?php echo kapp_h($app['image']); ?>" alt="<?php echo kapp_h($app['name']); ?>"
         style="border-radius:16px;border:1.5px solid var(--panel-line);display:block">
  </p>
<?php endif; ?>

  <?php $external = empty($app['file']); /* 配布ファイルを持たない=公式サイト(LP)で配布する掲載 */ ?>
  <div class="gate">
    <?php if ($p['total'] === 0): ?>
      <p class="price">無料<small><?php echo $external ? '公式サイトで配布' : 'ダウンロードいただけます'; ?></small></p>
    <?php else: ?>
      <p class="price"><?php echo number_format($p['total']); ?>円<small>税込</small></p>
      <p style="font-size:13.5px;margin-top:6px">
        本体 <?php echo number_format($p['amount']); ?>円 ＋ 消費税 <?php echo number_format($p['tax']); ?>円</p>
    <?php endif; ?>

    <p style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
      <?php if (!empty($app['demo_url']) && !$external): ?>
        <a class="btn ghost" href="<?php echo kapp_h($app['demo_url']); ?>" target="_blank" rel="noopener">
          デモを触ってみる</a>
      <?php endif; ?>
      <?php if ($owned): ?>
        <a class="btn" href="download.php?id=<?php echo kapp_h($app['id']); ?>">ダウンロード</a>
      <?php elseif ($external && !empty($app['demo_url'])): ?>
        <a class="btn" href="<?php echo kapp_h($app['demo_url']); ?>" target="_blank" rel="noopener">公式サイトで入手する</a>
      <?php elseif ($p['total'] === 0): ?>
        <a class="btn" href="<?php echo $order_qs($app['id']); ?>">無料で受け取る</a>
      <?php else: ?>
        <a class="btn" href="<?php echo $order_qs($app['id']); ?>">購入する</a>
      <?php endif; ?>
    </p>
    <?php if ($owned): ?>
      <p class="hint">ご購入済みです。何度でもダウンロードいただけます。</p>
    <?php elseif ($external): ?>
      <p class="hint">公式サイト（LP）から iPhone 版・Android 版を入手できます。</p>
    <?php elseif (!empty($app['demo_url'])): ?>
      <p class="hint">デモでご確認のうえ、ご納得いただいてからご購入ください。</p>
    <?php endif; ?>

    <?php
    /* 共有ボタン。出品者が自分の商品を広めることが、この店の集客そのもの。
       紹介文まで用意しておかないと「URLをコピーして文章を考える」で止まる。 */
    $share_text = $app['name'] . ' — ' . mb_strimwidth($app['summary'], 0, 70, '…', 'UTF-8');
    $share_url  = $canonical;
    ?>
    <p class="hint" style="margin-top:18px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span>この商品を紹介する</span>
      <a class="btn ghost" style="padding:6px 14px;font-size:12px"
         href="https://x.com/intent/tweet?text=<?php echo rawurlencode($share_text); ?>&amp;url=<?php echo rawurlencode($share_url); ?>"
         target="_blank" rel="noopener">𝕏 でポスト</a>
      <a class="btn ghost" style="padding:6px 14px;font-size:12px"
         href="https://social-plugins.line.me/lineit/share?url=<?php echo rawurlencode($share_url); ?>"
         target="_blank" rel="noopener">LINE</a>
      <a class="btn ghost" style="padding:6px 14px;font-size:12px"
         href="https://b.hatena.ne.jp/entry/<?php echo kapp_h(preg_replace('#^https?://#', '', $share_url)); ?>"
         target="_blank" rel="noopener">はてブ</a>
      <button type="button" class="btn ghost" style="padding:6px 14px;font-size:12px"
              onclick="navigator.clipboard.writeText('<?php echo kapp_h($share_url); ?>').then(function(){this.textContent='コピーしました';}.bind(this))">
        URLをコピー</button>
    </p>
  </div>

<?php if (!$external && $p['total'] > 0): /* 入手方法の3択（手順書があれば3つ・なければ2つ） */
  $has_guide = !empty($app['guide_url']); $opt = 1; ?>
  <div class="card plain">
    <h2>入手方法は<?php echo $has_guide ? '4' : '3'; ?>つあります</h2>
    <p style="font-size:13.5px;color:var(--ink-soft);margin:0 0 10px">同じゴールに、あなたに合う入口からどうぞ。</p>
    <div class="scroll">
    <table class="kv" style="min-width:0">
      <tr><th>①完成品を購入</th>
        <td>すぐ使いたい方向け。このページで購入し、即ダウンロード（ソースコード付き・<?php echo number_format($p['total']); ?>円税込）。AIで自社向けに改変できます。</td></tr>
      <?php if ($has_guide): ?>
      <tr><th>②手順書で自作</th>
        <td>作る力を手に入れたい方向け。開発手順書（同価格・55,000円）を購入し、Claude CodeなどのAIエージェントで自分の手で開発します。<br>
          <a href="<?php echo kapp_h($app['guide_url']); ?>" target="_blank" rel="noopener">開発手順書を見る（Brain）</a></td></tr>
      <?php endif; ?>
      <tr><th><?php echo $has_guide ? '③' : '②'; ?>この商品を自社仕様に</th>
          <td>「うちの業種・業務に合わせてほしい」方向け。<b>バイブカスタマイズ</b>（110,000円税込）で、この商品を土台に当社が変更します。動くデモを確認してからのお支払いです。<br>
            <a href="https://kurage.exbridge.jp/vibe-customize.html?ref=kappstore" target="_blank" rel="noopener">バイブカスタマイズを見る</a></td></tr>
        <tr><th><?php echo $has_guide ? '④' : '③'; ?>ゼロから作る</th>
          <td>近い商品が無い、または業務そのものが特殊な方向け。設計書から作る<b>バイブプロトタイプ制作</b>（330,000円税込）です。<br>
            <a href="https://kurage.exbridge.jp/vibe-prototype.html?ref=kappstore" target="_blank" rel="noopener">バイブプロトタイプ制作を見る</a></td></tr>
    </table>
    </div>
  </div>
<?php endif; ?>

  <?php /* AEOの定義文判定は「見出しの直後の本文」を見る。だから定義文は
           h2 のすぐ下に置く（h1直下のリード文では拾われなかった）。 */ ?>
  <div class="card">
    <h2><?php echo kapp_h(kapp_short_name($app)); ?>とは</h2>
    <p style="font-size:14px;overflow-wrap:anywhere"><b><?php echo kapp_h(kapp_short_name($app)); ?>とは、</b><?php echo kapp_h(kapp_definition_sentence($app)); ?></p>
<?php if (!empty($app['body'])): ?>
    <?php /* 商品説明はMarkdownで書かれている。段落・見出し・表を含むので p ではなく div で受ける
             （p の中に h4/ul/table は置けず、ブラウザが勝手に閉じて崩れる）。 */ ?>
    <div class="md-body"><?php echo kapp_md($app['body']); ?></div>
<?php endif; ?>
  </div>

  <div class="card plain">
    <h2>販売情報</h2>
    <div class="scroll">
    <table class="kv" style="min-width:0">
      <tr><th>開発元</th><td>
        <?php if ($seller): ?>
          <?php echo kapp_h($seller['name']); ?>
          <?php if (!empty($seller['url'])): ?><br>
            <a href="<?php echo kapp_h($seller['url']); ?>" target="_blank" rel="noopener nofollow">
              <?php echo kapp_h($seller['url']); ?></a>
          <?php endif; ?>
        <?php else: ?>@<?php echo kapp_h($app['seller']); ?><?php endif; ?>
      </td></tr>
      <tr><th>𝕏 アカウント</th><td>
        <a href="https://x.com/<?php echo kapp_h($app['seller']); ?>" target="_blank" rel="noopener nofollow">@<?php echo kapp_h($app['seller']); ?></a>
      </td></tr>
      <?php if (!empty($app['demo_url'])): ?>
      <tr><th>デモサイト</th><td>
        <a href="<?php echo kapp_h($app['demo_url']); ?>" target="_blank" rel="noopener"><?php echo kapp_h($app['demo_url']); ?></a>
      </td></tr>
      <?php endif; ?>
      <?php if (!empty($app['filename'])): ?>
      <tr><th>配布ファイル</th><td><?php echo kapp_h($app['filename']); ?>
        <?php if (!empty($app['filesize'])): ?>
          （<?php echo number_format($app['filesize'] / 1024, 0); ?> KB）<?php endif; ?></td></tr>
      <?php endif; ?>
      <tr><th>公開日</th><td><?php echo date('Y年n月j日', (int)$app['created_at']); ?></td></tr>
    </table>
    </div>
  </div>

  <div class="gate">
    <h2 style="margin-top:0">ご購入前に必ずお読みください</h2>
    <ul>
      <li><b>これはプロトタイプです。動作を保証していません。</b>お客様の環境
        （サーバー・PHPのバージョン・通信の制限など）によっては、そのままでは動かない可能性があります。</li>
      <li><b>AIエージェント（Claude Code / Codex など）を使えることが前提です。</b>
        設置も、つまずいたときの解決も、<b>AIに相談しながらご自身で進めていただく</b>前提の商品です。
        手順書・設計書・AI向け指示書を同梱しています。</li>
      <li><b>お問い合わせに必ずお応えできるとは限りません。</b>
        購入代金に個別サポートは含まれていません（有償のサポートは別途承ります）。</li>
      <li><b>ノークレーム・ノーリターンでお願いいたします。</b>
        ダウンロード商品の性質上、ご購入後の返品・返金はお受けできません。
        <b>必ずデモを触って、ご自身の目で確かめてからご判断ください。</b></li>
    </ul>
  </div>

  <div class="card plain">
    <h2>そのほかの条件</h2>
    <ul>
      <li>設置には、PHPが動作するレンタルサーバー等のご用意が必要です。詳細は各アプリの説明をご確認ください。</li>
      <li>ソースコードは改変・再配布が可能です。ライセンスは同梱の LICENSE をご確認ください。</li>
    </ul>
    <p class="hint">
      <a href="https://kurage.exbridge.jp/terms.html">利用規約</a> ／
      <a href="https://kurage.exbridge.jp/tokusho.php">特定商取引法に基づく表記</a></p>
  </div>
</section>

<section style="max-width:760px;margin:18px auto 0;background:var(--foam);border:1px solid var(--panel-line);border-radius:16px;padding:18px 20px;box-shadow:var(--shadow)">
  <h2 style="font-size:18px;margin:0 0 6px;color:var(--abyss)">よくある質問</h2>
  <?php /* 質問は必ず見出しタグで出す。details/summary は見出しとして解釈されず、
           AI検索・AEOの「質問見出し」判定に入らない（kgeo監査で0点だった原因）。 */ ?>
  <?php foreach ($faq_items as $qa): ?>
    <div style="border-top:1px solid var(--panel-line);padding:11px 0">
      <h3 style="font-size:15px;font-weight:800;margin:0;color:var(--abyss)"><?php echo kapp_h($qa[0]); ?></h3>
      <p style="margin:6px 0 0;color:var(--abyss-soft);overflow-wrap:anywhere;line-height:1.7"><?php echo kapp_h($qa[1]); ?></p>
    </div>
  <?php endforeach; ?>
</section>
</main>
<?php kapp_footer(); ?>
