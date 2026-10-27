#!/usr/bin/env python3
"""Amiga-era art prompt profile and a keyless AGA preview renderer."""

from __future__ import annotations

import argparse
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SPIKE_ART = os.path.join(ROOT, "assets", "images", "amiga-spike")

AMIGA_PORTRAIT_STYLE = (
    "Amiga-era pixel-art portrait, hand-painted bitmap shading with crisp pixel "
    "clusters, compact 32-colour palette, charcoal shadow and restrained cobalt "
    "blue #378EF0 accents, readable face at small size, no photographic texture"
)

AMIGA_MAP_STYLE = (
    "Amiga-era bitmap cartography on warm parchment, fine dark-ink plan lines, "
    "crisp pixel clusters, compact 32-colour palette with restrained cobalt "
    "blue #378EF0 accents, generous blank paper, quiet map texture, no lettering"
)

AGA_SIZES = {
    "face": (64, 64),
    "bust": (160, 212),
    "map": (320, 240),
}
AGA_COLORS = 32


def write_aga_samples(output_dir: str = SPIKE_ART) -> list[str]:
    """Write AGA-sized samples from existing art without contacting a model."""
    try:
        from PIL import Image
    except ImportError as exc:
        raise RuntimeError("Pillow is required: pip install --user Pillow") from exc

    sources = {
        "npcs/aldric_face.png": ("npcs/aldric_face.png", "face"),
        "npcs/aldric_bust.png": ("npcs/aldric_bust.png", "bust"),
        "maps/rivermark.png": ("maps/rivermark.png", "map"),
    }
    results = []
    for relative, (source, size_key) in sources.items():
        src = os.path.join(ROOT, "assets", "images", source)
        if not os.path.isfile(src):
            raise FileNotFoundError(f"sample source not found: {src}")
        with Image.open(src) as original:
            if size_key == "face":
                background = Image.new("RGBA", original.size, "#171A20")
                background.alpha_composite(original.convert("RGBA"))
                image = background.convert("RGB")
            else:
                image = original.convert("RGB")
            image = image.resize(AGA_SIZES[size_key], Image.Resampling.LANCZOS)
            image = image.quantize(
                colors=AGA_COLORS,
                method=Image.Quantize.MEDIANCUT,
                dither=Image.Dither.NONE,
            )
            dest = os.path.join(output_dir, relative)
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            image.save(dest, format="PNG", optimize=True)
            results.append(dest)
    return results


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--samples",
        action="store_true",
        help="write face, bust, and parchment-map AGA previews without the image service",
    )
    args = parser.parse_args()
    if not args.samples:
        parser.error("pass --samples")
    try:
        for path in write_aga_samples():
            print(os.path.relpath(path, ROOT))
    except (FileNotFoundError, RuntimeError) as exc:
        parser.error(str(exc))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
