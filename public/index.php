<?php
/**
 * Kurage App Store — アプリ一覧（トップ）。
 *
 * 買い手はここから探し、デモを触ってから注文する。ログイン不要で閲覧できる
 * （何を売っているか見せてからログインさせる。vibe-prototype.php と同じ方針）。
 *
 * このページは店の説明も兼ねる。「非エンジニアが、買ったあとで自分で
 * 改変・拡張できるプロトタイプの店」という一点を伝えるのが役割で、
 * 検索から来た人が最初に読む文章でもあるため、そこを厚く書いている。
 */
require_once __DIR__ . '/kapp_boot.php';
kapp_handle_auth_links('index.php');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$all = kapp_apps_published();
$apps = $all;
if ($q !== '') {
    $hit = array();
    foreach ($apps as $app) {
        $hay = $app['name'] . ' ' . $app['summary'] . ' ' . (isset($app['body']) ? $app['body'] : '');
        if (mb_stripos($hay, $q, 0, 'UTF-8') !== false) { $hit[] = $app; }
    }
    $apps = $hit;
}

$sellers = array();
foreach (kapp_sellers() as $s) { $sellers[kapp_norm_user($s['x'])] = $s; }

/* ---- 構造化データ ----
 * 商品一覧(ItemList)とよくある質問(FAQPage)を出す。検索結果での見え方に効く。 */
$item_list = array();
foreach ($all as $i => $app) {
    $p = kapp_price_parts($app['price']);
    $item_list[] = array(
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'item'     => array(
            '@type'       => 'Product',
            'name'        => $app['name'],
            'description' => mb_strimwidth($app['summary'], 0, 200, '…', 'UTF-8'),
            'url'         => 'https://kappstore.exbridge.jp/app.php?id=' . $app['id'],
            'offers'      => array(
                '@type'         => 'Offer',
                'price'         => (string)$p['total'],
                'priceCurrency' => 'JPY',
                'availability'  => 'https://schema.org/InStock',
            ),
        ),
    );
}
$faq = array(
    array('プログラミングができなくても使えますか。',
          'はい。購入したファイル一式をClaude CodeなどのAIエージェントに渡すと、同梱の手順書を読んで設置まで進みます。設置後の「項目を1つ増やしたい」「文面を変えたい」といった改造も、AIに頼んで進められます。'),
    array('買ったあとで自由に変えられますか。',
          'すべてMIT Licenseです。商用利用・改変・再配布・再販が自由に行えます。ソースコードが手元に残るので、他社に依頼しなくても自分で育てられます。'),
    array('どのくらいの規模のコードですか。',
          '1,000〜2,000行程度に収めています。読めないコードは改変できないため、「全部読めるサイズ」を意図的な上限にしています。'),
    array('動かすのに何が必要ですか。',
          'PHPが動くレンタルサーバーがあれば動きます。データベース・Composer・npmは不要で、FTPでアップロードするだけです。'),
    array('買う前に試せますか。',
          'すべての商品にデモをご用意しています。実際に操作してから購入をご判断ください。'),
    array('必ず動きますか。サポートはありますか。',
          '販売しているのはプロトタイプで、動作を保証していません。お客様の環境によっては、そのままでは動かない可能性があります。設置もつまずいたときの解決も、Claude CodeやCodexなどのAIエージェントに相談しながらご自身で進めていただく前提の商品です。お問い合わせへの対応はお約束できず、購入代金にサポートは含まれていません。ノークレーム・ノーリターンでお願いいたします。'),
    array('AIエージェントを使っていなくても買えますか。',
          'おすすめしていません。設置も改造もAIに相談しながら進める前提で作られているため、Claude CodeやCodexをお使いでない場合は、期待した結果にならない可能性が高くなります。'),
);
$faq_list = array();
foreach ($faq as $f) {
    $faq_list[] = array(
        '@type' => 'Question', 'name' => $f[0],
        'acceptedAnswer' => array('@type' => 'Answer', 'text' => $f[1]),
    );
}
$jsonld = json_encode(array(
    '@context' => 'https://schema.org',
    '@graph'   => array(
        array('@type' => 'WebSite', 'name' => 'Kurage App Store',
              'url' => 'https://kappstore.exbridge.jp/',
              'description' => '非エンジニアが自分で改変・拡張できる業務システムのプロトタイプを販売するダウンロードストア。'),
        array('@type' => 'ItemList', 'itemListElement' => $item_list),
        array('@type' => 'FAQPage', 'mainEntity' => $faq_list),
    ),
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

kapp_head(
    '非エンジニアが自分で改変できる業務システム | Kurage App Store',
    '買ったあとで自分でカスタマイズできる業務システムのプロトタイプを販売。AIエージェントに渡せば設置も改造も進められます。MITライセンスで改変・再販自由、データベース不要、デモを触ってから購入できます。',
    'https://kappstore.exbridge.jp/',
    false,
    $jsonld
);
kapp_header('改変できる業務システムのお店', $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap">
<section>
  <h1>買ったあとで、自分で変えられる業務システム</h1>
  <p class="lead">
    Kurage App Store は、<b>非エンジニアの方が自分でカスタマイズ・拡張できる</b>
    業務システムのプロトタイプを販売するダウンロードストアです。<br>
    完成品ではなく<b>「育てられる土台」</b>をお渡しします。
  </p>

  <form method="get" role="search" style="margin-bottom:26px;display:flex;gap:10px;flex-wrap:wrap">
    <input type="text" name="q" value="<?php echo kapp_h($q); ?>"
           aria-label="やりたいことで探す"
           placeholder="やりたいことで探す（例：請求書、注文、監視、見積書）"
           style="flex:1;min-width:240px">
    <button type="submit" class="btn">探す</button>
  </form>

<?php if (!$apps): ?>
  <p class="empty-note">
    <?php echo $q !== '' ? '「' . kapp_h($q) . '」に一致するアプリはありませんでした。' : 'まだ公開中のアプリがありません。'; ?>
  </p>
<?php else: ?>
  <div class="grid">
  <?php foreach ($apps as $app): $p = kapp_price_parts($app['price']); ?>
    <article class="item">
      <a class="thumb<?php echo empty($app['image']) ? ' empty' : ''; ?>"
         href="app.php?id=<?php echo kapp_h($app['id']); ?>">
        <?php if (!empty($app['image'])): ?>
          <img src="kapp_media/<?php echo kapp_h($app['image']); ?>" alt="<?php echo kapp_h($app['name']); ?>" loading="lazy">
        <?php else: ?>🪼<?php endif; ?>
      </a>
      <div class="body">
        <h3><a href="app.php?id=<?php echo kapp_h($app['id']); ?>"><?php echo kapp_h($app['name']); ?></a></h3>
        <p class="sum"><?php echo kapp_h(mb_strimwidth($app['summary'], 0, 84, '…', 'UTF-8')); ?></p>
        <div class="foot">
          <?php if ($p['total'] === 0): ?>
            <span class="yen">無料</span><span class="tag free">FREE</span>
          <?php else: ?>
            <span class="yen"><?php echo number_format($p['total']); ?>円<small>税込</small></span>
          <?php endif; ?>
          <?php if (!empty($app['demo_url'])): ?><span class="tag">デモあり</span><?php endif; ?>
        </div>
        <p style="font-size:11.5px;color:var(--abyss-soft)">
          販売：<?php
            $sk = kapp_norm_user($app['seller']);
            echo kapp_h(isset($sellers[$sk]) ? $sellers[$sk]['name'] : '@' . $app['seller']);
          ?>
        </p>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>

<section>
  <?php /* 買う前に必ず読ませる。売っているのはプロトタイプであり、
           動かないことがある前提。ここを曖昧にすると必ず揉める。 */ ?>
  <div class="gate">
    <h2 style="margin-top:0">ご購入前に必ずお読みください</h2>
    <p>
      当ストアで販売しているのは<b>プロトタイプ</b>です。完成品でも、
      サポート付きの製品でもありません。次の3点にご同意いただける方だけ、ご購入ください。
    </p>
    <ul>
      <li><b>動かないことがあります。</b>お客様の環境（サーバー・PHPのバージョン・
        通信の制限など）によっては、そのままでは動作しない可能性があります。
        <b>動作を保証していません。</b></li>
      <li><b>AIエージェントを使えることが前提です。</b>設置も、つまずいたときの解決も、
        <b>Claude Code や Codex に相談しながらご自身で進めていただく</b>前提の商品です。
        そのための手順書・設計書・AI向け指示書を同梱しています。
        <b>AIをお使いにならない方には販売していません。</b></li>
      <li><b>お問い合わせに必ずお応えできるとは限りません。</b>個別のご質問・不具合のご相談に、
        対応をお約束できません。有償のサポートは別途承りますが、
        <b>購入代金にサポートは含まれていません。</b></li>
    </ul>
    <p>
      <b>ノークレーム・ノーリターンでお願いいたします。</b>
      ダウンロード商品の性質上、ご購入後の返品・返金はお受けできません。
      <b>すべての商品にデモをご用意しています</b>ので、必ず実際に触れて、
      ご自身の目で確かめてからご判断ください。
    </p>
  </div>
</section>

<section>
  <div class="card plain">
    <h2>完成品を買うと、変えられなくなる</h2>
    <p>
      業務システムを外注すると、たいていこうなります。作ってもらった直後は満足します。
      半年経つと業務が変わって合わなくなる。直したいけれど自分では触れないので、また見積もりを取る。
      小さな変更に数十万円かかる。だんだん頼まなくなり、Excelで回避するようになる。
    </p>
    <p>
      <b>完成品は、渡された瞬間から劣化していきます。</b>変えられないからです。
    </p>
    <p>
      かといって、ゼロから作るのも現実的ではありません。AIがあっても、非エンジニアが白紙から
      業務システムを立ち上げるのは重い。「何をどう持つか」という判断が先に来るからです。
    </p>
    <p><b>その中間が、これまで売られていませんでした。</b></p>
  </div>
</section>

<section>
  <div class="card plain">
    <h2>なぜ非エンジニアでも改変できるのか</h2>
    <p>「AIがあるから」だけでは足りません。改変できる形で作ってあるからです。</p>
    <ul>
      <li><b>全部読めるサイズ。</b>1本あたり1,000〜2,000行程度に収めています。
        読めないコードは改変できません。だから機能を盛らず、<b>読める上限</b>を先に決めています。</li>
      <li><b>なぜそうしたかが書いてある。</b>コード中のコメントに設計の理由を残しています。
        理由が書かれていないと、AIは良かれと思って安全側の作りを外してしまいます。</li>
      <li><b>AIへの指示書が同梱。</b><code>CLAUDE.md</code> / <code>AGENTS.md</code> /
        <code>INSTALL.md</code> を入れてあります。AIに渡せば、設置手順を読んでそのまま進みます。</li>
      <li><b>改造の練習問題つき。</b>「項目を1つ増やす」から始めて、
        少しずつ自分のものにしていく順序を用意しています。</li>
      <li><b>MIT License。</b>商用利用・改変・再配布・再販が自由です。
        買った方が改造して、自分のブランドで売っても構いません。</li>
    </ul>
  </div>
</section>

<section>
  <div class="card plain">
    <h2>触ってから買えます</h2>
    <p>
      ソフトを買わない最大の理由は「自社で使えるか分からない」です。
      スクリーンショットと機能一覧では、この不安は消えません。<b>触れば10秒で分かります。</b>
    </p>
    <p>
      当ストアの商品には<b>すべてデモがついています</b>。実際に操作し、
      画面と動きを確かめてからご購入ください。
    </p>
  </div>
</section>

<section>
  <div class="card plain">
    <h2>ご購入からご利用までの流れ</h2>
    <ul>
      <li><b>デモを触る</b> — 各アプリのデモサイトで、実際の動きをご確認ください。</li>
      <li><b>注文して決済</b> — 𝕏 でログインし、銀行振込または PayPal でお支払いいただきます。請求書PDFを発行できます。</li>
      <li><b>ダウンロード</b> — お支払い後、この画面からダウンロードできます。</li>
      <li><b>AIに渡して設置</b> — ダウンロードした一式を Claude Code などのAIエージェントに渡すと、
        設置手順書と設計書が同梱されているので、そのまま構築まで進められます。</li>
      <li><b>自分で育てる</b> — 業務が変わったら、AIに頼んで直します。他社への依頼は要りません。</li>
    </ul>
    <p class="hint">動作環境・設置方法は、アプリごとの詳細画面に記載しています。</p>
  </div>
</section>

<section>
  <div class="card plain">
    <h2>動作環境</h2>
    <p>
      <b>PHPが動くレンタルサーバーがあれば動きます。</b>
      データベース・Composer・npm・常駐プロセスは不要で、FTPでアップロードするだけです。
    </p>
    <p>
      日本の小さな会社が持っているのは、たいていPHPが動く共有サーバーです。
      そこにデータベースの設定から始めさせると、多くの方がそこで止まります。
      <b>届く相手を増やすために、必要な条件をここまで下げています。</b>
    </p>
  </div>
</section>

<section>
  <div class="card plain">
    <h2>よくあるご質問</h2>
    <?php foreach ($faq as $f): ?>
    <h3 style="font-size:15px;margin-top:16px"><?php echo kapp_h($f[0]); ?></h3>
    <p style="font-size:14px"><?php echo kapp_h($f[1]); ?></p>
    <?php endforeach; ?>
  </div>
</section>

<section>
  <div class="card plain">
    <h2>出品をお考えの方へ</h2>
    <p>
      当ストアの方針は3つです。<b>買った人がAIに渡すだけで設置できること</b>、
      <b>デモを触ってから買えること</b>、<b>改変が自由であること</b>。
      この3つを満たさないものは並べていません。
    </p>
    <p>
      出品にご興味のある方は<a href="sellers.php">開発元の登録</a>からご連絡ください。
      現在は準備中のため承認制です。
    </p>
  </div>
</section>
</main>
<?php kapp_footer(); ?>
