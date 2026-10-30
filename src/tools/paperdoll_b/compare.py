import json, numpy as np
from PIL import Image, ImageDraw, ImageFont
F = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 16)
def comp(O, sel, bg=(239, 220, 178)):
    M = json.load(open(O + 'manifest.json'))
    items = {s: next(i for i in M['slots'][s] if i['id'] == v) for s, v in sel.items() if v}
    cuts = {}
    for it in items.values():
        for t, f in it.get('cuts', {}).items(): cuts.setdefault(t, []).append(np.array(Image.open(O + f))[..., 3] > 0)
    c = Image.new('RGBA', tuple(M['canvas']), bg + (255,))
    for slot in M['order']:
        if slot == 'base': L = np.array(Image.open(O + 'base.png').convert('RGBA'))
        elif slot in items: L = np.array(Image.open(O + items[slot]['file']).convert('RGBA'))
        else: continue
        for cm in cuts.get(slot, []): L[cm, 3] = 0
        c.alpha_composite(Image.fromarray(L))
    return c
cols = [('32 (PR v1)', 'out/'), ('32', 'out3/32/'), ('64', 'out3/64/'), ('128', 'out3/128/'), ('256', 'out3/256/')]
rows = [{'hair': 'hair_braid', 'armour': 'armour_plate', 'off': 'shield'}, {'hair': 'hair_long', 'armour': 'armour_chain', 'main': 'sword'}]
Z = 2; cw, ch = 192 * Z + 12, 320 * Z + 12
S = Image.new('RGB', (cw * len(cols) + 12, ch * len(rows) + 70), (36, 22, 13)); d = ImageDraw.Draw(S)
d.text((12, 8), 'Palette depth, same sources, shown at 2x nearest-neighbour. "32 (PR v1)" is the first PR; the others use the v2 pipeline (darker plate, soft hairline, staff fix).', font=F, fill=(243, 216, 152))
for j, (lab, O) in enumerate(cols):
    d.text((12 + j * cw + 6, 38), lab + ' colours', font=F, fill=(244, 230, 196))
    for i, sel in enumerate(rows):
        im = comp(O, sel).resize((192 * Z, 320 * Z), Image.NEAREST); S.paste(im, (12 + j * cw, 64 + i * ch))
S.save('compare-colours.png'); print(S.size)
# zoomed head/hand crops at 4x for detail
Z2 = 4; crops = [(40, 0, 152, 90), (0, 100, 112, 190)]
T = Image.new('RGB', (len(cols) * (112 * Z2 + 8), 90 * Z2 + 80 * Z2 + 50), (36, 22, 13)); d = ImageDraw.Draw(T)
for j, (lab, O) in enumerate(cols):
    d.text((j * (112 * Z2 + 8) + 4, 4), lab, font=F, fill=(244, 230, 196))
    a = comp(O, rows[0]).crop(crops[0]); a = a.resize((a.width * Z2, a.height * Z2), Image.NEAREST); T.paste(a, (j * (112 * Z2 + 8), 28))
    b = comp(O, {'hair': 'hair_long', 'armour': 'armour_chain', 'main': 'staff'}).crop(crops[1]); b = b.resize((b.width * Z2, b.height * Z2), Image.NEAREST); T.paste(b, (j * (112 * Z2 + 8), 32 + 90 * Z2))
T.save('compare-colours-detail.png'); print(T.size)
