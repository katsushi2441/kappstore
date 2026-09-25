#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""商品台帳の video_url / video_poster を設定する（商品ページの先頭にPVを出す）。

  /usr/bin/python3 scripts/set_video.py <商品ID> --video <絶対URL> --poster <絶対URL> --dry
  /usr/bin/python3 scripts/set_video.py <商品ID> --video <絶対URL> --poster <絶対URL> --apply

app.php は台帳の video_url があれば商品画像の代わりに <video> を出し、
VideoObject の JSON-LD も付ける。値は**絶対URL**でないと出ない。

台帳の他の項目（とくに配布ファイル名 file）は触らない。
書く前に必ず outputs/ledger_backup_<日時>.json へ退避する。
再出品で video_url が消えた事故があるため（scripts/list_app.php の注記を参照）、
出品をやり直したあとは、この手順でもう一度入れ直す。
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

sys.path.insert(0, os.path.join(ROOT, 'scripts'))
from update_body import env, fetch_ledger, put_ledger  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('app_id')
    ap.add_argument('--video', required=True, help='PVの絶対URL（mp4）')
    ap.add_argument('--poster', required=True, help='ポスター画像の絶対URL')
    ap.add_argument('--apply', action='store_true')
    ap.add_argument('--dry', action='store_true')
    a = ap.parse_args()
    if not (a.apply or a.dry):
        sys.exit('--dry か --apply を付けてください')
    for u in (a.video, a.poster):
        if not u.startswith('http'):
            sys.exit(f'絶対URLで渡してください: {u}')

    e = env()
    led = fetch_ledger(e)
    apps = led['apps'] if isinstance(led, dict) and 'apps' in led else led
    hit = [x for x in apps if x.get('id') == a.app_id]
    if not hit:
        sys.exit(f'台帳に {a.app_id} がありません')
    app = hit[0]
    print('商品:', app.get('name', '')[:60])
    print('  いまの video_url   :', app.get('video_url') or '（未設定）')
    print('  いまの video_poster:', app.get('video_poster') or '（未設定）')
    print('  設定する video_url :', a.video)
    print('  設定する poster    :', a.poster)

    os.makedirs(OUT, exist_ok=True)
    bak = os.path.join(OUT, 'ledger_backup_' + datetime.now().strftime('%Y%m%d_%H%M%S') + '.json')
    with open(bak, 'w', encoding='utf-8') as f:
        json.dump(led, f, ensure_ascii=False, indent=1)
    print('  退避:', bak)

    if a.dry:
        print('--dry なので書いていません')
        return 0
    app['video_url'] = a.video
    app['video_poster'] = a.poster
    put_ledger(e, led, os.path.join(OUT, 'apps_upload.json'))
    print('書き戻しました。商品ページで表示を確認してください。')
    return 0


if __name__ == '__main__':
    sys.exit(main())
