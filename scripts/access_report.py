#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""kappstore の SimpleTrack ログを集計する。

  python3 scripts/access_report.py [--month 2026-08] [--days 14] [--fetch]

  --fetch を付けると heteml から access-*.log と kapp_data/apps.json を取り直す
  （FTP認証は /home/kojima/work/aixec/.env の FTP_USER/FTP_PASS/FTP_HOST）。

判別は public/simpletrack.php の st_classify_ua と同じ規則を写している。
人／AI／検索エンジン／その他ボットに分け、既定では「人」だけを数える。
"""
import argparse, json, os, re, subprocess, sys
from collections import defaultdict, Counter
from datetime import datetime, timedelta

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CACHE = os.path.join(ROOT, 'outputs', 'access')
REMOTE = '/web/kappstore_exbridge_jp'

AI_BOTS = {'gptbot':'GPTBot (OpenAI)','oai-searchbot':'OAI-SearchBot','chatgpt-user':'ChatGPT-User',
 'claudebot':'ClaudeBot','claude-web':'Claude-Web','anthropic-ai':'Anthropic-AI',
 'perplexitybot':'PerplexityBot','perplexity-user':'Perplexity-User','google-extended':'Google-Extended',
 'ccbot':'CCBot','bytespider':'Bytespider','meta-externalagent':'Meta-ExternalAgent',
 'applebot-extended':'Applebot-Extended','cohere-ai':'Cohere-AI','diffbot':'Diffbot',
 'amazonbot':'Amazonbot','youbot':'YouBot','timpibot':'Timpibot','omgili':'Omgili'}
SEARCH_BOTS = {'googlebot':'Googlebot','google-safety':'Google-Safety','google-read-aloud':'Google Read Aloud',
 'googleother':'GoogleOther','bingbot':'Bingbot','duckduckbot':'DuckDuckBot','yandexbot':'YandexBot',
 'baiduspider':'Baiduspider','applebot':'Applebot','naver':'Naver','petalbot':'PetalBot'}
OTHER_BOT_WORDS = ['bot','crawler','spider','slurp','crawl','mediapartners','curl','wget','python',
 'httpclient','scrapy','headless','phantom','selenium','playwright','puppeteer','http_request',
 'facebookexternalhit','twitterbot','slackbot','discordbot','linebot','ahrefsbot','semrushbot',
 'mj12bot','dotbot','dataforseo','kgrowth','monitoring','uptime','pingdom','zabbix','simpletrackverify']

def classify(ua):
    u = (ua or '').strip().lower()
    if not u: return 'bot', '(UAなし)'
    for k, v in AI_BOTS.items():
        if k in u: return 'ai', v
    for k, v in SEARCH_BOTS.items():
        if k in u: return 'search', v
    for w in OTHER_BOT_WORDS:
        if w in u: return 'bot', w
    if 'iphone' in u or 'android' in u or 'mobile' in u: return 'human', 'スマートフォン'
    if 'ipad' in u or 'tablet' in u: return 'human', 'タブレット'
    return 'human', 'パソコン'

def fetch():
    env = {}
    with open('/home/kojima/work/aixec/.env') as f:
        for line in f:
            m = re.match(r'^([A-Z_]+)=(.*)$', line.strip())
            if m: env[m.group(1)] = m.group(2).strip('"\'')
    os.makedirs(CACHE, exist_ok=True)
    base = "ftp://%s:%s@%s%s/" % (env['FTP_USER'], env['FTP_PASS'], env['FTP_HOST'], REMOTE)
    names = subprocess.run(['curl','-s','--list-only', base], capture_output=True, text=True).stdout.split()
    got = []
    for n in names:
        if re.match(r'^access-\d{4}-\d{2}\.log$', n):
            subprocess.run(['curl','-s','-o', os.path.join(CACHE, n), base + n], check=True)
            got.append(n)
    subprocess.run(['curl','-s','-o', os.path.join(CACHE,'apps.json'), base + 'kapp_data/apps.json'], check=True)
    return got

def load_apps():
    p = os.path.join(CACHE, 'apps.json')
    if not os.path.exists(p): return {}
    data = json.load(open(p, encoding='utf-8'))
    apps = data.get('apps', data) if isinstance(data, dict) else data
    out = {}
    for a in apps:
        out[a.get('id')] = {'name': a.get('name') or a.get('title') or a.get('id'),
                            'price': a.get('price'), 'status': a.get('status')}
    return out

def rows(since=None):
    for fn in sorted(os.listdir(CACHE)):
        if not re.match(r'^access-\d{4}-\d{2}\.log$', fn): continue
        for line in open(os.path.join(CACHE, fn), encoding='utf-8', errors='replace'):
            p = [x.strip() for x in line.rstrip('\n').split('|')]
            if len(p) < 5: continue
            try: ts = datetime.strptime(p[0], '%Y-%m-%d %H:%M:%S')
            except ValueError: continue
            if since and ts < since: continue
            yield {'ts': ts, 'vid': p[1], 'url': p[2], 'ref': p[3], 'ua': p[4],
                   'title': p[5] if len(p) > 5 else ''}

def page_of(url):
    u = re.sub(r'^https?://[^/]+', '', url)
    m = re.search(r'app\.php\?id=([0-9a-f]{8,})', u)
    if m: return 'app:' + m.group(1)
    u = u.split('#')[0]
    u = re.sub(r'[?&]ref=[^&]*', '', u)
    return u or '/'

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--fetch', action='store_true')
    ap.add_argument('--days', type=int, default=0, help='直近N日だけ集計')
    ap.add_argument('--month', help='YYYY-MM だけ集計')
    args = ap.parse_args()
    if args.fetch: print('取得:', ', '.join(fetch()))
    apps = load_apps()
    since = datetime.now() - timedelta(days=args.days) if args.days else None
    data = [r for r in rows(since)]
    if args.month:
        data = [r for r in data if r['ts'].strftime('%Y-%m') == args.month]
    if not data:
        print('ログがありません（--fetch を付けて取得してください）'); return
    for r in data: r['kind'], r['label'] = classify(r['ua'])
    print('期間: %s 〜 %s / 行 %d' % (data[0]['ts'], data[-1]['ts'], len(data)))
    kinds = Counter(r['kind'] for r in data)
    print('内訳: 人 %d / 検索bot %d / AI bot %d / その他bot %d'
          % (kinds['human'], kinds['search'], kinds['ai'], kinds['bot']))

    human = [r for r in data if r['kind'] == 'human']
    pv, uu = Counter(), defaultdict(set)
    for r in human:
        k = page_of(r['url']); pv[k] += 1; uu[k].add(r['vid'])
    print('\n== ページ別（人のみ・PV / 実訪問者） ==')
    for k, n in pv.most_common(40):
        name = k
        if k.startswith('app:'):
            a = apps.get(k[4:])
            name = ('商品 ' + (a['name'] if a else k[4:]))
        print('%5d %4d  %s' % (n, len(uu[k]), name))

    print('\n== 商品ページの人アクセス（実訪問者順） ==')
    prod = defaultdict(lambda: {'pv':0,'uu':set(),'ai':0,'search':0})
    for r in data:
        k = page_of(r['url'])
        if not k.startswith('app:'): continue
        pid = k[4:]
        if r['kind'] == 'human':
            prod[pid]['pv'] += 1; prod[pid]['uu'].add(r['vid'])
        elif r['kind'] in ('ai','search'):
            prod[pid][r['kind']] += 1
    for pid, v in sorted(prod.items(), key=lambda x: (-len(x[1]['uu']), -x[1]['pv'])):
        a = apps.get(pid)
        print('%3d人 %3dPV  検索bot%3d AI bot%3d  %s' %
              (len(v['uu']), v['pv'], v['search'], v['ai'], (a['name'] if a else pid)))
    unseen = [pid for pid in apps if pid not in prod]
    if unseen:
        print('\n-- 期間中アクセス0の商品 %d 件 --' % len(unseen))
        for pid in unseen: print('   ', apps[pid]['name'])

    print('\n== 流入元（人のみ・外部参照のみ） ==')
    ext = Counter()
    for r in human:
        ref = r['ref']
        if not ref: ext['(参照元なし)'] += 1; continue
        host = re.sub(r'^https?://([^/]+).*$', r'\1', ref)
        if 'kappstore.exbridge.jp' in host: continue
        ext[host] += 1
    for h, n in ext.most_common(15): print('%5d  %s' % (n, h))
    print('\n== ?ref= タグ（人のみ） ==')
    tags = Counter()
    for r in human:
        m = re.search(r'[?&]ref=([^&\s]+)', r['url'])
        if m: tags[m.group(1)] += 1
    for t, n in tags.most_common(15): print('%5d  %s' % (n, t))
    print('\n== 日別（人のみ） ==')
    days = Counter(r['ts'].strftime('%Y-%m-%d') for r in human)
    for d in sorted(days): print('%s  %d' % (d, days[d]))

if __name__ == '__main__':
    main()
