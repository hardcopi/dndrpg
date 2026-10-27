#!/usr/bin/env python3
"""Focused tests for the opt-in Amiga art profile and keyless previews."""

import os
import tempfile
import unittest

import amiga_art
import gen_npc_art
import gen_region_map


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
    def test_samples_are_written_in_parallel_aga_sizes(self):
        from PIL import Image

        with tempfile.TemporaryDirectory() as output_dir:
            written = amiga_art.write_aga_samples(output_dir)
            self.assertEqual(len(written), 3)
            for path, key in zip(written, ("face", "bust", "map")):
                self.assertTrue(os.path.isfile(path))
                with Image.open(path) as image:
                    self.assertEqual(image.size, amiga_art.AGA_SIZES[key])
                    self.assertLessEqual(len(image.getcolors(256) or []),
                                         amiga_art.AGA_COLORS)


if __name__ == "__main__":
    unittest.main()
