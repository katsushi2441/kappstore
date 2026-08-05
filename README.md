# kappstore — Kurage App Store

**AIが設置できる業務システムのダウンロード販売。** <https://kappstore.exbridge.jp/>

デモを触ってから買い、買ったらダウンロードした一式をAIエージェント（Claude Code など）に
渡すだけで設置まで進められる、という買い方のお店です。

```
Claude Codeが探す → デモURLを人間に渡す → 人間が触る → 人間が決済
                                                    → DLしてAIが設置・構築
```

決済のところだけ人間が承認するので、KYCの要る決済代行のアカウント作成をエージェントに
やらせる必要がありません。

## 構成

heteml の素のPHPだけで動きます。**常駐プロセスもDBもポートも使いません。**

| ファイル | 役割 |
|---|---|
| `public/index.php` | アプリ一覧（トップ）。検索つき |
| `public/app.php` | アプリ詳細。デモ導線と購入導線 |
| `public/order.php` | 注文・決済。請求書PDF／銀行振込／PayPal |
| `public/download.php` | ダウンロード配布。購入判定はここだけ |
| `public/register.php` | 出品登録・編集（承認済み販売店のみ） |
| `public/sellers.php` | 販売店一覧・登録・審査 |
| `public/orders.php` | 購入履歴・再ダウンロード |
| `public/kapp_lib.php` | 台帳（販売店・アプリ・注文） |
| `public/kapp_ui.php` | 共通のガワとCSS |
| `public/kapp_invoice.php` | 請求書PDF生成（外部ライブラリ非依存） |

データは `public/kapp_data/*.json` に flock 付きで書きます。配布ファイルは
`public/kapp_data/files/` に置き、`.htaccess` でWebから直接読めないようにして、
`download.php` の購入判定を必ず通します。

## 継承しているもの

- **注文フロー** — `kurage_web/vibe-prototype.php`
- **請求書PDF** — `kurage_web/vibe_invoice.php`（PDFの組み方を直したら両方に反映）
- **認証** — `kurage_web/auth_common.php` をそのまま配置。OAuth自体は
  `aiknowledgecms.exbridge.jp` が受け持ち、セッションCookieが `.exbridge.jp` で
  共有されるため、**この配下に X の鍵は置きません**
- **配色・部品** — Kurage シリーズ共通のトークン

## 販売者

当面は `KAPP_ADMIN`（`xb_bittensor`）のみが販売します。将来のマーケットプレイス化に
備えて登録の受け口は開けてあり、**承認フラグ**で出品を止めています。管理者は
`sellers.php?admin=1` から承認できます。

## 価格

出品時に**税別価格**を入れると、消費税10%（切り捨て）を足した税込で表示・請求します。
`0` 円は無料配布で、注文と同時にダウンロードが開きます（決済を挟みません）。

## 開発

```bash
php scripts/check_kappstore.php   # 台帳・認可・請求書PDFの確認（44項目）
python3 scripts/make_ogp.py       # OGP画像を作り直す
bash scripts/deploy.sh            # heteml へ公開
```

`scripts/deploy.sh` は `aixec/.env` の FTP 認証情報を使います。実行時設定
`public/kapp_config.php` はリポジトリに入れません（`kapp_config.php.example` を参照）。

### heteml の注意点

- **PHPのバージョンは `.htaccess` で決まります。** `AddHandler php8.3-script .php` を
  置かないと **5.6 で動きます**（新規サブドメインの既定）。`public/.htaccess` を
  消さないでください。
- `auth_common.php` は同じディレクトリの `config.php` を require します。
  kurage 側の `config.php` には kfreqai / rqdb4ai の API 認証情報が入っているので
  **流用せず**、`public/config.php`（空）を置いています。使わない認証情報を、
  置く理由のないドキュメントルートに増やさないためです。

## ライセンス

MIT License. 出品されるアプリ個別のライセンスは、各アプリの同梱物によります。
