"""Stage 1b: hand-tuned clean-up for held items (raw res, base coords)."""
import cv2, numpy as np
from pathlib import Path
from PIL import Image
from scipy import ndimage
S = Path('/workspace/cc/paperdoll/stage1'); H, W = 1280, 720
def lab(a): return cv2.cvtColor(np.ascontiguousarray(a), cv2.COLOR_RGB2LAB).astype(np.float32)
def keep_big(m, n=1):
    l, k = ndimage.label(m)
    if not k: return m
    s = ndimage.sum(np.ones_like(l), l, range(1, k+1))
    if n == 1: return l == (np.argmax(s) + 1)
    return np.isin(l, np.where(s >= n)[0]+1)
def skin(rgb):
    L = lab(rgb); return (L[..., 1] > 143) & (L[..., 2] > 148) & (L[..., 0] > 55)
bskin = np.load(S/'base_skin.npy')
Y = np.arange(H)[:, None]; X = np.arange(W)[None, :]

# ---- sword: blade/hilt + the fist that grips it
sw = np.array(Image.open(S/'weapon_sword_full.png')); m = np.load(S/'weapon_sword_mask.npy')
sk = skin(sw[..., :3]) & (sw[..., 3] > 127)
fist = sk & (Y > 600) & (X < 260)
fist = keep_big(fist)
fist = ndimage.binary_closing(fist, iterations=3)
fist_d = ndimage.binary_dilation(fist, iterations=3) & (sw[..., 3] > 127)  # include its ink line
blade = m & ~sk
blade = ndimage.binary_opening(blade, iterations=3)          # kills the thin left-over arm stroke
blade = keep_big(blade | fist_d, 1) | fist_d
sword_m = ndimage.binary_closing(blade, iterations=2) & (sw[..., 3] > 127)
out = sw.copy(); out[..., 3] = np.where(sword_m, 255, 0); Image.fromarray(out).save(S/'w_sword.png')
fy0 = int(np.where(fist.any(1))[0].min())
# cut: the base's open right hand (it becomes a fist)
cut_r = bskin & (Y > fy0 - 2) & (Y < 780) & (X < 210)
np.save(S/'cut_right.npy', cut_r)
print('sword fist top', fy0)

# ---- staff: shaft from v1, re-gripped by the sword fist
st = np.array(Image.open(S/'weapon_staff_v1_full.png'))
sm0 = np.load(S/'weapon_staff_v1_mask.npy')
sm = sm0 & ~(skin(st[..., :3]) & (Y > 420) & (Y < 570) & (X > 100))
c = st[..., :3].astype(int); mag = (c[..., 0] > c[..., 1] + 25) & (c[..., 2] > c[..., 1] + 25)   # magenta-tinted glow fringe
sm &= ~mag
sm = ndimage.binary_opening(sm, iterations=2)
rows = np.where(sm.any(1))[0]
cx = np.array([np.median(np.where(sm[y])[0]) if sm[y].any() else np.nan for y in range(H)])
core = np.nanmedian(cx[600:1150])
band = np.abs(X - core) < 42
top = rows.min(); crystal_end = top + 130
sm &= band | (Y < crystal_end)
sm = keep_big(sm, 1500)
# fill the hole the old hand left: copy the nearest intact shaft row downwards
filled = st.copy(); fm = sm.copy()
widths = sm.sum(1)
gap = [y for y in range(400, 640) if widths[y] < 8]
if gap:
    g0, g1 = min(gap), max(gap)
    src = g1 + 6
    for y in range(g0 - 2, g1 + 4):
        filled[y] = st[src + (y % 24)]; fm[y] = sm[src + (y % 24)]
fm = keep_big(fm)
dx = int(round(np.nanmedian(np.where(fist)[1]) - core))
filled = np.roll(filled, dx, 1); fm = np.roll(fm, dx, 1)
staff = filled.copy(); staff[..., 3] = np.where(fm, 255, 0)
staff[fist_d] = sw[fist_d]; staff[fist_d, 3] = 255
Image.fromarray(staff).save(S/'w_staff.png')
print('staff shift', dx, 'gap', (gap[0], gap[-1]) if gap else None)

# ---- shield: ellipse fit, drop the forearm poking out
sh = np.array(Image.open(S/'weapon_shield_full.png')); m = np.load(S/'weapon_shield_mask.npy')
mm = ndimage.binary_closing(m, iterations=10); mm = ndimage.binary_fill_holes(mm)
mm = ndimage.binary_opening(mm, structure=np.ones((3,3)), iterations=22); mm = keep_big(mm)
cs, _ = cv2.findContours(mm.astype(np.uint8), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
el = cv2.fitEllipse(max(cs, key=cv2.contourArea))
em = np.zeros((H, W), np.uint8); cv2.ellipse(em, el, 1, -1)
shm = (em > 0) & (sh[..., 3] > 127)
out = sh.copy(); out[..., 3] = np.where(shm, 255, 0); Image.fromarray(out).save(S/'w_shield.png')
print('shield ellipse', el)
def onbg(im, box):
    im = Image.fromarray(im).crop(box); bg = Image.new('RGBA', im.size, (40, 90, 40, 255)); bg.alpha_composite(im); return bg
s = Image.new('RGB', (700, 1240))
s.paste(onbg(np.array(Image.open(S/'w_sword.png')), (40, 420, 290, 1240)), (0, 0))
s.paste(onbg(staff, (40, 20, 290, 1240)), (240, 0))
s.paste(onbg(out, (370, 400, 690, 820)), (480, 0)); s.save(S/'wcrop2.png')
