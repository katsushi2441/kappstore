<?php
/**
 * Kurage App Store — 販売店の一覧・登録・審査。
 *
 * 当面の販売者は KAPP_ADMIN（xb_bittensor）のみ。将来のマーケットプレイス化に
 * 備えて登録の受け口は開けておき、承認フラグで出品を止める。
 */
require_once __DIR__ . '/kapp_boot.php';
kapp_handle_auth_links('sellers.php');

$admin_view = $is_admin && isset($_GET['admin']);
$notice = '';
$error = '';

/* ---- 販売店登録 ---- */
if ($logged_in && isset($_POST['register_seller'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } else {
        $name = trim((string)(isset($_POST['seller_name']) ? $_POST['seller_name'] : ''));
        $url  = trim((string)(isset($_POST['seller_url']) ? $_POST['seller_url'] : ''));
        $mail = trim((string)(isset($_POST['seller_email']) ? $_POST['seller_email'] : ''));
        if (mb_strlen($name, 'UTF-8') > 80 || mb_strlen($url, 'UTF-8') > 200) {
            $error = '入力が長すぎます。';
        } elseif ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $error = '通知先メールアドレスをご入力ください。';
        } else {
            $result = kapp_register_seller($user, $name, $url, $mail);
            if (empty($result[0])) { $error = $result[1]; }
            else {
                $notice = $result[1];
                $is_seller = kapp_is_approved_seller($user);
            }
        }
    }
}

/* ---- 審査（管理者のみ） ---- */
if ($is_admin && isset($_POST['approve'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。';
    } else {
        $target = (string)$_POST['approve'];
        $to = !empty($_POST['approved']);
        $result = kapp_approve_seller($target, $to);
        if (empty($result[0])) { $error = $result[1]; } else { $notice = '@' . $target . ' を' . $result[1]; }
    }
}

$sellers = kapp_sellers();
$mine = $logged_in ? kapp_find_seller($user) : null;

// 一覧に出すのは承認済みだけ（審査待ちを晒さない）。管理者画面では全件出す。
$listed = array();
foreach ($sellers as $s) {
    if (!empty($s['approved'])) { $listed[] = $s; }
}

$app_count = array();
foreach (kapp_apps_published() as $app) {
    $k = kapp_norm_user($app['seller']);
    $app_count[$k] = (isset($app_count[$k]) ? $app_count[$k] : 0) + 1;
}

kapp_head('販売店一覧 | Kurage App Store', 'Kurage App Store に出品している販売店の一覧。',
    'https://kappstore.exbridge.jp/sellers.php');
kapp_header('販売店', $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap narrow">
<section>
  <h1><?php echo $admin_view ? '販売店の審査' : '販売店一覧'; ?></h1>

  <?php if ($notice !== ''): ?><p class="ok"><?php echo kapp_h($notice); ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?php echo kapp_h($error); ?></p><?php endif; ?>

<?php if ($admin_view): ?>
  <p class="lead">登録された販売店の承認・取り消しを行います。承認された販売店だけが出品できます。</p>
  <div class="card">
  <?php if (!$sellers): ?>
    <p style="font-size:14px">まだ販売店の登録がありません。</p>
  <?php endif; ?>
  <?php foreach ($sellers as $s): ?>
    <div class="row">
      <div class="grow">
        <b><?php echo kapp_h($s['name']); ?></b>
        <span class="tag <?php echo !empty($s['approved']) ? 'paid' : 'draft'; ?>">
          <?php echo !empty($s['approved']) ? '承認済' : '審査待ち'; ?></span><br>
        <span style="color:var(--abyss-soft);font-size:12.5px">
          @<?php echo kapp_h($s['x']); ?>
          <?php if (!empty($s['url'])): ?> · <?php echo kapp_h($s['url']); ?><?php endif; ?>
          · <?php echo date('Y/n/j', (int)$s['created_at']); ?>登録</span>
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
        <input type="hidden" name="approve" value="<?php echo kapp_h($s['x']); ?>">
        <?php if (empty($s['approved'])): ?>
          <input type="hidden" name="approved" value="1">
          <button type="submit" class="btn" style="padding:7px 16px;font-size:12.5px">承認する</button>
        <?php else: ?>
          <button type="submit" class="btn ghost" style="padding:7px 16px;font-size:12.5px">承認を取り消す</button>
        <?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
  <p><a href="sellers.php">← 販売店一覧へ</a></p>

<?php else: ?>
  <p class="lead">Kurage App Store に出品している販売店です。</p>

  <?php if (!$listed): ?>
    <p class="empty-note">まだ販売店がありません。</p>
  <?php else: ?>
  <div class="card">
    <?php foreach ($listed as $s): $k = kapp_norm_user($s['x']); ?>
    <div class="row">
      <div class="grow">
        <b><?php echo kapp_h($s['name']); ?></b><br>
        <span style="color:var(--abyss-soft);font-size:12.5px">
          <a href="https://x.com/<?php echo kapp_h($s['x']); ?>" target="_blank" rel="noopener nofollow">@<?php echo kapp_h($s['x']); ?></a>
          <?php if (!empty($s['url'])): ?>
            · <a href="<?php echo kapp_h($s['url']); ?>" target="_blank" rel="noopener nofollow"><?php echo kapp_h($s['url']); ?></a>
          <?php endif; ?>
        </span>
      </div>
      <span class="tag">公開 <?php echo isset($app_count[$k]) ? (int)$app_count[$k] : 0; ?> 点</span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card plain">
    <h2>販売店として出品する</h2>
    <?php if (!$logged_in): ?>
      <p style="font-size:14px">出品には 𝕏 でのログインが必要です。</p>
      <p style="margin-top:14px"><a class="btn" href="?login=1">𝕏 でログイン</a></p>
    <?php else: ?>
      <?php if ($mine): ?>
        <p style="font-size:14px">
          登録済みです（<b><?php echo kapp_h($mine['name']); ?></b>）。
          <?php if (empty($mine['approved'])): ?>
            <b>現在は審査待ちです。</b>承認されると出品できるようになります。
          <?php else: ?>
            <a href="register.php">アプリを出品する</a>
          <?php endif; ?>
        </p>
      <?php endif; ?>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
        <label>𝕏 アカウント</label>
        <input type="text" value="@<?php echo kapp_h($user); ?>" disabled
               style="opacity:.7;background:var(--panel)">
        <p class="hint">ログイン中のアカウントで登録されます。</p>

        <label for="seller_name">販売者名<span style="color:#c0392b">*</span></label>
        <input type="text" id="seller_name" name="seller_name" maxlength="80" required
               placeholder="例：株式会社エクスブリッジ"
               value="<?php echo kapp_h($mine ? $mine['name'] : ''); ?>">

        <label for="seller_email">通知先メールアドレス<span style="color:#c0392b">*</span></label>
        <input type="email" id="seller_email" name="seller_email" maxlength="200" required
               placeholder="例：info@example.co.jp"
               value="<?php echo kapp_h($mine && !empty($mine['email']) ? $mine['email'] : ''); ?>">
        <p class="hint"><b>ご注文が入るとここへお知らせします。</b>購入者には表示されません。</p>

        <label for="seller_url">URL（任意）</label>
        <input type="url" id="seller_url" name="seller_url" maxlength="200" placeholder="https://example.com/"
               value="<?php echo kapp_h($mine && !empty($mine['url']) ? $mine['url'] : ''); ?>">
        <p class="hint">会社サイトやポートフォリオなど。購入者に表示されます。</p>

        <button type="submit" name="register_seller" value="1" class="btn" style="margin-top:18px">
          <?php echo $mine ? '販売店情報を更新' : '販売店として登録'; ?></button>
      </form>
      <p class="hint" style="margin-top:14px">
        現在はマーケットプレイス公開の準備中のため、出品は承認制です。
        登録いただいた方から順にご案内します。</p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
</main>
<?php kapp_footer(); ?>
