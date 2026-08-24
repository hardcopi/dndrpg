import json, re
O='/sessions/dazzling-jolly-pasteur/mnt/outputs/'
svg=open(O+'waerhaven-map.svg').read()
W=json.load(open(O+'waerhaven.json'))
CW,CH=W['meta']['mapSize']; MX,MY=W['meta']['mapOffset']
slim=dict(meta=W['meta'], summary=W['summary'], districts=W['districts'],
          locations=W['locations'], factions=W['factions'], people=W['people'],
          rumours=W['rumours'], hooks=W['hooks'],
          streets=[{'name':s['name'],'kind':s['kind']} for s in W['streets']],
          plazas=W['plazas'], gates=W['gates'])
DATA=json.dumps(slim,separators=(',',':'))
inner=svg[svg.index('>',svg.index('<svg'))+1: svg.rindex('</svg>')]

HTML = r"""<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Waerhaven — City Explorer</title>
<style>
:root{--ink:#3a2a18;--paper:#efe2c4;--panel:#241b12;--edge:#5c4a32;--accent:#c8a24a}
*{box-sizing:border-box}
html,body{margin:0;height:100%;background:#181310;color:#e8ddc6;
  font-family:Georgia,'Iowan Old Style','Times New Roman',serif;overflow:hidden}
#app{display:flex;height:100%}
#stage{flex:1;position:relative;overflow:hidden;cursor:grab;background:#1d1813}
#stage.drag{cursor:grabbing}
#world{position:absolute;transform-origin:0 0}
#world svg{display:block}
#pins{position:absolute;inset:0;pointer-events:none}
.pin{position:absolute;transform:translate(-50%,-50%);pointer-events:auto;cursor:pointer;
  border-radius:50%;border:2px solid transparent}
.pin:hover{border-color:var(--accent);background:rgba(200,162,74,.28)}
.pin.sel{border-color:#fff;background:rgba(255,255,255,.32)}
.pin.hide{display:none}
#side{width:380px;background:var(--panel);border-left:2px solid var(--edge);
  display:flex;flex-direction:column;box-shadow:-8px 0 24px rgba(0,0,0,.5)}
#side header{padding:15px 18px 11px;border-bottom:1px solid var(--edge)}
h1{margin:0;font-size:21px;letter-spacing:3px;color:var(--accent)}
h1 small{display:block;font-size:11px;letter-spacing:1.3px;color:#9b8968;font-style:italic;
  margin-top:4px;font-weight:400}
#tabs{display:flex;border-bottom:1px solid var(--edge)}
#tabs button{flex:1;padding:9px 4px;font-size:11.5px;background:transparent;color:#9b8968;
  border:0;border-bottom:2px solid transparent;cursor:pointer;font-family:inherit;letter-spacing:1px}
#tabs button.on{color:var(--accent);border-bottom-color:var(--accent)}
#controls{padding:10px 18px;border-bottom:1px solid var(--edge)}
input{width:100%;padding:7px 9px;background:#150f0a;color:#e8ddc6;border:1px solid var(--edge);
  border-radius:4px;font-family:inherit;font-size:13px}
#body{flex:1;overflow-y:auto;padding:15px 18px}
#body::-webkit-scrollbar{width:9px}#body::-webkit-scrollbar-thumb{background:var(--edge);border-radius:5px}
.card h2{margin:0 0 2px;font-size:21px;color:#f0e2c0}
.card .sub{font-size:12px;color:#9b8968;font-style:italic;margin-bottom:12px}
.kv{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid #3a2d1e;font-size:13px}
.kv span:first-child{color:#9b8968}.kv span:last-child{text-align:right}
p.t{font-size:13.5px;line-height:1.6;color:#dccfae;margin:10px 0}
.hook{margin-top:12px;padding:11px 12px;background:#181109;border-left:3px solid var(--accent);
  font-size:13px;line-height:1.55;font-style:italic;color:#d8c8a4}
.mood{font-size:12px;color:#8a7a5c;font-style:italic;margin-top:8px}
h3{font-size:11px;letter-spacing:2px;color:var(--accent);margin:18px 0 6px;text-transform:uppercase;font-weight:400}
.row{padding:6px 8px;margin:2px -8px;border-radius:4px;cursor:pointer;font-size:13px;
  display:flex;justify-content:space-between;gap:8px;align-items:baseline}
.row:hover{background:#31251a}
.row em{color:#8a7a5c;font-size:11px;font-style:normal;white-space:nowrap}
.num{display:inline-block;min-width:20px;height:20px;line-height:18px;text-align:center;
  border:1px solid var(--edge);border-radius:50%;font-size:10.5px;margin-right:7px;color:var(--accent)}
.pow{color:var(--accent);letter-spacing:2px}
ul.r{margin:6px 0 0;padding-left:18px}
ul.r li{font-size:13px;line-height:1.55;margin-bottom:8px;color:#dccfae}
#zoom{position:absolute;left:14px;bottom:14px;display:flex;gap:6px;z-index:5}
#zoom button{width:34px;height:34px;font-size:17px;background:rgba(24,17,9,.9);color:var(--accent);
  border:1px solid var(--edge);border-radius:5px;cursor:pointer;font-family:inherit}
#zoom button:hover{background:var(--accent);color:#1d1509}
#tip{position:absolute;pointer-events:none;background:rgba(20,14,8,.95);color:#e8ddc6;padding:5px 9px;
  border:1px solid var(--edge);border-radius:4px;font-size:12px;display:none;z-index:9;white-space:nowrap}
#hint{position:absolute;right:14px;bottom:14px;background:rgba(24,17,9,.86);border:1px solid var(--edge);
  border-radius:5px;padding:8px 11px;font-size:11px;z-index:5;color:#9b8968}
</style></head><body>
<div id="app">
 <div id="stage">
  <div id="world"><!--SVG--></div>
  <div id="pins"></div>
  <div id="zoom"><button data-z="1.35">+</button><button data-z="0.74">&minus;</button>
    <button data-z="fit" style="width:auto;padding:0 11px;font-size:12px">fit</button></div>
  <div id="hint">scroll to zoom &middot; drag to pan &middot; click a numbered place</div>
  <div id="tip"></div>
 </div>
 <aside id="side">
  <header><h1>WAERHAVEN<small>Second City of the Ythan League</small></h1></header>
  <div id="tabs"></div>
  <div id="controls"><input id="q" placeholder="Search places, people&hellip;" autocomplete="off"></div>
  <div id="body"></div>
 </aside>
</div>
<script>
const W=__DATA__, MX=__MX__, MY=__MY__;
const world=document.getElementById('world'),stage=document.getElementById('stage'),
      pins=document.getElementById('pins'),body=document.getElementById('body'),
      tip=document.getElementById('tip');
const CW=W.meta.mapSize[0],CH=W.meta.mapSize[1];
let scale=1,ox=0,oy=0;
const esc=s=>String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
const fmt=n=>n.toLocaleString();

const pinEls=[];
function apply(){
  world.style.transform=`translate(${ox}px,${oy}px) scale(${scale})`;
  for(const p of pinEls){
    p.el.style.left=((p.x+MX)*scale+ox)+'px';
    p.el.style.top =((p.y+MY)*scale+oy)+'px';
    const s=Math.max(14,Math.min(46,30*Math.sqrt(scale)));
    p.el.style.width=s+'px';p.el.style.height=s+'px';
  }
}
function fit(){const r=stage.getBoundingClientRect();
  scale=Math.min(r.width/CW,r.height/CH)*0.98;
  ox=(r.width-CW*scale)/2;oy=(r.height-CH*scale)/2;apply();}
function zoomTo(x,y,z){const r=stage.getBoundingClientRect();
  scale=Math.max(0.1,Math.min(9,z));
  ox=r.width/2-(x+MX)*scale;oy=r.height/2-(y+MY)*scale;apply();}

W.locations.forEach(l=>{
  const el=document.createElement('div');el.className='pin';
  pinEls.push({el,x:l.x,y:l.y,obj:l});
  el.onclick=e=>{e.stopPropagation();showLoc(l,el)};
  el.onmouseenter=()=>{tip.textContent=l.key+'. '+l.name;tip.style.display='block'};
  el.onmousemove=e=>{const r=stage.getBoundingClientRect();
    tip.style.left=(e.clientX-r.left+14)+'px';tip.style.top=(e.clientY-r.top+14)+'px'};
  el.onmouseleave=()=>tip.style.display='none';
  pins.appendChild(el);
});
function markSel(el){document.querySelectorAll('.pin.sel').forEach(p=>p.classList.remove('sel'));
  if(el)el.classList.add('sel')}

const D=id=>W.districts.find(d=>d.id===id);
function showLoc(l,el){
  markSel(el||(pinEls.find(p=>p.obj===l)||{}).el);
  const d=D(l.district);
  body.innerHTML=`<div class="card"><h2><span class="num">${l.key}</span>${esc(l.name)}</h2>
    <div class="sub">${esc(l.type)} &middot; ${d?esc(d.name):''}</div>
    <p class="t">${esc(l.desc)}</p>
    <div class="hook">${esc(l.hook)}</div>
    ${W.people.filter(p=>p.where===l.id).map(p=>`<h3>Here</h3>
      <div class="row" data-p="${esc(p.name)}"><span>${esc(p.name)}</span><em>${esc(p.role)}</em></div>`).join('')}
    ${kvBlock({'Ward':d?d.name:'—','Position':Math.round(l.x)+', '+Math.round(l.y)+' m'})}
    </div>`;
  wire();
}
function kvBlock(o){return Object.entries(o).map(([k,v])=>
  `<div class="kv"><span>${k}</span><span>${esc(v)}</span></div>`).join('')}
function showDistrict(d){
  const ls=W.locations.filter(l=>l.district===d.id).sort((a,b)=>a.key-b.key);
  body.innerHTML=`<div class="card"><h2>${esc(d.name)}</h2>
    <div class="sub">${esc(d.sub)}</div>
    <p class="t">${esc(d.text)}</p>
    <div class="mood">${esc(d.mood)}</div>
    ${kvBlock({'Roofs':fmt(d.buildings),'Places of note':ls.length})}
    <h3>Places</h3>${ls.map(l=>`<div class="row" data-l="${l.id}">
      <span><span class="num">${l.key}</span>${esc(l.name)}</span><em>${esc(l.type)}</em></div>`).join('')}
    </div>`;
  wire();
  zoomTo(d.centre[0],d.centre[1],Math.max(0.55,900/d.radius/2.4));
}
function wire(){
  body.querySelectorAll('[data-l]').forEach(r=>r.onclick=()=>{
    const l=W.locations.find(x=>x.id===r.dataset.l);showLoc(l);zoomTo(l.x,l.y,Math.max(scale,1.1))});
  body.querySelectorAll('[data-d]').forEach(r=>r.onclick=()=>showDistrict(D(r.dataset.d)));
  body.querySelectorAll('[data-p]').forEach(r=>r.onclick=()=>{
    const p=W.people.find(x=>x.name===r.dataset.p);showPerson(p)});
  body.querySelectorAll('[data-f]').forEach(r=>r.onclick=()=>{
    const f=W.factions.find(x=>x.id===r.dataset.f);showFaction(f)});
}
function showPerson(p){
  const l=W.locations.find(x=>x.id===p.where);
  const f=p.faction?W.factions.find(x=>x.id===p.faction):null;
  body.innerHTML=`<div class="card"><h2>${esc(p.name)}</h2><div class="sub">${esc(p.role)}</div>
   <p class="t">${esc(p.note)}</p>
   ${kvBlock({'Found at':l?l.name:'—','Faction':f?f.name:'unaligned'})}
   ${l?`<h3>Go there</h3><div class="row" data-l="${l.id}"><span><span class="num">${l.key}</span>${esc(l.name)}</span><em>${esc(l.type)}</em></div>`:''}
   </div>`;
  wire(); if(l) zoomTo(l.x,l.y,Math.max(scale,1.1));
}
function showFaction(f){
  const seat=W.locations.find(x=>x.id===f.seat);
  body.innerHTML=`<div class="card"><h2>${esc(f.name)}</h2>
   <div class="sub"><span class="pow">${'◆'.repeat(f.power)+'◇'.repeat(5-f.power)}</span> influence</div>
   <p class="t">${esc(f.desc)}</p>
   ${kvBlock({'Leader':f.leader,'Seat':seat?seat.name:f.seat})}
   <div class="hook">Wants: ${esc(f.wants)}</div>
   ${seat?`<h3>Seat</h3><div class="row" data-l="${seat.id}"><span><span class="num">${seat.key}</span>${esc(seat.name)}</span><em>${esc(seat.type)}</em></div>`:''}
   ${W.people.filter(p=>p.faction===f.id).length?`<h3>People</h3>`+W.people.filter(p=>p.faction===f.id).map(p=>
     `<div class="row" data-p="${esc(p.name)}"><span>${esc(p.name)}</span><em>${esc(p.role)}</em></div>`).join(''):''}
   </div>`;
  wire();
}
const TABS={
 'City':()=>{body.innerHTML=`<div class="card">
   ${kvBlock({'Realm':W.meta.realm,'Population':fmt(W.meta.population),'Roofs':fmt(2365),'Wards':W.districts.length})}
   <p class="t">${esc(W.summary.seat)}</p>
   ${kvBlock({'Economy':W.summary.economy})}
   <p class="t"><b>Government.</b> ${esc(W.summary.government)}</p>
   <p class="t"><b>Defences.</b> ${esc(W.summary.defences)}</p>
   <div class="hook">${esc(W.summary.tension)}</div>
   <h3>Wards</h3>${W.districts.map(d=>`<div class="row" data-d="${d.id}">
     <span>${esc(d.name)}</span><em>${fmt(d.buildings)} roofs</em></div>`).join('')}
   </div>`;wire()},
 'Places':()=>{body.innerHTML=`<div class="card"><h3>All places of note</h3>
   ${W.locations.slice().sort((a,b)=>a.key-b.key).map(l=>`<div class="row" data-l="${l.id}">
     <span><span class="num">${l.key}</span>${esc(l.name)}</span><em>${esc(l.type)}</em></div>`).join('')}</div>`;wire()},
 'People':()=>{body.innerHTML=`<div class="card"><h3>Notable people</h3>
   ${W.people.map(p=>`<div class="row" data-p="${esc(p.name)}"><span>${esc(p.name)}</span>
     <em>${esc(p.role)}</em></div>`).join('')}</div>`;wire()},
 'Powers':()=>{body.innerHTML=`<div class="card"><h3>Factions</h3>
   ${W.factions.map(f=>`<div class="row" data-f="${f.id}"><span>${esc(f.name)}</span>
     <em class="pow">${'◆'.repeat(f.power)}</em></div>`).join('')}</div>`;wire()},
 'Play':()=>{body.innerHTML=`<div class="card"><h3>Hooks</h3>
   ${W.hooks.map(h=>`<div style="margin-bottom:14px"><div style="font-size:14px;color:#f0e2c0">
     ${esc(h.title)} <em style="color:#8a7a5c;font-size:11px">(${h.tier})</em></div>
     <p class="t" style="margin:4px 0 0">${esc(h.text)}</p></div>`).join('')}
   <h3>Rumours</h3><ul class="r">${W.rumours.map(r=>`<li>${esc(r)}</li>`).join('')}</ul></div>`;wire()},
};
const tabs=document.getElementById('tabs');
Object.keys(TABS).forEach((k,i)=>{const b=document.createElement('button');b.textContent=k;
  if(i===0)b.className='on';
  b.onclick=()=>{[...tabs.children].forEach(c=>c.className='');b.className='on';TABS[k]()};
  tabs.appendChild(b)});
document.getElementById('q').addEventListener('input',e=>{
  const q=e.target.value.trim().toLowerCase();
  for(const p of pinEls) p.el.classList.toggle('hide', !!q && !p.obj.name.toLowerCase().includes(q));
  if(!q) return;
  const L=W.locations.filter(l=>l.name.toLowerCase().includes(q));
  const P=W.people.filter(p=>p.name.toLowerCase().includes(q)||p.role.toLowerCase().includes(q));
  body.innerHTML=`<div class="card">
    ${L.length?`<h3>Places</h3>`+L.map(l=>`<div class="row" data-l="${l.id}">
      <span><span class="num">${l.key}</span>${esc(l.name)}</span><em>${esc(l.type)}</em></div>`).join(''):''}
    ${P.length?`<h3>People</h3>`+P.map(p=>`<div class="row" data-p="${esc(p.name)}">
      <span>${esc(p.name)}</span><em>${esc(p.role)}</em></div>`).join(''):''}
    ${!L.length&&!P.length?'<p class="t">Nothing of that name.</p>':''}</div>`;
  wire();
});
document.querySelectorAll('#zoom button').forEach(b=>b.onclick=()=>{
  if(b.dataset.z==='fit')return fit();
  const r=stage.getBoundingClientRect();
  zoomTo((r.width/2-ox)/scale-MX,(r.height/2-oy)/scale-MY,scale*parseFloat(b.dataset.z))});
let drag=null;
stage.addEventListener('mousedown',e=>{drag={x:e.clientX-ox,y:e.clientY-oy};stage.classList.add('drag')});
addEventListener('mousemove',e=>{if(drag){ox=e.clientX-drag.x;oy=e.clientY-drag.y;apply()}});
addEventListener('mouseup',()=>{drag=null;stage.classList.remove('drag')});
stage.addEventListener('wheel',e=>{e.preventDefault();
  const r=stage.getBoundingClientRect(),mx=e.clientX-r.left,my=e.clientY-r.top;
  const k=Math.exp(-e.deltaY*0.0016),ns=Math.max(0.1,Math.min(9,scale*k));
  ox=mx-(mx-ox)*(ns/scale);oy=my-(my-oy)*(ns/scale);scale=ns;apply()},{passive:false});
addEventListener('resize',fit);
fit();TABS['City']();
</script></body></html>"""

HTML=HTML.replace('<!--SVG-->',
  f'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
  f'viewBox="0 0 {CW} {CH}" width="{CW}" height="{CH}" '
  f'font-family="Georgia,\'Iowan Old Style\',\'Times New Roman\',serif">{inner}</svg>')
HTML=HTML.replace('__DATA__',DATA).replace('__MX__',str(MX)).replace('__MY__',str(MY))
open(O+'waerhaven-explorer.html','w').write(HTML)
print("waerhaven-explorer.html", round(len(HTML)/1024),"KB")

# ---- link the world explorer's Waerhaven entry through to the city ----
we=open(O+'sundermere-explorer.html').read()
if 'waerhaven-explorer.html' not in we:
    we=we.replace(
      """    <div class="hook">No detail written yet. Ask Claude to develop ${esc(o.name)} —
      districts, factions, notable NPCs, rumours, and what the party finds here.</div>""",
      """    ${o.name==='Waerhaven'
      ? `<div class="hook" style="border-left-color:#c8a24a">This city is fully mapped.
         <a href="waerhaven-explorer.html" style="color:#c8a24a">Open the Waerhaven city explorer &rarr;</a>
         <br><span style="font-size:11px;color:#8a7a5c">8 wards, 2,365 buildings, 40 keyed places.</span></div>`
      : `<div class="hook">No detail written yet. Ask Claude to develop ${esc(o.name)} —
         districts, factions, notable NPCs, rumours, and what the party finds here.</div>`}""")
    open(O+'sundermere-explorer.html','w').write(we)
    print("world explorer linked ->", 'waerhaven-explorer.html' in we)
