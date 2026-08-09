<?php
/**
 * What is wrong with a conversation.
 *
 * Why this exists
 * ---------------
 * Dialogue is validated in two places that did not agree. `tools/load_content.py`
 * validates the FILES on the way to `sql/content.sql` and refuses to write on a
 * dangling link, an unreachable node, a node with no fallback variant, a split
 * pool or an unknown field. `ContentEditor::saveDialogue()` validates a DATABASE
 * write and checks a much shorter list — shape, `start`, key spelling and
 * dangling jumps — deliberately, because it did not want to be a second copy of
 * the condition and effect vocabularies.
 *
 * That was a fair trade while dialogue was edited as raw JSON in a textarea,
 * where making an orphan node takes real effort. It stops being fair the moment
 * there is a graph editor, where adding a node nothing points at is one click.
 * The database would accept a tree that the next content build rejects, and the
 * error would surface as a refusal to load, a long way from the edit.
 *
 * So the structural rules live here, once, and are used twice: by
 * `saveDialogue()` to refuse a bad write, and by `content/lint_dialogue` to mark
 * up the graph. The messages are copied VERBATIM from load_content.py so that
 * the page and the build say the same words about the same fault; each rule
 * carries the line it was taken from, and if the loader's wording changes these
 * should be changed with it.
 *
 * What is deliberately NOT here
 * ----------------------------
 * The condition and effect vocabularies, and the reference checks that resolve
 * a quest, item, companion or location key. Those are open-ended, they live in
 * the loader, and re-stating them in PHP is exactly the duplication the
 * ContentEditor docblock warned against. `Requirements::pass()` and
 * `Effects::apply()` throw on an unknown key at runtime, which is the backstop.
 *
 * The one rule here that the loader does NOT mirror is the flag wiring check,
 * and it is not an oversight: it is the only question in this file that cannot
 * be answered from one document. A flag set in `ludo_corse` and read by a quest
 * stage is correctly wired, and a linter holding one conversation cannot see
 * that. The world's read and write sets are therefore INJECTED — ContentEditor
 * gathers them across every NPC, quest stage, encounter, exit and companion —
 * and the check stands down entirely when it has not been given them, because
 * "nobody writes this flag" and "nobody told me who writes flags" produce the
 * same empty set and only one of them is a finding. It warns and never errors
 * for the same reason: the scan is broad but it is a list, and a list can be
 * missed off. A save must not be refused on the strength of one.
 *
 * Findings are reported, never thrown, except by `assert()` — a linter that
 * stops at the first problem is a linter you run once and then argue with.
 */

declare(strict_types=1);

class DialogueLint
{
    /** A finding that must stop a save. */
    public const ERROR = 'error';
    /** Worth saying, not worth refusing. */
    public const WARNING = 'warning';

    /**
     * Flags written by the engine, which no authored effect will ever set.
     *
     * The dead-gate check works by reading every `set_flag` in the corpus, so a
     * flag written in PHP looks exactly like a flag nobody wrote — the gate
     * reads as unreachable and the scene behind it as dead. That was right
     * until the Undervault, where the only fact authored content can state
     * about a generated dungeon is how deep the party has been, and depth is
     * not something a conversation can set.
     *
     * Declared rather than pattern-matched, and named against the constant its
     * writer owns: a list of prefixes to ignore would silence the check for
     * everything that happened to look like one. Add to this only when an
     * engine genuinely writes a flag content is meant to gate on — the cost of
     * a wrong entry is a dead scene nobody is told about.
     *
     * The Concern's pressure is the second, and it arrives as a whole band of
     * flags rather than one: the counter, and a flag per named threshold. They
     * are unpacked from ConcernPressure::THRESHOLDS rather than listed, so
     * adding a threshold cannot leave this agreeing with one fewer flag than
     * the engine writes.
     */
    private const ENGINE_FLAGS = [
        DelveEngine::DEPTH_FLAG,
        ConcernPressure::FLAG,
        ...ConcernPressure::THRESHOLDS,
    ];

    /** load_content.py:45 */
    private const KEY_RE = '/^[a-z0-9_]+$/';

    /** load_content.py:250 — the keys where a jump lands. */
    private const JUMP_KEYS = ['next', 'on_success', 'on_failure'];

    /** load_content.py:149-151 */
    private const NODE_REQUIRED = ['text'];
    private const NODE_OPTIONAL = ['expression', 'speaker', 'conditions', 'once',
                                   'pool', 'on_enter', 'interjections', 'choices'];

    /** load_content.py:152-153 */
    private const CHOICE_REQUIRED = ['label'];
    private const CHOICE_OPTIONAL = ['tag', 'conditions', 'check', 'effects',
                                     'next', 'end', 'reoffer'];

    /** load_content.py:162 */
    private const CHECK_REQUIRED = ['skill', 'dc', 'on_success', 'on_failure'];
    private const CHECK_OPTIONAL = ['retry'];

    /** load_content.py:163 */
    private const INTERJECTION_REQUIRED = ['companion', 'text'];
    private const INTERJECTION_OPTIONAL = ['conditions', 'once'];

    /**
     * Node keys that are entered from outside the tree, and are therefore roots
     * for the reachability walk rather than orphans.
     *
     * `camp` is the one that matters and the one nothing validates today: the
     * camp screen opens it directly with `Dialog.openNode(npcKey, 'camp')`
     * (assets/js/ui-panels.js), and no `next` anywhere has to name it. Walking
     * only from `start` would report the entire camp subtree — the largest part
     * of all four companion trees — as unreachable, which is both wrong and the
     * fastest way to teach somebody to ignore this tool.
     *
     * Encounter parley nodes are the other case and are passed in, because they
     * live in the `encounters` table rather than in the tree.
     */
    private const EXTERNAL_ROOTS = ['camp'];

    /** @var array<int, array{level:string, where:string, message:string}> */
    private array $found = [];

    /** Extra roots from `encounters.parley_node`, as node keys. @var string[] */
    private array $parleyRoots;

    /** sprite_key => how many busts were cut. @var array<string,int> */
    private array $bustCounts;

    /**
     * Every flag any effect in the world sets, as a set. Empty = not supplied.
     * @var array<string,true>
     */
    private array $flagsWritten;

    /**
     * Every flag any condition in the world reads, as a set. Empty = not supplied.
     * @var array<string,true>
     */
    private array $flagsRead;

    /**
     * The `*_at_least` family, where a bigger threshold implies a smaller one.
     *
     * Needed by the shadowing test: `approval_at_least: 20` is satisfied
     * wherever `approval_at_least: 40` is, so the first shadows the second even
     * though the two predicates are not identical. Every other predicate in the
     * vocabulary is compared for equality, which is the conservative reading —
     * it can miss a shadow, it cannot invent one.
     */
    private const AT_LEAST_KEYS = ['approval_at_least', 'gold_at_least',
                                   'level_at_least', 'at_least', 'romance_stage'];

    /**
     * @param string[]           $parleyRoots  node keys reached by a parley
     * @param array<string,int>  $bustCounts   from assets/images/npcs/busts.json
     * @param string[]           $flagsWritten every flag the world sets
     * @param string[]           $flagsRead    every flag the world reads
     */
    public function __construct(
        array $parleyRoots = [],
        array $bustCounts = [],
        array $flagsWritten = [],
        array $flagsRead = []
    ) {
        $this->parleyRoots = $parleyRoots;
        $this->bustCounts = $bustCounts;
        $this->flagsWritten = array_fill_keys($flagsWritten, true);
        $this->flagsRead = array_fill_keys($flagsRead, true);
    }

    /**
     * Every fault in one conversation.
     *
     * `$spriteKey` is only needed for the expression bound; pass null and that
     * one check is skipped rather than guessed at.
     *
     * @param array<string,mixed> $doc  a decoded dialogue document
     * @return array<int, array{level:string, where:string, message:string}>
     */
    public function check(array $doc, ?string $spriteKey = null): array
    {
        $this->found = [];

        $nodes = $doc['nodes'] ?? null;
        if (!is_array($nodes) || !$nodes) {
            $this->err('$.nodes', 'A conversation needs at least one node under "nodes".');
            return $this->found;
        }

        $start = (string) ($doc['start'] ?? '');
        if ($start === '') {
            $this->err('$.start', 'missing required field \'start\'');
        } elseif (!isset($nodes[$start])) {
            // load_content.py:1055
            $this->err('$.start', "start node '{$start}' is not in `nodes`");
        }

        foreach ($nodes as $key => $node) {
            $this->checkNode((string) $key, $node, $nodes, $spriteKey);
        }

        $this->checkReachability($nodes, $start);
        $this->checkFlagWiring($doc);

        return $this->found;
    }

    /**
     * The roots that are not `start` — the ways in from outside the document.
     *
     * Exposed because the graph has to draw them as roots and would otherwise
     * work the list out again from its own copy of the rule.
     *
     * @return string[]
     */
    public function externalRootsIn(array $nodes): array
    {
        $out = [];
        foreach (array_merge(self::EXTERNAL_ROOTS, $this->parleyRoots) as $key) {
            if (isset($nodes[$key])) {
                $out[] = $key;
            }
        }
        return array_values(array_unique($out));
    }

    /** Just the errors — what a save must refuse on. */
    public function errorsOnly(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['level'] === self::ERROR
        ));
    }

    // -----------------------------------------------------------------------

    private function checkNode(string $key, $node, array $nodes, ?string $spriteKey): void
    {
        if (!preg_match(self::KEY_RE, $key)) {
            // load_content.py:1060
            $this->err("\$.nodes.{$key}", "malformed node key '{$key}' — keys are lowercase [a-z0-9_]");
        }

        $variants = $this->variantsOf($node);
        if (!$variants) {
            // load_content.py:1067
            $this->err("\$.nodes.{$key}", 'empty variant list — a node has to say something');
            return;
        }

        $isList = is_array($node) && array_is_list($node);
        $this->checkVariantOrder($key, $variants, $isList);
        $this->checkVariantShadowing($key, $variants, $isList);
        $this->checkPools($key, $variants);

        foreach ($variants as $i => $variant) {
            $at = $isList ? "\$.nodes.{$key}[{$i}]" : "\$.nodes.{$key}";

            $this->fields($at, $variant, self::NODE_REQUIRED, self::NODE_OPTIONAL);

            if (!$isList && (isset($variant['conditions']) || isset($variant['pool']))) {
                // load_content.py:1188-1189, :1195-1196
                $this->err($at, isset($variant['pool'])
                    ? 'only a variant may join a pool; make this node a list of variants'
                    : 'only a variant may carry conditions; make this node a list of variants');
            }

            if ($spriteKey !== null && isset($variant['expression'])) {
                $max = max(1, (int) ($this->bustCounts[$spriteKey] ?? 1));
                $e = (int) $variant['expression'];
                if ($e < 1 || $e > $max) {
                    // load_content.py:1209-1210
                    $this->err(
                        $at . '.expression',
                        "expression {$e} is outside 1..{$max} for sprite_key '{$spriteKey}' "
                        . '(assets/images/npcs/busts.json)'
                    );
                }
            }

            foreach ($variant['interjections'] ?? [] as $j => $line) {
                if (is_array($line)) {
                    $this->fields(
                        "{$at}.interjections[{$j}]", $line,
                        self::INTERJECTION_REQUIRED, self::INTERJECTION_OPTIONAL
                    );
                }
            }

            foreach (array_values($variant['choices'] ?? []) as $c => $choice) {
                $this->checkChoice("{$at}.choices[{$c}]", $choice, $nodes);
            }
        }
    }

    /**
     * The fallback rule.
     *
     * A node's variants are tried in order and the first match wins, so a
     * variant with no conditions ends the list — anything after it is dead. And
     * a list where every variant is conditional has no fallback at all, which is
     * the exact shape `DialogEngine::node()` throws on: "has no variant this
     * party matches". That throw reaches the player as a conversation that will
     * not open, which is how it was found.
     */
    private function checkVariantOrder(string $key, array $variants, bool $isList): void
    {
        if (!$isList) {
            return;
        }
        $last = count($variants) - 1;
        $fallbackAt = null;
        foreach ($variants as $i => $variant) {
            $unconditional = empty($variant['conditions']);
            // load_content.py:1074 — a `once` variant is spent and steps aside,
            // so it does not swallow the variants below it.
            if ($unconditional && empty($variant['once'])) {
                $fallbackAt = $i;
                break;
            }
        }
        if ($fallbackAt === null) {
            // load_content.py:1081-1082
            $this->err(
                "\$.nodes.{$key}",
                'every variant is conditional — with no fallback the conversation '
                . 'dead-ends when none of them pass'
            );
        } elseif ($fallbackAt !== $last) {
            // load_content.py:1077-1078
            $this->err(
                "\$.nodes.{$key}[{$fallbackAt}]",
                'an unconditional variant must come last; the variants after it '
                . 'can never be reached'
            );
        }
    }

    /**
     * A variant an earlier variant will always beat.
     *
     * `checkVariantOrder` above catches the blunt version of this — an
     * UNCONDITIONAL variant swallows everything below it — and that is the only
     * version the loader has ever checked. The quiet version is a conditional
     * variant whose conditions are a subset of a later one's. It reads as two
     * different scenes and it is one: the first matches wherever the second
     * does, it is tried first, so the second is dead. Same fault, same effect on
     * the player, and nothing said so.
     *
     * It matters here more than it would elsewhere because of where the content
     * actually sits: `aldric.greeting` is 53 ordered variants, ilse 49, kessa 46,
     * sera 41. Nobody holds 53 condition sets in their head while adding the
     * 54th, and the failure is silent — the line is simply never spoken.
     *
     * Implication is tested conservatively. A predicate implies another only if
     * it is identical, or if both are `*_at_least` on the same key and the
     * earlier threshold is the lower one. Anything cleverer would need to know
     * that `{"flag":"x","equals":"a"}` and `{"flag":"x","equals":"b"}` are
     * mutually exclusive, which is the condition vocabulary, which lives in
     * `Requirements` and is deliberately not restated here. So this can miss a
     * shadow; it cannot invent one, and that is the right way round for a rule
     * that refuses a save.
     *
     * Two kinds of earlier variant are skipped because neither holds the door
     * shut forever: a `once` variant stands down once it is spent — the same
     * reasoning `checkVariantOrder` applies at load_content.py:1074 — and a
     * pooled variant rotates with its pool.
     */
    private function checkVariantShadowing(string $key, array $variants, bool $isList): void
    {
        if (!$isList) {
            return;
        }
        foreach ($variants as $j => $later) {
            $lateConds = $this->conditionsOf($later);
            if (!$lateConds) {
                // No conditions at all is the fallback, and being beaten to it
                // is exactly what checkVariantOrder already reports. One fault,
                // one finding.
                continue;
            }
            for ($i = 0; $i < $j; $i++) {
                $earlier = $variants[$i];
                if (!empty($earlier['once']) || isset($earlier['pool'])) {
                    continue;
                }
                $earlyConds = $this->conditionsOf($earlier);
                if (!$earlyConds) {
                    continue;
                }
                if (!$this->implies($earlyConds, $lateConds)) {
                    continue;
                }
                $this->err(
                    "\$.nodes.{$key}[{$j}]",
                    "can never be shown — variant {$i} above it is satisfied wherever "
                    . 'this one is, so it always wins. Narrow the earlier variant, or '
                    . 'move this one above it.'
                );
                break;
            }
        }
    }

    /**
     * Does every predicate in $a hold wherever all of $b hold?
     *
     * @param array<int,mixed> $a the earlier variant's conditions
     * @param array<int,mixed> $b the later variant's conditions
     */
    private function implies(array $a, array $b): bool
    {
        foreach ($a as $need) {
            $met = false;
            foreach ($b as $have) {
                if ($this->predicateImplies($need, $have)) {
                    $met = true;
                    break;
                }
            }
            if (!$met) {
                return false;
            }
        }
        return true;
    }

    /** One predicate implying another: identical, or a weaker `*_at_least`. */
    private function predicateImplies($need, $have): bool
    {
        if ($this->canon($need) === $this->canon($have)) {
            return true;
        }
        if (!is_array($need) || !is_array($have) || count($need) !== 1 || count($have) !== 1) {
            return false;
        }
        $nk = array_key_first($need);
        $hk = array_key_first($have);
        if ($nk !== $hk || !in_array((string) $nk, self::AT_LEAST_KEYS, true)) {
            return false;
        }
        return is_numeric($need[$nk]) && is_numeric($have[$hk])
            && (float) $have[$hk] >= (float) $need[$nk];
    }

    /** A stable string for a predicate, so two of them can be compared by value. */
    private function canon($v): string
    {
        if (is_array($v)) {
            $sorted = $v;
            if (!array_is_list($sorted)) {
                ksort($sorted);
            }
            foreach ($sorted as $k => $inner) {
                $sorted[$k] = $this->canon($inner);
            }
            return json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** A variant's conditions as a list, or [] when it has none. */
    private function conditionsOf($variant): array
    {
        if (!is_array($variant) || !isset($variant['conditions'])) {
            return [];
        }
        $c = $variant['conditions'];
        return is_array($c) && array_is_list($c) ? $c : [];
    }

    /**
     * Pool rules.
     *
     * Contiguity is a readability rule rather than an engine one — the engine
     * gathers pool members by name wherever they sit — but a file whose pools
     * are interleaved cannot be read in the order the engine applies it, and
     * that is the whole reason the format is ordered.
     */
    private function checkPools(string $key, array $variants): void
    {
        $seen = [];
        foreach ($variants as $i => $variant) {
            $pool = $variant['pool'] ?? null;
            if (!is_string($pool) || $pool === '') {
                continue;
            }
            if (!preg_match(self::KEY_RE, $pool)) {
                // load_content.py:1145
                $this->err(
                    "\$.nodes.{$key}[{$i}].pool",
                    "malformed pool name '{$pool}' — pool names are lowercase [a-z0-9_]"
                );
            }
            if (empty($variant['conditions'])) {
                // load_content.py:1149-1150
                $this->err(
                    "\$.nodes.{$key}[{$i}]",
                    'a pooled variant needs `conditions` — the unconditional variant '
                    . 'is the node\'s fallback and must stay outside the rotation'
                );
            }
            $seen[$pool][] = $i;
        }

        foreach ($seen as $pool => $positions) {
            if (count($positions) === 1) {
                // load_content.py:1161-1162
                $this->warn(
                    "\$.nodes.{$key}",
                    "pool '{$pool}' has one member, so it rotates between itself and "
                    . 'itself; either give it siblings or drop the key'
                );
                continue;
            }
            $span = max($positions) - min($positions) + 1;
            if ($span !== count($positions)) {
                $gaps = [];
                for ($i = min($positions); $i <= max($positions); $i++) {
                    if (!in_array($i, $positions, true)) {
                        $gaps[] = $i;
                    }
                }
                // load_content.py:1157-1158
                $this->err(
                    "\$.nodes.{$key}",
                    "pool '{$pool}' is split by variant(s) [" . implode(', ', $gaps)
                    . '] — members of a pool must be contiguous, so the file reads '
                    . 'in the order the engine applies'
                );
            }
        }
    }

    private function checkChoice(string $at, $choice, array $nodes): void
    {
        if (!is_array($choice)) {
            $this->err($at, 'expected an object');
            return;
        }
        $this->fields($at, $choice, self::CHOICE_REQUIRED, self::CHOICE_OPTIONAL);

        $exits = array_values(array_filter(
            ['next', 'check', 'end'],
            static fn (string $k): bool => !empty($choice[$k])
        ));
        if (count($exits) > 1) {
            // load_content.py:1250
            $this->err($at, implode(', ', $exits) . ' together — a choice leads one way');
        }

        if (isset($choice['next'])) {
            $this->jump($at . '.next', (string) $choice['next'], $nodes);
        }

        // A reply has to do something. Not one of the loader's rules — it does
        // not check this — but one ContentEditor has enforced since before the
        // graph existed, and it is worth keeping: a choice with no exit renders
        // as a button that closes the conversation and changes nothing.
        //
        // `effects` counts as an exit. Mara's "A room, please." carries only
        // `{"rest": "inn"}` and the engine treats the effect as where the choice
        // goes; nineteen shipped replies are that shape. An earlier version of
        // this rule demanded next-or-end and refused content that had been
        // working for months.
        if (!isset($choice['next']) && empty($choice['end'])
            && empty($choice['effects']) && empty($choice['check'])) {
            $this->err($at, 'goes nowhere — it needs "next", "end": true, a check, or an effect.');
        }

        if (!empty($choice['tag']) && empty($choice['conditions'])) {
            // load_content.py:1244-1245
            $this->warn($at, 'a tag pill with no conditions advertises a requirement that is not enforced');
        }

        if (isset($choice['check']) && is_array($choice['check'])) {
            $check = $choice['check'];
            $this->fields($at . '.check', $check, self::CHECK_REQUIRED, self::CHECK_OPTIONAL);
            foreach (['on_success', 'on_failure'] as $branch) {
                if (isset($check[$branch])) {
                    $this->jump("{$at}.check.{$branch}", (string) $check[$branch], $nodes);
                }
            }
            if (isset($check['skill']) && !isset(Rules::SKILLS[(string) $check['skill']])) {
                // load_content.py:1264-1265
                $this->err(
                    $at . '.check.skill',
                    "'" . $check['skill'] . "' is not an SRD skill (Rules::SKILLS): "
                    . implode(', ', array_keys(Rules::SKILLS))
                );
            }
        }
    }

    /** load_content.py:1255 / :1270 — the dangling-link error. */
    private function jump(string $at, string $target, array $nodes): void
    {
        if (!isset($nodes[$target])) {
            $this->err($at, "no node '{$target}' in this file");
        }
    }

    /**
     * Every node the conversation can actually get to.
     *
     * Flood-fills from `start`, from the external roots the engine can open
     * directly, and from any parley node an encounter names. Anything left over
     * is written, costed and unreachable — the same class of fault as a quest
     * nothing can start.
     */
    private function checkReachability(array $nodes, string $start): void
    {
        $roots = [];
        if ($start !== '' && isset($nodes[$start])) {
            $roots[] = $start;
        }
        foreach (array_merge(self::EXTERNAL_ROOTS, $this->parleyRoots) as $root) {
            if (isset($nodes[$root])) {
                $roots[] = $root;
            }
        }
        if (!$roots) {
            return;
        }

        $seen = [];
        $queue = $roots;
        while ($queue) {
            $key = array_shift($queue);
            if (isset($seen[$key]) || !isset($nodes[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ($this->jumpsFrom($nodes[$key]) as $target) {
                if (!isset($seen[$target])) {
                    $queue[] = $target;
                }
            }
        }

        $from = $start !== '' ? $start : ($roots[0] ?? 'start');
        foreach ($nodes as $key => $_) {
            if (!isset($seen[$key])) {
                // load_content.py:1105-1109
                $this->err(
                    "\$.nodes.{$key}",
                    "unreachable from '{$from}' — no next/on_success/on_failure ever names it"
                );
            }
        }
    }

    /**
     * Where a node can lead. Recursive so it finds a jump wherever it sits —
     * the loader walks the same three keys at any depth, and a choice's check
     * branches are nested two levels down.
     *
     * @return string[]
     */
    public function jumpsFrom($node): array
    {
        $out = [];
        $walk = function ($o) use (&$walk, &$out): void {
            if (!is_array($o)) {
                return;
            }
            foreach ($o as $k => $v) {
                if (is_string($v) && in_array((string) $k, self::JUMP_KEYS, true)) {
                    $out[] = $v;
                } else {
                    $walk($v);
                }
            }
        };
        $walk($node);
        return array_values(array_unique($out));
    }

    /** load_content.py:493-506 */
    private function fields(string $at, $obj, array $required, array $optional): void
    {
        if (!is_array($obj)) {
            $this->err($at, 'expected an object');
            return;
        }
        foreach ($required as $k) {
            if (!array_key_exists($k, $obj)) {
                $this->err($at, "missing required field '{$k}'");
            }
        }
        $known = array_merge($required, $optional);
        foreach (array_keys($obj) as $k) {
            if (!in_array((string) $k, $known, true)) {
                $this->err($at . '.' . $k, "unknown field '{$k}'");
            }
        }
    }

    /**
     * A node is either one variant or a list of them. Both shapes are authored
     * in the shipped files, so both have to lint.
     *
     * @return array<int, array>
     */
    public function variantsOf($node): array
    {
        if (!is_array($node)) {
            return [];
        }
        return array_is_list($node)
            ? array_values(array_filter($node, 'is_array'))
            : [$node];
    }

    /**
     * Flags gated on but never set, and flags set but never gated on.
     *
     * The first is a dead gate: a condition that cannot pass, so everything
     * behind it is written, costed and unreachable — the same class of fault as
     * an orphan node, except that reachability walks the edges and finds
     * nothing wrong with it. This is the check that answers "is this node ever
     * reachable", which walking the graph cannot.
     *
     * The second is a dead effect: a flag set and read by nothing. It is not
     * always a fault — an author laying breadcrumbs for content not written yet
     * sets flags nothing reads, deliberately, and `ludo_corse` alone has seven —
     * which is why it warns. It is still the only place "I wrote the branch and
     * never hooked it up" would show.
     *
     * The document's own flags are merged into both injected sets before
     * comparing, because on the save path the world index was gathered from the
     * database and this document is the one thing in the world that has not
     * been written to it yet. Without the merge, adding a gate and the effect
     * that opens it in one edit would report both halves as broken.
     *
     * ENGINE_FLAGS is merged in for a different reason: those are written by
     * code, so no amount of reading the content will ever find the effect that
     * sets them.
     */
    private function checkFlagWiring(array $doc): void
    {
        if (!$this->flagsWritten && !$this->flagsRead) {
            return;
        }

        $written = $this->flagsWritten;
        foreach (self::ENGINE_FLAGS as $f) {
            $written[$f] = true;
        }
        foreach (self::flagsWrittenIn($doc) as $f) {
            $written[$f] = true;
        }
        $read = $this->flagsRead;
        foreach (self::flagsReadIn($doc) as $f) {
            $read[$f] = true;
        }

        foreach (self::flagSites($doc, false) as [$at, $flag]) {
            if (!isset($written[$flag])) {
                $this->warn(
                    $at,
                    "no effect anywhere sets the flag '{$flag}', so this condition can "
                    . 'never pass and what it gates can never be seen'
                );
            }
        }
        foreach (self::flagSites($doc, true) as [$at, $flag]) {
            if (!isset($read[$flag])) {
                $this->warn(
                    $at,
                    "the flag '{$flag}' is set here and no condition anywhere reads it"
                );
            }
        }
    }

    /**
     * Every flag this document reads in a condition.
     *
     * Public because ContentEditor builds the world index out of these, and a
     * second walk written over there would be a second thing to keep right.
     *
     * @return string[]
     */
    public static function flagsReadIn(array $doc): array
    {
        return array_values(array_unique(array_column(self::flagSites($doc, false), 1)));
    }

    /**
     * Every flag this document sets in an effect.
     *
     * @return string[]
     */
    public static function flagsWrittenIn(array $doc): array
    {
        return array_values(array_unique(array_column(self::flagSites($doc, true), 1)));
    }

    /**
     * Where each flag is named, as [json path, flag] pairs.
     *
     * Recursive rather than shape-aware, for the reason `jumpsFrom` is: a
     * condition sits on a variant, a choice and an interjection, an effect sits
     * on `on_enter` and on a choice's `effects`, and a walk that enumerated
     * those places would need editing every time one more was added. Descent
     * stops at a `conditions` or effects list — inside it, predicates and
     * effects are read by the two helpers below rather than walked blindly, so
     * a node key that happens to be spelled `flag` cannot be mistaken for one.
     *
     * @param  bool $writes true for effects, false for conditions
     * @return array<int, array{0:string, 1:string}>
     */
    private static function flagSites(array $doc, bool $writes): array
    {
        $out = [];
        $branch = $writes ? ['effects', 'on_enter', 'on_exit'] : ['conditions'];

        $walk = function ($node, string $path) use (&$walk, &$out, $branch, $writes): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if (!is_array($v)) {
                    continue;
                }
                $here = array_is_list($node) ? "{$path}[{$k}]" : "{$path}.{$k}";
                if (in_array((string) $k, $branch, true)) {
                    $flags = $writes ? self::effectFlags($v) : self::conditionFlags($v);
                    foreach ($flags as $f) {
                        $out[] = [$here, $f];
                    }
                    continue;
                }
                $walk($v, $here);
            }
        };
        $walk($doc, '$');

        return $out;
    }

    /**
     * Flag names read by a condition list.
     *
     * `not` holds one predicate and `any` holds a list of them, so both are
     * followed — a flag read under a negation is still read, and a gate that
     * can never pass is worth the same finding whichever way round it is
     * written.
     *
     * @return string[]
     */
    private static function conditionFlags($conditions): array
    {
        $out = [];
        foreach (is_array($conditions) ? $conditions : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            if (isset($c['flag']) && is_string($c['flag'])) {
                $out[] = $c['flag'];
            }
            if (isset($c['not']) && is_array($c['not'])) {
                $out = array_merge($out, self::conditionFlags([$c['not']]));
            }
            if (isset($c['any']) && is_array($c['any'])) {
                $out = array_merge($out, self::conditionFlags($c['any']));
            }
        }
        return $out;
    }

    /**
     * Flag names written by an effect list. Accepts a bare effect object too,
     * because `quest_stages.effects_json` is authored both ways.
     *
     * @return string[]
     */
    private static function effectFlags($effects): array
    {
        if (!is_array($effects)) {
            return [];
        }
        $list = array_is_list($effects) ? $effects : [$effects];
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) {
                continue;
            }
            foreach (['set_flag', 'increment_flag'] as $k) {
                if (isset($e[$k]) && is_string($e[$k])) {
                    $out[] = $e[$k];
                }
            }
        }
        return $out;
    }

    private function err(string $where, string $message): void
    {
        $this->found[] = ['level' => self::ERROR, 'where' => $where, 'message' => $message];
    }

    private function warn(string $where, string $message): void
    {
        $this->found[] = ['level' => self::WARNING, 'where' => $where, 'message' => $message];
    }
}
