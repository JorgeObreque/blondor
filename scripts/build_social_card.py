# /// script
# requires-python = ">=3.10"
# dependencies = ["Pillow>=10,<13"]
# ///
"""Genera la vista previa de reservas: uv run scripts/build_social_card.py."""

from pathlib import Path

from PIL import Image, ImageChops


ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "agenda/assets/img"
OUTPUT = ASSETS / "social-card-blondor-v1.png"

logo = Image.open(ASSETS / "logo.png").convert("RGB")
background = logo.getpixel((0, 0))

# Quitar únicamente el margen azul del original, conservando el emblema completo.
difference = ImageChops.difference(logo, Image.new("RGB", logo.size, background))
mask = difference.convert("L").point(lambda value: 255 if value > 40 else 0)
bounds = mask.getbbox()
if bounds is None:
    raise ValueError("No se encontró el emblema de Blondor en logo.png")

emblem = logo.crop(bounds)
emblem.thumbnail((510, 510), Image.Resampling.LANCZOS)
card = Image.new("RGB", (1200, 630), background)
card.paste(emblem, ((card.width - emblem.width) // 2, (card.height - emblem.height) // 2))
card.save(OUTPUT, optimize=True)
print(f"Generada {OUTPUT.relative_to(ROOT)} ({card.width} × {card.height})")
