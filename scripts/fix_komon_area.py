#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""商品本文に残る AI-IT顧問の古い対象地域「名古屋市内」を「愛知県内」に直す（2026-11-02）。

AI-IT顧問は 2026-10-26 に対象を「名古屋市内限定」から「愛知県内」に広げた（名古屋市外は Zoom 中心）。
商品ページのテンプレート（app.php・index.php）は 11/2 に直したが、商品ごとの本文に3つの書き方で残っていた。
データの範囲を指す「名古屋市内」（避難所・事業所など）は直さない。決まった3つの書き方だけを置き換える。

  python3 scripts/fix_komon_area.py --dry
  python3 scripts/fix_komon_area.py --apply

台帳は1回だけ取り、まとめて直して、退避してから1回で書き戻す（FTP を短い間に何度もつながない）。
"""
import argparse
import json
import os
import sys
from datetime import datetime

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from update_body import env, fetch_ledger, put_ledger, OUT  # noqa: E402

PAIRS = [
    ('名古屋市内の事業者・事務所は [AI-IT顧問契約]', '愛知県内の事業者・事務所は [AI-IT顧問契約]'),
    ('**名古屋市内の会社さまへ。** AI-IT顧問契約', '**愛知県内の会社さまへ。** AI-IT顧問契約'),
    ('**AI-IT顧問契約**（名古屋市内・月15時間・税別150,000円）', '**AI-IT顧問契約**（愛知県内・月15時間・税別150,000円）'),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--apply', action='store_true')
    ap.add_argument('--dry', action='store_true')
    a = ap.parse_args()
    if not (a.apply or a.dry):
        sys.exit('--dry か --apply を指定してください')
    e = env()
    data = fetch_ledger(e)
    orig = json.loads(json.dumps(data))   # 退避用に、直す前の台帳をそのまま写しておく
    n_before = len(data.get('apps', []))
    changed = []
    for app in data.get('apps', []):
        body = app.get('body') or ''
        new = body
        for old, rep in PAIRS:
            new = new.replace(old, rep)
        if new != body:
            app['body'] = new
            changed.append((app['id'], app['name'][:40], sum(body.count(o) for o, _ in PAIRS)))
    for i, n, c in changed:
        print(f'  {i} {c}か所  {n}')
    print(f'直す商品 {len(changed)} 件 / 台帳 {n_before} 件')
    if a.dry or not changed:
        return
    os.makedirs(OUT, exist_ok=True)
    stamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    # 書き戻す前に、取ってきたままの台帳を退避する（直したものではなく元のもの）
    json.dump(orig, open(os.path.join(OUT, f'ledger_backup_{stamp}.json'), 'w', encoding='utf-8'), ensure_ascii=False)
    if len(data['apps']) != n_before:
        sys.exit('件数が変わったので止めます')
    put_ledger(e, data, os.path.join(OUT, f'ledger_new_{stamp}.json'))
    print(f'書き戻しました（退避: outputs/ledger_backup_{stamp}.json）')


if __name__ == '__main__':
    main()
