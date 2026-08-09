#!/usr/bin/env python3
"""
Walk the authored tree under content/ and print a reachability report.

tools/load_content.py answers "is this well formed" — every reference resolves,
every node can be reached, every quest ends. This answers the question after
that one: "can a *player* get there, and does anything ever read what we wrote?"

    python3 tools/trace_content.py              # full report
    python3 tools/trace_content.py --quiet      # findings only
    python3 tools/trace_content.py --flags      # just the flag ledger
    python3 tools/trace_content.py --openings   # just what greetings notice
    python3 tools/trace_content.py --economy    # just the approval economy

Five things it looks for that a loader cannot:

  * An ending nothing reaches. The loader is satisfied if *some* quest_stage
    effect names a terminal stage. That effect may itself sit in a dialogue
    node no conversation can reach, or on a quest stage nothing enters. This
    walks the dialogue graphs from their start nodes first and only counts
    effects that survive that walk.

  * A flag that is set and never read. It looks like memory and it is dead
    weight: no variant changes, no option unlocks, nothing happens.

  * A flag that is read and never set. The variant guarding it can never win,
    so the fallback below it is the only thing that will ever be shown, and
    the authored text above it is invisible.

  * An ending nobody greets you differently for. A player finishes a quest,
    walks back to the person it was about, and hears the line they heard
    before. The quest worked; the world did not notice. This is the check that
    would have caught `kessa_debt_fate=paid` — Kessa's opening reacted to the
    ending where you sell her out and to neither of the two endings most
    players actually get, so paying off her debt changed her greeting not at
    all. See openings() for what counts as noticing.

  * A companion nobody can be forgiven by. Approval is a sum across files and
    no file can see the sum, so it drifted until 93% of it lived in quest
    resolutions — one-shot, mutually exclusive, and often set against each
    other. Talking moved the number by almost nothing, so a companion pushed
    under `leave_at` by a decision was gone for good. This adds up what a
    single playthrough can earn by conversation and compares it against the
    line each companion actually walks out on. See economy().

The last four are warnings rather than errors — a flag written by the engine
(an encounter's victory_flag) or consumed by it may legitimately have only one
half in content/ — but every one of them is a thing somebody meant to finish.
"""

import argparse
import json
import os
import re
import sys
from collections import defaultdict

import load_content

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENT = os.path.join(ROOT, "content")

KINDS = ("items", "monsters", "spells", "npcs", "dialog",
         "encounters", "quests", "companions")

KEY_FIELD = {
    "items": "item_key", "monsters": "monster_key", "spells": "spell_key",
    "npcs": "npc_key", "dialog": "npc_key", "encounters": "encounter_key",
    "quests": "quest_key", "companions": "companion_key",
}

JUMP_KEYS = ("on_success", "on_failure")


# ---------------------------------------------------------------------------
def load():
    tree = {k: {} for k in KINDS}
    for kind in KINDS:
        d = os.path.join(CONTENT, kind)
        if not os.path.isdir(d):
            continue
        for name in sorted(os.listdir(d)):
            if not name.endswith(".json"):
                continue
            obj = json.load(open(os.path.join(d, name), encoding="utf-8"))
            key = obj.get(KEY_FIELD[kind])
            if isinstance(key, str):
                tree[kind][key] = obj
    return tree


def variants(node):
    return node if isinstance(node, list) else [node]


def choice_jumps(choice):
    """Where one choice can land: next, or either side of a check."""
    if not isinstance(choice, dict):
        return
    if isinstance(choice.get("next"), str):
        yield choice["next"]
    chk = choice.get("check")
    if isinstance(chk, dict):
        for k in JUMP_KEYS:
            if isinstance(chk.get(k), str):
                yield chk[k]


# ---------------------------------------------------------------------------
# Dialogue graphs
# ---------------------------------------------------------------------------
class Graph:
    """One dialogue file, walked from its start node."""

    def __init__(self, dialog_key, obj):
        self.key = dialog_key
        self.start = obj.get("start")
        self.nodes = obj.get("nodes") or {}
        self.inbound = defaultdict(list)   # node -> ["from.choice[i]", ...]
        self.reachable = set()
        self._edges()
        self._walk()

    def _edges(self):
        for nk, node in self.nodes.items():
            for vi, v in enumerate(variants(node)):
                if not isinstance(v, dict):
                    continue
                label = nk if not isinstance(node, list) else f"{nk}[{vi}]"
                for ci, c in enumerate(v.get("choices") or []):
                    for tgt in choice_jumps(c):
                        how = "next" if c.get("next") == tgt else "check"
                        self.inbound[tgt].append(f"{label} choice {ci} ({how})")

    def _walk(self):
        if not isinstance(self.start, str) or self.start not in self.nodes:
            return
        frontier = [self.start]
        self.reachable.add(self.start)
        while frontier:
            cur = frontier.pop()
            for v in variants(self.nodes.get(cur)):
                if not isinstance(v, dict):
                    continue
                for c in v.get("choices") or []:
                    for tgt in choice_jumps(c):
                        if tgt in self.nodes and tgt not in self.reachable:
                            self.reachable.add(tgt)
                            frontier.append(tgt)

    def orphans(self):
        return sorted(nk for nk in self.nodes if nk not in self.reachable)

    def reach(self, node_key):
        """
        Every node reachable from one node, itself included.

        The same walk as _walk(), started somewhere else. `camp` is the reason
        it exists: the camp screen opens that node directly, so "what can be
        said at the fire" is a subtree with its own entrance, and the approval
        economy wants to weigh it separately from what is said in the street.
        Empty for a file that has no such node, which is most of them.
        """
        if node_key not in self.nodes:
            return set()
        seen = {node_key}
        frontier = [node_key]
        while frontier:
            cur = frontier.pop()
            for v in variants(self.nodes.get(cur)):
                if not isinstance(v, dict):
                    continue
                for c in v.get("choices") or []:
                    for tgt in choice_jumps(c):
                        if tgt in self.nodes and tgt not in seen:
                            seen.add(tgt)
                            frontier.append(tgt)
        return seen


# ---------------------------------------------------------------------------
# Effect and condition harvesting
# ---------------------------------------------------------------------------
def walk_effects(effects, where, sink):
    if not isinstance(effects, list):
        return
    for e in effects:
        if isinstance(e, dict):
            sink.append((e, where))


def walk_conditions(conds, where, sink):
    """Conditions nest through any/all/not; flatten them all out."""
    if isinstance(conds, dict):
        conds = [conds]
    if not isinstance(conds, list):
        return
    for c in conds:
        if not isinstance(c, dict):
            continue
        sink.append((c, where))
        for k in ("any", "all"):
            if k in c:
                walk_conditions(c[k], where, sink)
        if "not" in c:
            walk_conditions(c["not"], where, sink)


def harvest(tree, graphs):
    """
    Every effect and condition in the tree, tagged with where it lives and
    whether a player can actually get to it.

    `live` is the distinction the loader does not draw: an effect inside a
    dialogue node that nothing jumps to is authored, valid, and unreachable.
    """
    effects, conditions = [], []

    for dk, obj in tree["dialog"].items():
        g = graphs[dk]
        for nk, node in (obj.get("nodes") or {}).items():
            live = nk in g.reachable
            for vi, v in enumerate(variants(node)):
                if not isinstance(v, dict):
                    continue
                at = f"dialog/{dk}.json:{nk}" + (f"[{vi}]" if isinstance(node, list) else "")
                walk_effects(v.get("on_enter"), (at + " on_enter", live), effects)
                walk_conditions(v.get("conditions"), (at + " variant", live), conditions)
                for ij in v.get("interjections") or []:
                    if isinstance(ij, dict):
                        walk_conditions(ij.get("conditions"),
                                        (at + " interjection", live), conditions)
                for ci, c in enumerate(v.get("choices") or []):
                    if not isinstance(c, dict):
                        continue
                    cat = f"{at} choice {ci}"
                    walk_effects(c.get("effects"), (cat, live), effects)
                    walk_conditions(c.get("conditions"), (cat, live), conditions)

    for qk, obj in tree["quests"].items():
        for sk, stage in (obj.get("stages") or {}).items():
            if isinstance(stage, dict):
                walk_effects(stage.get("effects"),
                             (f"quests/{qk}.json:{sk} effects", True), effects)

    for ck, obj in tree["companions"].items():
        rec = obj.get("recruit") or {}
        walk_conditions(rec.get("conditions"),
                        (f"companions/{ck}.json recruit", True), conditions)

    return effects, conditions


# ---------------------------------------------------------------------------
# Quest stage reachability
# ---------------------------------------------------------------------------
def stage_reachability(tree, effects):
    """
    quest_key -> {stage_key: [what enters it]}.

    A quest's first stage is entered by `start_quest`; everything else has to
    be named by a `quest_stage` effect somewhere a player can get to.
    """
    entered = {qk: defaultdict(list) for qk in tree["quests"]}
    for eff, (where, live) in effects:
        if not live:
            continue
        if "start_quest" in eff:
            qk = eff["start_quest"]
            stages = (tree["quests"].get(qk) or {}).get("stages") or {}
            if stages:
                entered.setdefault(qk, defaultdict(list))[next(iter(stages))].append(where)
        if "quest_stage" in eff and isinstance(eff["quest_stage"], dict):
            qk = eff["quest_stage"].get("quest")
            sk = eff["quest_stage"].get("stage")
            if isinstance(qk, str) and isinstance(sk, str):
                entered.setdefault(qk, defaultdict(list))[sk].append(where)
    return entered


# ---------------------------------------------------------------------------
# Flags
# ---------------------------------------------------------------------------
def engine_flags():
    """
    Flag names written by PHP rather than by any authored effect.

    Each entry names the file and the constant that owns it. Missing is fatal
    rather than ignorable: a silently-empty list turns every gate on an engine
    flag into a dead-gate finding, which is precisely the noise this exists to
    stop, and it would look like an authoring fault rather than a tooling one.

    Read out of the PHP by load_content.read_engine_flags(), which needs the
    same list to refuse content that WRITES one of these. Imported rather than
    reimplemented: this used to be a second grep for the same fact, and two
    greps are two things a rename can leave quietly agreeing with nothing.
    DialogueLint::ENGINE_FLAGS is the third reader, kept in step by naming the
    constants rather than copying the strings.
    """
    return list(load_content.read_engine_flags())


def flag_ledger(tree, effects, conditions):
    setters = defaultdict(list)
    readers = defaultdict(list)

    for eff, (where, live) in effects:
        for key in ("set_flag", "clear_flag", "increment_flag"):
            if isinstance(eff.get(key), str):
                setters[eff[key]].append((where, live, key))

    # The engine writes these when a fight ends. They are set by content even
    # though no effect names them, and a condition reading one is not dangling.
    for ek, obj in tree["encounters"].items():
        for field in ("victory_flag", "defeat_flag"):
            if isinstance(obj.get(field), str):
                setters[obj[field]].append((f"encounters/{ek}.json {field}", True, field))

    # And these are written by code, with no content anywhere naming them —
    # the Undervault's depth, which is the only thing a conversation can ask
    # about a floor that was generated ten minutes ago. Read out of the PHP
    # rather than copied, so renaming the constant cannot leave this behind
    # silently agreeing with nothing.
    for name, where in engine_flags():
        setters[name].append((where, True, "engine"))

    for cond, (where, live) in conditions:
        if isinstance(cond.get("flag"), str):
            readers[cond["flag"]].append((where, live))

    return setters, readers


# ---------------------------------------------------------------------------
# Openings
# ---------------------------------------------------------------------------
ANY = object()   # a greeting that reads a flag without pinning it to a value


class Reads:
    """
    What one greeting — or all of them together — is able to notice.

      flags   flag name -> {value, ...}, with ANY standing for a greeting that
              reads the flag without pinning it to a value; a bare
              {"flag": "hills_fate"} notices every ending that sets it.
      quests  quest key -> {resolution, ...}, again with ANY for
              {"status": "completed"} or a bare {"quest": k}, both of which are
              a greeting reacting to the quest being over without caring how.
    """

    def __init__(self):
        self.flags = defaultdict(set)
        self.quests = defaultdict(set)

    def add(self, cond):
        key = cond.get("flag")
        if isinstance(key, str):
            self.flags[key].add(str(cond["equals"]) if "equals" in cond else ANY)
        qk = cond.get("quest")
        if isinstance(qk, str):
            if isinstance(cond.get("resolution"), str):
                self.quests[qk].add(cond["resolution"])
            elif cond.get("status") in (None, "completed", "failed"):
                self.quests[qk].add(ANY)

    def absorb(self, other):
        for k, v in other.flags.items():
            self.flags[k] |= v
        for k, v in other.quests.items():
            self.quests[k] |= v
        return self

    def flag(self, key, value):
        vals = self.flags.get(key)
        return bool(vals) and (ANY in vals or str(value) in vals)

    def quest(self, quest_key, resolution):
        vals = self.quests.get(quest_key)
        return bool(vals) and (ANY in vals or resolution in vals)


def greeting_reads(tree):
    """
    Per dialogue file, what its opening line is allowed to notice.

    Only the `start` node, and only its *variant* conditions. That is precisely
    the set of facts that can change which line a person says when you walk up
    to them, which is the thing the player experiences as being remembered. A
    condition further down the tree gates a reply, and a reply the player has
    to go looking for is not the same as being greeted differently.
    """
    out = {}
    for dk, obj in tree["dialog"].items():
        reads = Reads()
        node = (obj.get("nodes") or {}).get(obj.get("start"))
        if node is not None:
            sink = []
            for v in variants(node):
                if isinstance(v, dict):
                    walk_conditions(v.get("conditions"), (dk, True), sink)
            for cond, _ in sink:
                reads.add(cond)
        out[dk] = reads
    return out


def openings(tree, rep, show):
    """
    Every ending in the act, and whether any greeting anywhere reacts to it.

    An ending is (quest, resolution). It counts as noticed if a greeting reads

      * that resolution by name, or
      * the quest as merely finished, or
      * a flag the terminal stage sets, at the value it sets it to.

    The third is how the shipped content actually does it — a terminal stage
    writes `quarry_fate`, and four people's opening lines branch on the value —
    so a check that only understood {"quest": ..., "resolution": ...} would
    report the whole act as unnoticed and be turned off within a day.

    `*_fate` values are then checked on their own account as well, because a
    fate flag set from somewhere that is not a terminal stage is still a branch
    of the act, and still wants somebody to look up when it changes.

    Then the same question asked of one person at a time, which is the version
    that catches what players actually report. Garrow Finch's greeting reads
    all three endings of `kessa_debt`, so the act-wide check above is satisfied
    and has been all along — while Kessa's own greeting read only the one where
    you sell her, and paying off her debt changed nothing about how she says
    hello. "Somebody in the act noticed" and "the person it happened to
    noticed" are different questions and only the second one is the complaint.
    """
    per_file = greeting_reads(tree)
    everyone = Reads()
    for reads in per_file.values():
        everyone.absorb(reads)

    fates = defaultdict(set)          # flag -> {value, ...} anything sets it to
    for qk, obj in tree["quests"].items():
        for stage in (obj.get("stages") or {}).values():
            if not isinstance(stage, dict):
                continue
            for eff in stage.get("effects") or []:
                if isinstance(eff, dict) and isinstance(eff.get("set_flag"), str):
                    fates[eff["set_flag"]].add(str(eff.get("value", "1")))

    if show:
        rule("Endings, and whether any greeting notices them")
    for qk in sorted(tree["quests"]):
        obj = tree["quests"][qk]
        for sk, stage in (obj.get("stages") or {}).items():
            if not isinstance(stage, dict) or not stage.get("terminal"):
                continue
            res = stage.get("resolution")
            sets = [(e["set_flag"], str(e.get("value", "1")))
                    for e in (stage.get("effects") or [])
                    if isinstance(e, dict) and isinstance(e.get("set_flag"), str)]
            by = []
            if isinstance(res, str) and res in everyone.quests.get(qk, ()):
                by.append(f"resolution {res!r}")
            if ANY in everyone.quests.get(qk, ()):
                by.append("the quest being over")
            by += [f"{k}={v}" for k, v in sets if everyone.flag(k, v)]
            if show:
                print(f"    {'   ' if by else '!! '}{qk}/{res}")
                for b in by:
                    print(f"          noticed by  {b}")
                if not by:
                    print("          noticed by  NOBODY"
                          + (f"  (sets {', '.join(f'{k}={v}' for k, v in sets)})" if sets else ""))
            if not by:
                hint = (" — a greeting could read "
                        + " or ".join(f'{{"flag": "{k}", "equals": "{v}"}}' for k, v in sets)
                        ) if sets else ""
                rep.find("opening",
                         f"{qk}/{res}: no dialogue greeting reacts to this ending{hint}")

    if show:
        rule("Fate flags, and whether any greeting notices each value")
    # Reported one finding per flag rather than one per value. An unwritten
    # branch has three endings and would otherwise file three near-identical
    # lines, and a check that files ninety lines the first time somebody adds a
    # region is a check that gets turned off.
    for key in sorted(k for k in fates if k.endswith("_fate")):
        missing = [v for v in sorted(fates[key]) if not everyone.flag(key, v)]
        if show:
            for value in sorted(fates[key]):
                print(f"    {'!! ' if value in missing else '   '}{key} = {value}")
        if missing:
            rep.find("opening",
                     f"{key}: no dialogue greeting reads "
                     + ", ".join(repr(v) for v in missing)
                     + f" ({len(fates[key]) - len(missing)} of {len(fates[key])} "
                       f"values are read) — the ending happens and nobody looks up")

    # --- and now the same question, asked of the person it happened to ------
    if show:
        rule("Companions, and whether their own opening notices their own quest")
    for ck in sorted(tree["companions"]):
        comp = tree["companions"][ck]
        quest_key = comp.get("personal_quest")
        quest = tree["quests"].get(quest_key) if isinstance(quest_key, str) else None
        if quest is None:
            if isinstance(quest_key, str):
                rep.find("opening", f"{ck}: personal_quest {quest_key!r} is not a quest")
            continue
        # A companion talks through an NPC file named by npc_key; the dialogue
        # file is keyed by the same string. Fall back to the companion key,
        # which is what the shipped four use for both.
        mine = per_file.get(comp.get("npc_key") or ck)
        if mine is None:
            rep.find("opening", f"{ck}: no dialogue file, so no opening to react with")
            continue
        for sk, stage in (quest.get("stages") or {}).items():
            if not isinstance(stage, dict) or not stage.get("terminal"):
                continue
            res = stage.get("resolution")
            sets = [(e["set_flag"], str(e.get("value", "1")))
                    for e in (stage.get("effects") or [])
                    if isinstance(e, dict) and isinstance(e.get("set_flag"), str)]
            by = []
            if isinstance(res, str) and mine.quest(quest_key, res):
                by.append(f"{quest_key}/{res}")
            by += [f"{k}={v}" for k, v in sets if mine.flag(k, v)]
            if show:
                print(f"    {'   ' if by else '!! '}{ck}: {quest_key}/{res}"
                      + ("   via " + ", ".join(by) if by else "   NOT READ BY THEIR OWN GREETING"))
            if not by:
                # Deliberately worded as the player's experience rather than as
                # a missing condition. The whole class of bug reads, from the
                # outside, as a companion who has not noticed their own life.
                rep.find("opening",
                         f"{ck}: finishing {quest_key} as {res!r} does not change how "
                         f"they greet the party — their own opening never reads it")


# ---------------------------------------------------------------------------
# The approval economy
# ---------------------------------------------------------------------------
#
# The check that would have caught the thing a player actually reported:
# "Aldric left me because I couldn't get his affection high enough."
#
# Approval had drifted into being ~93% quest-resolution money. Quest endings
# are one-shot, mutually exclusive, and frequently set companions against each
# other, so a party that decides pragmatically ends up with one friend and
# three enemies — and once somebody was under, there was no way back, because
# talking to them moved the number by almost nothing. Every individual file
# was fine. The sum of them was not, and nothing in the tree looks at sums.
#
# Two numbers per channel, because they answer different questions:
#
#   ceiling   what ONE playthrough can reach. Within a node the player picks
#             one reply, so choices are a max rather than a sum; variants of a
#             node are alternatives, so those are a max too. This is the number
#             that matters — it is what a determined player can actually earn.
#   gross     every positive delta authored anywhere, added up. Always larger,
#             and useful only as "how much writing is there", which is why the
#             old measurement of this problem looked healthy.
#
# The floor is the same arithmetic with the sign turned round: what a player
# who says the wrong thing every time can lose.


def approval_deltas(effects):
    """Every {"approval": {...}} in an effects list, flattened to (key, delta)."""
    out = []
    for e in effects or []:
        if isinstance(e, dict) and isinstance(e.get("approval"), dict):
            for k, v in e["approval"].items():
                if isinstance(v, (int, float)) and not isinstance(v, bool):
                    out.append((k, int(v)))
    return out


def blank_purse():
    return dict(ceiling=0, floor=0, gross_up=0, gross_down=0)


def add_gross(purse, delta):
    if delta > 0:
        purse["gross_up"] += delta
    else:
        purse["gross_down"] += delta


def node_extremes(node):
    """
    The best and worst one visit to a node can do, per companion.

    on_enter is unavoidable and so is added to every branch; the replies are
    alternatives and so are a max/min; the variants of a node are alternatives
    in the same way.
    """
    best, worst = defaultdict(int), defaultdict(int)
    for v in variants(node):
        if not isinstance(v, dict):
            continue
        enter = defaultdict(int)
        for k, d in approval_deltas(v.get("on_enter")):
            enter[k] += d
        up, down = defaultdict(int), defaultdict(int)
        for c in v.get("choices") or []:
            if not isinstance(c, dict):
                continue
            per = defaultdict(int)
            for k, d in approval_deltas(c.get("effects")):
                per[k] += d
            for k, d in per.items():
                up[k] = max(up[k], d)
                down[k] = min(down[k], d)
        for k in set(enter) | set(up) | set(down):
            best[k] = max(best[k], enter[k] + up[k])
            worst[k] = min(worst[k], enter[k] + down[k])
    return best, worst


def economy(tree, graphs, rep, show):
    channels = ("camp", "elsewhere", "quests")
    tally = {ch: defaultdict(blank_purse) for ch in channels}

    for dk, obj in tree["dialog"].items():
        g = graphs[dk]
        at_the_fire = g.reach("camp")
        for nk, node in (obj.get("nodes") or {}).items():
            # An unreachable node's approval is authored and unspendable, and
            # counting it would hide exactly the shortfall this is looking for.
            if nk not in g.reachable and nk not in at_the_fire:
                continue
            ch = "camp" if nk in at_the_fire else "elsewhere"
            best, worst = node_extremes(node)
            for k, d in best.items():
                if d > 0:
                    tally[ch][k]["ceiling"] += d
            for k, d in worst.items():
                if d < 0:
                    tally[ch][k]["floor"] += d
            for v in variants(node):
                if not isinstance(v, dict):
                    continue
                blocks = [v.get("on_enter")] + [c.get("effects") for c in v.get("choices") or []
                                                if isinstance(c, dict)]
                for block in blocks:
                    for k, d in approval_deltas(block):
                        add_gross(tally[ch][k], d)

    # A quest's terminal stages are alternatives — that is the whole point of a
    # resolution — so they are a max. Everything on the way there is passed
    # through and adds up.
    for qk, obj in tree["quests"].items():
        stages = obj.get("stages") or {}
        ends_best, ends_worst = defaultdict(int), defaultdict(int)
        for sk, stage in stages.items():
            if not isinstance(stage, dict):
                continue
            per = defaultdict(int)
            for k, d in approval_deltas(stage.get("effects")):
                per[k] += d
                add_gross(tally["quests"][k], d)
            for k, d in per.items():
                if stage.get("terminal"):
                    ends_best[k] = max(ends_best[k], d)
                    ends_worst[k] = min(ends_worst[k], d)
                elif d > 0:
                    tally["quests"][k]["ceiling"] += d
                else:
                    tally["quests"][k]["floor"] += d
        for k, d in ends_best.items():
            if d > 0:
                tally["quests"][k]["ceiling"] += d
        for k, d in ends_worst.items():
            if d < 0:
                tally["quests"][k]["floor"] += d

    if show:
        rule("The approval economy: what talking is worth against what quests are worth")
        print("    ceiling = the most one playthrough can earn (replies are a choice,")
        print("    not a shopping list); gross = every delta authored, which is how")
        print("    much writing there is rather than how much a player can reach.\n")

    for ck in sorted(tree["companions"]):
        comp = tree["companions"][ck]
        leave_at = int(((comp.get("approval") or {}).get("leave_at", -40)))
        camp = tally["camp"][ck]
        other = tally["elsewhere"][ck]
        quests = tally["quests"][ck]
        talk_ceiling = camp["ceiling"] + other["ceiling"]
        talk_floor = camp["floor"] + other["floor"]
        climb = abs(leave_at)

        if show:
            print(f"  {ck}    leaves at {leave_at}, so climbing back needs +{climb}")
            for label, p in (("at the fire", camp), ("elsewhere in dialogue", other),
                             ("quest stages", quests)):
                print(f"      {label:<22} ceiling {p['ceiling']:+5d}   floor {p['floor']:+5d}"
                      f"      (gross {p['gross_up']:+d} / {p['gross_down']:+d})")
            share = (100 * quests["gross_up"]
                     // max(1, quests["gross_up"] + camp["gross_up"] + other["gross_up"]))
            print(f"      conversation total    ceiling {talk_ceiling:+5d}   "
                  f"floor {talk_floor:+5d}      ({share}% of the writing is quest money)")
            print()

        # Measured on the camp channel rather than on all dialogue, and that is
        # the whole judgement in this check.
        #
        # "Elsewhere in dialogue" is approval attached to scenes — a personal
        # quest's confrontation, the moment somebody's secret comes out. A
        # player can only spend those once and only in the course of doing the
        # quest, so a companion who is under water after the quest has already
        # been through them and cannot go back. What the fire is worth is the
        # only figure that answers "can I fix this from where I am standing",
        # which is the question the player was actually asking.
        if camp["ceiling"] < climb:
            rep.find("economy",
                     f"{ck}: sitting with them at camp is worth at most "
                     f"+{camp['ceiling']} across a whole playthrough, but they walk out "
                     f"at {leave_at}. A party pushed under by quest endings "
                     f"({quests['floor']:+d} is reachable there) cannot talk them back "
                     f"up to nothing, let alone to liked — the scene approval elsewhere "
                     f"in dialogue (+{other['ceiling']}) is attached to quests they have "
                     f"already been through. Give them roughly "
                     f"+{climb - camp['ceiling']} more in one-time camp conversation.")


# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------
class Report:
    def __init__(self):
        self.findings = []

    def find(self, kind, msg):
        self.findings.append((kind, msg))


def rule(title):
    print(f"\n=== {title} " + "=" * max(0, 66 - len(title)))


def main():
    ap = argparse.ArgumentParser(
        description="Reachability and flag report for content/**/*.json.")
    ap.add_argument("--quiet", action="store_true", help="findings only")
    ap.add_argument("--flags", action="store_true", help="only the flag ledger")
    ap.add_argument("--openings", action="store_true",
                    help="only what greetings notice")
    ap.add_argument("--economy", action="store_true",
                    help="only the approval economy, dialogue against quests")
    args = ap.parse_args()

    tree = load()
    graphs = {dk: Graph(dk, obj) for dk, obj in tree["dialog"].items()}
    effects, conditions = harvest(tree, graphs)
    entered = stage_reachability(tree, effects)
    setters, readers = flag_ledger(tree, effects, conditions)
    rep = Report()

    full = not args.quiet and not args.flags and not args.openings and not args.economy

    # --- quests -----------------------------------------------------------
    if full:
        rule("Quests and their endings")
    for qk in sorted(tree["quests"]):
        obj = tree["quests"][qk]
        stages = obj.get("stages") or {}
        first = next(iter(stages), None)
        if full:
            print(f"\n  {qk}  — {obj.get('title')}")
        for sk, stage in stages.items():
            if not isinstance(stage, dict):
                continue
            ways = entered.get(qk, {}).get(sk, [])
            terminal = bool(stage.get("terminal"))
            mark = "END" if terminal else "   "
            if sk == first and not ways:
                ways = ["(first stage — nothing starts this quest)"]
                rep.find("quest", f"{qk}: no start_quest effect anywhere enters it")
            if terminal and not ways:
                rep.find("ending",
                         f"{qk}/{sk} (resolution {stage.get('resolution')!r}) "
                         f"is unreachable — no live quest_stage effect names it")
            if full:
                res = f" [{stage.get('resolution')}]" if terminal else ""
                print(f"    {mark} {sk}{res}")
                for w in ways:
                    print(f"          <- {w}")
                if not ways:
                    print("          <- NOTHING")

    # --- dialogue ---------------------------------------------------------
    if full:
        rule("Dialogue nodes and what reaches them")
    for dk in sorted(graphs):
        g = graphs[dk]
        if full:
            n_var = sum(len(variants(v)) for v in g.nodes.values())
            print(f"\n  {dk}  start={g.start!r}  "
                  f"{len(g.nodes)} nodes, {n_var} variants")
        for nk in sorted(g.nodes):
            live = nk in g.reachable
            srcs = g.inbound.get(nk, [])
            if nk == g.start:
                srcs = ["(start)"] + srcs
            if not live:
                rep.find("dialog", f"{dk}:{nk} is unreachable from {g.start!r}")
            if full:
                print(f"    {'  ' if live else '!!'} {nk}")
                for s in srcs:
                    print(f"          <- {s}")
                if not srcs:
                    print("          <- NOTHING")

    # --- flags ------------------------------------------------------------
    if full or args.flags:
        rule("Flags: set by / read by")
    # The engine's own counters are exempt from "read nowhere" and from nothing
    # else. `concern_pressure` is written by PHP and read straight back by PHP,
    # and content is told to gate on the named thresholds instead of the raw
    # number — so nothing in the corpus reading it is the design working, not a
    # flag somebody forgot to finish. The thresholds themselves are NOT exempt:
    # a band of pressure nobody wrote a reaction for is exactly this finding.
    private = {f for f, _ in load_content.read_private_engine_flags()}
    for flag in sorted(set(setters) | set(readers)):
        s, r = setters.get(flag, []), readers.get(flag, [])
        if full or args.flags:
            print(f"\n  {flag}")
            for where, live, how in s:
                print(f"      set  {'   ' if live else '(!)'} {how:<14} {where}")
            if not s:
                print("      set   NOWHERE")
            for where, live in r:
                print(f"      read {'   ' if live else '(!)'} {where}")
            if not r:
                print("      read  NOWHERE")
        if not s:
            rep.find("flag", f"{flag!r} is read in {len(r)} place(s) and set nowhere — "
                             f"every variant guarding it is dead")
        if not r and flag not in private:
            rep.find("flag", f"{flag!r} is set in {len(s)} place(s) and read nowhere — "
                             f"nothing in the act changes because of it")

    # --- openings ---------------------------------------------------------
    openings(tree, rep, full or args.openings)

    # --- the approval economy ---------------------------------------------
    economy(tree, graphs, rep, full or args.economy)

    # --- companions -------------------------------------------------------
    if full:
        rule("Companions")
        for ck in sorted(tree["companions"]):
            c = tree["companions"][ck]
            rec = c.get("recruit") or {}
            print(f"  {ck:<8} {c.get('race')} {c.get('class'):<10} "
                  f"rank={c.get('combat_rank'):<5} sprite={c.get('sprite_key'):<10} "
                  f"quest={c.get('personal_quest')}")
            print(f"           recruit at {rec.get('location')} if "
                  f"{json.dumps(rec.get('conditions'), ensure_ascii=False)}")
    for ck, c in tree["companions"].items():
        recruited = any("recruit" in e and e["recruit"] == ck
                        for e, (_, live) in effects if live)
        if not recruited:
            rep.find("companion", f"{ck}: no live `recruit` effect anywhere joins them")

    # --- encounters -------------------------------------------------------
    #
    # There are three ways into a fight now, and only one of them is an effect:
    #
    #   a live `start_combat` in dialogue or a quest stage — somebody says the
    #     wrong thing and it begins;
    #   `place.location` — the fight is standing in a room, and arriving there
    #     starts it (or, with `ambush`, starts it before anyone can leave);
    #   `is_random` with a `region` — the wilderness rolls for it while the
    #     party travels, so no authored line will ever name it.
    #
    # Only the first of those existed when encounters hung off map tiles. An
    # encounter with none of the three is genuinely unreachable, which is the
    # finding worth keeping; the other two are placement and are fine.
    used = {e["start_combat"] for e, (_, live) in effects
            if live and isinstance(e.get("start_combat"), str)}
    for ek, obj in tree["encounters"].items():
        if ek in used:
            continue
        placed_at = ((obj.get("place") or {}).get("location"))
        if placed_at:
            continue
        if obj.get("is_random") and obj.get("region"):
            continue
        if obj.get("is_random"):
            rep.find("encounter", f"{ek}: is_random but names no region, so nothing rolls it")
            continue
        rep.find("encounter", f"{ek}: nothing opens it — no start_combat effect, "
                              f"no place.location, not random")

    # --- findings ---------------------------------------------------------
    rule("Findings")
    if not rep.findings:
        print("  none — every ending is reachable and every flag is both set and read.")
        return 0
    by_kind = defaultdict(list)
    for kind, msg in rep.findings:
        by_kind[kind].append(msg)
    for kind in sorted(by_kind):
        print(f"\n  {kind}:")
        for msg in sorted(by_kind[kind]):
            print(f"    - {msg}")
    print(f"\n  {len(rep.findings)} finding(s)")
    return 1


if __name__ == "__main__":
    sys.exit(main())
