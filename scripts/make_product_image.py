#!/usr/bin/env python3
# kappstore用の商品画像。実際の予約画面(デモのスクリーンショット)を主役にする。
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 675
SHOT = "/tmp/claude-1000/-home-kojima-work/7230f738-72fc-4ac2-ae71-cd89b6f444a1/scratchpad/kres_mobile.png"
OUT = "outputs/kreserve_product.png"

img = Image.new("RGB", (W, H), "#f2f7f9")
d = ImageDraw.Draw(img)
# 上帯
for x in range(W):
    t = x / W
    r = int(0x0b + (0x2f - 0x0b) * t); g = int(0x91 + (0x6b - 0x91) * t); b = int(0xa7 + (0xd8 - 0xa7) * t)
    d.line([(x, 0), (x, 8)], fill=(r, g, b))

black = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
bold = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
f_t = ImageFont.truetype(black, 56)
f_s = ImageFont.truetype(bold, 26)
f_b = ImageFont.truetype(bold, 22)

# 右: 実画面(モバイル予約ページ)を端末風フレームで
shot = Image.open(SHOT).convert("RGB")
sw = 340
sh = int(shot.height * sw / shot.width)
shot = shot.resize((sw, sh), Image.LANCZOS).crop((0, 0, sw, 560))
fx, fy = W - sw - 90, 70
d.rounded_rectangle([fx - 12, fy - 12, fx + sw + 12, fy + 560 + 12], radius=26, fill="#1c2836")
img.paste(shot, (fx, fy))

# 左: コピー
d.text((70, 90), "予約・受付システム", font=f_t, fill="#17324d")
d.text((70, 170), "kreserve", font=ImageFont.truetype(black, 44), fill="#0b91a7")
d.text((70, 260), "サロン・クリニック・士業の予約ページ＋管理画面", font=f_s, fill="#17324d")
bullets = [
    "メニュー→日→時間→お名前の4ステップ予約",
    "ダブルブッキング防止・キャンセルURL・確認メール",
    "PHPのみ・DB不要・レンタルサーバーにFTPで置くだけ",
    "設定変更はAI(Claude Code)に頼める設計マニュアル同梱",
]
y = 330
for t in bullets:
    d.ellipse([70, y + 10, 82, y + 22], fill="#0b91a7")
    d.text((96, y), t, font=f_b, fill="#3d4f61")
    y += 46
d.text((70, y + 24), "MITライセンス／全部読めるサイズ(約800行)／デモを触ってから購入", font=ImageFont.truetype(bold, 18), fill="#64788a")

img.save(OUT)
print("saved:", OUT, img.size)
