<?php
/**
 * A door as a thing you do something to, rather than a thing you walk through.
 *
 * Every verb a party has at a threshold lives here: looking it over for a
 * trap, disarming one it has found, picking or forcing a lock, and putting
 * furniture against it. Travel itself does not — that is location/travel, and
 * a door is only ever an exit to it.
 *
 * The point of gathering them is the barricade. A room whose every door is
 * braced is a room the party can sleep in, and that is the only long rest a
 * delve offers that is not simply given: DelveEngine marks empty rooms
 * camp-safe on the argument that "a long rest in the room you just cleared
 * would make the whole descent free". Bracing the doors is what makes it not
 * free — a check at each one, and a barricade you cannot walk through until
 * you take it down again.
 *
 * WHERE THE STATE LIVES. A barricade belongs to the THRESHOLD, not to either
 * room it separates: a door braced from one side is braced from the other, and
 * `location_exits` holds two rows for one doorway. So the flag is keyed on the
 * pair of locations, smallest id first, and both rows find the same flag.
 */

declare(strict_types=1);

final class DoorEngine
{
    /** How far short of the DC a disarm can fall before it goes off. */
    private const DISARM_SLIP = 5;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The flag one barricade lives under.
     *
     * Sorted, so the two exit rows of one doorway agree on the name. A
     * barricade is a fact about the doorway; which side you were standing on
     * when you built it is not part of it.
     */
    public static function barricadeFlag(int $a, int $b): string
    {
        return 'dg_barr_' . min($a, $b) . '_' . max($a, $b);
    }

    /**
     * Everything the party may do at this door, and why not where they may not.
     *
     * The client draws exactly what comes back and never works an option out
     * for itself — whether a lock can be picked depends on what the party is
     * carrying, and whether a trap can be disarmed depends on what they have
     * found, and neither is a thing a browser should be deciding.
     */
    public function menu(int $characterId, int $exitId): array
    {
        [$char, $exit, $partyId] = $this->doorContext($characterId, $exitId);
        $world = new WorldState($this->db);

        $here = (int) $char['current_location_id'];
        $beyond = (int) $exit['to_location_id'];
        $barred = $world->isSet($partyId, self::barricadeFlag($here, $beyond));

        $conds = $exit['conditions_json'] ? (json_decode((string) $exit['conditions_json'], true) ?: null) : null;
        $ctx = $world->context($partyId, $characterId);
        $locked = $conds !== null && !Requirements::pass($conds, $ctx);
        $forceable = $locked && DelveEngine::forceFlagOf($exit['conditions_json']) !== null;
        $portcullis = str_contains((string) $exit['label'], 'portcullis');

        $trap = $this->trapBeyond($beyond);
        $trapState = $trap === null ? null : $world->get($partyId, DelveEngine::trapFlag((string) $trap['key']));

        $options = [];
        // Open first, always, and the same verb whether or not it is barred —
        // a barricade you have to clear is still the way through.
        $options[] = [
            'act'     => $barred ? 'clear' : 'open',
            'label'   => $barred ? 'Take down the barricade' : 'Open the door',
            'hint'    => $barred
                ? 'Lift the furniture back off it. Quick enough, and quiet'
                : ($locked ? 'It will not give — it is locked' : 'Walk through'),
            'enabled' => $barred || !$locked,
        ];

        if ($trap !== null && $trapState === 'found') {
            $options[] = [
                'act'     => 'disarm',
                'label'   => 'Disarm the trap',
                'hint'    => $trap['name'] . ' — get it wrong badly enough and it goes off',
                'enabled' => true,
            ];
        } elseif ($trapState === null || $trapState === false) {
            $options[] = [
                'act'     => 'inspect',
                'label'   => 'Check for traps',
                'hint'    => 'Look the frame and the latch over before touching them',
                'enabled' => true,
            ];
        }

        if ($forceable && !$portcullis) {
            $options[] = [
                'act'     => 'pick',
                'label'   => 'Pick the lock',
                'hint'    => $this->partyHasTools($partyId)
                    ? 'Tools and patience — harder than a shoulder, and silent'
                    : 'Nobody here is carrying tools for it',
                'enabled' => $this->partyHasTools($partyId),
            ];
        }
        if ($forceable) {
            $options[] = [
                'act'     => 'force',
                'label'   => $portcullis ? 'Lift the portcullis' : 'Force the door',
                'hint'    => 'Shoulder, boot or bar — and the noise it makes',
                'enabled' => true,
            ];
        }

        if (!$barred) {
            $options[] = [
                'act'     => 'barricade',
                'label'   => 'Barricade it',
                'hint'    => 'Brace it shut. Every door in the room, and you can sleep here',
                'enabled' => true,
            ];
        }

        return [
            'ok'        => true,
            'exit_id'   => (int) $exit['id'],
            'label'     => (string) $exit['label'],
            'barred'    => $barred,
            'locked'    => $locked,
            'options'   => $options,
            'barricade' => $this->barricadeReport($partyId, $here),
        ];
    }

    /**
     * Look this door over for a trap.
     *
     * Instant rather than a die ceremony, because it is a look and not a feat —
     * the same shape as searching a room, which rolls server-side and reports
     * what it found. Disarming and barricading are the ones worth stopping the
     * game for.
     */
    public function inspect(int $characterId, int $exitId): array
    {
        [$char, $exit, $partyId] = $this->doorContext($characterId, $exitId);
        $beyond = (int) $exit['to_location_id'];
        $trap = $this->trapBeyond($beyond);

        $look = Rules::skillCheck($char, 'investigation');
        $roll = random_int(1, 20);
        $total = $roll + (int) $look['total'];
        $messages = ["You look the door over. Investigation — {$roll} + " . (int) $look['total'] . " = {$total}."];

        if ($trap === null) {
            $messages[] = 'The frame is honest. Nothing in the latch, nothing under it.';
            return ['ok' => true, 'found' => false, 'messages' => $messages];
        }

        $world = new WorldState($this->db);
        $flag = DelveEngine::trapFlag((string) $trap['key']);
        if ($world->isSet($partyId, $flag)) {
            $messages[] = 'You have already had this one out of it.';
            return ['ok' => true, 'found' => true, 'messages' => $messages];
        }
        if ($total >= (int) $trap['dc']) {
            $world->set($partyId, $flag, 'found');
            $messages[] = $trap['found_text'] ?? "There is something in it: {$trap['name']}.";
            return ['ok' => true, 'found' => true, 'messages' => $messages];
        }
        $messages[] = 'You find nothing. That is not the same as there being nothing.';
        return ['ok' => true, 'found' => false, 'messages' => $messages];
    }

    /** Phase one of a disarm: the die is offered against the trap's own DC. */
    public function disarm(int $characterId, int $exitId): array
    {
        [, $exit, $partyId] = $this->doorContext($characterId, $exitId);
        $beyond = (int) $exit['to_location_id'];
        $trap = $this->trapBeyond($beyond);
        if ($trap === null) {
            throw new InvalidArgumentException('There is nothing in this door to disarm.');
        }
        $world = new WorldState($this->db);
        $state = $world->get($partyId, DelveEngine::trapFlag((string) $trap['key']));
        if ($state !== 'found') {
            throw new InvalidArgumentException('You have not found anything in this door to disarm.');
        }

        return [
            'ok'    => true,
            'check' => (new CheckService($this->db))->request($partyId, [
                'skill'   => 'sleight_of_hand',
                'dc'      => (int) $trap['dc'],
                'context' => ['trap_key' => $trap['key'], 'passage' => $beyond],
            ], $characterId),
        ];
    }

    /**
     * Phase two: the die lands on the trap.
     *
     * Three outcomes rather than two, which is the whole reason a known trap is
     * still a decision. A near miss is a failed attempt and nothing more; miss
     * by five or more and the hands that were working on it set it off.
     */
    public function disarmResolve(int $characterId, string $checkId, array $boosts): array
    {
        $partyId = $this->partyIdFor($characterId);
        $result = (new CheckService($this->db))->resolve($checkId, $boosts);
        if ((int) ($result['party_id'] ?? 0) !== $partyId) {
            throw new InvalidArgumentException('That check is not yours.');
        }
        $trapKey = (string) ($result['context']['trap_key'] ?? '');
        if ($trapKey === '') {
            throw new InvalidArgumentException('That check disarms nothing.');
        }

        $world = new WorldState($this->db);
        $flag = DelveEngine::trapFlag($trapKey);
        $ok = in_array($result['outcome'], ['success', 'critical'], true);
        $sprung = !$ok && (int) ($result['margin'] ?? 0) <= -self::DISARM_SLIP;

        $events = [];
        if ($ok) {
            $world->set($partyId, $flag, 'sprung');   // spent: it can never fire
            $messages = ['The mechanism comes out of the frame in one piece. It will not trouble anyone again.'];
        } elseif ($sprung) {
            $world->set($partyId, $flag, 'sprung');
            $messages = ['Your hand slips, and the door answers.'];
            // And it actually goes off, on the person who had their hands in
            // it — not on a random walker, the way walking into one picks its
            // victim. They were the one working on it.
            //
            // Fired through LocationEngine::fireTrapOn so it is the SAME trap
            // doing the same damage as the one in the floor: same save, same
            // halving, same floor at one hit point, same event for the client
            // to animate. Saying "the door answers" and then doing nothing was
            // the state this was in when it was first written, and prose that
            // promises a consequence the rules do not deliver is worse than no
            // prose at all.
            $trap = $this->trapByLocationKey($trapKey);
            $victim = $this->victimFor($result, $partyId);
            if ($trap !== null && $victim !== null) {
                $events[] = (new LocationEngine($this->db))->fireTrapOn($partyId, $trap, $victim);
            }
        } else {
            $messages = ['It will not come free. Still there, still waiting.'];
        }

        return [
            'ok'       => true,
            'disarmed' => $ok,
            'sprung'   => $sprung,
            'result'   => $result,
            'events'   => $events,
            'messages' => $messages,
        ];
    }

    /** Phase one of a barricade: a check per door, at the floor's own difficulty. */
    public function barricade(int $characterId, int $exitId): array
    {
        [$char, $exit, $partyId] = $this->doorContext($characterId, $exitId);
        $here = (int) $char['current_location_id'];
        $beyond = (int) $exit['to_location_id'];
        $flag = self::barricadeFlag($here, $beyond);

        $world = new WorldState($this->db);
        if ($world->isSet($partyId, $flag)) {
            throw new InvalidArgumentException('That one is already braced.');
        }

        return [
            'ok'    => true,
            'check' => (new CheckService($this->db))->request($partyId, [
                'skill'   => 'athletics',
                'dc'      => 10 + $this->depthOf($here),
                'context' => ['barricade' => $flag, 'exit_id' => (int) $exit['id']],
            ], $characterId),
        ];
    }

    /** Phase two: the furniture goes against the door, or it does not. */
    public function barricadeResolve(int $characterId, string $checkId, array $boosts): array
    {
        $partyId = $this->partyIdFor($characterId);
        $result = (new CheckService($this->db))->resolve($checkId, $boosts);
        if ((int) ($result['party_id'] ?? 0) !== $partyId) {
            throw new InvalidArgumentException('That check is not yours.');
        }
        $flag = (string) ($result['context']['barricade'] ?? '');
        if (!str_starts_with($flag, 'dg_barr_')) {
            throw new InvalidArgumentException('That check braces nothing.');
        }

        $braced = in_array($result['outcome'], ['success', 'critical'], true);
        if ($braced) {
            (new WorldState($this->db))->set($partyId, $flag);
        }

        $char = $this->characterRow($characterId);
        return [
            'ok'        => true,
            'braced'    => $braced,
            'result'    => $result,
            'events'    => [],
            'barricade' => $this->barricadeReport($partyId, (int) $char['current_location_id']),
            'messages'  => [$braced
                ? 'It goes up: whatever was not nailed down, wedged against the frame.'
                : 'Nothing here will hold it. The frame is wrong, or the furniture is.'],
        ];
    }

    /** Take one down again. Free — the work was putting it up. */
    public function clearBarricade(int $characterId, int $exitId): array
    {
        [$char, $exit, $partyId] = $this->doorContext($characterId, $exitId);
        $here = (int) $char['current_location_id'];
        $flag = self::barricadeFlag($here, (int) $exit['to_location_id']);
        (new WorldState($this->db))->clear($partyId, $flag);

        return [
            'ok'        => true,
            'barricade' => $this->barricadeReport($partyId, $here),
            'messages'  => ['You lift it back off the door.'],
        ];
    }

    /**
     * How the room stands: how many ways out, how many are braced.
     *
     * `sealed` is the answer to "can the party sleep here", and it is false for
     * a room with no doors at all — a cave mouth open on three sides is not
     * sealed by having nothing to barricade.
     *
     * @return array{doors:int, braced:int, sealed:bool}
     */
    public function barricadeReport(?int $partyId, int $locationId): array
    {
        if (!$partyId) {
            return ['doors' => 0, 'braced' => 0, 'sealed' => false];
        }
        $stmt = $this->db->prepare(
            'SELECT to_location_id FROM location_exits WHERE from_location_id = ?'
        );
        $stmt->execute([$locationId]);
        $ways = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $world = new WorldState($this->db);
        $braced = 0;
        foreach ($ways as $to) {
            if ($world->isSet($partyId, self::barricadeFlag($locationId, (int) $to))) {
                $braced++;
            }
        }
        $doors = count($ways);
        return [
            'doors'  => $doors,
            'braced' => $braced,
            'sealed' => $doors > 0 && $braced === $doors,
        ];
    }

    /** Is this doorway braced, from either side? */
    public static function isBarred(PDO $db, int $partyId, int $from, int $to): bool
    {
        return (new WorldState($db))->isSet($partyId, self::barricadeFlag($from, $to));
    }

    /**
     * The trap spec behind a location key, for firing after a botched disarm.
     *
     * Looked up again rather than carried through the check: what is stashed
     * with a check is a context the client round-trips, and a damage die is
     * not something to let out of the server and take back.
     */
    private function trapByLocationKey(string $locationKey): ?array
    {
        $stmt = $this->db->prepare('SELECT trap_json FROM locations WHERE location_key = ?');
        $stmt->execute([$locationKey]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || $json === '') {
            return null;
        }
        $trap = json_decode($json, true);
        return is_array($trap) && !empty($trap['name']) ? $trap : null;
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
              WHERE cp.party_id = ? AND c.is_active = 1 AND c.current_hp > 0
              LIMIT 1'
        );
        $stmt->execute([$partyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ---------------------------------------------------------------- plumbing

    /** @return array{0:array, 1:array, 2:int} the character, the exit, the party */
    private function doorContext(int $characterId, int $exitId): array
    {
        $char = $this->characterRow($characterId);
        $partyId = $this->partyIdFor($characterId);
        if (!$partyId) {
            throw new InvalidArgumentException('Doors are party work.');
        }
        $stmt = $this->db->prepare(
            'SELECT id, label, conditions_json, to_location_id
               FROM location_exits WHERE id = ? AND from_location_id = ?'
        );
        $stmt->execute([$exitId, (int) $char['current_location_id']]);
        $exit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exit) {
            throw new InvalidArgumentException('No such way out of here.');
        }
        return [$char, $exit, $partyId];
    }

    private function characterRow(int $characterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, l.location_key
               FROM characters c
               LEFT JOIN locations l ON l.id = c.current_location_id
              WHERE c.id = ?'
        );
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
     * The trap on the far side of this door, if the floor put one there.
     *
     * A trapped door's spec sits on the PASSAGE, not on the door — see
     * DungeonGen, which puts it there because the passage is where the party is
     * standing when it goes off. So looking a door over means looking at what
     * is beyond it.
     *
     * @return array{key:string,name:string,dc:int,found_text?:string}|null
     */
    private function trapBeyond(int $locationId): ?array
    {
        $stmt = $this->db->prepare('SELECT location_key, trap_json FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['trap_json'] === null) {
            return null;
        }
        $trap = json_decode((string) $row['trap_json'], true);
        if (!is_array($trap) || empty($trap['name'])) {
            return null;
        }
        // `key` here is the LOCATION key the trap flag is written under, which
        // is what DelveEngine::trapFlag expects — not the trap's own kind.
        $trap['key'] = (string) $row['location_key'];
        return $trap;
    }

    /** How deep the floor is, from the location key. Zero anywhere else. */
    private function depthOf(int $locationId): int
    {
        $stmt = $this->db->prepare('SELECT location_key FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);
        $key = (string) $stmt->fetchColumn();
        return preg_match('/^_dg_\d+_(\d+)_/', $key, $m) ? (int) $m[1] : 0;
    }

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
