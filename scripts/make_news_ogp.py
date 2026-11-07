#!/usr/bin/python3
"""AI・IT・DXニュース（/news）と業務の制度ニュース（/seido）のOGP画像（1200x630）。2026-11-07。

件数は毎時変わって古くなるので入れない（「毎日更新」）。ページの種類ごとに1枚
（news・news-ai・news-security・news-dx・seido・seido-<分類>）。マスコットは右下（変形させない・contain）。
  /usr/bin/python3 scripts/make_news_ogp.py → public/assets/og/<名前>.png
"""
import base64, json, os, pathlib
from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / 'public' / 'assets' / 'og'
MASCOT = 'data:image/png;base64,' + base64.b64encode(pathlib.Path('/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png').read_bytes()).decode()

news = json.load(open(ROOT / 'public' / 'kapp_data' / 'news.json', encoding='utf-8'))
seido = json.load(open(ROOT / 'public' / 'kapp_data' / 'seido.json', encoding='utf-8'))
nc = lambda k: sum(1 for x in news['items'] if k in x['topics'])
sc = lambda k: sum(1 for x in seido['items'] if k in x['cats'])

CARDS = [
    ('news', 'AI・IT・DX・防災ニュース', '会社が確かめることと、使える業務システム', ['AI', 'セキュリティ', 'DX', '防災'], '毎日更新'),
    ('news-ai', 'AIのニュース', '会社がAIを使うときに確かめること', ['生成AI', 'AIエージェント', 'AIの規制'], '毎日更新'),
    ('news-security', 'セキュリティのニュース', '不正アクセス・情報漏えいのあとに確かめること', ['不正アクセス', '情報漏えい', '脆弱性'], '毎日更新'),
    ('news-dx', 'DX・クラウドのニュース', 'SaaSとシステムの見直しで確かめること', ['クラウド', 'SaaS', '基幹システム'], '毎日更新'),
    ('news-bousai', '防災のニュース', '地震・大雨・台風のあとに会社が確かめること', ['地震・津波', '大雨・台風', '避難・被災'], '毎日更新'),
    ('seido', '業務の制度ニュース', '報道と府省の発表から、会社がやることを', ['労務', '情報管理', '補助金と支援'], '府省の発表から・毎日更新'),
    ('seido-roumu', '労務の制度ニュース', '最低賃金・年収の壁・労働時間', ['最低賃金', '年収の壁', '勤怠'], '報道と府省の発表から'),
    ('seido-joho', '情報管理の制度ニュース', '不正アクセス・個人情報の注意喚起', ['不正アクセス', '個人情報', '注意喚起'], '報道と府省の発表から'),
    ('seido-shien', '補助金と支援の制度ニュース', '中小企業向けの公募・支援', ['補助金', '助成金', '公募'], '報道と府省の発表から'),
    ('seido-torihiki', '取引の制度ニュース', '取適法・価格転嫁・取引の適正化', ['取適法', '価格転嫁', '発注書'], '報道と府省の発表から'),
    ('seido-tetsuzuki', '税と手続の制度ニュース', '消費税減税・給付付き税額控除・インボイス', ['消費税', '給付付き税額控除', 'インボイス'], '報道と府省の発表から'),
]

HTML = """<html><head><meta charset="utf-8"><style>
*{margin:0;box-sizing:border-box}
body{width:1200px;height:630px;overflow:hidden;font-family:"Noto Sans CJK JP",sans-serif;position:relative;background:#f4fbfa;color:#10242b}
.b1{position:absolute;left:-120px;top:-160px;width:620px;height:620px;border-radius:50%;background:#dff3ef}
.b2{position:absolute;right:-140px;bottom:-220px;width:620px;height:620px;border-radius:50%;background:#e9f6f4}
.store{position:absolute;left:70px;top:58px;font-size:26px;font-weight:800;color:#0a8f85}
.chips{position:absolute;left:70px;top:112px;display:flex;gap:12px}
.chips span{background:#fff;border:2px solid #bfe4de;border-radius:999px;padding:6px 18px;font-size:24px;font-weight:800;color:#0a5f58}
.t{position:absolute;left:70px;top:178px;width:840px;display:flex;flex-direction:column;gap:14px}
.h{font-size:{HSIZE}px;font-weight:900;line-height:1.2;white-space:nowrap}
.s{font-size:32px;font-weight:700;color:#37485a;line-height:1.45}
.n{position:absolute;left:70px;bottom:120px;font-size:34px;font-weight:900;color:#fff;background:#0a8f85;border-radius:14px;padding:10px 26px}
.u{position:absolute;left:72px;bottom:52px;font-size:24px;color:#5b6b70}
.m{position:absolute;right:40px;bottom:30px;width:300px;height:300px;object-fit:contain}
</style></head><body><div class="b1"></div><div class="b2"></div>
<div class="store">Kurage App Store</div>
<div class="chips">__CHIPS__</div>
<div class="t"><div class="h">__H__</div><div class="s">__S__</div></div>
<div class="n">__N__</div><div class="u">kappstore.exbridge.jp/__SLUG__</div>
<img class="m" src="__M__"></body></html>"""


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        b = p.chromium.launch()
        pg = b.new_page(viewport={'width': 1200, 'height': 630})
        for slug, h, s, chips, n in CARDS:
            hsize = 72 if len(h) <= 11 else (62 if len(h) <= 13 else 54)   # 長い見出しは小さくして1行に収める
            html = (HTML.replace('{HSIZE}', str(hsize)).replace('__CHIPS__', ''.join(f'<span>{c}</span>' for c in chips))
                    .replace('__H__', h).replace('__S__', s).replace('__N__', n).replace('__SLUG__', slug).replace('__M__', MASCOT))
            pg.set_content(html)
            pg.screenshot(path=str(OUT / f'{slug}.png'))
        b.close()
    print(f'{len(CARDS)}枚 → {OUT}')


if __name__ == '__main__':
    main()
