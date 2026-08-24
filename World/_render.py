"""Render Sundermere as a parchment-style hand-drawn SVG map."""
import numpy as np, json, math, base64, io
from collections import defaultdict
from PIL import Image, ImageFilter

D='/sessions/dazzling-jolly-pasteur/work/'
elev=np.load(D+'elev.npy'); land=np.load(D+'land.npy'); acc=np.load(D+'acc.npy')
biome=np.load(D+'biome.npy'); nation=np.load(D+'nation.npy'); rel=np.load(D+'rel.npy')
dist=np.load(D+'dist.npy'); lake=np.load(D+'lake.npy'); isea=np.load(D+'inlandsea.npy')
W_=json.load(open(D+'world.json'))
H,W = elev.shape
CW,CH = W_['meta']['width'], W_['meta']['height']
SX,SY = CW/W, CH/H
rng=np.random.default_rng(808)
PAD = 62                                  # decorative frame inset

INK   = "#4a3520"; INK2="#6b5236"; INK3="#8a7css"
PAPER = "#efe2c4"
SEACOL= "#9fb4bc"

# ---------------------------------------------------------------- washes
BCOL = {0:(203,212,207),1:(214,206,190),2:(200,183,148),3:(150,168,148),
        4:(126,148,110),5:(166,178,134),6:(219,199,148),7:(203,196,152),8:(163,190,198)}
img = Image.new('RGB',(W,H))
px = img.load()
REALMHUE = {r['id']:r['colour'] for r in W_['realms']}
order = [r['id'] for r in W_['realms']]
def hex2rgb(h): return tuple(int(h[i:i+2],16) for i in (1,3,5))
for y in range(H):
    for x in range(W):
        b=int(biome[y,x]); c=list(BCOL[b if land[y,x] else 0])
        if isea[y,x]: c=list(BCOL[8])
        n=int(nation[y,x])
        if land[y,x] and n>=0:
            t=hex2rgb(REALMHUE[order[n]])
            c=[int(a*0.86+b2*0.14) for a,b2 in zip(c,t)]
        if land[y,x]:
            sh = 1.0 - 0.16*float(rel[y,x])            # gentle relief shading
            c=[int(v*sh) for v in c]
        px[x,y]=tuple(np.clip(c,0,255))
img = img.resize((CW//2,CH//2), Image.LANCZOS).filter(ImageFilter.GaussianBlur(3.2))
buf=io.BytesIO(); img.save(buf,'PNG',optimize=True)
WASH = base64.b64encode(buf.getvalue()).decode()

# ---------------------------------------------------------------- helpers
def path_d(pts, close=True):
    if not pts: return ""
    d="M%.1f %.1f"%pts[0] + "".join("L%.1f %.1f"%p for p in pts[1:])
    return d+"Z" if close else d
def chaikin(p,it=2,closed=True):
    for _ in range(it):
        o=[];n=len(p)
        for i in range(n if closed else n-1):
            a=p[i];b=p[(i+1)%n]
            o.append((a[0]*.75+b[0]*.25,a[1]*.75+b[1]*.25))
            o.append((a[0]*.25+b[0]*.75,a[1]*.25+b[1]*.75))
        p=o
    return p
def simplify(pts, tol=1.0):
    out=[pts[0]]
    for p in pts[1:]:
        if (p[0]-out[-1][0])**2+(p[1]-out[-1][1])**2 >= tol*tol: out.append(p)
    return out
def esc(s): return s.replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")

coasts=[simplify([tuple(p) for p in c],1.6) for c in W_['coastlines']]
coasts=[c for c in coasts if len(c)>14]
coasts.sort(key=len,reverse=True)

# offset a closed polygon outward (for sea stipple lines)
def offset(pts, amt):
    n=len(pts); out=[]
    # outward = away from centroid-ish via edge normals
    for i,(x,y) in enumerate(pts):
        a=pts[(i-1)%n]; b=pts[(i+1)%n]
        tx,ty=b[0]-a[0],b[1]-a[1]; L=math.hypot(tx,ty)+1e-9
        out.append((x+ty/L*amt, y-tx/L*amt))
    return out
# determine winding so offsets go seaward
def area(p):
    s=0
    for i in range(len(p)): x1,y1=p[i]; x2,y2=p[(i+1)%len(p)]; s+=x1*y2-x2*y1
    return s/2

# ---------------------------------------------------------------- borders
segs=defaultdict(list)
for y in range(H-1):
    for x in range(W-1):
        for dy,dx in ((0,1),(1,0)):
            p,q=y+dy,x+dx
            if not(land[y,x] and land[p,q]): continue
            a,b=int(nation[y,x]),int(nation[p,q])
            if a==b: continue
            if dx: e=((x+1,y),(x+1,y+1))
            else:  e=((x,y+1),(x+1,y+1))
            key=tuple(sorted((a,b)))
            segs[key].append(e)
def chain(segl):
    adj=defaultdict(list)
    for a,b in segl: adj[a].append(b); adj[b].append(a)
    seen=set(); paths=[]
    ends=[k for k in adj if len(adj[k])==1]
    for st in ends+list(adj):
        if st in seen: continue
        cur=st; prev=None; path=[st]; seen.add(st)
        while True:
            nx=None
            for c in adj[cur]:
                if c!=prev and c not in seen: nx=c; break
            if nx is None: break
            seen.add(nx); path.append(nx); prev,cur=cur,nx
        if len(path)>6: paths.append(path)
    return paths
borders=[]
for (a,b),sl in segs.items():
    for p in chain(sl):
        pts=[(v[0]*SX,v[1]*SY) for v in p]
        borders.append((a,b,chaikin(simplify(pts,2.2),2,closed=False)))

# ---------------------------------------------------------------- symbol placement
def poisson(mask_fn, r, jitter=0.55, weight=None):
    out=[]; step=max(1,int(r*0.72)); occ=[]
    ys=list(range(1,H-1,step))
    for y in ys:
        for x in range(1,W-1,step):
            yy=y+int(rng.integers(-1,2)); xx=x+int(rng.integers(-1,2))
            if not (0<=yy<H and 0<=xx<W): continue
            if not mask_fn(yy,xx): continue
            if weight is not None and rng.random()>weight(yy,xx): continue
            fy=yy+float(rng.normal(0,jitter)); fx=xx+float(rng.normal(0,jitter))
            ok=True
            for py,pxx in occ:
                if (fy-py)**2+(fx-pxx)**2 < (r*0.85)**2: ok=False; break
            if ok: occ.append((fy,fx)); out.append((fx,fy))
    return out

mtn_pts = poisson(lambda y,x: land[y,x] and rel[y,x]>0.48 and not lake[y,x], 3.0,
                  weight=lambda y,x: min(1.0,(rel[y,x]-0.44)*3.0))
hill_pts= poisson(lambda y,x: land[y,x] and 0.30<rel[y,x]<=0.48 and not lake[y,x], 4.0,
                  weight=lambda y,x: 0.55)
conif   = poisson(lambda y,x: land[y,x] and biome[y,x] in (3,) and not lake[y,x], 3.2, weight=lambda y,x:0.85)
deep    = poisson(lambda y,x: land[y,x] and biome[y,x]==4 and not lake[y,x], 3.0, weight=lambda y,x:0.9)
wood    = poisson(lambda y,x: land[y,x] and biome[y,x]==5 and not lake[y,x], 4.6, weight=lambda y,x:0.6)
dunes   = poisson(lambda y,x: land[y,x] and biome[y,x]==6 and not lake[y,x], 5.2, weight=lambda y,x:0.55)
grass   = poisson(lambda y,x: land[y,x] and biome[y,x]==7 and not lake[y,x] and rel[y,x]<0.3, 7.0, weight=lambda y,x:0.4)
print("glyphs  mtn",len(mtn_pts),"hill",len(hill_pts),"conifer",len(conif),
      "deep",len(deep),"wood",len(wood),"dune",len(dunes),"grass",len(grass))

# ---------------------------------------------------------------- SVG
S=[]
A=S.append
A(f'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
  f'viewBox="0 0 {CW} {CH}" width="{CW}" height="{CH}" font-family="Georgia,\'Iowan Old Style\',\'Times New Roman\',serif">')
A('<title>Sundermere — A Continent of the Sundered West</title>')

A('<defs>')
A('''<filter id="paper" x="0" y="0" width="100%" height="100%">
 <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="5" seed="7" result="n"/>
 <feColorMatrix in="n" type="saturate" values="0" result="g"/>
 <feComponentTransfer in="g" result="t"><feFuncA type="linear" slope="0.30"/></feComponentTransfer>
 <feComposite in="t" in2="SourceGraphic" operator="atop"/></filter>''')
A('''<filter id="grain" x="-5%" y="-5%" width="110%" height="110%">
 <feTurbulence type="fractalNoise" baseFrequency="0.022" numOctaves="4" seed="19" result="n"/>
 <feDisplacementMap in="SourceGraphic" in2="n" scale="3.4" xChannelSelector="R" yChannelSelector="G"/></filter>''')
A('''<filter id="softgrain" x="-5%" y="-5%" width="110%" height="110%">
 <feTurbulence type="fractalNoise" baseFrequency="0.03" numOctaves="3" seed="33" result="n"/>
 <feDisplacementMap in="SourceGraphic" in2="n" scale="1.8" xChannelSelector="R" yChannelSelector="G"/></filter>''')
A('''<filter id="stain" x="-20%" y="-20%" width="140%" height="140%">
 <feTurbulence type="fractalNoise" baseFrequency="0.006" numOctaves="4" seed="55" result="n"/>
 <feColorMatrix in="n" type="saturate" values="0"/>
 <feComponentTransfer><feFuncA type="table" tableValues="0 0 0.10 0.30 0.55"/></feComponentTransfer></filter>''')
A('''<filter id="coastglow" x="-10%" y="-10%" width="120%" height="120%">
 <feGaussianBlur stdDeviation="7" result="b"/><feComponentTransfer in="b">
 <feFuncA type="linear" slope="0.55"/></feComponentTransfer></filter>''')
A(f'<radialGradient id="vig" cx="50%" cy="48%" r="72%"><stop offset="55%" stop-color="#000" stop-opacity="0"/>'
  f'<stop offset="100%" stop-color="#4a3316" stop-opacity="0.34"/></radialGradient>')
# glyph symbols
A('''<g id="sym">
<g id="mtn">
 <path d="M-8 1 L-2.2 -10.2 L0.6 -5.6 L2.2 -8.2 L8 1 Z" fill="#efe4c8" stroke="none"/>
 <path d="M-8 1 L-2.2 -10.2 L2.2 -8.2 L8 1" fill="none" stroke="%INK%" stroke-width="1.05" stroke-linejoin="round"/>
 <path d="M-2.2 -10.2 L-0.4 1 M2.2 -8.2 L3.4 1" fill="none" stroke="%INK%" stroke-width="0.6" opacity="0.75"/>
 <path d="M0.4 -6.6 L2.4 1 M1.6 -4.2 L3.9 1 M3.0 -2.0 L5.2 1 M4.4 0.0 L6.0 1"
       fill="none" stroke="%INK%" stroke-width="0.5" opacity="0.5"/></g>
<g id="mtn2">
 <path d="M-7.4 1 L-1.4 -9.4 L4.6 1 Z" fill="#efe4c8"/>
 <path d="M-7.4 1 L-1.4 -9.4 L4.6 1" fill="none" stroke="%INK%" stroke-width="1.0" stroke-linejoin="round"/>
 <path d="M-1.4 -9.4 L0.2 1" fill="none" stroke="%INK%" stroke-width="0.55" opacity="0.7"/>
 <path d="M-0.6 -5.4 L1.6 1 M0.6 -3.0 L3.0 1 M1.8 -0.8 L4.0 1"
       fill="none" stroke="%INK%" stroke-width="0.45" opacity="0.45"/></g>
<g id="hill">
 <path d="M-5.4 1 Q-2.6 -4.6 0 -1.2 Q2.4 -4.9 5.4 1" fill="#efe4c8" stroke="%INK%"
       stroke-width="0.85" stroke-linecap="round" stroke-linejoin="round"/></g>
<g id="conifer">
 <path d="M0 1 L0 -2.4" stroke="%INK%" stroke-width="0.8"/>
 <path d="M-3.2 -1.6 L0 -8.6 L3.2 -1.6 Z" fill="#efe4c8" stroke="%INK%" stroke-width="0.85"
       stroke-linejoin="round"/>
 <path d="M-2.2 -3.6 L0 -7.2 L2.2 -3.6" fill="none" stroke="%INK%" stroke-width="0.5" opacity="0.6"/></g>
<g id="broadleaf">
 <path d="M0 1.4 L0 -2.2" stroke="%INK%" stroke-width="0.85"/>
 <path d="M-3.6 -3.2 a3.7 3.2 0 0 1 2.0 -4.0 a3.4 3.0 0 0 1 4.4 1.0 a3.2 2.9 0 0 1 -1.2 4.6
          a4.2 3.4 0 0 1 -5.2 -1.6 Z" fill="#efe4c8" stroke="%INK%" stroke-width="0.85"/>
 <path d="M-1.9 -4.6 q1.4 -1.1 3.0 -0.4" fill="none" stroke="%INK%" stroke-width="0.45" opacity="0.55"/></g>
<g id="dune">
 <path d="M-6 0.6 q3.0 -3.4 6.0 -0.9 q2.2 1.9 5.2 -0.9" fill="none" stroke="%INK%"
       stroke-width="0.8" stroke-linecap="round" opacity="0.8"/></g>
<g id="tuft">
 <path d="M-2.2 0.4 q0.8 -2.6 0.4 -3.4 M0 0.4 q0.3 -2.9 0.3 -3.6 M2.2 0.4 q-0.5 -2.6 -0.2 -3.3"
  fill="none" stroke="%INK%" stroke-width="0.62" stroke-linecap="round" opacity="0.65"/></g>
<g id="marsh">
 <path d="M-4.6 0 h3.4 M-1.0 -1.7 h3.4 M-4.0 -3.2 h3.0" stroke="%INK%" stroke-width="0.6" opacity="0.55"/></g>
</g>'''.replace("%INK%",INK))
A('</defs>')

# ---- 1. parchment ground -------------------------------------------------
A(f'<rect width="{CW}" height="{CH}" fill="{PAPER}"/>')
A(f'<rect width="{CW}" height="{CH}" fill="#d8c49a" filter="url(#stain)" opacity="0.55"/>')
A(f'<rect width="{CW}" height="{CH}" fill="{SEACOL}" opacity="0.52"/>')

# ---- 2. sea stipple lines ------------------------------------------------
A('<g id="sea-lines" filter="url(#softgrain)" fill="none" stroke="%s" stroke-linejoin="round">'%INK2)
for c in coasts[:6]:
    sgn = 1 if area(c)>0 else -1
    for k,(amt,op,wdt) in enumerate([(9,0.34,1.3),(20,0.24,1.05),(33,0.16,0.9),(48,0.10,0.8),(66,0.06,0.7)]):
        o=chaikin(offset(c, amt*sgn),1)
        A(f'<path d="{path_d(simplify(o,3.0))}" stroke-width="{wdt}" opacity="{op}"/>')
A('</g>')

# ---- 3. land: shadow, wash, coast ---------------------------------------
land_d = " ".join(path_d(c) for c in coasts)
A(f'<clipPath id="landclip"><path d="{land_d}" clip-rule="evenodd"/></clipPath>')
A(f'<path d="{land_d}" fill-rule="evenodd" fill="#7a6240" opacity="0.32" filter="url(#coastglow)" transform="translate(5,7)"/>')
A(f'<path d="{land_d}" fill-rule="evenodd" fill="#f3e8ce"/>')
A(f'<g clip-path="url(#landclip)"><image x="0" y="0" width="{CW}" height="{CH}" opacity="0.80" '
  f'preserveAspectRatio="none" xlink:href="data:image/png;base64,{WASH}"/></g>')
A('<g id="coastline" fill="none" filter="url(#softgrain)">')
for c in coasts:
    A(f'<path d="{path_d(c)}" stroke="{INK}" stroke-width="2.1" stroke-opacity="0.92" stroke-linejoin="round"/>')
A('</g>')

# ---- 5. terrain glyphs (painter order: back to front) --------------------
A('<g id="terrain" clip-path="url(#landclip)" filter="url(#softgrain)">')
def emit(pts, sym, sc_fn, sort=True):
    seq = sorted(pts, key=lambda p:p[1]) if sort else pts
    for fx,fy in seq:
        s = sc_fn(fx,fy)
        A(f'<use href="#{sym}" x="{fx*SX:.1f}" y="{fy*SY:.1f}" transform="translate(0,0)" '
          f'width="1" height="1" style="transform-box:fill-box" />' if False else
          f'<g transform="translate({fx*SX:.1f},{fy*SY:.1f}) scale({s:.2f})"><use href="#{sym}" xlink:href="#{sym}"/></g>')
emit(dunes,"dune", lambda x,y: 0.85+0.3*rng.random())
emit(grass,"tuft", lambda x,y: 0.85+0.3*rng.random())
emit(wood,"broadleaf", lambda x,y: 0.80+0.30*rng.random())
emit(deep,"broadleaf", lambda x,y: 0.92+0.32*rng.random())
emit(conif,"conifer", lambda x,y: 0.90+0.32*rng.random())
emit(hill_pts,"hill", lambda x,y: 0.85+0.35*rng.random())
def mscale(fx,fy):
    r=float(rel[int(np.clip(fy,0,H-1)),int(np.clip(fx,0,W-1))])
    return 0.72+1.05*np.clip((r-0.42)/0.5,0,1)+0.14*rng.random()
seq=sorted(mtn_pts,key=lambda p:p[1])
for fx,fy in seq:
    sym = "mtn" if rng.random()<0.55 else "mtn2"
    A(f'<g transform="translate({fx*SX:.1f},{fy*SY:.1f}) scale({mscale(fx,fy):.2f})"><use href="#{sym}" xlink:href="#{sym}"/></g>')
A('</g>')

# ---- 5b. rivers (drawn over terrain) -----------------------------------------------------------
A('<g id="rivers" fill="none" stroke-linecap="round" stroke-linejoin="round" filter="url(#softgrain)">')
for r in W_['rivers']:
    pts=[tuple(p) for p in r['pts']]
    if len(pts)<4: continue
    pts=chaikin(simplify(pts,2.0),2,closed=False)
    mx=max(r['widths']) if r['widths'] else 1
    w = 1.5 + 3.1*min(1.0, math.sqrt(r['flow']/5400))
    d = path_d(pts,False)
    A(f'<path d="{d}" stroke="{PAPER}" stroke-width="{w+2.4:.2f}" opacity="0.55"/>')
    A(f'<path d="{d}" stroke="#2f4d61" stroke-width="{w:.2f}" opacity="0.95"/>')
A('</g>')

# ---- 6. lakes + inland sea ----------------------------------------------
def cell_polys(mask, minsz=26):
    lab=np.zeros(mask.shape,int); cur=0; groups=[]
    for sy in range(H):
        for sx in range(W):
            if mask[sy,sx] and lab[sy,sx]==0:
                cur+=1; st=[(sy,sx)]; lab[sy,sx]=cur; cells=[]
                while st:
                    y,x=st.pop(); cells.append((y,x))
                    for dy,dx in ((1,0),(-1,0),(0,1),(0,-1)):
                        p,q=y+dy,x+dx
                        if 0<=p<H and 0<=q<W and mask[p,q] and lab[p,q]==0:
                            lab[p,q]=cur; st.append((p,q))
                if len(cells)>=minsz: groups.append(cells)
    return groups
def outline(cells):
    cs=set(cells); sl=[]
    for y,x in cells:
        if (y-1,x) not in cs: sl.append(((x,y),(x+1,y)))
        if (y+1,x) not in cs: sl.append(((x,y+1),(x+1,y+1)))
        if (y,x-1) not in cs: sl.append(((x,y),(x,y+1)))
        if (y,x+1) not in cs: sl.append(((x+1,y),(x+1,y+1)))
    return chain(sl)
A('<g id="water-bodies" filter="url(#softgrain)">')
for m,minsz in ((lake & land, 22), (isea, 40)):
    for cells in cell_polys(m, minsz):
        for p in outline(cells):
            pts=chaikin([(v[0]*SX,v[1]*SY) for v in p],4)
            A(f'<path d="{path_d(pts)}" fill="#b9cdd4" fill-opacity="0.72" stroke="#3d5a6c" stroke-width="1.15"/>')
A('</g>')

# ---- 7. borders ----------------------------------------------------------
A('<g id="borders" fill="none" stroke-linecap="round">')
for a,b,pts in borders:
    if len(pts)<8: continue
    wild = (a<0 or b<0)
    col = REALMHUE[order[max(a,b)]] if not wild else "#6b5236"
    A(f'<path d="{path_d(pts,False)}" stroke="{col}" stroke-width="{3.2 if not wild else 2.0}" '
      f'stroke-opacity="{0.85 if not wild else 0.50}" stroke-dasharray="{"9 5 2 5" if not wild else "3 6"}"/>')
A('</g>')

# ---- 8. roads ------------------------------------------------------------
A('<g id="roads" fill="none" stroke="#7a5b34" stroke-linecap="round">')
for r in W_['roads']:
    if r['grade']=="searoute":
        a,b=r['pts'][0],r['pts'][1]
        mx,my=(a[0]+b[0])/2,(a[1]+b[1])/2
        nx_,ny_=-(b[1]-a[1]),(b[0]-a[0]); L=math.hypot(nx_,ny_)+1e-9
        cx_,cy_=mx+nx_/L*L*0.055, my+ny_/L*L*0.055
        A(f'<path d="M{a[0]:.1f} {a[1]:.1f} Q{cx_:.1f} {cy_:.1f} {b[0]:.1f} {b[1]:.1f}" '
          f'stroke="#3d6070" stroke-width="1.5" stroke-opacity="0.40" stroke-dasharray="2 7"/>')
        continue
    pts=chaikin(simplify([tuple(p) for p in r['pts']],4.0),2,closed=False)
    if len(pts)<3: continue
    hw = r['grade']=="highway"
    A(f'<path d="{path_d(pts,False)}" stroke-width="{2.6 if hw else 1.7}" '
      f'stroke-opacity="{0.80 if hw else 0.55}" stroke-dasharray="{"7 4" if hw else "4 5"}"/>')
A('</g>')
print("border chains:",len(borders))

# ---- 9. label engine -----------------------------------------------------
placed=[]
def fits(x,y,w,h,pad=2.0):
    for (a,b,c,d) in placed:
        if x-pad < a+c and x+w+pad > a and y-pad < b+d and y+h+pad > b: return False
    return True
def reserve(x,y,w,h): placed.append((x,y,w,h))
def tw(text,size,ls=0.0): return len(text)*size*0.505 + max(0,len(text)-1)*ls
def label(text, x, y, size, *, anchor="middle", fill=INK, weight="normal",
          style="normal", ls=0.0, opacity=1.0, halo=2.6, force=False, caps=False,
          dy=0.0, family=None, extra=""):
    t = text.upper() if caps else text
    w = tw(t,size,ls); h = size*1.06
    ax = {"middle":x-w/2,"start":x,"end":x-w}[anchor]
    if ax < PAD+8:
        x += (PAD+8-ax); ax = PAD+8
    if ax+w > CW-PAD-8:
        x -= (ax+w-(CW-PAD-8)); ax = CW-PAD-8-w
    ay = y+dy-size*0.78
    if not force and not fits(ax,ay,w,h): return False
    reserve(ax,ay,w,h)
    fam = f' font-family="{family}"' if family else ""
    common = (f'x="{ax:.1f}" y="{y+dy:.1f}" font-size="{size}" text-anchor="start"{fam} '
              f'font-weight="{weight}" font-style="{style}" letter-spacing="{ls}"{extra}')
    A(f'<text {common} fill="none" stroke="{PAPER}" stroke-width="{halo}" stroke-opacity="0.92" '
      f'stroke-linejoin="round">{esc(t)}</text>')
    A(f'<text {common} fill="{fill}" opacity="{opacity}">{esc(t)}</text>')
    return True

# reserve the frame margin so nothing crowds the border
for r in [(0,0,CW,PAD),(0,CH-PAD,CW,PAD),(0,0,PAD,CH),(CW-PAD,0,PAD,CH)]: reserve(*r)

# ---- 10. realm names (drawn first: biggest, lowest priority to be covered)
A('<g id="realm-labels">')
for r in W_['realms']:
    x,y = r['centroid']
    label(r['name'], x, y, 36, caps=True, ls=5.6, fill=r['colour'], opacity=0.92,
          weight="bold", halo=8.0, force=True)
A('</g>')

# ---- 11. feature labels --------------------------------------------------
A('<g id="feature-labels">')
arcs=[]
for f in W_['features']:
    k=f['kind']
    if k=="range":
        ang=max(-32,min(32,f.get('angle',0)))
        L=max(150,min(430, math.sqrt(f['cells'])*17))
        rad=math.radians(ang)
        x0=f['x']-math.cos(rad)*L/2; y0=f['y']-math.sin(rad)*L/2
        x1=f['x']+math.cos(rad)*L/2; y1=f['y']+math.sin(rad)*L/2
        pid=f"arc{len(arcs)}"
        arcs.append(f'<path id="{pid}" d="M{x0:.0f} {y0:.0f} Q{f["x"]:.0f} {f["y"]-18:.0f} {x1:.0f} {y1:.0f}"/>')
        reserve(min(x0,x1), min(y0,y1)-16, abs(x1-x0), abs(y1-y0)+30)
        tp=f'<textPath href="#{pid}" xlink:href="#{pid}" startOffset="50%" text-anchor="middle">{esc(f["name"].upper())}</textPath>'
        base='font-size="22" letter-spacing="4.4" font-weight="bold"'
        A(f'<text {base} fill="none" stroke="{PAPER}" stroke-width="6" stroke-opacity="0.9" stroke-linejoin="round">{tp}</text>')
        A(f'<text {base} fill="{INK}" opacity="0.95">{tp}</text>')
    elif k in ("forest","desert"):
        label(f['name'], f['x'], f['y'], 17, style="italic", fill="#4f4327", opacity=0.85, ls=1.4)
    elif k=="sea":
        label(f['name'], f['x'], f['y'], 26, style="italic", caps=False, fill="#2f4d61", opacity=0.9, ls=3.0, force=True)
    elif k=="gulf":
        label(f['name'], f['x'], f['y'], 40, style="italic", fill="#2f4d61", opacity=0.85, ls=7.0, force=True)
    elif k in ("ocean","bay"):
        label(f['name'], f['x'], f['y'], 25, style="italic", fill="#2f4d61", opacity=0.80, ls=3.4, force=True)
    elif k=="lake":
        label(f['name'], f['x'], f['y'], 13, style="italic", fill="#2f4d61", opacity=0.9)
A("".join(f'<defs>{a}</defs>' for a in arcs))
A('</g>')

# river names along their course
A(f'<g id="river-labels" font-size="13" font-style="italic">')
rdefs=[]
for i,r in enumerate(W_['rivers']):
    if not r.get('name'): continue
    pts=[tuple(p) for p in r['pts']]
    if len(pts)<26: continue
    seg=pts[len(pts)//2-9:len(pts)//2+9]
    if len(seg)<4: continue
    if not fits(min(p[0] for p in seg), min(p[1] for p in seg)-9,
                abs(seg[-1][0]-seg[0][0])+40, 18): continue
    reserve(min(p[0] for p in seg), min(p[1] for p in seg)-9, abs(seg[-1][0]-seg[0][0])+40, 18)
    if seg[-1][0] < seg[0][0]: seg=seg[::-1]
    rdefs.append(f'<path id="riv{i}" d="{path_d(chaikin(simplify(seg,3),2,False),False)}"/>')
    tp=f'<textPath href="#riv{i}" xlink:href="#riv{i}" startOffset="50%" text-anchor="middle">{esc(r["name"])}</textPath>'
    A(f'<text fill="none" stroke="{PAPER}" stroke-width="4" stroke-opacity="0.9" stroke-linejoin="round">{tp}</text>')
    A(f'<text fill="#2f4d61">{tp}</text>')
A(f'<defs>{"".join(rdefs)}</defs></g>')

# ---- 13. settlements -----------------------------------------------------
def town_icon(t, col):
    if t=="capital":
        return (f'<circle r="9.4" fill="{PAPER}" stroke="{INK}" stroke-width="1.5"/>'
                f'<path d="M0 -7.6 L2.1 -2.4 L7.6 -2.4 L3.2 1.0 L4.9 6.4 L0 3.2 L-4.9 6.4 '
                f'L-3.2 1.0 L-7.6 -2.4 L-2.1 -2.4 Z" fill="{col}" stroke="{INK}" stroke-width="0.9" stroke-linejoin="round"/>'
                f'<circle r="12.6" fill="none" stroke="{INK}" stroke-width="0.9" opacity="0.55"/>')
    if t=="city":
        return (f'<circle r="6.4" fill="{PAPER}" stroke="{INK}" stroke-width="1.35"/>'
                f'<path d="M-4.2 2.4 v-4.6 h1.5 v-1.6 h1.4 v1.6 h1.6 v-1.6 h1.4 v1.6 h1.5 v4.6 Z" '
                f'fill="{col}" stroke="{INK}" stroke-width="0.75" stroke-linejoin="round"/>')
    if t=="town":
        return f'<circle r="4.1" fill="{PAPER}" stroke="{INK}" stroke-width="1.25"/><circle r="1.7" fill="{INK}"/>'
    return f'<circle r="2.0" fill="{INK}" opacity="0.72"/>'

order_t={"capital":0,"city":1,"town":2,"village":3}
S_sorted=sorted(W_['settlements'], key=lambda s:(order_t[s['tier']], -s['pop']))
A('<g id="settlements">')
LBL={"capital":(19.0,"bold",True,3.2),"city":(15.5,"bold",True,2.0),"town":(12.5,"normal",False,0.6)}
for s in S_sorted:
    col = REALMHUE.get(s['realm'], "#6b5236")
    t=s['tier']
    if t=="village":
        if not fits(s['x']-3,s['y']-3,6,6): continue
        reserve(s['x']-3,s['y']-3,6,6)
        A(f'<g transform="translate({s["x"]:.1f},{s["y"]:.1f})">{town_icon(t,col)}</g>')
        continue
    rr={"capital":13,"city":7,"town":4.5}[t]
    if t!="capital" and not fits(s['x']-rr,s['y']-rr,rr*2,rr*2): continue
    reserve(s['x']-rr,s['y']-rr,rr*2,rr*2)
    A(f'<g transform="translate({s["x"]:.1f},{s["y"]:.1f})">{town_icon(t,col)}</g>')
    size,wt,caps,ls = LBL[t]
    if not label(s['name'], s['x'], s['y']-rr-4, size, caps=caps, weight=wt, ls=ls,
                 fill=INK, halo=3.0, force=(t=="capital")):
        label(s['name'], s['x']+rr+5, s['y']+size*0.34, size, anchor="start", caps=caps,
              weight=wt, ls=ls, fill=INK, halo=3.0)
A('</g>')

# ---- 12. landmarks -------------------------------------------------------
GLYPH={
 "tower":'<path d="M-3.4 4 v-8 h1.6 v-2 h1.4 v2 h1.4 v-2 h1.4 v2 h1.6 v8 Z" fill="{p}" stroke="{i}" stroke-width="1.1" stroke-linejoin="round"/>',
 "ruin": '<path d="M-5 4 v-6 h2.4 v3 h1.6 v-7 h2.2 v10 M2.4 4 v-5 h2.6 v5" fill="none" stroke="{i}" stroke-width="1.25" stroke-linejoin="round"/>',
 "dungeon":'<path d="M-4.4 4 v-5 a4.4 4.6 0 0 1 8.8 0 v5 Z" fill="{p}" stroke="{i}" stroke-width="1.15"/><path d="M0 4 v-5.6" stroke="{i}" stroke-width="0.9"/>',
 "stones":'<path d="M-5 4 v-6.4 h2.2 v6.4 M-1.1 4 v-8 h2.2 v8 M2.8 4 v-5.4 h2.2 v5.4" fill="{p}" stroke="{i}" stroke-width="1.05" stroke-linejoin="round"/>',
 "shrine":'<path d="M-4.6 4 L0 -5.4 L4.6 4 Z" fill="{p}" stroke="{i}" stroke-width="1.15" stroke-linejoin="round"/><path d="M0 -8.4 v3.2 M-1.5 -7.1 h3" stroke="{i}" stroke-width="1.0"/>',
 "barrow":'<path d="M-5.4 4 a5.4 4.4 0 0 1 10.8 0 Z" fill="{p}" stroke="{i}" stroke-width="1.15"/><path d="M-1.5 4 v-2.6 h3 v2.6" fill="none" stroke="{i}" stroke-width="0.9"/>',
 "battle":'<path d="M-4.8 -4.6 L4.4 4.2 M4.8 -4.6 L-4.4 4.2" stroke="{i}" stroke-width="1.4" stroke-linecap="round"/>',
 "mine":  '<path d="M-4.8 3.6 L4.4 -3.8 M-1.2 -4.4 a3.6 3.6 0 0 1 5.6 0.6" fill="none" stroke="{i}" stroke-width="1.3" stroke-linecap="round"/>',
 "lair":  '<path d="M-5.6 3.4 q2.6 -3.4 5.6 -1.2 q3.0 2.2 5.6 -1.4 M-2.6 -1.4 q2.6 -4.4 5.6 -1.0" fill="none" stroke="{i}" stroke-width="1.25" stroke-linecap="round"/>',
 "pass":  '<path d="M-6 4 L-1.6 -3.6 L0.6 0.4 L3 -4.6 L6.4 4" fill="none" stroke="{i}" stroke-width="1.2" stroke-linejoin="round"/>',
 "oasis": '<path d="M0 4 v-5 M0 -1 q-4 -2.4 -5 -0.4 M0 -1 q4 -2.4 5 -0.4 M0 -1 q-1 -4 1.6 -4.6" fill="none" stroke="{i}" stroke-width="1.05" stroke-linecap="round"/>',
}
A('<g id="landmarks">')
for p in W_['landmarks']:
    g=GLYPH.get(p['type'],GLYPH['ruin']).format(i=INK,p=PAPER)
    if not fits(p['x']-9,p['y']-9,18,18): continue
    reserve(p['x']-9,p['y']-9,18,18)
    A(f'<g transform="translate({p["x"]:.1f},{p["y"]:.1f}) scale(0.95)" opacity="0.9">{g}</g>')
    label(p['name'], p['x'], p['y']+16, 10.5, style="italic", fill="#5a4a2c", opacity=0.9, halo=2.4)
A('</g>')

# ---- 14. compass rose ----------------------------------------------------
cxr, cyr, R = CW-236, CH-232, 84
A(f'<g id="compass" transform="translate({cxr},{cyr})" opacity="0.88">')
A(f'<circle r="{R*0.94:.0f}" fill="none" stroke="{INK}" stroke-width="1.5" opacity="0.55"/>')
A(f'<circle r="{R*0.80:.0f}" fill="none" stroke="{INK}" stroke-width="0.8" opacity="0.4"/>')
for k in range(32):
    a=math.radians(k*11.25); L=R*(0.94 if k%8==0 else (0.87 if k%4==0 else 0.83))
    A(f'<path d="M{math.sin(a)*R*0.80:.1f} {-math.cos(a)*R*0.80:.1f} L{math.sin(a)*L:.1f} {-math.cos(a)*L:.1f}" '
      f'stroke="{INK}" stroke-width="{1.2 if k%8==0 else 0.6}" opacity="0.6"/>')
for a,ln,wd in [(0,R*0.80,0.16),(90,R*0.80,0.16),(180,R*0.80,0.16),(270,R*0.80,0.16),
                 (45,R*0.50,0.24),(135,R*0.50,0.24),(225,R*0.50,0.24),(315,R*0.50,0.24)]:
    rad=math.radians(a); s_,c_=math.sin(rad),-math.cos(rad)
    tipx,tipy = s_*ln, c_*ln
    bx1,by1 = -c_*ln*wd, s_*ln*wd
    A(f'<path d="M{tipx:.1f} {tipy:.1f} L{bx1:.1f} {by1:.1f} L0 0 Z" fill="{INK}" opacity="0.95"/>')
    A(f'<path d="M{tipx:.1f} {tipy:.1f} L{-bx1:.1f} {-by1:.1f} L0 0 Z" fill="{INK}" opacity="0.38"/>')
A(f'<circle r="5" fill="{PAPER}" stroke="{INK}" stroke-width="1.2"/>')
for lt,a in (("N",0),("E",90),("S",180),("W",270)):
    rad=math.radians(a)
    A(f'<text x="{math.sin(rad)*R*1.16:.1f}" y="{-math.cos(rad)*R*1.16+7:.1f}" font-size="20" '
      f'text-anchor="middle" fill="{INK}" font-weight="bold">{lt}</text>')
A('</g>')

# ---- 15. scale bar -------------------------------------------------------
MI_PER_PX = W_['meta']['cellMi']/SX
bar_mi = 400; bar_px = bar_mi/MI_PER_PX
bx,by = PAD+38, CH-PAD-46
A(f'<g id="scale" opacity="0.9">')
A(f'<rect x="{bx}" y="{by}" width="{bar_px:.1f}" height="9" fill="{PAPER}" stroke="{INK}" stroke-width="1.1"/>')
for k in range(4):
    if k%2==0: A(f'<rect x="{bx+bar_px*k/4:.1f}" y="{by}" width="{bar_px/4:.1f}" height="9" fill="{INK}"/>')
for k in range(5):
    A(f'<text x="{bx+bar_px*k/4:.1f}" y="{by-6}" font-size="12" text-anchor="middle" fill="{INK}">{k*100}</text>')
A(f'<text x="{bx+bar_px/2:.1f}" y="{by+23}" font-size="12.5" text-anchor="middle" fill="{INK}" '
  f'font-style="italic">statute miles</text></g>')

# ---- 16. title cartouche -------------------------------------------------
tx,ty,tw_,th_ = PAD+34, PAD+30, 452, 152
A(f'<g id="cartouche">')
A(f'<path d="M{tx} {ty+16} q0 -16 16 -16 h{tw_-32} q16 0 16 16 v{th_-32} q0 16 -16 16 h{-(tw_-32)} q-16 0 -16 -16 Z" '
  f'fill="{PAPER}" fill-opacity="0.88" stroke="{INK}" stroke-width="2.2"/>')
A(f'<path d="M{tx+9} {ty+22} q0 -13 13 -13 h{tw_-44} q13 0 13 13 v{th_-44} q0 13 -13 13 h{-(tw_-44)} q-13 0 -13 -13 Z" '
  f'fill="none" stroke="{INK}" stroke-width="0.9" opacity="0.6"/>')
A(f'<text x="{tx+tw_/2-tw("SUNDERMERE",46,7)/2:.0f}" y="{ty+62}" font-size="46" fill="{INK}" '
  f'font-weight="bold" letter-spacing="7">SUNDERMERE</text>')
A(f'<text x="{tx+tw_/2-tw("A Continent of the Sundered West",15,2.2)/2:.0f}" y="{ty+90}" font-size="15" fill="{INK2}" '
  f'font-style="italic" letter-spacing="2.2">A Continent of the Sundered West</text>')
A(f'<path d="M{tx+70} {ty+104} H{tx+tw_-70}" stroke="{INK}" stroke-width="0.9" opacity="0.55"/>')
_sub=f'Nine Realms · {len(W_["settlements"])} Settlements · {sum(s["pop"] for s in W_["settlements"]):,} Souls'
A(f'<text x="{tx+tw_/2-tw(_sub,12.5,1.4)/2:.0f}" y="{ty+126}" font-size="12.5" fill="{INK2}" '
  f'letter-spacing="1.4">{_sub}</text>')
A('</g>')

# ---- 17. legend ----------------------------------------------------------
lx,ly = CW-PAD-262, PAD+28
A(f'<g id="legend">')
A(f'<rect x="{lx}" y="{ly}" width="248" height="{34+len(W_["realms"])*21}" rx="10" fill="{PAPER}" '
  f'fill-opacity="0.86" stroke="{INK}" stroke-width="1.6"/>')
A(f'<text x="{lx+124-tw("THE NINE REALMS",14.5,2.6)/2:.0f}" y="{ly+23}" font-size="14.5" fill="{INK}" '
  f'font-weight="bold" letter-spacing="2.6">THE NINE REALMS</text>')
for i,r in enumerate(W_['realms']):
    yy=ly+42+i*21
    A(f'<rect x="{lx+14}" y="{yy-9}" width="13" height="13" fill="{r["colour"]}" fill-opacity="0.72" '
      f'stroke="{INK}" stroke-width="0.8"/>')
    A(f'<text x="{lx+35}" y="{yy+2}" font-size="12.5" fill="{INK}">{esc(r["name"])}</text>')
    A(f'<text x="{lx+234}" y="{yy+2}" font-size="11" fill="{INK2}" text-anchor="end" '
      f'font-style="italic">{esc(r["capital"])}</text>')
A('</g>')

# ---- 18. vignette + frame ------------------------------------------------
A(f'<rect width="{CW}" height="{CH}" fill="url(#vig)" pointer-events="none"/>')
A(f'<rect width="{CW}" height="{CH}" fill="#8a6a3c" filter="url(#stain)" opacity="0.20" pointer-events="none"/>')
A(f'<rect x="{PAD*0.42:.0f}" y="{PAD*0.42:.0f}" width="{CW-PAD*0.84:.0f}" height="{CH-PAD*0.84:.0f}" '
  f'fill="none" stroke="{INK}" stroke-width="3.4" opacity="0.85"/>')
A(f'<rect x="{PAD*0.72:.0f}" y="{PAD*0.72:.0f}" width="{CW-PAD*1.44:.0f}" height="{CH-PAD*1.44:.0f}" '
  f'fill="none" stroke="{INK}" stroke-width="1.1" opacity="0.65"/>')
A('</svg>')

svg="\n".join(S)
open('/sessions/dazzling-jolly-pasteur/mnt/outputs/sundermere-map.svg','w').write(svg)
print("SVG written:", round(len(svg)/1024), "KB")
