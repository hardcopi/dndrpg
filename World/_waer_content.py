"""Waerhaven stage 3: keyed locations, people, factions -> waerhaven.json"""
import numpy as np, json, math
D='/sessions/dazzling-jolly-pasteur/work/'
geo=json.load(open(D+'waer_geo.json')); plan=json.load(open(D+'waer_plan.json'))
world=json.load(open('/sessions/dazzling-jolly-pasteur/mnt/outputs/sundermere-world.json'))
WA=[s for s in world['settlements'] if s['name']=='Waerhaven'][0]
CW,CH=geo['canvas']
B=plan['buildings']
rng=np.random.default_rng(3355)

# ============================ DISTRICTS ============================
DISTRICTS = {
 "theboom": dict(name="The Boom", sub="the harbour and its quays",
   text="Waerhaven's reason for existing. Five stone piers, a double rank of League warehouses, "
        "and a hand-forged chain that can be winched taut across the harbour mouth in four minutes "
        "by eleven men. The chain has been raised in anger twice in ninety years. It has been raised "
        "for drill every single week of those ninety years, because the Boom Watch is paid by the drill.",
   mood="Tar, wet rope, sawdust, cold water. Gulls. Shouting."),
 "slipways": dict(name="The Slipways", sub="where the League's fleet is built",
   text="Six building slips, a dry dock, the mast pond, and a ropewalk three hundred and eighty "
        "metres long — the longest continuous roofed structure on the continent, and the only reason "
        "Waerhaven matters more than Venddock. Every hull in the Ythan fleet was laid down here.",
   mood="The ox-mill grinding. Adzes. Steam from the timber-bending kilns."),
 "highstrand": dict(name="Highstrand", sub="the crown of the ridge",
   text="Stone houses, glazed windows, and the three buildings that argue over who runs the city: "
        "the Wet Hall, the Shipwrights' Moot, and the Factor's House. From the Moot Yard you can see "
        "every slip, every pier, and forty miles of open Mere.",
   mood="Quiet. Swept. Watched from upper windows."),
 "oldwaer": dict(name="Old Waer", sub="the fishing village that was here first",
   text="Four centuries older than the charter and it has never forgiven the charter for arriving. "
        "Lanes too narrow for a cart, houses built against houses, and a population that still calls "
        "itself Waerfolk rather than League. The point's light burns at the top of it.",
   mood="Fish. Woodsmoke. Somebody always singing badly."),
 "hallowgate": dict(name="Hallowgate Ward", sub="inside the landward gate",
   text="Where the ox-roads arrive. Timber yards stacked four metres high, the carters' yards, "
        "the Watch, the granary, and Hallowmarket — the only place in the city where you can buy "
        "everything and trust nothing.",
   mood="Oxen, dust, the smell of green sawn pine."),
 "tarrow": dict(name="Tarrow", sub="pitch, tar and oakum",
   text="Downwind by law, since the fire of the ninth year. The pitch yards, the boom forge, the "
        "oakum sheds where the poor pick old rope apart until their fingers split. Waerhaven cannot "
        "float a hull without Tarrow and cannot stand to look at it.",
   mood="Black smoke, black hands, a smell that gets into cloth and stays."),
 "wardenswatch": dict(name="The Wardenswatch", sub="extramural, and unlawful",
   text="Six years ago there was nothing here but the lazar house. Now there are perhaps nineteen "
        "hundred people from Drennmark living outside the wall, in a suburb the League's charter says "
        "does not exist. Waerhaven feeds it because Waerhaven needs the hands.",
   mood="Too many people, not enough roofs, and everyone facing north."),
 "northhorn": dict(name="The North Horn", sub="the outwork across the water",
   text="A spit of rock with a tower on it, the north anchor of the boom chain, and forty fisher-huts "
        "that pay no tax to anyone because nobody has ever been able to decide whose they are.",
   mood="Wind. Always wind."),
}

# ============================ KEYED LOCATIONS ============================
# landmarks already have footprints; add descriptions + hooks
LM_TEXT = {
 "leaguehall": ("The Wet Hall","civic",
   "The League's hall, built on piles over a tidal cut so the ground floor floods twice a day by design — "
   "the founders' joke about where League power actually comes from. Council meets on the dry upper floor.",
   "Halvia Corr's writ is read here. It has not been obeyed in full for two years."),
 "shipmoot": ("The Shipwrights' Moot","guild",
   "A long low hall of black oak with a half-built hull frame standing permanently in the middle of it. "
   "Master shipwrights are elected under that frame and buried within sight of it.",
   "The Moot can stop the fleet by stopping work. Everyone knows it. Nobody has said it aloud yet."),
 "factorhouse": ("The Factor's House","civic",
   "Lathmere's appointed Factor lives here, collects here, and is disliked here. Three storeys of "
   "imported Ardennian stone in a city that builds in timber, which tells you everything.",
   "Factor Bevis Culm has not sent an accurate return to Lathmere in eleven months."),
 "counting": ("The Counting Boom","guild",
   "Where cargo is weighed, taxed and argued over. The great beam-scale is calibrated against a League "
   "standard weight kept in a sealed box that three people may open, none of whom trust the others.",
   "The standard weight is four ounces light. It has been for six years. Somebody did that."),
 "greatcrane": ("The Great Crane","works",
   "A treadwheel crane worked by four men walking inside the drum. It can lift a mainmast. Twice a year "
   "it lifts something the Boom Watch is paid not to look at.",
   "The crane crew are the best-informed smugglers in the Ythan League."),
 "drydock": ("The Dry Dock","works",
   "Gated, pumped, and the only dock north of Lathmere that can take a war-hull out of the water whole.",
   "A Drennmark boat is in it now, being repaired for free, by order of nobody."),
 "mastpond": ("The Mast Pond","water",
   "Great pines lie submerged here for two years to season before they are shaped. Cold, black, deep, "
   "and full of timber that belongs to the League and is counted only once a year.",
   "The count last autumn was short by nine masts. The count was not repeated."),
 "sawmill": ("The Ox-Mill","works",
   "Eight oxen on a capstan drive the frame-saws. The mill runs from first light and is audible in "
   "every district but Highstrand, which was arranged deliberately.",
   "The mill has been ordered to double output for the League's new fleet. There is not enough timber."),
 "templemere": ("The Temple of the Deep Mere","temple",
   "The city's chief temple, dedicated to the cold thing that is politely called the Mere. Offerings "
   "go into the water, not onto an altar. The clergy are all former sailors.",
   "For six years the offerings have been washing back ashore. The clergy have stopped mentioning it."),
 "charthouse": ("The Charthouse","guild",
   "Every sounding, shoal and anchorage of the Ythan Mere, on vellum, under lock. Pilots are licensed here.",
   "Three charts of the northern shore were removed in spring. The Charthouse has not reported the theft."),
 "watchkeep": ("Hallowgate Keep","military",
   "The gatehouse and the Watch's barracks. Forty men, of whom perhaps twelve are sober before noon.",
   "Watch-Captain Oren Skeld is quietly letting Drennmark folk through the gate at night, and billing the League for the torches."),
 "boomforge": ("The Boom Forge","works",
   "Where the harbour chain was forged and is still repaired, link by link. The largest forge in the League.",
   "It is currently forging something that is not chain, on a private commission, behind a screen."),
 "pitchyard": ("The Pitch Yards","works",
   "Cauldrons, kilns, and the sourest smell in the north. The Tarrow gangs run it as a closed shop.",
   "Pitch prices have tripled since the fleet order. Somebody is holding stock back."),
 "lazarhouse": ("The Lazar House","civic",
   "Built well outside the wall in a kinder century, for the sick nobody wanted inside it.",
   "It has been full for two years, and not with lepers. Nobody will say what with."),
 "wardenhall": ("The Grey Hall","civic",
   "A converted timber barn that is, in every practical sense, Drennmark's embassy to a city that has "
   "not agreed to receive one. The Grey Wardens keep a table here.",
   "Warden Aske Vorn has a list of everyone who came south and where they came from. She will not show it."),
 "lighthouse": ("The Waer Light","tower",
   "A stone tower on the point, burning rendered fish-oil in all weathers. Visible eleven miles.",
   "The light has been dark on four nights this year. Each time, a boat came in that nobody logged."),
 "fishhall": ("The Fish Hall","market",
   "Old Waer's market and its parliament. The Waerfolk settle everything here, loudly, before noon.",
   "The Hall voted last month to refuse League grain prices. It has no authority to do that."),
 "gaol": ("The Tar Gaol","military",
   "Debtors, smugglers, and anyone the Tarrow gangs want held. Sentences are commonly served picking oakum.",
   "Half the prisoners are Drennmark folk held for the crime of existing outside the charter."),
 "granary": ("The League Granary","store",
   "Stone, rat-proofed, and the single most important building in Waerhaven. The city grows no food.",
   "It holds eleven weeks of grain. The Factor has told Lathmere it holds twenty."),
 "ropehouse": ("The Ropehouse","works",
   "Three hundred and eighty metres of roofed ropewalk. Cable is laid here for the entire Ythan fleet.",
   "The ropewalk is the longest straight line in the city, and the only place in Waerhaven you cannot be overheard."),
}

# extra keyed locations placed onto existing ordinary buildings
EXTRA = [
 # (name, type, district, blurb, hook)
 ("The Drowned Jarl","inn","theboom","A Hjaldmark-built longhouse turned dockside inn. Sawdust floor, ferocious ale, no questions.","Hjaldmark crews drink here. They have been asking about old shrines on the north shore."),
 ("The Pitch & Pot","tavern","tarrow","Tarrow's own. Outsiders are served slowly and watched closely.","Where you hire someone who will do a thing quietly."),
 ("The Sounding Line","inn","highstrand","Respectable, expensive, and full of pilots and factors.","Charts change hands here that the Charthouse does not know about."),
 ("The Green Bough","tavern","wardenswatch","A Drennmark house, in a Drennmark suburb, serving Drennmark beer badly.","Everyone here left somewhere. Ask why and the room goes quiet."),
 ("The Cold Anchor","tavern","oldwaer","Waerfolk only, in practice. Four centuries of the same six families.","The oldest woman in the room remembers what the Mere sounded like before."),
 ("The Waerman's Rest","inn","hallowgate","The carters' inn by the gate. Beds by the hour, stabling by the night.","First place any news from the south arrives."),
 ("Ilber's Chandlery","shop","theboom","Rope, canvas, pitch, lamp oil, salt beef, and everything else a hull needs.","Ilber extends credit to captains and sells the debt to worse people."),
 ("The Sailmaker's Loft","shop","slipways","A long upper floor where canvas is cut. Twenty women work here and miss nothing on the quay.","The best information network in the city, and it is not for sale."),
 ("Halvard's Ironmongery","shop","hallowgate","Nails, chain, tools, fittings. Halvard is a dwarf of Karrun-Deep, forty years resident.","He has stopped receiving letters from his hold. He has stopped mentioning it."),
 ("The Bone Cutter","service","tarrow","Surgeon. Sets bones, takes limbs, asks nothing.","Treats wounds the Watch would want to hear about."),
 ("Vennik's","shop","highstrand","Instruments, glasses, compasses, and a gnomish proprietor from Lathmere.","Vennik is the League's actual intelligence officer in Waerhaven."),
 ("The Oakum House","works","tarrow","Where old rope is picked apart by the desperate and the sentenced.","Pays in bread. Employs four hundred. Half are children."),
 ("The Sawyers' Hall","guild","slipways","The pit-sawyers' guild, junior to the Moot and furious about it.","Threatening to strike over the doubled quota."),
 ("The Weigh House","civic","hallowgate","Where inbound timber is measured and taxed at the gate.","Timber has been arriving under-measured and over-paid. Somebody is being bought."),
 ("The Chain Winch","works","theboom","The capstan house that raises the boom. Eleven men, four minutes.","The winch was tampered with in spring. It was repaired without a report."),
 ("The Quiet Charter","hidden","theboom","A door in a warehouse wall with no sign, no guild, and no name on any roll.","Runs Drennmark refugees in and League timber out. Everyone benefits. Nobody admits it."),
 ("The Underquay","hidden","theboom","Flooded cellars beneath the old quay, connected, and useful to people who do not like doors.","Floods to the ceiling twice a day. Things are stored here that should not be found."),
 ("Chapel of the Grey Road","temple","wardenswatch","A shed with a painted door, where Drennmark's road-god is asked for a way back.","The Wardens meet here when they do not want to meet at the Grey Hall."),
 ("The Sunfather's Bench","shrine","theboom","A single stone bench and a brazier kept by Sarathal traders far from home.","The brazier lit itself in spring. Twice. The traders have gone very quiet."),
 ("The Nine Masts","tavern","slipways","Shipwrights' house. Nine masts painted on the beam, one for each hull class.","Somebody painted a tenth mast in the night. Nobody will admit to it, and nobody has painted it out."),
]

# assign EXTRA to plausible existing buildings
used=set()
def claim(district, want_kind=None, min_area=0):
    best=None
    for i,b in enumerate(B):
        if i in used or b.get('id'): continue
        if b['district']!=district: continue
        a=b['w']*b['d']
        if a<min_area: continue
        # prefer buildings on a decent street, near the district heart
        d=[x for x in geo['districts'] if x['id']==district][0]
        s=abs(a-160)+math.hypot(b['x']-d['cx'],b['y']-d['cy'])*0.6
        if best is None or s<best[0]: best=(s,i)
    if best is None: return None
    used.add(best[1]); return best[1]

POI=[]
for pid,(name,typ,desc,hook) in LM_TEXT.items():
    b=next((x for x in B if x.get('id')==pid), None)
    if b is None: print("  !! missing landmark", pid); continue
    POI.append(dict(id=pid,name=name,type=typ,x=b['x'],y=b['y'],
                    district=b['district'],desc=desc,hook=hook,mapped=True))
for name,typ,dist,desc,hook in EXTRA:
    i=claim(dist, min_area=70)
    if i is None:
        print("  !! no building for", name); continue
    b=B[i]; pid=name.lower().replace("'","").replace(" ","_").replace("&","and")
    b['id']=pid; b['name']=name; b['kind']=typ
    POI.append(dict(id=pid,name=name,type=typ,x=b['x'],y=b['y'],
                    district=b['district'],desc=desc,hook=hook,mapped=True))
print("keyed locations:", len(POI))

# ============================ FACTIONS ============================
FACTIONS=[
 dict(id="moot", name="The Shipwrights' Moot", power=5, seat="shipmoot",
  desc="The master shipwrights. Elected, hereditary in practice, and the only body in Waerhaven that "
       "can halt the League's fleet by folding its arms.",
  wants="To be recognised as the city's government in name as well as in fact.",
  leader="Master Shipwright Gedda Halloway"),
 dict(id="factor", name="The League Factor", power=3, seat="factorhouse",
  desc="Lathmere's appointed officer, with a writ, a small staff, and no friends.",
  wants="To survive his posting, cover the granary shortfall, and be recalled with honour.",
  leader="Factor Bevis Culm"),
 dict(id="boomwatch", name="The Boom Watch", power=3, seat="watchkeep",
  desc="Harbour guard and city watch in one. Underpaid, over-drilled, and comprehensively bought.",
  wants="Quiet, and the drill money to keep arriving.",
  leader="Watch-Captain Oren Skeld"),
 dict(id="tarrowgangs", name="The Tarrow Houses", power=4, seat="pitchyard",
  desc="Three families holding pitch, tar and oakum as a closed shop, and the labour that goes with it.",
  wants="The fleet order fulfilled at their price, and Tarrow left alone.",
  leader="Old Mother Reth, and her two sons who do not speak to each other"),
 dict(id="wardens", name="The Grey Wardens", power=2, seat="wardenhall",
  desc="Drennmark's pine-marchers, present in a city that has no treaty with them, doing work nobody "
       "has authorised.",
  wants="Waerhaven to understand what is coming south, before it arrives.",
  leader="Warden Aske Vorn"),
 dict(id="quietcharter", name="The Quiet Charter", power=3, seat="the_quiet_charter",
  desc="Not a guild. An arrangement. Moves people in and timber out, and pays everyone a little.",
  wants="The border to stay exactly as unenforced as it is.",
  leader="Nobody will say. Three people are suspected. One of them is right."),
 dict(id="waerfolk", name="The Waerfolk", power=2, seat="fishhall",
  desc="Old Waer's six founding families, who were fishing this point before the League existed and "
       "intend to be here after.",
  wants="The charter loosened, the Fish Hall recognised, and the Mere left alone.",
  leader="Goodwife Ellsa Waer, who holds no office and decides most things"),
]

# ============================ PEOPLE ============================
NPCS=[
 dict(name="Gedda Halloway", role="Master Shipwright of the Moot", where="shipmoot", faction="moot",
   note="Sixties, deaf in one ear from a lifetime of caulking hammers, reads people faster than plans. "
        "Has quietly concluded the League cannot be relied on and is deciding what to do about it."),
 dict(name="Bevis Culm", role="League Factor", where="factorhouse", faction="factor",
   note="Thin, precise, and eleven months into a lie about the granary he cannot now unsay. Would "
        "take a great deal of help from anyone who offered it, and would owe them everything."),
 dict(name="Oren Skeld", role="Watch-Captain", where="watchkeep", faction="boomwatch",
   note="Takes money from three parties and lets refugees through the gate for free, which he considers "
        "balances the account. It does not, but he sleeps."),
 dict(name="Aske Vorn", role="Grey Warden of Drennmark", where="wardenhall", faction="wardens",
   note="Came south four years ago with sixty people and arrived with forty-one. Carries a list. "
        "Will not discuss the nineteen. Has asked the Charthouse for the northern soundings twice."),
 dict(name="Ellsa Waer", role="Goodwife of Old Waer", where="fishhall", faction="waerfolk",
   note="Eighty-one. Holds no office and settles most disputes in the city before they reach anyone "
        "who does. Says the Mere 'has gone quiet in the wrong way'. She is not being poetic."),
 dict(name="Mother Reth", role="Head of the Tarrow Houses", where="pitchyard", faction="tarrowgangs",
   note="Runs pitch, oakum and four hundred desperate workers. Genuinely believes she is the only "
        "person in Waerhaven who feeds the poor, and is very nearly right."),
 dict(name="Vennik Tallowglass", role="Instrument-maker; League intelligence", where="venniks", faction="factor",
   note="Gnome of Lathmere. Sells glasses and compasses, reports everything, and has started omitting "
        "things from his reports because he has grown fond of the place."),
 dict(name="Halvard Stonecut", role="Ironmonger of Karrun-Deep", where="halvards_ironmongery", faction=None,
   note="Forty years out of the holds. His letters home stopped eight months ago. He has written "
        "eleven more and sent them anyway."),
 dict(name="Ilber Cass", role="Chandler and moneylender", where="ilbers_chandlery", faction="quietcharter",
   note="Lends to captains, sells the debt onward, and is one of the three people suspected of "
        "running the Quiet Charter. He is not. He would like to be."),
 dict(name="Sister Nairn", role="Keeper of the Deep Mere", where="templemere", faction=None,
   note="Former mate on a grain hoy. Has been quietly collecting the offerings that wash back ashore "
        "and storing them, because throwing them in again feels like an insult to something."),
 dict(name="Doss the Crane", role="Crane-master", where="greatcrane", faction="quietcharter",
   note="Walks in the drum with three others. Sees every cargo. Says nothing, charges accordingly."),
 dict(name="Tam Ryke", role="Sailmaker's forewoman", where="the_sailmakers_loft", faction=None,
   note="Runs twenty women and the best information network in Waerhaven. Will trade what she knows "
        "for help with things the Watch will not touch."),
]

# ============================ RUMOURS & HOOKS ============================
RUMOURS=[
 "The Waer Light was dark four nights this year, and each night a boat came in unlogged.",
 "The League's standard weight in the Counting Boom is four ounces light, and has been for six years.",
 "Nine masts are missing from the Mast Pond and the count was never repeated.",
 "The Boom Forge is making something behind a screen that is not chain.",
 "Offerings thrown into the Mere have been washing back ashore since the year the Wyrdpines went bad.",
 "Three charts of the northern shore walked out of the Charthouse in spring and were never reported.",
 "Halvard the ironmonger has had no word from Karrun-Deep in eight months. Nor has anyone else.",
 "Someone painted a tenth mast on the beam of the Nine Masts, and no shipwright will paint it out.",
 "The granary holds eleven weeks of grain. The Factor has told Lathmere it holds twenty.",
 "The Sunfather's brazier on the quay lit itself twice this spring with nobody near it.",
 "Warden Vorn came south with sixty people and arrived with forty-one, and will not say what took the nineteen.",
 "The dry dock is repairing a Drennmark boat for free, on nobody's authority.",
]
HOOKS=[
 dict(title="The Short Weight", tier="low",
   text="Ilber Cass will pay well to prove the Counting Boom's standard weight is light — he has been "
        "buying by that weight for six years and wants the loss back. Proving it means getting into a "
        "sealed box that three people can open, none of whom will."),
 dict(title="Nine Masts", tier="low",
   text="Nine seasoned masts are gone from the Mast Pond and the Moot wants them found before the "
        "League asks. They are not stolen so much as moved, and where they went is a question about "
        "who is quietly building a hull nobody ordered."),
 dict(title="The Light Goes Out", tier="mid",
   text="Four dark nights, four unlogged boats. The keeper of the Waer Light is being paid, or "
        "frightened, or replaced. Whoever is landing cargo on the point is not landing cargo."),
 dict(title="The Forty-One", tier="mid",
   text="Aske Vorn's list names everyone who came south out of the Wyrdpines and where they came from. "
        "Two names on it are of people who are here in Waerhaven and should not be — because Vorn "
        "watched them die on the road."),
 dict(title="Eleven Weeks", tier="mid",
   text="The granary is two-thirds empty and the Factor has lied about it to Lathmere. Winter is "
        "coming, the Wardenswatch has nineteen hundred extra mouths, and the League's grain barges "
        "will stop the moment the lie is found. Someone has to fix this before it is discovered — or "
        "make sure it is discovered at exactly the right moment."),
 dict(title="What the Mere Gives Back", tier="high",
   text="Sister Nairn has a locked room of offerings the Mere has returned. They are not weathered. "
        "Some are not the offerings that were thrown. The Temple wants it quiet, the Waerfolk want it "
        "answered, and something under the harbour is very patiently making a point."),
 dict(title="The Tenth Mast", tier="high",
   text="A hull class that does not exist has been painted on the Nine Masts' beam, the Boom Forge is "
        "making something that is not chain, and three northern charts are missing. Somebody in "
        "Waerhaven is building a ship for a voyage the League has not authorised, to a shore the "
        "Charthouse has stopped admitting exists."),
]

# ============================ ASSEMBLE ============================
POP=WA['pop']
out=dict(
 meta=dict(name="Waerhaven", realm="The Ythan League", realmId="ythan",
   settlementId=WA['id'], tier=WA['tier'], population=POP,
   worldX=WA['x'], worldY=WA['y'],
   width=CW, height=CH, metresPerPixel=1.0,
   subtitle="Second City of the Ythan League; the yard that builds its fleet",
   note="x,y are metres on the city plan, matching waerhaven-map.svg viewBox 0 0 %d %d"%(CW,CH)),
 summary=dict(
   seat="A promontory on the north shore of the Ythan Mere, water on three sides, deep forest behind.",
   economy="Timber, shipbuilding, cordage, pitch. No river: every log is hauled in overland by ox-road.",
   government="Chartered League city. A Factor appointed from Lathmere; a Shipwrights' Moot that "
              "actually decides; a Fish Hall in Old Waer that decides what neither of them dares to.",
   defences="A landward curtain wall with three gates, and a chain boom across the harbour mouth "
            "between two towers.",
   tension="Waerhaven is the League's closest city to Drennmark. For six years people have been "
           "coming south out of the Wyrdpines. The charter says the city may not take them. The "
           "slipways cannot meet the League's fleet order without them."),
 districts=[dict(id=k, **{kk:vv for kk,vv in v.items()},
                 centre=[d['cx'],d['cy']], radius=d['r'], colour=d['tone'],
                 buildings=sum(1 for b in B if b['district']==k))
            for k,v in DISTRICTS.items()
            for d in geo['districts'] if d['id']==k],
 shore=geo['shore'], curtain=geo['curtain'], moleN=geo['moleN'], moleS=geo['moleS'],
 gates=geo['gates'], towers=geo['towers'],
 streets=plan['named'], plazas=plan['plazas'], piers=plan['piers'], slips=plan['slips'],
 buildings=B, locations=POI, factions=FACTIONS, people=NPCS,
 rumours=RUMOURS, hooks=HOOKS,
)
json.dump(out, open('/sessions/dazzling-jolly-pasteur/mnt/outputs/waerhaven.json','w'), indent=1)
import os
print("waerhaven.json", round(os.path.getsize('/sessions/dazzling-jolly-pasteur/mnt/outputs/waerhaven.json')/1024),"KB")
print("districts:", len(out['districts']), " locations:", len(POI),
      " factions:", len(FACTIONS), " people:", len(NPCS),
      " buildings:", len(B), " streets(named):", len(plan['named']))
print("people/building:", round(POP/len(B),1))
