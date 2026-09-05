#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""商品ページの本文(body)を差し替える。

なぜ必要か（2026-09-05 実測）:
  商品ページ同士のテキストを突き合わせると、共通部分が最大78%だった。
  1ページ約2,900字のうち2,100字が購入前注意・FAQ・価格・導線といった
  全商品共通の文言で、商品ごとに違うのは600〜800字しかない。
  その結果 Search Console で「検出 - インデックス未登録」（Googleが存在は
  知っているがクロールする価値なしと判断した状態）が10件中4件出ていた。
  共通部分は購入者に必要なので消せない。固有の本文を厚くするのが対処。

  使い方:
    python3 scripts/update_body.py <商品ID> <本文のmdファイル> --dry
    python3 scripts/update_body.py <商品ID> <本文のmdファイル> --apply

台帳の他の項目（とくに配布ファイル名 file）は触らない。書く前に必ず
outputs/ledger_backup_<日時>.json へ退避する。
"""
import argparse
import json
import os
import subprocess
import sys
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'outputs')
REMOTE = '/web/kappstore_exbridge_jp/kapp_data/apps.json'


def env():
    """FTPの認証情報は aixec/.env から読む（このリポジトリには置かない）。"""
    path = '/home/kojima/work/aixec/.env'
    out = {}
    with open(path, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            k, v = line.split('=', 1)
            out[k.strip()] = v.strip().strip('"').strip("'")
    for k in ('FTP_HOST', 'FTP_USER', 'FTP_PASS'):
        if not out.get(k):
            sys.exit(f'{k} が {path} にありません')
    return out


def fetch_ledger(e):
    url = f"ftp://{e['FTP_USER']}:{e['FTP_PASS']}@{e['FTP_HOST']}{REMOTE}"
    r = subprocess.run(['curl', '-s', '--fail', url], capture_output=True)
    if r.returncode != 0:
        sys.exit('台帳を取得できませんでした')
    return json.loads(r.stdout.decode('utf-8'))


def put_ledger(e, data, path):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, separators=(',', ':'))
    url = f"ftp://{e['FTP_USER']}:{e['FTP_PASS']}@{e['FTP_HOST']}{REMOTE}"
    r = subprocess.run(['curl', '-s', '--fail', '-T', path, url], capture_output=True)
    if r.returncode != 0:
        sys.exit('台帳を書き戻せませんでした')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('app_id')
    ap.add_argument('body_file')
    ap.add_argument('--apply', action='store_true')
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()
    if not (args.apply or args.dry):
        sys.exit('--dry か --apply を指定してください')

    body = open(args.body_file, encoding='utf-8').read().strip()
    if len(body) < 200:
        sys.exit(f'本文が短すぎます（{len(body)}字）。差し替えを中止します')

    e = env()
    data = fetch_ledger(e)
    hit = [a for a in data.get('apps', []) if a.get('id') == args.app_id]
    if not hit:
        sys.exit(f'商品が見つかりません: {args.app_id}')
    app = hit[0]

    before = len(app.get('body') or '')
    print(f"商品     : {app['name']}")
    print(f"本文の長さ: {before:,}字 → {len(body):,}字（{len(body) - before:+,}）")

    if args.dry:
        print('--dry なので書き戻しません')
        return

    os.makedirs(OUT, exist_ok=True)
    stamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    backup = os.path.join(OUT, f'ledger_backup_{stamp}.json')
    with open(backup, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print(f'退避     : {backup}')

    app['body'] = body
    app['updated_at'] = int(datetime.now().timestamp())
    put_ledger(e, data, os.path.join(OUT, f'apps_after_{stamp}.json'))
    print('書き戻しました')


if __name__ == '__main__':
    main()
