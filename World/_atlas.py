"""Sundermere atlas builder: names, roads, landmarks, and world.json export."""
import numpy as np, json, math, heapq
from collections import deque, defaultdict

D = '/sessions/dazzling-jolly-pasteur/work/'
elev=np.load(D+'elev.npy'); land=np.load(D+'land.npy'); acc=np.load(D+'acc.npy')
biome=np.load(D+'biome.npy'); nation=np.load(D+'nation.npy'); rel=np.load(D+'rel.npy')
dist=np.load(D+'dist.npy'); slope=np.load(D+'slope.npy'); lake=np.load(D+'lake.npy')
isea=np.load(D+'inlandsea.npy'); moist=np.load(D+'moist.npy')
raw=json.load(open(D+'world_raw.json')); coast=json.load(open(D+'coast.json'))
H,W = elev.shape
CW, CH = 2520, 1764          # canvas px  (6 px per grid cell)
SX, SY = CW/W, CH/H
rng = np.random.default_rng(1091)
def gx(x): return round(x*SX, 1)
def gy(y): return round(y*SY, 1)

# ============================ 1. THE REALMS ============================
REALMS = [
  dict(id="ardennia", name="Ardennia", full="The Ardent Marches of Ardennia", cap="Vantry",
       gov="Feudal kingdom under a boy-king and a fractious regency",
       ruler="King Aldric IV, aged eleven; Lord Regent Sevrin Maur",
       people="Humans, with old-blood halfling farm clans in the downs",
       hue="#8c3a3a",
       hook="The last unbroken fragment of the Ardent Empire. Its armies are still the finest on the continent and its treasury is empty."),
  dict(id="thrail", name="Thrail", full="The Free Cities of the Thrail", cap="Thrailgate",
       gov="Merchant concord of seven chartered cities; no standing army",
       ruler="The Seven Syndics, elected annually and bribed continuously",
       people="Everyone. Thrail asks no one's blood, only their tariff.",
       hue="#b8862b",
       hook="Whoever holds the isthmus holds the continent. Thrail holds it by owing everyone money."),
  dict(id="ordrane", name="Ordrane", full="The Kingdom of Ordrane", cap="Corrowmarch",
       gov="Centralised kingdom with a powerful knightly order, the Iron Wake",
       ruler="Queen Maerwyn the Younger",
       people="Humans; large half-orc population in the border marches",
       hue="#2f6b4f",
       hook="The rising power. Ordrane has spent thirty years buying what Ardennia inherited, and is nearly finished."),
  dict(id="sylvarien", name="Sylvarien", full="The Vale-Realm of Sylvarien", cap="Aelthyr",
       gov="Hereditary council of nine vale-houses",
       ruler="Coronal Ithren Vaelas, in the eleventh century of her reign",
       people="Elves, half-elves, and the wood-gnomes of the lower vales",
       hue="#4a6f8c",
       hook="Sylvarien remembers the cataclysm that made the Sundering. It has never told anyone what it was."),
  dict(id="ythan", name="The Ythan League", full="The Free League of the Ythan Mere", cap="Lathmere",
       gov="Trade league of lake-ports; a fleet instead of an army",
       ruler="Mistress of the Mere, Halvia Corr",
       people="Humans and river-folk; a large gnomish artificer quarter in Lathmere",
       hue="#3f7f96",
       hook="Controls every barge on the inland sea. Its charter is older than any crown and it enforces it with grain prices."),
  dict(id="drennmark", name="Drennmark", full="The Old Kingdom of Drennmark", cap="Ashen Drenn",
       gov="Waning monarchy; real power sits with the Grey Wardens of the pine-marches",
       ruler="King Torvald the Sleepless",
       people="Humans of the northern forest; scattered lycanthropic bloodlines",
       hue="#5d6b4a",
       hook="Something in the Wyrdpines has been moving south for six years. The Wardens have stopped reporting it."),
  dict(id="karrundeep", name="Karrun-Deep", full="The Hold-Kingdoms of Karrun-Deep", cap="Zharrund",
       gov="Confederation of nine delving holds; each hold sovereign below ground",
       ruler="The Nine Thanes, sitting as the Deepmoot",
       people="Dwarves; human surface-tenants in the valley towns",
       hue="#7a5230",
       hook="Three of the nine holds have gone silent. The Deepmoot has voted, six to nothing, not to discuss it."),
  dict(id="hjaldmark", name="Hjaldmark", full="The Clanlands of Hjaldmark", cap="Vorrsgard",
       gov="Clan-holds bound by oath to a High Jarl; oaths renewed each midwinter",
       ruler="High Jarl Sigrunn Ash-Hand",
       people="Humans of the frozen coasts; goliath clans in the high fells",
       hue="#4f5f70",
       hook="Hjaldmark longships have started raiding Ardennia again. Not for gold. They are looking for something."),
  dict(id="sarathal", name="Sarathal", full="The Sun-Throne of Sarathal", cap="Zir Sarath",
       gov="Divine monarchy; the Sun-Throne claims descent from a dead god",
       ruler="Sunfather Amrahd IX",
       people="Humans of the deep south; dragonborn caste of the Ember Legion",
       hue="#a8792b",
       hook="Sarathal's god has been dead a thousand years. Two years ago, its temples began answering prayers again."),
]
assert len(REALMS)==9

# ============================ 2. NAME GENERATION ============================
CULT = {
 "ardennia": (["Cae","Van","Mor","Bel","Dun","Ald","Ker","Har","Pen","Tre","Cor","Lan","Bri","Marl","Gwen","Rhe","Sel","Aub"],
              ["try","don","mere","wick","ford","caster","holt","bury","stowe","ridge","combe","hallow","gate","march","field","worth"]),
 "thrail":   (["Thrail","Sal","Cant","Vero","Miren","Osp","Cal","Tam","Bra","Serr","Quill","Nave","Port","Lom","Estr"],
              ["gate","port","haven","quay","stead","hold","reach","cross","landing","bourne","market","strand","bridge","rest"]),
 "ordrane":  (["Corr","Ash","Whit","Black","Green","Hal","Ked","Norr","Wyn","Stan","Eld","Marl","Brack","Hollow","Grim","Ryd"],
              ["march","stead","barrow","field","ton","well","moor","hurst","brook","fen","hall","warden","garth","shaw","down","keep"]),
 "sylvarien":(["Ael","Sil","Nyr","Thal","Ellu","Vae","Cae","Ith","Lor","Ysh","Aen","Mira","Sae","Ulth","Ny"],
              ["thyr","vion","reth","lien","dara","syl","meth","ael","wyn","rian","thas","dwen","lorn","sae","ithil"]),
 "ythan":    (["Lath","Mer","Corr","Ost","Vend","Hals","Brin","Waer","Tor","Nim","Skel","Cad","Aln","Perr"],
              ["mere","water","haven","wharf","landing","holm","strand","gild","boom","reach","dock","fleet","weir","staith"]),
 "drennmark":(["Dren","Ash","Vorn","Hald","Skarn","Bryn","Torv","Eld","Grim","Rask","Ulf","Hjor","Sten","Wyr"],
              ["drenn","vale","fell","pines","holt","garth","mark","stead","wold","thorn","rook","barrow","hollow","watch"]),
 "karrundeep":(["Zhar","Kaz","Dur","Bar","Thur","Grum","Khaz","Mor","Vun","Hrak","Dol","Bruk","Angg","Torm"],
              ["rund","dun","delve","gorm","hold","krag","gate","forge","deep","hammer","anvil","vault","spire","brand"]),
 "hjaldmark":(["Vorrs","Hjal","Sig","Ulf","Skei","Brann","Ravn","Hels","Fjor","Yrs","Kald","Steig","Aud","Grim"],
              ["gard","vik","haven","fell","strand","ness","hjem","stad","borg","holm","rike","skar","varde","by"]),
 "sarathal": (["Zir","Am","Sar","Kesh","Rah","Meru","Tan","Ilb","Qad","Nef","Sur","Haz","Ras","Tam"],
              ["ath","sarath","med","kesh","ahd","rune","zir","kar","dun","hem","rakh","ir","umet","adan"]),
 "wild":     (["Grave","Thorn","Wolf","Crow","Bone","Ash","Mire","Hush","Gall","Weeping","Sorrow","Cold","Blight","Ruin","Widow","Hunter"],
              ["briar","hollow","fen","reach","watch","fall","crag","wold","gate","rest","mark","end","stand","hearth","cairn"]),
}
used=set()
def gen(cult):
    pre,suf = CULT[cult]
    for _ in range(400):
        n = pre[rng.integers(len(pre))] + suf[rng.integers(len(suf))]
        if n.lower() not in used and 5 <= len(n) <= 13:
            used.add(n.lower()); return n
    n = pre[rng.integers(len(pre))]+suf[rng.integers(len(suf))]+str(int(rng.integers(2,90)))
    used.add(n.lower()); return n

for r in REALMS:
    used.add(r["cap"].lower()); used.add(r["name"].lower())

# ============================ 3. SETTLEMENTS ============================
TIER = {34:"capital", 20:"city", 12:"town", 7.0:"village"}
picked = raw['picked']
caps   = raw['caps']
capkey = {(int(c[1]),int(c[2])) for c in caps}
cap_order = [(int(c[1]),int(c[2])) for c in caps]

BN_={1:'alpine',2:'highland',3:'taiga',4:'deepforest',5:'woodland',6:'arid',7:'plains',8:'lake'}
def region_biome(y,x,r=4):
    y0,y1=max(0,y-r),min(H,y+r+1); x0,x1=max(0,x-r),min(W,x+r+1)
    sub=biome[y0:y1,x0:x1]; msk=land[y0:y1,x0:x1] & (sub!=8)
    if not msk.any(): return BN_[int(biome[y,x])] if biome[y,x] in BN_ else 'plains'
    vals,cnt=np.unique(sub[msk],return_counts=True)
    return BN_[int(vals[np.argmax(cnt)])]

settlements=[]
for i,(sc,y,x,mr) in enumerate(picked):
    y,x = int(y),int(x)
    n = int(nation[y,x])
    cult = REALMS[n]["id"] if n>=0 else "wild"
    tier = TIER[mr]
    if (y,x) in capkey:
        ni = cap_order.index((y,x)); tier="capital"; nm = REALMS[ni]["cap"]; n = ni
    else:
        nm = gen(cult)
    coastal = bool(dist[y,x] <= 2)
    onriver = bool(acc[y,x] > 110)
    settlements.append(dict(
        id=f"s{i:03d}", name=nm, tier=tier, realm=(REALMS[n]["id"] if n>=0 else None),
        x=gx(x+0.5), y=gy(y+0.5), gx=x, gy=y,
        biome=region_biome(y,x), localBiome=BN_[int(biome[y,x])],
        port=coastal, river=onriver,
        elev=round(float(rel[y,x]),3)))
# population model
POP={"capital":(38000,95000),"city":(9000,26000),"town":(1800,7000),"village":(120,900)}
for s in settlements:
    lo,hi=POP[s["tier"]]
    m = 1.0 + (0.35 if s["port"] else 0) + (0.2 if s["river"] else 0) - (0.25 if s["biome"] in ("alpine","taiga","arid") else 0)
    s["pop"]=int(np.clip(rng.integers(lo,hi)*m, lo*0.5, hi*1.6))

# ============================ 4. ROADS (A* on half-res cost grid) ============================
h2,w2 = H//2, W//2
def blk(a, f=np.mean):
    return f(a[:h2*2,:w2*2].reshape(h2,2,w2,2), axis=(1,3))
land2 = blk(land.astype(float)) > 0.5
rel2  = blk(rel); slope2 = blk(slope); lake2 = blk(lake.astype(float))>0.5
cost2 = 1.0 + 9.0*np.clip(rel2,0,1)**2 + 12.0*slope2 + np.where(lake2,60,0)
cost2 = np.where(land2, cost2, 400.0)

_l2y,_l2x = np.nonzero(land2)
def snap(p):
    """coastal cells often fail the half-res land test -- snap to nearest land cell"""
    y,x = p
    y=min(h2-1,max(0,y)); x=min(w2-1,max(0,x))
    if land2[y,x]: return (y,x)
    d=(_l2y-y)**2+(_l2x-x)**2
    k=int(np.argmin(d))
    if d[k] > 25: return None                      # genuinely offshore (island)
    return (int(_l2y[k]), int(_l2x[k]))

def astar(a, b):
    a=snap(a); b=snap(b)
    if a is None or b is None or a==b: return None
    (sy,sx),(ty,tx) = a,b
    g = np.full((h2,w2), np.inf); g[sy,sx]=0
    came = {}
    hcost = lambda y,x: math.hypot(y-ty,x-tx)*1.0
    pq=[(hcost(sy,sx),0.0,sy,sx)]
    while pq:
        f,gc,y,x = heapq.heappop(pq)
        if (y,x)==(ty,tx):
            path=[(y,x)]
            while (y,x) in came: y,x = came[(y,x)]; path.append((y,x))
            return path[::-1]
        if gc > g[y,x]+1e-9: continue
        for dy,dx in ((1,0),(-1,0),(0,1),(0,-1),(1,1),(1,-1),(-1,1),(-1,-1)):
            p,q = y+dy,x+dx
            if 0<=p<h2 and 0<=q<w2:
                step=(1.0 if (dy==0 or dx==0) else 1.414)*cost2[p,q]
                ng=gc+step
                if ng < g[p,q]-1e-9:
                    g[p,q]=ng; came[(p,q)]=(y,x); heapq.heappush(pq,(ng+hcost(p,q),ng,p,q))
    return None

# candidate edges: k-nearest among capitals/cities/towns
hubs=[s for s in settlements if s["tier"] in ("capital","city","town")]
pos=np.array([[s["gy"],s["gx"]] for s in hubs],float)
edges=set()
for i in range(len(hubs)):
    d=np.hypot(pos[:,0]-pos[i,0],pos[:,1]-pos[i,1]); d[i]=1e9
    for j in np.argsort(d)[:4]:
        if d[j] < 105: edges.add((min(i,int(j)),max(i,int(j))))
roads=[]
_ly,_lx = np.nonzero(land)
def on_land(pts, fix=3):
    """half-res paths clip the coast; nudge stray points onto land, reject if too far."""
    out=[]
    for x,y in pts:
        gy_=min(H-1,max(0,int(y/SY))); gx_=min(W-1,max(0,int(x/SX)))
        if land[gy_,gx_]: out.append((x,y)); continue
        best=None
        for dy in range(-fix,fix+1):
            for dx in range(-fix,fix+1):
                a,b=gy_+dy,gx_+dx
                if 0<=a<H and 0<=b<W and land[a,b]:
                    d=dy*dy+dx*dx
                    if best is None or d<best[0]: best=(d,a,b)
        if best is None: return None
        out.append((gx(best[2]+0.5), gy(best[1]+0.5)))
    return out
def add_road(i,j,grade=None,cap=2.6):
    a=(hubs[i]["gy"]//2, hubs[i]["gx"]//2); b=(hubs[j]["gy"]//2, hubs[j]["gx"]//2)
    p=astar(a,b)
    if not p or len(p)<2: return None
    L=sum(math.hypot(p[k+1][0]-p[k][0],p[k+1][1]-p[k][1]) for k in range(len(p)-1))*2
    direct=math.hypot(a[0]-b[0],a[1]-b[1])*2
    if cap and direct>0 and L/direct>cap: return None
    pts=[(gx(x*2+1),gy(y*2+1)) for y,x in p]
    pts=on_land(pts)
    if pts is None: return None
    if grade is None:
        grade="highway" if (hubs[i]["tier"]!="town" and hubs[j]["tier"]!="town") else "road"
    r=dict(id="", a=hubs[i]["id"], b=hubs[j]["id"], grade=grade,
           len_km=round(L*6.5), len_mi=round(L*4.04),
           pts=[[round(u,1),round(v,1)] for u,v in pts])
    roads.append(r); return r

for i,j in sorted(edges): add_road(i,j)

# --- guarantee a connected network: join components by cheapest available link ---
def comps():
    adj=defaultdict(set); idx={h["id"]:k for k,h in enumerate(hubs)}
    for r in roads:
        adj[idx[r["a"]]].add(idx[r["b"]]); adj[idx[r["b"]]].add(idx[r["a"]])
    seen=set(); out=[]
    for k in range(len(hubs)):
        if k in seen: continue
        st=[k]; seen.add(k); grp=[]
        while st:
            n=st.pop(); grp.append(n)
            for m in adj[n]:
                if m not in seen: seen.add(m); st.append(m)
        out.append(grp)
    return out
for _ in range(60):
    groups=comps()
    if len(groups)<=1: break
    groups.sort(key=len, reverse=True)
    best=None
    big=set(groups[0])
    for g in groups[1:]:
        for j in g:
            for i in groups[0]:
                d=(pos[i,0]-pos[j,0])**2+(pos[i,1]-pos[j,1])**2
                if best is None or d<best[0]: best=(d,i,j)
    if best is None: break
    d,i,j = best
    if add_road(i,j,grade="highway",cap=3.4) is None and add_road(i,j,grade="highway",cap=None) is None:
        # unreachable overland (across the sea) -- record a sea route instead
        hubs[i].setdefault("_iso",0); hubs[j]["_iso"]=1
        a=hubs[i]; b=hubs[j]
        roads.append(dict(id="", a=a["id"], b=b["id"], grade="searoute",
            len_km=round(math.hypot(a["gx"]-b["gx"],a["gy"]-b["gy"])*6.5),
            len_mi=round(math.hypot(a["gx"]-b["gx"],a["gy"]-b["gy"])*4.04),
            pts=[[a["x"],a["y"]],[b["x"],b["y"]]]))
for k,r in enumerate(roads): r["id"]=f"r{k:03d}"
print("roads:",len(roads),"highways:",sum(1 for r in roads if r['grade']=='highway'))

# ============================ 5. NAMED FEATURES ============================
def label_components(bm, minsize):
    lab=np.zeros(bm.shape,int); cur=0; out=[]
    for sy in range(bm.shape[0]):
        for sx in range(bm.shape[1]):
            if bm[sy,sx] and lab[sy,sx]==0:
                cur+=1; st=[(sy,sx)]; lab[sy,sx]=cur; cells=[]
                while st:
                    y,x=st.pop(); cells.append((y,x))
                    for dy,dx in ((1,0),(-1,0),(0,1),(0,-1)):
                        p,q=y+dy,x+dx
                        if 0<=p<bm.shape[0] and 0<=q<bm.shape[1] and bm[p,q] and lab[p,q]==0:
                            lab[p,q]=cur; st.append((p,q))
                if len(cells)>=minsize: out.append(cells)
    return out

RANGE_NAMES=["The Sceptres","The Kalder Wall","The Thornspine","The Emberfells","Hagsback Ridge","The Sunder Teeth"]
FOREST_NAMES=["The Wyrdpines","Gravebriar","The Elderwood","Duskhollow","The Hush","Thornmantle","The Rookwood","Ashen Weald"]
DESERT_NAMES=["The Ashen Reach","The Sunfields","The Thirst","The Burnt Marches"]
feature=[]
def centroid(cells):
    a=np.array(cells,float); return a[:,1].mean(), a[:,0].mean()

mtn = label_components(land & (rel>0.56), 220)
mtn.sort(key=len, reverse=True)
for i,c in enumerate(mtn[:6]):
    cx,cy = centroid(c); a=np.array(c,float)
    ang = math.degrees(math.atan2(np.polyfit(a[:,1],a[:,0],1)[0],1)) if len(set(a[:,1]))>2 else 0
    feature.append(dict(kind="range", name=RANGE_NAMES[i], x=gx(cx), y=gy(cy),
                        angle=round(ang,1), cells=len(c)))
frs = label_components(land & (biome==4), 380); frs.sort(key=len,reverse=True)
for i,c in enumerate(frs[:8]):
    cx,cy=centroid(c); feature.append(dict(kind="forest", name=FOREST_NAMES[i], x=gx(cx), y=gy(cy), cells=len(c)))
des = label_components(land & (biome==6), 500); des.sort(key=len,reverse=True)
for i,c in enumerate(des[:4]):
    cx,cy=centroid(c); feature.append(dict(kind="desert", name=DESERT_NAMES[i], x=gx(cx), y=gy(cy), cells=len(c)))
lk  = label_components(lake & land, 90); lk.sort(key=len,reverse=True)
LAKE_NAMES=["Loch Vaenn","Still Anwyn","The Black Tarn","Mirrormere","Sorrowmere","Lake Idris"]
for i,c in enumerate(lk[:6]):
    cx,cy=centroid(c); feature.append(dict(kind="lake", name=LAKE_NAMES[i], x=gx(cx), y=gy(cy), cells=len(c)))
if isea.any():
    ys,xs=np.nonzero(isea)
    feature.append(dict(kind="sea", name="The Ythan Mere", x=gx(xs.mean()), y=gy(ys.mean()), cells=int(isea.sum())))
WATERS=[("The Sundering","gulf",0.50,0.60),("The Cold Gyre","ocean",0.10,0.10),
        ("The Bale Sea","ocean",0.90,0.78),("The Wracks","ocean",0.13,0.88),
        ("The Dragon's Bight","bay",0.83,0.30)]
for nm,k,fx,fy in WATERS:
    feature.append(dict(kind=k, name=nm, x=gx(fx*W), y=gy(fy*H)))

RIVER_NAMES=["The Ythan","Corrow Water","The Silverrun","The Vaunt","Blackbrook","The Aelthyr",
             "Drennwater","The Kessel","Thrail Water","The Wyrm","Sarath Flow","The Marrow",
             "Greylode","The Whisper","Cold Anwyn","The Gallow"]
rivers=[]
for i,r in enumerate(raw['rivers']):
    pts=r['pts']; fl=r['flow']
    nm = RIVER_NAMES[i] if i < len(RIVER_NAMES) else None
    rivers.append(dict(id=f"w{i:02d}", name=nm,
                       flow=round(max(fl)), pts=[[gx(p[0]),gy(p[1])] for p in pts],
                       widths=[round(0.7+2.6*math.sqrt(f/max(1,max(fl))),2) for f in fl]))

# ============================ 6. LANDMARKS ============================
LM = {
 "alpine":   [("Ruined watchtower","tower"),("Dragon's eyrie","lair"),("Abandoned delve","dungeon"),("The High Pass","pass")],
 "highland":[("Hill-fort ruin","ruin"),("Standing stones","stones"),("Old mine works","mine"),("Hermit's monastery","shrine")],
 "taiga":   [("Frozen barrow","barrow"),("Warden's watch","tower"),("Sunken hall","dungeon"),("Wolf-shrine","shrine")],
 "deepforest":[("Circle of stones","stones"),("The Green Chapel","shrine"),("Overgrown keep","ruin"),("Witch's steading","lair")],
 "woodland":[("Wayside shrine","shrine"),("Old battlefield","battle"),("Toll-keep","ruin"),("Barrow field","barrow")],
 "arid":    [("Buried city","ruin"),("Broken obelisk","stones"),("The Salt Oasis","oasis"),("Tomb of kings","dungeon")],
 "plains":  [("Field of Cairns","battle"),("Waystone cross","stones"),("Abandoned abbey","shrine"),("Siege ruin","ruin")],
}
LM_PRE=["Old","Grey","Broken","Sunken","Nameless","Hollow","Weeping","Bleak","Fallen","Silent",
        "Hanged Man's","Drowned","Forgotten","Shattered","Whispering","Black","Widow's","Cold"]
poi=[]; taken=[]; poi_names=set()
cand=[]
for y in range(2,H-2):
    for x in range(2,W-2):
        if not land[y,x] or lake[y,x]: continue
        b={1:'alpine',2:'highland',3:'taiga',4:'deepforest',5:'woodland',6:'arid',7:'plains',8:'lake'}[int(biome[y,x])]
        if b not in LM: continue
        s = 0.0
        s += 2.0 if nation[y,x] < 0 else 0.0          # wilderness preferred
        s += 1.2*min(1.0, dist[y,x]/18)               # remote
        s += 1.5*rel[y,x]
        s += float(rng.random())*2.2
        cand.append((s,y,x,b))
cand.sort(reverse=True)
for s,y,x,b in cand:
    if len(poi)>=46: break
    if any((y-py)**2+(x-px)**2 < 26**2 for py,px in taken): continue
    taken.append((y,x))
    base,typ = LM[b][int(rng.integers(len(LM[b])))]
    stem = base[4:] if base.startswith("The ") else base
    if rng.random() < 0.55:
        nm = f"{LM_PRE[int(rng.integers(len(LM_PRE)))]} {stem}"
    else:
        nm = base
    if nm.lower() in poi_names:                      # qualify duplicates by locale
        near = min(settlements, key=lambda t:(t['gy']-y)**2+(t['gx']-x)**2)
        for conn in (" near "," above "," beyond "," outside "):
            cand = nm + conn + near['name']
            if cand.lower() not in poi_names: nm = cand; break
    poi_names.add(nm.lower())
    n=int(nation[y,x])
    poi.append(dict(id=f"p{len(poi):02d}", name=nm, type=typ, x=gx(x+0.5), y=gy(y+0.5),
                    gx=int(x), gy=int(y), biome=b,
                    realm=(REALMS[n]["id"] if n>=0 else None),
                    danger=int(np.clip(1+ (0 if n>=0 else 2) + int(rel[y,x]*4) + int(rng.integers(0,3)), 1, 10))))
print("landmarks:",len(poi))

# ============================ 7. EXPORT ============================
BN={0:'ocean',1:'alpine',2:'highland',3:'taiga',4:'deepforest',5:'woodland',6:'arid',7:'plains',8:'lake'}
realm_out=[]
for i,r in enumerate(REALMS):
    m = nation==i; ys,xs=np.nonzero(m)
    bs={BN[b]:round(float((biome[m]==b).mean()),3) for b in range(1,9)}
    sset=[s for s in settlements if s["realm"]==r["id"]]
    realm_out.append(dict(id=r["id"], name=r["name"], fullName=r["full"], capital=r["cap"],
        government=r["gov"], ruler=r["ruler"], peoples=r["people"], colour=r["hue"], hook=r["hook"],
        area_km2=int(m.sum()*6.5*6.5), centroid=[gx(xs.mean()), gy(ys.mean())],
        population=sum(s["pop"] for s in sset), settlements=len(sset), biomes=bs))

world=dict(
  meta=dict(name="Sundermere", subtitle="A Continent of the Sundered West",
            width=CW, height=CH, grid=[W,H], cellKm=6.5, cellMi=4.04, seaLevel=0.42,
            generated="2026-08-19", version=1,
            note="x,y are canvas pixels matching sundermere-map.svg viewBox 0 0 %d %d"%(CW,CH)),
  realms=realm_out, settlements=settlements, roads=roads, rivers=rivers,
  features=feature, landmarks=poi,
  coastlines=[[[round(p[0]*SX,1),round(p[1]*SY,1)] for p in c] for c in coast],
)
json.dump(world, open(D+'world.json','w'), indent=1)
np.save(D+'nation.npy', nation)
print("settlements:", len(settlements), {t:sum(1 for s in settlements if s['tier']==t) for t in ('capital','city','town','village')})
print("total population:", f"{sum(s['pop'] for s in settlements):,}")
print("features:", len(feature), " rivers:", len(rivers))
import os; print("world.json", round(os.path.getsize(D+'world.json')/1024), "KB")
