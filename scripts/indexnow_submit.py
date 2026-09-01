#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""IndexNow に更新URLを通知する（Bing・Yandex・Naver・Seznam が受け取る）。

Googleは IndexNow を使わないが、ChatGPTの検索はBingの索引を使うため、
AI検索に載るまでの時間はここが効く。鍵ファイル public/<key>.txt を
公開しておくのが条件（deploy.sh が配置する）。

  python3 scripts/indexnow_submit.py          # 台帳の全商品＋固定ページ
  python3 scripts/indexnow_submit.py <URL>... # 指定したURLだけ
"""
import glob, json, os, subprocess, sys, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HOST = 'kappstore.exbridge.jp'
BASE = 'https://%s/' % HOST


def find_key():
    keys = [os.path.basename(p)[:-4] for p in glob.glob(os.path.join(ROOT, 'public', '*.txt'))
            if len(os.path.basename(p)) == 36]  # 32桁hex + .txt
    if not keys:
        sys.exit('public/<32桁hex>.txt が見つかりません')
    return keys[0]


def fetch_ledger():
    """台帳は本番が正。ローカルの写しを当てにせず毎回FTPで取り直す。"""
    import re
    env = {}
    with open('/home/kojima/work/aixec/.env') as f:
        for line in f:
            m = re.match(r'^([A-Z_]+)=(.*)$', line.strip())
            if m:
                env[m.group(1)] = m.group(2).strip('"\'')
    dst = os.path.join(ROOT, 'outputs', 'apps_live.json')
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    url = "ftp://%s:%s@%s/web/kappstore_exbridge_jp/kapp_data/apps.json" % (
        env['FTP_USER'], env['FTP_PASS'], env['FTP_HOST'])
    subprocess.run(['curl', '-s', '--fail', '-o', dst, url], check=True)
    return dst


def all_urls():
    urls = [BASE, BASE + 'sellers.php']
    data = json.load(open(fetch_ledger(), encoding='utf-8'))
    for a in data['apps']:
        if a.get('status', 'published') == 'published':
            urls.append(BASE + 'app.php?id=' + a['id'])
    return urls


def main():
    key = find_key()
    urls = sys.argv[1:] or all_urls()
    # 鍵ファイルが本当に公開されているか先に確かめる（無いと全件が拒否される）
    kurl = BASE + key + '.txt'
    got = subprocess.run(['curl', '-s', '-m', '20', kurl], capture_output=True, text=True).stdout.strip()
    if got != key:
        sys.exit('鍵ファイルが公開されていません: %s （deploy.sh で配置してください）' % kurl)
    body = json.dumps({'host': HOST, 'key': key, 'keyLocation': kurl, 'urlList': urls}).encode()
    req = urllib.request.Request('https://api.indexnow.org/indexnow', data=body,
                                 headers={'Content-Type': 'application/json; charset=utf-8'})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            print('IndexNow %s  %d件送信' % (r.status, len(urls)))
    except urllib.error.HTTPError as e:
        print('IndexNow %s: %s' % (e.code, e.read().decode('utf-8', 'replace')[:300]))
        sys.exit(1)


if __name__ == '__main__':
    main()
