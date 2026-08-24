<?php
/**
 * The Player's Handbook — everything a player is allowed to know, gathered.
 *
 * Bestiary's opposite number and built the same way: the page is structure, this
 * is where the reading happens, and handbook.php queries nothing except through
 * here. What it gathers is the CATALOGUE — the peoples, the callings, the
 * ladder, the spells, the conditions, the gear — as opposed to a module's own
 * book, which prints one adventure, or the bestiary, which prints what is going
 * to hit you.
 *
 * THE RULE THIS FILE EXISTS TO KEEP. A handbook is the most dangerous document
 * in a project like this, because it is the one a player will believe. Every
 * number in it is therefore read from the thing that enforces it and never
 * transcribed: the level ladder from `Rules::XP_THRESHOLDS`, the slots from
 * `Rules::slotTable()`, the features from `Rules::CLASS_FEATURES` and
 * `SUBCLASS_FEATURES`, the conditions from `Conditions::CATALOG`, the peoples
 * and callings from the same `races` and `classes` rows the creator is built
 * from. Raise the level cap and this book grows; implement a class feature and
 * it appears here; write a spell and it is in the spell list.
 *
 * The one thing that is prose is the SENTENCE describing a feature, and that
 * comes from `app/inc/public_page.php`'s FEATURE_COPY — the same sentences the
 * public class pages print, so the book and the website cannot disagree about
 * what Evasion does. A key with no sentence prints its own name and nothing
 * else, which is terse rather than wrong.
 *
 * WHAT IT DELIBERATELY DOES NOT CONTAIN. Monsters — those are the bestiary's,
 * and a player's handbook that prints the thing waiting at the bottom of the
 * stair is not a player's handbook. Nor any module's locations, people or
 * quests; those are `AdventureBook`'s, and they are the referee's copy.
 */

declare(strict_types=1);

class Handbook
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * The peoples, one entry per ROW — which is per race AND subrace, because
     * that is what a player actually chooses and what the bonuses hang off.
     *
     * `RACES_WITHHELD` is subtracted, for the reason the public races page
     * subtracts it: a handbook offering a race the creator refuses is a
     * handbook that lies on the first page a player acts on.
     *
     * @return list<array<string, mixed>>
     */
    public function peoples(): array
    {
        $rows = $this->db->query(
            'SELECT name, subrace, speed, traits, description, source,
                    str_bonus, dex_bonus, con_bonus, int_bonus, wis_bonus, cha_bonus
               FROM races ORDER BY name, subrace'
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            if (in_array((string) $r['name'], RACES_WITHHELD, true)) {
                continue;
            }
            $r['bonuses'] = race_bonuses($r);
            $r['trait_rows'] = race_traits(
                (string) $r['name'],
                $r['subrace'] === null ? null : (string) $r['subrace'],
                (string) $r['traits']
            );
            $r['display_name'] = trim((string) $r['subrace']) !== ''
                ? (string) $r['subrace']
                : (string) $r['name'];
            $r['anchor'] = 'r-' . self::slug($r['display_name']);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * The callings, each with the features the engine actually runs and,
     * separately, the ones its row only prints.
     *
     * @return list<array<string, mixed>>
     */
    public function callings(): array
    {
        $rows = $this->db->query(
            'SELECT name, hit_die, primary_ability, saving_throws,
                    armor_proficiencies, weapon_proficiencies,
                    subclass_name, subclass_level, features, source
               FROM classes ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $c) {
            $name = (string) $c['name'];
            $sub = (string) ($c['subclass_name'] ?? '');
            $c['run'] = class_features_implemented($name, $sub, (int) ($c['subclass_level'] ?? 3));

            // The names the row prints, minus the ones already listed above as
            // implemented. Same matching rule the class page uses, so the two
            // cannot disagree about which half a feature falls in.
            $held = array_fill_keys(array_column($c['run'], 1), true);
            $printed = [];
            foreach (array_filter(array_map('trim', explode(',', (string) $c['features']))) as $f) {
                $key = strtolower(str_replace([' ', '-', "'"], ['_', '_', ''], $f));
                if (!isset($held[$key])) {
                    $printed[] = $f;
                }
            }
            $c['printed'] = $printed;
            $c['slots'] = $this->slotLadder($name);
            $c['anchor'] = 'c-' . self::slug($name);
            $out[] = $c;
        }
        return $out;
    }

    /**
     * A class's spell slots at every level, or null for a class that has none.
     *
     * Read from Rules::slotTable() a level at a time rather than from the
     * private tables behind it, so a handbook cannot print a ladder the game
     * does not hand out. Trailing all-zero columns are trimmed: a Paladin's
     * table is five wide and a Wizard's nine, and printing four empty columns
     * on the Paladin's would be four columns of nothing to read past.
     *
     * @return array{levels: list<int>, rows: array<int, list<int>>}|null
     */
    public function slotLadder(string $class): ?array
    {
        $rows = [];
        $widest = 0;
        for ($level = 1; $level <= Rules::MAX_LEVEL; $level++) {
            $table = Rules::slotTable($class, $level);
            $rows[$level] = array_values($table);
            foreach ($table as $slotLevel => $count) {
                if ($count > 0) {
                    $widest = max($widest, $slotLevel);
                }
            }
        }
        if ($widest === 0) {
            return null;                       // not a caster; nothing to print
        }
        foreach ($rows as $level => $counts) {
            $rows[$level] = array_slice($counts, 0, $widest);
        }
        return ['levels' => range(1, $widest), 'rows' => $rows];
    }

    /**
     * The whole level ladder in one table: experience, proficiency, and whether
     * this is a level that offers an ability increase or a feat.
     *
     * @return list<array{level:int, xp:int, prof:int, asi:bool}>
     */
    public function ladder(): array
    {
        $out = [];
        for ($level = 1; $level <= Rules::MAX_LEVEL; $level++) {
            $out[] = [
                'level' => $level,
                'xp'    => Rules::XP_THRESHOLDS[$level] ?? 0,
                'prof'  => Rules::proficiencyBonus($level),
                'asi'   => Rules::isAsiLevel($level),
            ];
        }
        return $out;
    }

    /**
     * Every spell, grouped by level, with cantrips first.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function spellsByLevel(): array
    {
        $rows = $this->db->query(
            'SELECT spell_key, name, level, school, class_list, casting_time,
                    range_text, components, duration, description, damage_dice,
                    damage_type, resolution, save_ability, save_effect,
                    concentration, target_kind, higher_level_json, source
               FROM spells ORDER BY level, name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $s) {
            // `class_list` is the column and `casters` the decoded list; one
            // name for both would overwrite the raw JSON with its own parse.
            $s['casters'] = self::listOf($s['class_list']);
            $s['anchor'] = 's-' . self::slug((string) $s['spell_key']);
            $s['effect_line'] = self::spellEffect($s);
            $out[(int) $s['level']][] = $s;
        }
        ksort($out);
        return $out;
    }

    /**
     * One line saying what a spell actually does when it is cast here.
     *
     * Built from the columns the engine resolves against rather than from the
     * description, which is flavour: a player reading "8d6 fire, Dexterity save
     * for half" is reading what will happen to them.
     */
    private static function spellEffect(array $s): string
    {
        $bits = [];
        $dice = trim((string) ($s['damage_dice'] ?? ''));
        $type = trim((string) ($s['damage_type'] ?? ''));

        if ($dice !== '') {
            $bits[] = $type === 'healing' ? "heals {$dice}" : "{$dice} {$type}";
        }

        $ability = trim((string) ($s['save_ability'] ?? ''));
        if ($ability !== '') {
            $name = Rules::ABILITY_NAMES[$ability] ?? ucfirst($ability);
            $effect = (string) ($s['save_effect'] ?? 'half');
            $bits[] = $effect === 'negate'
                ? "{$name} save to avoid it"
                : "{$name} save for half";
        } elseif (($s['resolution'] ?? '') === 'attack') {
            $bits[] = 'a spell attack roll';
        }

        $hl = json_decode((string) ($s['higher_level_json'] ?? ''), true);
        if (is_array($hl) && ($hl['dice_per_level'] ?? '') !== '') {
            $bits[] = "+{$hl['dice_per_level']} per slot level above";
        }
        if (!empty($s['concentration'])) {
            $bits[] = 'concentration';
        }
        return $bits ? ucfirst(implode(' · ', $bits)) : '';
    }

    /**
     * The conditions, straight out of the engine's own catalogue.
     *
     * @return list<array{key:string, label:string, description:string}>
     */
    public function conditions(): array
    {
        $out = [];
        foreach (Conditions::CATALOG as $key => $c) {
            $out[] = [
                'key'         => (string) $key,
                'label'       => (string) ($c['label'] ?? ucfirst((string) $key)),
                'description' => (string) ($c['description'] ?? ''),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    /**
     * The skills, each under the ability it is rolled with.
     *
     * @return array<string, list<string>>
     */
    public function skillsByAbility(): array
    {
        $out = [];
        foreach (Rules::SKILLS as $skill => $ability) {
            $out[$ability][] = ucwords(str_replace('_', ' ', (string) $skill));
        }
        foreach ($out as $ability => $skills) {
            sort($out[$ability]);
        }
        return $out;
    }

    /**
     * The gear a player can carry, grouped by kind.
     *
     * Treasure is left out: it is what a hoard is made of rather than anything
     * a player chooses, and a handbook listing forty gemstones is a handbook
     * nobody finds the armour in.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function gear(): array
    {
        $rows = $this->db->query(
            "SELECT item_key, name, description, item_type, rarity, weight,
                    value_gp, damage_dice, damage_type, armor_bonus, armor_type,
                    slot, properties
               FROM items
              WHERE item_type <> 'treasure'
              ORDER BY item_type, name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $i) {
            $i['line'] = self::gearLine($i);
            $out[(string) $i['item_type']][] = $i;
        }
        ksort($out);
        return $out;
    }

    /** The mechanical half of an item, as one readable line. */
    private static function gearLine(array $i): string
    {
        $bits = [];
        if (trim((string) ($i['damage_dice'] ?? '')) !== '') {
            $bits[] = trim($i['damage_dice'] . ' ' . (string) ($i['damage_type'] ?? ''));
        }
        // `armor_bonus` means two different things and the engine already knows
        // which: CharacterGenerator ADDS a shield's to the total and takes any
        // other armour's as the BASE. Printing "AC +13" for a chain shirt would
        // be the handbook disagreeing with the sheet about the same column.
        $ac = (int) ($i['armor_bonus'] ?? 0);
        $armour = trim((string) ($i['armor_type'] ?? ''));
        if ($ac !== 0) {
            if ($armour === 'shield') {
                $bits[] = 'AC +' . $ac;
            } else {
                $line = 'AC ' . $ac;
                // The Dexterity clause, said in the SRD's own words rather than
                // left as the two raw flags that encode it.
                $props = is_array($i['properties'] ?? null)
                    ? $i['properties']
                    : json_decode((string) ($i['properties'] ?? ''), true);
                if (is_array($props) && !empty($props['dex_bonus'])) {
                    $max = $props['dex_bonus_max'] ?? null;
                    $line .= ' + Dex' . ($max !== null ? ' (max ' . (int) $max . ')' : '');
                }
                $bits[] = $line . ($armour !== '' ? " · {$armour}" : '');
            }
        }
        foreach (self::propertyBits($i['properties'] ?? null) as $prop) {
            $bits[] = $prop;
        }
        if ((float) ($i['weight'] ?? 0) > 0) {
            $bits[] = rtrim(rtrim(number_format((float) $i['weight'], 2, '.', ''), '0'), '.') . ' lb';
        }
        if ((int) ($i['value_gp'] ?? 0) > 0) {
            $bits[] = (int) $i['value_gp'] . ' gp';
        }
        return implode(' · ', $bits);
    }

    /**
     * The readable half of an item's `properties`, which is an OBJECT of mixed
     * types rather than a list — booleans, numbers, lists, and nested effect
     * blocks all live in it.
     *
     * A flag prints as its name, a number as its name and value, a list of
     * scalars as its name and the values. Anything nested is SKIPPED: a
     * handbook is a book, not a dump of the column, and `use_effect` unrolled
     * into prose would be four lines of engine plumbing under a potion.
     * Bookkeeping flags are skipped for the same reason — `quest_item` tells a
     * player nothing about the thing in their hand.
     *
     * @return list<string>
     */
    private static function propertyBits($value): array
    {
        $props = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($props)) {
            return [];
        }
        // `dex_bonus` and `dex_bonus_max` are already said in words by the AC
        // clause above; repeating them raw would print the same rule twice.
        $skip = [
            'quest_item' => true, 'readable' => true,
            'dex_bonus' => true, 'dex_bonus_max' => true,
        ];

        $out = [];
        foreach ($props as $key => $v) {
            $key = (string) $key;
            if (isset($skip[$key])) {
                continue;
            }
            $label = str_replace('_', ' ', $key);
            if (is_bool($v)) {
                if ($v) {
                    $out[] = $label;
                }
            } elseif (is_int($v) || is_float($v)) {
                $out[] = $label . ' ' . ($v > 0 ? '+' : '') . $v;
            } elseif (is_string($v)) {
                $out[] = $label . ' ' . $v;
            } elseif (is_array($v) && $v === array_filter($v, 'is_scalar')) {
                $out[] = $label . ' ' . implode(', ', array_map('strval', $v));
            }
            // Anything left is a nested block, and it stays out.
        }
        return $out;
    }

    /**
     * A JSON column that holds a list, as a list. Returns nothing rather than
     * guessing when the column holds something else — a handbook is not the
     * place to find out that `classes` was a string this time.
     *
     * @return list<string>
     */
    private static function listOf($value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** An anchor-safe slug. */
    private static function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }
}
