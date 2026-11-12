#!/usr/bin/env python3
"""
Dead and circular gates: a condition that asks for a flag or a quest stage no
player can ever get.

tools/trace_content.py names a flag that is read and never set. This answers
the harder question after it: is it set somewhere a player can actually
REACH? The case that started it was `deep_store_seen` — set by exactly one
reply, inside the conversation it unlocks, so the only way in was to have
already been in. Every reference resolves and every node is "reachable" by the
loader's graph walk; the gate still never opens.

    python3 tools/gate_audit.py            # report; exits 1 on a NEW dead/circular gate
    python3 tools/gate_audit.py --check    # same (what verify.sh runs)
    python3 tools/gate_audit.py --why npc_key:node_key   # what blocks one dialogue node

How it decides. A fixpoint over everything that can set a flag or enter a
quest stage, starting from what needs nothing:

  * dialogue: walked from `start`, `camp`, and every `npc:node` named elsewhere
    in content/ (encounter parley nodes and the like). A variant or reply is
    taken only when the flags and quest stages its conditions ask for are
    already reachable; its on_enter / effects then count.
  * quests: a stage's effects count once the stage is reachable; start_quest
    reaches the first stage; on_job_board quests have theirs from the start.
  * encounters: victory_flag, defeat_flag and victory_quest_stage count (a fight
    can always be had — an approximation).
  * everything else (locations, items, …): an effects list counts when the
    conditions around it pass.

It ignores `not`, non-flag predicates (level, gold, origin, items, companions)
and variant order. All of that is over-approximate: it can miss a dead gate,
it cannot invent one. Flags the engine writes are taken from the PHP: a gate
whose exact name appears as a quoted literal in app/ or api/ is treated as
set (uv_deepest, the concern thresholds, found_exit_sump_drain, …), and
namespaced flags with a ':' (seen:, drawn:, watch_done:, check_failed:) are
engine bookkeeping and skipped.

Out of scope: the old_city module (inactive), and Rich's intentionally skipped
flags in SKIPPED_FLAGS. Known dead gates waiting on a story decision are in
ALLOWED, each with what it blocks. To accept a new one on purpose, add it to
ALLOWED with a comment saying why and who decides, in its own commit. When a
listed gate becomes reachable the check says so (it does not fail) — delete the
entry then.
"""

import argparse
import json
import os
import re
import sys
from collections import defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENT = os.path.join(ROOT, "content")
PHP_DIRS = (os.path.join(ROOT, "app"), os.path.join(ROOT, "api"))

# Rich's call: flags left unset on purpose (content written ahead of the beat
# that sets them, or a branch cut). Not reported.
SKIPPED_FLAGS = {
    "sluice_wage_intercepted", "sluice_wage_known", "pool_dead_named",
    "salt_iron_exposed", "hills_raiders_beaten", "reliquary_agents_beaten",
    "salt_wagons_taken", "tarrow_bravos_beaten", "writ_changed_hands",
    "priory_refusal_known", "corse_doubts_the_dig",
}
SKIPPED_PREFIXES = ("long_room_",)
INACTIVE_MODULES = {"old_city"}

# Known dead gates, each waiting on a story decision (Rich). Keyed by what the
# condition asks for: a flag name, or "Q:<quest>:<stage>" for a quest stage.
ALLOWED = {
    # Empty: the three loops found on Nov 11 (What Goes Out, Nine Masts, Rats
    # and Better) were fixed in content and the engine the same night.
}


def variants(node):
    return node if isinstance(node, list) else [node]


def load_dir(kind):
    out = {}
    d = os.path.join(CONTENT, kind)
    for name in sorted(os.listdir(d)) if os.path.isdir(d) else []:
        if name.endswith(".json"):
            path = os.path.join(d, name)
            with open(path, encoding="utf-8") as fh:
                out[path] = json.load(fh)
    return out


def rel(path):
    return os.path.relpath(path, ROOT)


# --- what a condition list asks for ------------------------------------------
def reqs(conds):
    """Positive requirements, as a list of alternatives (sets). `any` branches;
    `not`, at_most / equals-0 and non-flag predicates ask for nothing."""
    alts = [frozenset()]
    for c in conds or []:
        if not isinstance(c, dict):
            continue
        if isinstance(c.get("flag"), str):
            if "at_most" in c or ("equals" in c and str(c["equals"]) in ("0", "")):
                continue
            alts = [a | {c["flag"]} for a in alts]
        elif isinstance(c.get("quest"), str) and isinstance(c.get("stage"), str):
            alts = [a | {"Q:%s:%s" % (c["quest"], c["stage"])} for a in alts]
        elif isinstance(c.get("all"), list):
            sub = reqs(c["all"])
            alts = [a | b for a in alts for b in sub]
        elif isinstance(c.get("any"), list):
            sub = [r for x in c["any"] for r in reqs([x])]
            if sub:
                alts = [a | b for a in alts for b in sub]
    return alts


def passes(conds, have):
    return any(r <= have for r in reqs(conds))


class World:
    def __init__(self):
        self.dialogs = load_dir("dialog")
        self.quests = {d["quest_key"]: (p, d) for p, d in load_dir("quests").items()}
        self.encounters = load_dir("encounters")
        self.others = {}
        for kind in ("locations", "items", "npcs", "companions", "monsters", "spells", "modules"):
            self.others.update(load_dir(kind))
        self.loc_mod, self.npc_mod = {}, {}
        for p, d in load_dir("locations").items():
            for k in d.get("locations", {}):
                self.loc_mod[k] = d.get("module")
        for p, d in load_dir("npcs").items():
            self.npc_mod[d.get("npc_key")] = self.loc_mod.get((d.get("place") or {}).get("location"))
        # Nodes entered from outside their tree: "npc:node" anywhere in content.
        self.entries = defaultdict(set)
        for path in self.all_paths():
            with open(path, encoding="utf-8") as fh:
                for m in re.finditer(r'"([a-z0-9_]+):([a-z0-9_]+)"', fh.read()):
                    self.entries[m.group(1)].add(m.group(2))
        self.php = ""
        for d in PHP_DIRS:
            for base, _, files in os.walk(d):
                for f in files:
                    if f.endswith(".php"):
                        with open(os.path.join(base, f), encoding="utf-8", errors="replace") as fh:
                            self.php += fh.read()

    def all_paths(self):
        return (list(self.dialogs) + [p for p, _ in self.quests.values()]
                + list(self.encounters) + list(self.others))

    def module_of(self, path, doc):
        kind = os.path.basename(os.path.dirname(path))
        if kind == "locations":
            return doc.get("module")
        if kind in ("dialog", "npcs"):
            return self.npc_mod.get(doc.get("npc_key"))
        if kind == "encounters":
            return self.loc_mod.get((doc.get("place") or {}).get("location") or doc.get("location"))
        if kind == "quests":
            m = self.npc_mod.get(doc.get("giver"))
            if m:
                return m
            for s in (doc.get("stages") or {}).values():
                m = self.loc_mod.get((s.get("target") or {}).get("location"))
                if m:
                    return m
        return None

    def engine_sets(self, flag):
        return re.search(r"['\"]" + re.escape(flag) + r"['\"]", self.php) is not None

    # -- what an effects list sets --------------------------------------------
    def sets_in(self, effects):
        out = set()
        for e in effects or []:
            if not isinstance(e, dict):
                continue
            for k in ("set_flag", "increment_flag", "victory_flag", "defeat_flag"):
                if isinstance(e.get(k), str):
                    out.add(e[k])
            if isinstance(e.get("victory_quest_stage"), str) and ":" in e["victory_quest_stage"]:
                out.add("Q:" + e["victory_quest_stage"].strip())
            qs = e.get("quest_stage")
            if isinstance(qs, dict):
                out.add("Q:%s:%s" % (qs.get("quest"), qs.get("stage")))
            if isinstance(e.get("start_quest"), str) and e["start_quest"] in self.quests:
                stages = list((self.quests[e["start_quest"]][1].get("stages") or {}))
                if stages:
                    out.add("Q:%s:%s" % (e["start_quest"], stages[0]))
        return out

    def generic(self, o, have, ok=True, out=None):
        out = set() if out is None else out
        if isinstance(o, dict):
            conds = o.get("conditions") if isinstance(o.get("conditions"), list) else o.get("when")
            here = ok and (passes(conds, have) if isinstance(conds, list) else True)
            if here and any(k in o for k in ("set_flag", "quest_stage", "start_quest",
                                             "increment_flag", "victory_quest_stage")):
                out |= self.sets_in([o])
            for k, v in o.items():
                if k in ("conditions", "when"):
                    continue
                if isinstance(v, list) and k in ("effects", "on_enter") and here:
                    out |= self.sets_in(v)
                self.generic(v, have, here, out)
        elif isinstance(o, list):
            for v in o:
                self.generic(v, have, ok, out)
        return out

    def roots(self, doc):
        return [doc.get("start") or "greeting", "camp"] + sorted(self.entries.get(doc.get("npc_key"), ()))

    def walk_dialog(self, doc, have):
        nodes, seen, todo, got = doc.get("nodes") or {}, set(), self.roots(doc), set()
        while todo:
            n = todo.pop()
            if n in seen or n not in nodes:
                continue
            seen.add(n)
            for v in variants(nodes[n]):
                if not passes(v.get("conditions"), have):
                    continue
                got |= self.sets_in(v.get("on_enter"))
                for c in v.get("choices", []):
                    if not passes(c.get("conditions"), have):
                        continue
                    got |= self.sets_in(c.get("effects"))
                    chk = c.get("check") or {}
                    todo += [t for t in (c.get("next"), chk.get("on_success"), chk.get("on_failure")) if t]
        return got, seen

    def reachable(self):
        # Engine-written, namespaced and skipped flags count as had: the engine
        # (or Rich) supplies them, so what sits behind them is judged on the rest.
        have = {req for req, _, _ in self.gates() if ignored(req, self)}
        for q, (_, d) in self.quests.items():
            st = list(d.get("stages") or {})
            if st and str(d.get("on_job_board")).lower() in ("true", "1"):
                have.add("Q:%s:%s" % (q, st[0]))
        for d in self.encounters.values():
            have |= self.sets_in([d])
        while True:
            before = len(have)
            for d in self.dialogs.values():
                have |= self.walk_dialog(d, have)[0]
            for q, (_, d) in self.quests.items():
                for s, v in (d.get("stages") or {}).items():
                    if "Q:%s:%s" % (q, s) in have:
                        have |= self.sets_in(v.get("effects"))
            for d in self.others.values():
                have |= self.generic(d, have)
            if len(have) == before:
                return have

    # -- every gate, with where it is -----------------------------------------
    def gates(self):
        """(requirement, path, where) for every positive requirement in a condition."""
        out = []

        def scan(o, path, where):
            if isinstance(o, dict):
                for k, v in o.items():
                    if k == "conditions" and isinstance(v, list):
                        for r in reqs(v):
                            out.extend((x, path, where) for x in r)
                    else:
                        scan(v, path, where)
            elif isinstance(o, list):
                for v in o:
                    scan(v, path, where)

        for path, doc in self.dialogs.items():
            if self.module_of(path, doc) in INACTIVE_MODULES:
                continue
            for n, nv in (doc.get("nodes") or {}).items():
                for i, v in enumerate(variants(nv)):
                    scan(v.get("conditions") and {"conditions": v["conditions"]}, path, "node %s variant %d" % (n, i))
                    for j, c in enumerate(v.get("choices", [])):
                        scan(c, path, "node %s variant %d reply %d" % (n, i, j))
                    for c in v.get("interjections") or []:
                        scan(c, path, "node %s variant %d interjection" % (n, i))
        for path, doc in [(p, d) for p, d in self.quests.values()] + list(self.encounters.items()) + list(self.others.items()):
            if self.module_of(path, doc) not in INACTIVE_MODULES:
                scan(doc, path, os.path.basename(os.path.dirname(path)))
        return out


def line_of(path, needle, node=None):
    with open(path, encoding="utf-8") as fh:
        lines = fh.read().split("\n")
    start = 0
    if node:
        for i, l in enumerate(lines):
            if re.match(r'\s*"%s": [\[{]' % re.escape(node), l):
                start = i
                break
    for i in range(start, len(lines)):
        if needle in lines[i]:
            return i + 1
    return start + 1


def ignored(req, world):
    if req.startswith("Q:"):
        return False
    return (":" in req or req in SKIPPED_FLAGS or req.startswith(SKIPPED_PREFIXES)
            or world.engine_sets(req))


def why(world, spec):
    npc, node = spec.split(":", 1)
    path = os.path.join(CONTENT, "dialog", npc + ".json")
    doc = world.dialogs[path]
    have = world.reachable()
    _, seen = world.walk_dialog(doc, have)
    print("%s:%s %s" % (npc, node, "is reachable" if node in seen else "is NOT reachable"))
    for n, nv in (doc.get("nodes") or {}).items():
        for i, v in enumerate(variants(nv)):
            for j, c in enumerate(v.get("choices", [])):
                chk = c.get("check") or {}
                if node in (c.get("next"), chk.get("on_success"), chk.get("on_failure")):
                    miss_v = [sorted(r - have) for r in reqs(v.get("conditions"))]
                    miss_c = [sorted(r - have) for r in reqs(c.get("conditions"))]
                    print("  from %s variant %d reply %d%s: variant needs %s, reply needs %s"
                          % (n, i, j, "" if n in seen else " (itself unreachable)", miss_v, miss_c))
    return 0


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--check", action="store_true", help="exit 1 on a gate not in ALLOWED (default)")
    ap.add_argument("--why", metavar="NPC:NODE", help="explain what blocks one dialogue node")
    args = ap.parse_args()
    world = World()
    if args.why:
        return why(world, args.why)

    have = world.reachable()
    # Anything any effect anywhere names, reachable or not: tells dead from circular.
    named = set()

    def collect(o):
        if isinstance(o, dict):
            named.update(world.sets_in([o]))
            for v in o.values():
                collect(v)
        elif isinstance(o, list):
            for v in o:
                collect(v)
    for p in world.all_paths():
        with open(p, encoding="utf-8") as fh:
            collect(json.load(fh))
    for q, (_, d) in world.quests.items():
        st = list(d.get("stages") or {})
        if st:
            named.add("Q:%s:%s" % (q, st[0]))

    found = defaultdict(list)
    for req, path, where in world.gates():
        if req in have or ignored(req, world):
            continue
        found[req].append((path, where))

    new, known = [], []
    for req in sorted(found):
        (known if req in ALLOWED else new).append(req)
    print("gate_audit: %d gates out of reach (%d allowed, %d new)" % (len(found), len(known), len(new)))
    for req in known:
        print("  allowed  %s — %s" % (req, ALLOWED[req]))
    for req in sorted(set(ALLOWED) - set(found)):
        print("  FIXED?   %s is no longer out of reach — delete it from ALLOWED in tools/gate_audit.py" % req)
    for req in new:
        kind = "circular (every setter sits behind a gate that needs it)" if req in named \
            else "dead (nothing in content sets it, and the PHP never names it)"
        label = ("quest stage %s" % req[2:]) if req.startswith("Q:") else ("flag '%s'" % req)
        print("\nNEW: %s is %s. Asked for at:" % (label, kind))
        needle = '"%s"' % (req.split(":")[-1])
        for path, where in sorted(set(found[req])):
            node = where.split()[1] if where.startswith("node ") else None
            print("    %s:%d  %s" % (rel(path), line_of(path, needle, node), where))
    if new:
        print("\nFix the content so a player can reach it, or (on purpose, in its own commit) add it to"
              " ALLOWED in tools/gate_audit.py with a comment. Explain one node with --why npc:node.")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
