"""Stage 1: key, register, diff -> per-part RGBA at raw res (720x1280) in base coordinates."""
import cv2, numpy as np, sys
from pathlib import Path
from PIL import Image
from scipy import ndimage
R = Path('/workspace/cc/rivermark-front'); src = open(R/'figures_b.py').read().split('\njobs = ')[0]
g = {}; exec(compile(src, 'figures_b.py', 'exec'), g); soft_key = g['soft_key']
D = Path('/workspace/cc/paperdoll'); OUT = D/'stage1'; OUT.mkdir(exist_ok=True)
H, W = 1280, 720
def lab(a): return cv2.cvtColor(np.ascontiguousarray(a), cv2.COLOR_RGB2LAB).astype(np.float32)
def load(n): return np.array(Image.open(D/'raw'/f'{n}.jpg').convert('RGB'))
base = load('base'); bk = soft_key(base); bfg = bk[..., 3] > 127
Image.fromarray(bk).save(OUT/'base.png')
bl = lab(base); bskin = bfg & (bl[..., 1] > 143) & (bl[..., 2] > 148) & (bl[..., 0] > 55)
np.save(OUT/'base_skin.npy', bskin)
NECK, WRIST = 230, 615
REG = {  # (regmask rows ranges, slot region)
 'hair':   [(300, 1240)],
 'armour': [(40, 230)],
 'weapon': [(40, 230), (300, 460), (1100, 1240)],
}
def register(p, rows):
    m = np.zeros((H, W), np.uint8)
    for a, b in rows: m[a:b, 120:600] = 255
    bg = cv2.GaussianBlur(cv2.cvtColor(base, cv2.COLOR_RGB2GRAY).astype(np.float32), (5,5), 0)
    pg = cv2.GaussianBlur(cv2.cvtColor(p, cv2.COLOR_RGB2GRAY).astype(np.float32), (5,5), 0)
    w = np.eye(2, 3, dtype=np.float32)
    _, w = cv2.findTransformECC(bg, pg, w, cv2.MOTION_AFFINE, (3, 300, 1e-6), m, 5)
    return w
def _unused(a): return cv2.cvtColor(a, cv2.COLOR_RGB2LAB).astype(np.float32)
def keep_big(m, n):
    l, k = ndimage.label(m)
    if not k: return m
    s = ndimage.sum(np.ones_like(l), l, range(1, k+1)); return np.isin(l, np.where(s >= n)[0]+1)
jobs = sys.argv[1:] or ['hair_long','hair_crop','hair_braid','armour_leather','armour_chain','armour_plate','weapon_sword','weapon_staff_v1','weapon_shield']
for n in jobs:
    slot = n.split('_')[0]; p = load(n)
    w = register(p, REG[slot])
    k = soft_key(p)
    k = cv2.warpAffine(k, w, (W, H), flags=cv2.INTER_LINEAR | cv2.WARP_INVERSE_MAP, borderValue=(0,0,0,0))
    pfg = k[..., 3] > 127
    Image.fromarray(k).save(OUT/f'{n}_full.png')
    d = np.sqrt(((lab(k[..., :3]) - lab(base)) ** 2).sum(-1))
    d = cv2.GaussianBlur(d, (0, 0), 2.0)
    T = {'hair': 22, 'armour': 20, 'weapon': 24}[slot]
    m = pfg & ((d > T) | ~bfg)
    m = ndimage.binary_opening(m, iterations=2)
    m = ndimage.binary_closing(m, iterations=4) & pfg
    m = ndimage.binary_fill_holes(m) & pfg
    m = keep_big(m, 1500)
    pl = lab(k[..., :3]); pskin = (pl[..., 1] > 143) & (pl[..., 2] > 148) & (pl[..., 0] > 55)
    if slot == 'hair':
        m &= ~pskin  # never carry face/ear skin in a hair layer
        m = ndimage.binary_opening(m, iterations=1); m = keep_big(m, 300)
    if slot == 'armour':
        near = ndimage.binary_dilation(m, iterations=30)
        m |= pfg & near & ~bskin & (np.arange(H)[:, None] > NECK)
        m = ndimage.binary_closing(m, iterations=3) & pfg
        m &= (np.arange(H)[:, None] > NECK - 40)
    if slot == 'weapon':
        m &= ~(pskin & (np.arange(H)[:, None] < WRIST))  # fist only, not the forearm
        m = keep_big(m, 800)
    np.save(OUT/f'{n}_mask.npy', m)
    out = k.copy(); out[..., 3] = np.where(m, k[..., 3], 0)
    Image.fromarray(out).save(OUT/f'{n}.png')
    vis = base.copy(); vis[m] = (0.4*vis[m] + 0.6*np.array([0,255,0])).astype(np.uint8)
    Image.fromarray(np.hstack([vis, out[..., :3]*(out[..., 3:]>0)])).resize((720, 640)).save(OUT/f'{n}_vis.jpg')
    print(n, np.round(w, 3).tolist(), int(m.sum()), flush=True)
