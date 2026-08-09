<?php
/**
 * Everything a printed character sheet needs, worked out once on the server.
 *
 * Why this is a class and not a block at the top of `sheet_print.php`
 * ------------------------------------------------------------------
 * A page of paper somebody takes to a table has to be right. The in-game sheet
 * can be wrong for a moment and be fixed by a refresh; a printed one is wrong
 * for the whole evening, and the player has no way to tell that the +5 they are
 * adding to a die is not the +5 the game would have added. So every number on
 * the sheet is asked of Rules — the same Rules the dialogue checks, the combat
 * engine and the level-up ceremony ask — and none of it is worked out in the
 * template. A template that does arithmetic is a second rules engine that
 * nobody tests.
 *
 * It also means the whole sheet can be built and inspected without rendering
 * any HTML, which is what `tools/test_sheet.php` does.
 *
 * What is deliberately NOT here
 * -----------------------------
 * Personality traits, ideals, bonds, flaws, languages, treasure other than
 * gold, and the death-save and inspiration marks. The `characters` table has no
 * column for any of them, and inventing values to fill the boxes would be
 * putting words in a player's mouth. They print as ruled blanks instead, which
 * is what those boxes are for on a paper sheet anyway.
 */

declare(strict_types=1);

class CharacterSheet
{
    private PDO $db;

    /**
     * Content rows already looked up, so building one sheet asks for a race and
     * a class once each. Both are read three or four times over the course of a
     * build — the header, the features box, the proficiencies box — and they
     * cannot change while a page renders.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $raceCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $classCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The whole sheet for one character.
     *
     * Ownership is NOT checked here. That is the caller's job and it is checked
     * in `sheet_print.php` before this is reached — stating it plainly because a
     * builder that quietly enforced access would be a second gate, and two gates
     * disagreeing is how one of them ends up open.
     *
     * @return array<string, mixed>
     */
    public function build(int $characterId): array
    {
        $char = (new CharacterGenerator($this->db))->getCharacter($characterId);

        $class = (string) $char['class'];
        $level = max(1, (int) $char['level']);
        $prof = (int) ($char['proficiency_bonus'] ?? Rules::proficiencyBonus($level));
        $inventory = (new InventoryService($this->db))->list($characterId);

        // Worn magic's save bonus, folded onto the row before the save column
        // is built — the sheet-side half of the `equip_save_bonus` contract in
        // Rules::savingThrow. Combat stamps the same number onto combatants.
        $equipSave = 0;
        foreach ($inventory as $item) {
            if ((int) ($item['is_equipped'] ?? 0) === 1) {
                $props = json_decode((string) ($item['properties'] ?? ''), true) ?: [];
                $equipSave += (int) ($props['save_bonus'] ?? 0);
            }
        }
        $char['equip_save_bonus'] = $equipSave;

        return [
            'character'          => $char,
            'owner'              => $this->ownerName((int) ($char['user_id'] ?? 0)),
            'race_row'           => $this->raceRow((string) $char['race'], $char['subrace']),
            'class_row'          => $this->classRow($class),
            'proficiency_bonus'  => $prof,
            'abilities'          => $this->abilities($char),
            'saves'              => $this->saves($char),
            'skills'             => $this->skills($char),
            'passive_perception' => Rules::passiveScore($char, 'perception'),
            'initiative'         => Rules::abilityMod((int) $char['dexterity']),
            'hit_dice'           => $level . 'd' . Rules::hitDie($class),
            'attacks'            => $this->attacks($char, $inventory),
            'inventory'          => $inventory,
            'carried_weight'     => $this->carriedWeight($inventory),
            'features'           => $this->features($char),
            'proficiencies'      => $this->proficiencies($class),
            'spellcasting'       => $this->spellcasting($char),
        ];
    }

    // -----------------------------------------------------------------------
    // The boxed columns
    // -----------------------------------------------------------------------

    /**
     * The six scores, in the order the printed sheet stacks them.
     *
     * Rules::ABILITIES is that order already — Strength down to Charisma — so
     * the sheet takes it rather than restating it and risking a column that
     * disagrees with the rest of the codebase about what comes after Wisdom.
     *
     * @return list<array{key:string, label:string, abbr:string, score:int, mod:int}>
     */
    private function abilities(array $char): array
    {
        $out = [];
        foreach (Rules::ABILITIES as $key) {
            $label = Rules::ABILITY_NAMES[$key];
            $score = (int) $char[strtolower($label)];
            $out[] = [
                'key'   => $key,
                'label' => $label,
                'abbr'  => strtoupper($key),
                'score' => $score,
                'mod'   => Rules::abilityMod($score),
            ];
        }
        return $out;
    }

    /** @return list<array{key:string, label:string, mod:int, proficient:bool}> */
    private function saves(array $char): array
    {
        $out = [];
        foreach (Rules::ABILITIES as $key) {
            $out[] = [
                'key'        => $key,
                'label'      => Rules::ABILITY_NAMES[$key],
                'mod'        => Rules::savingThrow($char, $key)['total'],
                'proficient' => Rules::isSaveProficient($char, $key),
            ];
        }
        return $out;
    }

    /**
     * All eighteen, in the alphabetical order the printed sheet uses.
     *
     * Rules::SKILLS is declared in that order, so iterating it is the order —
     * no sort, and no second list of skill names to fall out of step with the
     * first one.
     *
     * @return list<array{key:string, label:string, ability:string, mod:int,
     *                    proficient:bool, expertise:bool}>
     */
    private function skills(array $char): array
    {
        $out = [];
        foreach (Rules::SKILLS as $key => $ability) {
            $out[] = [
                'key'        => $key,
                'label'      => self::skillLabel($key),
                'ability'    => strtoupper($ability),
                'mod'        => Rules::skillCheck($char, $key)['total'],
                'proficient' => Rules::isSkillProficient($char, $key),
                // Separate from `proficient` rather than a third state of it:
                // a doubled skill is still a proficient one, and the printed
                // sheet fills the same bubble either way.
                'expertise'  => Rules::hasExpertise($char, $key),
            ];
        }
        return $out;
    }

    /** `sleight_of_hand` as the player reads it, not as the database stores it. */
    private static function skillLabel(string $skill): string
    {
        $words = explode('_', $skill);
        foreach ($words as $i => $word) {
            $words[$i] = $word === 'of' ? $word : ucfirst($word);
        }
        return implode(' ', $words);
    }

    // -----------------------------------------------------------------------
    // Attacks
    // -----------------------------------------------------------------------

    /**
     * Every weapon carried, plus the fists that are always available.
     *
     * The whole pack rather than only what is equipped, because a printed sheet
     * is used across an evening in which the player will draw a different
     * weapon, and "what would my bonus be with the other one" is a question the
     * paper should already have answered. The equipped weapon is marked so the
     * player can see which one the game is currently swinging.
     *
     * Unarmed strike goes last and is always present. On a character carrying
     * nothing it is the only true row; on everyone else it is the line you need
     * exactly once, in the fight where you got disarmed.
     *
     * @return list<array<string, mixed>>
     */
    private function attacks(array $char, array $inventory): array
    {
        $out = [];
        foreach ($inventory as $item) {
            if (($item['item_type'] ?? '') !== 'weapon') {
                continue;
            }
            $attack = Rules::weaponAttack($char, $item);
            $attack['equipped'] = (int) ($item['is_equipped'] ?? 0) === 1;
            $attack['notes'] = self::weaponNotes($item);
            $out[] = $attack;
        }

        $fists = Rules::weaponAttack($char, null);
        $fists['equipped'] = false;
        $fists['notes'] = '';
        $out[] = $fists;

        return $out;
    }

    /**
     * The properties worth printing beside a weapon, as one short phrase.
     *
     * Only the ones that change what the player does at the table: whether it
     * needs both hands, whether it can be thrown, what it does when swung
     * two-handed. `martial` and `simple` are left out — they say which
     * proficiency the weapon needs, which the Other Proficiencies box already
     * covers, and a damage cell has room for about four words.
     */
    private static function weaponNotes(array $item): string
    {
        $props = Rules::weaponProperties($item);
        $notes = [];
        if (!empty($props['versatile'])) {
            $notes[] = 'versatile ' . $props['versatile'];
        }
        if (!empty($props['ranged'])) {
            $notes[] = 'range ' . $props['ranged'];
        }
        if (!empty($props['thrown'])) {
            $notes[] = 'thrown';
        }
        if (!empty($props['two_handed'])) {
            $notes[] = 'two-handed';
        }
        if (!empty($props['finesse'])) {
            $notes[] = 'finesse';
        }
        if (!empty($props['light'])) {
            $notes[] = 'light';
        }
        if (!empty($props['heavy'])) {
            $notes[] = 'heavy';
        }
        if (!empty($props['silvered'])) {
            $notes[] = 'silvered';
        }
        return implode(', ', $notes);
    }

    /**
     * What the pack weighs, in pounds.
     *
     * Quantity counts: ten javelins weigh ten javelins. Nothing in this game
     * enforces encumbrance, so the number is printed for the table's benefit
     * rather than the engine's — a group playing with the carrying-capacity
     * rules needs it and cannot recover it from the sheet otherwise.
     */
    private function carriedWeight(array $inventory): float
    {
        $total = 0.0;
        foreach ($inventory as $item) {
            $total += (float) ($item['weight'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
        }
        return round($total, 1);
    }

    // -----------------------------------------------------------------------
    // Features, traits and proficiencies
    // -----------------------------------------------------------------------

    /**
     * The Features & Traits box: what the race gave, what the class gave, and
     * whatever the player chose at an ability-score level.
     *
     * Race traits and class features are stored as comma-separated names with
     * no descriptions — that is all the seed carries — so they print as names.
     * Feats carry their own prose and print with it, because a feat is a rule
     * the player has to apply and a name alone is not enough to apply it.
     *
     * @return list<array{source:string, name:string, detail:string}>
     */
    private function features(array $char): array
    {
        $out = [];

        $race = $this->raceRow((string) $char['race'], $char['subrace']);
        $raceLabel = trim((string) $char['race'] . ' ' . (string) ($char['subrace'] ?? ''));
        foreach (self::splitList((string) ($race['traits'] ?? '')) as $trait) {
            $out[] = [
                'source' => $raceLabel,
                'name'   => $trait,
                // Which of the printed traits the engine actually applies —
                // the sheet-side face of the RACE_FEATURES honesty rule. An
                // unmarked trait is flavour, and saying so beats letting the
                // player assume the game is rolling it for them.
                'detail' => self::traitImplemented($char, $trait) ? 'Applied by the game.' : '',
            ];
        }

        $class = $this->classRow((string) $char['class']);
        foreach (self::splitList((string) ($class['features'] ?? '')) as $feature) {
            $out[] = ['source' => (string) $char['class'], 'name' => $feature, 'detail' => ''];
        }

        // The subclass is a feature in its own right: it is the choice made at
        // 3rd level and the sheet should say which way it went.
        $subclass = trim((string) ($char['subclass'] ?? ''));
        if ($subclass !== '') {
            $out[] = [
                'source' => (string) $char['class'],
                'name'   => $subclass,
                'detail' => (string) ($class['subclass_name'] ?? '') !== ''
                    ? 'Chosen at level ' . (int) ($class['subclass_level'] ?? 3)
                    : '',
            ];
        }

        foreach (Feats::taken($char) as $key) {
            $feat = Feats::get($key);
            if ($feat === null) {
                continue;
            }
            $out[] = ['source' => 'Feat', 'name' => $feat['name'], 'detail' => $feat['description']];
        }

        return $out;
    }

    /**
     * Whether a printed race trait is one the engine enforces.
     *
     * The trait strings in `races.traits` predate RACE_FEATURES and do not
     * share its keys, so the handful whose names differ are mapped by hand.
     * Dwarven Toughness is the odd one out: implemented directly in
     * CharacterGenerator::hitPointsAtLevel() rather than through the table.
     */
    private static function traitImplemented(array $char, string $trait): bool
    {
        $named = [
            'Lucky'             => 'halfling_luck',
            'Damage Resistance' => 'draconic_resistance',
        ];
        if (strcasecmp($trait, 'Dwarven Toughness') === 0) {
            return true;
        }
        $key = $named[$trait] ?? str_replace([' ', '-'], '_', strtolower(trim($trait)));
        return Rules::raceFeature($char, $key);
    }

    /**
     * The Other Proficiencies & Languages box.
     *
     * Languages are absent on purpose: `races` has no column for them, and the
     * SRD grants them by race. Printing a blank line the player fills in is
     * honest; printing a guess is not.
     *
     * @return list<array{label:string, value:string}>
     */
    private function proficiencies(string $class): array
    {
        $row = $this->classRow($class);
        $out = [];
        $armor = trim((string) ($row['armor_proficiencies'] ?? ''));
        $weapons = trim((string) ($row['weapon_proficiencies'] ?? ''));
        if ($armor !== '') {
            $out[] = ['label' => 'Armor', 'value' => implode(', ', self::splitList($armor))];
        }
        if ($weapons !== '') {
            $out[] = ['label' => 'Weapons', 'value' => implode(', ', self::splitList($weapons))];
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Spellcasting
    // -----------------------------------------------------------------------

    /**
     * The second page, or null for anyone who does not need one.
     *
     * Null is the whole point of the return type: a Fighter printing a blank
     * spell page is a wasted sheet of paper and a confusing one. The test is
     * the class's spellcasting ability, not whether they happen to know a
     * spell — a level-1 Paladin knows none and has no slots yet, but the page
     * belongs to them and will fill in.
     *
     * @return array<string, mixed>|null
     */
    private function spellcasting(array $char): ?array
    {
        $class = (string) $char['class'];
        $ability = Rules::spellcastingAbility($class);
        if ($ability === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT s.* FROM spells s
             INNER JOIN character_spells cs ON cs.spell_id = s.id
             WHERE cs.character_id = ?
             ORDER BY s.level, s.name'
        );
        $stmt->execute([(int) $char['id']]);
        $known = $stmt->fetchAll();

        // Grouped by level rather than sorted by it, because the printed page
        // has a separate ruled block per level and needs to know which are
        // empty as much as which are full.
        $byLevel = [];
        foreach ($known as $spell) {
            $byLevel[(int) $spell['level']][] = $spell;
        }

        return [
            'ability'       => Rules::ABILITY_NAMES[$ability],
            'ability_mod'   => Rules::abilityMod((int) $char[strtolower(Rules::ABILITY_NAMES[$ability])]),
            'save_dc'       => Rules::spellSaveDc($char),
            'attack_bonus'  => Rules::spellAttackBonus($char),
            'slots'         => Rules::slotState($char),
            'known'         => $known,
            'by_level'      => $byLevel,
            'max_level'     => Rules::maxCastableSpellLevel($class, (int) $char['level']),
        ];
    }

    // -----------------------------------------------------------------------
    // Content lookups
    // -----------------------------------------------------------------------

    /**
     * The `races` row behind a character, preferring their subrace.
     *
     * A Hill Dwarf and a Mountain Dwarf are separate rows with different
     * traits, so asking by name alone would print the wrong ones half the time.
     * Falls back to the name when the subrace is absent or unrecognised, and to
     * an empty array when the race itself is — a character of an invented race
     * should still print, with an empty traits list, rather than fail.
     *
     * @return array<string, mixed>
     */
    private function raceRow(string $race, ?string $subrace): array
    {
        $key = $race . '/' . (string) $subrace;
        if (isset($this->raceCache[$key])) {
            return $this->raceCache[$key];
        }

        $row = false;
        if ($subrace !== null && trim($subrace) !== '') {
            $stmt = $this->db->prepare('SELECT * FROM races WHERE name = ? AND subrace = ?');
            $stmt->execute([$race, $subrace]);
            $row = $stmt->fetch();
        }
        if ($row === false) {
            $stmt = $this->db->prepare('SELECT * FROM races WHERE name = ? ORDER BY id LIMIT 1');
            $stmt->execute([$race]);
            $row = $stmt->fetch();
        }

        return $this->raceCache[$key] = ($row === false ? [] : $row);
    }

    /**
     * The account that owns this character, for the sheet's "Player Name" box.
     *
     * The owner rather than whoever pressed Print. Those are the same person
     * almost always, and different exactly when an administrator opens a
     * player's save to look at it — at which point printing the administrator's
     * name onto that player's sheet would be quietly wrong in a way nobody
     * would think to check.
     *
     * An empty string for a character nobody owns, which leaves the box blank
     * rather than printing a guess.
     */
    private function ownerName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $stmt = $this->db->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** @return array<string, mixed> */
    private function classRow(string $class): array
    {
        if (isset($this->classCache[$class])) {
            return $this->classCache[$class];
        }
        $stmt = $this->db->prepare('SELECT * FROM classes WHERE name = ?');
        $stmt->execute([$class]);
        $row = $stmt->fetch();
        return $this->classCache[$class] = ($row === false ? [] : $row);
    }

    /**
     * A comma-separated column as a list, with the empties dropped.
     *
     * `races.traits` and `classes.features` are both written this way, and a
     * trailing comma in the seed should not print as an eighth blank bullet.
     *
     * @return list<string>
     */
    private static function splitList(string $raw): array
    {
        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
