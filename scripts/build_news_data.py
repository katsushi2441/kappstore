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
TOPICS.append(
    # 防災（2026-11-08 ユーザー「大雨とか地震とか、ニュースに関連づくプロダクトあるからね」）。地震・大雨・台風・津波・土砂・停電・避難
    ('bousai', re.compile(r'地震|震度|震源|津波|大雨|豪雨|線状降水帯|台風|土砂(災害|崩れ)|洪水|氾濫|浸水|冠水|避難(指示|所|情報|勧告)|停電|噴火|大雪|竜巻|防災|被災|罹災|災害(救助|対策|派遣)|緊急地震速報|南海トラフ|首都直下')))
EXCLUDE = re.compile(r'サイバートラック|見逃し配信|プレゼント|占い|今日の天気|地震計の|台風の目|(株|株価|相場|市場).{0,6}(大荒れ|嵐)')


# ---------------------------------------------------------------- 見出しごとの関係する商品（2026-11-08）
# ユーザー「プロダクトとニュースで言えばもっと、あると思うよね。AIのニュースなら、AIチャットボットでも関連づくしね」。
# 話題ごとに商品を並べるだけでは、ニュース1件と商品がつながらない。見出しの語から、いちばん関係の深い商品を最大2つ選ぶ。
# 上から順に当て、具体的なもの（地震→地震ハザード）を先、広いもの（国会の審議→国会トラッカー）を後に置く。
APP_RULES = [
    # 防災
    (r'津波', '86b85a63bc426575'),
    # 地震ハザードマップは名古屋版なので、名古屋・愛知・東海の地震だけ。ほかの地震は全国で使える避難所マップ
    (r'(名古屋|愛知|東海|三河|尾張).{0,20}(地震|震度)|(地震|震度).{0,20}(名古屋|愛知|東海|三河|尾張)|南海トラフ', '51649180bea0fd57'),
    (r'地震|震度|震源|首都直下|活断層', '162f155897390072'),
    (r'土砂(災害|崩れ)|がけ崩れ|地すべり|土石流', '02b945f9c87c9d86'),
    (r'盛り?土', '40efd031ba24c9d8'),
    (r'大雨|豪雨|洪水|氾濫|浸水|冠水|線状降水帯|内水', '41a09acc163dcb7d'),
    (r'避難(指示|所|情報|者)|避難生活', '162f155897390072'),
    (r'被災|罹災|義援金|仮設住宅|災害救助|生活再建', 'a2ddb1f2961a74d8'),
    (r'台風|警報|防災|大雪|竜巻|停電', 'cf817fdce13ee120'),
    # 感染症
    (r'インフルエンザ|新型コロナ|コロナ.{0,3}(感染|流行)|学級閉鎖|感染症|はしか|麻しん', 'f0f1422b462508b4'),
    # セキュリティ・情報
    (r'改ざん', '9dd7d5be638f9cb8'),
    (r'不正アクセス|脆弱性|サイバー攻撃|ランサム|情報漏え?い|情報流出|個人情報.{0,6}(流出|漏)|フィッシング|なりすまし', 'a96e95e176b48800'),
    (r'ファイル(共有|転送|送信)|PPAP|メール添付', '4003999985c45854'),
    # AI・業務
    (r'議事録|文字起こし', 'cd1eda3248c87920'),
    (r'(?<!機械)受注(?!統計)|発注|FAX注文|ファクス', '88acc4be142bd444'),
    (r'手書き|OCR|紙の(書類|申請|手続)|ペーパーレス|書類の電子化', 'ef52a62e1c6bbe7a'),
    (r'AI検索|LLMO|AIO|生成AIに(勧め|推薦)', '6e3c38c7a92a5da8'),
    (r'チャットボット|ChatGPT|チャットGPT|生成AI|生成ＡＩ|AIエージェント|Claude|クロード|Gemini|ジェミニ|LLM|大規模言語モデル|OpenAI|オープンAI|Anthropic|アンソロピック', '224e141f77bd07a8'),
    (r'kintone|キントーン|サイボウズ|グループウェア', 'c1864cba4ab726b0'),
    (r'電子契約|契約書', 'f543c9cbd613871b'),
    (r'名刺', '4679d4cf90699580'),
    (r'予約(システム|サイト|管理|受付)|無断キャンセル|ドタキャン', '362c94ab4e1384f2'),
    (r'口コミ|やらせレビュー|星1|ステマ|ステルスマーケティング|景品表示法|景表法|誇大広告|No\.?1表示', 'cb0b3c587a75fc8a'),
    (r'SEO|検索順位', 'c8ffa66502f7d905'),
    (r'LINE(公式|ヤフー|で)', '0e723e981c922df6'),
    (r'カスハラ|カスタマーハラスメント|コールセンター|問い合わせ(窓口|対応)', 'df2e650ed0c59bae'),
    (r'テレアポ|営業電話', '0931d2dfecffc022'),
    (r'メール配信|メルマガ|迷惑メール', '1cc9b2d4ba1a853f'),
    (r'ネット通販|ネットショップ|ECサイト|(?<![A-Za-z])EC(?![A-Za-z])', '754c7ddb5f1c8f26'),
    # 労務
    (r'最低賃金', '0c438fde80e5c346'),
    (r'保育(園|所|士)', 'b7e010bb0869cbde'),
    (r'勤怠|残業|労働時間|長時間労働|打刻|36協定|過労死', 'f0f56c6e4da881be'),
    (r'引き継ぎ|属人化|退職代行|離職|人手不足', '56bf3ddd46b5a457'),
    # 取引・税・お金
    (r'取適法|下請|価格転嫁|フリーランス(法|新法|保護)|優越的地位', '42866a2a798d09eb'),
    (r'インボイス|適格請求書', '3925790d5cdbe66e'),
    (r'請求書|領収書|電子帳簿', '15abb025dc2ee4f6'),
    (r'入札|官公需|公共調達', '464a65ef3e6a3646'),
    (r'補助金|助成金', '0ad3f1528024edcd'),
    (r'給付金|支援金|給付付き税額控除', '5da3d8efae6ddd62'),
    (r'NISA|ニーサ|iDeCo|イデコ', 'cb13df4a7769e84f'),
    (r'相続|遺族年金|死亡後の手続|葬儀', '60a6c07508d735ac'),
    # 土地・建設
    (r'建設業許可', 'c8a24775df796de6'),
    (r'民泊', 'c36227d2b62b8517'),
    (r'重要事項説明|宅建|不動産取引', '01d71e3bd7f717a8'),
    (r'用途地域|都市計画|市街化調整|建ぺい率|容積率', '4bb2a5775eaad593'),
    (r'地価|土地取引|宅地', '3caa6a1697f5f568'),
    (r'出店|閉店|新店舗|商圏|撤退', 'a5ac4b9f1fdb6d19'),
    # 福祉・子ども
    (r'要介護|介護認定', '0b560e1299f406a5'),
    (r'訪問介護|ケアマネ', '57aebd041b7bab37'),
    (r'訪問看護', '2bdf59a8795a50e8'),
    (r'グループホーム', 'bf709cfc7b0bf51a'),
    (r'放課後等デイ|児童発達支援', '38ed38e789c77ec2'),
    (r'就労継続支援|就労支援', '790cee5b922df2e4'),
    (r'学童', 'f2853d368ddf8e57'),
    (r'不登校|フリースクール', 'a2f1706ca3edff6b'),
    (r'介護', '9a5b83640c786d9b'),
    (r'動物病院|獣医', '302ada8100bb954f'),
    # 制度と政治（広いので最後）
    (r'パブリックコメント|意見公募', 'b80a258f26c448d5'),
    (r'選挙ポスター|ポスター掲示板', '488e3b290d0db8a9'),
    (r'施行|法改正|改正法|義務化|制度変更|来年4月から|来年度から|消費税|減税|増税|税制|年収の?壁|106万|130万|社会保険|厚生年金|育休|育児休業|介護休業|賃上げ', 'bb4980bad6219e25'),
    (r'国会|委員会で|法案|審議入り|答弁|質問主意書', '5447675118edd258'),
]
APP_RULES = [(re.compile(rx, re.I), aid) for rx, aid in APP_RULES]
TOPIC_FALLBACK = {'ai': '224e141f77bd07a8', 'security': 'a96e95e176b48800', 'dx': 'c1864cba4ab726b0', 'bousai': 'cf817fdce13ee120'}


def apps_for(text, topics=()):
    t = STRIP.sub('', text)
    out = []
    for rx, aid in APP_RULES:
        if aid not in out and rx.search(t):
            out.append(aid)
        if len(out) >= 2:
            break
    if not out:
        for tp in topics:
            if tp in TOPIC_FALLBACK:
                out.append(TOPIC_FALLBACK[tp]); break
    return out


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


# 制度に関わるニュースの見出し（2026-11-08 ユーザー「防災だけじゃなくて、制度に関連するニュースでもいいよね」）。
# 府省の発表（上の SEIDO）とは別に、報道の見出しを /seido の分類に分ける。分類のキーは SEIDO と同じ。
NEWS_SEIDO = [
    ('roumu', re.compile(r'最低賃金|賃上げ|春闘|労働時間|働き方改革|残業|長時間労働|育休|育児休業|介護休業|社会保険|年収の壁|106万|130万|厚生年金|雇用保険|外国人労働者|技能実習|育成就労|ハラスメント|労災|過労死|有給休暇|週休3日')),
    ('joho', re.compile(r'個人情報(保護|の漏|流出)|個人情報保護委員会|情報漏え?い|サイバー攻撃|不正アクセス|ランサム')),
    ('shien', re.compile(r'補助金|助成金|支援金|給付金|資金繰り|中小企業.{0,10}(支援|融資)|事業再構築|持続化')),
    ('torihiki', re.compile(r'取適法|下請|価格転嫁|フリーランス(法|新法|保護)|公正取引委員会|公取委|独占禁止法|優越的地位')),
    ('tetsuzuki', re.compile(r'消費税|減税|増税|給付付き税額控除|インボイス|所得税|住民税|基礎控除|年末調整|電子帳簿|定額減税|税制(改正|大綱)|確定申告|ふるさと納税|マイナンバー')),
]
NEWS_SEIDO_NOISE = re.compile(r'サイバートラック|見逃し配信|プレゼント|占い|(米国|中国|韓国|英国|欧州|トランプ).{0,12}(減税|増税|関税)')


from collections import Counter


def news_seido(news_items):
    """news-cards の見出し（直近60日）から、制度の分類に当たるものを返す。"""
    d = json.load(open(SRC, encoding='utf-8'))
    cutoff = time.strftime('%Y-%m-%d', time.localtime(time.time() - DAYS * 86400))
    seen, out = set(), []
    for x in d['items']:
        if x['day'] < cutoff:
            continue
        t = STRIP.sub('', x['headline'])
        if NEWS_SEIDO_NOISE.search(t):
            continue
        cats = [k for k, rx in NEWS_SEIDO if rx.search(t)]
        if not cats:
            continue
        key = re.sub(r'\s+', '', x['headline'])
        if key in seen:
            continue
        seen.add(key)
        out.append({'day': x['day'], 'time': x.get('time', ''), 'source': x.get('source', ''), 'headline': x['headline'], 'cats': cats, 'apps': apps_for(x['headline']),
                    'url': x.get('newsUrl') or x.get('post') or '',
                    'ex': f"https://exbridge.jp/news/{x['day']}/{x['id']}.html" if x.get('id') else ''})
    out.sort(key=lambda x: (x['day'], x['time']), reverse=True)
    return out


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
        out.append({'day': x.get('date', ''), 'ministry': x.get('ministry_name', ''), 'title': t, 'cats': cats, 'apps': apps_for(t),
                    'url': x.get('url', ''), 'ex': f"https://exbridge.jp/shocho/n/{x['id']}.html" if x.get('id') else ''})
    out.sort(key=lambda x: x['day'], reverse=True)
    nws = news_seido(None)
    json.dump({'generated_at': time.strftime('%Y-%m-%d %H:%M'), 'items': out, 'news': nws}, open(SEIDO_OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f'seido のニュース: {len(nws)}件（{dict(Counter(c for x in nws for c in x["cats"]))}）')
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
            'headline': x['headline'], 'topics': tp, 'apps': apps_for(x['headline'], tp),
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
