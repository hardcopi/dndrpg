<?php
/**
 * The chest in the room: a thing you open, and everything that stands in the way.
 *
 * A generated floor's treasure rooms get a furnishing — a chest, a strongbox, a
 * barrel, a crate, a sarcophagus or a cabinet — rolled by DungeonGen::furnish()
 * and named in the room's own paragraph. This is the half that lets a party do
 * something about it.
 *
 * IT IS THE LID ON LOOT THAT ALREADY WORKED. The ground loot in a delve room is
 * ordinary `location_items` and has always been takeable by walking in and
 * pressing Take; a furnished room's loot is now behind the lid instead —
 * LocationEngine::itemsAt() hands the party nothing while the flag says shut.
 * So no new inventory path exists, no new kind of reward, and a floor with the
 * feature turned off behaves exactly as every floor did before it. What the
 * furnishing adds is the four questions between a party and the same pile:
 * is it locked, is it rigged, did you look, and who put their hands in it.
 *
 * WHY IT MIRRORS DoorEngine RATHER THAN SHARING IT. A door and a chest ask the
 * same four verbs — inspect, disarm, pick, force — and it is tempting to make
 * one engine of them. They are addressed differently at every level: a door is
 * an EXIT ROW and belongs to a threshold between two places, a furnishing is a
 * fact about ONE place and has no exit at all; a door's lock is a condition on
 * the exit that travel already enforces, a furnishing's lock gates a list of
 * items. The shared part is the check ceremony and LocationEngine::fireTrapOn,
 * and both of those are already shared. Fusing the rest would mean an engine
 * whose every method began by asking which of the two things it was holding.
 *
 * WHERE THE STATE LIVES. WorldState, per party, keyed on the room's location
 * key — the same place a barricade and a passage trap live, for the same
 * reason: what the dungeon IS belongs to `level_json`, what has HAPPENED in it
 * belongs to the party. Two flags per furnishing, `dg_furn_<key>` for the lid
 * and `dg_trap_<key>#furn` for the mechanism, kept apart from the room's own
 * floor trap by that suffix.
 */

declare(strict_types=1);

final class FurnishingEngine
{
    /** How far short of the DC a disarm can fall before it goes off. */
    private const DISARM_SLIP = 5;

    /**
     * What each kind is called, and what a lid on it does.
     *
     * The generator's clause describes the thing in the room's prose; this is
     * the thing addressed as an object — "the strongbox", not "a squat iron
     * strongbox sits against the wall". Two registers, and a menu written in
     * the first one reads like a description of itself.
     *
     * @var array<string, array{name:string,open:string,shut:string}>
     */
    private const KINDS = [
        'chest'       => ['name' => 'the chest',       'open' => 'The lid comes up.',
                          'shut' => 'A chest, lid down.'],
        'strongbox'   => ['name' => 'the strongbox',   'open' => 'The iron lid swings back on its pins.',
                          'shut' => 'A strongbox, banded and shut.'],
        'barrel'      => ['name' => 'the barrel',      'open' => 'The head lifts out in one piece.',
                          'shut' => 'A barrel, headed and standing.'],
        'crate'       => ['name' => 'the crate',       'open' => 'The boards come away from the nails.',
                          'shut' => 'A crate, lid nailed down.'],
        'sarcophagus' => ['name' => 'the sarcophagus', 'open' => 'The lid grinds aside a hand\'s width, then all the way.',
                          'shut' => 'A sarcophagus, lid seated.'],
        'cabinet'     => ['name' => 'the cabinet',     'open' => 'Both doors come open together.',
                          'shut' => 'A cabinet, doors closed.'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** What this kind is called in a sentence addressed to it. */
    public static function nameOf(string $kind): string
    {
        return self::KINDS[$kind]['name'] ?? 'it';
    }

    /**
     * The block the location payload carries, or null where there is nothing.
     *
     * Shipped by LocationEngine::getState() so the room panel can draw the
     * thing without a second request, and read by the two maps for the glyph.
     * `locked` and `trapped` are NOT in it: whether a lid is fastened is
     * something you find out by trying it, and whether it is rigged is what
     * looking it over is for. A payload that told the client both would be the
     * room answering the questions it is asking.
     */
    public function report(int $characterId, ?int $partyId, int $locationId, ?string $locationKey = null): ?array
    {
        $found = (new DelveEngine($this->db))->furnishingAt($partyId, $locationId, $locationKey);
        if ($found === null) {
            return null;
        }
        $kind = (string) $found['furnishing']['kind'];
        $world = new WorldState($this->db);
        $open = $world->isSet((int) $partyId, DelveEngine::furnishingFlag($found['location_key']));
        $trapState = $world->get((int) $partyId, DelveEngine::furnishingTrapFlag($found['location_key']));

        return [
            'kind'     => $kind,
            'name'     => self::nameOf($kind),
            'open'     => $open,
            'blurb'    => $open ? 'Open, and emptied of nothing yet.' : (self::KINDS[$kind]['shut'] ?? 'Shut.'),
            // Only what the party has actually learned — 'found' or 'sprung',
            // and nothing at all until they have looked. An unset flag is not
            // "there is no trap"; it is "nobody has looked", which is why the
            // menu goes on offering another look.
            'trap'     => is_string($trapState) ? $trapState : null,
        ];
    }

    /**
     * Everything the party may do to it, and why not where they may not.
     *
     * Same contract as DoorEngine::menu — the client draws exactly what comes
     * back. Whether a lock can be picked depends on what the party is carrying
     * and whether a trap can be disarmed depends on what they have found, and
     * a browser knows neither.
     */
    public function menu(int $characterId): array
    {
        [, $partyId, $found] = $this->context($characterId);
        $furn = $found['furnishing'];
        $kind = (string) $furn['kind'];
        $key = $found['location_key'];
        $world = new WorldState($this->db);

        $open = $world->isSet($partyId, DelveEngine::furnishingFlag($key));
        $locked = !$open && !empty($furn['locked']);
        $trapState = $world->get($partyId, DelveEngine::furnishingTrapFlag($key));
        $armed = !empty($furn['trapped']) && $trapState !== 'sprung';

        $options = [];
        // Open first, always, and offered even when it is locked and even when
        // the party knows it is rigged. Both of those are the player's call to
        // make: a locked lid says so when you try it, and a known trap you open
        // anyway is a decision somebody is entitled to take.
        $options[] = [
            'act'     => 'open',
            'label'   => $open ? 'Look inside' : 'Open ' . self::nameOf($kind),
            'hint'    => $open
                ? 'It is already open'
                : ($locked ? 'It will not give — it is fastened' : 'Lift the lid and see'),
            'enabled' => !$locked,
        ];

        if ($armed && $trapState === 'found') {
            $options[] = [
                'act'     => 'disarm',
                'label'   => 'Disarm the trap',
                'hint'    => ($furn['trap']['name'] ?? 'A mechanism')
                             . ' — get it wrong badly enough and it goes off',
                'enabled' => true,
            ];
        } elseif (!$open && $trapState !== 'sprung') {
            $options[] = [
                'act'     => 'inspect',
                'label'   => 'Check it for traps',
                'hint'    => 'Go over the lid, the hinges and the seam before touching them',
                'enabled' => true,
            ];
        }

        if ($locked) {
            $tools = $this->partyHasTools($partyId);
            $options[] = [
                'act'     => 'pick',
                'label'   => 'Pick the lock',
                'hint'    => $tools
                    ? 'Tools and patience — harder than a bar, and it leaves the lid whole'
                    : 'Nobody here is carrying tools for it',
                'enabled' => $tools,
            ];
            $options[] = [
                'act'     => 'force',
                'label'   => 'Force it',
                'hint'    => 'A bar under the lid and your weight on the end of it',
                'enabled' => true,
            ];
        }

        return [
            'ok'      => true,
            'kind'    => $kind,
            'label'   => ucfirst(self::nameOf($kind)),
            'open'    => $open,
            'locked'  => $locked,
            'options' => $options,
        ];
    }

    /**
     * Look it over for a mechanism.
     *
     * Instant rather than a die ceremony, exactly as DoorEngine::inspect is and
     * for the same reason: it is a look, not a feat. Investigation, against the
     * trap's own DC.
     */
    public function inspect(int $characterId): array
    {
        [$char, $partyId, $found] = $this->context($characterId);
        $furn = $found['furnishing'];
        $key = $found['location_key'];

        $look = Rules::skillCheck($char, 'investigation');
        $roll = random_int(1, 20);
        $total = $roll + (int) $look['total'];
        $messages = ['You go over it. Investigation — ' . $roll . ' + '
                     . (int) $look['total'] . ' = ' . $total . '.'];

        if (empty($furn['trapped']) || empty($furn['trap'])) {
            $messages[] = 'The hinges are honest and the seam is clean. Nothing in it but whatever is in it.';
            return ['ok' => true, 'found' => false, 'messages' => $messages];
        }

        $world = new WorldState($this->db);
        $flag = DelveEngine::furnishingTrapFlag($key);
        if ($world->isSet($partyId, $flag)) {
            $messages[] = 'You have already had this one out of it.';
            return ['ok' => true, 'found' => true, 'messages' => $messages];
        }
        if ($total >= (int) $furn['trap']['dc']) {
            $world->set($partyId, $flag, 'found');
            $messages[] = (string) ($furn['trap']['found_text']
                ?? 'There is something in it: ' . $furn['trap']['name'] . '.');
            return ['ok' => true, 'found' => true, 'messages' => $messages];
        }
        $messages[] = 'You find nothing. That is not the same as there being nothing.';
        return ['ok' => true, 'found' => false, 'messages' => $messages];
    }

    /**
     * Lift the lid.
     *
     * No die of its own — a lid that is not fastened opens. What can happen
     * here is the trap, on whoever opened it, and that is the whole point of
     * the two verbs above: a party that looked and disarmed opens it for
     * nothing, and a party in a hurry pays for the hurry.
     */
    public function open(int $characterId): array
    {
        [$char, $partyId, $found] = $this->context($characterId);
        $furn = $found['furnishing'];
        $key = $found['location_key'];
        $world = new WorldState($this->db);
        $flag = DelveEngine::furnishingFlag($key);

        if ($world->isSet($partyId, $flag)) {
            return ['ok' => true, 'opened' => false, 'sprung' => false, 'events' => [],
                    'messages' => ['It is already open.']];
        }
        if (!empty($furn['locked'])) {
            throw new InvalidArgumentException('It is fastened. It will have to be picked or forced.');
        }

        return $this->lift($partyId, $char, $found, self::KINDS[(string) $furn['kind']]['open'] ?? 'It comes open.');
    }

    /** Phase one of forcing it: Athletics, against the lid's own difficulty. */
    public function force(int $characterId): array
    {
        [, $partyId, $found] = $this->context($characterId);
        $this->assertShutAndLocked($partyId, $found);

        return [
            'ok'    => true,
            'check' => (new CheckService($this->db))->request($partyId, [
                'skill'   => 'athletics',
                'dc'      => (int) $found['furnishing']['dc'],
                'context' => ['furnishing' => $found['location_key']],
            ], $characterId),
        ];
    }

    /**
     * Phase one of picking it: Sleight of Hand, and two harder than forcing.
     *
     * The same relation the doors have — a bar is cruder and easier than a pick
     * — and for the same reason it is worth having both: forcing is loud and
     * available to anyone, picking is quiet and needs the tools.
     */
    public function pick(int $characterId): array
    {
        [, $partyId, $found] = $this->context($characterId);
        $this->assertShutAndLocked($partyId, $found);
        if (!$this->partyHasTools($partyId)) {
            throw new InvalidArgumentException('Nobody in the party is carrying thieves\' tools.');
        }

        return [
            'ok'    => true,
            'check' => (new CheckService($this->db))->request($partyId, [
                'skill'   => 'sleight_of_hand',
                'dc'      => (int) $found['furnishing']['dc'] + 2,
                'context' => ['furnishing' => $found['location_key']],
            ], $characterId),
        ];
    }

    /**
     * Phase two of either: the lid gives, or it does not.
     *
     * One resolver for both verbs, because what happens after the die is the
     * same in both — the lid comes up, and whatever is under it goes off. The
     * check itself already carries which skill was rolled and at what DC.
     */
    public function openResolve(int $characterId, string $checkId, array $boosts): array
    {
        [$char, $partyId, $found] = $this->context($characterId);
        $result = (new CheckService($this->db))->resolve($checkId, $boosts);
        if ((int) ($result['party_id'] ?? 0) !== $partyId) {
            throw new InvalidArgumentException('That check is not yours.');
        }
        if ((string) ($result['context']['furnishing'] ?? '') !== $found['location_key']) {
            throw new InvalidArgumentException('That check opens nothing here.');
        }
        if (!in_array($result['outcome'], ['success', 'critical'], true)) {
            return ['ok' => true, 'opened' => false, 'sprung' => false, 'result' => $result,
                    'events' => [], 'messages' => ['It holds. Whatever is in it stays in it.']];
        }

        $out = $this->lift($partyId, $char, $found, 'It gives, all at once.');
        $out['result'] = $result;
        return $out;
    }

    /** Phase one of a disarm: the die is offered against the trap's own DC. */
    public function disarm(int $characterId): array
    {
        [, $partyId, $found] = $this->context($characterId);
        $furn = $found['furnishing'];
        if (empty($furn['trapped']) || empty($furn['trap'])) {
            throw new InvalidArgumentException('There is nothing in this one to disarm.');
        }
        $world = new WorldState($this->db);
        if ($world->get($partyId, DelveEngine::furnishingTrapFlag($found['location_key'])) !== 'found') {
            throw new InvalidArgumentException('You have not found anything in it to disarm.');
        }

        return [
            'ok'    => true,
            'check' => (new CheckService($this->db))->request($partyId, [
                'skill'   => 'sleight_of_hand',
                'dc'      => (int) $furn['trap']['dc'],
                'context' => ['furnishing' => $found['location_key'], 'disarm' => true],
            ], $characterId),
        ];
    }

    /**
     * Phase two: the die lands on the mechanism.
     *
     * Three outcomes rather than two, the same as a door's — a near miss is a
     * failed attempt and nothing more; miss by five or more and the hands that
     * were working on it set it off.
     */
    public function disarmResolve(int $characterId, string $checkId, array $boosts): array
    {
        [, $partyId, $found] = $this->context($characterId);
        $result = (new CheckService($this->db))->resolve($checkId, $boosts);
        if ((int) ($result['party_id'] ?? 0) !== $partyId) {
            throw new InvalidArgumentException('That check is not yours.');
        }
        if ((string) ($result['context']['furnishing'] ?? '') !== $found['location_key']) {
            throw new InvalidArgumentException('That check disarms nothing here.');
        }

        $world = new WorldState($this->db);
        $flag = DelveEngine::furnishingTrapFlag($found['location_key']);
        $ok = in_array($result['outcome'], ['success', 'critical'], true);
        $sprung = !$ok && (int) ($result['margin'] ?? 0) <= -self::DISARM_SLIP;

        $events = [];
        if ($ok) {
            $world->set($partyId, $flag, 'sprung');   // spent: it can never fire
            $messages = ['The mechanism comes out from under the lid in one piece. It will not trouble anyone again.'];
        } elseif ($sprung) {
            $world->set($partyId, $flag, 'sprung');
            $messages = ['Your hand slips, and it answers.'];
            $victim = $this->victimFor($result, $partyId);
            $trap = $found['furnishing']['trap'] ?? null;
            if (is_array($trap) && $victim !== null) {
                $events[] = (new LocationEngine($this->db))->fireTrapOn($partyId, $trap, $victim);
            }
        } else {
            $messages = ['It will not come free. Still there, still waiting.'];
        }

        return ['ok' => true, 'disarmed' => $ok, 'sprung' => $sprung, 'result' => $result,
                'events' => $events, 'messages' => $messages];
    }

    // =======================================================================
    // Below the verbs
    // =======================================================================

    /**
     * The lid comes up, and the mechanism gets its one chance.
     *
     * Shared by opening an unfastened one and by winning the die against a
     * fastened one, because those differ only in what it took to get here. The
     * trap fires on whoever did it — the same rule a door's disarm uses, and
     * for the same reason: they were the one with their hands in it.
     */
    private function lift(int $partyId, array $char, array $found, string $prose): array
    {
        $furn = $found['furnishing'];
        $world = new WorldState($this->db);
        $world->set($partyId, DelveEngine::furnishingFlag($found['location_key']), 'open');

        $messages = [$prose];
        $events = [];
        $sprung = false;
        $trapFlag = DelveEngine::furnishingTrapFlag($found['location_key']);
        $trap = $furn['trap'] ?? null;

        if (!empty($furn['trapped']) && is_array($trap)
            && $world->get($partyId, $trapFlag) !== 'sprung') {
            $sprung = true;
            $world->set($partyId, $trapFlag, 'sprung');
            $events[] = (new LocationEngine($this->db))->fireTrapOn($partyId, $trap, $char);
        }

        // Deliberately says nothing about what is inside. The room panel
        // re-reads the location the moment this returns and the ground loot is
        // no longer gated, so the list IS the answer — and a sentence here
        // naming the contents would be a second one that could disagree.
        return ['ok' => true, 'opened' => true, 'sprung' => $sprung,
                'events' => $events, 'messages' => $messages];
    }

    /** @return array{0:array,1:int,2:array} the character, the party, the furnishing */
    private function context(int $characterId): array
    {
        $char = $this->characterRow($characterId);
        $partyId = $this->partyIdFor($characterId);
        if (!$partyId) {
            throw new InvalidArgumentException('Opening things is party work.');
        }
        $found = (new DelveEngine($this->db))
            ->furnishingAt($partyId, (int) $char['current_location_id']);
        if ($found === null) {
            throw new InvalidArgumentException('There is nothing here to open.');
        }
        return [$char, $partyId, $found];
    }

    private function assertShutAndLocked(int $partyId, array $found): void
    {
        $world = new WorldState($this->db);
        if ($world->isSet($partyId, DelveEngine::furnishingFlag($found['location_key']))) {
            throw new InvalidArgumentException('It is already open.');
        }
        if (empty($found['furnishing']['locked'])) {
            throw new InvalidArgumentException('It is not fastened. Just open it.');
        }
    }

    private function characterRow(int $characterId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('No such character.');
        }
        return $row;
    }

    private function partyIdFor(int $characterId): int
    {
        $stmt = $this->db->prepare('SELECT party_id FROM character_party WHERE character_id = ?');
        $stmt->execute([$characterId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Whose hands were on it: the character the check was made by.
     *
     * Falls back to any walker if that character cannot be read, so a trap
     * never silently fails to go off — better it hits somebody than nobody.
     */
    private function victimFor(array $result, int $partyId): ?array
    {
        $id = (int) ($result['character']['id'] ?? 0);
        if ($id > 0) {
            $stmt = $this->db->prepare('SELECT * FROM characters WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        $stmt = $this->db->prepare(
            'SELECT c.* FROM characters c
               JOIN character_party cp ON cp.character_id = c.id
              WHERE cp.party_id = ? AND c.current_hp > 0 AND c.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$partyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Party-wide, exactly as a door's lock asks it — see LocationEngine. */
    private function partyHasTools(int $partyId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
               FROM character_inventory ci
               JOIN items i ON i.id = ci.item_id
               JOIN character_party cp ON cp.character_id = ci.character_id
               JOIN characters c ON c.id = ci.character_id
              WHERE cp.party_id = ? AND i.item_key = ?
                AND ci.quantity > 0 AND c.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$partyId, 'thieves_tools']);
        return (bool) $stmt->fetchColumn();
    }
}
