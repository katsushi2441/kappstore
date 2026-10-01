#!/usr/bin/env python3
"""「開発中・要お問い合わせ」の掲載を台帳(apps.json)に足す（2026-10-01）。

システムはまだ無いので、配布ファイル・価格を持たない。台帳の dev 欄（例:「開発中」）が立っていると、
app.php は価格・購入ボタンの代わりに問い合わせボタンを出す。中身は kpayload/data/solution-list.json の
kind=aichat の項目（/solution/ の着地ページと同じ文）から組むので、文が2か所でずれない。

  /usr/bin/python3 scripts/list_dev_app.py --dry      # 組み上がる台帳の差分だけ見る
  /usr/bin/python3 scripts/list_dev_app.py --apply    # 1接続(FTPS)で 画像・台帳・変えたPHP を送る

台帳は書き換える前に outputs/ に退避する。同じ id があれば作成日時を残して上書き（二重掲載しない）。
"""
import argparse, ftplib, io, json, os, re, secrets, sys, time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOL = "/home/kojima/work/kpayload/data/solution-list.json"
OGP = "/home/kojima/work/exbridge_jp/images/ogp/sol-{}.png"
REMOTE = "/web/kappstore_exbridge_jp"
PHP = ["app.php", "index.php", "llms.php", "catalog.php", "kapp_usecases.php", "kapp_categories.php"]   # 開発中の表示に合わせて直したもの


def env():
    e = {}
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        m = re.match(r"(FTP_[A-Z]+)=(.*)", ln.strip())
        if m:
            e[m.group(1)] = m.group(2).strip("\"'")
    return e


def body_md(p):
    out = [p["lead"], "", "## こんな質問に、こう答える予定です", ""]
    for a in p.get("asks", []):
        out += [f"**{a['q']}**", "", a["a"], ""]
    out += ["## 答えの決め方", "", p.get("how", ""), "", "## 使う公開データ", ""]
    out += [f"- **{s['name']}**: {s['what']}" for s in p.get("sources", [])]
    out += ["", "## 使う人・置く場所", ""] + [f"- {u}" for u in p.get("users", [])]
    out += ["", "## いま使える当社のシステム（このAI相談の土台）", ""]
    out += [f"- [{r['label']}]({r['url']}{'&' if '?' in r['url'] else '?'}ref=kappstore-dev-{p['slug']}): {r['what']}" for r in p.get("related", [])]
    out += ["", "## 開発状況", "",
            "現在開発中です。使いたい自治体・会社・事務所のご要望を伺い、対象の地域・必要な項目・置き方"
            "（自社サイトに埋め込む／自社サーバーに置く／AIエージェントから呼ぶ）を決めて開発します。価格はご相談のうえでお見積りします。"]
    return "\n".join(out)


def record(p, old, seo):
    kid = p["kapp"]["url"].split("id=")[1]
    now = int(time.time())
    r = dict(old or {})
    r.update({
        "id": kid, "seller": "xb_bittensor", "name": p["name"],
        "summary": p["facts"], "body": body_md(p),
        "demo_url": f"https://exbridge.jp/solution/{p['slug']}.html?ref=kappstore-dev",
        "demo_label": "詳しい説明（開発中の仕様）を見る",
        "price": 0, "file": "", "filename": "", "filesize": 0,
        "image": (old or {}).get("image") or secrets.token_hex(12) + ".png",
        "status": "published", "dev": "開発中",
        "faq": [[f["q"], f["a"]] for f in p.get("faqs", []) if not f["q"].startswith("いつから")],
        "seo_title": seo["title"], "seo_desc": seo["desc"],
        "created_at": (old or {}).get("created_at") or now, "updated_at": now,
    })
    return r


def main():
    ap = argparse.ArgumentParser()
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--dry", action="store_true"); g.add_argument("--apply", action="store_true")
    a = ap.parse_args()
    pages = [p for p in json.load(open(SOL, encoding="utf-8")) if p.get("kind") == "aichat" and p.get("kapp")]
    seo = json.load(open(os.path.join(ROOT, "scripts", "seo_meta.json"), encoding="utf-8"))["items"]
    e = env()
    f = ftplib.FTP_TLS(e["FTP_HOST"], timeout=60); f.login(e["FTP_USER"], e["FTP_PASS"]); f.prot_p()
    try:
        buf = io.BytesIO(); f.retrbinary(f"RETR {REMOTE}/kapp_data/apps.json", buf.write)
        raw = buf.getvalue(); data = json.loads(raw)
        n0 = len(data["apps"])
        os.makedirs(os.path.join(ROOT, "outputs"), exist_ok=True)
        bak = os.path.join(ROOT, "outputs", time.strftime("apps.json.bak-%Y%m%d_%H%M%S"))
        open(bak, "wb").write(raw)
        by = {x["id"]: i for i, x in enumerate(data["apps"])}
        imgs = []
        for p in pages:
            kid = p["kapp"]["url"].split("id=")[1]
            old = data["apps"][by[kid]] if kid in by else None
            r = record(p, old, seo[kid])
            if kid in by:
                data["apps"][by[kid]] = r
            else:
                data["apps"].append(r)
            imgs.append((OGP.format(p["slug"]), r["image"]))
            print(("更新" if old else "追加"), kid, r["name"], "image", r["image"])
        print(f"台帳 {n0} → {len(data['apps'])} 件（退避: {bak}）")
        if a.dry:
            return
        for src, name in imgs:
            f.storbinary(f"STOR {REMOTE}/kapp_media/{name}", open(src, "rb"))
        for ph in PHP:
            f.storbinary(f"STOR {REMOTE}/{ph}", open(os.path.join(ROOT, "public", ph), "rb"))
        out = json.dumps(data, ensure_ascii=False).encode()   # 本番の台帳と同じ1行の形
        f.storbinary(f"STOR {REMOTE}/kapp_data/apps.json", io.BytesIO(out))
        print("送信済み: 画像", len(imgs), "・PHP", len(PHP), "・台帳")
    finally:
        f.quit()


if __name__ == "__main__":
    main()
