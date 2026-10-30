import json
from PIL import Image, ImageDraw, ImageFont
O='out/'; M=json.load(open(O+'manifest.json'))
try: F=ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 15)
except Exception: F=ImageFont.load_default()
cells=[]
for slot in ['base','hair','armour','main','off']:
    for it in M['slots'][slot]: cells.append((f"{slot}: {it['label']}", O+it['file']))
cells.append(('cut mask (main hand)', O+'cut_hand_r.png'))
Z=2; cw, ch = 192*Z+16, 320*Z+40; cols=6
rows=(len(cells)+cols-1)//cols
s=Image.new('RGB',(cw*cols, ch*rows+50),(36,22,13)); d=ImageDraw.Draw(s)
d.text((10,14),'Rivermark paper doll PoC: every layer at 2x on its full 192x320 canvas (checker = transparent)',font=F,fill=(243,216,152))
for i,(lab,f) in enumerate(cells):
    x=(i%cols)*cw+8; y=(i//cols)*ch+50
    chk=Image.new('RGB',(192*Z,320*Z))
    cd=ImageDraw.Draw(chk)
    for yy in range(0,320*Z,16):
        for xx in range(0,192*Z,16): cd.rectangle([xx,yy,xx+15,yy+15],fill=(228,205,160) if (xx//16+yy//16)%2 else (248,235,205))
    im=Image.open(f).convert('RGBA').resize((192*Z,320*Z),Image.NEAREST)
    chk.paste(im,(0,0),im); s.paste(chk,(x,y+26)); d.text((x,y+4),lab,font=F,fill=(244,230,196))
s.save('contact_sheet.png'); print(s.size)
