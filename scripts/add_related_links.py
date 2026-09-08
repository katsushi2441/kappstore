#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""日本語導入キット（5,500円）の商品本文に「同じ用途の買い切りアプリ」への横リンクを足す（冪等）。
検索の入口はキット（Docmost/CodeAlmanac/paperless-ngx…）なので、そこから買い切りアプリへ渡す。
  /usr/bin/python3 scripts/add_related_links.py            # 本番台帳を退避→更新→配置
  /usr/bin/python3 scripts/add_related_links.py --dry-run  # 変更内容だけ表示
リンクには ref=kit-<slug> を付け、access_report.py で効果を数える。
"""
import datetime
import ftplib
import io
import json
import os
import sys

MARK = '## あわせて（同じ用途の買い切りアプリ）'
STORE = 'https://kappstore.exbridge.jp/app.php?id='
APPS = {  # 買い切りアプリ id → 短い説明
    '2186dc017968081c': ('Kurage Memo', 'Simplenoteの代わりをセルフホスト。1ファイルPHPのメモ'),
    'cd1eda3248c87920': ('Kurage AI MOM', 'Whisperで文字起こし・AI議事録を自社サーバーで'),
    '719429354f079793': ('Kurage DB Agent', 'AIから安全にDBを操作させる。範囲を宣言して制限'),
    'cfb7c4a69621600f': ('Kurage Architect', 'AIと対話してシステム設計書をつくる'),
    'c8ffa66502f7d905': ('Kurage SEO', '日本語サイト向けのSEO診断を買い切りで'),
    '48ca584977698dcc': ('ktrackgeo', '自社サイトはAIに読まれているか。AIクローラー計測'),
    '56bf3ddd46b5a457': ('Kurage HR Post', '業務引き継ぎ・属人化解消の職務管理'),
    'c1864cba4ab726b0': ('kvgwc', 'kintone・サイボウズの代わりに買い切りグループウェア'),
    'f0f56c6e4da881be': ('Kurage Kintai', '顔打刻つき勤怠管理を安く導入'),
    'f6fab083d826739f': ('Kurage CRM Agent', 'エクセル日報を自動化する入力ゼロCRM'),
    '4679d4cf90699580': ('kaima', 'AI名刺解析。Sansanの代わりに'),
    '362c94ab4e1384f2': ('Kurage 予約・受付', '予約管理システムを買い切りで'),
    '224e141f77bd07a8': ('Kurage Light ChatBot', 'AIチャットボットを買い切りで'),
    '4bd9a6f3f99cdc05': ('kbbs', '宣伝・求人を無料掲載できる掲示板を自社で持つ'),
    '61febea74f9c74b0': ('kinvoice', '領収書をPDFで発行してメール送信'),
    '15abb025dc2ee4f6': ('kbilling', '請求書ソフトを買い切りで・発行から集金まで'),
    '5b55bb2c808eead4': ('kpaylink', '注文フォームから請求書・決済まで'),
    '32502ed71cea6bcf': ('Kurage 通報先ナビ', '困りごと→どこに言えばいいかを一発で'),
    '237974724fb41216': ('Kurage 制度ナビ', '困りごと→使える制度・申請先・期限・書類。相談記録つき'),
    '41a09acc163dcb7d': ('Kurage 洪水・内水ハザードマップ', '住所→洪水・内水で何メートル・何日浸かるか'),
    '162f155897390072': ('Kurage 避難所マップ', 'その災害で使える避難所まで徒歩何分か'),
    '8e76e53cf264cc1c': ('Kurage 施設検索', '今日、個人で使える体育館を横断検索'),
    'a5ac4b9f1fdb6d19': ('Kurage 商圏分析', '住所ひとつで徒歩圏の人口・世帯・事業所'),
}
# キット id → (ref用slug, 見出しの一言, 関連アプリ id)
KITS = {
    '7495b31ca104b483': ('docmost', 'ナレッジ・文書', ['2186dc017968081c', 'cd1eda3248c87920', '719429354f079793']),
    '7f9481c10a09b560': ('docspell', '書類の保管', ['2186dc017968081c', 'cd1eda3248c87920', '61febea74f9c74b0']),
    '2c8193abe130c90c': ('paperless', '書類の保管', ['2186dc017968081c', 'cd1eda3248c87920', '61febea74f9c74b0']),
    '8adb859f30cb8cc5': ('pdfmath', '文書・翻訳', ['cd1eda3248c87920', '2186dc017968081c', 'cfb7c4a69621600f']),
    'b81d730eb535b454': ('codealmanac', '開発・設計', ['cfb7c4a69621600f', '719429354f079793', 'c8ffa66502f7d905']),
    '767ea5a2f7963f2a': ('vikunja', 'タスク・進行管理', ['56bf3ddd46b5a457', 'c1864cba4ab726b0', 'f0f56c6e4da881be']),
    '1364b33c7d1cde58': ('planka', 'タスク・進行管理', ['56bf3ddd46b5a457', 'c1864cba4ab726b0', 'f0f56c6e4da881be']),
    '9d27eb0ebe2fc7e0': ('espocrm', '顧客管理', ['f6fab083d826739f', '4679d4cf90699580', '362c94ab4e1384f2']),
    '11e7c1d9a83d1ca3': ('krayin', '顧客管理', ['f6fab083d826739f', '4679d4cf90699580', '362c94ab4e1384f2']),
    '6d0e2c491e4170da': ('freescout', '問い合わせ対応', ['224e141f77bd07a8', 'f6fab083d826739f', '362c94ab4e1384f2']),
    '104c05db7779a709': ('libredesk', '問い合わせ対応', ['224e141f77bd07a8', 'f6fab083d826739f', '362c94ab4e1384f2']),
    'a3b60acb11b65f47': ('decap', 'サイト運営', ['c8ffa66502f7d905', '48ca584977698dcc', '4bd9a6f3f99cdc05']),
    'a5109169d3b23989': ('appsmith', '社内ツール', ['719429354f079793', 'c1864cba4ab726b0', 'cfb7c4a69621600f']),
    '495b5aca4ee119db': ('billionmail', 'メール・請求', ['61febea74f9c74b0', '15abb025dc2ee4f6', '5b55bb2c808eead4']),
    '025aa9bee5dd411e': ('alaveteli', '自治体・議員事務所', ['32502ed71cea6bcf', '237974724fb41216', '41a09acc163dcb7d', '162f155897390072']),
    'b34e36cfaad27a14': ('fixmystreet', '自治体・議員事務所', ['32502ed71cea6bcf', '41a09acc163dcb7d', '162f155897390072', '8e76e53cf264cc1c']),
    '53493d74a09cfd8c': ('kouchou', '自治体・議員事務所', ['237974724fb41216', '32502ed71cea6bcf', 'a5ac4b9f1fdb6d19', '41a09acc163dcb7d']),
}


def env():
    for l in open('/home/kojima/work/aixec/.env', encoding='utf-8'):
        if '=' in l and not l.startswith('#'):
            k, v = l.rstrip('\n').split('=', 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def section(slug, theme, ids, names):
    lines = [MARK, '', f'導入キットは「まず使ってみる」入口です。{theme}の仕事をそのまま自社の名前で運用するなら、買い切りのアプリもあります（ソースコード同梱・追加費用なし）。', '']
    for i in ids:
        if i not in names:
            continue
        nm, what = APPS[i]
        lines.append(f'- [{names[i]}]({STORE}{i}&ref=kit-{slug}) — {what}')
    lines.append('')
    lines.append('名古屋市内の事業者・事務所は [AI-IT顧問契約](https://exbridge.jp/ai-it-komon.html?ref=kit-' + slug + ')（キャンペーン期間中はKurage App Storeの商品代金が無料）で設置まで任せられます。')
    return '\n'.join(lines)


def main():
    dry = '--dry-run' in sys.argv
    env()
    f = ftplib.FTP(os.environ['FTP_HOST'], timeout=300)
    f.login(os.environ['FTP_USER'], os.environ['FTP_PASS'])
    f.cwd('/web/kappstore_exbridge_jp/kapp_data')
    b = io.BytesIO()
    f.retrbinary('RETR apps.json', b.write)
    raw = b.getvalue()
    here = os.path.dirname(os.path.abspath(__file__))
    os.makedirs(os.path.join(here, '..', 'outputs', 'ledger_backups'), exist_ok=True)
    open(os.path.join(here, '..', 'outputs', 'ledger_backups', f'apps_backup_{datetime.datetime.now():%Y%m%d_%H%M%S}.json'), 'wb').write(raw)
    d = json.loads(raw)
    names = {a['id']: a['name'] for a in d['apps']}
    n_before = len(d['apps'])
    changed = 0
    for a in d['apps']:
        k = KITS.get(a['id'])
        if not k:
            continue
        slug, theme, ids = k
        body = a.get('body', '')
        if MARK in body:
            continue
        a['body'] = body.rstrip() + '\n\n' + section(slug, theme, ids, names)
        a['updated_at'] = int(datetime.datetime.now().timestamp())
        changed += 1
        print(f'  + {a["name"][:40]} ← {[names[i][:14] for i in ids if i in names]}')
    print(f'変更 {changed} 件 / 台帳 {n_before} 件')
    if dry or not changed:
        f.quit()
        return
    assert len(d['apps']) == n_before
    f.storbinary('STOR apps.json', io.BytesIO(json.dumps(d, ensure_ascii=False).encode('utf-8')))
    f.quit()
    print('配置完了')


if __name__ == '__main__':
    main()
