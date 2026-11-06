#!/usr/bin/python3
"""Kurage App Store の「AI・IT・DXニュース」（/news）のデータを作る（2026-11-07 ユーザー指示「AI,IT,DXニュース」）。

ChatGPT などのAI検索が kappstore を読みに来ないため（ChatGPT-User の訪問が10・11月とも0回）、
更新の多い、店に合った入口を作る。時事ニュース全部ではなく、AI・セキュリティ・DXとクラウドの3つの話題だけにして、
話題ごとに「会社が確かめること」と「使える当社のアプリ」を出す（画面は public/news.php）。

- 材料: kpayload/data/news-cards.json（exbridge.jp/news/ の元。毎時更新）。見出しと媒体・時刻・元記事のURLだけを使う。
- 判定は見出しの語。的外れ（サイトの名前の「〜デジタル」、テスラの「サイバートラック」など）は除く。
- 出力: public/kapp_data/news.json（直近60日・新しい順）

  /usr/bin/python3 scripts/build_news_data.py [--deploy]
"""
import ftplib, json, os, re, sys, time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = '/home/kojima/work/kpayload/data/news-cards.json'
OUT = os.path.join(ROOT, 'public', 'kapp_data', 'news.json')
DAYS = 60

# 見出しから先に取り除くもの（媒体名の「〜デジタル」などで当たらないように）
STRIP = re.compile(r'[：:｜|]\s*[^：:｜|]{0,20}(新聞|ニュース|NEWS|デジタル|オンライン|電子版)[^：:｜|]{0,10}$')
TOPICS = [
    ('ai', re.compile(r'生成AI|生成ＡＩ|(?<![A-Za-zＡ-Ｚａ-ｚ])(AI|ＡＩ)(?![A-Za-zＡ-Ｚａ-ｚ])|人工知能|ChatGPT|チャットGPT|LLM|大規模言語モデル|AIエージェント|OpenAI|オープンAI|アンソロピック|Anthropic|Gemini|ジェミニ|Claude|クロード|フィジカルAI')),
    ('security', re.compile(r'サイバー(攻撃|対策|セキュリティ|犯罪|防御|対処)|不正アクセス|情報(漏え?い|漏洩|流出)|個人情報.{0,6}(流出|漏)|ランサム|脆弱性|フィッシング|ハッカー|マルウェア|サイバー空間|なりすまし.{0,6}(メール|サイト)')),
    ('dx', re.compile(r'(?<![A-Za-zＡ-Ｚａ-ｚ])(DX|ＤＸ)(?![A-Za-zＡ-Ｚａ-ｚ])|デジタル(化|庁|改革|行政|人材|田園|社会)|クラウド|SaaS|ＳａａＳ|システム(障害|刷新|移行|統合|更新)|基幹システム|データセンター|マイナンバー|マイナ保険証|オープンソース|(?<![A-Za-z])OSS(?![A-Za-z])|ペーパーレス|電子(契約|帳簿|インボイス)|キャッシュレス|ローコード')),
]
EXCLUDE = re.compile(r'サイバートラック|見逃し配信|プレゼント|占い|今日の天気')


def topics_of(headline):
    t = STRIP.sub('', headline)
    if EXCLUDE.search(t):
        return []
    return [k for k, rx in TOPICS if rx.search(t)]


# ---------------------------------------------------------------- 業務の制度ニュース（/seido・2026-11-07）
# 省庁トラッカー（exbridge.jp/shocho/）が毎日取り込む府省の発表から、会社の業務に関わるものだけ。
# 記者会見・審議会の開催案内・表彰・職員の採用などのお知らせは外す。分け方は題名の語だけ。
SHOCHO = '/home/kojima/work/kpayload/data/shocho/items.json'
SEIDO_OUT = os.path.join(ROOT, 'public', 'kapp_data', 'seido.json')
SEIDO = [
    ('roumu', re.compile(r'最低賃金|賃金|労働|雇用|社会保険|厚生年金|育児休業|介護休業|ハラスメント|労災|安全衛生|ストレスチェック|適用拡大|働き方|外国人(材|労働)|技能実習|育成就労')),
    ('joho', re.compile(r'個人情報|不正アクセス|サイバー|情報(漏え?い|漏洩|流出)|セキュリティ|フィッシング|なりすまし')),
    ('shien', re.compile(r'(中小企業|小規模事業者|事業者|企業).{0,25}(補助金|助成金|支援金|公募|交付決定|給付金|融資|資金繰り|支援)|(補助金|助成金).{0,25}(中小企業|小規模事業者|事業者)|持続化補助金|事業再構築|ものづくり補助金|IT導入補助金|デジタル化・AI導入補助金|雇用(調整)?助成金|雇用就農資金|産業雇用安定助成金|なりわい再建')),
    ('torihiki', re.compile(r'取適法|下請|価格転嫁|取引適正|取引の適正|手形|約束手形|振込手数料|公正取引')),
    ('tetsuzuki', re.compile(r'インボイス|適格請求書|電子帳簿|電子取引|事業者.{0,8}(届出|手続|申請)|法人.{0,8}(手続|申請|届出)|電子申請|gBizID|ＧビズＩＤ|e-Gov|ｅ－Ｇｏｖ|電子契約|源泉徴収|年末調整|社会保険.{0,6}(手続|届出)')),
]
SEIDO_NOISE = re.compile(r'採用選考|看護教育|ねんりんピック|健康福祉祭|自治体向け|地方公共団体向け|WEBマガジン|大学|学部|免許|国家資格|ゲノム|憲法|地方債|交付税|放送コンテンツ|学園祭|つみたて投資枠|検討会|会議資料|タスクフォース|合同会議|政策会議|にご協力|核燃料税|記者会見|会見のお知らせ|開催します|開催案内|開催について|を開催|表彰|採用情報|職員(の)?募集|人事異動|募集情報|審議会.{0,20}(資料|議事録)|部会.{0,10}(資料|議事録)|専門委員会|調査結果の公表について$|統計調査報告|労働力調査|月例')


def build_seido():
    L = list(json.load(open(SHOCHO, encoding='utf-8')).values())
    out = []
    for x in L:
        t = x.get('title', '')
        if SEIDO_NOISE.search(t):
            continue
        cats = [k for k, rx in SEIDO if rx.search(t)]
        if not cats:
            continue
        out.append({'day': x.get('date', ''), 'ministry': x.get('ministry_name', ''), 'title': t, 'cats': cats,
                    'url': x.get('url', ''), 'ex': f"https://exbridge.jp/shocho/n/{x['id']}.html" if x.get('id') else ''})
    out.sort(key=lambda x: x['day'], reverse=True)
    json.dump({'generated_at': time.strftime('%Y-%m-%d %H:%M'), 'items': out}, open(SEIDO_OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    from collections import Counter
    print(f'seido: {len(out)}件（{dict(Counter(c for x in out for c in x["cats"]))}）→ {SEIDO_OUT}')


def main():
    d = json.load(open(SRC, encoding='utf-8'))
    cutoff = time.strftime('%Y-%m-%d', time.localtime(time.time() - DAYS * 86400))
    seen, items = set(), []
    for x in d['items']:
        if x['day'] < cutoff:
            continue
        tp = topics_of(x['headline'])
        if not tp:
            continue
        key = re.sub(r'\s+', '', x['headline'])
        if key in seen:      # 同じ見出しが別の媒体・時刻で重なったものは最初の1件だけ
            continue
        seen.add(key)
        items.append({
            'day': x['day'], 'time': x.get('time', ''), 'source': x.get('source', ''),
            'headline': x['headline'], 'topics': tp,
            'url': x.get('newsUrl') or x.get('post') or '',
            'ex': f"https://exbridge.jp/news/{x['day']}/{x['id']}.html" if x.get('id') else '',
        })
    items.sort(key=lambda x: (x['day'], x['time']), reverse=True)
    out = {'generated_at': time.strftime('%Y-%m-%d %H:%M'), 'source_generated': d.get('generated', ''), 'days': DAYS, 'items': items}
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump(out, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    from collections import Counter
    c = Counter(t for x in items for t in x['topics'])
    print(f'news: {len(items)}件（{dict(c)}）→ {OUT}')
    build_seido()
    if '--deploy' in sys.argv:
        ftp = ftplib.FTP_TLS(os.environ['FTP_HOST'], timeout=120)
        ftp.login(os.environ['FTP_USER'], os.environ['FTP_PASS']); ftp.prot_p()
        for f in (OUT, SEIDO_OUT):
            with open(f, 'rb') as fp:
                ftp.storbinary('STOR /web/kappstore_exbridge_jp/kapp_data/' + os.path.basename(f), fp)
        ftp.quit()
        print('送信 kapp_data/news.json・seido.json')


if __name__ == '__main__':
    main()
