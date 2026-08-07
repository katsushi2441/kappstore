<?php
// 販売店のライフサイクルの動作確認。メールもネットワークも使わない。
//
// ここが破れると、審査していない相手が出品できたり、振込先が無いまま
// 商品が並んだりする。状態の遷移と、出品可になる条件を重点的に見る。
//
// 実行: php scripts/check_sellers.php

$tmp = sys_get_temp_dir() . '/kappstore-sellers-' . getmypid();
@mkdir($tmp, 0700, true);
define('KAPP_DATA_DIR', $tmp);
define('KAPP_ADMIN', 'xb_bittensor');
define('KAPP_ADMIN_EMAIL', 'sysadmin@example.test');
define('KAPP_MAIL_FROM', 'noreply@example.test');

require_once __DIR__ . '/../public/kapp_lib.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}
function st($u) { return kapp_seller_status(kapp_find_seller($u)); }

/** 詳細登録の入力一式。 */
function details($over = array()) {
    return array_merge(array(
        'name' => '株式会社サンプル', 'company' => '株式会社サンプル', 'contact' => '山田 太郎',
        'tel' => '052-000-0000', 'email' => 'info@example.test', 'url' => 'https://example.test/',
        'addr' => '愛知県名古屋市', 'bank' => '三井住友銀行 上前津支店 普通 1234567 カ）サンプル',
        'invoice_no' => 'T1234567890123', 'agree' => '1',
    ), $over);
}

/* ============================================================
 * 1. 経路B — 本人が応募して、承認され、詳細を登録する
 * ========================================================== */
check('未登録は出品できない', kapp_is_approved_seller('alice'), false);
check('未登録に状態は無い',   st('alice'), '');

// 応募の必須項目
check('会社名なしは弾く', kapp_apply_seller('alice', '', '山田', '052-000-0000', 'a@example.test')[0], false);
check('担当者なしは弾く', kapp_apply_seller('alice', 'A社', '', '052-000-0000', 'a@example.test')[0], false);
check('電話なしは弾く',   kapp_apply_seller('alice', 'A社', '山田', '', 'a@example.test')[0], false);
check('電話が短いと弾く', kapp_apply_seller('alice', 'A社', '山田', '123', 'a@example.test')[0], false);
check('メール不正は弾く', kapp_apply_seller('alice', 'A社', '山田', '052-000-0000', 'bad')[0], false);
check('弾かれたら登録されない', kapp_find_seller('alice'), null);

check('応募できる', kapp_apply_seller('alice', 'A社', '山田 太郎', '052-000-0000', 'a@example.test')[0], true);
check('状態は審査待ち', st('alice'), 'applied');
check('審査待ちでは出品できない', kapp_is_approved_seller('alice'), false);
check('審査待ちでは詳細登録に進めない', kapp_seller_can_complete('alice'), false);
check('審査前に詳細を出しても弾く', kapp_complete_seller('alice', details())[0], false);
check('弾かれても状態は変わらない', st('alice'), 'applied');

// 審査待ちの間は書き直せる
check('応募内容を更新できる',
    kapp_apply_seller('alice', 'A社（更新）', '山田 太郎', '052-000-0000', 'a2@example.test')[0], true);
check('更新が反映される', kapp_find_seller('alice')['email'], 'a2@example.test');
check('重複して増えない', count(kapp_sellers()), 1);

check('承認できる', kapp_approve_seller('alice', true)[0], true);
check('承認後は詳細登録待ち', st('alice'), 'approved');
check('承認だけでは出品できない', kapp_is_approved_seller('alice'), false);
check('承認後は詳細登録に進める', kapp_seller_can_complete('alice'), true);

// 詳細登録の必須項目
check('販売者名なしは弾く', kapp_complete_seller('alice', details(array('name' => '')))[0], false);
check('振込先なしは弾く',   kapp_complete_seller('alice', details(array('bank' => '')))[0], false);
check('登録番号が変なら弾く', kapp_complete_seller('alice', details(array('invoice_no' => 'T12')))[0], false);
check('URLが変なら弾く',     kapp_complete_seller('alice', details(array('url' => 'example.test')))[0], false);
check('弾かれたら出品可にならない', st('alice'), 'approved');

// 同意していなければ出品可にしない。ここが抜けると、手数料や返品の条件に
// 同意していない人が商品を並べられる
check('同意なしは弾く',   kapp_complete_seller('alice', details(array('agree' => '')))[0], false);
check('弾かれたら出品可にならない（同意）', st('alice'), 'approved');
check('詳細を登録できる', kapp_complete_seller('alice', details())[0], true);
check('同意した事実を控える', kapp_find_seller('alice')['agreed_terms'], true);
check('同意した版を控える',   kapp_find_seller('alice')['agreed_version'], KAPP_SELLER_TERMS_VERSION);
check('同意した時刻を控える', is_int(kapp_find_seller('alice')['agreed_at']), true);
check('出品可になる',     st('alice'), 'active');
check('出品できる',       kapp_is_approved_seller('alice'), true);
check('登録番号が整う',   kapp_find_seller('alice')['invoice_no'], 'T1234567890123');
check('振込先が入る', strpos(kapp_find_seller('alice')['bank'], '普通 1234567') !== false, true);

// 登録番号は任意
check('登録番号なしでも登録できる',
    kapp_complete_seller('alice', details(array('invoice_no' => '')))[0], true);
check('空のまま保存される', kapp_find_seller('alice')['invoice_no'], '');

/* ============================================================
 * 2. 経路A — 管理者が招待して、本人が詳細を登録する
 * ========================================================== */
$r = kapp_invite_seller('bob', 'bob@example.test', '展示会で名刺交換');
check('招待できる', $r[0], true);
$bob = $r[1];
check('招待直後の状態', st('bob'), 'invited');
check('招待だけでは出品できない', kapp_is_approved_seller('bob'), false);
check('招待は審査を挟まず詳細登録へ', kapp_seller_can_complete('bob'), true);

check('トークンが発行される', strlen($bob['token']) === 32, true);
check('トークンで引ける', kapp_find_seller_by_token($bob['token'])['x'], 'bob');
check('違うトークンでは引けない', kapp_find_seller_by_token('deadbeef'), null);
check('空のトークンでは引けない', kapp_find_seller_by_token(''), null);
check('案内URLにトークンが入る',
    strpos(kapp_seller_invite_url($bob), $bob['token']) !== false, true);

check('招待は重複できない', kapp_invite_seller('bob')[0], false);
check('応募済みも招待できない', kapp_invite_seller('alice')[0], false);
check('𝕏名が空なら招待できない', kapp_invite_seller('')[0], false);
check('𝕏名に記号は使えない', kapp_invite_seller('bad name!')[0], false);
check('@付きでも招待できる', kapp_invite_seller('@carol')[0], true);
check('@は落として保存', kapp_find_seller('carol')['x'], 'carol');

check('招待された人が詳細を登録できる',
    kapp_complete_seller('bob', details(array('name' => 'B商店')))[0], true);
check('出品可になる', st('bob'), 'active');

/* ============================================================
 * 3. 停止
 * ========================================================== */
check('停止できる', kapp_approve_seller('bob', false)[0], true);
check('状態は停止中', st('bob'), 'suspended');
check('停止中は出品できない', kapp_is_approved_seller('bob'), false);
check('停止中は詳細登録に進めない', kapp_seller_can_complete('bob'), false);
check('停止中に詳細を出しても弾く', kapp_complete_seller('bob', details())[0], false);

// 詳細が埋まっている相手を承認し直すと、そのまま出品可に戻る
check('再承認できる', kapp_approve_seller('bob', true)[0], true);
check('詳細済みなら即出品可', st('bob'), 'active');

// 詳細が無い相手を承認すると詳細登録待ちに留まる
check('未入力の相手は詳細登録待ち止まり', kapp_approve_seller('carol', true)[0], true);
check('carolはまだ出品できない', kapp_is_approved_seller('carol'), false);
check('carolの状態', st('carol'), 'approved');

check('いない相手は承認できない', kapp_approve_seller('nobody', true)[0], false);
check('いない相手は詳細登録できない', kapp_complete_seller('nobody', details())[0], false);
check('未ログインは応募できない', kapp_apply_seller('', 'A社', '山田', '052-000-0000', 'a@example.test')[0], false);

/* ============================================================
 * 4. 旧レコードとの互換
 *
 * status を持たない古い形式が残っていても、出品可否の判定が
 * 変わらないこと。ここが壊れると既存の販売店が締め出される。
 * ========================================================== */
kapp_ledger_update(KAPP_SELLERS, 'sellers', function (&$data) {
    $data['sellers'][] = array('x' => 'legacy_ok', 'name' => '旧承認済', 'approved' => true,
        'created_at' => time(), 'updated_at' => time());
    $data['sellers'][] = array('x' => 'legacy_ng', 'name' => '旧審査待ち', 'approved' => false,
        'created_at' => time(), 'updated_at' => time());
    return array(true, '');
});
check('旧approved=trueは出品可', st('legacy_ok'), 'active');
check('旧approved=trueで出品できる', kapp_is_approved_seller('legacy_ok'), true);
check('旧approved=falseは審査待ち', st('legacy_ng'), 'applied');
check('旧approved=falseは出品できない', kapp_is_approved_seller('legacy_ng'), false);

/* ============================================================
 * 5. 大文字小文字・@ の扱い
 * ========================================================== */
check('大文字でも同じ人', kapp_find_seller('ALICE')['x'], 'alice');
check('@付きでも同じ人',  kapp_find_seller('@alice')['x'], 'alice');
check('大文字でも出品可判定は同じ', kapp_is_approved_seller('Alice'), true);
check('二重登録にならない', count(kapp_sellers()), 5);

/* 後片付け */
foreach (glob($tmp . '/*.json') as $f) { @unlink($f); }
@rmdir($tmp . '/files'); @rmdir($tmp);

echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
