#!/usr/bin/env python
# mobile/tool/gen_app_icon.py — renders the launcher icon from one master.
#
# The artwork is the Lao initials ຕດ in the app's own green, drawn from the
# Noto Sans Lao Bold that the web app already ships. Run this after editing the
# constants below; it rewrites every Android mipmap and every iOS AppIcon PNG,
# so the two platforms can never drift apart.
#
#   python mobile/tool/gen_app_icon.py [--preview path.png]
#
# iOS needs the sizes listed in Contents.json rather than a fixed set, so the
# icon set is read instead of hardcoded. Android uses the five densities the
# project actually carries: there is no mipmap-anydpi-v26, so no adaptive
# foreground/background layers to write either.
import argparse
import json
import pathlib
import sys

from PIL import Image, ImageDraw, ImageFont

ROOT = pathlib.Path(__file__).resolve().parents[1]          # mobile/
FONT = ROOT.parent / "public" / "fonts" / "NotoSansLao-Bold.ttf"
IOS_SET = ROOT / "ios" / "Runner" / "Assets.xcassets" / "AppIcon.appiconset"

TEXT = "ຕດ"
BG = (0x0B, 0x3D, 0x2E)      # lib/core/theme.dart gov
FG = (255, 255, 255)
MASTER = 1024
GLYPH_WIDTH = 0.60           # of the canvas: every launcher masks the corners

ANDROID_DENSITIES = {
    48: "mipmap-mdpi",
    72: "mipmap-hdpi",
    96: "mipmap-xhdpi",
    144: "mipmap-xxhdpi",
    192: "mipmap-xxxhdpi",
}


def font_for(draw, target_width):
    """The largest bold Lao font whose ink is at most target_width wide."""
    size, chosen = 0, None
    for candidate in range(32, MASTER, 2):
        font = ImageFont.truetype(str(FONT), candidate)
        box = draw.textbbox((0, 0), TEXT, font=font)
        if box[2] - box[0] > target_width:
            break
        size, chosen = candidate, font
    return size, chosen


def render():
    img = Image.new("RGB", (MASTER, MASTER), BG)
    draw = ImageDraw.Draw(img)
    size, font = font_for(draw, int(GLYPH_WIDTH * MASTER))
    left, top, right, bottom = draw.textbbox((0, 0), TEXT, font=font)
    # Centring the ink rather than the em box: the Lao line sits low in its
    # ascent and the ດ tail hangs below the baseline, so the em box is off-centre.
    x = (MASTER - (right - left)) / 2 - left
    y = (MASTER - (bottom - top)) / 2 - top
    draw.text((x, y), TEXT, font=font, fill=FG)
    return img, size


def scaled(master_img, px):
    return master_img.resize((px, px), Image.LANCZOS) if px != MASTER else master_img


def write_android(master_img):
    res = ROOT / "android" / "app" / "src" / "main" / "res"
    written = []
    for px, folder in sorted(ANDROID_DENSITIES.items()):
        path = res / folder / "ic_launcher.png"
        if not (res / folder).is_dir():
            print("skip, density folder absent: %s" % folder, file=sys.stderr)
            continue
        scaled(master_img, px).save(path)
        written.append("%s/ic_launcher.png = %dpx" % (folder, px))
    return written


def write_ios(master_img):
    entries = json.loads((IOS_SET / "Contents.json").read_text(encoding="utf-8"))["images"]
    # Keyed by filename, not by pixel size: two rows can ask for the same size
    # under different names (40x40@1x and 20x20@2x are both 40px), and every
    # file the set lists has to be replaced or it keeps the old artwork.
    targets = {}
    for entry in entries:
        if "size" not in entry or "filename" not in entry:
            continue
        # Contents.json writes "2x" for the scale, not 2.
        scale = str(entry.get("scale", "1x")).rstrip("x") or "1"
        px = int(round(float(entry["size"].split("x")[0]) * float(scale)))
        targets.setdefault(entry["filename"], px)
    for name, px in sorted(targets.items(), key=lambda kv: kv[1]):
        scaled(master_img, px).save(IOS_SET / name)
    return ["%s = %dpx" % (name, px) for name, px in sorted(targets.items(), key=lambda kv: kv[1])]


def main():
    if not FONT.is_file():
        sys.exit("Lao font not found at %s" % FONT)
    parser = argparse.ArgumentParser()
    parser.add_argument("--preview", help="also write the 1024px master here")
    args = parser.parse_args()

    master_img, font_size = render()
    print("text %s at font size %d, background %s" % (TEXT, font_size, "#%02X%02X%02X" % BG))
    for line in write_android(master_img):
        print("  android  %s" % line)
    for line in write_ios(master_img):
        print("  ios      %s" % line)
    if args.preview:
        master_img.save(args.preview)
        print("  master   %s" % args.preview)


if __name__ == "__main__":
    main()
