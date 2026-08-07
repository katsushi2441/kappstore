<?php
/**
 * Kurage App Store — 販売店の一覧・応募・詳細登録・審査。
 *
 * 入り口が2つある（kapp_lib.php の「販売店の状態」を参照）。
 *
 *   管理者が招待 → 案内URLを送る → 本人が詳細登録 → 出品可
 *   本人が応募   → 管理者が承認   → 本人が詳細登録 → 出品可
 *
 * 応募の段階では連絡先しか聞かない。銀行口座まで求めると応募が来ない。
 * 逆に、詳細登録では振込先を必須にする。売上をお振り込みできない状態で
 * 商品を並べさせないため。
 */
require_once __DIR__ . '/kapp_boot.php';

// 案内URL（?t=<token>）で来た人は、ログイン後もここへ戻す
$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
kapp_handle_auth_links('sellers.php' . ($token !== '' ? '?t=' . rawurlencode($token) : ''));

$admin_view = $is_admin && isset($_GET['admin']);
$notice = ''; $error = ''; $invited_url = '';

/* ---- 応募（経路B）---- */
if ($logged_in && isset($_POST['apply'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } else {
        $r = kapp_apply_seller($user,
            isset($_POST['company']) ? $_POST['company'] : '',
            isset($_POST['contact']) ? $_POST['contact'] : '',
            isset($_POST['tel'])     ? $_POST['tel']     : '',
            isset($_POST['email'])   ? $_POST['email']   : '');
        if (empty($r[0])) { $error = $r[1]; }
        else {
            $notice = 'お申し込みを受け付けました。審査のうえ、ご登録のメールアドレスへご連絡します。';
            kapp_notify_seller_applied(kapp_find_seller($user));
        }
    }
}

/* ---- 詳細登録（両経路の合流点）---- */
if ($logged_in && isset($_POST['complete'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } else {
        $f = array();
        foreach (array('name','company','contact','tel','email','url','addr','bank','invoice_no') as $k) {
            $f[$k] = isset($_POST[$k]) ? $_POST[$k] : '';
        }
        $r = kapp_complete_seller($user, $f);
        if (empty($r[0])) { $error = $r[1]; }
        else {
            $notice = $r[1];
            $is_seller = kapp_is_approved_seller($user);
            kapp_notify_seller_active(kapp_find_seller($user));
        }
    }
}

/* ---- 招待（管理者・経路A）---- */
if ($is_admin && isset($_POST['invite'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。';
    } else {
        $r = kapp_invite_seller(
            isset($_POST['invite_x'])     ? $_POST['invite_x']     : '',
            isset($_POST['invite_email']) ? $_POST['invite_email'] : '',
            isset($_POST['invite_memo'])  ? $_POST['invite_memo']  : '');
        if (empty($r[0])) { $error = $r[1]; }
        else {
            $invited_url = kapp_seller_invite_url($r[1]);
            $sent = kapp_notify_seller_invited($r[1], false);
            $notice = '@' . $r[1]['x'] . ' を登録しました。'
                    . ($sent ? 'ご案内メールを送信しました。' : '下記のURLをお送りください。');
        }
    }
}

/* ---- 審査（管理者）---- */
if ($is_admin && isset($_POST['approve'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。';
    } else {
        $target = (string)$_POST['approve'];
        $to = !empty($_POST['approved']);
        $r = kapp_approve_seller($target, $to);
        if (empty($r[0])) { $error = $r[1]; }
        else {
            $notice = '@' . $target . ' を' . $r[1];
            if ($to) { kapp_notify_seller_invited(kapp_find_seller($target), true); }
        }
    }
}

$sellers = kapp_sellers();
$mine    = $logged_in ? kapp_find_seller($user) : null;
$status  = kapp_seller_status($mine);

// 案内URLで来たが、別のアカウントでログインしている場合を拾う
$invite_target = $token !== '' ? kapp_find_seller_by_token($token) : null;
$wrong_account = ($invite_target && $logged_in
                  && kapp_norm_user($invite_target['x']) !== $user);

// 一覧に出すのは出品可の販売店だけ（応募中・審査待ちを晒さない）
$listed = array();
foreach ($sellers as $s) {
    if (kapp_seller_status($s) === 'active') { $listed[] = $s; }
}

$app_count = array();
foreach (kapp_apps_published() as $app) {
    $k = kapp_norm_user($app['seller']);
    $app_count[$k] = (isset($app_count[$k]) ? $app_count[$k] : 0) + 1;
}

kapp_head('販売店 | Kurage App Store', 'Kurage App Store に出品している販売店の一覧と、出品のお申し込み。',
    'https://kappstore.exbridge.jp/sellers.php', $token !== '');
kapp_header('販売店', $logged_in, $user, $is_seller, $is_admin);
if ($logged_in) { kapp_subnav($admin_view ? 'sellers.php?admin=1' : 'sellers.php', $user, $is_seller, $is_admin); }
?>
<main class="wrap narrow">
<section>
  <h1><?php echo $admin_view ? '販売店の管理' : '販売店'; ?></h1>

  <?php if ($notice !== ''): ?><p class="ok"><?php echo kapp_h($notice); ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?php echo kapp_h($error); ?></p><?php endif; ?>
  <?php if ($invited_url !== ''): ?>
    <div class="card plain">
      <p style="font-size:13.5px;margin-bottom:6px">ご案内URL（販売店へお送りください）</p>
      <input type="text" readonly value="<?php echo kapp_h($invited_url); ?>"
             onclick="this.select()" style="font-size:12.5px">
    </div>
  <?php endif; ?>

<?php if ($admin_view): ?>
  <?php /* ================= 管理者 ================= */ ?>
  <div class="card plain">
    <h2>販売店を登録する</h2>
    <p class="hint">こちらからお声掛けした販売店を登録します。審査は挟まず、
      ご案内URLから本人に詳細を登録していただきます。</p>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
      <label for="invite_x">𝕏 アカウント<span style="color:#c0392b">*</span></label>
      <input type="text" id="invite_x" name="invite_x" maxlength="16" required placeholder="例：xb_bittensor">
      <p class="hint">@ は不要です。このアカウントでログインした方だけが登録に進めます。</p>

      <label for="invite_email">ご案内メールの宛先（任意）</label>
      <input type="email" id="invite_email" name="invite_email" maxlength="200" placeholder="例：info@example.co.jp">
      <p class="hint">ご入力いただくと、ご案内URLを自動でお送りします。空のときはURLを画面に表示します。</p>

      <label for="invite_memo">メモ（任意・管理用）</label>
      <input type="text" id="invite_memo" name="invite_memo" maxlength="200">

      <button type="submit" name="invite" value="1" class="btn" style="margin-top:16px">登録してご案内する</button>
    </form>
  </div>

  <h2 style="margin-top:32px">登録されている販売店</h2>
  <div class="card">
  <?php if (!$sellers): ?>
    <p style="font-size:14px">まだ販売店の登録がありません。</p>
  <?php endif; ?>
  <?php foreach ($sellers as $s): $st = kapp_seller_status($s); ?>
    <div class="row">
      <div class="grow">
        <b><?php echo kapp_h($s['name'] !== '' ? $s['name'] : (!empty($s['company']) ? $s['company'] : '（未登録）')); ?></b>
        <span class="tag <?php echo $st === 'active' ? 'paid' : 'draft'; ?>">
          <?php echo kapp_h(kapp_seller_status_label($st)); ?></span><br>
        <span style="color:var(--abyss-soft);font-size:12.5px">
          @<?php echo kapp_h($s['x']); ?>
          <?php if (!empty($s['contact'])): ?> · <?php echo kapp_h($s['contact']); ?> 様<?php endif; ?>
          <?php if (!empty($s['tel'])): ?> · <?php echo kapp_h($s['tel']); ?><?php endif; ?>
          <?php if (!empty($s['email'])): ?> · <?php echo kapp_h($s['email']); ?><?php endif; ?>
          · <?php echo date('Y/n/j', (int)$s['created_at']); ?>
        </span>
        <?php if ($st === 'invited' || $st === 'approved'): ?>
          <br><span style="font-size:11.5px;color:var(--abyss-soft)">
            ご案内URL: <?php echo kapp_h(kapp_seller_invite_url($s)); ?></span>
        <?php endif; ?>
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
        <input type="hidden" name="approve" value="<?php echo kapp_h($s['x']); ?>">
        <?php if ($st === 'applied' || $st === 'suspended'): ?>
          <input type="hidden" name="approved" value="1">
          <button type="submit" class="btn" style="padding:7px 16px;font-size:12.5px">承認する</button>
        <?php elseif ($st !== 'invited'): ?>
          <button type="submit" class="btn ghost" style="padding:7px 16px;font-size:12.5px">停止する</button>
        <?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
  <p><a href="sellers.php">← 販売店一覧へ</a></p>

<?php else: ?>
  <?php /* ================= 一般 ================= */ ?>
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
  <?php if (!$logged_in): ?>
    <h2>販売店として出品する</h2>
    <p style="font-size:14px">出品には 𝕏 でのログインが必要です。</p>
    <p style="margin-top:14px"><a class="btn" href="?login=1<?php echo $token !== '' ? '&t=' . rawurlencode($token) : ''; ?>">𝕏 でログイン</a></p>

  <?php elseif ($wrong_account): ?>
    <h2>アカウントが違います</h2>
    <p class="err">このご案内は <b>@<?php echo kapp_h($invite_target['x']); ?></b> 宛てです。
      現在 @<?php echo kapp_h($user); ?> でログインされています。</p>
    <p style="margin-top:14px"><a class="btn ghost" href="?logout=1">ログアウトして入り直す</a></p>

  <?php elseif ($status === 'applied'): ?>
    <h2>審査中です</h2>
    <p style="font-size:14px"><b>お申し込みを承っています。</b>審査のうえ、
      <?php echo kapp_h($mine['email']); ?> へご連絡します。内容は下記から変更できます。</p>
    <?php $show_apply = true; ?>

  <?php elseif ($status === 'suspended'): ?>
    <h2>現在ご出品いただけません</h2>
    <p style="font-size:14px">お手数ですが、お問い合わせください。</p>

  <?php elseif ($status === 'invited' || $status === 'approved'): ?>
    <h2>販売店情報のご登録</h2>
    <p style="font-size:14px">
      <?php if ($status === 'approved'): ?>
        <b>お申し込みを承認いたしました。</b>
      <?php endif; ?>
      残りの情報をご登録いただくと、ご出品いただけます。</p>
    <?php $show_complete = true; ?>

  <?php elseif ($status === 'active'): ?>
    <h2>ご登録済みです</h2>
    <p style="font-size:14px"><b><?php echo kapp_h($mine['name']); ?></b> として出品いただけます。
      <a href="register.php">アプリを出品する</a> ／ <a href="payout.php">精算を確認する</a></p>
    <p class="hint" style="margin-top:8px">内容の変更は下記から行えます。</p>
    <?php $show_complete = true; ?>

  <?php else: ?>
    <h2>販売店として出品する</h2>
    <p style="font-size:14px">まずはご連絡先をお知らせください。審査のうえ、
      残りの情報のご登録をご案内します。</p>
    <p class="hint">出品手数料は <b>販売価格（税抜）の10％ ＋ 40,000円（税別）</b>で、
      初期費用はいただきません。売れたときだけ発生します。販売価格は 100,000円（税別）からです。</p>
    <?php $show_apply = true; ?>
  <?php endif; ?>

  <?php /* ---- 応募フォーム ---- */ ?>
  <?php if (!empty($show_apply)): ?>
    <form method="post" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
      <label>𝕏 アカウント</label>
      <input type="text" value="@<?php echo kapp_h($user); ?>" disabled style="opacity:.7;background:var(--panel)">

      <label for="company">会社名（屋号）<span style="color:#c0392b">*</span></label>
      <input type="text" id="company" name="company" maxlength="80" required placeholder="例：株式会社サンプル"
             value="<?php echo kapp_h($mine && !empty($mine['company']) ? $mine['company'] : ''); ?>">

      <label for="contact">ご担当者名<span style="color:#c0392b">*</span></label>
      <input type="text" id="contact" name="contact" maxlength="40" required placeholder="例：山田 太郎"
             value="<?php echo kapp_h($mine && !empty($mine['contact']) ? $mine['contact'] : ''); ?>">

      <label for="tel">お電話番号<span style="color:#c0392b">*</span></label>
      <input type="tel" id="tel" name="tel" maxlength="20" required placeholder="例：052-000-0000"
             value="<?php echo kapp_h($mine && !empty($mine['tel']) ? $mine['tel'] : ''); ?>">

      <label for="email">メールアドレス<span style="color:#c0392b">*</span></label>
      <input type="email" id="email" name="email" maxlength="200" required placeholder="例：info@example.co.jp"
             value="<?php echo kapp_h($mine && !empty($mine['email']) ? $mine['email'] : ''); ?>">
      <p class="hint">審査結果のご連絡と、ご注文の通知先になります。<b>購入者には表示されません。</b></p>

      <button type="submit" name="apply" value="1" class="btn" style="margin-top:16px">
        <?php echo $status === 'applied' ? 'お申し込み内容を更新' : '出品を申し込む'; ?></button>
    </form>
  <?php endif; ?>

  <?php /* ---- 詳細登録フォーム ---- */ ?>
  <?php if (!empty($show_complete)): ?>
    <form method="post" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">
      <label>𝕏 アカウント</label>
      <input type="text" value="@<?php echo kapp_h($user); ?>" disabled style="opacity:.7;background:var(--panel)">

      <label for="name">販売者名<span style="color:#c0392b">*</span></label>
      <input type="text" id="name" name="name" maxlength="80" required placeholder="例：株式会社サンプル"
             value="<?php echo kapp_h(!empty($mine['name']) ? $mine['name'] : (!empty($mine['company']) ? $mine['company'] : '')); ?>">
      <p class="hint"><b>商品ページに表示されます。</b></p>

      <label for="company">会社名（屋号）<span style="color:#c0392b">*</span></label>
      <input type="text" id="company" name="company" maxlength="80" required
             value="<?php echo kapp_h(!empty($mine['company']) ? $mine['company'] : ''); ?>">

      <label for="contact">ご担当者名<span style="color:#c0392b">*</span></label>
      <input type="text" id="contact" name="contact" maxlength="40" required
             value="<?php echo kapp_h(!empty($mine['contact']) ? $mine['contact'] : ''); ?>">

      <label for="tel">お電話番号<span style="color:#c0392b">*</span></label>
      <input type="tel" id="tel" name="tel" maxlength="20" required
             value="<?php echo kapp_h(!empty($mine['tel']) ? $mine['tel'] : ''); ?>">

      <label for="email">メールアドレス<span style="color:#c0392b">*</span></label>
      <input type="email" id="email" name="email" maxlength="200" required
             value="<?php echo kapp_h(!empty($mine['email']) ? $mine['email'] : ''); ?>">
      <p class="hint">ご注文が入るとここへお知らせします。購入者には表示されません。</p>

      <label for="addr">ご住所</label>
      <input type="text" id="addr" name="addr" maxlength="200" placeholder="例：愛知県名古屋市…"
             value="<?php echo kapp_h(!empty($mine['addr']) ? $mine['addr'] : ''); ?>">

      <label for="bank">売上のお振込先<span style="color:#c0392b">*</span></label>
      <input type="text" id="bank" name="bank" maxlength="200" required
             placeholder="例：三井住友銀行 上前津支店 普通 1234567 カ）サンプル"
             value="<?php echo kapp_h(!empty($mine['bank']) ? $mine['bank'] : ''); ?>">
      <p class="hint">銀行名・支店名・種別・口座番号・口座名義を続けてご入力ください。
        <b>お売り上げのお振り込みに使います。</b></p>

      <label for="invoice_no">適格請求書発行事業者の登録番号（任意）</label>
      <input type="text" id="invoice_no" name="invoice_no" maxlength="20" placeholder="T1234567890123"
             value="<?php echo kapp_h(!empty($mine['invoice_no']) ? $mine['invoice_no'] : ''); ?>">
      <p class="hint">「T」＋13桁。お支払明細書に記載します。
        <b>登録がなくてもご出品・お支払いに支障はありません。</b></p>

      <label for="url">URL（任意）</label>
      <input type="url" id="url" name="url" maxlength="200" placeholder="https://example.com/"
             value="<?php echo kapp_h(!empty($mine['url']) ? $mine['url'] : ''); ?>">
      <p class="hint">会社サイトなど。購入者に表示されます。</p>

      <button type="submit" name="complete" value="1" class="btn" style="margin-top:18px">
        <?php echo $status === 'active' ? '販売店情報を更新' : '登録して出品を始める'; ?></button>
    </form>
  <?php endif; ?>
  </div>
</section>
<?php endif; ?>
</main>
<?php kapp_footer(); ?>
