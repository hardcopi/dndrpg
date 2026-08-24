"""Waerhaven stage 1: headland geometry, harbour, walls, districts.
Canvas is 2400x1800 at 1 pixel = 1 metre, north = up.
The macro shape is taken from the world map: a promontory pointing north into the
Ythan Mere, water on west / north / east, deep forest approaching from the south."""
import numpy as np, json, math
rng = np.random.default_rng(4471)
CW, CH = 2400, 1800

def smoothpoly(pts, it=3, closed=True):
    p=list(pts)
    for _ in range(it):
        o=[];n=len(p)
        for i in range(n if closed else n-1):
            a=p[i];b=p[(i+1)%n]
            o.append((a[0]*.75+b[0]*.25,a[1]*.75+b[1]*.25))
            o.append((a[0]*.25+b[0]*.75,a[1]*.25+b[1]*.75))
        p=o
    return p

def wobble(pts, amp=7.0, freq=0.9, closed=True):
    """fractal jitter along the normal so the shore never reads as a spline"""
    n=len(pts); out=[]
    for i,(x,y) in enumerate(pts):
        a=pts[(i-1)%n]; b=pts[(i+1)%n]
        tx,ty=b[0]-a[0],b[1]-a[1]; L=math.hypot(tx,ty)+1e-9
        ph=i*freq
        d=(math.sin(ph*0.7)+math.sin(ph*1.9+1.1)*0.55+math.sin(ph*4.3+0.3)*0.3)*amp
        out.append((x-ty/L*d, y+tx/L*d))
    return out

# ---------------------------------------------------------------- the headland
# closed polygon, clockwise from the south-west corner
SHORE = [
 (-60,1860),(60,1690),(150,1560),(232,1430),(300,1330),(352,1246),
 # --- mouth of the harbour: south horn
 (396,1178),(430,1120),
 # --- into the bay (the water bites east)
 (520,1150),(620,1196),(720,1216),(806,1200),(872,1150),(902,1074),
 (900,986),(866,904),(806,846),(726,812),(640,802),(556,812),(486,838),
 # --- north horn of the harbour mouth
 (430,806),(404,742),
 # --- open west shore running up to the point
 (444,676),(520,600),(604,528),(700,462),(806,406),(918,360),(1030,322),
 (1128,292),(1208,258),
 # --- the point itself: a narrow angular spur
 (1276,240),(1330,248),(1370,278),(1400,324),(1428,384),(1462,450),
 # --- east shore running back south
 (1524,528),(1596,600),(1664,660),
 # --- the Tar Steps: a small working cove
 (1690,706),(1722,744),(1766,752),(1794,720),(1820,690),
 (1852,730),(1878,790),(1936,872),
 (1980,948),(2008,1036),(2022,1130),(2028,1228),(2032,1330),(2044,1440),
 (2072,1560),(2116,1690),(2170,1860),
]
shore = smoothpoly(wobble(smoothpoly(SHORE,1,False), 6.0, 1.0, False), 2, False)

# land mask (4 m per cell for speed)
GS = 4
gw, gh = CW//GS, CH//GS
yy, xx = np.mgrid[0:gh, 0:gw]
px, py = xx*GS+GS/2, yy*GS+GS/2
def inside_land(px, py):
    """point is land if it is south/inside of the shore polyline, i.e. below it"""
    m = np.ones(px.shape, bool)
    poly = shore + [(2400,2000),(-100,2000)]
    n=len(poly); inside=np.zeros(px.shape,bool)
    for i in range(n):
        x1,y1=poly[i]; x2,y2=poly[(i+1)%n]
        cond = ((y1>py)!=(y2>py))
        xint = (x2-x1)*(py-y1)/(y2-y1+1e-12)+x1
        inside ^= cond & (px < xint)
    return inside
land = inside_land(px, py)
print("land cells", int(land.sum()), "of", gw*gh, "=", round(land.sum()/(gw*gh)*100), "%")

# ---------------------------------------------------------------- relief
# a ridge runs up the spine of the headland; Highstrand sits on its crown
def ridge_h(x,y):
    h = 0.0
    for cx,cy,r,a in [(1180,760,520,26.0),(1340,520,420,17.0),(980,1080,430,12.0),
                      (1700,900,360,9.0),(760,1000,300,-3.0)]:
        h += a*math.exp(-(((x-cx)**2+(y-cy)**2)/(2*r*r)))
    return h
H = np.zeros((gh,gw))
for j in range(gh):
    for i in range(gw):
        H[j,i]=ridge_h(i*GS+2, j*GS+2)
H = np.where(land, H, 0.0)

# ---------------------------------------------------------------- the walls
# a landward curtain across the neck, plus a short sea-wall along the harbour
CURTAIN = [(392,1206),(470,1284),(560,1330),(668,1356),(792,1370),(920,1376),
           (1060,1378),(1200,1372),(1338,1362),(1470,1350),(1596,1340),
           (1716,1336),(1830,1340),(1932,1352),(2016,1372)]
curtain = smoothpoly(CURTAIN,2,False)
# harbour mole / boom towers
MOLE_N = smoothpoly([(404,742),(360,730),(318,742),(292,772),(288,812)],2,False)
MOLE_S = smoothpoly([(396,1178),(352,1178),(312,1160),(292,1128),(292,1090)],2,False)

GATES = [
  dict(id="hallowgate", name="Hallowgate",   x=1122, y=1376, kind="great",  faces=180),
  dict(id="tarrowgate", name="Tarrow Gate",  x=1712, y=1336, kind="lesser", faces=170),
  dict(id="slipgate",   name="The Slip Gate",x=676,  y=1356, kind="lesser", faces=200),
  dict(id="boomtowerN", name="North Boom Tower", x=292, y=800,  kind="tower", faces=270),
  dict(id="boomtowerS", name="South Boom Tower", x=292, y=1096, kind="tower", faces=270),
]
TOWERS = [(x,y) for x,y in
  [(392,1206),(560,1330),(792,1370),(920,1376),(1060,1378),(1200,1372),
   (1338,1362),(1470,1350),(1596,1340),(1830,1340),(2016,1372)]]

# ---------------------------------------------------------------- districts
DISTRICTS = [
 dict(id="oldwaer",   name="Old Waer",        cx=1230, cy=470,  r=310, tone="#8d6a4a",
      blurb="The original fishing village on the point; the oldest and poorest streets."),
 dict(id="highstrand",name="Highstrand",      cx=1232, cy=812,  r=300, tone="#7a6a3e",
      blurb="The crown of the ridge. League hall, guild houses, and money."),
 dict(id="theboom",   name="The Boom",        cx=958,  cy=1076, r=215, tone="#3f6f80",
      blurb="Quays, warehouses and the chain across the harbour mouth."),
 dict(id="slipways",  name="The Slipways",    cx=756,  cy=1268, r=235, tone="#5c6b4a",
      blurb="Building slips, dry docks, the mast pond and the ropewalk."),
 dict(id="tarrow",    name="Tarrow",          cx=1810, cy=1010, r=280, tone="#5a4230",
      blurb="Pitch, tar and oakum. Kept downwind, and kept outside the good streets."),
 dict(id="hallowgate",name="Hallowgate Ward", cx=1290, cy=1200, r=330, tone="#6b5236",
      blurb="Inside the landward gate: timber yards, carters, and the Watch."),
 dict(id="wardenswatch",name="The Wardenswatch",cx=1338,cy=1512, r=232, tone="#4f5f70",
      blurb="Extramural. Drennmark's people, living where the charter says they are not."),
 dict(id="northhorn", name="The North Horn",  cx=548,  cy=690,  r=136, tone="#6b7a86",
      blurb="The boom-tower outwork across the harbour mouth, and the fisher-huts under it."),
]

json.dump(dict(canvas=[CW,CH], shore=[[round(a,1),round(b,1)] for a,b in shore],
               curtain=[[round(a,1),round(b,1)] for a,b in curtain],
               moleN=[[round(a,1),round(b,1)] for a,b in MOLE_N],
               moleS=[[round(a,1),round(b,1)] for a,b in MOLE_S],
               gates=GATES, towers=TOWERS, districts=DISTRICTS),
          open('/sessions/dazzling-jolly-pasteur/work/waer_geo.json','w'))
np.save('/sessions/dazzling-jolly-pasteur/work/waer_land.npy', land)
np.save('/sessions/dazzling-jolly-pasteur/work/waer_h.npy', H)
print("shore pts", len(shore), "curtain pts", len(curtain))

# quick preview
from PIL import Image, ImageDraw
im=Image.new('RGB',(CW//2,CH//2),(70,100,120)); d=ImageDraw.Draw(im)
for j in range(gh):
    for i in range(gw):
        if land[j,i]:
            v=int(120+H[j,i]*3.4)
            d.rectangle([i*GS//2,j*GS//2,(i+1)*GS//2,(j+1)*GS//2],fill=(v,int(v*0.92),int(v*0.7)))
d.line([(x/2,y/2) for x,y in shore],fill=(30,20,10),width=2)
d.line([(x/2,y/2) for x,y in curtain],fill=(200,40,40),width=3)
for m in (MOLE_N,MOLE_S): d.line([(x/2,y/2) for x,y in m],fill=(120,60,20),width=3)
for g in GATES: d.ellipse([g['x']/2-6,g['y']/2-6,g['x']/2+6,g['y']/2+6],fill=(255,220,0))
for t in DISTRICTS:
    d.ellipse([t['cx']/2-7,t['cy']/2-7,t['cx']/2+7,t['cy']/2+7],outline=(255,255,255),width=2)
    d.text((t['cx']/2+10,t['cy']/2-6),t['name'],fill=(255,255,255))
im.save('/sessions/dazzling-jolly-pasteur/mnt/outputs/_waer_preview.png')
print("preview written")
