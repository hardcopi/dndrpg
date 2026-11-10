#!/usr/bin/env python3
"""Play Act II end to end over HTTP, the way the browser does.

Each run makes a fresh character on a throwaway account and drives it through
the real ?r= routes (location/travel, dialogue/node, dialogue/choose,
quest/list): the Survey, the Act I epilogue, Tull's packet, Brackwater, the
Quarterly Meeting, that ending's follow-up quest, the eve, the packet, the
waiting days, the sheet, and the two quests that come after it.

Replies are chosen by their words, and NPCs are only spoken to where the scene
puts them, so a reply that is missing, a person who is not where the story
sends you, or a flag that never gets set fails here. That is the point of it.

The only state set directly is what Act I would have left behind (level 5,
the Deepworks reachable, the tutorial done), via tools/act_two_play_seed.php.

    python3 tools/test_act_two_play.py            # starts php -S itself
    BASE=http://127.0.0.1:8098 python3 tools/test_act_two_play.py

Removes its account and everything it made. Safe against a database in use.
"""
import http.cookiejar, json, os, random, socket, string, subprocess, sys, time, urllib.error, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.dirname(HERE)
SEED = os.path.join(HERE, 'act_two_play_seed.php')
PASS = FAIL = 0


def ok(name, cond, detail=''):
    global PASS, FAIL
    if cond:
        PASS += 1; print(f'  ok   {name}')
    else:
        FAIL += 1; print(f'  FAIL {name}' + (f'\n         {detail}' if detail else ''))


def php(*args):
    out = subprocess.run(['php', SEED, *map(str, args)], capture_output=True, text=True)
    if out.returncode != 0 or out.stderr.strip():
        print('   seed stderr:', out.stderr.strip()[:300])
    return json.loads(out.stdout.strip().splitlines()[-1]) if out.stdout.strip() else {}


class Client:
    def __init__(self, base):
        self.base = base
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''

    def req(self, route, body=None):
        url = f'{self.base}/api/index.php?r={route}'
        if body is None:
            r = urllib.request.Request(url)
        else:
            r = urllib.request.Request(url, data=json.dumps(body).encode(), method='POST',
                                       headers={'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf})
        try:
            raw = self.op.open(r, timeout=60).read().decode()
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
        try:
            d = json.loads(raw)
        except ValueError:
            d = {'ok': False, 'error': raw[:300]}
        if isinstance(d, dict) and d.get('csrf_token'):
            self.csrf = d['csrf_token']
        return d


LOCS = {}


class Run:
    """One character's playthrough. Problems are collected, not raised, so one run reports all of them."""

    def __init__(self, client, label):
        self.c = client; self.label = label; self.problems = []; self.lines = []; self.faces = set()
        name = 'Walker ' + ''.join(random.choice(string.ascii_lowercase) for _ in range(9)).title()
        m = self.c.req('character/create', {'name': name, 'race': 'Human', 'class': 'Fighter', 'method': 'standard',
                                            'abilities': {'strength': 15, 'dexterity': 14, 'constitution': 13, 'intelligence': 12, 'wisdom': 10, 'charisma': 8},
                                            'background': 'Soldier', 'alignment': 'Neutral Good', 'module': 'rivermark'})
        if 'character' not in m:
            raise SystemExit(f'character/create failed: {m.get("error")}')
        self.cid = m['character']['id']
        self.c.req('session/select', {'character_id': self.cid}); self.c.req('auth/status')
        self.pid = None

    def problem(self, kind, msg):
        self.problems.append(f'[{kind}] {msg}')

    def seed(self, **ops):
        self.pid = php('seed', self.cid, json.dumps(ops))['party_id']

    def flags(self):
        return php('flags', self.pid)

    def quests(self):
        return {q['quest_key']: q for q in self.c.req('quest/list').get('mine', [])}

    def qstatus(self, key):
        q = self.quests().get(key)
        return (q['status'], q.get('current_stage_key')) if q else None

    def here(self):
        return (self.c.req('location/current').get('location') or {})

    def travel(self, key):
        """Walk there. A random fight on the way is fled; one that will not let go (a failed flee roll
        after another) is ended by the seed tool, because the fights are not what this test is about,
        and an unlucky die in CI is not a blocker in the act."""
        r = {}
        for _ in range(6):
            r = self.c.req('location/travel', {'location_id': LOCS[key]})
            for _ in range(12):
                if not self.c.req('location/current').get('combat'):
                    break
                self.c.req('combat/action', {'action': 'flee'})
            else:
                php('endfight', self.cid)
            if self.here().get('key') == key:
                return True
        self.problem('travel', f'could not reach {key} (at {self.here().get("key")}): {str(r.get("error") or r.get("messages") or r.get("events") or "")[:200]}')
        return False

    # -- conversation ------------------------------------------------------
    def open(self, npc):
        if npc not in ('sera', 'aldric', 'kessa', 'ilse'):
            here = self.here()
            if npc not in [n.get('npc_key') for n in here.get('npcs', [])]:
                self.problem('placement', f'{npc} is not at {here.get("key")}')
        r = self.c.req('dialogue/node', {'npc_key': npc})
        node = r.get('node') or {}
        self.heard(npc, node)
        return node

    def heard(self, npc, node):
        self.lines.append((npc, node.get('text', '')))
        for aside in node.get('interjections') or []:
            self.lines.append((f"{aside.get('companion')}@{npc}", aside.get('text', '')))
        sp = node.get('speaker') or {}
        if sp:
            self.faces.add((sp.get('name'), sp.get('bust'), sp.get('image_url')))

    def choose(self, npc, node, label):
        for i, ch in enumerate(node.get('choices', [])):
            if label.lower() in (ch.get('label') or '').lower() and not ch.get('locked') and not ch.get('spent'):
                r = self.c.req('dialogue/choose', {'npc_key': npc, 'node_key': node.get('node_key'), 'choice': i})
                if r.get('check'):
                    chk = r['check']
                    r = self.c.req('dialogue/resolve_check', {'check_id': chk.get('id') or chk.get('check_id'), 'boosts': []})
                if r.get('ok') is False:
                    self.problem('dialog', f'{npc} "{label}": {r.get("error")}')
                nxt = r.get('node')
                if nxt:
                    self.heard(npc, nxt)
                return nxt
        return False

    def talk(self, npc, first, *then, tries=8):
        """Open npc until `first` is offered (spending earlier one-off lines the way a player clicking through
        would), then take `then` in order wherever it appears, filling gaps with the first ordinary reply."""
        for _ in range(tries):
            node = self.open(npc)
            labels = [c.get('label') or '' for c in node.get('choices', [])]
            if any(first.lower() in l.lower() for l in labels):
                node = self.choose(npc, node, first)
                want = list(then)
                for _ in range(12):
                    if not node or not node.get('choices') or not want:
                        break
                    labels = [c.get('label') or '' for c in node['choices']]
                    hit = next((l for l in labels if want[0].lower() in l.lower()), None)
                    if hit:
                        want.pop(0); node = self.choose(npc, node, hit)
                    else:
                        filler = next((l for l in labels if not any(a in l for a in ('Back', 'Not yet', 'Not now', 'Leave', 'Later'))), None)
                        if filler is None:
                            break
                        node = self.choose(npc, node, filler)
                if want:
                    self.problem('missing_reply', f'{npc}: never offered {want}')
                return True
            # Walk out of whatever this was by its last reply.
            for _ in range(6):
                if not node or not node.get('choices'):
                    break
                node = self.choose(npc, node, node['choices'][-1]['label'])
        self.problem('missing_reply', f'{npc}: "{first}" never offered in {tries} opens')
        return False

    def click_until(self, npc, flag, tries=6):
        for _ in range(tries):
            if self.flags().get(flag):
                return True
            node = self.open(npc)
            for _ in range(6):
                if not node or not node.get('choices'):
                    break
                node = self.choose(npc, node, node['choices'][-1]['label'])
        return bool(self.flags().get(flag))

    def book_passage(self):
        # Tull's way in differs by branch (a sold survey gets "Write it however you like"); take whichever he offers.
        for _ in range(4):
            node = self.open('osgar_tull')
            for _ in range(4):
                labels = [c.get('label') or '' for c in (node or {}).get('choices', [])]
                pick = next((l for l in labels if l.startswith('Sixty')), None) \
                    or next((l for l in labels if 'Write it however' in l or 'What does a place cost' in l), None)
                if not pick:
                    break
                node = self.choose('osgar_tull', node, pick)
                if pick.startswith('Sixty'):
                    return True
        self.problem('missing_reply', 'Tull never offered a place on the packet')
        return False

    def said(self, npc, needle, since=0):
        return any(n == npc and needle.lower() in t.lower() for n, t in self.lines[since:])


SURVEY_END = {'took': ('granary_row', 'alder_pyke', ['Read it to me', 'Roll it up']),
              'warned': ('granary_row', 'alder_pyke', ['Read it to me', 'Louder']),
              'burned': ('granary_row', 'alder_pyke', ['Read it to me', 'Burn it']),
              'sold': ('factors_house', 'ludo_corse', ['Buy it back', 'Done'])}
MINNA_OPEN = {'took': 'nine sheets', 'burned': 'survey that burned', 'sold': None, 'warned': 'read aloud'}
BRANCH = {'took': [('record_office', 'dorcas_fenn', ['Lay the survey', 'File it'])],
          'burned': [('the_fortieth', 'mr_pellow', ['I heard', 'It was us'])],
          'sold': [('number_nine', 'minna_crale', ['books', 'Let me see it', 'clause', 'Thank you'])],
          'warned': [('the_fortieth', 'pyke_downriver', ['What do you need', 'go to the yard']), ('the_yard', 'abel_moss', ['seen him work'])]}
HOLLIN = {'costed': ['Go on', 'cleared town', 'Cost it', 'Thank you'], 'minuted': ['Go on', 'objection', "That's all"],
          'subscribed': ['Go on', 'empty chair', 'pay the call', 'Mrs Orme'], 'walked': ['Go on', 'heard enough', 'stairs']}
FATE_OF = {'costed': 'took', 'minuted': 'burned', 'subscribed': 'sold', 'walked': 'warned'}
COMPANIONS = {'costed': ['aldric', 'ilse'], 'minuted': ['aldric', 'sera'], 'subscribed': ['sera', 'kessa'], 'walked': ['kessa', 'ilse']}


def play(client, outcome, vote=None, costing='whole', figure='circulated', minute='copied', walked='reasons',
         order='word', uncounted='read'):
    label = outcome + (f'/{vote}' if vote else '')
    for k, v, d in (('given', costing, 'whole'), ('read_first', figure, 'circulated'), ('shelved', minute, 'copied'),
                    (walked, walked, 'reasons'), (f'order:{order}', order, 'word'), (f'uncounted:{uncounted}', uncounted, 'read')):
        if v != d:
            label += f'/{k}'
    print(f'\n== {label} ==')
    r = Run(client, label)
    fate = FATE_OF[outcome]
    r.seed(level=5, gold=600 if outcome == 'subscribed' else 200, at='overseers_office', companions=(os.environ['PARTY'].split(',') if os.environ.get('PARTY') else COMPANIONS[outcome]),
           quests={'first_night': 'done'}, flags={'deepworks_known': 1, 'warren_floor_fate': 'sounded'})

    # The Survey, from the overseer's office.
    r.talk('bael_rourke', 'looking at the works', 'rack', 'Take the fair sheets')
    loc, npc, labels = SURVEY_END[fate]
    r.travel(loc); r.talk(npc, labels[0], *labels[1:])
    ok(f'{label}: the Survey ends {fate}', r.flags().get('survey_fate') == fate and r.flags().get('act_two_open'), str(r.qstatus('the_survey')))
    ok(f'{label}: taking the sheets closes the office too', (r.qstatus('the_overseers_office') or ('',))[0] == 'completed', str(r.qstatus('the_overseers_office')))
    r.travel('flagon_common_room'); r.talk('mara_hearthstone', 'Go on', "I'll see to it")
    ok(f'{label}: the Act I epilogue', (r.qstatus('act_one_epilogue') or ('',))[0] == 'completed', str(r.qstatus('act_one_epilogue')))

    # Tull, Brackwater, the meeting.
    r.travel('packet_stair')
    r.book_passage()
    ok(f'{label}: passage booked', r.flags().get('passage_booked') == '1')
    r.talk('osgar_tull', 'Go aboard')
    ok(f'{label}: Tull carries the party to Brackwater', r.here().get('key') == 'brackwater_wharf', r.here().get('key'))
    r.travel('the_yard'); r.talk('abel_moss', 'looking for the house', 'Number Nine')
    r.travel('number_nine')
    if MINNA_OPEN[fate]:
        r.talk('minna_crale', MINNA_OPEN[fate])
    for loc, npc, labels in BRANCH[fate]:
        r.travel(loc); r.talk(npc, labels[0], *labels[1:])
    r.travel('number_nine'); r.talk('minna_crale', 'Go up')
    r.talk('hollin_sare', HOLLIN[outcome][0], *HOLLIN[outcome][1:])
    ok(f'{label}: the meeting ends {outcome}', r.qstatus('the_quarterly_meeting') == ('completed', outcome), str(r.qstatus('the_quarterly_meeting')))

    # The ending's own follow-up.
    kept = vote in ('aboard', 'stair')
    if os.environ.get('SKIP_FOLLOWUP'):
        mark = len(r.lines)
        r.travel('flagon_common_room'); node = r.open('mara_hearthstone')
        print('   eve offered without the follow-up?', [c.get('label') for c in node.get('choices', [])], (node.get('text') or '')[:200])
        for _ in range(4):
            if not node or not node.get('choices'): break
            node = r.choose('mara_hearthstone', node, node['choices'][-1]['label'])
        print('   quarter_eve:', r.flags().get('quarter_eve'), {k: v for k, v in r.quests().items() if v['status'] == 'active' and v.get('act') == 2}.keys())
    if outcome == 'costed':
        r.travel('granary_row')
        if costing == 'whole':
            r.talk('hesper_amsel', 'What are you counting', 'Some things', 'Leave her to count')
        else:
            r.talk('hesper_amsel', 'What are you counting', 'Count it your way', 'Leave her to it')
        ok(f'{label}: the Costing', r.qstatus('the_costing') == ('completed', f'counted_{costing}') and r.flags().get('costing_fate') == costing,
           str(r.qstatus('the_costing')))
        journal = r.quests().get('the_costing', {}).get('current_journal') or ''
        ok(f'{label}: the Costing journal points back to Number Nine', 'Number Nine' in journal, journal[-120:])
        r.travel('number_nine'); r.talk('minna_crale', 'Send it round' if figure == 'circulated' else 'Let it be read first')
        ok(f'{label}: the figure is {figure}', r.flags().get('costing_figure') == figure)
        ok(f'{label}: Minna does not call the finished count commissioned', not r.said('minna_crale', 'is commissioned'))
        mark = len(r.lines); r.open('hollin_sare')
        ok(f'{label}: nor the chair call it in hand', r.said('hollin_sare', 'count is on the table', mark), str(r.lines[mark:])[:200])
    elif outcome == 'minuted':
        r.travel('record_office'); r.talk('dorcas_fenn', 'Read it.', 'Copy it out' if minute == 'copied' else 'Leave it on the shelf')
        ok(f'{label}: the Bound Minute', r.qstatus('the_bound_minute') == ('completed', minute) and r.flags().get('minute_fate') == minute,
           str(r.qstatus('the_bound_minute')))
        ok(f'{label}: Dorcas does not promise the volume after offering it', not r.said('dorcas_fenn', "quarter's end"))
    elif outcome == 'subscribed':
        r.travel('number_nine')
        r.talk('minna_crale', {'against': 'Lodge it now', 'proxy': 'Write a proxy'}.get(vote, 'keep it, and come'))
        ok(f'{label}: One Vote in Twelve', (r.qstatus('one_vote_in_twelve') or ('',))[0] == 'completed',
           str(r.qstatus('one_vote_in_twelve')))
    elif outcome == 'walked':
        r.travel('flagon_common_room')
        r.talk('mara_hearthstone', {'reasons': 'Write back and say why', 'withdrawn': 'Write back: withdrawn', 'stands': 'Let it stand'}[walked])
        ok(f'{label}: Item Seven Adjourned', r.qstatus('item_seven_adjourned') == ('completed', walked) and r.flags().get('walked_fate') == walked,
           str(r.qstatus('item_seven_adjourned')))

    # The eve and the packet.
    mark = len(r.lines)
    r.travel('flagon_common_room'); r.talk('mara_hearthstone', 'Sit a while', 'Go up')
    ok(f'{label}: the eve', r.flags().get('quarter_eve') == '1')
    ok(f'{label}: the eve does not put the room tomorrow', not r.said('mara_hearthstone', 'tomorrow', mark))
    ok(f'{label}: nobody calls a vote a costing on the eve', outcome == 'costed' or not r.said('sera@mara_hearthstone', 'costing', mark))
    if outcome != 'costed':
        ok(f'{label}: Sera does not credit the room with our numbers', not r.said('sera@mara_hearthstone', 'your numbers', mark))
    r.travel('packet_stair')
    if kept:
        r.talk('osgar_tull', 'Walk down to the plank', *(['Step aboard', 'Go below'] if vote == 'aboard' else ['Step back']))
    else:
        r.talk('osgar_tull', 'Watch them load', 'Watch it out of sight')
    ok(f'{label}: the packet goes', r.flags().get('packet_gone') == '1')
    if vote == 'aboard':
        ok(f'{label}: boarding carries the party down', r.here().get('key') == 'brackwater_wharf' and r.flags().get('vote_aboard') == '1', r.here().get('key'))
        r.travel('number_nine'); r.talk('hollin_sare', 'Leave')
        ok(f'{label}: the chair asks nothing the night before', r.said('hollin_sare', "shan't ask you anything"))
        ok(f'{label}: nothing reaches the room', not r.flags().get('room_sat'))
        # The cliffhanger holds: no boat back up from the wharf once you have gone down on the quarter-day.
        r.travel('brackwater_wharf')
        exits = r.here().get('exits') or []
        ok(f'{label}: no packet back upriver after stepping aboard',
           all(e.get('locked') for e in exits if e.get('to_key') == 'packet_stair'), str([(e.get('label'), e.get('locked')) for e in exits]))
        r.c.req('location/travel', {'location_id': LOCS['packet_stair']})
        ok(f'{label}: and no route round to the stair', r.here().get('key') != 'packet_stair', r.here().get('key'))
    else:
        # Tull is on the river; the town waits.
        r.travel('packet_stair')
        ok(f'{label}: the stair is empty while the boat is away', 'osgar_tull' not in [n.get('npc_key') for n in r.here().get('npcs', [])])
        ok(f'{label}: and painted empty', r.here().get('scene') == 'packet_stair_away'
           and os.path.isfile(os.path.join(SRC, 'assets/images/scenes/packet_stair_away.jpg')), str(r.here().get('scene')))
        ok(f'{label}: and there is no boat to board', all(e.get('locked') for e in r.here().get('exits', []) if e.get('to_key') == 'brackwater_wharf'),
           str([(e.get('label'), e.get('locked')) for e in r.here().get('exits', [])]))
        if outcome == 'costed':
            mark = len(r.lines)
            r.travel('granary_row'); r.open('alder_pyke')
            ok(f'{label}: Pyke is not still measuring the Row once the count is in', not r.said('alder_pyke', 'want the Row costed', mark))
        r.travel('flagon_common_room')
        ok(f'{label}: waiting at the Flagon passes the days', r.click_until('mara_hearthstone', 'days_waited'))
        r.travel('packet_stair'); r.talk('osgar_tull', 'Read the sheet', 'Go up into the town')
        ok(f'{label}: the room sat', r.flags().get('room_sat') == '1')
        ok(f'{label}: the packet is back in the painting', r.here().get('scene') is None, str(r.here().get('scene')))
        mark = len(r.lines)

        # After the sheet.
        r.travel('deepworks_canteen'); r.talk('corrin_dale', "I'll bring a copy")
        r.travel('counting_house'); r.talk('tibald_ashe', 'Take it down to the canteen')
        r.travel('deepworks_canteen')
        r.talk('corrin_dale', {'word': 'Read it word for word', 'meaning': 'Tell them what you think', 'notyet': "minute's not here yet"}[order])
        stage = {'word': 'read_word_for_word', 'meaning': 'told_what_it_means', 'notyet': 'not_here_yet'}[order]
        ok(f'{label}: In Its Order', r.qstatus('in_its_order') == ('completed', stage), str(r.qstatus('in_its_order')))
        ok(f'{label}: Dale is not still hearing of the costing after the sheet', not r.said('corrin_dale', 'costing the town', mark))
        r.travel('packet_stair'); r.talk('osgar_tull', "I'll take it down to them")
        r.travel('timekeepers_hut')
        r.talk('wenna_coyle', 'Struck', {'read': 'Read the fifteen', 'record': 'Record Office', 'kept': 'Fold it away'}[uncounted])
        if uncounted == 'record':
            ok(f'{label}: the count goes to the Record Office', r.qstatus('the_uncounted') == ('active', 'to_the_record'), str(r.qstatus('the_uncounted')))
            r.travel('record_office'); r.talk('dorcas_fenn', 'Enter it as given')
        stage = {'read': 'read_out', 'record': 'entered', 'kept': 'kept'}[uncounted]
        ok(f'{label}: The Uncounted', r.qstatus('the_uncounted') == ('completed', stage), str(r.qstatus('the_uncounted')))
        ok(f'{label}: Tull no longer offers first light', not r.said('osgar_tull', "whether you're on the step", mark))
        stale = {'costed': 'carry the costing up', 'minuted': 'Word for word, Moss says', 'subscribed': 'owner aboard', 'walked': 'dropped his pen'}[outcome]
        ok(f'{label}: Tull\'s meeting line does not come after the sheet', not r.said('osgar_tull', stale, mark))
        # Brackwater after the sitting: the chair and the secretary were in the room and say nothing of it.
        mark = len(r.lines)
        r.travel('number_nine'); r.open('hollin_sare'); r.open('minna_crale')
        ok(f'{label}: the chair keeps the minute after the sitting', r.said('hollin_sare', 'with Mrs Crale for the fair copy', mark)
           and not any(r.said('hollin_sare', x, mark) for x in ('costing is in hand', 'Next quarter, then')), str(r.lines[mark:])[:300])
        ok(f'{label}: and so does the secretary', r.said('minna_crale', 'not a line before', mark), str(r.lines[mark:])[:300])
        ok(f'{label}: the minute is not delivered', not any(k.startswith('minute_read') or k == 'vote_result' for k in r.flags()))
    # Every face drawn on the way has a picture: the bust, or the sprite the client falls back to.
    blank = [n for n, bust, img in r.faces
             if not any(u and os.path.isfile(os.path.join(SRC, u.split('?')[0].lstrip('/'))) for u in (bust, img))]
    ok(f'{label}: every speaker has a face', not blank, str(blank))
    if os.environ.get('TRANSCRIPTS'):
        with open(os.path.join(os.environ['TRANSCRIPTS'], label.replace('/', '_').replace(':', '-') + '.txt'), 'w') as f:
            for n, t in r.lines:
                if t:
                    f.write(f'[{n}] {t}\n\n')
    if os.environ.get('AFTER_VISIT') and vote != 'aboard':
        for loc in os.environ['AFTER_VISIT'].split(','):
            if not r.travel(loc): continue
            for n in r.here().get('npcs', []):
                node = r.open(n['npc_key'])
                print(f"   [{loc}/{n['npc_key']}] {(node.get('text') or '')[:260]}")
                for a2 in node.get('interjections') or []: print(f"      ({a2.get('companion')}) {a2.get('text','')[:200]}")
                for _ in range(3):
                    if not node or not node.get('choices'): break
                    node = r.choose(n['npc_key'], node, node['choices'][-1]['label'])
    left = [k for k, q in r.quests().items() if q['status'] == 'active' and q.get('act') == 2]
    ok(f'{label}: no Act II quest left hanging', not left, str(left))
    ok(f'{label}: no blockers on the way', not r.problems, '\n         '.join(r.problems))
    return r


def presence(client):
    """The dialogue route answers only for somebody the party could walk up to."""
    print('\n== presence ==')
    r = Run(client, 'presence')
    r.seed(level=5, at='overseers_office', companions=['sera', 'kessa'], quests={'first_night': 'done'})
    far = r.c.req('dialogue/node', {'npc_key': 'osgar_tull'})
    ok('presence: nobody across town can be opened', 'nobody of that name' in str(far.get('error')), str(far)[:160])
    reply = r.c.req('dialogue/choose', {'npc_key': 'osgar_tull', 'node_key': 'greeting', 'choice': 0})
    ok('presence: nor answered', 'nobody of that name' in str(reply.get('error')), str(reply)[:160])
    ok('presence: somebody in the room can', bool(r.c.req('dialogue/node', {'npc_key': 'bael_rourke'}).get('node')))
    ok('presence: a companion travelling with you can, anywhere', bool(r.c.req('dialogue/node', {'npc_key': 'sera'}).get('node')))
    ok('presence: and at their camp node', bool(r.c.req('dialogue/node', {'npc_key': 'kessa', 'node_key': 'camp'}).get('node')))
    ok('presence: a node key does not get round it', r.c.req('dialogue/node', {'npc_key': 'osgar_tull', 'node_key': 'greeting'}).get('ok') is False)
    # A fight talked down hands over to its parley node, wherever the speaker is placed.
    ok('presence: parley seeded', php('parley', r.cid, 'hesk_dray:call_them_off').get('ok') is True)
    ok('presence: a parley opens its speaker where the fight was',
       bool(r.c.req('dialogue/node', {'npc_key': 'hesk_dray', 'node_key': 'call_them_off'}).get('node')))
    r.travel('flagon_common_room')
    ok('presence: and not after walking away', r.c.req('dialogue/node', {'npc_key': 'hesk_dray', 'node_key': 'call_them_off'}).get('ok') is False)
    ok('presence: no blockers on the way', not r.problems, '\n         '.join(r.problems))


def free_port():
    s = socket.socket(); s.bind(('127.0.0.1', 0)); p = s.getsockname()[1]; s.close(); return p


def main():
    base = os.environ.get('BASE')
    server = None
    if not base:
        port = free_port(); base = f'http://127.0.0.1:{port}'
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', SRC], cwd=SRC,
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                urllib.request.urlopen(f'{base}/api/index.php?r=auth/status', timeout=2); break
            except Exception:
                time.sleep(0.2)
    LOCS.update(php('locations'))
    client = Client(base); client.req('auth/status')
    user = 'a2play_' + ''.join(random.choice(string.digits) for _ in range(10))
    try:
        reg = client.req('auth/register', {'username': user, 'password': 'a-good-long-password-for-a-throwaway'})
        client.req('auth/status')
        if not reg.get('ok'):
            client.req('auth/login', {'username': user, 'password': 'a-good-long-password-for-a-throwaway'}); client.req('auth/status')
        ok('signed in on a throwaway account', client.req('auth/status').get('signed_in') is True)
        runs = [('costed', None), ('minuted', None), ('walked', None), ('subscribed', 'against'),
                ('subscribed', 'proxy'), ('subscribed', 'stair'), ('subscribed', 'aboard')]
        only = os.environ.get('ONLY')
        if not only or only == 'presence':
            presence(client)
        runs = [(o, v, {}) for o, v in runs] + [
            ('costed', None, {'costing': 'given', 'figure': 'read_first', 'order': 'meaning', 'uncounted': 'record'}),
            ('minuted', None, {'minute': 'shelved', 'order': 'notyet', 'uncounted': 'kept'}),
            ('walked', None, {'walked': 'withdrawn', 'uncounted': 'record'}),
            ('walked', None, {'walked': 'stands', 'order': 'meaning', 'uncounted': 'kept'}),
        ]
        for outcome, vote, opts in runs:
            if only and only not in f'{outcome}/{vote}/' + '/'.join(map(str, opts.values())):
                continue
            try:
                play(client, outcome, vote, **opts)
            except Exception as e:  # one broken run should not hide the others
                ok(f'{outcome}/{vote}: played without crashing', False, repr(e)[:300])
    finally:
        php('cleanup', user)
        if server:
            server.terminate()
    print(f'\n{PASS} passed, {FAIL} failed')
    sys.exit(1 if FAIL else 0)


if __name__ == '__main__':
    main()
