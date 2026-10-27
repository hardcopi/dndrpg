#!/usr/bin/env python3
"""The Amiga spike paints its own samples, and does not touch production art."""

import os
import tempfile
import unittest
from unittest import mock

import amiga_art
import gen_npc_art
import gen_region_map

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PRODUCTION = (
    "assets/images/npcs/aldric_face.png",
    "assets/images/npcs/aldric_bust.png",
    "assets/images/maps/rivermark.png",
)


def _pixels(image):
    data = getattr(image, "get_flattened_data", None)
    return list(data() if data else image.getdata())


class AmigaPromptTests(unittest.TestCase):
    def test_npc_profile_is_opt_in(self):
        wearer = {
            "name": "Brother Aldric",
            "description": "A patient priest in a worn wool robe.",
            "race": "Human",
            "class": "Cleric",
            "role": "Priest",
        }
        default = gen_npc_art.build_prompt("aldric", [wearer])
        amiga = gen_npc_art.build_prompt("aldric", [wearer], amiga=True)

        self.assertIn("stylised painterly digital character portrait", default)
        self.assertNotIn("Amiga-era", default)
        self.assertIn("Amiga-era pixel-art portrait", amiga)
        self.assertIn("#378EF0", amiga)

    def test_region_profile_is_opt_in(self):
        region = {
            "region_key": "rivermark",
            "name": "Rivermark",
            "region_type": "town",
            "description": "A settlement beside the river.",
            "locations": {},
        }
        default = gen_region_map.prompt_for(region)
        amiga = gen_region_map.prompt_for(region, amiga=True)

        self.assertIn("hand-inked cartography", default)
        self.assertNotIn("Amiga-era", default)
        self.assertIn("Amiga-era bitmap cartography", amiga)
        self.assertIn("#378EF0", amiga)


class AGASampleTests(unittest.TestCase):
    def test_samples_are_painted_at_aga_sizes(self):
        from PIL import Image

        with tempfile.TemporaryDirectory() as output_dir:
            written = amiga_art.write_aga_samples(output_dir)
            self.assertEqual(len(written), 3)
            for path, key in zip(written, ("face", "bust", "map")):
                self.assertTrue(os.path.isfile(path))
                self.assertTrue(path.startswith(output_dir))
                with Image.open(path) as image:
                    self.assertEqual(image.size, amiga_art.AGA_SIZES[key])
                    self.assertLessEqual(len(image.getcolors(256) or []),
                                         amiga_art.AGA_COLORS)

    def test_painter_is_deterministic_and_within_contract(self):
        face_a = amiga_art.render_aldric_face()
        face_b = amiga_art.render_aldric_face()
        bust = amiga_art.render_aldric_bust()
        self.assertEqual(_pixels(face_a), _pixels(face_b))
        self.assertEqual(face_a.size, (64, 64))
        self.assertEqual(bust.size, (160, 212))
        self.assertLessEqual(len(set(_pixels(bust))), 32)
        with self.assertRaises(ValueError):
            amiga_art.render_aldric_face((145, 145))
        with self.assertRaises(ValueError):
            amiga_art.render_aldric_bust((400, 512))

    def test_bust_reads_as_a_figure_on_a_charcoal_ground(self):
        image = amiga_art.render_aldric_bust()
        counts = {}
        for colour in _pixels(image):
            counts[colour] = counts.get(colour, 0) + 1
        area = image.size[0] * image.size[1]
        skin = sum(counts.get(c, 0) for c in amiga_art.SKIN)
        ground = sum(counts.get(c, 0) for c in amiga_art.GROUND)
        cobalt = sum(counts.get(c, 0) for c in amiga_art.COBALT)
        self.assertGreater(skin / area, 0.08)
        self.assertGreater(ground / area, 0.15)
        # The stone on the sun-disc is the accent. A field of it is a mistake.
        self.assertGreater(cobalt, 8)
        self.assertLess(cobalt, 200)
        self.assertIn(image.getpixel((0, 0)), amiga_art.GROUND)
        self.assertIn(image.getpixel((image.size[0] - 1, 0)), amiga_art.GROUND)
        self.assertGreater(counts.get(amiga_art.EYE[0], 0), 20)
        self.assertGreater(counts.get(amiga_art.EYE[1], 0), 0)

    def test_face_is_the_same_head(self):
        image = amiga_art.render_aldric_face()
        counts = {}
        for colour in _pixels(image):
            counts[colour] = counts.get(colour, 0) + 1
        area = image.size[0] * image.size[1]
        skin = sum(counts.get(c, 0) for c in amiga_art.SKIN)
        self.assertGreater(skin / area, 0.15)
        # Both eyes survive the tighter framing: sclera, and a catchlight
        # on each half. One catchlight means one eye lost it to the sampling grid.
        self.assertGreater(counts.get(amiga_art.EYE[0], 0), 8)
        width, height = image.size
        catch = amiga_art.EYE[1]
        left = right = 0
        for y in range(height):
            for x in range(width):
                if image.getpixel((x, y)) != catch:
                    continue
                if x < width // 2:
                    left += 1
                else:
                    right += 1
        self.assertGreater(left, 0)
        self.assertGreater(right, 0)
        self.assertEqual(sum(counts.get(c, 0) for c in amiga_art.COBALT), 0)

    def test_map_is_warm_parchment_with_ink_and_no_marker(self):
        image = amiga_art.render_rivermark_map()
        pixels = _pixels(image)
        count = len(pixels)
        mean_r = sum(p[0] for p in pixels) / count
        mean_g = sum(p[1] for p in pixels) / count
        mean_b = sum(p[2] for p in pixels) / count
        colours = set(pixels)
        self.assertEqual(image.size, (320, 240))
        self.assertLessEqual(len(colours), 32)
        self.assertGreater(mean_r, 170)
        self.assertGreater(mean_r, mean_g)
        self.assertGreater(mean_g, mean_b + 15)
        self.assertIn(amiga_art.INK, colours)
        self.assertNotIn(amiga_art.COBALT[1], colours)
        self.assertGreater(len(amiga_art._rivermark_segments()), 5)

    def test_samples_are_not_a_downsample_of_production_art(self):
        from PIL import Image

        painted = amiga_art.render_aldric_bust()
        source = os.path.join(ROOT, "assets", "images", "npcs", "aldric_bust.png")
        with Image.open(source) as original:
            reduced = original.convert("RGB").resize(
                painted.size, Image.Resampling.LANCZOS)
        painted_px = _pixels(painted)
        reduced_px = _pixels(reduced)
        total = 0
        for a, b in zip(painted_px, reduced_px):
            total += abs(a[0] - b[0]) + abs(a[1] - b[1]) + abs(a[2] - b[2])
        mae = total / (len(painted_px) * 3)
        # A palette-limited resize of the shipped bust sits close to that
        # bust. This drawing does not.
        self.assertGreater(mae, 40)

    def test_samples_do_not_open_production_pngs(self):
        real_open = open

        def guard(file, *args, **kwargs):
            name = file if isinstance(file, (str, os.PathLike)) else ""
            text = str(name).replace("\\", "/")
            if text.endswith(".png") and "/assets/images/" in text \
                    and "/amiga-spike/" not in text:
                raise AssertionError(f"production art opened: {text}")
            return real_open(file, *args, **kwargs)

        with tempfile.TemporaryDirectory() as output_dir:
            with mock.patch("builtins.open", guard):
                written = amiga_art.write_aga_samples(output_dir)
            self.assertEqual(len(written), 3)

    def test_writing_samples_leaves_production_bytes_alone(self):
        paths = [os.path.join(ROOT, rel) for rel in PRODUCTION]

        def read_all():
            blobs = []
            for path in paths:
                with open(path, "rb") as fh:
                    blobs.append(fh.read())
            return blobs

        before = read_all()
        with tempfile.TemporaryDirectory() as output_dir:
            amiga_art.write_aga_samples(output_dir)
        self.assertEqual(before, read_all())

    def test_checked_in_samples_match_the_painter(self):
        from PIL import Image

        with tempfile.TemporaryDirectory() as output_dir:
            written = amiga_art.write_aga_samples(output_dir)
            for path in written:
                shipped = os.path.join(
                    amiga_art.SPIKE_ART, os.path.relpath(path, output_dir))
                self.assertTrue(os.path.isfile(shipped), shipped)
                with Image.open(path) as fresh, Image.open(shipped) as stored:
                    self.assertEqual(fresh.size, stored.size)
                    self.assertEqual(_pixels(fresh), _pixels(stored), shipped)


if __name__ == "__main__":
    unittest.main()
