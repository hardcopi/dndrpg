"""Waerhaven stage 2: streets, plazas, quays and building footprints."""
import numpy as np, json, math
from collections import deque, defaultdict
D='/sessions/dazzling-jolly-pasteur/work/'
geo=json.load(open(D+'waer_geo.json'))
land=np.load(D+'waer_land.npy'); Hm=np.load(D+'waer_h.npy')
CW,CH=geo['canvas']; GS=4; gh,gw=land.shape
rng=np.random.default_rng(9021)
shore=[tuple(p) for p in geo['shore']]; curtain=[tuple(p) for p in geo['curtain']]
DIS=geo['districts']; GATES=geo['gates']

# ---------- distance to water (metres) ----------
dw=np.full((gh,gw),9999,np.int32); q=deque()
for j in range(gh):
    for i in range(gw):
        if not land[j,i]: dw[j,i]=0; q.append((j,i))
while q:
    j,i=q.popleft()
    for dj,di in ((1,0),(-1,0),(0,1),(0,-1)):
        a,b=j+dj,i+di
        if 0<=a<gh and 0<=b<gw and dw[a,b]>dw[j,i]+1:
            dw[a,b]=dw[j,i]+1; q.append((a,b))
dwm = dw*GS
def water_dist(x,y):
    j=int(np.clip(y/GS,0,gh-1)); i=int(np.clip(x/GS,0,gw-1))
    return float(dwm[j,i])
def on_land(x,y,margin=0.0):
    return water_dist(x,y) > margin
def snap(x,y,margin=14.0,maxr=190):
    """nudge an authored point onto land with clearance (my hand coords are approximate)"""
    if water_dist(x,y) >= margin: return (x,y)
    best=None
    for r in range(4,maxr,4):
        for k in range(0,360,6):
            a=math.radians(k); nx,ny=x+math.cos(a)*r, y+math.sin(a)*r
            if 0<nx<CW and 0<ny<CH and water_dist(nx,ny)>=margin:
                d=(nx-x)**2+(ny-y)**2
                if best is None or d<best[0]: best=(d,nx,ny)
        if best: return (best[1],best[2])
    return (x,y)

# ---------- the curtain as a barrier, with holes at the gates ----------
def seg_pt_dist(px,py,x1,y1,x2,y2):
    dx,dy=x2-x1,y2-y1; L2=dx*dx+dy*dy
    t=0.0 if L2==0 else max(0,min(1,((px-x1)*dx+(py-y1)*dy)/L2))
    return math.hypot(px-(x1+t*dx), py-(y1+t*dy))
def blocked(x,y):
    for k in range(len(curtain)-1):
        if seg_pt_dist(x,y,*curtain[k],*curtain[k+1]) < 9:
            for g in GATES:
                if (x-g['x'])**2+(y-g['y'])**2 < 34**2: return False
            return True
    return False

# ---------- district lookup ----------
def district_score(x,y):
    best=None
    for d in DIS:
        s=math.hypot(x-d['cx'],y-d['cy'])/d['r']
        if best is None or s<best[0]: best=(s,d['id'])
    return best
def district_at(x,y): return district_score(x,y)[1]
SETTLED=1.32
def settled(x,y): return district_score(x,y)[0] <= SETTLED

# ---------- street network ----------
CELL=32
class Net:
    def __init__(s):
        s.segs=[]; s.hash=defaultdict(list)
    def key(s,x,y): return (int(x//CELL),int(y//CELL))
    def add(s,a,b,kind,w,name=None):
        i=len(s.segs); s.segs.append(dict(a=a,b=b,kind=kind,w=w,name=name))
        x1,y1=a; x2,y2=b
        n=max(1,int(math.hypot(x2-x1,y2-y1)//CELL)+1)
        for t in range(n+1):
            s.hash[s.key(x1+(x2-x1)*t/n, y1+(y2-y1)*t/n)].append(i)
        return i
    def near(s,x,y,r=1):
        k=s.key(x,y); out=set()
        for a in range(-r,r+1):
            for b in range(-r,r+1): out.update(s.hash[(k[0]+a,k[1]+b)])
        return out
    def dist(s,x,y,ignore=()):
        best=1e9
        for i in s.near(x,y):
            if i in ignore: continue
            g=s.segs[i]
            d=seg_pt_dist(x,y,*g['a'],*g['b'])
            if d<best: best=d
        return best
NET=Net()

def smooth(pts,it=2):
    p=list(pts)
    for _ in range(it):
        o=[p[0]]
        for i in range(len(p)-1):
            a,b=p[i],p[i+1]
            o.append((a[0]*.7+b[0]*.3,a[1]*.7+b[1]*.3))
            o.append((a[0]*.3+b[0]*.7,a[1]*.3+b[1]*.7))
        o.append(p[-1]); p=o
    return p

NAMED=[]
def primary(name, pts, kind="primary", w=9.0, margin=13.0):
    pts=[snap(x,y,margin) for x,y in pts]
    p=smooth(pts,2)
    p=[snap(x,y,margin*0.8) for x,y in p]
    for i in range(len(p)-1): NET.add(p[i],p[i+1],kind,w,name)
    NAMED.append(dict(name=name,kind=kind,pts=[[round(a,1),round(b,1)] for a,b in p],w=w))

# --- the authored skeleton -------------------------------------------------
primary("The Gate Way",   [(1122,1400),(1128,1290),(1146,1186),(1168,1074),(1192,960),
                           (1208,862),(1222,742),(1230,632),(1234,520),(1268,410),(1318,326)], w=11)
primary("The Strand",     [(700,1320),(790,1276),(872,1232),(930,1170),(950,1080),(948,990),
                           (918,904),(866,846),(800,806),(742,772),(716,700),(760,614),
                           (852,556),(960,514),(1080,492),(1170,486)], w=10)
primary("Highstrand Way", [(880,900),(986,872),(1096,846),(1208,834),(1330,846),(1452,876),
                           (1566,916),(1668,962),(1758,1000)], w=9)
primary("Tarrow Road",    [(1712,1360),(1704,1256),(1716,1160),(1756,1074),(1806,1000),
                           (1826,912),(1810,822),(1780,760)], w=8.5)
primary("The Girdle",     [(470,1288),(620,1332),(790,1350),(960,1352),(1130,1344),
                           (1300,1330),(1470,1318),(1640,1308),(1810,1310),(1960,1330)], w=8)
primary("The Ropewalk",   [(556,1306),(700,1322),(848,1332),(980,1338)], kind="ropewalk", w=6)
primary("Cooper's Row",   [(960,1216),(1060,1240),(1170,1252),(1280,1246),(1382,1226)], w=7)
primary("Netmender's Lane",[(1170,486),(1266,516),(1350,566),(1414,634),(1452,714)], w=7)
primary("The Tar Steps",  [(1806,1000),(1790,900),(1772,812),(1766,754)], kind="steps", w=5)
primary("Wardens' Road",  [(1122,1400),(1176,1500),(1256,1596),(1350,1676),(1444,1748)], w=8)
primary("Timber Road",    [(1080,1420),(966,1502),(852,1580),(742,1648)], w=8)
primary("The Quay",       [(912,1186),(934,1104),(942,1020),(928,940),(898,880)], kind="quay", w=13)

# --- plazas (no buildings inside) ------------------------------------------
PLAZAS=[
 dict(id="hallowmarket", name="Hallowmarket",  x=1150,y=1176, r=76),
 dict(id="mootyard",     name="The Moot Yard", x=1208,y=838,  r=62),
 dict(id="fishsquare",   name="Old Waer Square",x=1234,y=524, r=52),
 dict(id="boomhead",     name="Boomhead",      x=986, y=1136, r=58),
 dict(id="slipyard",     name="The Great Slip",x=772, y=1236, r=64),
]

# --- procedural growth -----------------------------------------------------
DENS={"northhorn":(0.55,5.0),"oldwaer":(0.95,3.6),"highstrand":(0.62,5.0),"theboom":(0.92,4.4),
      "slipways":(0.86,4.6),"tarrow":(0.8,4.2),"hallowgate":(0.62,5.0),
      "wardenswatch":(0.46,6.0)}
def in_plaza(x,y,pad=0):
    return any((x-p['x'])**2+(y-p['y'])**2 < (p['r']+pad)**2 for p in PLAZAS)

def grow(x,y,ang,kind,w,budget,minsep):
    pts=[(x,y)]; step=15.0
    for _step in range(int(budget/step)):
        ang += float(rng.normal(0,0.11))
        nx,ny = x+math.cos(ang)*step, y+math.sin(ang)*step
        if not (0<nx<CW and 0<ny<CH): break
        if not on_land(nx,ny,12): break
        if blocked(nx,ny): break
        if not settled(nx,ny): break
        d=NET.dist(nx,ny)
        if _step >= 2 and d < minsep:
            if len(pts)>1: pts.append((nx,ny))
            break
        pts.append((nx,ny)); x,y,=nx,ny
    if len(pts)<3: return None
    for i in range(len(pts)-1): NET.add(pts[i],pts[i+1],kind,w)
    return pts

def seed_from_network(count, kind, w, budget, minsep, parents=("primary",)):
    made=0; tries=0
    while made<count and tries<count*40:
        tries+=1
        g=NET.segs[int(rng.integers(len(NET.segs)))]
        if g['kind'] not in parents: continue
        t=float(rng.random())
        x=g['a'][0]+(g['b'][0]-g['a'][0])*t; y=g['a'][1]+(g['b'][1]-g['a'][1])*t
        base=math.atan2(g['b'][1]-g['a'][1], g['b'][0]-g['a'][0])
        ang=base+(math.pi/2 if rng.random()<0.5 else -math.pi/2)+float(rng.normal(0,0.34))
        did=district_at(x,y); dens=DENS.get(did,(0.7,5.0))[0]
        if rng.random()>dens: continue
        if grow(x+math.cos(ang)*14, y+math.sin(ang)*14, ang, kind, w, budget, minsep):
            made+=1
    return made

print("secondary:", seed_from_network(150,"secondary",6.2,300,34,("primary",)))
print("tertiary :", seed_from_network(300,"tertiary",4.4,190,24,("primary","secondary")))
print("alleys   :", seed_from_network(420,"alley",3.0,120,17,("secondary","tertiary")))
print("segments :", len(NET.segs))

# ---------- landmark buildings (claim their footprint first) ----------
LANDMARKS=[
 # id, name, x, y, w, d, rot(deg), type
 ("leaguehall","The Wet Hall",1176,806,74,46,-6,"civic"),
 ("shipmoot","The Shipwrights' Moot",1272,880,62,40,4,"guild"),
 ("factorhouse","The Factor's House",1122,776,44,36,-8,"civic"),
 ("counting","The Counting Boom",982,1052,52,32,12,"guild"),
 ("greatcrane","The Great Crane",930,1128,22,22,0,"works"),
 ("drydock","The Dry Dock",706,1300,86,44,8,"works"),
 ("mastpond","The Mast Pond",576,1300,72,48,0,"water"),
 ("sawmill","The Ox-Mill",892,1298,58,34,4,"works"),
 ("templemere","The Temple of the Deep Mere",1298,616,56,40,10,"temple"),
 ("charthouse","The Charthouse",1330,742,40,30,-12,"guild"),
 ("watchkeep","Hallowgate Keep",1122,1330,58,42,0,"military"),
 ("boomforge","The Boom Forge",1782,1046,46,34,-6,"works"),
 ("pitchyard","The Pitch Yards",1858,952,72,52,8,"works"),
 ("lazarhouse","The Lazar House",1508,1596,44,32,6,"civic"),
 ("wardenhall","The Grey Hall",1330,1602,50,34,-4,"civic"),
 ("lighthouse","The Waer Light",1332,306,20,20,0,"tower"),
 ("fishhall","The Fish Hall",1180,556,44,30,6,"market"),
 ("gaol","The Tar Gaol",1746,1178,38,30,0,"military"),
 ("granary","The League Granary",1044,1234,64,40,-6,"store"),
 ("ropehouse","The Ropehouse",790,1372,120,20,3,"works"),
]
BUILD=[]
def obb(x,y,w,d,rot):
    c,s=math.cos(rot),math.sin(rot)
    hw,hd=w/2,d/2
    return [(x+c*dx-s*dy, y+s*dx+c*dy) for dx,dy in ((-hw,-hd),(hw,-hd),(hw,hd),(-hw,hd))]
def sat(p1,p2):
    for poly in (p1,p2):
        for i in range(len(poly)):
            x1,y1=poly[i]; x2,y2=poly[(i+1)%len(poly)]
            ax,ay=-(y2-y1),(x2-x1); L=math.hypot(ax,ay)+1e-9; ax,ay=ax/L,ay/L
            a1=[px*ax+py*ay for px,py in p1]; a2=[px*ax+py*ay for px,py in p2]
            if max(a1)<min(a2) or max(a2)<min(a1): return False
    return True
BH=defaultdict(list)
def bkey(x,y): return (int(x//CELL),int(y//CELL))
def place(x,y,w,d,rot,kind,name=None,pid=None,force=False,street_gap=1.0):
    poly=obb(x,y,w,d,rot)
    for vx,vy in poly:
        if not on_land(vx,vy,4): return None
        if blocked(vx,vy): return None
    if not force:
        if not settled(x,y): return None
        if in_plaza(x,y,max(w,d)*0.4): return None
        if NET.dist(x,y) < max(w,d)*0.5 + street_gap: return None
    k=bkey(x,y); cand=[]
    for a in range(-1,2):
        for b in range(-1,2): cand += BH[(k[0]+a,k[1]+b)]
    for j in cand:
        if sat(poly, BUILD[j]['poly']): return None
    i=len(BUILD)
    BUILD.append(dict(x=round(x,1),y=round(y,1),w=round(w,1),d=round(d,1),
                      rot=round(math.degrees(rot),1),kind=kind,name=name,id=pid,
                      district=district_at(x,y),poly=poly))
    BH[k].append(i)
    return i

for pid,name,x,y,w,d,rot,kind in LANDMARKS:
    sx,sy = snap(x,y,max(w,d)*0.5+8)
    r=place(sx,sy,w,d,math.radians(rot),kind,name,pid,force=True)
    if r is None:
        for rr in range(10,150,8):
            done=False
            for k in range(0,360,15):
                a=math.radians(k)
                r=place(sx+math.cos(a)*rr, sy+math.sin(a)*rr, w,d,math.radians(rot),
                        kind,name,pid,force=True)
                if r is not None: done=True; break
            if done: break
    if r is None: print("  !! landmark rejected:",name)

# ---------- warehouse and shed rows along the working waterfront ----------
def named_street(nm):
    for n in NAMED:
        if n['name']==nm: return [tuple(p) for p in n['pts']]
    return []
def row_both(pts, w, d, gap, kind, offset_extra=0.0):
    """try each side, keep the one the land actually allows"""
    a=row_along(pts, 1, w, d, gap, kind, offset_extra)
    b=row_along(pts, -1, w, d, gap, kind, offset_extra)
    return a+b
def row_along(pts, side, w, d, gap, kind, offset_extra=0.0):
    made=0; acc=0.0
    for i in range(len(pts)-1):
        x1,y1=pts[i]; x2,y2=pts[i+1]
        L=math.hypot(x2-x1,y2-y1)
        if L<1: continue
        ang=math.atan2(y2-y1,x2-x1); nx_,ny_=-math.sin(ang),math.cos(ang)
        t=0.0
        while t<L:
            if acc<=0:
                bw=w*float(rng.uniform(0.82,1.18)); bd=d*float(rng.uniform(0.85,1.15))
                off=7+bd/2+offset_extra
                cx=x1+(x2-x1)*t/L+nx_*off*side; cy=y1+(y2-y1)*t/L+ny_*off*side
                if place(cx,cy,bw,bd,ang+float(rng.normal(0,0.03)),kind,force=True): made+=1
                acc=bw+gap
            step=min(4.0,L-t); t+=step; acc-=step
    return made
q=named_street("The Quay")
print("quay warehouses:", row_both(q, 26, 17, 5, "warehouse"))
print("quay back row:", row_both(q, 22, 15, 6, "warehouse", offset_extra=26))
st=named_street("The Strand")
print("strand stores:", row_both([p for p in st if 1150<p[1]<1330], 20, 14, 6, "warehouse"))
sl=named_street("The Ropewalk")
print("boat sheds:", row_both(sl, 24, 16, 7, "shed"))
print("slip sheds:", row_both([(640,1206),(700,1210),(760,1206),(820,1196),(876,1182)], 22, 18, 8, "shed"))

# ---------- fill: buildings lining every street ----------
SIZE={"northhorn":(5,10,5,9),"oldwaer":(5,10,5,9),"highstrand":(11,19,9,15),"theboom":(13,26,10,18),
      "slipways":(12,24,9,17),"tarrow":(8,17,7,14),"hallowgate":(9,18,8,14),
      "wardenswatch":(4,9,4,8)}
placed=0
segs=sorted(range(len(NET.segs)), key=lambda i:-NET.segs[i]['w'])
for si in segs:
    g=NET.segs[si]
    x1,y1=g['a']; x2,y2=g['b']
    L=math.hypot(x2-x1,y2-y1)
    if L<6: continue
    ang=math.atan2(y2-y1,x2-x1)
    nx_,ny_=-math.sin(ang),math.cos(ang)
    did=district_at((x1+x2)/2,(y1+y2)/2)
    lo,hi,dlo,dhi=SIZE.get(did,(8,16,7,13))
    _,spacing=DENS.get(did,(0.7,5.0))
    t=float(rng.random())*8
    while t<L:
        px_=x1+(x2-x1)*t/L; py_=y1+(y2-y1)*t/L
        for side in (1,-1):
            bw=float(rng.uniform(lo,hi)); bd=float(rng.uniform(dlo,dhi))
            off=g['w']/2 + bd/2 + float(rng.uniform(0.6,3.4))
            cx=px_+nx_*off*side; cy=py_+ny_*off*side
            rot=ang+float(rng.normal(0,0.05))
            if place(cx,cy,bw,bd,rot,"house"): placed+=1
        t += float(rng.uniform(lo*0.55, hi*0.75)) + spacing
print("buildings:", len(BUILD), "(fill", placed, ")")

# ---------- quays: piers into the harbour ----------
def pier_from(bx,by,ang,ln,wd):
    bx,by = snap(bx,by,6)
    # walk backwards until we are on land, then run out into the water
    for _ in range(60):
        if water_dist(bx,by) >= 5: break
        bx-=math.cos(ang)*4; by-=math.sin(ang)*4
    ex,ey=bx+math.cos(ang)*ln, by+math.sin(ang)*ln
    return dict(a=[round(bx,1),round(by,1)],b=[round(ex,1),round(ey,1)],w=wd)
PIERS=[pier_from(*p) for p in
       [(905,1150,math.radians(184),88,13),(922,1064,math.radians(192),80,12),
        (924,986,math.radians(198),72,11),(898,910,math.radians(210),60,10),
        (770,1232,math.radians(126),66,12),(852,1210,math.radians(140),58,11)]]
# slipways: ramps into the water at the bay head
SLIPS=[pier_from(*p) for p in
       [(660,1252,math.radians(292),58,16),(706,1258,math.radians(288),58,16),
        (752,1256,math.radians(284),56,16),(798,1246,math.radians(278),54,16),
        (844,1232,math.radians(272),52,16)]]

for b in BUILD: b.pop('poly',None)
json.dump(dict(streets=[dict(a=[round(v,1) for v in s['a']],b=[round(v,1) for v in s['b']],
                             kind=s['kind'],w=s['w'],name=s['name']) for s in NET.segs],
               named=NAMED, buildings=BUILD, plazas=PLAZAS, piers=PIERS, slips=SLIPS),
          open(D+'waer_plan.json','w'))
print("districts:", {d['id']: sum(1 for b in BUILD if b['district']==d['id']) for d in DIS})

# ---------- preview ----------
from PIL import Image, ImageDraw
SC=0.5
im=Image.new('RGB',(int(CW*SC),int(CH*SC)),(96,124,140)); dr=ImageDraw.Draw(im)
for j in range(gh):
    for i in range(gw):
        if land[j,i]:
            dr.rectangle([i*GS*SC,j*GS*SC,(i+1)*GS*SC,(j+1)*GS*SC],fill=(226,214,186))
for p in PLAZAS:
    dr.ellipse([(p['x']-p['r'])*SC,(p['y']-p['r'])*SC,(p['x']+p['r'])*SC,(p['y']+p['r'])*SC],fill=(206,194,166))
for s in NET.segs:
    dr.line([(s['a'][0]*SC,s['a'][1]*SC),(s['b'][0]*SC,s['b'][1]*SC)],
            fill=(176,160,130),width=max(1,int(s['w']*SC)))
for b in BUILD:
    poly=obb(b['x'],b['y'],b['w'],b['d'],math.radians(b['rot']))
    col=(120,96,70) if b['kind']=='house' else (60,50,40)
    dr.polygon([(x*SC,y*SC) for x,y in poly],fill=col)
for pp in PIERS+SLIPS:
    dr.line([(pp['a'][0]*SC,pp['a'][1]*SC),(pp['b'][0]*SC,pp['b'][1]*SC)],
            fill=(150,120,80),width=max(2,int(pp['w']*SC)))
dr.line([(x*SC,y*SC) for x,y in shore],fill=(20,14,8),width=2)
dr.line([(x*SC,y*SC) for x,y in curtain],fill=(90,40,30),width=5)
im.save('/sessions/dazzling-jolly-pasteur/mnt/outputs/_waer_preview.png')
print("preview written")
