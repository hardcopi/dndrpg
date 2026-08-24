import json, re, base64
O='/sessions/dazzling-jolly-pasteur/mnt/outputs/'
svg=open(O+'sundermere-map.svg').read()
w=json.load(open(O+'sundermere-world.json'))
CW,CH=w['meta']['width'],w['meta']['height']

# slim payload for the browser (drop the heavy coastline geometry + road polylines)
slim=dict(meta=w['meta'], realms=w['realms'],
          settlements=[{k:v for k,v in s.items() if k not in ('gx','gy')} for s in w['settlements']],
          landmarks=w['landmarks'], features=w['features'],
          roads=[{k:v for k,v in r.items() if k!='pts'} for r in w['roads']])
DATA=json.dumps(slim, separators=(',',':'))

# strip the outer <svg> wrapper so we can control sizing
inner=svg[svg.index('>',svg.index('<svg'))+1: svg.rindex('</svg>')]

HTML = """<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sundermere — World Explorer</title>
<style>
:root{--ink:#3a2a18;--paper:#efe2c4;--panel:#241b12;--edge:#5c4a32;--accent:#c8a24a}
*{box-sizing:border-box}
html,body{margin:0;height:100%;background:#181310;color:#e8ddc6;
  font-family:Georgia,'Iowan Old Style','Times New Roman',serif;overflow:hidden}
#app{display:flex;height:100%}
#stage{flex:1;position:relative;overflow:hidden;cursor:grab;background:#1d1813}
#stage.drag{cursor:grabbing}
#world{position:absolute;transform-origin:0 0;will-change:transform}
#world svg{display:block}
#pins{position:absolute;inset:0;pointer-events:none}
.pin{position:absolute;transform:translate(-50%,-50%);pointer-events:auto;cursor:pointer;
  border-radius:50%;border:2px solid transparent}
.pin:hover{border-color:var(--accent);background:rgba(200,162,74,.25)}
.pin.sel{border-color:#fff;background:rgba(255,255,255,.3)}
.pin.hide{display:none}
#side{width:352px;background:var(--panel);border-left:2px solid var(--edge);
  display:flex;flex-direction:column;box-shadow:-8px 0 24px rgba(0,0,0,.5)}
#side header{padding:16px 18px 12px;border-bottom:1px solid var(--edge)}
h1{margin:0;font-size:21px;letter-spacing:3px;color:var(--accent)}
h1 small{display:block;font-size:11px;letter-spacing:1.4px;color:#9b8968;
  font-style:italic;margin-top:4px;font-weight:400}
#controls{padding:12px 18px;border-bottom:1px solid var(--edge);font-size:13px}
input,select{width:100%;padding:7px 9px;margin:4px 0 9px;background:#150f0a;color:#e8ddc6;
  border:1px solid var(--edge);border-radius:4px;font-family:inherit;font-size:13px}
.tiers{display:flex;gap:5px;flex-wrap:wrap}
.tiers button{flex:1;min-width:62px;padding:5px 4px;font-size:11px;background:#150f0a;
  color:#9b8968;border:1px solid var(--edge);border-radius:4px;cursor:pointer;font-family:inherit}
.tiers button.on{background:var(--accent);color:#1d1509;border-color:var(--accent);font-weight:bold}
#body{flex:1;overflow-y:auto;padding:16px 18px}
#body::-webkit-scrollbar{width:9px}#body::-webkit-scrollbar-thumb{background:var(--edge);border-radius:5px}
.card h2{margin:0 0 2px;font-size:22px;color:#f0e2c0}
.card .sub{font-size:12px;color:#9b8968;font-style:italic;margin-bottom:14px}
.kv{display:flex;justify-content:space-between;gap:10px;padding:6px 0;
  border-bottom:1px solid #3a2d1e;font-size:13px}
.kv span:first-child{color:#9b8968}
.kv span:last-child{text-align:right}
.swatch{display:inline-block;width:11px;height:11px;border-radius:2px;margin-right:6px;
  vertical-align:-1px;border:1px solid #000}
.hook{margin-top:14px;padding:12px 13px;background:#181109;border-left:3px solid var(--accent);
  font-size:13px;line-height:1.55;font-style:italic;color:#d8c8a4}
.hint{color:#8a7a5c;font-size:13px;line-height:1.6}
.list{margin-top:14px}
.list h3{font-size:11px;letter-spacing:2px;color:var(--accent);margin:16px 0 6px;
  text-transform:uppercase;font-weight:normal}
.row{padding:6px 8px;margin:2px -8px;border-radius:4px;cursor:pointer;font-size:13px;
  display:flex;justify-content:space-between;gap:8px}
.row:hover{background:#31251a}
.row em{color:#8a7a5c;font-size:11px;font-style:normal}
#zoom{position:absolute;left:14px;bottom:14px;display:flex;gap:6px;z-index:5}
#zoom button{width:34px;height:34px;font-size:17px;background:rgba(24,17,9,.9);color:var(--accent);
  border:1px solid var(--edge);border-radius:5px;cursor:pointer;font-family:inherit}
#zoom button:hover{background:var(--accent);color:#1d1509}
#tip{position:absolute;pointer-events:none;background:rgba(20,14,8,.95);color:#e8ddc6;
  padding:5px 9px;border:1px solid var(--edge);border-radius:4px;font-size:12px;
  display:none;z-index:9;white-space:nowrap}
#legend{position:absolute;right:14px;bottom:14px;background:rgba(24,17,9,.88);
  border:1px solid var(--edge);border-radius:5px;padding:9px 12px;font-size:11px;z-index:5;
  color:#9b8968;line-height:1.7}
</style></head><body>
<div id="app">
 <div id="stage">
   <div id="world"><!--SVG--></div>
   <div id="pins"></div>
   <div id="zoom"><button data-z="1.35">+</button><button data-z="0.74">&minus;</button>
     <button data-z="fit" style="width:auto;padding:0 11px;font-size:12px">fit</button></div>
   <div id="legend">scroll to zoom &middot; drag to pan &middot; click a marker</div>
   <div id="tip"></div>
 </div>
 <aside id="side">
  <header><h1>SUNDERMERE<small>A Continent of the Sundered West</small></h1></header>
  <div id="controls">
    <input id="q" placeholder="Search places…" autocomplete="off">
    <select id="realm"><option value="">All realms &amp; wilderness</option></select>
    <div class="tiers" id="tiers"></div>
  </div>
  <div id="body"></div>
 </aside>
</div>
<script>
const W = __DATA__;
const world=document.getElementById('world'), stage=document.getElementById('stage'),
      pins=document.getElementById('pins'), body=document.getElementById('body'),
      tip=document.getElementById('tip');
const CW=W.meta.width, CH=W.meta.height;
let scale=1, ox=0, oy=0, sel=null;
const shown={capital:1,city:1,town:1,village:0,landmark:1};

function apply(){
  world.style.transform=`translate(${ox}px,${oy}px) scale(${scale})`;
  for(const p of pinEls){
    p.el.style.left=(p.x*scale+ox)+'px';
    p.el.style.top =(p.y*scale+oy)+'px';
    const s=Math.max(9,Math.min(34,p.base*Math.sqrt(scale)));
    p.el.style.width=s+'px'; p.el.style.height=s+'px';
  }
}
function fit(){
  const r=stage.getBoundingClientRect();
  scale=Math.min(r.width/CW,r.height/CH)*0.98;
  ox=(r.width-CW*scale)/2; oy=(r.height-CH*scale)/2; apply();
}
function zoomTo(x,y,z){
  const r=stage.getBoundingClientRect();
  scale=Math.max(0.15,Math.min(9,z));
  ox=r.width/2-x*scale; oy=r.height/2-y*scale; apply();
}

// ---- markers -------------------------------------------------------------
const pinEls=[];
const realmOf=id=>W.realms.find(r=>r.id===id);
const BASE={capital:30,city:22,town:16,village:11,landmark:16};
function addPin(o,kind){
  const el=document.createElement('div'); el.className='pin';
  el.title=''; pinEls.push({el,x:o.x,y:o.y,base:BASE[kind],kind,obj:o});
  el.addEventListener('click',e=>{e.stopPropagation();select(o,kind,el)});
  el.addEventListener('mouseenter',e=>{
    tip.textContent=o.name+(o.tier?' — '+o.tier:'')+(kind==='landmark'?' — '+o.type:'');
    tip.style.display='block';
  });
  el.addEventListener('mousemove',e=>{
    const r=stage.getBoundingClientRect();
    tip.style.left=(e.clientX-r.left+14)+'px'; tip.style.top=(e.clientY-r.top+14)+'px';
  });
  el.addEventListener('mouseleave',()=>tip.style.display='none');
  pins.appendChild(el);
}
W.settlements.forEach(s=>addPin(s,s.tier));
W.landmarks.forEach(p=>addPin(p,'landmark'));

function refresh(){
  const q=document.getElementById('q').value.trim().toLowerCase();
  const rf=document.getElementById('realm').value;
  for(const p of pinEls){
    let ok = shown[p.kind];
    if(rf) ok = ok && (p.obj.realm===rf || (rf==='__wild' && !p.obj.realm));
    if(q)  ok = p.obj.name.toLowerCase().includes(q);
    p.el.classList.toggle('hide',!ok);
  }
}

// ---- side panel ----------------------------------------------------------
const fmt=n=>n.toLocaleString();
const esc=s=>String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
function kv(k,v){return `<div class="kv"><span>${k}</span><span>${v}</span></div>`}
function select(o,kind,el){
  document.querySelectorAll('.pin.sel').forEach(p=>p.classList.remove('sel'));
  if(el) el.classList.add('sel');
  sel=o;
  if(kind==='landmark'){
    const r=realmOf(o.realm);
    body.innerHTML=`<div class="card"><h2>${esc(o.name)}</h2>
      <div class="sub">${esc(o.type)} &middot; ${esc(o.biome)}</div>
      ${kv('Territory', r?esc(r.name):'Unclaimed wilderness')}
      ${kv('Danger', '&#9670;'.repeat(Math.min(5,Math.ceil(o.danger/2)))+' &nbsp;'+o.danger+'/10')}
      ${kv('Position', Math.round(o.x)+', '+Math.round(o.y))}
      <div class="hook">Undeveloped. Ask Claude to flesh this out — what's inside, who's there, why anyone would go.</div>
      </div>`;
    return;
  }
  const r=realmOf(o.realm);
  const near=W.settlements.filter(s=>s!==o)
    .map(s=>[Math.hypot(s.x-o.x,s.y-o.y),s]).sort((a,b)=>a[0]-b[0]).slice(0,6);
  body.innerHTML=`<div class="card"><h2>${esc(o.name)}</h2>
    <div class="sub">${o.tier}${r?' of '+esc(r.name):' &middot; free of any crown'}</div>
    ${kv('Population', fmt(o.pop))}
    ${kv('Terrain', esc(o.biome))}
    ${kv('Harbour', o.port?'yes — sea access':'landlocked')}
    ${kv('River', o.river?'yes — on a navigable river':'no')}
    ${kv('Elevation', Math.round(o.elev*100)+'% of peak')}
    ${kv('Position', Math.round(o.x)+', '+Math.round(o.y))}
    ${r?`${kv('Ruler',esc(r.ruler))}${kv('Government',esc(r.government))}`:''}
    <div class="hook">No detail written yet. Ask Claude to develop ${esc(o.name)} —
      districts, factions, notable NPCs, rumours, and what the party finds here.</div>
    <div class="list"><h3>Nearest places</h3>
      ${near.map(([d,s])=>`<div class="row" data-id="${s.id}"><span>${esc(s.name)}</span>
        <em>${Math.round(d*W.meta.cellMi/(CW/W.meta.grid[0]))} mi &middot; ${s.tier}</em></div>`).join('')}
    </div></div>`;
  body.querySelectorAll('.row').forEach(row=>row.addEventListener('click',()=>{
    const s=W.settlements.find(x=>x.id===row.dataset.id);
    const p=pinEls.find(p=>p.obj===s); select(s,s.tier,p&&p.el); zoomTo(s.x,s.y,Math.max(scale,1.6));
  }));
}
function showRealm(r){
  const list=W.settlements.filter(s=>s.realm===r.id)
    .sort((a,b)=>b.pop-a.pop);
  const bio=Object.entries(r.biomes).filter(([k,v])=>v>0.05)
    .sort((a,b)=>b[1]-a[1]).map(([k,v])=>`${k} ${Math.round(v*100)}%`).join(', ');
  body.innerHTML=`<div class="card">
    <h2><span class="swatch" style="background:${r.colour}"></span>${esc(r.name)}</h2>
    <div class="sub">${esc(r.fullName)}</div>
    ${kv('Capital', esc(r.capital))}
    ${kv('Ruler', esc(r.ruler))}
    ${kv('Government', esc(r.government))}
    ${kv('Peoples', esc(r.peoples))}
    ${kv('Population', fmt(r.population))}
    ${kv('Settlements', r.settlements)}
    ${kv('Area', fmt(r.area_km2)+' km&sup2;')}
    ${kv('Land', bio)}
    <div class="hook">${esc(r.hook)}</div>
    <div class="list"><h3>Places</h3>
      ${list.map(s=>`<div class="row" data-id="${s.id}"><span>${esc(s.name)}</span>
        <em>${s.tier} &middot; ${fmt(s.pop)}</em></div>`).join('')}
    </div></div>`;
  body.querySelectorAll('.row').forEach(row=>row.addEventListener('click',()=>{
    const s=W.settlements.find(x=>x.id===row.dataset.id);
    const p=pinEls.find(p=>p.obj===s); select(s,s.tier,p&&p.el); zoomTo(s.x,s.y,2.0);
  }));
}
function home(){
  body.innerHTML=`<div class="card">
    <p class="hint">${W.settlements.length} settlements, ${W.landmarks.length} landmarks and
    ${W.realms.length} realms across ${fmt(W.realms.reduce((a,r)=>a+r.area_km2,0))} km&sup2;.
    Click any marker on the map, or pick a realm below.</p>
    <div class="list"><h3>The Nine Realms</h3>
    ${W.realms.map(r=>`<div class="row" data-r="${r.id}">
      <span><span class="swatch" style="background:${r.colour}"></span>${esc(r.name)}</span>
      <em>${fmt(r.population)}</em></div>`).join('')}
    <h3>Wilderness</h3>
    <div class="row" data-r="__wild"><span>Unclaimed lands</span>
      <em>${W.settlements.filter(s=>!s.realm).length} places</em></div>
    </div></div>`;
  body.querySelectorAll('.row').forEach(row=>row.addEventListener('click',()=>{
    const id=row.dataset.r;
    if(id==='__wild'){document.getElementById('realm').value='__wild';refresh();return}
    const r=W.realms.find(x=>x.id===id); showRealm(r);
    document.getElementById('realm').value=id; refresh();
    zoomTo(r.centroid[0],r.centroid[1],1.1);
  }));
}

// ---- controls ------------------------------------------------------------
const sel_r=document.getElementById('realm');
W.realms.forEach(r=>sel_r.insertAdjacentHTML('beforeend',
  `<option value="${r.id}">${esc(r.name)}</option>`));
sel_r.insertAdjacentHTML('beforeend','<option value="__wild">Unclaimed wilderness</option>');
sel_r.addEventListener('change',()=>{
  refresh();
  const r=W.realms.find(x=>x.id===sel_r.value);
  if(r){showRealm(r);zoomTo(r.centroid[0],r.centroid[1],1.1)} else home();
});
document.getElementById('q').addEventListener('input',refresh);
const tiers=document.getElementById('tiers');
['capital','city','town','village','landmark'].forEach(t=>{
  const b=document.createElement('button'); b.textContent=t; b.className=shown[t]?'on':'';
  b.onclick=()=>{shown[t]=!shown[t];b.className=shown[t]?'on':'';refresh()};
  tiers.appendChild(b);
});
document.querySelectorAll('#zoom button').forEach(b=>b.onclick=()=>{
  if(b.dataset.z==='fit') return fit();
  const r=stage.getBoundingClientRect();
  const cx=(r.width/2-ox)/scale, cy=(r.height/2-oy)/scale;
  zoomTo(cx,cy,scale*parseFloat(b.dataset.z));
});

// ---- pan & zoom ----------------------------------------------------------
let drag=null;
stage.addEventListener('mousedown',e=>{drag={x:e.clientX-ox,y:e.clientY-oy};stage.classList.add('drag')});
addEventListener('mousemove',e=>{if(drag){ox=e.clientX-drag.x;oy=e.clientY-drag.y;apply()}});
addEventListener('mouseup',()=>{drag=null;stage.classList.remove('drag')});
stage.addEventListener('wheel',e=>{
  e.preventDefault();
  const r=stage.getBoundingClientRect(), mx=e.clientX-r.left, my=e.clientY-r.top;
  const k=Math.exp(-e.deltaY*0.0016), ns=Math.max(0.15,Math.min(9,scale*k));
  ox=mx-(mx-ox)*(ns/scale); oy=my-(my-oy)*(ns/scale); scale=ns; apply();
},{passive:false});
addEventListener('resize',fit);
fit(); home(); refresh();
</script></body></html>"""

HTML = HTML.replace('<!--SVG-->',
  f'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
  f'viewBox="0 0 {CW} {CH}" width="{CW}" height="{CH}" '
  f'font-family="Georgia,\'Iowan Old Style\',\'Times New Roman\',serif">{inner}</svg>')
HTML = HTML.replace('__DATA__', DATA)
open(O+'sundermere-explorer.html','w').write(HTML)
print("explorer:", round(len(HTML)/1024), "KB")
