"""Stage 2: raw-res layers -> 192x320 ramp-indexed style-b layers + manifest.json.
Every layer shares one transform, one Bayer grid and one set of colour ramps, so they stack."""
import cv2, json, numpy as np
from pathlib import Path
from PIL import Image, ImageEnhance
from scipy import ndimage
D = Path('/workspace/cc/paperdoll'); S = D/'stage1'; O = D/'out'; O.mkdir(exist_ok=True)
CW, CH, FOOT = 192, 320, 4
s = (CH - FOOT - 10) / (1227 - 44)
SW, SH = round(720 * s), round(1280 * s)
OX, OY = round(96 - 358.5 * s), round(CH - FOOT - 1227 * s)
BAYER = np.array([[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]]) / 16.0 - 0.5
BT = np.tile(BAYER, (CH // 4, CW // 4))

def hx(c): c = c.lstrip('#'); return [int(ch * 2, 16) for ch in c] if len(c) == 3 else [int(c[i:i+2], 16) for i in (0, 2, 4)]
RAMPS = {  # default ramps (12-bit OCS-friendly colours, dark -> light)
 'ink':     {'swap': False, 'colors': ['#100']},
 'skin':    {'swap': True,  'colors': ['#521', '#843', '#b76', '#da9', '#fdb']},
 'hair':    {'swap': True,  'colors': ['#211', '#321', '#532', '#743', '#965']},
 'cloth':   {'swap': True,  'colors': ['#301', '#602', '#923', '#c44', '#e76']},
 'metal':   {'swap': True,  'colors': ['#223', '#445', '#778', '#aab', '#dde']},
 'leather': {'swap': False, 'colors': ['#210', '#421', '#632', '#853', '#a75']},
 'linen':   {'swap': False, 'colors': ['#987', '#cb9', '#edc']},
 'brass':   {'swap': False, 'colors': ['#863', '#db6']},
 'magic':   {'swap': False, 'colors': ['#4cf']},
}
VARIANTS = {
 'skin':  {'fair': RAMPS['skin']['colors'], 'pale': ['#634', '#966', '#c99', '#ebb', '#fed'], 'tan': ['#410', '#731', '#a53', '#c86', '#ea9'],
           'brown': ['#200', '#421', '#743', '#965', '#b87'], 'deep': ['#100', '#310', '#521', '#743', '#965'], 'orc': ['#230', '#350', '#572', '#8a4', '#bc7']},
 'hair':  {'brown': RAMPS['hair']['colors'], 'black': ['#000', '#112', '#223', '#334', '#556'], 'blond': ['#541', '#862', '#b94', '#dc6', '#fe9'],
           'red': ['#300', '#510', '#831', '#b52', '#d84'], 'grey': ['#333', '#555', '#888', '#aaa', '#ddd'], 'blue': ['#012', '#124', '#236', '#358', '#57b']},
 'cloth': {'red': RAMPS['cloth']['colors'], 'blue': ['#012', '#124', '#247', '#47b', '#7ae'], 'green': ['#020', '#141', '#363', '#585', '#8b7'],
           'purple': ['#203', '#415', '#637', '#859', '#b8c'], 'black': ['#111', '#222', '#333', '#445', '#667'], 'gold': ['#320', '#650', '#982', '#cb3', '#ee6']},
 'metal': {'steel': RAMPS['metal']['colors'], 'iron': ['#111', '#223', '#445', '#667', '#99a'], 'gold': ['#310', '#640', '#a72', '#db4', '#fe8'],
           'bronze': ['#310', '#521', '#843', '#b75', '#da9'], 'mithril': ['#346', '#68a', '#9bd', '#cdf', '#fff']},
}
assert sum(len(r['colors']) for r in RAMPS.values()) == 32

def to_canvas(rgba):
    a = rgba.astype(np.float32); al = a[..., 3:] / 255.0
    pm = np.dstack([a[..., :3] * al, a[..., 3:]])
    sm = cv2.resize(pm, (SW, SH), interpolation=cv2.INTER_AREA)
    c = np.zeros((CH, CW, 4), np.float32)
    x0, y0 = max(0, OX), max(0, OY); x1, y1 = min(CW, OX + SW), min(CH, OY + SH)
    c[y0:y1, x0:x1] = sm[y0 - OY:y1 - OY, x0 - OX:x1 - OX]
    al = c[..., 3:] / 255.0
    rgb = np.where(al > 0, c[..., :3] / np.maximum(al, 1e-4), 0)
    return np.clip(rgb, 0, 255), c[..., 3]
def mask_to_canvas(m):
    rgb, al = to_canvas(np.dstack([np.zeros(m.shape + (3,), np.uint8), (m * 255).astype(np.uint8)])); return al >= 128

MINC = {'base': 400, 'hair': 25, 'armour': 80, 'main': 30, 'off': 200}
def opaque(al, minc=4):
    op = al >= 128
    op = ndimage.binary_opening(op, iterations=1) | (al >= 220)
    l, n = ndimage.label(op)
    if n:
        sz = ndimage.sum(np.ones_like(l), l, range(1, n + 1)); op = np.isin(l, np.where(sz >= minc)[0] + 1)
    return op

def classify(rgb, op, allowed, extra=None):
    """Assign each opaque pixel to a ramp by colour, limited to the ramps a layer may use."""
    u8 = np.clip(rgb, 0, 255).astype(np.uint8)
    L, A, B = [ch.astype(float) for ch in cv2.split(cv2.cvtColor(u8, cv2.COLOR_RGB2LAB))]
    a, b = A - 128, B - 128; chroma = np.hypot(a, b)
    r = np.full(op.shape, 'leather', object)
    def put(name, cond):
        if name in allowed: r[cond & (r == FALL)] = name
    FALL = 'leather'
    r[:] = FALL
    put('magic', (b < -12) & (L > 120))
    put('cloth', (a > 18) & (a > b + 4) & (chroma > 26))      # red cloth
    put('cloth', (b < -6))                                     # blue cloth (plate trim, shield stripe)
    put('brass', (b > 26) & (a < 14) & (L > 95))
    put('skin', (a > 13) & (b > 17) & (L > 70))
    put('linen', (chroma < 32) & (L > 125) & (b > 4))
    put('metal', (chroma < 14))
    put('hair', np.ones_like(op))
    if extra is not None: extra(r, L, a, b)
    return r, L

def build(name, rgba, allowed, extra=None, keep_mask=None, minc=4):
    rgb, al = to_canvas(rgba)
    rgb = np.array(ImageEnhance.Contrast(ImageEnhance.Color(Image.fromarray(rgb.astype(np.uint8))).enhance(1.15)).enhance(1.06)).astype(float)
    op = opaque(al, minc)
    if keep_mask is not None: op &= keep_mask
    ramp, L = classify(rgb, op, allowed, extra)
    return dict(name=name, rgb=rgb, op=op, ramp=ramp, L=L)

def layer_raw(n): return np.array(Image.open(S/f'{n}.png').convert('RGBA'))
H, W = 1280, 720
Yc = np.arange(CH)[:, None]; Xc = np.arange(CW)[None, :]

hem_y = OY + round(725 * s)   # tunic hem in canvas coords
def base_extra(r, L, a, b):
    legs = (Yc > hem_y) & (Xc > 55) & (Xc < 137)
    r[legs & (r != 'linen')] = 'leather'
def fist_only_skin(fx0, fx1, fy0, fy1):
    def f(r, L, a, b):
        outside = ~((Xc >= fx0) & (Xc <= fx1) & (Yc >= fy0) & (Yc <= fy1))
        r[(r == 'skin') & outside] = 'leather'
    return f
def hair_extra(r, L, a, b):
    r[(r != 'skin')] = 'hair'

layers = []
layers.append(('base', 'base', build('base', layer_raw('base'), {'skin', 'linen', 'leather', 'metal'}, base_extra, minc=400)))
for n, label in [('hair_long', 'Long'), ('hair_crop', 'Crop'), ('hair_braid', 'Braid')]:
    layers.append(('hair', n, build(n, layer_raw(n), {'hair', 'skin'}, hair_extra, minc=25)))
bskin_c = mask_to_canvas(np.load(S/'base_skin.npy'))
def on_base_skin(r, L, a, b):
    bad = (r == 'skin') & ~bskin_c
    lin = bad & (L > 120) & (np.hypot(a, b) < 45) & (r is not None)
    r[bad] = 'leather'
    if _cur_allowed[0] and 'linen' in _cur_allowed[0]: r[lin] = 'linen'
_cur_allowed = [None]
for n, allowed in [('armour_leather', {'leather', 'brass', 'linen', 'skin'}),
                   ('armour_chain', {'metal', 'cloth', 'leather', 'brass', 'skin'}),
                   ('armour_plate', {'metal', 'cloth', 'leather', 'brass'})]:
    _cur_allowed[0] = allowed
    layers.append(('armour', n, build(n, layer_raw(n), allowed, on_base_skin, minc=80)))
cut_r = mask_to_canvas(np.load(S/'cut_right.npy'))
fy = np.where(cut_r.any(1))[0]; fx = np.where(cut_r.any(0))[0]
_box = np.zeros_like(cut_r); _box[fy.min() + 2:fy.max() + 4, fx.min() - 4:fx.max() + 3] = True
cut_r = (ndimage.binary_dilation(cut_r, iterations=3) & _box) | cut_r
fist_box = (fx.min() - 3, fx.max() + 3, fy.min() - 4, fy.max() + 6)
_fs = fist_only_skin(*fist_box)
def sword_extra(r, L, a, b):
    _fs(r, L, a, b)
    blade = (Yc > fist_box[3] + 6) & (r == 'leather')
    r[blade] = 'metal'
layers.append(('main', 'w_sword', build('w_sword', layer_raw('w_sword'), {'metal', 'brass', 'leather', 'skin'}, sword_extra)))
layers.append(('main', 'w_staff', build('w_staff', layer_raw('w_staff'), {'leather', 'magic', 'metal', 'skin'}, fist_only_skin(*fist_box))))
layers.append(('off', 'w_shield', build('w_shield', layer_raw('w_shield'), {'metal', 'cloth', 'leather'}, minc=200)))

# per-ramp luminance thresholds from the pooled pixels of every layer -> same skin tone in base and fist
pool = {}
for _, _, Ly in layers:
    for rn in RAMPS:
        sel = Ly['op'] & (Ly['ramp'] == rn)
        if sel.any(): pool.setdefault(rn, []).append(Ly['L'][sel])
TH = {}
for rn, parts in pool.items():
    v = np.concatenate(parts); k = len(RAMPS[rn]['colors'])
    qs = np.percentile(v, np.linspace(0, 100, k + 1)[1:-1]) if k > 1 else np.array([])
    step = float(np.median(np.diff(np.percentile(v, [5] + list(np.linspace(0, 100, k + 1)[1:-1]) + [95])))) if k > 1 else 0
    TH[rn] = (qs, step)

def render(Ly):
    h, w = Ly['op'].shape
    out = np.zeros((h, w, 4), np.uint8); lvl = np.zeros((h, w), int)
    for rn, spec in RAMPS.items():
        sel = Ly['op'] & (Ly['ramp'] == rn)
        if not sel.any(): continue
        qs, step = TH.get(rn, (np.array([]), 0))
        v = Ly['L'] + BT * step * 0.9
        li = np.searchsorted(qs, v) if len(qs) else np.zeros_like(v, int)
        cols = np.array([hx(c) for c in spec['colors']])
        out[sel, :3] = cols[li[sel]]; out[sel, 3] = 255
    op = Ly['op']
    edge = op & ~ndimage.binary_erosion(op, structure=[[0, 1, 0], [1, 1, 1], [0, 1, 0]])
    out[edge, :3] = hx(RAMPS['ink']['colors'][0])
    return out

manifest = {'canvas': [CW, CH], 'order': ['back', 'base', 'armour', 'hair', 'off', 'main'],
            'ramps': {k: {'swap': v['swap'], 'default': v['colors']} for k, v in RAMPS.items()},
            'variants': VARIANTS, 'slots': {}, 'note': 'Every layer is 192x320 in the base body coordinate space; never trim. Pixels use only the default ramp colours, so a recolour is an exact RGB -> (ramp, level) lookup.'}
labels = {'base': 'Human (male)', 'hair_long': 'Shoulder-length', 'hair_crop': 'Soldier crop', 'hair_braid': 'Warrior braid',
          'armour_leather': 'Boiled leather', 'armour_chain': 'Mail and tabard', 'armour_plate': 'Full plate',
          'w_sword': 'Longsword', 'w_staff': 'Crystal staff', 'w_shield': 'Round shield'}
Image.fromarray(np.where(cut_r[..., None], np.array([0, 0, 0, 255], np.uint8), np.zeros(4, np.uint8))).save(O/'cut_hand_r.png')
for slot, n, Ly in layers:
    img = render(Ly); fn = n.replace('w_', 'main_' if slot == 'main' else 'off_') + '.png'
    Image.fromarray(img, 'RGBA').save(O/fn, optimize=True)
    used = {rn: int(((Ly['ramp'] == rn) & Ly['op']).sum()) for rn in RAMPS if ((Ly['ramp'] == rn) & Ly['op']).any()}
    item = {'id': n.replace('w_', ''), 'label': labels[n], 'file': fn, 'ramps': used}
    if n in ('w_sword', 'w_staff'): item['cuts'] = {'base': 'cut_hand_r.png', 'armour': 'cut_hand_r.png'}
    manifest['slots'].setdefault(slot, []).append(item)
    print(fn, used)
json.dump(manifest, open(O/'manifest.json', 'w'), indent=1)
print('scale', s, 'offset', OX, OY, 'fist', fist_box)
