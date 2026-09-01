#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""商品ページがGoogleに登録されているかを Search Console の URL検査APIで実測する。

  python3 scripts/index_coverage.py

「登録されている＝検索に出る」ではないが、登録すらされていなければ
title を直しても効かない。直した効果を待つ前に、まずここを見る。
認証は googleads/gsc_oauth.py の refresh_token を使う（無人実行できる）。
"""
import json, os, sys, urllib.error, urllib.request

sys.path.insert(0, '/home/kojima/work/googleads')
from gsc_oauth import access_token  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://kappstore.exbridge.jp/'
API = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect'


def main():
    ledger = os.path.join(ROOT, 'outputs', 'apps_live.json')
    if not os.path.exists(ledger):
        ledger = os.path.join(ROOT, 'outputs', 'apps_verify.json')
    apps = json.load(open(ledger, encoding='utf-8'))['apps']
    tok = access_token()
    res, rows = {}, []
    for a in apps:
        u = SITE + 'app.php?id=' + a['id']
        body = json.dumps({'inspectionUrl': u, 'siteUrl': SITE, 'languageCode': 'ja'}).encode()
        req = urllib.request.Request(API, data=body,
                                     headers={'Authorization': 'Bearer ' + tok,
                                              'Content-Type': 'application/json'})
        try:
            d = json.loads(urllib.request.urlopen(req, timeout=90).read())
            r = d['inspectionResult']['indexStatusResult']
            state = r.get('coverageState', '?')
            crawl = (r.get('lastCrawlTime') or '-')[:10]
        except urllib.error.HTTPError as e:
            state, crawl = 'APIエラー%d' % e.code, '-'
        except Exception:
            state, crawl = '例外', '-'
        res.setdefault(state, []).append(a['name'][:30])
        rows.append({'id': a['id'], 'name': a['name'], 'state': state, 'last_crawl': crawl})
        print('%-28s %-24s 最終クロール %s' % (a['name'][:26], state, crawl))
    json.dump(rows, open(os.path.join(ROOT, 'outputs', 'index_coverage.json'), 'w'),
              ensure_ascii=False, indent=2)
    print()
    for k, v in sorted(res.items(), key=lambda x: -len(x[1])):
        print('%s: %d件' % (k, len(v)))


if __name__ == '__main__':
    main()
