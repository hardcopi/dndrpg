<?php
/**
 * Character creation using 5e SRD rules (point buy, standard array, random).
 */

declare(strict_types=1);

class CharacterGenerator
{
    public const STANDARD_ARRAY = [15, 14, 13, 12, 10, 8];
    public const POINT_BUY_COST = [
        8 => 0, 9 => 1, 10 => 2, 11 => 3, 12 => 4,
        13 => 5, 14 => 7, 15 => 9,
    ];
    public const POINT_BUY_BUDGET = 27;

    public const ABILITIES = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];

    private PDO $db;

    /** Explicit owner for anything this creates; falls back to the session. */
    private ?int $ownerId = null;

    public function __construct(PDO $db, ?int $ownerId = null)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /**
     * Who owns a character or party this creates.
     *
     * Defaults to whoever is signed in, but can be set outright — the tests
     * create characters for a specific account with no session at all, and the
     * companion recruitment path already knows whose party it is joining.
     *
     * Returns null only when there is no session and none was given, which
     * leaves `user_id` NULL. That is a row nobody owns and therefore nobody can
     * see: the ownership filters treat it as inaccessible rather than as
     * public, which is the safe direction for a value that should not occur.
     */
    public function ownerId(): ?int
    {
        if ($this->ownerId !== null) {
            return $this->ownerId;
        }
        return function_exists('session_user_id') ? session_user_id() : null;
    }

    public function setOwner(?int $userId): void
    {
        $this->ownerId = $userId;
    }

    public function listRaces(): array
    {
        $stmt = $this->db->query('SELECT * FROM races ORDER BY name, subrace');
        return $stmt->fetchAll();
    }

    /**
     * What each class walks out of creation carrying.
     *
     * Keys, never ids — these rows come from content/items/ and the numbers the
     * seed happened to assign are not stable. A missing key is skipped when the
     * kit is granted rather than being fatal: an incomplete kit is a recoverable
     * annoyance, a character that cannot be created is not.
     */
    public const STARTING_KITS = [
        'Barbarian' => [['greataxe', 1], ['potion_healing', 1]],
        'Bard'      => [['shortsword', 1], ['leather_armor', 1], ['potion_healing', 1]],
        'Cleric'    => [['mace', 1], ['chain_shirt', 1], ['holy_symbol', 1], ['potion_healing', 2]],
        'Druid'     => [['mace', 1], ['leather_armor', 1], ['potion_healing', 1]],
        'Fighter'   => [['longsword', 1], ['chain_shirt', 1], ['potion_healing', 1]],
        'Monk'      => [['shortsword', 1], ['potion_healing', 1]],
        'Paladin'   => [['longsword', 1], ['chain_shirt', 1], ['potion_healing', 1]],
        'Ranger'    => [['shortbow', 1], ['leather_armor', 1], ['potion_healing', 1]],
        'Rogue'     => [['shortsword', 1], ['leather_armor', 1], ['thieves_tools', 1], ['potion_healing', 1]],
        'Sorcerer'  => [['shortsword', 1], ['potion_healing', 1]],
        'Warlock'   => [['shortsword', 1], ['leather_armor', 1], ['potion_healing', 1]],
        'Wizard'    => [['shortsword', 1], ['potion_healing', 1]],
    ];

    public static function kitFor(string $className): array
    {
        return self::STARTING_KITS[$className]
            ?? [['shortsword', 1], ['potion_healing', 1]];
    }

    /**
     * Classes, each carrying its starting kit and the AC that kit produces.
     *
     * The creator's review step needs to show the armour class the character will
     * actually have, and creation equips the kit's armour and recalculates — so a
     * review printing plain 10 + Dex would be contradicted by the character sheet
     * one click later. Rather than transcribe the armour table into JavaScript,
     * the armour's own numbers are served and `starting_ac` is computed here by
     * the same arithmetic recalculateAC uses.
     */
    public function listClasses(): array
    {
        $classes = $this->db->query('SELECT * FROM classes ORDER BY name')->fetchAll();

        // The same columns recalculateAC reads, so the review cannot disagree
        // with the character sheet: `armor_bonus` is the base, and the Dexterity
        // rule lives in the item's `properties` JSON rather than in a column.
        $look = $this->db->prepare(
            'SELECT name, item_type, armor_type, armor_bonus, properties
               FROM items WHERE item_key = ? LIMIT 1'
        );

        foreach ($classes as &$c) {
            $gear = [];
            $c['starting_armor'] = null;
            foreach (self::kitFor((string) $c['name']) as [$key, $qty]) {
                $look->execute([$key]);
                $row = $look->fetch();
                if (!$row) {
                    continue;
                }
                $gear[] = $qty > 1 ? "{$row['name']} ×{$qty}" : $row['name'];

                if ($row['item_type'] === 'armor' && $row['armor_type'] !== 'shield') {
                    $props = json_decode($row['properties'] ?? '{}', true) ?: [];
                    $c['starting_armor'] = [
                        'name' => $row['name'],
                        'armor_bonus' => (int) $row['armor_bonus'],
                        'dex_bonus' => !empty($props['dex_bonus']),
                        'dex_bonus_max' => $props['dex_bonus_max'] ?? null,
                    ];
                }
            }
            $c['starting_gear'] = $gear;
            $c['shield_bonus'] = 0;
        }
        unset($c);

        return $classes;
    }

    /**
     * The AC this class will have at creation, armour included.
     *
     * Mirrors recalculateAC for the one case creation produces: body armour from
     * the kit, no shield. Unarmoured defence is what Barbarians and Monks have
     * *instead of* armour, and neither of those kits contains any, so the two
     * branches cannot both apply.
     */
    public static function startingAc(array $class, array $abilities): int
    {
        $armour = $class['starting_armor'] ?? null;
        if (!$armour) {
            // A subclass that arrives at 1st is already on the character the
            // moment they exist, and Draconic Resilience changes this number.
            $subclass = ((int) ($class['subclass_level'] ?? 99) <= 1)
                ? ($class['subclass_name'] ?? null)
                : null;
            return self::unarmoredAc((string) $class['name'], $abilities, $subclass);
        }
        $ac = (int) $armour['armor_bonus'];
        if (!empty($armour['dex_bonus'])) {
            $dexMod = ability_mod((int) ($abilities['dexterity'] ?? 10));
            $cap = $armour['dex_bonus_max'] ?? 99;
            $ac += min($dexMod, (int) $cap);
        }
        return $ac;
    }

    /**
     * Create a character and optional party membership.
     *
     * @param array $data name, race, subrace, class, method, abilities, background, alignment
     */
    public function create(array $data, ?int $partyId = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            throw new InvalidArgumentException('Character name is required (max 50 characters).');
        }
        $this->assertNameFree($name);

        $raceName = (string) ($data['race'] ?? '');
        $subrace  = $data['subrace'] ?? null;
        if ($subrace === '') {
            $subrace = null;
        }

        $className = (string) ($data['class'] ?? '');
        $method    = (string) ($data['method'] ?? 'standard');

        $race = $this->findRace($raceName, $subrace);
        if (!$race) {
            throw new InvalidArgumentException('Invalid race/subrace.');
        }

        $class = $this->findClass($className);
        if (!$class) {
            throw new InvalidArgumentException('Invalid class.');
        }

        // The gift step. Validated up here so a bad key refuses the creation
        // before anything is written; granted at the bottom through
        // LevelUpService::grantFeat — the one door — so Tough's hit points and
        // Alert's initiative are paid the same way they are at a level-up.
        // Only the origin shelf: those are 5.2's first-level grants, carry no
        // prerequisites by design, and were until now the companions-only
        // asymmetry the Feats header documents.
        $featKey = trim((string) ($data['feat'] ?? ''));
        if ($featKey !== '') {
            $feat = Feats::get($featKey);
            if ($feat === null || ($feat['category'] ?? '') !== 'origin') {
                throw new InvalidArgumentException('Only an Origin feat may be taken at creation.');
            }
        }

        $base = $this->resolveAbilities(
            $method,
            $data['abilities'] ?? [],
            // The dice the server already threw for this creation, if any; see
            // resolveAbilities for why the submitted scores are checked against
            // them rather than trusted or re-rolled.
            $data['rolled'] ?? []
        );
        $final = self::racialAbilities($base, $race);

        // Settled before the hit points because Draconic Resilience changes
        // them, and the Sorcerer is one of the classes whose subclass arrives
        // at 1st.
        $subclassAtCreation = ((int) $class['subclass_level'] <= 1) ? $class['subclass_name'] : null;

        $maxHp = self::hitPointsAtLevel(
            (int) $class['hit_die'],
            ability_mod($final['constitution']),
            1,
            $race['subrace'],
            $subclassAtCreation
        );
        $ac = self::unarmoredAc((string) $class['name'], $final, $subclassAtCreation);

        $speed = (int) $race['speed'];

        // Which game this character is for, and where that puts them. Joining a
        // party already in play settles both at once: its module was fixed when
        // it was made, and its members stand together.
        $module = $this->resolveModule($data, $partyId);
        $startLocationId = $this->startingLocationId($module, $partyId);

        $stmt = $this->db->prepare(
            'INSERT INTO characters
            (user_id, name, race, subrace, class, subclass, level, experience,
             strength, dexterity, constitution, intelligence, wisdom, charisma,
             max_hp, current_hp, armor_class, speed, proficiency_bonus, gold,
             background, alignment, combat_rank, origin_tags,
             current_location_id)
             VALUES
            (:user_id, :name, :race, :subrace, :class, :subclass, 1, 0,
             :str, :dex, :con, :int, :wis, :cha,
             :max_hp, :cur_hp, :ac, :speed, 2, :gold,
             :background, :alignment, :rank, :origins,
             :location_id)'
        );

        $gold = 50 + random_int(0, 30);

        $stmt->execute([
            // Whoever is signed in owns what they make. This was the constant 1
            // — the single seeded account — which is why every character in the
            // database belonged to the same user and why the character list
            // could show all of them to anybody.
            ':user_id'     => $this->ownerId(),
            ':name'        => $name,
            ':race'        => $race['name'],
            ':subrace'     => $race['subrace'],
            ':class'       => $class['name'],
            ':subclass'    => $subclassAtCreation,
            ':str'         => $final['strength'],
            ':dex'         => $final['dexterity'],
            ':con'         => $final['constitution'],
            ':int'         => $final['intelligence'],
            ':wis'         => $final['wisdom'],
            ':cha'         => $final['charisma'],
            ':max_hp'      => $maxHp,
            ':cur_hp'      => $maxHp,
            ':ac'          => $ac,
            ':speed'       => $speed,
            ':gold'        => $gold,
            ':background'  => $data['background'] ?? 'Folk Hero',
            ':alignment'   => $data['alignment'] ?? 'Neutral',
            // Where they stand when a fight starts. A caster left on the schema
            // default of `front` opens every combat in the rank that exists to
            // be hit.
            ':rank'        => Rules::defaultCombatRank((string) $class['name']),
            // Stored rather than derived on every read. WorldState::originTags()
            // recomputes it when absent, which is why dialogue worked without
            // this, but that meant the column the schema documents as
            // denormalised was never actually written.
            ':origins'     => implode(',', WorldState::originTags([
                'race'       => $race['name'],
                'subrace'    => $race['subrace'],
                'class'      => $class['name'],
                'background' => $data['background'] ?? 'Folk Hero',
            ])),
            ':location_id' => $startLocationId,
        ]);

        $characterId = (int) $this->db->lastInsertId();

        // The avatar is picked during creation, so it is set here rather than
        // left to a second trip through the character sheet. An empty choice
        // stays NULL and falls back to the class default, which is what a
        // character made before the picker existed already does.
        $chosen = trim((string) ($data['sprite_key'] ?? ''));
        if ($chosen !== '') {
            $this->setAvatar($characterId, $chosen);
        }

        // Starting gear from SRD-flavored kits
        $this->grantStartingGear($characterId, $class['name']);

        // Spells for casters
        $this->grantStartingSpells($characterId, $class['name']);

        // The origin feat, validated above. After the gear so recalculateAC()
        // inside grantFeat sees whatever the kit equipped.
        if ($featKey !== '') {
            (new LevelUpService($this->db))->grantFeat($characterId, $featKey);
        }

        if ($partyId === null) {
            $p = $this->db->prepare(
                'INSERT INTO parties (user_id, module_id, name, leader_character_id)
                 VALUES (:u, :m, :n, :c)'
            );
            $p->execute([
                ':u' => $this->ownerId(),
                ':m' => (int) $module['id'],
                ':n' => $name . "'s Party",
                ':c' => $characterId,
            ]);
            $partyId = (int) $this->db->lastInsertId();
        }

        $slotStmt = $this->db->prepare('SELECT COALESCE(MAX(slot),0)+1 AS s FROM character_party WHERE party_id = ?');
        $slotStmt->execute([$partyId]);
        $slot = (int) $slotStmt->fetchColumn();

        $cp = $this->db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,?)');
        $cp->execute([$partyId, $characterId, $slot]);

        // Stand them where the party is standing. startingLocationId() has
        // already resolved that — for a new party it is the module's opening
        // room, for an existing one it is wherever that party got to.
        $this->db->prepare(
            'UPDATE characters SET current_location_id=? WHERE id=?'
        )->execute([$startLocationId, $characterId]);

        return $this->getCharacter($characterId) + ['party_id' => $partyId];
    }

    /**
     * Which module a new character is being made for.
     *
     * Joining an existing party is not a choice: a party's module is fixed when
     * it is created, because a party split across two games has no coherent
     * location, quest log or job board. Only a character starting a fresh party
     * gets to pick, and an unrecognised pick is refused rather than quietly
     * redirected — a player who asked for one game and got another would only
     * find out several scenes in.
     */
    /**
     * Where a party wakes up after a defeat: its own module's opening room.
     *
     * Public because CombatEngine needs it, and a second copy of "which game is
     * this party playing, and where does that game begin" is exactly what this
     * class already exists to stop. The defeat path had `flagon_common_room`
     * written into it as a literal, so a party beaten anywhere in the Old City
     * woke up in Rivermark — a region their module does not own, cannot reach
     * by any exit, and from which the whole of the other game is walkable. The
     * module boundary held everywhere a player could walk and gave way the
     * first time somebody lost a fight.
     *
     * `null` only if the content is broken enough to have no locations at all,
     * which the caller should treat as "leave them where they fell" rather
     * than move them somewhere wrong.
     *
     * @return array{id: int, name: string}|null
     */
    public function havenFor(int $partyId): ?array
    {
        try {
            $module = $this->resolveModule([], $partyId);
            // No party id passed on purpose: startingLocationId() reads it as
            // "stand them where the party is standing", and where the party is
            // standing is the fight they just lost.
            $id = $this->startingLocationId($module, null);
        } catch (Throwable $e) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, name FROM locations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    private function resolveModule(array $data, ?int $partyId): array
    {
        if ($partyId !== null) {
            $stmt = $this->db->prepare(
                'SELECT m.* FROM parties p
                   INNER JOIN modules m ON m.id = p.module_id
                  WHERE p.id = ?'
            );
            $stmt->execute([$partyId]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
            // A party from before modules existed, or one whose module was
            // retired. Fall through to the default rather than refuse to add a
            // character to a save that is otherwise perfectly playable.
        }

        // With the adventures hidden there is one world and nobody chooses it.
        //
        // AFTER the party and before the request, and the order is the whole
        // point. A party that is already inside a module lives there — this is
        // also how a defeated party finds its way to the right haven, and put
        // ahead of the party lookup it marched everybody who lost a fight in
        // the Undervault out to the Proving Yard. A request that NAMES a module
        // is a different matter: a stale link or an old bookmark must not put a
        // new character into a game nobody can see, so it is overruled.
        if (!ADVENTURES_ENABLED) {
            $stmt = $this->db->prepare('SELECT * FROM modules WHERE module_key = ?');
            $stmt->execute([FREE_PLAY_MODULE]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException(
                    'The ' . FREE_PLAY_MODULE . ' module is missing. '
                    . 'Run sql/migrations/2026-08-21-free-play.sql.'
                );
            }
            return $row;
        }

        $key = trim((string) ($data['module'] ?? ''));
        if ($key !== '') {
            $stmt = $this->db->prepare(
                'SELECT * FROM modules WHERE module_key = ? AND is_active = 1'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new InvalidArgumentException('Unknown or unavailable module.');
            }
            return $row;
        }

        $row = $this->db->query(
            'SELECT * FROM modules WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1'
        )->fetch();
        if (!$row) {
            throw new RuntimeException('No modules exist; load the content first.');
        }
        return $row;
    }

    /**
     * Where a new character wakes up.
     *
     * Resolved by key so content can move the opening room without a code
     * change. Both fallbacks stay inside the module on purpose: landing a
     * character in another game's world is the one failure this whole scoping
     * exercise exists to prevent, so "any location at all" is not an acceptable
     * last resort even when the content is broken.
     */
    private function startingLocationId(array $module, ?int $partyId): int
    {
        // Somebody joining a party in progress starts where that party is, not
        // at the module's front door — the alternative strands them regions
        // away from the people they just joined, which is what used to happen.
        if ($partyId !== null) {
            $stmt = $this->db->prepare(
                'SELECT c.current_location_id
                   FROM character_party cp
                   INNER JOIN characters c ON c.id = cp.character_id
                  WHERE cp.party_id = ? AND c.current_location_id IS NOT NULL
                  ORDER BY cp.slot
                  LIMIT 1'
            );
            $stmt->execute([$partyId]);
            $id = $stmt->fetchColumn();
            if ($id !== false && $id !== null) {
                return (int) $id;
            }
        }

        $stmt = $this->db->prepare('SELECT id FROM locations WHERE location_key = ?');
        $stmt->execute([(string) $module['start_location_key']]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            // The module names an opening room the content no longer carries.
            // Anywhere in its own world beats anywhere at all.
            $stmt = $this->db->prepare(
                'SELECT l.id FROM locations l
                   INNER JOIN regions r ON r.id = l.region_id
                  WHERE r.module_id = ?
                  ORDER BY r.sort_order, l.sort_order, l.id
                  LIMIT 1'
            );
            $stmt->execute([(int) $module['id']]);
            $id = $stmt->fetchColumn();
        }
        if ($id === false) {
            throw new RuntimeException(
                'Module ' . $module['module_key'] . ' has no locations; load the content first.'
            );
        }
        return (int) $id;
    }

    /**
     * Refuse a name somebody already has.
     *
     * Checked here for a message a player can act on; the UNIQUE index on
     * `characters.name` is what actually guarantees it, since two submissions
     * racing each other would both pass this and only one can pass that.
     *
     * Recruited companions are ordinary `characters` rows, so this also stops
     * a player from naming themselves after one and then being unable to
     * recruit them — better to collide at creation, where there is a form to
     * correct, than at the moment somebody agrees to join.
     */
    private function assertNameFree(string $name, ?int $exceptId = null): void
    {
        $why = $this->nameTaken($name, $exceptId);
        if ($why !== null) {
            throw new InvalidArgumentException($why);
        }
    }

    /**
     * Why this name cannot be used, or null if it can.
     *
     * The same rule as assertNameFree without the exception, so the creator can
     * ask before it submits. The wizard needs this: the name is entered on the
     * first step and the character is created on the last, and finding out four
     * steps later that the name was taken — after choosing a face, a haircut and
     * six ability scores — is the exact frustration a wizard introduces if
     * nobody checks early.
     */
    public function nameTaken(string $name, ?int $exceptId = null): ?string
    {
        // Only living characters hold a name. Retiring somebody gives theirs
        // back, which matches the index: `active_name` generates NULL for a
        // retired row and NULLs do not collide.
        $sql = 'SELECT id FROM characters WHERE name = ? AND is_active = 1';
        $args = [$name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $exceptId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);
        if ($stmt->fetchColumn()) {
            return "There is already a character called {$name}. Pick another name.";
        }

        // Authored companions are not `characters` rows until they are
        // recruited, so their names have to be reserved separately. Without
        // this a player could name themselves after a companion, meet her
        // forty minutes later, and have the recruitment fail on a database
        // constraint in the middle of the scene where she agrees to come.
        $taken = $this->db->prepare('SELECT name FROM companions WHERE name = ? LIMIT 1');
        $taken->execute([$name]);
        if ($taken->fetchColumn()) {
            return "{$name} is somebody you may meet in Rivermark. Pick another name.";
        }

        return null;
    }

    /**
     * Rename a character, keeping names unique.
     *
     * Exists because the migration that added the constraint renames existing
     * duplicates to "Name (2)", and the player needs somewhere to put that
     * right.
     */
    public function rename(int $characterId, string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw new InvalidArgumentException('A name is required (max 50 characters).');
        }
        $this->assertNameFree($name, $characterId);
        $this->db->prepare('UPDATE characters SET name = ? WHERE id = ?')
            ->execute([$name, $characterId]);
        return $this->getCharacter($characterId);
    }

    /**
     * Retire a character, freeing their name.
     *
     * A soft delete: the row stays because quests, flags, the roll log and any
     * finished combat session all reference it by id, and hard-deleting would
     * either cascade those away or leave them dangling. `is_active = 0` drops
     * them from the party, the character select and the name index, which is
     * everywhere a player can see them.
     *
     * Recruited companions do not come through here — CompanionService::dismiss
     * sends them to camp and ::leave writes them out of the story, and both
     * keep the character row usable in case they come back.
     */
    public function retire(int $characterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, companion_key, is_active FROM characters WHERE id = ?'
        );
        $stmt->execute([$characterId]);
        $char = $stmt->fetch();
        if (!$char) {
            throw new InvalidArgumentException('No such character.');
        }
        if (!(int) $char['is_active']) {
            throw new InvalidArgumentException($char['name'] . ' is already retired.');
        }
        if ($char['companion_key']) {
            throw new InvalidArgumentException(
                $char['name'] . ' is a companion. Dismiss them at camp instead.'
            );
        }

        // Refuse only if this is the last player character anywhere — not the last
        // in its own party.
        //
        // Scoping it to the party was too strict in a way that only showed up once
        // "Start a New Game" really started one: a character alone in a new party
        // could never be retired, so every game you began and thought better of
        // left a row in the character list for good. What the guard is actually
        // for is making sure you are never left with nothing to play, and that is
        // a question about all your characters at once.
        //
        // The party this leaves behind may hold companions with nobody whose story
        // it is. That is an abandoned save rather than a broken one — companions
        // are not offered in the character list, so there is no way to walk into
        // it by accident.
        $others = $this->db->prepare(
            'SELECT COUNT(*) FROM characters
              WHERE id <> ? AND is_active = 1 AND companion_key IS NULL'
        );
        $others->execute([$characterId]);
        if ((int) $others->fetchColumn() === 0) {
            throw new InvalidArgumentException(
                'That is your last character. Create another before retiring this one.'
            );
        }

        $this->db->prepare('UPDATE characters SET is_active = 0 WHERE id = ?')
            ->execute([$characterId]);
        $this->db->prepare('DELETE FROM character_party WHERE character_id = ?')
            ->execute([$characterId]);

        return [
            'ok'      => true,
            'message' => $char['name'] . ' has retired. The name is free again.',
            'retired' => (int) $char['id'],
        ];
    }

    /**
     * Bring a retired character back.
     *
     * Retiring is a soft delete precisely so this is possible — the row, its
     * inventory, its quests and its flags were all left intact. The one thing
     * that can stand in the way is the name: somebody else may have taken it
     * while this character was retired, and only one of them can hold it.
     *
     * Re-joining a party is separate, and deliberate: a character retired from
     * a party that has since filled up has nowhere to go, and silently
     * bouncing a companion to make room would be worse than saying so.
     */
    public function restore(int $characterId, ?int $partyId = null): array
    {
        $stmt = $this->db->prepare('SELECT id, name, is_active FROM characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $char = $stmt->fetch();
        if (!$char) {
            throw new InvalidArgumentException('No such character.');
        }
        if ((int) $char['is_active']) {
            throw new InvalidArgumentException($char['name'] . ' has not retired.');
        }
        $this->assertNameFree((string) $char['name'], $characterId);

        $this->db->prepare('UPDATE characters SET is_active = 1 WHERE id = ?')
            ->execute([$characterId]);

        if ($partyId !== null) {
            $count = $this->db->prepare('SELECT COUNT(*) FROM character_party WHERE party_id = ?');
            $count->execute([$partyId]);
            if ((int) $count->fetchColumn() >= 4) {
                throw new InvalidArgumentException(
                    $char['name'] . ' is back, but the party is full. Free a slot at camp.'
                );
            }
            $slot = $this->db->prepare(
                'SELECT COALESCE(MAX(slot),0)+1 FROM character_party WHERE party_id = ?'
            );
            $slot->execute([$partyId]);
            $this->db->prepare(
                'INSERT IGNORE INTO character_party (party_id, character_id, slot) VALUES (?,?,?)'
            )->execute([$partyId, $characterId, (int) $slot->fetchColumn()]);
        }

        return [
            'ok'        => true,
            'message'   => $char['name'] . ' is back.',
            'character' => $this->getCharacter($characterId),
        ];
    }

    /**
     * Choose where somebody deploys: at the front of the line, or behind it.
     *
     * This was a combat rule and is now a starting position. Battles are fought
     * on a grid, so `combat_rank` decides only which end of the party's
     * deployment zone a character begins in — BattleMapGen::deploy() reads it
     * once and no rule consults it again.
     *
     * Still refused during a fight, and for a sharper reason than before:
     * positions live in `combat_sessions.state_json`, so changing this
     * mid-combat would move where they stand in the *next* fight while
     * appearing to do nothing in this one. Moving inside a fight is walking,
     * and CombatEngine::doMove owns it.
     */
    public function setCombatRank(int $characterId, string $rank): array
    {
        if (!in_array($rank, ['front', 'back'], true)) {
            throw new InvalidArgumentException('A rank is either front or back.');
        }
        $active = $this->db->prepare(
            'SELECT 1 FROM combat_sessions WHERE character_id = ? AND is_active = 1 LIMIT 1'
        );
        $active->execute([$characterId]);
        if ($active->fetchColumn()) {
            throw new InvalidArgumentException(
                'Not in the middle of a fight — walk them there on your turn instead.'
            );
        }
        $this->db->prepare('UPDATE characters SET combat_rank = ? WHERE id = ?')
            ->execute([$rank, $characterId]);
        return $this->getCharacter($characterId);
    }

    /** Fallback when a character's class has no sprite_key row. */
    private const DEFAULT_SPRITE = 'fighter';

    /**
     * Ability modifiers plus the art key for this character's class, so the
     * client can resolve its map sprite, faceset and dialogue bust.
     */
    private function decorate(array $char): array
    {
        $char['modifiers'] = [
            'strength'     => ability_mod((int) $char['strength']),
            'dexterity'    => ability_mod((int) $char['dexterity']),
            'constitution' => ability_mod((int) $char['constitution']),
            'intelligence' => ability_mod((int) $char['intelligence']),
            'wisdom'       => ability_mod((int) $char['wisdom']),
            'charisma'     => ability_mod((int) $char['charisma']),
        ];
        $char['sprite_key'] = $char['sprite_key'] ?? self::DEFAULT_SPRITE;

        // Feats, twice over and deliberately.
        //
        // `feats` is the plain list of keys, and it MUST stay that shape:
        // Feats::taken() reads this key in preference to `feats_json`, because
        // a combatant carries the decoded list and nothing else, and
        // CombatEngine builds its combatants out of rows that have been through
        // here. Putting anything richer under this name would make every feat
        // silently inert the moment a fight started — the exact failure this
        // whole system was most at risk of.
        //
        // `feat_detail` is the readable half, for a screen that wants to print
        // what a character has rather than test for one.
        $char['feats'] = Feats::taken($char);
        $char['feat_detail'] = Feats::detail($char);
        // Attached here rather than computed in the client, because the XP
        // ladder lives in Rules and a second copy in JavaScript is the same
        // drift the spell slot table already suffers from.
        $char['xp_progress'] = Rules::xpProgress((int) ($char['experience'] ?? 0));
        return $char;
    }

    /**
     * Which art a character wears, in order of who gets to decide.
     *
     * A recruited companion follows their authored template, because the
     * template is the content and content is the source of truth. Recruitment
     * used to *copy* the key onto the `characters` row, and a copy goes stale
     * the moment the JSON changes: editing Brother Aldric's `sprite_key` gave
     * him a new face in conversation, which reads `npcs`, while his map sprite
     * and party portrait stayed Leyanne, which read the stale copy. Exactly the
     * mistake docs/CONTENT.md warns about for numeric ids, made with a string.
     *
     * Below the template: the player's own pick, then the class default, so a
     * character made before avatars existed still looks right.
     *
     * Public, and both halves must travel together. WorldState kept its own
     * shortened copy — `COALESCE(c.sprite_key, cl.sprite_key)`, no companions
     * leg — and since recruitment stopped copying the key onto the character
     * row, `c.sprite_key` is NULL for every companion, so that copy fell
     * straight through to the class default. Kessa wore a generic barbarian and
     * Aldric a generic cleric on every screen fed from WorldState::context(),
     * the dice roller among them. One rule, in one place, is the fix; a second
     * copy of it is the bug.
     */
    public const SPRITE_JOIN =
        'LEFT JOIN companions cmp ON cmp.companion_key = c.companion_key';

    public const SPRITE_SELECT =
        'COALESCE(cmp.sprite_key, c.sprite_key, cl.sprite_key) AS sprite_key';

    public function getCharacter(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, ' . self::SPRITE_SELECT . ' FROM characters c
             LEFT JOIN classes cl ON cl.name = c.class
             ' . self::SPRITE_JOIN . '
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $char = $stmt->fetch();
        if (!$char) {
            throw new RuntimeException('Character not found.');
        }
        return $this->decorate($char);
    }

    public function getPartyCharacters(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, ' . self::SPRITE_SELECT . ' FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             LEFT JOIN classes cl ON cl.name = c.class
             ' . self::SPRITE_JOIN . '
             WHERE cp.party_id = ? AND c.is_active = 1
             ORDER BY cp.slot'
        );
        $stmt->execute([$partyId]);
        return array_map([$this, 'decorate'], $stmt->fetchAll());
    }

    /** Avatars a player may choose, in display order. */
    public function listAvatars(): array
    {
        return $this->db
            ->query('SELECT sprite_key, label FROM avatars ORDER BY sort_order, label')
            ->fetchAll();
    }

    /**
     * Set a character's avatar.
     *
     * The key reaches the client as part of an asset path, so it is constrained
     * to a safe identifier and then accepted if the avatars table has the row
     * OR the painted file exists on disk. Painted tokens are files on disk, not
     * pack actors; the table stays the catalogue of pack actors. Passing an
     * empty key clears the override and returns the character to its class
     * default.
     */
    public function setAvatar(int $characterId, string $spriteKey): array
    {
        if ($spriteKey === '') {
            $this->db->prepare('UPDATE characters SET sprite_key = NULL WHERE id = ?')
                ->execute([$characterId]);
            return $this->getCharacter($characterId);
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $spriteKey)) {
            throw new InvalidArgumentException('Unknown avatar.');
        }

        $check = $this->db->prepare('SELECT 1 FROM avatars WHERE sprite_key = ?');
        $check->execute([$spriteKey]);
        $painted = dirname(__DIR__, 2) . '/assets/images/npcs/' . $spriteKey . '_bust.png';
        if (!$check->fetchColumn() && !is_file($painted)) {
            throw new InvalidArgumentException('Unknown avatar.');
        }

        $this->db->prepare('UPDATE characters SET sprite_key = ? WHERE id = ?')
            ->execute([$spriteKey, $characterId]);
        return $this->getCharacter($characterId);
    }

    /**
     * A race's ability adjustments applied to a set of base scores.
     *
     * Public and static because a companion is built from an authored template
     * rather than from the creation screen, and it has to arrive at the same
     * numbers a player-made character of that race would. Anything less than
     * one implementation means Kessa's Strength depends on which door she came
     * through.
     */
    public static function racialAbilities(array $base, array $race): array
    {
        $map = [
            'strength'     => (int) ($race['str_bonus'] ?? 0),
            'dexterity'    => (int) ($race['dex_bonus'] ?? 0),
            'constitution' => (int) ($race['con_bonus'] ?? 0),
            'intelligence' => (int) ($race['int_bonus'] ?? 0),
            'wisdom'       => (int) ($race['wis_bonus'] ?? 0),
            'charisma'     => (int) ($race['cha_bonus'] ?? 0),
        ];
        foreach ($map as $ability => $bonus) {
            $base[$ability] = min(20, (int) ($base[$ability] ?? 10) + $bonus);
        }

        // Half-Elf's two free +1s are not in the races table because the SRD
        // lets the player choose them; the seed picks DEX and CON so the row
        // stays a single race rather than one per pair.
        if (($race['name'] ?? '') === 'Half-Elf') {
            $base['dexterity'] = min(20, $base['dexterity'] + 1);
            $base['constitution'] = min(20, $base['constitution'] + 1);
        }
        return $base;
    }

    /**
     * Maximum hit points for a character of this class at this level.
     *
     * First level is the full hit die, every level after it the fixed average
     * from Rules. A companion template may be authored above level 1, and
     * rolling their history at recruitment would make the same companion a
     * different character in two playthroughs.
     */
    public static function hitPointsAtLevel(
        int $hitDie,
        int $conMod,
        int $level,
        ?string $subrace = null,
        ?string $subclass = null
    ): int {
        $hp = $hitDie + $conMod;
        for ($l = 2; $l <= max(1, $level); $l++) {
            $hp += Rules::hpPerLevel($hitDie, $conMod);
        }
        // Dwarven Toughness is +1 per level, and the only racial HP rule the
        // seed carries.
        if ($subrace === 'Hill Dwarf') {
            $hp += max(1, $level);
        }
        // Draconic Resilience is the same shape from the other direction —
        // relevant at creation because the Sorcerer's subclass arrives at 1st.
        if ($subclass === 'Draconic Bloodline') {
            $hp += max(1, $level);
        }
        return max(1, $hp);
    }

    /**
     * Armour class with nothing worn.
     *
     * The floor every AC calculation starts from: equipping armour replaces it,
     * a shield adds to whatever is left. Barbarian and Monk unarmoured defence
     * live here rather than in the equipment pass because they are what those
     * classes have *instead* of armour.
     *
     * @param array $abilities ability scores keyed by their long names
     */
    public static function unarmoredAc(string $class, array $abilities, ?string $subclass = null): int
    {
        $dexMod = ability_mod((int) ($abilities['dexterity'] ?? 10));
        if (strcasecmp($class, 'Barbarian') === 0) {
            return 10 + $dexMod + ability_mod((int) ($abilities['constitution'] ?? 10));
        }
        if (strcasecmp($class, 'Monk') === 0) {
            return 10 + $dexMod + ability_mod((int) ($abilities['wisdom'] ?? 10));
        }
        // Draconic Resilience: "parts of your skin are covered by a thin
        // sheen of dragon-like scales" — 13 + DEX while wearing no armour.
        if ($subclass === 'Draconic Bloodline') {
            return 13 + $dexMod;
        }
        return 10 + $dexMod;
    }

    public function findRace(string $name, ?string $subrace): ?array
    {
        if ($subrace) {
            $stmt = $this->db->prepare('SELECT * FROM races WHERE name = ? AND subrace = ? LIMIT 1');
            $stmt->execute([$name, $subrace]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM races WHERE name = ? AND (subrace IS NULL OR subrace = "") LIMIT 1');
            $stmt->execute([$name]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
            // fallback first subrace
            $stmt = $this->db->prepare('SELECT * FROM races WHERE name = ? ORDER BY id LIMIT 1');
            $stmt->execute([$name]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findClass(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM classes WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function resolveAbilities(string $method, array $input, array $rolled = []): array
    {
        $out = array_fill_keys(self::ABILITIES, 10);

        if ($method === 'random') {
            // `rolled` is the six 4d6-drop-lowest totals the server already threw
            // and handed to the client to animate. When it is present the player
            // is only choosing which ability each total lands on, so the submitted
            // map has to be a permutation of it — that is what stops a client
            // posting six 18s, while still letting them arrange what they rolled.
            //
            // Without it, roll here as before. Nothing in the app takes that path
            // any more, but a caller that does should get dice rather than an
            // error.
            if (!$rolled) {
                foreach (self::ABILITIES as $ab) {
                    $out[$ab] = Dice::abilityScore()['total'];
                }
                return $out;
            }

            $direct = [];
            foreach (self::ABILITIES as $ab) {
                if (isset($input[$ab])) {
                    $direct[$ab] = (int) $input[$ab];
                }
            }
            if (count($direct) !== count(self::ABILITIES)) {
                throw new InvalidArgumentException(
                    'Assign a rolled score to all six abilities.'
                );
            }

            $given = array_values($direct);
            $expected = array_map('intval', array_values($rolled));
            sort($given);
            sort($expected);
            if ($given !== $expected) {
                throw new InvalidArgumentException(
                    'Those are not the scores that were rolled. Roll again.'
                );
            }
            return $direct + $out;
        }

        if ($method === 'standard') {
            $scores = $input['scores'] ?? self::STANDARD_ARRAY;
            if (count($scores) !== 6) {
                $scores = self::STANDARD_ARRAY;
            }
            $assignment = $input['assignment'] ?? self::ABILITIES;
            foreach (self::ABILITIES as $i => $ab) {
                $key = $assignment[$i] ?? $ab;
                if (!in_array($key, self::ABILITIES, true)) {
                    $key = $ab;
                }
                $out[$key] = (int) ($scores[$i] ?? 10);
            }
            // Also accept a direct ability map, which is what the creator posts.
            // It must still be the standard array — every one of 15/14/13/12/10/8
            // used exactly once. Without this check the six dropdowns could each
            // be set to 15 and the server would happily write a character with
            // 15s across the board, which is not the standard array in any
            // sense and is a straight upgrade over point buy.
            $direct = [];
            foreach (self::ABILITIES as $ab) {
                if (isset($input[$ab])) {
                    $direct[$ab] = (int) $input[$ab];
                }
            }
            if ($direct) {
                if (count($direct) !== count(self::ABILITIES)) {
                    throw new InvalidArgumentException(
                        'The standard array needs a score for all six abilities.'
                    );
                }
                $given = array_values($direct);
                $expected = self::STANDARD_ARRAY;
                sort($given);
                sort($expected);
                if ($given !== $expected) {
                    throw new InvalidArgumentException(
                        'The standard array must use 15, 14, 13, 12, 10 and 8 once each.'
                    );
                }
                $out = $direct + $out;
            }
            return $out;
        }

        // Point buy
        $totalCost = 0;
        foreach (self::ABILITIES as $ab) {
            $val = (int) ($input[$ab] ?? 8);
            if ($val < 8 || $val > 15) {
                throw new InvalidArgumentException("Point buy score for {$ab} must be 8–15.");
            }
            $totalCost += self::POINT_BUY_COST[$val];
            $out[$ab] = $val;
        }
        if ($totalCost > self::POINT_BUY_BUDGET) {
            throw new InvalidArgumentException('Point buy exceeds 27 points.');
        }
        return $out;
    }

    /**
     * Public because it is also the repair for a save whose inventory a content
     * reload cascaded away — see tools/repair_save.php, which only ever calls it
     * for a character carrying nothing at all.
     */
    public function grantStartingGear(int $characterId, string $className): void
    {
        // item ids from seed: weapons/armor/potions
        /*
         * Kits name items by key, never by id.
         *
         * These were hardcoded numbers — [[3,1],[10,1]] — matching the ids
         * seed_data.sql happened to insert. Once content/ took ownership of the
         * items table those numbers pointed at whatever the import assigned,
         * so a Barbarian's greataxe became a quest ledger. It survived only
         * because the old seed rows were still present as duplicates, which
         * means it would have broken on the next clean load rather than on the
         * change that caused it.
         *
         * A missing key is skipped rather than fatal: an incomplete kit is a
         * recoverable annoyance, a character that cannot be created is not.
         */
        $kit = self::kitFor($className);

        $lookup = $this->db->prepare('SELECT id FROM items WHERE item_key = ? LIMIT 1');
        $resolved = [];
        foreach ($kit as [$key, $qty]) {
            $lookup->execute([$key]);
            $id = $lookup->fetchColumn();
            if ($id !== false) {
                $resolved[] = [(int) $id, $qty];
            }
        }
        $kit = $resolved;

        $stmt = $this->db->prepare(
            'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped) VALUES (?,?,?,?)'
        );
        foreach ($kit as $i => [$itemId, $qty]) {
            $equipped = $i === 0 ? 1 : 0; // equip first weapon
            // also equip first armor in kit
            $stmt->execute([$characterId, $itemId, $qty, $equipped]);
        }

        // Equip armor if present
        $this->db->prepare(
            'UPDATE character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             SET ci.is_equipped = 1
             WHERE ci.character_id = ? AND i.item_type = \'armor\''
        )->execute([$characterId]);

        $this->recalculateAC($characterId);
    }

    /**
     * What each caster knows at level one.
     *
     * Names, not keys: these rows come from content/spells/, and the name is the
     * half of a spell that is stable across a rename of its file.
     *
     * Guidance is on the two SRD lists that have it because CheckService offers
     * its d4 on an ability check only when somebody in the party actually knows
     * it. Without a cleric or druid who does, the boost is code that never runs.
     *
     * Every name below is on that class's own SRD list. It is worth saying
     * because the Warlock's was not: it opened with Fire Bolt and Magic Missile,
     * neither of which a Warlock can learn — Fire Bolt is a Sorcerer/Wizard
     * cantrip and Magic Missile is not on the pact list at all. The class's
     * signature at-will, Eldritch Blast, was missing from the one class that
     * gets it.
     *
     * Paladins and Rangers are absent on purpose rather than by oversight:
     * HALF_CASTER_SLOTS gives them nothing at level 1, so a starting spell would
     * be one they could not cast until level 2.
     */
    public const STARTING_SPELLS = [
        'Wizard'   => ['Fire Bolt', 'Ray of Frost', 'Magic Missile'],
        'Sorcerer' => ['Fire Bolt', 'Magic Missile'],
        'Warlock'  => ['Eldritch Blast', 'Chill Touch', 'Hellish Rebuke'],
        'Cleric'   => ['Guidance', 'Sacred Flame', 'Cure Wounds', 'Shield of Faith', 'Bless'],
        'Druid'    => ['Guidance', 'Cure Wounds'],
        'Bard'     => ['Vicious Mockery', 'Cure Wounds'],
    ];

    /**
     * Give a caster the spells their class starts with.
     *
     * Public because it is also the repair: a content reload used to delete and
     * re-insert every spell, and `character_spells.spell_id` cascades, so existing
     * casters lost everything they knew. `INSERT IGNORE`-style duplicate avoidance
     * is done with a NOT EXISTS rather than a unique index, since the table has
     * none and adding one is a migration this does not need.
     *
     * @return int how many spells were newly granted
     */
    public function grantStartingSpells(int $characterId, string $className): int
    {
        $list = self::STARTING_SPELLS[$className] ?? null;
        if (!$list) {
            return 0;
        }
        $ins = $this->db->prepare(
            'INSERT INTO character_spells (character_id, spell_id, prepared)
             SELECT ?, s.id, 1 FROM spells s
              WHERE s.name = ?
                AND NOT EXISTS (
                    SELECT 1 FROM character_spells cs
                     WHERE cs.character_id = ? AND cs.spell_id = s.id
                )
              LIMIT 1'
        );
        $granted = 0;
        foreach ($list as $spellName) {
            $ins->execute([$characterId, $spellName, $characterId]);
            $granted += $ins->rowCount();
        }
        return $granted;
    }

    public function recalculateAC(int $characterId): void
    {
        $char = $this->getCharacter($characterId);
        $dexMod = ability_mod((int) $char['dexterity']);

        // Every equipped item, not only armour: a Ring of Protection carries
        // its `ac_bonus` in properties and would otherwise be jewellery.
        $stmt = $this->db->prepare(
            'SELECT i.* FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.character_id = ? AND ci.is_equipped = 1'
        );
        $stmt->execute([$characterId]);
        $equipped = $stmt->fetchAll();

        $ac = self::unarmoredAc((string) $char['class'], $char, $char['subclass'] ?? null);

        // Medium Armour Master raises the Dexterity a suit will carry from 2 to
        // 3. Read once, before the loop, because it applies to whichever suit
        // is worn rather than to a particular one.
        $mediumCap = Feats::maxOf($char, 'medium_armor_dex_cap');

        $hasBodyArmor = false;
        $shieldBonus = 0;
        // The enchantment pass: properties.ac_bonus on anything worn — the +1
        // on a magic suit or shield, the flat bonus on a ring or cloak. Summed
        // apart from the armour arithmetic because it applies on top of
        // whichever base wins below.
        $enchantment = 0;
        foreach ($equipped as $a) {
            $props = json_decode($a['properties'] ?? '{}', true) ?: [];
            $enchantment += (int) ($props['ac_bonus'] ?? 0);
            if ($a['item_type'] !== 'armor') {
                continue;
            }
            if ($a['armor_type'] === 'shield') {
                $shieldBonus += (int) $a['armor_bonus'];
                continue;
            }
            $hasBodyArmor = true;
            $base = (int) $a['armor_bonus'];
            $props = json_decode($a['properties'] ?? '{}', true) ?: [];
            if (!empty($props['dex_bonus'])) {
                $maxDex = (int) ($props['dex_bonus_max'] ?? 99);
                if ($a['armor_type'] === 'medium' && $mediumCap > $maxDex) {
                    $maxDex = $mediumCap;
                }
                $ac = $base + min($dexMod, $maxDex);
            } else {
                $ac = $base;
            }
        }

        // If body armor worn, Barb/Monk unarmored defense does not apply (already replaced)
        $ac += $shieldBonus;
        $ac += $enchantment;

        // The Defense fighting style, which is the only feat that moves armour
        // class and moves it only while there is armour to move. A shield on
        // its own is not armour for this purpose — the SRD says "while you are
        // wearing armour", and a shield is carried.
        if ($hasBodyArmor) {
            $ac += Feats::sum($char, 'ac_bonus_armored');
        }

        $this->db->prepare('UPDATE characters SET armor_class = ? WHERE id = ?')
            ->execute([$ac, $characterId]);
    }
}
