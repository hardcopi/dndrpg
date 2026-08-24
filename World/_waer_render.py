"""Waerhaven stage 4: parchment street map (SVG)."""
import json, math, base64, io
import numpy as np
from PIL import Image, ImageFilter
D='/sessions/dazzling-jolly-pasteur/work/'
O='/sessions/dazzling-jolly-pasteur/mnt/outputs/'
W=json.load(open(O+'waerhaven.json'))
plan=json.load(open(D+'waer_plan.json'))
land=np.load(D+'waer_land.npy'); Hm=np.load(D+'waer_h.npy')
PW,PH = W['meta']['width'], W['meta']['height']       # plan 2400x1800
M    = 62                                             # frame margin
LEG  = 596                                            # legend panel width
CW, CH = M*2+PW+LEG, M*2+PH
rng=np.random.default_rng(77)

INK="#4a3520"; INK2="#6b5236"; PAPER="#efe2c4"; SEACOL="#8ba7b2"
ROAD="#f2e7cc"; BLDG="#bb9c6b"; BLDG2="#a3855c"
WATERINK="#2f4d61"

def esc(s): return str(s).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")
def dpath(pts, close=False):
    if not pts: return ""
    d="M%.1f %.1f"%tuple(pts[0])+"".join("L%.1f %.1f"%tuple(p) for p in pts[1:])
    return d+"Z" if close else d
def chaikin(p,it=2,closed=True):
    p=[tuple(q) for q in p]
    for _ in range(it):
        o=[];n=len(p)
        rngN = n if closed else n-1
        if not closed: o.append(p[0])
        for i in range(rngN):
            a=p[i];b=p[(i+1)%n]
            o.append((a[0]*.75+b[0]*.25,a[1]*.75+b[1]*.25))
            o.append((a[0]*.25+b[0]*.75,a[1]*.25+b[1]*.75))
        if not closed: o.append(p[-1])
        p=o
    return p
def offset_poly(pts,amt):
    n=len(pts); out=[]
    for i,(x,y) in enumerate(pts):
        a=pts[(i-1)%n]; b=pts[(i+1)%n]
        tx,ty=b[0]-a[0],b[1]-a[1]; L=math.hypot(tx,ty)+1e-9
        out.append((x+ty/L*amt, y-tx/L*amt))
    return out

shore=[tuple(p) for p in W['shore']]
LANDPOLY = shore + [(PW+400,PH+400),(-400,PH+400)]

# ---------- relief wash as an embedded image ----------
gh,gw=land.shape
img=Image.new('RGB',(gw,gh))
px=img.load()
for j in range(gh):
    for i in range(gw):
        if land[j,i]:
            h=Hm[j,i]
            v=int(238-h*1.25); px[i,j]=(v,int(v*0.955),int(v*0.86))
        else:
            px[i,j]=(180,196,200)
img=img.resize((gw*3,gh*3),Image.LANCZOS).filter(ImageFilter.GaussianBlur(4))
buf=io.BytesIO(); img.save(buf,'PNG',optimize=True)
WASH=base64.b64encode(buf.getvalue()).decode()

S=[];A=S.append
A(f'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
  f'viewBox="0 0 {CW} {CH}" width="{CW}" height="{CH}" '
  f'font-family="Georgia,\'Iowan Old Style\',\'Times New Roman\',serif">')
A('<title>Waerhaven — Second City of the Ythan League</title>')
A('<defs>')
A('''<filter id="sg" x="-4%" y="-4%" width="108%" height="108%">
 <feTurbulence type="fractalNoise" baseFrequency="0.035" numOctaves="3" seed="12" result="n"/>
 <feDisplacementMap in="SourceGraphic" in2="n" scale="1.5" xChannelSelector="R" yChannelSelector="G"/></filter>''')
A('''<filter id="stain" x="-20%" y="-20%" width="140%" height="140%">
 <feTurbulence type="fractalNoise" baseFrequency="0.005" numOctaves="4" seed="31" result="n"/>
 <feColorMatrix in="n" type="saturate" values="0"/>
 <feComponentTransfer><feFuncA type="table" tableValues="0 0 0.10 0.30 0.55"/></feComponentTransfer></filter>''')
A('''<filter id="glow" x="-10%" y="-10%" width="120%" height="120%">
 <feGaussianBlur stdDeviation="6"/><feComponentTransfer><feFuncA type="linear" slope="0.6"/></feComponentTransfer></filter>''')
A(f'<radialGradient id="vig" cx="42%" cy="48%" r="74%"><stop offset="56%" stop-color="#000" stop-opacity="0"/>'
  f'<stop offset="100%" stop-color="#4a3316" stop-opacity="0.30"/></radialGradient>')
A('</defs>')

A(f'<rect width="{CW}" height="{CH}" fill="{PAPER}"/>')
A(f'<rect width="{CW}" height="{CH}" fill="#d8c49a" filter="url(#stain)" opacity="0.5"/>')

# ================= the plan =================
A(f'<g transform="translate({M},{M})">')
A(f'<rect x="-2" y="-2" width="{PW+4}" height="{PH+4}" fill="{SEACOL}" opacity="0.78"/>')

# sea stipple
A(f'<g fill="none" stroke="{INK2}" stroke-linejoin="round" filter="url(#sg)">')
for amt,op,wd in [(11,0.32,1.3),(24,0.22,1.05),(40,0.15,0.9),(60,0.09,0.8)]:
    o=chaikin(offset_poly(shore,-amt),1,closed=False)
    A(f'<path d="{dpath(o)}" stroke-width="{wd}" opacity="{op}"/>')
A('</g>')

A(f'<clipPath id="landclip"><path d="{dpath(LANDPOLY,True)}"/></clipPath>')
A(f'<path d="{dpath(LANDPOLY,True)}" fill="#7a6240" opacity="0.30" filter="url(#glow)" transform="translate(4,6)"/>')
A(f'<path d="{dpath(LANDPOLY,True)}" fill="#f3e8ce"/>')
A(f'<g clip-path="url(#landclip)"><image x="0" y="0" width="{PW}" height="{PH}" opacity="0.62" '
  f'preserveAspectRatio="none" xlink:href="data:image/png;base64,{WASH}"/></g>')

# district tints
A('<defs>')
for d in W['districts']:
    A(f'<radialGradient id="dg_{d["id"]}"><stop offset="0%" stop-color="{d["colour"]}" stop-opacity="0.30"/>'
      f'<stop offset="62%" stop-color="{d["colour"]}" stop-opacity="0.17"/>'
      f'<stop offset="100%" stop-color="{d["colour"]}" stop-opacity="0"/></radialGradient>')
A('</defs>')
A('<g clip-path="url(#landclip)">')
for d in W['districts']:
    A(f'<circle cx="{d["centre"][0]}" cy="{d["centre"][1]}" r="{d["radius"]*1.5:.0f}" fill="url(#dg_{d["id"]})"/>')
A('</g>')

# coastline ink
A(f'<path d="{dpath(shore)}" fill="none" stroke="{INK}" stroke-width="2.4" stroke-opacity="0.92" '
  f'stroke-linejoin="round" filter="url(#sg)"/>')

# ---------- woodland beyond the walls ----------
A('<defs><g id="tree">'
  '<path d="M0 1.6 L0 -2.4" stroke="#3f3a22" stroke-width="1.0"/>'
  '<path d="M-3.6 -2.0 L0 -9.2 L3.6 -2.0 Z" fill="#c8cfa6" stroke="#4a5330" stroke-width="1.0" stroke-linejoin="round"/>'
  '<path d="M-2.5 -4.2 L0 -7.8 L2.5 -4.2" fill="none" stroke="#4a5330" stroke-width="0.6" opacity="0.65"/>'
  '</g></defs>')
def settled_score(x,y):
    return min(math.hypot(x-d['centre'][0],y-d['centre'][1])/d['radius'] for d in W['districts'])
def land_at(x,y):
    j=int(min(max(y/4,0),land.shape[0]-1)); i=int(min(max(x/4,0),land.shape[1]-1))
    return bool(land[j,i])
A('<g id="woods" clip-path="url(#landclip)" filter="url(#sg)">')
occ=[]
for yy_ in range(40,PH,26):
    for xx_ in range(40,PW,26):
        fx=xx_+float(rng.normal(0,6)); fy=yy_+float(rng.normal(0,6))
        if not land_at(fx,fy): continue
        if settled_score(fx,fy) < 1.42: continue
        if rng.random()>0.72: continue
        sc=0.95+float(rng.random())*0.55
        A(f'<g transform="translate({fx:.0f},{fy:.0f}) scale({sc:.2f})"><use href="#tree" xlink:href="#tree"/></g>')
A('</g>')

# ---------- plazas ----------
A('<g id="plazas">')
for p in W['plazas']:
    A(f'<circle cx="{p["x"]}" cy="{p["y"]}" r="{p["r"]}" fill="{ROAD}" stroke="{INK}" '
      f'stroke-width="1.1" stroke-opacity="0.45"/>')
A('</g>')

# ---------- streets: ink casing then paved fill ----------
segs=plan['streets']
A('<g id="street-ink" clip-path="url(#landclip)" fill="none" stroke="%s" stroke-linecap="round">'%INK)
for g in segs:
    A(f'<path d="M{g["a"][0]:.1f} {g["a"][1]:.1f}L{g["b"][0]:.1f} {g["b"][1]:.1f}" '
      f'stroke-width="{g["w"]+1.6:.1f}" stroke-opacity="0.55"/>')
A('</g>')
A('<g id="street-fill" clip-path="url(#landclip)" fill="none" stroke="%s" stroke-linecap="round">'%ROAD)
for g in segs:
    A(f'<path d="M{g["a"][0]:.1f} {g["a"][1]:.1f}L{g["b"][0]:.1f} {g["b"][1]:.1f}" '
      f'stroke-width="{g["w"]:.1f}"/>')
A('</g>')

# ---------- water works: piers, slips, moles ----------
A('<g id="waterworks" filter="url(#sg)">')
for p in W['piers']+W['slips']:
    A(f'<path d="M{p["a"][0]} {p["a"][1]}L{p["b"][0]} {p["b"][1]}" stroke="{INK}" '
      f'stroke-width="{p["w"]+1.8}" stroke-opacity="0.85" stroke-linecap="butt"/>')
    A(f'<path d="M{p["a"][0]} {p["a"][1]}L{p["b"][0]} {p["b"][1]}" stroke="#dfc9a0" '
      f'stroke-width="{p["w"]}" stroke-linecap="butt"/>')
for m in (W['moleN'],W['moleS']):
    pts=[tuple(q) for q in m]
    A(f'<path d="{dpath(pts)}" fill="none" stroke="{INK}" stroke-width="15" stroke-linecap="round" stroke-opacity="0.9"/>')
    A(f'<path d="{dpath(pts)}" fill="none" stroke="#d9c69f" stroke-width="10" stroke-linecap="round"/>')
# the boom chain
nA=W['moleN'][-1]; nB=W['moleS'][-1]
A(f'<path d="M{nA[0]} {nA[1]} Q{(nA[0]+nB[0])/2-26:.0f} {(nA[1]+nB[1])/2:.0f} {nB[0]} {nB[1]}" '
  f'fill="none" stroke="{WATERINK}" stroke-width="3.4" stroke-dasharray="9 7" opacity="0.75"/>')
A('</g>')

# ---------- buildings ----------
KINDFILL={"house":BLDG,"warehouse":"#ad8f60","shed":"#b39668","works":"#96794f",
          "civic":"#a68d61","guild":"#a68d61","temple":"#b09765","military":"#8f7649",
          "market":"#b09765","store":"#a3855c","tower":"#8f7649","water":"#95b2bd",
          "inn":"#b79463","tavern":"#b79463","shop":"#b49261","service":"#b49261",
          "hidden":"#b09madeup","shrine":"#c4ad80"}
def bpoly(b):
    r=math.radians(b['rot']); c,s=math.cos(r),math.sin(r); hw,hd=b['w']/2,b['d']/2
    return [(b['x']+c*dx-s*dy, b['y']+s*dx+c*dy) for dx,dy in ((-hw,-hd),(hw,-hd),(hw,hd),(-hw,hd))]
A('<g id="buildings" filter="url(#sg)" stroke="%s" stroke-linejoin="round">'%INK)
KEYED={l['id'] for l in W['locations']}
for b in W['buildings']:
    if b.get('id') in KEYED: continue
    f=KINDFILL.get(b['kind'],BLDG)
    if f.startswith("#b09m"): f=BLDG2
    A(f'<path d="{dpath(bpoly(b),True)}" fill="{f}" stroke-width="0.9" stroke-opacity="0.95"/>')
A('</g>')
# keyed buildings drawn heavier, on top
A('<g id="keyed" filter="url(#sg)" stroke="%s" stroke-linejoin="round">'%INK)
for b in W['buildings']:
    if b.get('id') not in KEYED: continue
    A(f'<path d="{dpath(bpoly(b),True)}" fill="#6f5730" stroke-width="2.2"/>')
A('</g>')

# ---------- walls ----------
cur=[tuple(p) for p in W['curtain']]
A('<g id="walls" filter="url(#sg)">')
A(f'<path d="{dpath(cur)}" fill="none" stroke="{INK}" stroke-width="19" stroke-linejoin="round" stroke-linecap="round"/>')
A(f'<path d="{dpath(cur)}" fill="none" stroke="#c3a878" stroke-width="12.5" stroke-linejoin="round" stroke-linecap="round"/>')
A(f'<path d="{dpath(cur)}" fill="none" stroke="{INK}" stroke-width="1.1" stroke-opacity="0.5"/>')
# merlons along the outer face
for i in range(0,len(cur)-1,2):
    x1,y1=cur[i]; x2,y2=cur[i+1]
    a=math.atan2(y2-y1,x2-x1); nx,ny=-math.sin(a),math.cos(a)
    A(f'<rect x="{x1+nx*7.4-2.6:.1f}" y="{y1+ny*7.4-2.6:.1f}" width="5.2" height="5.2" '
      f'fill="{INK}" opacity="0.8" transform="rotate({math.degrees(a):.0f} {x1+nx*7.4:.1f} {y1+ny*7.4:.1f})"/>')
for tx,ty in W['towers']:
    A(f'<circle cx="{tx}" cy="{ty}" r="15" fill="#c3a878" stroke="{INK}" stroke-width="3.0"/>')
    A(f'<circle cx="{tx}" cy="{ty}" r="5" fill="none" stroke="{INK}" stroke-width="1.2" opacity="0.6"/>')
for g in W['gates']:
    r=17 if g['kind']=="great" else 14
    A(f'<rect x="{g["x"]-r}" y="{g["y"]-r*0.72:.0f}" width="{r*2}" height="{r*1.44:.0f}" rx="3" '
      f'fill="#c3a878" stroke="{INK}" stroke-width="3.2"/>')
    A(f'<path d="M{g["x"]-r+4} {g["y"]}L{g["x"]+r-4} {g["y"]}" stroke="{INK}" stroke-width="2" opacity="0.7"/>')
A('</g>')

# ---------- label engine ----------
placed=[]
def tw(t,size,ls=0.0): return len(t)*size*0.505+max(0,len(t)-1)*ls
def fits(x,y,w,h,pad=2.0):
    for a,b,c,d in placed:
        if x-pad<a+c and x+w+pad>a and y-pad<b+d and y+h+pad>b: return False
    return True
def label(t,x,y,size,*,anchor="middle",fill=INK,weight="normal",style="normal",
          ls=0.0,opacity=1.0,halo=4.0,force=False,caps=False,dy=0.0):
    t2=t.upper() if caps else t
    w=tw(t2,size,ls); h=size*1.06
    ax={"middle":x-w/2,"start":x,"end":x-w}[anchor]; ay=y+dy-size*0.78
    if not force and not fits(ax,ay,w,h): return False
    placed.append((ax,ay,w,h))
    common=(f'x="{ax:.1f}" y="{y+dy:.1f}" font-size="{size}" text-anchor="start" '
            f'font-weight="{weight}" font-style="{style}" letter-spacing="{ls}"')
    A(f'<text {common} fill="none" stroke="{PAPER}" stroke-width="{halo}" stroke-opacity="0.92" '
      f'stroke-linejoin="round">{esc(t2)}</text>')
    A(f'<text {common} fill="{fill}" opacity="{opacity}">{esc(t2)}</text>')
    return True

# street names along their polylines
A('<g id="street-names" font-size="15" font-style="italic">')
defs=[]
for i,st in enumerate(W['streets']):
    pts=[tuple(p) for p in st['pts']]
    if len(pts)<6: continue
    if pts[-1][0] < pts[0][0]: pts=pts[::-1]
    defs.append(f'<path id="sn{i}" d="{dpath(chaikin(pts,1,False))}"/>')
    xs=[p[0] for p in pts]; ys=[p[1] for p in pts]
    placed.append((min(xs),min(ys)-9,max(xs)-min(xs),max(ys)-min(ys)+18))
    tp=f'<textPath href="#sn{i}" xlink:href="#sn{i}" startOffset="50%" text-anchor="middle">{esc(st["name"])}</textPath>'
    A(f'<text fill="none" stroke="{PAPER}" stroke-width="4.5" stroke-opacity="0.9" stroke-linejoin="round">{tp}</text>')
    A(f'<text fill="{INK}">{tp}</text>')
A(f'<defs>{"".join(defs)}</defs></g>')

# plaza names
for p in W['plazas']:
    label(p['name'], p['x'], p['y']+4, 15, style="italic", fill=INK2, halo=4)

# ---------- numbered POI keys ----------
POIS=sorted(W['locations'], key=lambda l:(l['y'], l['x']))
KEYNUM={}
A('<g id="poi">')
for n,l in enumerate(POIS, start=1):
    KEYNUM[l['id']]=n
    A(f'<circle cx="{l["x"]}" cy="{l["y"]}" r="13" fill="{PAPER}" stroke="{INK}" stroke-width="2.2"/>')
    A(f'<text x="{l["x"]}" y="{l["y"]+5.4:.1f}" font-size="15" text-anchor="middle" '
      f'font-weight="bold" fill="{INK}">{n}</text>')
    placed.append((l['x']-14,l['y']-14,28,28))
A('</g>')

# district names last, big
A('<g id="district-names">')
for d in W['districts']:
    off = d['radius']*0.40
    lx, ly = d['centre'][0], d['centre'][1]-off
    size = 33
    if d['id']=="northhorn": lx,ly,size = 626, 588, 22
    if d['id']=="theboom":   lx,ly = 1010, 1000
    if d['id']=="slipways":  lx,ly = 706, 1298
    label(d['name'], lx, ly, size, caps=True, ls=5.0,
          fill=d['colour'], weight="bold", opacity=0.98, halo=8.5, force=True)
A('</g>')

# gate + tower names
for g in W['gates']:
    if g['kind']=="tower": continue
    label(g['name'], g['x'], g['y']+34, 15, weight="bold", fill=INK, halo=4.5)
label("North Boom Tower", 292, 782, 13, style="italic", fill=INK2, halo=4)
label("South Boom Tower", 292, 1128, 13, style="italic", fill=INK2, halo=4)
label("The Ythan Mere", 300, 300, 34, style="italic", fill=WATERINK, ls=5.0, opacity=0.75, force=True)
label("Waerhaven Road", 470, 1616, 16, style="italic", fill=INK2, halo=4)
label("to Venddock & Lathmere", 1560, 1786, 16, style="italic", fill=INK2, halo=4)
label("Duskhollow", 2030, 1690, 22, style="italic", fill="#5a6b4a", ls=2.0, halo=5)

# scale bar (metres)
bx,by=96,PH-96; bar=500.0
A(f'<g><rect x="{bx}" y="{by}" width="{bar}" height="10" fill="{PAPER}" stroke="{INK}" stroke-width="1.2"/>')
for k in range(5):
    if k%2==0: A(f'<rect x="{bx+bar*k/5:.0f}" y="{by}" width="{bar/5:.0f}" height="10" fill="{INK}"/>')
for k in range(6):
    A(f'<text x="{bx+bar*k/5:.0f}" y="{by-7}" font-size="13" text-anchor="middle" fill="{INK}">{k*100}</text>')
A(f'<text x="{bx+bar/2:.0f}" y="{by+26}" font-size="14" text-anchor="middle" fill="{INK}" '
  f'font-style="italic">metres</text></g>')

# compass
cx,cy,R=PW-150,246,72
A(f'<g transform="translate({cx},{cy})" opacity="0.9">')
A(f'<circle r="{R*0.92:.0f}" fill="none" stroke="{INK}" stroke-width="1.5" opacity="0.55"/>')
A(f'<circle r="{R*0.78:.0f}" fill="none" stroke="{INK}" stroke-width="0.8" opacity="0.4"/>')
for a,ln,wd in [(0,R*0.78,0.16),(90,R*0.78,0.16),(180,R*0.78,0.16),(270,R*0.78,0.16),
                (45,R*0.48,0.24),(135,R*0.48,0.24),(225,R*0.48,0.24),(315,R*0.48,0.24)]:
    rad=math.radians(a); s_,c_=math.sin(rad),-math.cos(rad)
    A(f'<path d="M{s_*ln:.1f} {c_*ln:.1f} L{-c_*ln*wd:.1f} {s_*ln*wd:.1f} L0 0 Z" fill="{INK}" opacity="0.95"/>')
    A(f'<path d="M{s_*ln:.1f} {c_*ln:.1f} L{c_*ln*wd:.1f} {-s_*ln*wd:.1f} L0 0 Z" fill="{INK}" opacity="0.38"/>')
A(f'<circle r="4.5" fill="{PAPER}" stroke="{INK}" stroke-width="1.2"/>')
for lt,a in (("N",0),("E",90),("S",180),("W",270)):
    rad=math.radians(a)
    A(f'<text x="{math.sin(rad)*R*1.2:.1f}" y="{-math.cos(rad)*R*1.2+7:.1f}" font-size="19" '
      f'text-anchor="middle" fill="{INK}" font-weight="bold">{lt}</text>')
A('</g>')
A('</g>')   # end plan

# ================= legend panel =================
LX=M+PW+34
A(f'<g id="legend" transform="translate({LX},{M})">')
A(f'<rect x="0" y="0" width="{LEG-34}" height="{PH}" rx="12" fill="{PAPER}" fill-opacity="0.9" '
  f'stroke="{INK}" stroke-width="2.4"/>')
A(f'<rect x="9" y="9" width="{LEG-52}" height="{PH-18}" rx="8" fill="none" stroke="{INK}" '
  f'stroke-width="0.9" opacity="0.55"/>')
t="WAERHAVEN"
A(f'<text x="{(LEG-34)/2-tw(t,40,6)/2:.0f}" y="66" font-size="40" fill="{INK}" font-weight="bold" '
  f'letter-spacing="6">{t}</text>')
t2="Second City of the Ythan League"
A(f'<text x="{(LEG-34)/2-tw(t2,15,1.6)/2:.0f}" y="92" font-size="15" fill="{INK2}" '
  f'font-style="italic" letter-spacing="1.6">{t2}</text>')
t3=f"{W['meta']['population']:,} souls  ·  {len(W['buildings']):,} roofs  ·  {len(W['districts'])} wards"
A(f'<text x="{(LEG-34)/2-tw(t3,13,1.1)/2:.0f}" y="114" font-size="13" fill="{INK2}" letter-spacing="1.1">{t3}</text>')
A(f'<path d="M40 130 H{LEG-74}" stroke="{INK}" stroke-width="1" opacity="0.5"/>')

y=160
A(f'<text x="26" y="{y}" font-size="14" fill="{INK}" font-weight="bold" letter-spacing="2.6">THE WARDS</text>')
y+=14
for d in W['districts']:
    y+=22
    A(f'<rect x="26" y="{y-11}" width="13" height="13" fill="{d["colour"]}" fill-opacity="0.65" '
      f'stroke="{INK}" stroke-width="0.8"/>')
    A(f'<text x="48" y="{y}" font-size="13.5" fill="{INK}" font-weight="bold">{esc(d["name"])}</text>')
    A(f'<text x="{LEG-60}" y="{y}" font-size="11.5" fill="{INK2}" text-anchor="end" '
      f'font-style="italic">{esc(d["sub"])}</text>')
y+=30
A(f'<path d="M40 {y} H{LEG-74}" stroke="{INK}" stroke-width="1" opacity="0.5"/>')
y+=28
A(f'<text x="26" y="{y}" font-size="14" fill="{INK}" font-weight="bold" letter-spacing="2.6">PLACES OF NOTE</text>')
y+=10
col_h=math.ceil(len(POIS)/2)
startY=y
for idx,l in enumerate(POIS):
    col=idx//col_h; row=idx%col_h
    xx=26+col*((LEG-70)/2); yy=startY+20+row*20.4
    A(f'<circle cx="{xx+8}" cy="{yy-4.4}" r="8.6" fill="none" stroke="{INK}" stroke-width="1.3"/>')
    A(f'<text x="{xx+8}" y="{yy-0.6}" font-size="10.5" text-anchor="middle" fill="{INK}" '
      f'font-weight="bold">{KEYNUM[l["id"]]}</text>')
    nm=l['name']
    if tw(nm,12.4)>((LEG-70)/2-30): nm=nm[:int(((LEG-70)/2-30)/(12.4*0.505))-1]+"…"
    A(f'<text x="{xx+22}" y="{yy}" font-size="12.4" fill="{INK}">{esc(nm)}</text>')
y=startY+20+col_h*20.4+16
A(f'<path d="M40 {y} H{LEG-74}" stroke="{INK}" stroke-width="1" opacity="0.5"/>')
y+=26
A(f'<text x="26" y="{y}" font-size="14" fill="{INK}" font-weight="bold" letter-spacing="2.6">THE POWERS</text>')
for f in W['factions']:
    y+=21
    A(f'<text x="26" y="{y}" font-size="12.6" fill="{INK}">{esc(f["name"])}</text>')
    A(f'<text x="{LEG-60}" y="{y}" font-size="11" fill="{INK2}" text-anchor="end">'
      f'{"◆"*f["power"]}{"◇"*(5-f["power"])}</text>')
y+=34
for ln in ["No river reaches Waerhaven. Every log that becomes","a hull is hauled overland by ox-road from Duskhollow.",
           "","The chain boom closes the harbour in four minutes.","It has been raised in anger twice in ninety years."]:
    A(f'<text x="26" y="{y}" font-size="12" fill="{INK2}" font-style="italic">{esc(ln)}</text>'); y+=17
A('</g>')

# frame + vignette
A(f'<rect width="{CW}" height="{CH}" fill="url(#vig)" pointer-events="none"/>')
A(f'<rect x="{M*0.42:.0f}" y="{M*0.42:.0f}" width="{CW-M*0.84:.0f}" height="{CH-M*0.84:.0f}" '
  f'fill="none" stroke="{INK}" stroke-width="3.4" opacity="0.85"/>')
A(f'<rect x="{M*0.72:.0f}" y="{M*0.72:.0f}" width="{CW-M*1.44:.0f}" height="{CH-M*1.44:.0f}" '
  f'fill="none" stroke="{INK}" stroke-width="1.1" opacity="0.6"/>')
A('</svg>')

svg="\n".join(S)
open(O+'waerhaven-map.svg','w').write(svg)
# write key numbers back into the json so the doc and explorer agree
for l in W['locations']: l['key']=KEYNUM[l['id']]
W['meta']['mapOffset']=[M,M]; W['meta']['mapSize']=[CW,CH]
json.dump(W, open(O+'waerhaven.json','w'), indent=1)
print("waerhaven-map.svg", round(len(svg)/1024),"KB   canvas",CW,"x",CH,
      "  buildings",len(W['buildings']),"  keys",len(POIS))
