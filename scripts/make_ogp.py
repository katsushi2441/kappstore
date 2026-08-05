#!/usr/bin/env python3
"""Kurage App Store のOGP画像(1200x630)を作る。

kurage_web/scripts/make_vibe_prototype_ogp.py と同じ配色・フォントで作り、
Kurageシリーズのカードとして並んだときに揃って見えるようにする。

実行: python3 scripts/make_ogp.py  →  public/assets/ogp.png
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FONT_R = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"

W, H = 1200, 630
FOAM = "#f5fbfb"
PANEL = "#e7f3f2"
ABYSS = "#12202f"
MUTED = "#55697a"
TEAL = "#12a99f"
TEAL_DEEP = "#0a726b"

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "public" / "assets" / "ogp.png"
AVATAR = ROOT / "public" / "assets" / "kurage_avatar.png"

img = Image.new("RGB", (W, H), FOAM)
dr = ImageDraw.Draw(img)

# 右側に淡いパネルを敷いて、アバターの背景にする
dr.rectangle([(W - 380, 0), (W, H)], fill=PANEL)
dr.rectangle([(0, 0), (W, 10)], fill=TEAL)

x = 84
f_eyebrow = ImageFont.truetype(FONT, 25)
f_title = ImageFont.truetype(FONT, 66)
f_lead = ImageFont.truetype(FONT_R, 27)
f_pill = ImageFont.truetype(FONT, 24)

dr.text((x, 108), "KURAGE APP STORE", font=f_eyebrow, fill=TEAL_DEEP)

dr.text((x, 160), "AIが設置できる", font=f_title, fill=ABYSS)
dr.text((x, 244), "業務システムの店", font=f_title, fill=TEAL_DEEP)

dr.text((x, 356), "デモを触ってから買えます。", font=f_lead, fill=ABYSS)
dr.text((x, 396), "買ったらAIエージェントに渡すだけで、", font=f_lead, fill=MUTED)
dr.text((x, 434), "設置もカスタマイズもそのまま進みます。", font=f_lead, fill=MUTED)

# 特徴のピル
px = x
for label in ("デモあり", "ソース同梱", "改変自由"):
    tw = dr.textlength(label, font=f_pill)
    dr.rounded_rectangle([(px, 500), (px + tw + 44, 546)], radius=23, fill=TEAL)
    dr.text((px + 22, 508), label, font=f_pill, fill="#ffffff")
    px += tw + 60

dr.text((x, 578), "kappstore.exbridge.jp", font=f_pill, fill=MUTED)

# Kurage キャラ（正規アセット）
if AVATAR.exists():
    av = Image.open(AVATAR).convert("RGBA")
    side = 300
    av.thumbnail((side, side), Image.LANCZOS)
    img.paste(av, (W - 190 - av.width // 2, (H - av.height) // 2), av)

OUT.parent.mkdir(parents=True, exist_ok=True)
img.save(OUT, "PNG", optimize=True)
print(f"wrote {OUT} ({OUT.stat().st_size:,} bytes)")
