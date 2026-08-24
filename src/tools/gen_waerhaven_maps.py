#!/usr/bin/env python3
"""Region plates for Waerhaven, cut out of the city's own street plan.

Every other module's region maps come off `gen_region_map.py`, which asks an
image model for a painting. Waerhaven does not need one: `World/` already holds
a hand-built plan of the city at 2400x1800 metres, with every keyed place, every
street and every ward on it at a real coordinate. Asking a diffusion model to
invent a harbour when the harbour is already drawn — and drawn to the same
coordinates the locations are authored against — would be worse art AND wrong.

So this crops the plan instead, one 4:3 window per ward, and writes each
location's `map_pos` back from where the place actually stands. A node at
[45, 26] then sits on the building it names rather than near it.

Two layers come off first. `<g id="poi">` is the numbered key discs and
`<g id="district-names">` is the big ward captions; the chart draws its own
names over the plate, and two sets of labels fighting is what the note in
`gen_region_map.py` means by "keep them quiet". Street names stay — they are
small, italic, and the only thing on the plate that tells you which way is out.

    python3 tools/gen_waerhaven_maps.py            # plates + map_pos
    python3 tools/gen_waerhaven_maps.py --check    # say what it would do

Needs inkscape for the rasterising and Pillow for the Underquay's darkening.
"""
import argparse, json, os, re, shutil, subprocess, sys, tempfile

ROOT  = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WORLD = os.path.join(os.path.dirname(ROOT), "World")
MAPS  = os.path.join(ROOT, "assets", "images", "maps")
LOCS  = os.path.join(ROOT, "content", "locations")

# The plate is drawn stretched across the 0-100 by 0-75 field the chart uses,
# so a window has to be 4:3 or every building on it is subtly the wrong shape.
PLATE_W, PLATE_H = 1536, 1152
ASPECT = PLATE_W / PLATE_H

# How much air to leave round the places a ward actually contains. A ward is
# usually three buildings in a hundred metres; without a floor it would crop to
# a doorway.
PAD      = 0.38     # of the ward's own span, each side
MIN_SPAN = 330.0    # metres across, so a tight ward still shows its streets

# Nodes are drawn as discs with names under them. Keeping them off the very
# edge is what stops a name being clipped by the chart's own viewBox.
X_MIN, X_MAX = 6.0, 94.0
Y_MIN, Y_MAX = 6.0, 69.0


# Bold captions the plan draws for its gates. They are set at the same weight
# and size a chart node's name is drawn at, so on a plate they read as a node
# that cannot be clicked. The italic street, water and woodland names stay:
# nothing in the chart duplicates them and they are the only thing on a cropped
# window that says which way is out.
GATE_CAPTIONS = ("Hallowgate", "Tarrow Gate", "The Slip Gate")


def strip_layers(svg: str) -> str:
    """Remove <g id="..."> ... </g> whole, by counting depth from the tag."""
    for cap in GATE_CAPTIONS:
        svg = re.sub(r'<text\b[^>]*>%s</text>' % re.escape(cap), '', svg)
    for gid in ("poi", "district-names"):
        m = re.search(r'<g id="%s">' % gid, svg)
        if not m:
            continue
        i, depth = m.end(), 1
        while depth:
            nxt = re.search(r'<g\b|</g>', svg[i:])
            if not nxt:
                raise SystemExit("unbalanced <g> while stripping %s" % gid)
            j = i + nxt.start()
            if svg[j:j + 3] == '</g':
                depth -= 1; i = j + 4
            else:
                depth += 1; i = j + 2
        svg = svg[:m.start()] + svg[i:]
    return svg


def window(points, plan_w, plan_h):
    """A 4:3 window in plan metres holding every point, with air round it."""
    xs = [p[0] for p in points]; ys = [p[1] for p in points]
    cx, cy = (min(xs) + max(xs)) / 2, (min(ys) + max(ys)) / 2
    w = max(max(xs) - min(xs), 0.0) * (1 + 2 * PAD)
    h = max(max(ys) - min(ys), 0.0) * (1 + 2 * PAD)
    if w / max(h, 1e-6) < ASPECT: w = h * ASPECT
    else:                         h = w / ASPECT
    if w < MIN_SPAN: w, h = MIN_SPAN, MIN_SPAN / ASPECT
    # Slide back inside the plan rather than shrinking: shrinking would change
    # the aspect and squash the buildings.
    x0 = min(max(cx - w / 2, 0.0), max(plan_w - w, 0.0))
    y0 = min(max(cy - h / 2, 0.0), max(plan_h - h, 0.0))
    return x0, y0, w, h


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--check", action="store_true", help="report, write nothing")
    args = ap.parse_args()

    if not args.check and not shutil.which("inkscape"):
        raise SystemExit("inkscape not on PATH — it does the rasterising")

    world = json.load(open(os.path.join(WORLD, "waerhaven.json")))
    meta  = world["meta"]
    off   = meta["mapOffset"]                       # plan sits here on the sheet
    plan_w, plan_h = meta["width"], meta["height"]

    byid   = {l["id"]: (float(l["x"]), float(l["y"])) for l in world["locations"]}
    plaza  = {p["id"]: (float(p["x"]), float(p["y"])) for p in world["plazas"]}
    gate   = {g["id"]: (float(g["x"]), float(g["y"])) for g in world["gates"]}
    street = {s["name"]: [tuple(map(float, p)) for p in s["pts"]] for s in world["streets"]}
    def mid(name):
        p = street[name]; return p[len(p) // 2]

    # Where each authored location actually stands on the plan. The keyed
    # places come straight off the gazetteer; the squares, the gate and the two
    # streets are the ones the act added, and they are taken from the plan's own
    # plaza / gate / street geometry rather than guessed.
    P = {
      "waermans_rest": byid["the_waermans_rest"],
      "the_rest_cellar": (byid["the_waermans_rest"][0] + 26, byid["the_waermans_rest"][1] + 34),
      "hallowmarket": plaza["hallowmarket"], "the_hallowgate": gate["hallowgate"],
      "hallowgate_keep": byid["watchkeep"], "league_granary": byid["granary"],
      "the_weigh_house": byid["the_weigh_house"],
      "halvards_ironmongery": byid["halvards_ironmongery"],

      "moot_yard": plaza["mootyard"], "shipwrights_moot": byid["shipmoot"],
      "the_factors_house": byid["factorhouse"], "the_wet_hall": byid["leaguehall"],
      "the_charthouse": byid["charthouse"], "venniks": byid["venniks"],
      "the_sounding_line": byid["the_sounding_line"],

      "boomhead": plaza["boomhead"], "the_counting_boom": byid["counting"],
      "the_chain_winch": byid["the_chain_winch"], "ilbers_chandlery": byid["ilbers_chandlery"],
      "the_drowned_jarl": byid["the_drowned_jarl"], "the_great_crane": byid["greatcrane"],
      "the_quiet_charter": byid["the_quiet_charter"],

      "the_great_slip": plaza["slipyard"], "the_nine_masts": byid["the_nine_masts"],
      "the_mast_pond": byid["mastpond"], "the_ropehouse": byid["ropehouse"],
      "the_sailmakers_loft": byid["the_sailmakers_loft"], "the_dry_dock": byid["drydock"],

      "old_waer_square": plaza["fishsquare"], "the_cold_anchor": byid["the_cold_anchor"],
      "the_fish_hall": byid["fishhall"],
      "temple_of_the_deep_mere": byid["templemere"], "the_waer_light": byid["lighthouse"],

      "the_tar_steps": mid("The Tar Steps"), "the_pitch_yards": byid["pitchyard"],
      "the_boom_forge": byid["boomforge"], "the_oakum_house": byid["the_oakum_house"],
      "the_pitch_and_pot": byid["the_pitch_and_pot"], "the_tar_gaol": byid["gaol"],

      "wardens_road": mid("Wardens' Road"), "the_grey_hall": byid["wardenhall"],
      "chapel_of_the_grey_road": byid["chapel_of_the_grey_road"],
      "the_green_bough": byid["the_green_bough"], "the_lazar_house": byid["lazarhouse"],
    }

    # Art direction per ward, for the two whose subject is water rather than
    # buildings. The Boom IS the harbour and the Slipways ARE the ways into it,
    # and a window fitted to their buildings alone frames the warehouses behind
    # them and crops off the thing the ward is for.
    #
    # These are hand-set windows in plan metres rather than extra points to fit
    # to, and that is deliberate: feeding the piers into the fitter drags the
    # box to 1135 m across, because the piers are tall and a 4:3 window round a
    # tall set is a very wide one, and the ward's own buildings end up small in
    # the middle of a lot of water. Choosing the frame is the same call
    # `gen_region_map.py` makes with BY_KEY, made the same way.
    WINDOW = {
        # west to the pier heads and the quay, so the chain's harbour is in it
        "the_boom":     (780.0, 940.0, 400.0, 300.0),
        # north to the slip heads and open water — the ways run into the Mere
        "the_slipways": (520.0, 1130.0, 480.0, 360.0),
        # Tarrow's places are a narrow column 330 m tall, and a 4:3 window
        # fitted to that is 750 m wide — which is half the city, most of it
        # not Tarrow. Pushed east onto the yards it is actually made of.
        "tarrow":       (1520.0, 790.0, 600.0, 450.0),
    }

    # The Underquay is under the outer quay and has no plan geometry, because
    # the plan is of a city and this is beneath it. It gets the harbour window
    # its rooms actually lie under, darkened, and keeps its authored map_pos —
    # a floor nobody surveyed does not get to pretend it was surveyed.
    UNDER = "the_underquay"
    under_window = (byid["greatcrane"], byid["counting"], byid["the_quiet_charter"])

    svg = strip_layers(open(os.path.join(WORLD, "waerhaven-map.svg")).read())
    tmp = tempfile.NamedTemporaryFile("w", suffix=".svg", delete=False)
    tmp.write(svg); tmp.close()

    regions, moved = [], 0
    for path in sorted(os.listdir(LOCS)):
        if not path.endswith(".json"):
            continue
        doc = json.load(open(os.path.join(LOCS, path)))
        if doc.get("module") != "waerhaven":
            continue
        key = doc["region_key"]
        pts = [P[k] for k in doc["locations"] if k in P]
        if not pts:
            if key != UNDER:
                print("  ..  %-18s no mapped points, skipped" % key)
                continue
            pts = list(under_window)
        x0, y0, w, h = (WINDOW["the_boom"] if key == UNDER
                        else WINDOW.get(key) or window(pts, plan_w, plan_h))
        regions.append((key, x0, y0, w, h, path, doc))

        if not args.check:
            out = os.path.join(MAPS, key + ".png")
            subprocess.run([
                "inkscape", "--export-type=png",
                "--export-area=%.1f:%.1f:%.1f:%.1f" % (
                    off[0] + x0, off[1] + y0, off[0] + x0 + w, off[1] + y0 + h),
                "--export-width=%d" % PLATE_W, "--export-height=%d" % PLATE_H,
                "--export-filename=" + out, tmp.name],
                check=True, capture_output=True)
            if key == UNDER:
                # Under the stone: the same ground, seen through it.
                from PIL import Image, ImageEnhance
                im = Image.open(out).convert("RGB")
                im = ImageEnhance.Color(im).enhance(0.25)
                im = ImageEnhance.Brightness(im).enhance(0.34)
                im = Image.blend(im, Image.new("RGB", im.size, (16, 24, 30)), 0.42)
                im.save(out)

        # map_pos, from where the place stands inside the window
        if key != UNDER:
            for lk, loc in doc["locations"].items():
                if lk not in P:
                    continue
                px, py = P[lk]
                mx = min(max((px - x0) / w * 100.0, X_MIN), X_MAX)
                my = min(max((py - y0) / h * 75.0,  Y_MIN), Y_MAX)
                new = [round(mx, 1), round(my, 1)]
                if loc.get("map_pos") != new:
                    loc["map_pos"] = new; moved += 1
            if not args.check:
                with open(os.path.join(LOCS, path), "w") as f:
                    json.dump(doc, f, indent=4, ensure_ascii=False); f.write("\n")

    os.unlink(tmp.name)
    for key, x0, y0, w, h, _, doc in regions:
        print("  %-18s window %6.0f,%-6.0f %5.0f x %-5.0f m   %2d locations%s"
              % (key, x0, y0, w, h, len(doc["locations"]),
                 "   (darkened)" if key == UNDER else ""))
    print("\n%d plate(s), %d map_pos rewritten%s"
          % (len(regions), moved, "  [--check: nothing written]" if args.check else ""))


if __name__ == "__main__":
    main()
