#!/usr/bin/env python3
"""Make the pixel token faces for the Rivermark pixel town map.

Every assets/images/npcs/*_face.png is centre-cropped square, box-reduced to
24x24 and quantised to 32 colours with no dither, into
assets/images/npcs/px/<same name>. ui-map.js asks for these only when the
chart draws Rivermark's painting, and falls back to the portrait when one is
missing. Re-run after adding or replacing a face.
"""
import glob
import os
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
NPCS = os.path.join(HERE, '..', 'assets', 'images', 'npcs')
SIZE = 24
COLOURS = 32


def main():
    out = os.path.join(NPCS, 'px')
    os.makedirs(out, exist_ok=True)
    n = 0
    for f in sorted(glob.glob(os.path.join(NPCS, '*_face.png'))):
        im = Image.open(f).convert('RGB')
        w, h = im.size
        s = min(w, h)
        im = im.crop(((w - s) // 2, (h - s) // 2, (w - s) // 2 + s, (h - s) // 2 + s))
        sm = im.reduce(s // SIZE) if s % SIZE == 0 else im.resize((SIZE, SIZE), Image.BOX)
        q = sm.quantize(colors=COLOURS, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
        q.save(os.path.join(out, os.path.basename(f)), optimize=True)
        n += 1
    print(f'{n} pixel faces in {out}')


if __name__ == '__main__':
    main()
