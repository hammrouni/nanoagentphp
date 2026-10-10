#!/usr/bin/env python3
"""Build the favicons, app icons and logo images from the design in ``logos/main``.

    python scripts/make_icons.py

``logos/main/Main.dc.html`` is the design reference. Its geometry is reproduced
here, so no raster source and no SVG renderer is needed (only Pillow):

    mark B (Colour)    an indigo hexagon on a cyan one, with a white N: the logo,
                       the favicon and the app icons (16 px uses the design's
                       heavier N)

Everything is written to ``logos/`` and copied to ``site/assets/`` (the site).
Output:
    mark.svg                               vector source
    favicon.ico                            16, 32 and 48 px in one file
    icon-16.png ... icon-512.png           mark icons: 16 32 48 64 96 128 192 256 512
    apple-touch-icon.png                   180 px, mark on white (iOS rounds it)
    icon-mark-transparent.png              512 px mark on transparency
    logo-full-400.png, -800.png            mark + wordmark, dark text, 400 and 800 px wide
    logo-full-light-800.png                the same with a white wordmark, for dark pages
    logo-full-transparent.png              the lockup at 1600 px wide
    site.webmanifest                       web app manifest
"""
from __future__ import annotations

import argparse
import json
import shutil
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

DEFAULT_OUT = Path("logos")
DEFAULT_PUBLISH = Path("site/assets")
ICON_SIZES = (16, 32, 48, 64, 96, 128, 192, 256, 512)
SS = 8  # supersampling factor

INDIGO = "#4A55B5"
CYAN = "#4BC4E8"
INK = "#0F1B2A"
WHITE = "#FFFFFF"
BG = "#FFFFFF"
THEME = "#4A55B5"

WORDMARK = "NANOAGENT"
TRACKING = 0.3  # letter spacing, in em
WORDMARK_FONTS = ("Montserrat-Regular.ttf", "segoeui.ttf", "arial.ttf", "DejaVuSans.ttf")

Point = tuple[float, float]

HEX_OUTER: list[Point] = [(110, 60), (85, 103.3), (35, 103.3), (10, 60), (35, 16.7), (85, 16.7)]
HEX_INNER: list[Point] = [(60, 14), (99.8, 37), (99.8, 83), (60, 106), (20.2, 83), (20.2, 37)]
N_RECTS = [(45, 41, 4, 38), (71, 41, 4, 38)]
N_DIAG: list[Point] = [(45, 41), (53, 41), (75, 79), (67, 79)]
N_RECTS_SMALL = [(42, 38, 9, 44), (69, 38, 9, 44)]
N_DIAG_SMALL: list[Point] = [(42, 38), (54, 38), (78, 82), (66, 82)]


def to_rgba(color: str) -> tuple[int, int, int, int]:
    color = color.lstrip("#")
    return int(color[0:2], 16), int(color[2:4], 16), int(color[4:6], 16), 255


def scaled(pts: list[Point], k: float) -> list[Point]:
    return [(x * k, y * k) for x, y in pts]


def stroke(draw: ImageDraw.ImageDraw, pts: list[Point], width: float, color: str, k: float) -> None:
    """A polyline with round caps and joins; coordinates are design units scaled by k."""
    fill = to_rgba(color)
    r = width * k / 2
    points = scaled(pts, k)
    for a, b in zip(points, points[1:]):
        draw.line([a, b], fill=fill, width=max(1, round(width * k)))
    for x, y in points:
        draw.ellipse([x - r, y - r, x + r, y + r], fill=fill)


def filled(draw: ImageDraw.ImageDraw, pts: list[Point], color: str, k: float, width: float = 4) -> None:
    """A filled polygon with a same-colour round-joined stroke, as the SVG draws it."""
    draw.polygon(scaled(pts, k), fill=to_rgba(color))
    stroke(draw, [*pts, pts[0]], width, color, k)


def draw_mark(draw: ImageDraw.ImageDraw, k: float, small: bool = False) -> None:
    """Mark B on the 120 x 120 design grid."""
    filled(draw, HEX_OUTER, CYAN, k)
    filled(draw, HEX_INNER, INDIGO, k)
    rects, diag = (N_RECTS_SMALL, N_DIAG_SMALL) if small else (N_RECTS, N_DIAG)
    for x, y, w, h in rects:
        draw.rectangle([x * k, y * k, (x + w) * k, (y + h) * k], fill=to_rgba(WHITE))
    draw.polygon(scaled(diag, k), fill=to_rgba(WHITE))


def render(size: int, painter) -> Image.Image:
    big = size * SS
    image = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    painter(ImageDraw.Draw(image), big / 120)
    return image.resize((size, size), Image.LANCZOS)


def mark_icon(size: int) -> Image.Image:
    return render(size, lambda d, k: draw_mark(d, k, small=size <= 16))


def wordmark_font(px: int) -> ImageFont.FreeTypeFont:
    for name in WORDMARK_FONTS:
        try:
            return ImageFont.truetype(name, px)
        except OSError:
            continue
    return ImageFont.load_default(px)


def lockup(color: str, width: int = 1600) -> Image.Image:
    """The mark and the tracked wordmark side by side, trimmed, on transparency."""
    mark_px = width // 5
    font_px = round(mark_px * 0.4)
    font = wordmark_font(font_px)
    advances = [font.getlength(ch) + TRACKING * font_px for ch in WORDMARK]
    text_w = round(sum(advances) - TRACKING * font_px)
    box = font.getbbox("N")
    gap = round(mark_px * 0.3)
    canvas = Image.new("RGBA", (mark_px + gap + text_w, mark_px), (0, 0, 0, 0))
    canvas.alpha_composite(mark_icon(mark_px))
    draw = ImageDraw.Draw(canvas)
    x = mark_px + gap
    y = (mark_px - (box[3] - box[1])) / 2 - box[1]
    for ch, advance in zip(WORDMARK, advances):
        draw.text((x, y), ch, font=font, fill=to_rgba(color))
        x += advance
    canvas = canvas.crop(canvas.getbbox())
    return canvas.resize((width, max(1, round(canvas.height * width / canvas.width))), Image.LANCZOS)


def width_of(image: Image.Image, width: int) -> Image.Image:
    return image.resize((width, max(1, round(image.height * width / image.width))), Image.LANCZOS)


def points(pts: list[Point]) -> str:
    return " ".join(f"{x:g},{y:g}" for x, y in pts)


MARK_SVG = (
    '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">'
    f'<polygon points="{points(HEX_OUTER)}" fill="{CYAN}" stroke="{CYAN}" stroke-width="4" stroke-linejoin="round"/>'
    f'<polygon points="{points(HEX_INNER)}" fill="{INDIGO}" stroke="{INDIGO}" stroke-width="4" stroke-linejoin="round"/>'
    '<g fill="#FFFFFF"><rect x="45" y="41" width="4" height="38"/><rect x="71" y="41" width="4" height="38"/>'
    f'<polygon points="{points(N_DIAG)}"/></g></svg>\n'
)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    parser.add_argument("--publish", type=Path, default=DEFAULT_PUBLISH,
                        help="also copy the set here (the folder the site serves)")
    parser.add_argument("--name", default="NanoAgent")
    args = parser.parse_args()

    out = args.out
    out.mkdir(parents=True, exist_ok=True)

    (out / "mark.svg").write_text(MARK_SVG, encoding="utf-8")

    icons = {size: mark_icon(size) for size in ICON_SIZES}
    for size, icon in icons.items():
        icon.save(out / f"icon-{size}.png")
    icons[16].save(out / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)],
                   append_images=[icons[32], icons[48]])
    touch = Image.new("RGBA", (180, 180), to_rgba(WHITE))
    touch.alpha_composite(mark_icon(132), (24, 24))
    touch.convert("RGB").save(out / "apple-touch-icon.png")
    mark_icon(512).save(out / "icon-mark-transparent.png")

    full = lockup(INK)
    full.save(out / "logo-full-transparent.png")
    width_of(full, 400).save(out / "logo-full-400.png")
    width_of(full, 800).save(out / "logo-full-800.png")
    width_of(lockup(WHITE), 800).save(out / "logo-full-light-800.png")

    manifest = {
        "name": args.name,
        "short_name": args.name,
        "start_url": "/",
        "display": "standalone",
        "background_color": BG,
        "theme_color": THEME,
        "icons": [
            {"src": f"icon-{size}.png", "sizes": f"{size}x{size}", "type": "image/png"}
            for size in (192, 256, 512)
        ],
    }
    (out / "site.webmanifest").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")

    generated = ["mark.svg", *(f"icon-{size}.png" for size in ICON_SIZES), "favicon.ico",
                 "apple-touch-icon.png", "icon-mark-transparent.png", "logo-full-400.png",
                 "logo-full-800.png", "logo-full-light-800.png", "logo-full-transparent.png",
                 "site.webmanifest"]
    for name in generated:
        print(f"  {name}")

    if args.publish:
        # the site keeps its own, shorter names
        site = {
            "favicon.ico": "favicon.ico",
            "icon-192.png": "logo-mark.png",
            "logo-full-800.png": "logo-full.png",
            "logo-full-light-800.png": "logo-full-light.png",
            "apple-touch-icon.png": "apple-touch-icon.png",
            "mark.svg": "mark.svg",
        }
        args.publish.mkdir(parents=True, exist_ok=True)
        for name, target in site.items():
            shutil.copyfile(out / name, args.publish / target)
        print(f"published {len(site)} files to {args.publish}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
