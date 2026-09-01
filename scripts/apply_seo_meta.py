#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""scripts/seo_meta.json の title/description を本番の台帳(apps.json)に反映する。

  python3 scripts/apply_seo_meta.py --dry     # 差分だけ表示
  python3 scripts/apply_seo_meta.py --apply   # FTPで書き戻す

台帳の他の項目（とくに配布ファイル名 file）は触らない。書く前に必ず
outputs/ledger_backup_<日時>.json へ退避する。
"""
import argparse, json, os, re, subprocess, sys, unicodedata
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'outputs')
REMOTE = '/web/kappstore_exbridge_jp/kapp_data/apps.json'
TITLE_MAX, DESC_MAX, DESC_MIN = 32, 120, 50   # kseo の閾値と同じ


def visible_length(text):
    w = 0.0
    for c in text:
        w += 1.0 if unicodedata.east_asian_width(c) in ('W', 'F', 'A') else 0.5
    return int(w + 0.999)


def ftp_base():
    env = {}
    with open('/home/kojima/work/aixec/.env') as f:
        for line in f:
            m = re.match(r'^([A-Z_]+)=(.*)$', line.strip())
            if m:
                env[m.group(1)] = m.group(2).strip('"\'')
    return "ftp://%s:%s@%s" % (env['FTP_USER'], env['FTP_PASS'], env['FTP_HOST'])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--apply', action='store_true')
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()
    if not (args.apply or args.dry):
        ap.error('--dry か --apply を指定してください')

    meta = json.load(open(os.path.join(ROOT, 'scripts', 'seo_meta.json'), encoding='utf-8'))['items']
    ng = [k for k, v in meta.items()
          if visible_length(v['title']) > TITLE_MAX
          or not (DESC_MIN <= visible_length(v['desc']) <= DESC_MAX)]
    if ng:
        print('文字数の閾値を超えています:', ', '.join(ng)); sys.exit(1)

    os.makedirs(OUT, exist_ok=True)
    base = ftp_base()
    cur = os.path.join(OUT, 'apps_live.json')
    subprocess.run(['curl', '-s', '-o', cur, base + REMOTE], check=True)
    data = json.load(open(cur, encoding='utf-8'))

    changed = []
    for app in data['apps']:
        m = meta.get(app['id'])
        if not m:
            print('  台帳にあるが seo_meta.json に無い:', app['id'], app['name'][:30]); continue
        if app.get('seo_title') == m['title'] and app.get('seo_desc') == m['desc']:
            continue
        app['seo_title'] = m['title']
        app['seo_desc'] = m['desc']
        changed.append(app['name'][:34])
    missing = [k for k in meta if k not in {a['id'] for a in data['apps']}]
    if missing:
        print('  seo_meta.json にあるが台帳に無い:', ', '.join(missing))

    print('変更対象 %d 件 / 台帳 %d 件' % (len(changed), len(data['apps'])))
    for n in changed:
        print('   ', n)
    if not args.apply or not changed:
        return

    stamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    bak = os.path.join(OUT, 'ledger_backup_%s.json' % stamp)
    os.replace(cur, bak)
    print('退避:', bak)
    new = os.path.join(OUT, 'apps_new.json')
    with open(new, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    subprocess.run(['curl', '-s', '--fail', '-T', new, base + REMOTE], check=True)
    # 書けたか読み直して確かめる
    chk = os.path.join(OUT, 'apps_verify.json')
    subprocess.run(['curl', '-s', '-o', chk, base + REMOTE], check=True)
    v = json.load(open(chk, encoding='utf-8'))
    ok = sum(1 for a in v['apps'] if a.get('seo_title'))
    print('本番で seo_title を持つ商品: %d / %d' % (ok, len(v['apps'])))


if __name__ == '__main__':
    main()
