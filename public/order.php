<?php
/**
 * Kurage App Store — 注文・決済。
 *
 * vibe-prototype.php の注文フローを継承する。違いは金額が固定ではなく
 * アプリごとの価格になること、支払い完了でダウンロードが開くこと。
 * 無料アプリは決済を挟まず、注文と同時に受け取れる。
 */
require_once __DIR__ . '/kapp_boot.php';
require_once __DIR__ . '/kapp_invoice.php';

if (!defined('KAPP_PAYPAL_CLIENT_ID')) { define('KAPP_PAYPAL_CLIENT_ID', ''); }

$app_id = isset($_GET['app']) ? (string)$_GET['app'] : '';
kapp_handle_auth_links('order.php?app=' . rawurlencode($app_id));

/* ---- 請求書PDF ---- */
if (isset($_GET['invoice'])) {
    if (!$logged_in) { http_response_code(401); exit('ログインが必要です'); }
    $order = kapp_find_order($user, (string)$_GET['invoice']);
    if (!$order) { http_response_code(404); exit('注文が見つかりません'); }
    $pdf = kapp_invoice_pdf($order);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $order['invoice_no'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, max-age=0');
    echo $pdf;
    exit;
}

/* ---- PayPal決済の記録 ---- */
if ($logged_in && isset($_GET['paid'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sent = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if (!$sent || !hash_equals($csrf, $sent)) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'message' => 'CSRF検証に失敗しました'));
        exit;
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    $result = kapp_mark_paid($user,
        isset($input['order_id']) ? (string)$input['order_id'] : '',
        isset($input['paypal_order_id']) ? (string)$input['paypal_order_id'] : '');
    echo json_encode(array('ok' => !empty($result[0]), 'message' => isset($result[1]) ? $result[1] : ''),
        JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- 表示する注文 / 対象アプリ ---- */
$current = ($logged_in && isset($_GET['order'])) ? kapp_find_order($user, (string)$_GET['order']) : null;
if ($current) { $app_id = $current['app_id']; }
$app = kapp_find_app($app_id);
if (!$app) {
    http_response_code(404);
    kapp_head('見つかりません | Kurage App Store', 'アプリが見つかりません。',
        'https://kappstore.exbridge.jp/order.php', true);
    kapp_header('注文', $logged_in, $user, $is_seller, $is_admin);
    echo '<main class="wrap narrow"><p class="empty-note">アプリが見つかりません。<br>'
       . '<a href="index.php">アプリ一覧へ戻る</a></p></main>';
    kapp_footer();
    exit;
}
$p = kapp_price_parts($app['price']);
$is_free = ($p['total'] === 0);

/* ---- 注文登録 ---- */
$error = '';
if ($logged_in && isset($_POST['register'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } else {
        $billing = trim((string)(isset($_POST['billing_name']) ? $_POST['billing_name'] : ''));
        $contact = trim((string)(isset($_POST['contact']) ? $_POST['contact'] : ''));
        $method  = (isset($_POST['method']) && $_POST['method'] === 'paypal') ? 'paypal' : 'bank';
        $email   = trim((string)(isset($_POST['email']) ? $_POST['email'] : ''));
        if ($is_free) { $billing = ($billing !== '') ? $billing : '@' . $user; $method = 'free'; }
        if ($billing === '') {
            $error = '請求先名をご入力ください。';
        } elseif (!$is_free && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $error = 'メールアドレスをご入力ください。';
        } elseif (mb_strlen($billing, 'UTF-8') > 100 || mb_strlen($contact, 'UTF-8') > 60) {
            $error = '入力が長すぎます。';
        } elseif (empty($_POST['agree'])) {
            $error = '利用規約への同意が必要です。';
        } else {
            $result = kapp_create_order($user, $app, $billing, $contact, $method, $email);
            if (empty($result[0])) {
                $error = isset($result[1]) ? $result[1] : '注文を登録できませんでした。';
            } else {
                // 注文が入ったことを知らせる。通知が無いと、銀行振込の
                // 入金確認をするタイミングを見失う。
                $new = kapp_find_order($user, $result[1]);
                if ($new) {
                    kapp_send_order_mail($new);        // 出品者（無ければ管理者）へ
                    kapp_send_buyer_order_mail($new);  // 購入者へ控え
                }
                // POSTの再送で二重登録されないよう、GETへ逃がす
                header('Location: order.php?order=' . rawurlencode($result[1]));
                exit;
            }
        }
    }
}

kapp_head('注文 | ' . $app['name'] . ' | Kurage App Store',
    $app['name'] . ' の注文手続き。', 'https://kappstore.exbridge.jp/order.php', true);
kapp_header('注文', $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap narrow">

<?php if (!$logged_in): ?>
<section>
  <h1>注文</h1>
  <p class="lead"><b><?php echo kapp_h($app['name']); ?></b> のご購入には
    <b>𝕏（旧Twitter）アカウントでのログイン</b>が必要です。</p>
  <div class="gate">
    <?php if ($is_free): ?><p class="price">無料</p>
    <?php else: ?><p class="price"><?php echo number_format($p['total']); ?>円<small>税込</small></p><?php endif; ?>
  </div>
  <div class="card">
    <h2>お手続きの流れ</h2>
    <ul>
      <li><b>𝕏 でログイン</b>し、請求先をご登録ください。</li>
      <li>登録すると<b>請求書PDF</b>をダウンロードできます。</li>
      <li>お支払いは<b>銀行振込</b>または <b>PayPal</b>（カード可）。</li>
      <li>お支払い後、<b>この画面からダウンロード</b>できます。</li>
    </ul>
  </div>
  <p style="text-align:center;margin-top:26px">
    <a class="btn" href="?login=1&amp;app=<?php echo kapp_h($app['id']); ?>">𝕏 でログインして進む</a></p>
  <p style="text-align:center"><a href="app.php?id=<?php echo kapp_h($app['id']); ?>">アプリの説明に戻る</a></p>
</section>

<?php elseif ($current): ?>
<section>
  <h1><?php echo $current['status'] === 'paid' ? 'ご購入ありがとうございます' : 'ご注文を承りました'; ?></h1>
  <p class="lead"><b><?php echo kapp_h($current['app_name']); ?></b><br>
    請求書番号 <b><?php echo kapp_h($current['invoice_no']); ?></b>
    （<?php echo kapp_h($current['billing_name']); ?> 様）</p>

  <?php if ($current['status'] === 'paid'): ?>
    <div class="gate">
      <p class="price">ダウンロードできます</p>
      <p style="margin-top:14px">
        <a class="btn" href="download.php?id=<?php echo kapp_h($current['app_id']); ?>">ダウンロード</a></p>
      <p class="hint">購入履歴からいつでも再ダウンロードいただけます。</p>
    </div>
    <?php if ((int)$current['total'] > 0): ?>
    <p style="margin-bottom:18px">
      <a class="btn ghost" href="?invoice=<?php echo kapp_h($current['id']); ?>">請求書PDFをダウンロード</a></p>
    <?php endif; ?>
    <div class="card">
      <h2>設置のしかた</h2>
      <p style="font-size:14px">ダウンロードした一式を、そのまま
        <b>Claude Code などのAIエージェントに渡してください。</b>
        同梱の設置手順書と設計書をAIが読み、構築まで進められます。</p>
      <p class="hint">レンタルサーバーへ設置する場合は、FTPの接続情報をお手元にご用意ください。</p>
    </div>
  <?php else: ?>
    <div class="gate">
      <p class="price"><?php echo number_format((int)$current['total']); ?>円<small>税込</small></p>
      <p style="margin-top:12px">
        <a class="btn ghost" href="?invoice=<?php echo kapp_h($current['id']); ?>">請求書PDFをダウンロード</a></p>
    </div>
    <div class="card">
      <h2>銀行振込でお支払い</h2>
      <table class="kv">
        <tr><th>金融機関</th><td>三井住友銀行 上前津支店</td></tr>
        <tr><th>口座</th><td>普通 7312531</td></tr>
        <tr><th>口座名義</th><td>カ）エクスブリッジ（株式会社エクスブリッジ）</td></tr>
        <tr><th>金額</th><td><?php echo number_format((int)$current['total']); ?>円（税込）</td></tr>
      </table>
      <p class="hint">振込手数料はお客様のご負担でお願いいたします。
        お振込みの際は請求書番号（<?php echo kapp_h($current['invoice_no']); ?>）をご記入ください。
        <b>ご入金の確認後にダウンロードが開きます。</b>確認できましたら、ご登録のメールアドレスへご連絡します。</p>
    </div>
    <?php if (KAPP_PAYPAL_CLIENT_ID !== ''): ?>
    <div class="card">
      <h2>PayPalでお支払い</h2>
      <p class="hint" style="margin-bottom:10px">お支払いが完了すると、すぐにダウンロードできます。</p>
      <div id="paypalButtons"></div>
      <p class="hint" id="payMsg">カード決済もPayPal経由でご利用いただけます。</p>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <p style="margin-top:20px">
    <a href="app.php?id=<?php echo kapp_h($current['app_id']); ?>">アプリの説明へ</a> ·
    <a href="orders.php">購入履歴へ</a></p>
</section>

<?php if ($current['status'] !== 'paid' && KAPP_PAYPAL_CLIENT_ID !== ''): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?php echo rawurlencode(KAPP_PAYPAL_CLIENT_ID); ?>&currency=JPY"></script>
<script>
(function () {
  var box = document.getElementById('paypalButtons');
  if (!box || !window.paypal) { return; }
  var msg = document.getElementById('payMsg');
  paypal.Buttons({
    style: { layout: 'vertical', height: 42 },
    createOrder: function (data, actions) {
      return actions.order.create({
        purchase_units: [{
          description: <?php echo json_encode(mb_strimwidth($current['app_name'], 0, 100, '', 'UTF-8')); ?>,
          amount: { currency_code: 'JPY', value: '<?php echo (int)$current['total']; ?>' }
        }]
      });
    },
    onApprove: function (data, actions) {
      return actions.order.capture().then(function (details) {
        return fetch('?paid=1', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?php echo json_encode($csrf); ?> },
          body: JSON.stringify({ order_id: <?php echo json_encode($current['id']); ?>, paypal_order_id: details.id })
        }).then(function (r) { return r.json(); }).then(function (res) {
          msg.textContent = res.ok ? 'お支払いを受け付けました。画面を更新します。' : ('記録に失敗しました: ' + res.message);
          if (res.ok) { setTimeout(function () { location.reload(); }, 1200); }
        });
      });
    },
    onError: function () { msg.textContent = 'PayPal決済でエラーが発生しました。時間をおいて再試行してください。'; }
  }).render('#paypalButtons');
})();
</script>
<?php endif; ?>

<?php else: ?>
<section>
  <h1><?php echo $is_free ? '受け取り手続き' : '注文'; ?></h1>
  <p class="lead"><b><?php echo kapp_h($app['name']); ?></b></p>

  <div class="gate">
    <?php if ($is_free): ?><p class="price">無料</p>
    <?php else: ?>
      <p class="price"><?php echo number_format($p['total']); ?>円<small>税込</small></p>
      <p style="font-size:13.5px;margin-top:6px">
        本体 <?php echo number_format($p['amount']); ?>円 ＋ 消費税 <?php echo number_format($p['tax']); ?>円</p>
    <?php endif; ?>
  </div>

  <?php if ($error !== ''): ?><p class="err"><?php echo kapp_h($error); ?></p><?php endif; ?>

  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">

    <?php if (!$is_free): ?>
      <h2>請求先</h2>
      <label for="billing_name">請求先名（会社名・屋号・氏名）<span style="color:#c0392b">*</span></label>
      <input type="text" id="billing_name" name="billing_name" maxlength="100" required
             placeholder="例：株式会社エクスブリッジ"
             value="<?php echo kapp_h(isset($_POST['billing_name']) ? $_POST['billing_name'] : ''); ?>">
      <p class="hint">請求書の宛名になります。「御中」は自動で付きます。</p>

      <label for="contact">ご担当者名（任意）</label>
      <input type="text" id="contact" name="contact" maxlength="60" placeholder="例：山田 太郎"
             value="<?php echo kapp_h(isset($_POST['contact']) ? $_POST['contact'] : ''); ?>">

      <label for="email">メールアドレス<span style="color:#c0392b">*</span></label>
      <input type="email" id="email" name="email" maxlength="200" required
             placeholder="例：keiri@example.co.jp"
             value="<?php echo kapp_h(isset($_POST['email']) ? $_POST['email'] : ''); ?>">
      <p class="hint">銀行振込の場合、<b>ご入金を確認したらこちらへご連絡します。</b></p>

      <label>お支払方法<span style="color:#c0392b">*</span></label>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin-top:8px">
        <label class="card plain" style="padding:14px 16px;margin:0;cursor:pointer;box-shadow:none">
          <input type="radio" name="method" value="bank" checked>
          <b style="font-size:14px">銀行振込</b><br>
          <span style="font-size:12.5px;color:var(--abyss-soft)">ご入金確認後にダウンロードが開きます</span></label>
        <label class="card plain" style="padding:14px 16px;margin:0;cursor:pointer;box-shadow:none">
          <input type="radio" name="method" value="paypal"
            <?php echo (isset($_POST['method']) && $_POST['method'] === 'paypal') ? 'checked' : ''; ?>>
          <b style="font-size:14px">PayPal</b><br>
          <span style="font-size:12.5px;color:var(--abyss-soft)">決済後すぐダウンロードできます（カード可）</span></label>
      </div>
    <?php endif; ?>

    <label class="card plain" style="display:flex;gap:10px;align-items:flex-start;padding:14px 16px;
           margin:18px 0;font-size:13.5px;box-shadow:none">
      <input type="checkbox" name="agree" value="1" required style="margin-top:6px;width:17px;height:17px;flex:none">
      <span><a href="https://kurage.exbridge.jp/terms.html" target="_blank" rel="noopener">Kurage 利用規約</a>
        と<a href="https://kurage.exbridge.jp/tokusho.php" target="_blank" rel="noopener">特定商取引法に基づく表記</a>に同意し、
        次の4点を確認しました。<br>
        ① これは<b>プロトタイプ</b>であり、<b>動作は保証されていません</b>。環境によっては動かない可能性があります。<br>
        ② 設置と問題解決は、<b>Claude Code / Codex などのAIエージェントに相談しながら自分で進めます</b>。<br>
        ③ <b>問い合わせへの対応は約束されておらず</b>、購入代金に個別サポートは含まれません。<br>
        ④ <b>ノークレーム・ノーリターン。</b>ダウンロード商品のため購入後の返品・返金はできません。
        デモで動作を確認のうえ購入します。</span>
    </label>

    <button type="submit" name="register" value="1" class="btn">
      <?php echo $is_free ? '無料で受け取る' : '注文して請求書を発行'; ?></button>
  </form>

  <p><a href="app.php?id=<?php echo kapp_h($app['id']); ?>">← アプリの説明に戻る</a></p>
</section>
<?php endif; ?>

</main>
<?php kapp_footer(); ?>
