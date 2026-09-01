#!/usr/bin/env bash
# kappstore の公開デプロイ。
#   店 : https://kappstore.exbridge.jp/  (/web/kappstore_exbridge_jp)
#
# heteml 上の素のPHPで動く。常駐プロセスもDBも使わないので、ここ以外に
# 触るものは無い（ポートも取らない）。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a
. /home/kojima/work/aixec/.env
set +a

if [[ ! -f public/kapp_config.php ]]; then
  echo "missing public/kapp_config.php (kapp_config.php.example を参照)" >&2
  exit 1
fi

remote="/web/kappstore_exbridge_jp"

upload() {  # upload <local> <remote-path>
  curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"
  echo "deployed: ${2}"
}

# --- サーバー設定（heteml は .htaccess でPHPのバージョンを選ぶ。
#     置かないと 5.6 で動く）---
upload public/.htaccess .htaccess

# --- 認証（kurage.exbridge.jp と同じものを使う。OAuth自体は
#     aiknowledgecms.exbridge.jp が受け持つので、ここにXの鍵は置かない）---
upload /home/kojima/work/kurage_web/auth_common.php auth_common.php
# auth_common.php が require する。kappstore 用の空の設定
# （kurage 側の config.php には kfreqai/rqdb4ai の認証情報が入っているので流用しない）
upload public/config.php config.php

# --- 画面 ---
for f in index.php app.php order.php orders.php download.php register.php sellers.php admin.php payout.php statement.php dashboard.php sales.php sitemap.php llms.php catalog.php simpletrack.php simpletrack_dashboard.php; do
  upload "public/$f" "$f"
done

# --- 部品 ---
for f in kapp_boot.php kapp_lib.php kapp_ui.php kapp_usecases.php kapp_invoice.php kapp_payout.php kapp_statement.php kapp_config.php simpletrack_config.php; do
  upload "public/$f" "$f"
done

# --- 素材 ---
upload public/assets/ogp.png            assets/ogp.png
upload public/assets/kurage_avatar.png  assets/kurage_avatar.png
upload public/assets/kurage_avatar.webp assets/kurage_avatar.webp
upload public/robots.txt                robots.txt

# --- IndexNow の鍵（Bing・Yandex等に更新を通知するのに要る）---
#     public/<32桁hex>.txt を、中身が鍵そのものの状態で公開しておく。
#     これが無いと scripts/indexnow_submit.py の送信が全件拒否される。
for k in public/*.txt; do
  b=$(basename "$k")
  case "$b" in
    ????????????????????????????????.txt) upload "$k" "$b" ;;
  esac
done

# --- 保護（台帳と配布ファイルをWebから直接読ませない）---
upload public/kapp_data/.htaccess  kapp_data/.htaccess
upload public/kapp_media/.htaccess kapp_media/.htaccess

echo
echo "published: https://kappstore.exbridge.jp/"
