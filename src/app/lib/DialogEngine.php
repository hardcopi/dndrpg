<?php
/**
 * Branching conversation: which line an NPC says, which replies are offered,
 * and what saying one does to the world.
 *
 * The node format is the one the old engine used — a map of keys to
 * `{text, choices}` — extended rather than replaced, so seeded dialogue still
 * parses. What is new is that a node key may hold an ordered list of variants
 * instead of one node, and that a choice may carry conditions, an ability
 * check and a list of effects. Everything the player experiences as an NPC
 * remembering something is those three things over WorldState's flags; there
 * is no separate memory system to fall out of step with them.
 *
 * Variants are ordered and the first match wins, which makes a settled
 * playthrough hear one line forever. `pool` is the answer to that: variants
 * sharing a pool name are interchangeable at one priority and are rotated
 * between visits. See chooseVariant(), which is where both rules live.
 *
 * The format is specified in docs/CONTENT.md and validated at import time by
 * tools/load_content.py, so this class trusts its input's shape and does not
 * re-litigate it. What it will not trust is the client: a choice index arrives
 * from the browser, and a player who edits one must not be able to take an
 * option their character was never offered.
 */

declare(strict_types=1);

class DialogEngine
{
    private PDO $db;
    private WorldState $world;
    private Effects $effects;
    private CheckService $checks;
    /* Built on demand: most nodes never ask about a quest gate. */
    private ?QuestService $quests = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->world = new WorldState($db);
        $this->effects = new Effects($db);
        $this->checks = new CheckService($db);
    }

    /**
     * Open a conversation, or move it to a named node.
     *
     * Returns everything the dialogue screen draws: who is speaking, which
     * expression they are wearing, the line, the companion asides, and the
     * replies this party is actually allowed to give.
     */
    public function node(int $partyId, string $npcKey, ?string $nodeKey = null): array
    {
        $npc = $this->npc($npcKey);
        $tree = $npc['dialogue'];

        // Somebody with no dialogue at all is not a broken conversation, it is
        // a person who does not have one — the children in the market square,
        // for instance, are scenery you can walk up to. That is a fact about
        // the NPC, so it is answered rather than thrown: an empty tree returns
        // no node, and the client says so in the log.
        //
        // The throw below still stands for the different case, where a tree
        // exists but no variant of the requested node matches. That one really
        // is content edited past the loader and worth shouting about.
        if (!$tree) {
            // Shaped as a node because the route hands whatever this returns
            // straight back under `node`; `silent` is the flag the client tests.
            return [
                'silent'   => true,
                'npc_key'  => $npcKey,
                'name'     => (string) ($npc['name'] ?? ''),
                'text'     => '',
                'choices'  => [],
            ];
        }

        $nodeKey ??= (string) ($tree['start'] ?? 'start');

        $ctx = $this->world->context($partyId, session_character_id());
        $resolved = $this->resolveNode($tree, $nodeKey, $ctx, $partyId, $npcKey);
        if ($resolved === null) {
            // Validated content cannot reach here — every variant list ends in
            // an unconditional fallback. Reaching it anyway means content was
            // hand-edited in the database past the loader, so say which node
            // rather than rendering an empty box.
            throw new RuntimeException(
                "Dialogue node '{$nodeKey}' on '{$npcKey}' has no variant this party matches."
            );
        }

        return self::forClient($resolved);
    }

    /**
     * A node payload with the engine's own bookkeeping taken off.
     *
     * `_variant` is the drawn variant, `_raw` and `_authored` hang off each
     * reply, and none of the three is the browser's business. `_raw` in
     * particular carried every reply's effects and conditions out to the page —
     * the quest a reply starts, the flag it sets, the DC behind a check — which
     * is a walkthrough served alongside the conversation. Nothing in the client
     * has ever read any of them.
     *
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private static function forClient(array $node): array
    {
        unset($node['_variant']);
        foreach ($node['choices'] ?? [] as $i => $choice) {
            unset($choice['_raw'], $choice['_authored']);
            $node['choices'][$i] = $choice;
        }
        return $node;
    }

    /**
     * Take a reply.
     *
     * Three shapes come back, and the client branches on which key is present:
     * a `check` to roll before anything happens, a `node` to render next, or a
     * `close` with whatever the effects produced. Effects always run first —
     * a choice that both sets a flag and jumps has already set it by the time
     * the destination node's conditions are evaluated, which is what lets a
     * node react to the very choice that led to it.
     */
    public function choose(int $partyId, string $npcKey, string $nodeKey, int $choiceIndex): array
    {
        $npc = $this->npc($npcKey);
        $ctx = $this->world->context($partyId, session_character_id());
        $node = $this->resolveNode($npc['dialogue'], $nodeKey, $ctx, $partyId, $npcKey, false);
        if ($node === null) {
            throw new RuntimeException("Dialogue node '{$nodeKey}' is not available.");
        }

        // Index into the FILTERED list, not the authored one. The client only
        // ever saw the filtered list, so anything else would let a tampered
        // index reach a reply whose conditions failed.
        $choice = $node['choices'][$choiceIndex] ?? null;
        if ($choice === null) {
            throw new InvalidArgumentException('That reply is not available.');
        }
        $raw = $choice['_raw'];

        // The player has now answered this variant, so it is spent. Here rather
        // than where it was drawn, so a conversation that was opened and shut
        // without being read leaves the `once` beat intact for next time.
        //
        // Marked before the reply is acted on: the effects below can navigate
        // away, start a fight or end the conversation, and a `once` that only
        // retires on the quiet paths would come back around.
        if (!empty($node['_variant']['once'])) {
            $this->world->set(
                $partyId,
                $this->seenFlag($npcKey, $nodeKey, $node['_variant'])
            );
        }

        // Greyed in the panel, and refused here for the same reason a spent
        // check is: the index arrives in a request body. Refused as a rejected
        // reply rather than by letting QuestService::start throw, so the panel
        // re-enables its replies and the conversation survives.
        if (!empty($choice['locked'])) {
            throw new InvalidArgumentException(
                'Not yet — that work wants ' . strtolower((string) $choice['locked']) . '.'
            );
        }

        if (!empty($raw['check'])) {
            // The client greys a spent check, but the client is not a guard:
            // this is the one that matters, because the index arrives in a
            // request body and a failed persuasion must stay failed.
            if (!empty($choice['spent'])) {
                throw new InvalidArgumentException(
                    'You have already tried that, and it did not work.'
                );
            }
            return $this->beginCheck(
                $partyId, $npcKey, $nodeKey, $raw, (int) $choice['_authored']
            );
        }

        $result = $this->effects->apply($partyId, $raw['effects'] ?? [], [
            'source' => "dialog:{$npcKey}:{$nodeKey}",
        ]);
        // Those effects were written through Effects' own WorldState, whose
        // flag cache is not this one. Anything resolved below — the next node,
        // its variants, its replies — must see what the reply just changed.
        $this->world->forget($partyId);

        return $this->afterChoice($partyId, $npcKey, $raw, $result);
    }

    /**
     * Finish a reply that was waiting on a die.
     *
     * The destination was decided when the check was offered and stashed with
     * it, so the outcome selects between two node keys that were fixed before
     * the roll. Nothing about where the conversation goes is computed after
     * the number is known.
     */
    public function resolveCheck(int $partyId, string $checkId, array $boosts): array
    {
        $roll = $this->checks->resolve($checkId, $boosts);
        $ctxKey = $roll['context'] ?? null;
        if (!is_array($ctxKey) || ($ctxKey['kind'] ?? '') !== 'dialog') {
            throw new InvalidArgumentException('That check did not come from a conversation.');
        }

        $npcKey = (string) $ctxKey['npc'];
        $raw = $ctxKey['choice'];
        $passed = in_array($roll['outcome'], ['success', 'critical'], true);

        // A failed check is spent. Recorded here rather than on the failure
        // NODE, because several choices route to the same one — Hollis has
        // four that all land on `he_is_untroubled` — and marking the node
        // would spend all four on one bad roll.
        if (!$passed && isset($ctxKey['node'], $ctxKey['authored'])
            && empty($raw['check']['retry'])) {
            $this->world->set($partyId, self::spentFlag(
                $npcKey, (string) $ctxKey['node'], (int) $ctxKey['authored']
            ));
        }

        $result = $this->effects->apply($partyId, $raw['effects'] ?? [], [
            'source' => "dialog:{$npcKey}",
        ]);
        // Outcome-specific effects belong on the destination node's on_enter,
        // which resolveNode applies. Keeping them off the choice is what lets
        // an author see, in one place, what a failed persuasion actually cost.
        $next = $passed
            ? (string) $raw['check']['on_success']
            : (string) $raw['check']['on_failure'];

        $out = $this->afterChoice($partyId, $npcKey, ['next' => $next], $result);
        $out['roll'] = $roll;
        return $out;
    }

    // -----------------------------------------------------------------------

    /**
     * Stash the pending check with the choice that raised it.
     *
     * CheckService owns the token and the boost accounting; all this adds is
     * the conversation to return to, so a resolved roll knows which two nodes
     * it was choosing between.
     */
    private function beginCheck(
        int $partyId,
        string $npcKey,
        string $nodeKey,
        array $raw,
        int $authoredIndex
    ): array {
        $check = $raw['check'];
        $spec = ['dc' => (int) $check['dc']];
        if (isset($check['skill'])) {
            $spec['skill'] = (string) $check['skill'];
        } elseif (isset($check['save'])) {
            $spec['save'] = (string) $check['save'];
        } elseif (isset($check['ability'])) {
            $spec['ability'] = (string) $check['ability'];
        }
        $spec['context'] = [
            'kind'     => 'dialog',
            'npc'      => $npcKey,
            'node'     => $nodeKey,
            'choice'   => $raw,
            'authored' => $authoredIndex,
        ];

        return ['check' => $this->checks->request($partyId, $spec, session_character_id())];
    }

    /**
     * What happens after a reply's effects have run.
     *
     * An effect can end the conversation out from under the author — opening a
     * shop, starting a fight, travelling — so those are checked before `next`.
     * Otherwise a choice that starts an ambush would also try to render the
     * node it pointed at, over the top of the fight.
     */
    private function afterChoice(int $partyId, string $npcKey, array $raw, array $result): array
    {
        $out = ['result' => $result];

        foreach (['combat', 'shop', 'travel'] as $interrupt) {
            if (!empty($result[$interrupt])) {
                $out['close'] = true;
                $out[$interrupt] = $result[$interrupt];
                return $out;
            }
        }

        if (!empty($raw['end']) || !empty($result['end_dialog'])) {
            $out['close'] = true;
            return $out;
        }

        if (!empty($raw['next'])) {
            $out['node'] = $this->node($partyId, $npcKey, (string) $raw['next']);
            return $out;
        }

        // A reply with no exit — "buy a room", "thanks" — returns to where it
        // was said rather than closing, so a shopkeeper is still standing
        // there after you have done the thing you came for. The old engine
        // closed the modal on any non-`next` action, which meant you could not
        // rest and then ask a question.
        $out['close'] = true;
        return $out;
    }

    /**
     * Pick the variant this party matches and render it.
     *
     * `$applyEnter` is false when re-resolving a node only to validate a choice
     * index against it: applying on_enter effects a second time would pay the
     * node's gold or set its flags twice for one reply.
     *
     * Which variant is chosen is remembered when the node is drawn, and the
     * validating pass reads it back rather than deciding again. It has to: a
     * node whose `on_enter` sets a flag that one of its OWN variants is
     * conditioned on — the "we have had this conversation already" pattern the
     * whole camp script is built on — changes which variant matches between
     * drawing the replies and hearing one. Kessa's `camp_kessa_the_mark` drew
     * three replies, and by the time one came back the short variant matched
     * and had two, so reply three was "not available" and replies one and two
     * silently answered with lines from a version of the scene the player was
     * never shown. Deciding once, at the moment the player is looking at it, is
     * the only reading that can be right.
     */
    private function resolveNode(
        array $tree,
        string $nodeKey,
        array $ctx,
        int $partyId,
        string $npcKey,
        bool $applyEnter = true
    ): ?array {
        $variants = $tree['nodes'][$nodeKey] ?? null;
        if ($variants === null) {
            return null;
        }
        // The loader normalises every node to a variant list, but dialogue
        // seeded before it existed is still a bare object.
        if (!array_is_list($variants)) {
            $variants = [$variants];
        }

        $index = $applyEnter
            ? $this->matchVariant($variants, $ctx, $partyId, $npcKey, $nodeKey, true)
            : $this->drawnVariant($variants, $ctx, $partyId, $npcKey, $nodeKey);
        if ($index === null) {
            return null;
        }
        $node = $variants[$index];

        if ($applyEnter) {
            // Remembered before the effects below run, because they are exactly
            // what can invalidate the match. Only worth storing where there is
            // a choice to remember.
            if (count($variants) > 1) {
                $this->world->set(
                    $partyId,
                    self::drawnFlag($npcKey, $nodeKey),
                    (string) $index
                );
            }
            // `once` is NOT spent here. Drawing a variant is not the same as
            // the player having had it: opening a conversation and closing it
            // — or clicking the same person twice, which re-opens and
            // re-resolves — burned the variant without a word of it ever
            // reaching the screen. Sella Carrow's whole introduction went that
            // way on the first conversation of a playthrough, and because it
            // was the only place she says who she or her daughter are, it was
            // unrecoverable. It is spent in choose(), when a reply comes back.
            if (!empty($node['on_enter'])) {
                $this->effects->apply($partyId, $node['on_enter'], [
                    'source' => "dialog:{$npcKey}:{$nodeKey}",
                ]);
                // Effects may have changed what the choices below should show —
                // a node that grants an item and then offers to use it — so the
                // snapshot is rebuilt rather than reused. Rebuilt from the
                // DATABASE: those effects went through Effects' own WorldState,
                // and re-reading this one's cached flags would hand back the
                // view from before they ran. That is what left a camp topic
                // sitting on the menu in the same breath as the told-flag it
                // had just set.
                $this->world->forget($partyId);
                $ctx = $this->world->context($partyId, session_character_id());
            }
        }

        $speakerKey = (string) ($node['speaker'] ?? $npcKey);
        $speaker = $speakerKey === $npcKey ? $this->npc($npcKey) : $this->npc($speakerKey);

        return [
            // The variant that was actually drawn, for choose() to mark spent.
            // Underscore-prefixed like `_raw` and `_authored` below, and
            // stripped by forClient() before any of this reaches the browser.
            '_variant'      => $node,
            'npc_key'       => $npcKey,
            'node_key'      => $nodeKey,
            'speaker'       => [
                'name'       => $speaker['name'],
                'role'       => $speaker['role'],
                'sprite_key' => $speaker['sprite_key'],
                // For the client's onerror fallback: not every speaker has a
                // bust cut (the pack ships none for children), and a broken
                // image on the theatre's shoulder is worse than the walk
                // sprite standing in.
                'image_url'  => $speaker['image_url'] ?? null,
                'bust'       => self::bust($speaker, (int) ($node['expression'] ?? 1)),
            ],
            'text'          => (string) $node['text'],
            // The recorded reading, when there is one. Shipped whether or not
            // the player has the voiceover switched on: it is a handful of URLs
            // against a payload that already carries a paragraph of prose, and
            // making the toggle a round trip would put a silence in front of
            // every line the first time somebody turned it on.
            'vo'            => Voiceover::clips($npcKey, (string) $node['text']),
            'interjections' => $this->interjections($node, $ctx, $partyId, $npcKey, $nodeKey),
            'choices'       => $this->choices($node, $ctx, $partyId, $npcKey, $nodeKey),
        ];
    }

    /**
     * Companion asides, from whoever is actually travelling with the party.
     *
     * A companion waiting at camp does not get to comment on a conversation
     * they are not in, which is the difference between a party that feels
     * present and a chorus that follows you everywhere.
     */
    private function interjections(array $node, array $ctx, int $partyId, string $npcKey, string $nodeKey): array
    {
        $out = [];
        foreach ($node['interjections'] ?? [] as $line) {
            $key = (string) ($line['companion'] ?? '');
            $state = $ctx['companions'][$key] ?? null;
            if (!$state || $state['status'] !== 'active') {
                continue;
            }
            if (!Requirements::pass($line['conditions'] ?? null, $ctx)) {
                continue;
            }
            // `once`: an aside that lands once and is never repeated. The
            // loader and lint have accepted the field since the interjection
            // shape was defined; this is the first code to read it. Spent at
            // render rather than at choose() — an aside is heard the moment
            // the scene plays, unlike a variant's opener which is only truly
            // "had" once the player answers it.
            if (!empty($line['once'])) {
                $flag = 'ijseen:' . $npcKey . ':' . $nodeKey . ':' . $key
                    . ':' . substr(md5((string) ($line['text'] ?? '')), 0, 8);
                if ($this->world->number($partyId, $flag) > 0) {
                    continue;
                }
                $this->world->set($partyId, $flag);
            }
            $out[] = [
                'companion' => $key,
                'text'      => (string) $line['text'],
                'approval'  => $state['approval'],
                // Filed under the NPC whose conversation the aside belongs to,
                // not under the companion: an aside is written for this scene
                // and is recorded with it.
                'vo'        => Voiceover::clips($npcKey, (string) $line['text']),
            ];
        }
        return $out;
    }

    /**
     * The replies this party may give, in authored order.
     *
     * A choice whose conditions fail is dropped entirely rather than shown
     * greyed out. Showing it would advertise content the player cannot reach
     * and, worse, tell them exactly what they would have needed — which turns
     * an origin option from a reward for who you are into a list of what you
     * are not.
     *
     * The DC is deliberately visible on a check option. That is the BG3
     * convention and it is what makes the roll a decision rather than a
     * surprise.
     */
    private function choices(
        array $node,
        array $ctx,
        int $partyId,
        string $npcKey,
        string $nodeKey
    ): array {
        $out = [];
        // Replies dropped as stale, kept only to notice the case where every
        // reply was stale — see the rescue at the bottom.
        $hidden = [];
        // Index into the AUTHORED list, because that is what identifies a
        // check across sessions. The filtered list below is what the client
        // indexes into, and the two are deliberately different things.
        foreach (array_values($node['choices'] ?? []) as $authored => $choice) {
            if (!Requirements::pass($choice['conditions'] ?? null, $ctx)) {
                continue;
            }
            // A job already taken is not an offer any more, and neither is a
            // decision already made. Dropped on the same terms as a failed
            // condition, and for the same reason: the menu should only hold
            // things that would do something.
            if ($this->offerAlreadyTaken($partyId, $choice)
                || $this->decisionAlreadyMade($partyId, $choice)) {
                // Keyed by the authored index, not appended: `_authored` is how
                // a check is identified across sessions, and a renumbered one
                // would point at a different reply.
                $hidden[$authored] = $choice;
                continue;
            }
            $entry = [
                'label' => (string) $choice['label'],
                'tag'   => $choice['tag'] ?? $this->questOfferTag($choice),
                '_raw'  => $choice,
            ];
            if (!empty($choice['check'])) {
                $check = $choice['check'];
                $entry['check'] = [
                    'dc'    => (int) $check['dc'],
                    'label' => $this->checkLabel($check),
                ];
                // A check you have already failed is still listed, greyed, so
                // the player can see what they tried and lost. Offering it
                // live would make every DC in the game advisory: you would
                // simply say it again until the die agreed.
                if ($this->checkSpent($partyId, $npcKey, $nodeKey, $authored, $check)) {
                    $entry['spent'] = true;
                }
            }
            if (($why = $this->questGate($partyId, $choice)) !== null) {
                $entry['locked'] = $why;
            }
            $entry['_authored'] = $authored;
            $out[] = $entry;
        }

        // A node whose every reply turned out to be stale would be a dead end
        // with no way out — the player would be looking at a speech and no
        // menu. Nakka's `raid_halt` second variant is exactly that shape: one
        // reply, and it settles the quest.
        //
        // So the filter yields. Showing a spent decision is a small wrong;
        // stranding somebody in a conversation is a large one, and the engine
        // already refuses the stale advance underneath, so nothing is
        // corrupted by letting it through.
        if (!$out && $hidden) {
            foreach ($hidden as $authored => $choice) {
                $out[] = [
                    'label'     => (string) $choice['label'],
                    'tag'       => $choice['tag'] ?? null,
                    '_raw'      => $choice,
                    '_authored' => $authored,
                ];
            }
        }
        return $out;
    }

    /**
     * A reply that settles a quest which is already settled.
     *
     * The sibling of offerAlreadyTaken(), and here for the same reason it is:
     * "do not offer a decision that has already been made" is true of every
     * such reply, so leaving it to 57 content files is leaving it to be wrong.
     *
     * The failure it prevents is not cosmetic. QuestService::advance() refuses
     * to move a quest that has ended — but a reply's OTHER effects have already
     * run by then, because effects are applied in order and independently.
     * Sera's "Sixty gold, in writing. Stay." takes the sixty gold and then
     * fails to change anything, and it stayed on the menu after the matter was
     * settled, so it could be picked again. And again.
     *
     * Only `completed` and `failed` count. Advancing an ACTIVE quest is the
     * normal case and most of what dialogue does.
     *
     * `reoffer` opts out, exactly as it does for a quest offer — an author who
     * wants a decision revisitable says so.
     */
    private function decisionAlreadyMade(int $partyId, array $choice): bool
    {
        if (!empty($choice['reoffer'])) {
            return false;
        }
        foreach ($choice['effects'] ?? [] as $effect) {
            $spec = $effect['quest_stage'] ?? null;
            if (!is_array($spec) || !is_string($spec['quest'] ?? null)) {
                continue;
            }
            $status = $this->quests()->statusFor($partyId, (string) $spec['quest']);
            if (in_array($status, ['completed', 'failed'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is this reply offering work the party is too green to take?
     *
     * A quest carries `required_level`, and `QuestService::start` enforces it by
     * throwing. That is right for a job board, where the row can simply be left
     * out of the list, and wrong for a conversation: the reply had already been
     * offered, so the throw came back as a 400 and killed the whole dialogue.
     * Reed opens on it — a level 1 party asking about the raids had no reply
     * that did not end the conversation in an error.
     *
     * So the gate is read here, where the replies are shaped, and reported the
     * way a spent check is: still listed, greyed, and saying what it wants. The
     * player learns the job exists and that they should come back, which is the
     * one thing hiding the reply could not tell them. `choose` refuses it too —
     * this is presentation, and presentation is not a guard.
     */
    private function questGate(int $partyId, array $choice): ?string
    {
        foreach ($choice['effects'] ?? [] as $effect) {
            $questKey = $effect['start_quest'] ?? null;
            if (!is_string($questKey)) {
                continue;
            }
            $need = $this->quests()->levelGate($partyId, $questKey);
            if ($need !== null) {
                return 'Level ' . $need;
            }
        }
        return null;
    }

    /**
     * A reply offering work the party already has, or already finished.
     *
     * Content gates these by hand, and mostly does not: of the 51 replies in the
     * game that start a quest, 37 carried no condition about the quest's own
     * state. `QuestService::start()` treats a second offer as a note rather than
     * an error — deliberately, because two NPCs may both know about the same
     * job — so the reply stayed on the menu forever and did nothing when taken.
     * The player says "I'll look into it" to a captain who already sent them,
     * and the conversation returns them to the same list. That reads as the
     * character having nothing left, which is the shape of a dead end.
     *
     * Decided here rather than in the 37 files because it is not an authoring
     * decision. "Do not offer a job the party is already doing" is true of every
     * such reply, and a rule that has to be remembered 51 times is a rule that
     * will be wrong the 52nd. An author who genuinely wants a reply to survive
     * being taken — a quest that can be restarted, or a line that only mentions
     * the job in passing — says so with `"reoffer": true`.
     *
     * Hiding, not greying. A spent check is greyed because the player chose it
     * and lost; an offer they accepted is not a failure to show them, it is
     * simply no longer an offer. Checked against every node in the game before
     * turning it on: not one consists solely of quest-offering replies, so this
     * can never empty a node and leave a conversation with no way out.
     */
    private function offerAlreadyTaken(int $partyId, array $choice): bool
    {
        if (!empty($choice['reoffer'])) {
            return false;
        }
        foreach ($choice['effects'] ?? [] as $effect) {
            $questKey = $effect['start_quest'] ?? null;
            if (!is_string($questKey)) {
                continue;
            }
            $status = $this->quests()->statusFor($partyId, $questKey);
            if (in_array($status, ['active', 'completed', 'failed'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a reply is the one that takes a job on.
     *
     * The UI draws this as a pill, the way it draws an origin tag, so that the
     * reply which changes what the party is doing does not look like the reply
     * that asks a follow-up question. Not one of the 51 quest-starting replies
     * in the game carried a tag of its own — the pill is display, authors were
     * using it for origins, and nobody thought to mark the thing the whole
     * conversation exists to reach.
     *
     * Derived rather than authored for the same reason as above: it is a fact
     * about the reply's effects, not an opinion about it, so asking authors to
     * remember it would only produce replies that lie by omission. An author who
     * wants different words uses their own `tag`, which wins.
     */
    private function questOfferTag(array $choice): ?string
    {
        if (isset($choice['tag'])) {
            return null;
        }
        foreach ($choice['effects'] ?? [] as $effect) {
            if (is_string($effect['start_quest'] ?? null)) {
                return 'QUEST';
            }
        }
        return null;
    }

    private function quests(): QuestService
    {
        return $this->quests ??= new QuestService($this->db);
    }

    /**
     * Has this party already failed this exact check?
     *
     * Identified by npc, node and the choice's position in the AUTHORED list,
     * which is stable for as long as the node is not re-ordered. Re-ordering a
     * node's choices in content will therefore mis-attribute an existing
     * save's failures — acceptable, because the alternative is hashing the
     * label and having a copy-edit forgive a failed roll.
     *
     * An author who genuinely wants a retryable check says so:
     *
     *     "check": { "skill": "persuasion", "dc": 13, "retry": true, ... }
     */
    private function checkSpent(
        int $partyId,
        string $npcKey,
        string $nodeKey,
        int $authoredIndex,
        array $check
    ): bool {
        if (!empty($check['retry'])) {
            return false;
        }
        return $this->world->isSet($partyId, self::spentFlag($npcKey, $nodeKey, $authoredIndex));
    }

    /** Where a failed check is remembered. Party-scoped, like everything else. */
    private static function spentFlag(string $npcKey, string $nodeKey, int $authoredIndex): string
    {
        return "check_failed:{$npcKey}:{$nodeKey}:{$authoredIndex}";
    }

    private function checkLabel(array $check): string
    {
        if (isset($check['skill'])) {
            return ucwords(str_replace('_', ' ', (string) $check['skill']));
        }
        if (isset($check['save'])) {
            return (Rules::ABILITY_NAMES[(string) $check['save']] ?? (string) $check['save']) . ' save';
        }
        return Rules::ABILITY_NAMES[(string) ($check['ability'] ?? '')] ?? 'Check';
    }

    /**
     * The bust to show, clamped to what was actually cut.
     *
     * Expression counts come from assets/images/npcs/busts.json at import time
     * and only four actors in the packs ship more than one, so asking for an
     * expression that does not exist is an ordinary authoring slip rather than
     * an exceptional one. Falling back to the base bust beats a broken image.
     *
     * Static and public because the content editor's conversation reader shows
     * the same portrait for the same variant, and this naming rule — the `_bust`
     * / `_bust_N` suffix and the clamp — is the whole of what "which portrait"
     * means. A copy of it in JavaScript would be free to disagree with the
     * engine about which file an `expression` names, and the disagreement would
     * show up as the editor previewing a face the player never sees.
     */
    public static function bust(array $npc, int $expression): string
    {
        $key = (string) ($npc['sprite_key'] ?? 'fighter');
        $count = max(1, (int) ($npc['bust_count'] ?? 1));
        $expression = max(1, min($expression, $count));
        $suffix = $expression > 1 ? "_bust_{$expression}" : '_bust';
        return "assets/images/npcs/{$key}{$suffix}.png";
    }

    /** A `once` variant is remembered per node and per position within it. */
    private function seenFlag(string $npcKey, string $nodeKey, array $variant): string
    {
        return 'seen:' . $npcKey . ':' . $nodeKey
            . ':' . substr(md5((string) ($variant['text'] ?? '')), 0, 8);
    }

    /** Which variant of a node this party was last shown. */
    private static function drawnFlag(string $npcKey, string $nodeKey): string
    {
        return "drawn:{$npcKey}:{$nodeKey}";
    }

    /** How far round a pool this party has got. Party-scoped, like everything else. */
    private static function poolFlag(string $npcKey, string $nodeKey, string $pool): string
    {
        return "pool:{$npcKey}:{$nodeKey}:{$pool}";
    }

    /**
     * The variant this party is shown, and the cursor moved on if it came from
     * a pool.
     *
     * Gathers the two pieces of party state chooseVariant() needs — which
     * `once` variants are spent, and where each pool's cursor stands — and then
     * defers the actual decision to that pure static, which is where the
     * ordering rules live and where they can be tested without a database.
     *
     * `$advance` is the whole of the difference between drawing a line and
     * merely looking one up. Only the render pass moves a cursor; the pass that
     * re-resolves a node to validate a reply index must not, or answering a
     * question would silently spend the next idle line. It is false by default
     * so that the read-only paths cannot forget.
     */
    private function matchVariant(
        array $variants,
        array $ctx,
        int $partyId,
        string $npcKey,
        string $nodeKey,
        bool $advance = false
    ): ?int {
        $retired = [];
        $cursors = [];
        foreach ($variants as $i => $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if (!empty($variant['once'])
                && $this->world->isSet($partyId, $this->seenFlag($npcKey, $nodeKey, $variant))) {
                $retired[$i] = true;
            }
            $pool = $variant['pool'] ?? null;
            if (is_string($pool) && $pool !== '' && !array_key_exists($pool, $cursors)) {
                $cursors[$pool] = (int) ($this->world->get(
                    $partyId,
                    self::poolFlag($npcKey, $nodeKey, $pool)
                ) ?? '0');
            }
        }

        // Both loops above read WorldState's per-request cache, so a node with
        // no `once` variants and no pools costs exactly what it did before:
        // $retired and $cursors come out empty and chooseVariant degenerates to
        // the first-match walk it replaced.
        $draw = self::chooseVariant($variants, $ctx, $retired, $cursors);
        if ($draw['index'] === null) {
            return null;
        }
        if ($advance && $draw['pool'] !== null) {
            $this->world->set(
                $partyId,
                self::poolFlag($npcKey, $nodeKey, (string) $draw['pool']),
                (string) $draw['next']
            );
        }
        return $draw['index'];
    }

    /**
     * Which variant of a node a playthrough sees, given what it has used up.
     *
     * Priority first, rotation second, and in that order — because the order is
     * the compatibility contract. The authored list is walked from the top
     * exactly as it always was, and the first variant that is neither retired
     * nor conditioned out is the answer. A node with no `pool` keys therefore
     * behaves identically to the engine before pools existed: it returns at the
     * first match and reports no pool, so nothing is written and nothing moves.
     *
     * If that first match *does* carry a `pool`, it has not won on its own — it
     * has won on behalf of every variant sharing that pool name. All of them
     * are gathered (skipping the retired and the conditioned-out, by the same
     * two tests) and the cursor picks one. Members earlier in the list than the
     * first match cannot be in the gathered set, because the walk would have
     * stopped at them; so "the pool the first match belongs to" and "the pool
     * whose earliest live member is first" are the same pool, and priority
     * between two pools in one node is decided the same way priority between
     * two plain variants is.
     *
     * Rotation rather than a random pick. Random repeats immediately about
     * 1/n of the time, and a companion saying the same line twice running is
     * the exact thing pools exist to stop; a cursor guarantees every line in a
     * pool before any of them comes round again.
     *
     * The cursor is taken modulo the number of live members on every read, so
     * it cannot point past the end. That is what makes it safe for an author to
     * add a line to a pool — or for a condition to drop one out of the live set
     * for this playthrough only — while somebody is mid-save with the cursor
     * sitting at four.
     *
     * Pure: no database, no session, nothing but the arguments. Everything
     * party-scoped it needs is passed in.
     *
     * @param array              $variants the authored variant list
     * @param array              $ctx      WorldState::context() snapshot
     * @param array<int,bool>    $retired  authored index => true if a `once`
     *                                     variant there has already been shown
     * @param array<string,int>  $cursors  pool name => stored cursor
     * @return array{index:?int, pool:?string, next:?int}
     *         `index` is the position in the AUTHORED list, or null if nothing
     *         matched. `pool`/`next` are non-null only when the draw came from
     *         a pool, and `next` is the cursor value to store back.
     */
    public static function chooseVariant(
        array $variants,
        array $ctx,
        array $retired = [],
        array $cursors = []
    ): array {
        foreach ($variants as $i => $variant) {
            if (!is_array($variant) || !empty($retired[$i])) {
                continue;
            }
            if (!Requirements::pass($variant['conditions'] ?? null, $ctx)) {
                continue;
            }

            $pool = $variant['pool'] ?? null;
            if (!is_string($pool) || $pool === '') {
                return ['index' => $i, 'pool' => null, 'next' => null];
            }

            $members = [];
            foreach ($variants as $j => $sibling) {
                if (!is_array($sibling) || ($sibling['pool'] ?? null) !== $pool) {
                    continue;
                }
                if (!empty($retired[$j])
                    || !Requirements::pass($sibling['conditions'] ?? null, $ctx)) {
                    continue;
                }
                $members[] = $j;
            }

            // $i is in $members by construction, so the count is never zero.
            $n = count($members);
            // Modulo twice: a stored cursor is content-shaped data in a text
            // column and a negative one should rotate rather than crash.
            $pos = ((((int) ($cursors[$pool] ?? 0)) % $n) + $n) % $n;
            return [
                'index' => $members[$pos],
                'pool'  => $pool,
                'next'  => ($pos + 1) % $n,
            ];
        }
        return ['index' => null, 'pool' => null, 'next' => null];
    }

    /**
     * The variant the player is answering — the one they were drawn.
     *
     * Deliberately not re-matched: the reply belongs to the scene on screen,
     * and re-deciding is the bug. Nothing is trusted from the request here, so
     * there is nothing to tamper with; the note was written by the render.
     * Falls back to matching when there is no note, which covers a node opened
     * before this existed and a save that predates it.
     *
     * Pools make the note load-bearing rather than merely correct. A pool's
     * cursor moves the instant a line is drawn, so re-matching here would find
     * the NEXT idle line and hand the player's reply to a variant they were
     * never shown — the `camp_kessa_the_mark` bug again, with a different
     * cause. The note is written for every node with more than one variant, and
     * a pool implies more than one, so the fallback below is unreachable for
     * any pool node this engine has drawn. It is left honest anyway: the
     * fallback matches without advancing, so a save from before pools existed
     * gets a plausible variant rather than a spent cursor.
     */
    private function drawnVariant(
        array $variants,
        array $ctx,
        int $partyId,
        string $npcKey,
        string $nodeKey
    ): ?int {
        $drawn = $this->world->get($partyId, self::drawnFlag($npcKey, $nodeKey));
        if ($drawn !== null && isset($variants[(int) $drawn]) && is_array($variants[(int) $drawn])) {
            return (int) $drawn;
        }
        return $this->matchVariant($variants, $ctx, $partyId, $npcKey, $nodeKey);
    }

    /** @var array<string, array> NPC rows are read repeatedly within one turn. */
    private array $npcCache = [];

    private function npc(string $npcKey): array
    {
        if (!isset($this->npcCache[$npcKey])) {
            $stmt = $this->db->prepare('SELECT * FROM npcs WHERE npc_key = ?');
            $stmt->execute([$npcKey]);
            $npc = $stmt->fetch();
            if (!$npc) {
                throw new RuntimeException("No NPC with key '{$npcKey}'.");
            }
            $npc['dialogue'] = json_decode($npc['dialogue_json'] ?? '{}', true) ?: [];
            $this->npcCache[$npcKey] = $npc;
        }
        return $this->npcCache[$npcKey];
    }
}
