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
        self.c = client; self.label = label; self.problems = []; self.lines = []
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
        for _ in range(4):
            r = self.c.req('location/travel', {'location_id': LOCS[key]})
            for _ in range(10):
                s = self.c.req('location/current')
                if not s.get('combat'):
                    break
                self.c.req('combat/action', {'action': 'flee'})
            if self.here().get('key') == key:
                return True
        self.problem('travel', f'could not reach {key} (at {self.here().get("key")}): {str(r.get("error") or "")[:120]}')
        return False

    # -- conversation ------------------------------------------------------
    def open(self, npc):
        if npc not in ('sera', 'aldric', 'kessa', 'ilse'):
            here = self.here()
            if npc not in [n.get('npc_key') for n in here.get('npcs', [])]:
                self.problem('placement', f'{npc} is not at {here.get("key")}')
        r = self.c.req('dialogue/node', {'npc_key': npc})
        node = r.get('node') or {}
        self.lines.append((npc, node.get('text', '')))
        return node

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
                    self.lines.append((npc, nxt.get('text', '')))
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


def play(client, outcome, vote=None):
    label = outcome + (f'/{vote}' if vote else '')
    print(f'\n== {label} ==')
    r = Run(client, label)
    fate = FATE_OF[outcome]
    r.seed(level=5, gold=600 if outcome == 'subscribed' else 200, at='overseers_office', companions=COMPANIONS[outcome],
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
    if outcome == 'costed':
        r.travel('granary_row'); r.talk('hesper_amsel', 'What are you counting', 'Some things', 'Leave her to count')
        ok(f'{label}: the Costing', (r.qstatus('the_costing') or ('',))[0] == 'completed', str(r.qstatus('the_costing')))
        journal = r.quests().get('the_costing', {}).get('current_journal') or ''
        ok(f'{label}: the Costing journal points back to Number Nine', 'Number Nine' in journal, journal[-120:])
        r.travel('number_nine'); r.talk('minna_crale', 'Send it round')
        ok(f'{label}: the figure is circulated', r.flags().get('costing_figure') == 'circulated')
        ok(f'{label}: Minna does not call the finished count commissioned', not r.said('minna_crale', 'is commissioned'))
    elif outcome == 'minuted':
        r.travel('record_office'); r.talk('dorcas_fenn', 'Read it.', 'Copy it out')
        ok(f'{label}: the Bound Minute', (r.qstatus('the_bound_minute') or ('',))[0] == 'completed' and r.flags().get('minute_fate') == 'copied')
        ok(f'{label}: Dorcas does not promise the volume after offering it', not r.said('dorcas_fenn', "quarter's end"))
    elif outcome == 'subscribed':
        r.travel('number_nine')
        r.talk('minna_crale', {'against': 'Lodge it now', 'proxy': 'Write a proxy'}.get(vote, 'keep it, and come'))
        ok(f'{label}: One Vote in Twelve', (r.qstatus('one_vote_in_twelve') or ('',))[0] == 'completed',
           str(r.qstatus('one_vote_in_twelve')))
    elif outcome == 'walked':
        r.travel('flagon_common_room'); r.talk('mara_hearthstone', 'Write back and say why')
        ok(f'{label}: Item Seven Adjourned', (r.qstatus('item_seven_adjourned') or ('',))[0] == 'completed' and r.flags().get('walked_fate') == 'reasons')

    # The eve and the packet.
    mark = len(r.lines)
    r.travel('flagon_common_room'); r.talk('mara_hearthstone', 'Sit a while', 'Go up')
    ok(f'{label}: the eve', r.flags().get('quarter_eve') == '1')
    ok(f'{label}: the eve does not put the room tomorrow', not r.said('mara_hearthstone', 'tomorrow', mark))
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
    else:
        # Tull is on the river; the town waits.
        r.travel('packet_stair')
        ok(f'{label}: the stair is empty while the boat is away', 'osgar_tull' not in [n.get('npc_key') for n in r.here().get('npcs', [])])
        r.travel('flagon_common_room')
        ok(f'{label}: waiting at the Flagon passes the days', r.click_until('mara_hearthstone', 'days_waited'))
        r.travel('packet_stair'); r.talk('osgar_tull', 'Read the sheet', 'Go up into the town')
        ok(f'{label}: the room sat', r.flags().get('room_sat') == '1')
        mark = len(r.lines)

        # After the sheet.
        r.travel('deepworks_canteen'); r.talk('corrin_dale', "I'll bring a copy")
        r.travel('counting_house'); r.talk('tibald_ashe', 'Take it down to the canteen')
        r.travel('deepworks_canteen'); r.talk('corrin_dale', 'Read it word for word')
        ok(f'{label}: In Its Order', r.qstatus('in_its_order') == ('completed', 'read_word_for_word'), str(r.qstatus('in_its_order')))
        r.travel('packet_stair'); r.talk('osgar_tull', "I'll take it down to them")
        r.travel('timekeepers_hut'); r.talk('wenna_coyle', 'Struck', 'Read the fifteen')
        ok(f'{label}: The Uncounted', r.qstatus('the_uncounted') == ('completed', 'read_out'), str(r.qstatus('the_uncounted')))
        ok(f'{label}: Tull no longer offers first light', not r.said('osgar_tull', "whether you're on the step", mark))
        stale = {'costed': 'carry the costing up', 'minuted': 'Word for word, Moss says', 'subscribed': 'owner aboard', 'walked': 'dropped his pen'}[outcome]
        ok(f'{label}: Tull\'s meeting line does not come after the sheet', not r.said('osgar_tull', stale, mark))
        ok(f'{label}: the minute is not delivered', not any(k.startswith('minute_read') or k == 'vote_result' for k in r.flags()))
    left = [k for k, q in r.quests().items() if q['status'] == 'active' and q.get('act') == 2]
    ok(f'{label}: no Act II quest left hanging', not left, str(left))
    ok(f'{label}: no blockers on the way', not r.problems, '\n         '.join(r.problems))
    return r


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
        for outcome, vote in runs:
            if only and only not in f'{outcome}/{vote}':
                continue
            try:
                play(client, outcome, vote)
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
