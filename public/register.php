<?php
/**
 * Kurage App Store — アプリの出品登録・編集。
 *
 * 承認済みの販売店だけが使える。配布ファイルは kapp_data/files/ に、
 * 画像は kapp_media/ に置く。配布ファイルはWebから直接落とせない場所に
 * 置き、download.php の購入判定を必ず通す。
 */
require_once __DIR__ . '/kapp_boot.php';
kapp_handle_auth_links('register.php');

define('KAPP_MEDIA_DIR', __DIR__ . '/kapp_media');
define('KAPP_MAX_FILE', 64 * 1024 * 1024);  // 配布ファイル 64MB
define('KAPP_MAX_IMAGE', 4 * 1024 * 1024);  // 画像 4MB

$edit_id = isset($_GET['edit']) ? (string)$_GET['edit'] : '';
$editing = $edit_id !== '' ? kapp_find_app($edit_id) : null;
if ($editing && kapp_norm_user($editing['seller']) !== $user && !$is_admin) { $editing = null; }

$error = '';
$notice = '';

/** アップロードを受け取って保存する。戻り値 array(保存名, 元のファイル名, サイズ) か null。 */
function kapp_take_upload($field, $dir, $max, $allowed_ext, &$error) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) { return null; }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $error = 'ファイルのアップロードに失敗しました（コード ' . (int)$f['error'] . '）。';
        return null;
    }
    if ($f['size'] > $max) {
        $error = 'ファイルが大きすぎます（上限 ' . number_format($max / 1024 / 1024, 0) . 'MB）。';
        return null;
    }
    $orig = basename((string)$f['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        $error = '対応していない形式です（' . implode(' / ', $allowed_ext) . ' のみ）。';
        return null;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0705, true)) {
        $error = '保存先を作成できませんでした。';
        return null;
    }
    // 保存名は自前で採番する。アップロード名をそのまま使うと上書きと推測が起きる。
    $stored = kapp_random_hex(12) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        $error = 'ファイルを保存できませんでした。';
        return null;
    }
    @chmod($dir . '/' . $stored, 0604);
    return array($stored, $orig, (int)$f['size']);
}

if ($logged_in && $is_seller && isset($_POST['save'])) {
    if (!kapp_csrf_ok($csrf)) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } else {
        $name    = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $summary = trim((string)(isset($_POST['summary']) ? $_POST['summary'] : ''));
        $body    = trim((string)(isset($_POST['body']) ? $_POST['body'] : ''));
        $demo    = trim((string)(isset($_POST['demo_url']) ? $_POST['demo_url'] : ''));
        $price   = (int)(isset($_POST['price']) ? $_POST['price'] : 0);
        $status  = (isset($_POST['status']) && $_POST['status'] === 'published') ? 'published' : 'draft';

        if ($name === '' || $summary === '') {
            $error = 'システム名と概要は必須です。';
        } elseif (mb_strlen($name, 'UTF-8') > 80) {
            $error = 'システム名が長すぎます（80文字まで）。';
        } elseif (mb_strlen($summary, 'UTF-8') > 300 || mb_strlen($body, 'UTF-8') > 8000) {
            $error = '説明が長すぎます。';
        } elseif ($demo !== '' && !preg_match('#^https?://#i', $demo)) {
            $error = 'デモサイトURLは http:// または https:// で始めてください。';
        } elseif ($price < 0 || $price > 10000000) {
            $error = '価格は 0 〜 10,000,000 円で指定してください。';
        } else {
            $file  = kapp_take_upload('file', KAPP_FILES, KAPP_MAX_FILE,
                        array('zip', 'gz', 'tgz', 'tar', '7z'), $error);
            $image = ($error === '') ? kapp_take_upload('image', KAPP_MEDIA_DIR, KAPP_MAX_IMAGE,
                        array('png', 'jpg', 'jpeg', 'webp', 'gif'), $error) : null;

            if ($error === '' && $image !== null && @getimagesize(KAPP_MEDIA_DIR . '/' . $image[0]) === false) {
                @unlink(KAPP_MEDIA_DIR . '/' . $image[0]);
                $image = null;
                $error = '画像として読み取れませんでした。';
            }

            if ($error === '') {
                $app = $editing ? $editing : array(
                    'id'         => kapp_random_hex(8),
                    'seller'     => $user,
                    'created_at' => time(),
                    'file'       => '',
                    'filename'   => '',
                    'filesize'   => 0,
                    'image'      => '',
                );
                $app['name']       = $name;
                $app['summary']    = $summary;
                $app['body']       = $body;
                $app['demo_url']   = $demo;
                $app['price']      = $price;
                $app['status']     = $status;
                $app['updated_at'] = time();
                if ($file !== null) {
                    // 差し替え時は古いファイルを消す（残すと容量を食い続ける）
                    if (!empty($app['file']) && is_file(KAPP_FILES . '/' . $app['file'])) {
                        @unlink(KAPP_FILES . '/' . $app['file']);
                    }
                    $app['file'] = $file[0]; $app['filename'] = $file[1]; $app['filesize'] = $file[2];
                }
                if ($image !== null) {
                    if (!empty($app['image']) && is_file(KAPP_MEDIA_DIR . '/' . $app['image'])) {
                        @unlink(KAPP_MEDIA_DIR . '/' . $app['image']);
                    }
                    $app['image'] = $image[0];
                }
                if ($status === 'published' && empty($app['file'])) {
                    $error = '配布ファイルを登録しないと公開できません。下書きとして保存されました。';
                    $app['status'] = 'draft';
                }
                $result = kapp_save_app($app);
                if (empty($result[0])) {
                    $error = '保存できませんでした。';
                } else {
                    header('Location: register.php?edit=' . rawurlencode($app['id']) . '&saved=1');
                    exit;
                }
            }
        }
    }
}

if (isset($_GET['saved'])) { $notice = '保存しました。'; }

$v = function ($key, $default = '') use ($editing) {
    if (isset($_POST[$key])) { return (string)$_POST[$key]; }
    return $editing && isset($editing[$key]) ? (string)$editing[$key] : $default;
};

kapp_head('出品する | Kurage App Store', 'Kurage App Store にアプリを出品します。',
    'https://kappstore.exbridge.jp/register.php', true);
kapp_header('出品', $logged_in, $user, $is_seller, $is_admin);
?>
<main class="wrap narrow">
<section>
  <h1><?php echo $editing ? '出品を編集' : 'アプリを出品する'; ?></h1>

  <?php if ($notice !== ''): ?><p class="ok"><?php echo kapp_h($notice); ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?php echo kapp_h($error); ?></p><?php endif; ?>

<?php if (!$logged_in): ?>
  <p class="lead">出品には 𝕏 でのログインが必要です。</p>
  <p><a class="btn" href="?login=1">𝕏 でログイン</a></p>

<?php elseif (!$is_seller): ?>
  <p class="lead">出品するには、販売店の登録と承認が必要です。</p>
  <p><a class="btn" href="sellers.php">販売店登録へ</a></p>

<?php else: ?>
  <form method="post" enctype="multipart/form-data" class="card">
    <input type="hidden" name="csrf" value="<?php echo kapp_h($csrf); ?>">

    <label for="name">システム名<span style="color:#c0392b">*</span></label>
    <input type="text" id="name" name="name" maxlength="80" required
           placeholder="例：見積書作成システム" value="<?php echo kapp_h($v('name')); ?>">
    <p class="hint">何の業務を解決するかが分かる名前にしてください。</p>

    <label for="summary">システム概要<span style="color:#c0392b">*</span></label>
    <textarea id="summary" name="summary" maxlength="300" required style="min-height:80px"
      placeholder="一覧に表示される短い説明。80文字程度が読みやすいです。"><?php echo kapp_h($v('summary')); ?></textarea>

    <label for="body">詳しい説明</label>
    <textarea id="body" name="body" maxlength="8000"
      placeholder="機能、動作環境（PHPのバージョン、DBの要否など）、設置方法、ライセンスなど。&#10;購入者はこの内容をAIに読ませて設置します。"><?php echo kapp_h($v('body')); ?></textarea>
    <p class="hint">動作環境と設置手順は必ず書いてください。購入後のサポートを減らす一番の近道です。</p>

    <label for="demo_url">デモサイトURL</label>
    <input type="url" id="demo_url" name="demo_url" maxlength="300" placeholder="https://demo.example.com/"
           value="<?php echo kapp_h($v('demo_url')); ?>">
    <p class="hint">触ってから買えることが、このお店の一番の売りです。できるだけご用意ください。</p>

    <label for="price">価格（税別・円）<span style="color:#c0392b">*</span></label>
    <input type="number" id="price" name="price" min="0" max="10000000" step="100" required
           value="<?php echo kapp_h($v('price', '0')); ?>">
    <p class="hint">0 を指定すると無料配布になります。表示は税込（10%）に換算されます。</p>

    <label for="file">ダウンロードファイル<?php echo $editing && !empty($editing['file']) ? '（差し替える場合のみ）' : ''; ?></label>
    <input type="file" id="file" name="file" accept=".zip,.gz,.tgz,.tar,.7z">
    <p class="hint">zip / tar.gz など。上限 <?php echo (int)(KAPP_MAX_FILE / 1024 / 1024); ?>MB。
      <?php if ($editing && !empty($editing['filename'])): ?>
        現在：<b><?php echo kapp_h($editing['filename']); ?></b>
        （<?php echo number_format((int)$editing['filesize'] / 1024, 0); ?> KB）
      <?php endif; ?></p>

    <label for="image">スクリーンショット<?php echo $editing && !empty($editing['image']) ? '（差し替える場合のみ）' : ''; ?></label>
    <input type="file" id="image" name="image" accept="image/*">
    <p class="hint">一覧と詳細に表示されます。横長（16:10 前後）が収まりよく出ます。上限
      <?php echo (int)(KAPP_MAX_IMAGE / 1024 / 1024); ?>MB。</p>

    <label for="status">公開状態</label>
    <select id="status" name="status">
      <option value="draft" <?php echo $v('status') === 'published' ? '' : 'selected'; ?>>下書き（一覧に出さない）</option>
      <option value="published" <?php echo $v('status') === 'published' ? 'selected' : ''; ?>>公開する</option>
    </select>
    <p class="hint">公開には配布ファイルの登録が必要です。</p>

    <button type="submit" name="save" value="1" class="btn" style="margin-top:20px">
      <?php echo $editing ? '保存する' : '出品する'; ?></button>
    <?php if ($editing): ?>
      <a class="btn ghost" href="app.php?id=<?php echo kapp_h($editing['id']); ?>" style="margin-left:8px">表示を確認</a>
    <?php endif; ?>
  </form>

  <?php $mine = kapp_seller_apps($user); if ($mine): ?>
  <div class="card plain">
    <h2>あなたの出品</h2>
    <?php foreach ($mine as $app): $p = kapp_price_parts($app['price']); ?>
    <div class="row">
      <div class="grow">
        <b><?php echo kapp_h($app['name']); ?></b>
        <span class="tag <?php echo $app['status'] === 'published' ? 'paid' : 'draft'; ?>">
          <?php echo $app['status'] === 'published' ? '公開中' : '下書き'; ?></span><br>
        <span style="color:var(--abyss-soft);font-size:12.5px">
          <?php echo $p['total'] === 0 ? '無料' : number_format($p['total']) . '円（税込）'; ?>
          · <?php echo date('Y/n/j', (int)$app['created_at']); ?></span>
      </div>
      <a class="chip" href="register.php?edit=<?php echo kapp_h($app['id']); ?>">編集</a>
      <a class="chip" href="app.php?id=<?php echo kapp_h($app['id']); ?>">表示</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
</section>
</main>
<?php kapp_footer(); ?>
