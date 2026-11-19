"""Puts the brand on screenshots and writes them for the website.

Each image gets a dark footer strip with the product name and the company
name, so the source is visible wherever the image ends up (for example in
Google Images), plus copyright details inside the file. The strip is added
below the picture, so it never covers anything in the screenshot.

Needs Pillow (pip install pillow) and the Inter font file.

    python tools/brand_images.py SOURCE_FOLDER OUTPUT_FOLDER

SOURCE_FOLDER holds the plain screenshots (png, jpg or webp). Never point
OUTPUT_FOLDER at the same folder: branding an image twice doubles the strip.
Keep your plain originals somewhere that is not uploaded to the website.
"""

from __future__ import annotations

import sys
from datetime import date
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

PRODUCT = "DiagnoSolution"
COMPANY = "Softorio"
SITE = "softorio.com"
OWNER_LINE = f"© {date.today().year} {COMPANY}. All rights reserved."
STRIP = (20, 64, 58)  # the software's dark green, #14403A
FONT_BOLD = "/usr/share/fonts/opentype/inter/Inter-Bold.otf"
FONT_REGULAR = "/usr/share/fonts/opentype/inter/Inter-Regular.otf"
PREFIX = ""  # keep the original file names


def font(path: str, size: int) -> ImageFont.FreeTypeFont:
    try:
        return ImageFont.truetype(path, size)
    except OSError:
        return ImageFont.load_default()


def brand(image: Image.Image) -> Image.Image:
    image = image.convert("RGB")
    width, height = image.size
    strip_h = max(34, round(width * 0.052))
    size = max(15, round(strip_h * 0.52))
    out = Image.new("RGB", (width, height + strip_h), STRIP)
    out.paste(image, (0, 0))

    draw = ImageDraw.Draw(out)
    bold, light = font(FONT_BOLD, size), font(FONT_REGULAR, round(size * 0.82))
    pad = round(strip_h * 0.45)
    y = height + strip_h // 2

    draw.text((pad, y), PRODUCT, font=bold, fill=(255, 255, 255), anchor="lm")
    left = pad + draw.textlength(PRODUCT, font=bold) + round(size * 0.5)
    draw.text((left, y), f"by {COMPANY}", font=light, fill=(185, 211, 203), anchor="lm")

    right = f"{SITE}"
    if draw.textlength(right, font=light) + left + draw.textlength(f"by {COMPANY}", font=light) + pad * 2 < width:
        draw.text((width - pad, y), right, font=light, fill=(185, 211, 203), anchor="rm")
    return out


def exif(title: str) -> bytes:
    data = Image.Exif()
    data[0x010E] = f"{PRODUCT}: {title}"  # ImageDescription
    data[0x013B] = COMPANY  # Artist
    data[0x8298] = OWNER_LINE  # Copyright
    data[0x0131] = PRODUCT  # Software
    return data.tobytes()


def main(source: Path, output: Path) -> int:
    if source.resolve() == output.resolve():
        print("The output folder must be different from the source folder.")
        return 2
    count = 0
    for path in sorted(source.rglob("*")):
        if path.suffix.lower() not in (".png", ".jpg", ".jpeg", ".webp"):
            continue
        target = output / path.relative_to(source).parent / (PREFIX + path.stem + ".webp")
        target.parent.mkdir(parents=True, exist_ok=True)
        with Image.open(path) as image:
            branded = brand(image)
        title = path.stem.replace("-", " ").replace("_", " ")
        branded.save(target, "WEBP", quality=82, method=6, exif=exif(title))
        count += 1
        print(f"{path.relative_to(source)}  ->  {target.relative_to(output)}")
    print(f"{count} images written.")
    return 0


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print(__doc__)
        sys.exit(2)
    sys.exit(main(Path(sys.argv[1]), Path(sys.argv[2])))
