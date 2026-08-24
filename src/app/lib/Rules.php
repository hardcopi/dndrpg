<?php
/**
 * The 5e SRD arithmetic every other engine defers to: ability modifiers,
 * proficiency, skills, saves, spellcasting and damage defences.
 *
 * All static and all pure — nothing here touches the database or the session,
 * so a rule can be exercised from a test harness without standing up MySQL.
 */

declare(strict_types=1);

class Rules
{
    /**
     * The short ability keys are canonical here because combat state uses them.
     * Character rows spell the words out, so every entry point normalises.
     */
    public const ABILITIES = ['str', 'dex', 'con', 'int', 'wis', 'cha'];

    public const ABILITY_NAMES = [
        'str' => 'Strength',
        'dex' => 'Dexterity',
        'con' => 'Constitution',
        'int' => 'Intelligence',
        'wis' => 'Wisdom',
        'cha' => 'Charisma',
    ];

    private const LONG_ABILITIES = [
        'str' => 'strength',
        'dex' => 'dexterity',
        'con' => 'constitution',
        'int' => 'intelligence',
        'wis' => 'wisdom',
        'cha' => 'charisma',
    ];

    /**
     * Which abilities a class actually wants, best first — for the creator.
     *
     * ADVICE, NOT A RULE, and the distinction is the whole reason this is a
     * separate table rather than a column. `classes.primary_ability` is the
     * SRD's own field and nothing here may contradict it; what the SRD does not
     * have is a SECOND ability, because it never needed one — "primary ability"
     * exists in those rules to gate multiclassing, not to help somebody arrange
     * six numbers. So the first half of each row restates a fact and the second
     * half is an opinion, and they are labelled differently on the screen for
     * that reason: the abilities step marks the primary as what the class is
     * built on and the secondary as what to raise next.
     *
     * `primary` is a LIST because the SRD's own answer sometimes is: a Fighter's
     * is "Strength or Dexterity", which is a real choice between two builds and
     * not an editorial hedge. Where the SRD names two abilities outright — Monk,
     * Paladin, Ranger — the second is carried here as the secondary rather than
     * as a second primary, because by the time somebody is assigning scores
     * "this one first, that one next" is the useful shape of the answer.
     *
     * Constitution is the secondary for seven of the twelve, which looks like a
     * table that gave up and is not: hit points and holding concentration are
     * what most classes want second, and writing something more varied here
     * would be worse advice more attractively arranged.
     *
     * tools/test_class_abilities.php holds the two halves together — every class
     * in the database has a row, no `primary` here names an ability the SRD
     * column does not, and nothing the column names is dropped from both halves.
     */
    public const CLASS_ABILITIES = [
        'Barbarian' => ['primary' => ['str'],        'secondary' => 'con'],
        'Bard'      => ['primary' => ['cha'],        'secondary' => 'dex'],
        'Cleric'    => ['primary' => ['wis'],        'secondary' => 'con'],
        'Druid'     => ['primary' => ['wis'],        'secondary' => 'con'],
        'Fighter'   => ['primary' => ['str', 'dex'], 'secondary' => 'con'],
        'Monk'      => ['primary' => ['dex'],        'secondary' => 'wis'],
        'Paladin'   => ['primary' => ['str'],        'secondary' => 'cha'],
        'Ranger'    => ['primary' => ['dex'],        'secondary' => 'wis'],
        'Rogue'     => ['primary' => ['dex'],        'secondary' => 'con'],
        'Sorcerer'  => ['primary' => ['cha'],        'secondary' => 'con'],
        'Warlock'   => ['primary' => ['cha'],        'secondary' => 'con'],
        'Wizard'    => ['primary' => ['int'],        'secondary' => 'con'],
    ];

    /** All eighteen SRD skills and the ability each is checked against. */
    public const SKILLS = [
        'acrobatics'      => 'dex',
        'animal_handling' => 'wis',
        'arcana'          => 'int',
        'athletics'       => 'str',
        'deception'       => 'cha',
        'history'         => 'int',
        'insight'         => 'wis',
        'intimidation'    => 'cha',
        'investigation'   => 'int',
        'medicine'        => 'wis',
        'nature'          => 'int',
        'perception'      => 'wis',
        'performance'     => 'cha',
        'persuasion'      => 'cha',
        'religion'        => 'int',
        'sleight_of_hand' => 'dex',
        'stealth'         => 'dex',
        'survival'        => 'wis',
    ];

    /**
     * The skills each SRD class may choose from.
     *
     * The SRD gives these as unordered sets, but `characters` has nowhere to
     * record which ones a player picked, so the fallback in skillCheck() takes
     * the first CLASS_SKILL_CHOICES entries of the list. The lists are
     * therefore written signature-skills-first: that ordering is our default
     * pick and carries no rules weight, but it is why a Rogue comes out
     * proficient in Stealth rather than in Acrobatics.
     */
    public const CLASS_SKILLS = [
        'Barbarian' => ['athletics', 'survival', 'intimidation', 'animal_handling', 'nature', 'perception'],
        'Bard'      => [
            'performance', 'persuasion', 'deception',
            'acrobatics', 'animal_handling', 'arcana', 'athletics', 'history', 'insight',
            'intimidation', 'investigation', 'medicine', 'nature', 'perception', 'religion',
            'sleight_of_hand', 'stealth', 'survival',
        ],
        'Cleric'    => ['religion', 'insight', 'history', 'medicine', 'persuasion'],
        'Druid'     => ['nature', 'survival', 'animal_handling', 'arcana', 'insight', 'medicine', 'perception', 'religion'],
        'Fighter'   => ['athletics', 'perception', 'acrobatics', 'animal_handling', 'history', 'insight', 'intimidation', 'survival'],
        'Monk'      => ['acrobatics', 'stealth', 'athletics', 'history', 'insight', 'religion'],
        'Paladin'   => ['athletics', 'persuasion', 'insight', 'intimidation', 'medicine', 'religion'],
        'Ranger'    => ['survival', 'perception', 'stealth', 'animal_handling', 'athletics', 'insight', 'investigation', 'nature'],
        'Rogue'     => [
            'stealth', 'perception', 'investigation', 'sleight_of_hand',
            'acrobatics', 'athletics', 'deception', 'insight', 'intimidation', 'performance', 'persuasion',
        ],
        'Sorcerer'  => ['arcana', 'persuasion', 'deception', 'insight', 'intimidation', 'religion'],
        'Warlock'   => ['arcana', 'deception', 'history', 'intimidation', 'investigation', 'nature', 'religion'],
        'Wizard'    => ['arcana', 'investigation', 'history', 'insight', 'medicine', 'religion'],
    ];

    /** How many of its class list a character of each class actually gets. */
    public const CLASS_SKILL_CHOICES = [
        'Barbarian' => 2, 'Bard' => 3, 'Cleric' => 2, 'Druid' => 2,
        'Fighter' => 2, 'Monk' => 2, 'Paladin' => 2, 'Ranger' => 3,
        'Rogue' => 4, 'Sorcerer' => 2, 'Warlock' => 2, 'Wizard' => 2,
    ];

    /** The two saving throws each SRD class is proficient in. */
    public const CLASS_SAVES = [
        'Barbarian' => ['str', 'con'],
        'Bard'      => ['dex', 'cha'],
        'Cleric'    => ['wis', 'cha'],
        'Druid'     => ['int', 'wis'],
        'Fighter'   => ['str', 'con'],
        'Monk'      => ['str', 'dex'],
        'Paladin'   => ['wis', 'cha'],
        'Ranger'    => ['str', 'dex'],
        'Rogue'     => ['dex', 'int'],
        'Sorcerer'  => ['con', 'cha'],
        'Warlock'   => ['wis', 'cha'],
        'Wizard'    => ['int', 'wis'],
    ];

    /**
     * SRD backgrounds and the two skills each grants.
     *
     * This is the SRD 5.2 list of sixteen, which is the open one. A background
     * not listed here — and not aliased by LEGACY_BACKGROUNDS below — grants
     * nothing, because guessing at a skill pair for an invented background
     * would put non-SRD rules content into the engine.
     */
    public const BACKGROUND_SKILLS = [
        'Acolyte'     => ['insight', 'religion'],
        'Artisan'     => ['investigation', 'persuasion'],
        'Charlatan'   => ['deception', 'sleight_of_hand'],
        'Criminal'    => ['sleight_of_hand', 'stealth'],
        'Entertainer' => ['acrobatics', 'performance'],
        'Farmer'      => ['animal_handling', 'nature'],
        'Guard'       => ['athletics', 'perception'],
        'Guide'       => ['stealth', 'survival'],
        'Hermit'      => ['medicine', 'religion'],
        'Merchant'    => ['animal_handling', 'persuasion'],
        'Noble'       => ['history', 'persuasion'],
        'Sage'        => ['arcana', 'history'],
        'Sailor'      => ['acrobatics', 'perception'],
        'Scribe'      => ['investigation', 'perception'],
        'Soldier'     => ['athletics', 'intimidation'],
        'Wayfarer'    => ['insight', 'stealth'],
    ];

    /**
     * Backgrounds this game shipped under their older names.
     *
     * `create.php` offered 'Folk Hero' — as the default — along with 'Guild
     * Artisan' and 'Outlander' on the companion templates, and none of the three
     * is in the list above, so every character carrying one silently received no
     * background skills at all. It looked like nothing: the skills simply were
     * not there, and no screen said why until the printed character sheet put
     * the whole eighteen in one column and the gaps became obvious.
     *
     * These are not guesses. Each is the open background that replaced the named
     * one when the lists were revised, so the mapping is a rename rather than an
     * invention — which is the distinction the note above actually cares about.
     *
     * The alias resolves the SKILLS only. The stored `characters.background`
     * string is left exactly as it is, because `WorldState::tag()` derives an
     * origin tag from it and three authored dialogue choices gate on
     * `guild_artisan`. Rewriting the column to the new name would silently close
     * content that is already written and already reachable.
     */
    public const LEGACY_BACKGROUNDS = [
        'Folk Hero'     => 'Farmer',
        'Guild Artisan' => 'Artisan',
        'Outlander'     => 'Guide',
    ];

    public const SPELLCASTING_ABILITY = [
        'Bard'     => 'cha',
        'Cleric'   => 'wis',
        'Druid'    => 'wis',
        'Paladin'  => 'cha',
        'Ranger'   => 'wis',
        'Sorcerer' => 'cha',
        'Warlock'  => 'cha',
        'Wizard'   => 'int',
    ];

    /**
     * The whole SRD ladder: twenty levels, and slots up to 9th.
     * Tables are indexed [character level][slot level - 1].
     *
     * These two move TOGETHER, and with the three slot tables below. Raising
     * MAX_LEVEL alone is not a bigger game, it is a crash: slotTable() clamps
     * the level it is given to MAX_LEVEL and then indexes the row directly, so
     * a cap of 20 over a table that stops at 6 is an undefined index the first
     * time a level-7 caster opens their sheet.
     */
    public const MAX_LEVEL = 20;
    public const MAX_SLOT_LEVEL = 9;

    /**
     * Classes that learn to swing twice, and the level they learn it at.
     *
     * Fifth, for all five of them.
     *
     * The Fighter keeps going where the others stop: a third attack at 11th and
     * a fourth at 20th. Those were two unreachable rows while the game stopped
     * at 6 and are two reachable ones now, which is why FIGHTER_ATTACKS exists
     * rather than a comment saying they are outside the cap.
     */
    public const EXTRA_ATTACK_AT = 5;
    public const EXTRA_ATTACK_CLASSES = ['Fighter', 'Barbarian', 'Paladin', 'Ranger', 'Monk'];

    /** The Fighter's own ladder: level => attacks per Attack action. */
    private const FIGHTER_ATTACKS = [20 => 4, 11 => 3, 5 => 2];

    /**
     * How many attacks one Attack action buys this character.
     *
     * Always at least one, including for a class that has no business
     * attacking: the wizard who swings a dagger is taking the Attack action
     * like anybody else.
     */
    public static function attacksPerAction(string $class, int $level): int
    {
        $key = self::classKey($class);
        if (!in_array($key, self::EXTRA_ATTACK_CLASSES, true)) {
            return 1;
        }
        if ($key === 'Fighter') {
            foreach (self::FIGHTER_ATTACKS as $at => $swings) {
                if ($level >= $at) {
                    return $swings;
                }
            }
            return 1;
        }
        return $level >= self::EXTRA_ATTACK_AT ? 2 : 1;
    }

    /**
     * Class features, and the level each arrives at.
     *
     * One table, because until now there was no such thing: the only class
     * features that existed in a fight were three bonus actions keyed off a
     * constant in CombatEngine, and `classes.features` — the comma-separated
     * string the character sheet prints — was read by nobody. A sheet that
     * promises what the engine does not have is the bug this table exists to
     * make impossible to write.
     *
     * Only what is IMPLEMENTED goes in here. Anything the SRD grants and this
     * engine does not is absent on purpose, so that hasFeature() can be trusted
     * as the one answer to "does this character actually get it".
     *
     * Everything is within Rules::MAX_LEVEL. A feature that arrives at 7th or
     * later would be a row nobody could ever reach.
     */
    public const CLASS_FEATURES = [
        'Barbarian' => [
            'reckless_attack' => 2,
            'danger_sense'    => 2,
            'fast_movement'   => 5,
        ],
        'Fighter' => [
            'action_surge'    => 2,
        ],
        'Cleric' => [
            'channel_divinity' => 2,
        ],
        'Monk' => [
            'martial_arts'    => 1,
        ],
        'Paladin' => [
            'lay_on_hands'    => 1,
            'divine_sense'    => 1,
            // Second, because that is when a half-caster first has a slot to
            // burn — HALF_CASTER_SLOTS gives a Paladin nothing at 1st, so a
            // Smite at 1st would be a button that could never be pressed.
            'divine_smite'    => 2,
        ],
        'Rogue' => [
            'uncanny_dodge'   => 5,
        ],
        'Wizard' => [
            'arcane_recovery' => 1,
        ],
    ];

    /**
     * Channel Divinity: how many times between breathers.
     *
     * One at 2nd, two at 6th, three at 18th — the last of which the game can
     * reach now that it runs to 20.
     */
    public static function channelDivinityUses(int $level): int
    {
        if ($level >= 18) {
            return 3;
        }
        return $level >= 6 ? 2 : 1;
    }

    /** How far Turn Undead and Preserve Life reach, in feet. */
    public const CHANNEL_FT = 30;

    /** Rounds an undead stays turned — the SRD's minute. */
    public const TURNED_ROUNDS = 10;

    /** Preserve Life's pool: five hit points per cleric level. */
    public static function preserveLifePool(int $level): int
    {
        return 5 * max(1, $level);
    }

    /**
     * The dice a Divine Smite adds, for a slot of this level.
     *
     * 2d8 for a 1st-level slot and another d8 per level above it, capped at
     * 5d8 — and one more against the undead and fiends, capped at 6d8. Both
     * caps are outside what this game can reach (MAX_SLOT_LEVEL is 3, so the
     * most a Paladin can burn is 4d8, or 5d8 against something unclean), and
     * they are written anyway because a table that silently disagrees with the
     * SRD at a level nobody reaches is a trap for whoever raises the cap.
     */
    public static function smiteDice(int $slotLevel, bool $unclean = false): int
    {
        $dice = min(5, 1 + max(1, $slotLevel));
        return $unclean ? min(6, $dice + 1) : $dice;
    }

    /** What a Smite burns extra dice against — the same list Divine Sense reads. */
    public const SMITE_UNCLEAN = ['undead', 'fiend'];

    /** A Paladin's healing pool: five hit points per level, per long rest. */
    public static function layOnHandsPool(int $level): int
    {
        return 5 * max(1, $level);
    }

    /** What curing one poisoning costs out of that pool. */
    public const LAY_ON_HANDS_CURE = 5;

    /** Uses of Divine Sense: one plus the Charisma modifier, per long rest. */
    public static function divineSenseUses(int $charisma): int
    {
        return max(1, 1 + self::abilityMod($charisma));
    }

    /** How far Divine Sense reaches, in feet. */
    public const DIVINE_SENSE_FT = 60;

    /** The creature types Divine Sense picks out of a room. */
    public const DIVINE_SENSE_TYPES = ['undead', 'fiend', 'celestial'];

    /**
     * Slot levels Arcane Recovery gives back: half the wizard's level, rounded
     * up. The SRD also forbids recovering a slot of 6th or higher — a rule
     * MAX_SLOT_LEVEL used to enforce for free when it was 3, and which has to
     * be written down now that a wizard can hold a 9th.
     */
    public const ARCANE_RECOVERY_MAX_SLOT = 5;

    public static function arcaneRecoveryBudget(int $level): int
    {
        return (int) ceil(max(1, $level) / 2);
    }

    /**
     * The die a Bard's Inspiration is worth, by level.
     *
     * d6, d8 at 5th, d10 at 10th, d12 at 15th. Lives here rather than in
     * CheckService, which is where it was: it is a rules table, and the two
     * other things that grow with a level — the Monk's fists and the Rogue's
     * sneak dice — are already answered from one place.
     */
    public static function bardicInspirationDie(int $level): int
    {
        return match (true) {
            $level >= 15 => 12,
            $level >= 10 => 10,
            $level >= 5  => 8,
            default      => 6,
        };
    }

    /**
     * The die a Monk's fists are worth, by level.
     *
     * d4 at 1st, d6 at 5th, d8 at 11th, d10 at 17th — the whole SRD ladder,
     * all of it reachable now.
     */
    public static function martialArtsDie(int $level): int
    {
        return match (true) {
            $level >= 17 => 10,
            $level >= 11 => 8,
            $level >= 5  => 6,
            default      => 4,
        };
    }

    /**
     * Whether Martial Arts applies to what this character is holding.
     *
     * "Unarmed strikes and monk weapons", and a monk weapon is a shortsword or
     * any simple melee weapon that is neither two-handed nor heavy. Read off
     * the properties the items already carry rather than a list of names, so a
     * simple weapon added to the content later is covered without being
     * enumerated here.
     *
     * @param array|null $item an `items` row, or null for a bare fist
     */
    public static function isMonkWeapon(?array $item): bool
    {
        if ($item === null) {
            return true;                       // fists always count
        }
        $props = self::weaponProperties($item);
        if (!empty($props['two_handed']) || !empty($props['heavy'])
            || !empty($props['ranged']) || !empty($props['ammunition'])) {
            return false;
        }
        if (strcasecmp(trim((string) ($item['name'] ?? '')), 'Shortsword') === 0) {
            return true;
        }
        // `martial` is set on the martial weapons; a simple one simply lacks it.
        return empty($props['martial']);
    }

    /** Whether this character has a named feature at the level they are. */
    public static function hasFeature(string $class, int $level, string $feature): bool
    {
        $at = self::CLASS_FEATURES[self::classKey($class)][$feature] ?? null;
        return $at !== null && $level >= $at;
    }

    /**
     * Subclass features, under the CLASS_FEATURES discipline: only what is
     * IMPLEMENTED. Each class has exactly one subclass in the seed, granted
     * automatically at `classes.subclass_level` (LevelUpService), so a row
     * here is a promise about every member of the class from that level on.
     *
     * What each key does, and where:
     *   improved_critical   Champion, 3rd — crits on 19 as well as 20
     *                       (CombatEngine::critFloor / resolveAttack)
     *   disciple_of_life    Life Domain, 1st — +2 + slot level on healing
     *                       spells (applySpellTo's heal branch)
     *   draconic_resilience Draconic Bloodline, 1st — +1 HP per level and
     *                       13 + DEX unarmoured (CharacterGenerator, rollHp)
     *   dark_ones_blessing  The Fiend, 1st — temp HP on dropping a foe
     *   fast_hands          Thief, 3rd — use an item as a bonus action
     *   colossus_slayer     Hunter, 3rd — +1d8 once a turn vs the wounded
     *   sculpt_spells       Evocation, 2nd — allies inside your own blast
     *                       are simply not in it
     *
     * Deliberately absent, so nobody trusts a ghost: Berserker's Frenzy (the
     * bonus-action structure holds one feature per class and a second bonus
     * attack needs a target picker — a real change, not a row), Lore's
     * Cutting Words, Open Hand's Flurry riders, Devotion's Sacred Weapon,
     * Land's Natural Recovery.
     */
    public const SUBCLASS_FEATURES = [
        'Champion'            => ['improved_critical' => 3],
        'Life Domain'         => ['disciple_of_life' => 1],
        'Draconic Bloodline'  => ['draconic_resilience' => 1],
        'The Fiend'           => ['dark_ones_blessing' => 1],
        'Thief'               => ['fast_hands' => 3],
        'Hunter'              => ['colossus_slayer' => 3],
        'School of Evocation' => ['sculpt_spells' => 2],
    ];

    /**
     * Whether this actor has a subclass feature, whichever shape it arrived
     * in: a combatant carries `subclass` stamped at the same crossing as
     * `feats`, a `characters` row carries the column itself. NULL — a
     * character below their subclass level, or any monster — answers false.
     */
    public static function subclassFeature(array $actor, string $feature): bool
    {
        $subclass = trim((string) ($actor['subclass'] ?? ''));
        if ($subclass === '') {
            return false;
        }
        $at = self::SUBCLASS_FEATURES[$subclass][$feature] ?? null;
        return $at !== null && max(1, (int) ($actor['level'] ?? 1)) >= $at;
    }

    /**
     * Every feature this character has, as a map the client can read.
     *
     * Sent with the combatant so the action bar can offer Action Surge to a
     * Fighter and to nobody else without holding a copy of CLASS_FEATURES in
     * JavaScript — the same discipline `ui.attacks_left` follows, and the same
     * drift ui-combat.js's header records having removed once already.
     *
     * Subclass features ride in the same map: to the client a Champion's
     * expanded crit and a Barbarian's Danger Sense are the same kind of fact.
     *
     * @return array<string, bool>
     */
    public static function featuresHeld(string $class, int $level, ?string $subclass = null): array
    {
        $out = [];
        foreach (self::CLASS_FEATURES[self::classKey($class)] ?? [] as $feature => $at) {
            $out[$feature] = $level >= $at;
        }
        $subclass = trim((string) $subclass);
        if ($subclass !== '') {
            foreach (self::SUBCLASS_FEATURES[$subclass] ?? [] as $feature => $at) {
                $out[$feature] = $level >= $at;
            }
        }
        return $out;
    }

    /** How far a Barbarian's Fast Movement carries them, in feet. */
    public const FAST_MOVEMENT_FT = 10;

    /**
     * Racial traits, under the same discipline as CLASS_FEATURES: only what is
     * IMPLEMENTED goes in here. `races.traits` — the comma-separated string the
     * sheet prints — remains prose, and anything it promises that this table
     * does not carry is flavour, deliberately. Darkvision is the loudest
     * absence: there is no light or darkness anywhere in the engine, so a
     * trait keyed to it would be a lie in either direction.
     *
     * Keyed by races.name, with subrace-specific grants under 'Name/Subrace'.
     * No level column: every racial trait in the SRD's level range here
     * arrives at 1st.
     *
     * What each key does, and where:
     *   halfling_luck        reroll a natural 1 on attack rolls and saves
     *                        (CombatEngine::resolveAttack / rollSave)
     *   brave                advantage on saves vs frightened (rollSave tags)
     *   fey_ancestry         advantage on saves vs charmed, and enchantment
     *                        sleep cannot take them (rollSave / applySpellTo)
     *   dwarven_resilience   poison resistance + advantage on poison saves
     *   stout_resilience     the halfling copy of the same
     *   hellish_resistance   fire resistance (Tiefling)
     *   draconic_resistance  fire resistance — ancestry fixed as red/fire the
     *                        same way the Half-Elf's +1s are fixed in
     *                        CharacterGenerator::racialAbilities()
     *   relentless_endurance drop to 1 HP instead of 0, once per long rest
     *                        (CombatEngine::takeDamage)
     *   savage_attacks       one extra weapon die on a melee critical
     *   keen_senses          Perception proficiency (skillProficient)
     *   gnome_cunning        advantage on INT/WIS/CHA saves against magic
     *   stonecunning         advantage on History checks (CheckService) —
     *                        the SRD limits it to stonework; nothing here can
     *                        tell what a check is about, so a dwarf gets it on
     *                        all of History. A small over-grant, on purpose.
     *   quarry_built         Athletics proficiency (skillProficient) — the
     *                        Sarsen copy of what keen_senses does for
     *                        Perception, and implemented at the same line
     *   set_fast             the contest to resist a grapple or a shove is
     *                        rolled with advantage (CombatEngine::doContest).
     *                        Defensive only: a Sarsen is hard to move, not
     *                        better at moving other people — the offensive
     *                        half of that contest is an Athletics check, and
     *                        quarry_built is already in it.
     *
     * The Sarsen's third printed trait, Load-Bearing, is deliberately absent:
     * carrying capacity is not a limit anywhere in this game, so a key for it
     * would be the same lie Darkvision would be. The sheet prints it without
     * "Applied by the game", which is the honest answer.
     */
    public const RACE_FEATURES = [
        'Dragonborn'     => ['draconic_resistance'],
        'Dwarf'          => ['dwarven_resilience', 'stonecunning'],
        'Elf'            => ['keen_senses', 'fey_ancestry'],
        'Gnome'          => ['gnome_cunning'],
        'Half-Elf'       => ['fey_ancestry'],
        'Half-Orc'       => ['relentless_endurance', 'savage_attacks'],
        'Halfling'       => ['halfling_luck', 'brave'],
        'Halfling/Stout' => ['stout_resilience'],
        'Sarsen'         => ['quarry_built', 'set_fast'],
        'Tiefling'       => ['hellish_resistance'],
    ];

    /** Damage resistances a racial feature carries. */
    private const RACE_FEATURE_RESISTANCES = [
        'dwarven_resilience'  => 'poison',
        'stout_resilience'    => 'poison',
        'hellish_resistance'  => 'fire',
        'draconic_resistance' => 'fire',
    ];

    /**
     * The implemented traits of one race, subrace grants included.
     *
     * @return string[] feature keys
     */
    public static function raceFeatures(?string $race, ?string $subrace = null): array
    {
        $race = trim((string) $race);
        if ($race === '') {
            return [];
        }
        $out = self::RACE_FEATURES[$race] ?? [];
        $subrace = trim((string) $subrace);
        if ($subrace !== '') {
            $out = array_merge($out, self::RACE_FEATURES[$race . '/' . $subrace] ?? []);
        }
        return $out;
    }

    /**
     * Whether this actor has a racial feature, whichever shape it arrived in.
     *
     * A combatant carries `race_features`, stamped once at the same crossing
     * as `feats`; a `characters` row carries `race` and `subrace` and is
     * answered from the table directly. Monsters have neither and get false.
     */
    public static function raceFeature(array $actor, string $feature): bool
    {
        $stamped = $actor['race_features'] ?? null;
        if (is_array($stamped)) {
            return in_array($feature, $stamped, true);
        }
        return in_array($feature, self::raceFeatures(
            isset($actor['race']) ? (string) $actor['race'] : '',
            isset($actor['subrace']) ? (string) $actor['subrace'] : null
        ), true);
    }

    /**
     * The damage resistances a set of racial features adds up to.
     *
     * @param  string[] $features
     * @return string[] damage types
     */
    public static function raceResistances(array $features): array
    {
        $out = [];
        foreach ($features as $feature) {
            $type = self::RACE_FEATURE_RESISTANCES[$feature] ?? null;
            if ($type !== null && !in_array($type, $out, true)) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Experience needed to hold each level, indexed by the level it buys.
     *
     * Lives here rather than in whichever system happens to be handing out the
     * XP. Combat, quest rewards and a dialogue `{"xp": 100}` effect all award
     * against the same ladder, and two copies of it is how a party levels for
     * killing the bandits but not for talking them down.
     */
    /** The SRD's own ladder, all twenty rungs. */
    public const XP_THRESHOLDS = [
        1 => 0,       2 => 300,     3 => 900,     4 => 2700,    5 => 6500,
        6 => 14000,   7 => 23000,   8 => 34000,   9 => 48000,   10 => 64000,
        11 => 85000,  12 => 100000, 13 => 120000, 14 => 140000, 15 => 165000,
        16 => 195000, 17 => 225000, 18 => 265000, 19 => 305000, 20 => 355000,
    ];

    public const HIT_DICE = [
        'Barbarian' => 12,
        'Fighter'   => 10, 'Paladin' => 10, 'Ranger' => 10,
        'Bard'      => 8, 'Cleric' => 8, 'Druid' => 8, 'Monk' => 8,
        'Rogue'     => 8, 'Warlock' => 8,
        'Sorcerer'  => 6, 'Wizard' => 6,
    ];

    /** Bard, Cleric, Druid, Sorcerer, Wizard. Nine columns, one per slot level. */
    private const FULL_CASTER_SLOTS = [
         1 => [2, 0, 0, 0, 0, 0, 0, 0, 0],
         2 => [3, 0, 0, 0, 0, 0, 0, 0, 0],
         3 => [4, 2, 0, 0, 0, 0, 0, 0, 0],
         4 => [4, 3, 0, 0, 0, 0, 0, 0, 0],
         5 => [4, 3, 2, 0, 0, 0, 0, 0, 0],
         6 => [4, 3, 3, 0, 0, 0, 0, 0, 0],
         7 => [4, 3, 3, 1, 0, 0, 0, 0, 0],
         8 => [4, 3, 3, 2, 0, 0, 0, 0, 0],
         9 => [4, 3, 3, 3, 1, 0, 0, 0, 0],
        10 => [4, 3, 3, 3, 2, 0, 0, 0, 0],
        11 => [4, 3, 3, 3, 2, 1, 0, 0, 0],
        12 => [4, 3, 3, 3, 2, 1, 0, 0, 0],
        13 => [4, 3, 3, 3, 2, 1, 1, 0, 0],
        14 => [4, 3, 3, 3, 2, 1, 1, 0, 0],
        15 => [4, 3, 3, 3, 2, 1, 1, 1, 0],
        16 => [4, 3, 3, 3, 2, 1, 1, 1, 0],
        17 => [4, 3, 3, 3, 2, 1, 1, 1, 1],
        18 => [4, 3, 3, 3, 3, 1, 1, 1, 1],
        19 => [4, 3, 3, 3, 3, 2, 1, 1, 1],
        20 => [4, 3, 3, 3, 3, 2, 2, 1, 1],
    ];

    /**
     * Paladins and Rangers get nothing at 1st level; their table starts at 2,
     * and it never reaches past 5th-level slots however long they live.
     */
    private const HALF_CASTER_SLOTS = [
         1 => [0, 0, 0, 0, 0],
         2 => [2, 0, 0, 0, 0],
         3 => [3, 0, 0, 0, 0],
         4 => [3, 0, 0, 0, 0],
         5 => [4, 2, 0, 0, 0],
         6 => [4, 2, 0, 0, 0],
         7 => [4, 3, 0, 0, 0],
         8 => [4, 3, 0, 0, 0],
         9 => [4, 3, 2, 0, 0],
        10 => [4, 3, 2, 0, 0],
        11 => [4, 3, 3, 0, 0],
        12 => [4, 3, 3, 0, 0],
        13 => [4, 3, 3, 1, 0],
        14 => [4, 3, 3, 1, 0],
        15 => [4, 3, 3, 2, 0],
        16 => [4, 3, 3, 2, 0],
        17 => [4, 3, 3, 3, 1],
        18 => [4, 3, 3, 3, 1],
        19 => [4, 3, 3, 3, 2],
        20 => [4, 3, 3, 3, 2],
    ];

    /**
     * Pact Magic is not the spellcasting table with smaller numbers: a Warlock
     * has only ever a couple of slots and they are all of the highest level it
     * can cast, which is why the rows below are zero until the top slot moves.
     */
    private const WARLOCK_SLOTS = [
         1 => [1, 0, 0, 0, 0],
         2 => [2, 0, 0, 0, 0],
         3 => [0, 2, 0, 0, 0],
         4 => [0, 2, 0, 0, 0],
         5 => [0, 0, 2, 0, 0],
         6 => [0, 0, 2, 0, 0],
         7 => [0, 0, 0, 2, 0],
         8 => [0, 0, 0, 2, 0],
         9 => [0, 0, 0, 0, 2],
        10 => [0, 0, 0, 0, 2],
        11 => [0, 0, 0, 0, 3],
        12 => [0, 0, 0, 0, 3],
        13 => [0, 0, 0, 0, 3],
        14 => [0, 0, 0, 0, 3],
        15 => [0, 0, 0, 0, 3],
        16 => [0, 0, 0, 0, 3],
        17 => [0, 0, 0, 0, 4],
        18 => [0, 0, 0, 0, 4],
        19 => [0, 0, 0, 0, 4],
        20 => [0, 0, 0, 0, 4],
    ];

    public static function abilityMod(int $score): int
    {
        return (int) floor(($score - 10) / 2);
    }

    public static function proficiencyBonus(int $level): int
    {
        $level = max(1, min(20, $level));
        return intdiv($level - 1, 4) + 2;
    }

    /**
     * The level a given total of experience is worth.
     *
     * Total, not a delta: levelling is recomputed from the character's whole XP
     * rather than stepped one threshold at a time, so a single large award —
     * the end of a quest chain, a boss — can carry a character up two levels
     * instead of stopping at the first one it crossed.
     */
    public static function levelForXp(int $xp): int
    {
        $level = 1;
        foreach (self::XP_THRESHOLDS as $candidate => $needed) {
            if ($xp >= $needed && $candidate <= self::MAX_LEVEL) {
                $level = $candidate;
            }
        }
        return $level;
    }

    /**
     * Progress toward the next level, for a bar the player can read.
     *
     * Returned as a block rather than a bare percentage because the bar needs
     * to say what it is measuring — "1,150 / 2,700 to level 4" is information,
     * an unlabelled 42% is decoration. `earned` and `needed` are relative to
     * the CURRENT level's threshold, not to zero, so a bar that is a third
     * full means a third of this level's climb is done and not a third of all
     * the experience in the game.
     *
     * At MAX_LEVEL the bar is full and `next_level` is null; a character who
     * cannot advance should not be shown a target they will never reach.
     */
    public static function xpProgress(int $xp): array
    {
        $level = self::levelForXp($xp);
        $floor = self::XP_THRESHOLDS[$level] ?? 0;

        if ($level >= self::MAX_LEVEL) {
            return [
                'level'      => $level,
                'xp'         => $xp,
                'next_level' => null,
                'earned'     => 0,
                'needed'     => 0,
                'percent'    => 100,
                'remaining'  => 0,
            ];
        }

        $ceiling = self::XP_THRESHOLDS[$level + 1];
        $span = max(1, $ceiling - $floor);
        $earned = max(0, $xp - $floor);

        return [
            'level'      => $level,
            'xp'         => $xp,
            'next_level' => $level + 1,
            'earned'     => $earned,
            'needed'     => $span,
            'percent'    => (int) floor(min(100, ($earned / $span) * 100)),
            'remaining'  => max(0, $ceiling - $xp),
        ];
    }

    /**
     * Which line a class belongs in when a fight starts.
     *
     * Combat has no movement grid, so this one field is the whole of a
     * character's positioning — and every character used to take the schema
     * default of `front`, which put a nine-hit-point warlock in the rank that
     * exists to be hit. The back rank is shielded while any of the front still
     * stands, so this is the difference between a caster surviving round one and
     * not.
     *
     * Full casters and the bow user go back; anyone who fights by closing the
     * distance goes front. A rogue is front because sneak attack wants a melee
     * finisher and Cunning Action already lets them leave.
     */
    public const BACK_RANK_CLASSES = [
        'Wizard', 'Sorcerer', 'Warlock', 'Cleric', 'Druid', 'Bard', 'Ranger',
    ];

    public static function defaultCombatRank(string $class): string
    {
        return in_array($class, self::BACK_RANK_CLASSES, true) ? 'back' : 'front';
    }

    public static function hitDie(string $class): int
    {
        foreach (self::HIT_DICE as $name => $die) {
            if (strcasecmp($name, $class) === 0) {
                return $die;
            }
        }
        return 8;
    }

    /**
     * Hit points a level after the first is worth, taken as the fixed average.
     *
     * Still here, and still used — but no longer by levelling. LevelUpService
     * rolls the hit die instead, because the player asked to roll it. This
     * remains the answer for anything that needs a character's hit points
     * without a person present to throw a die: companions recruited at a level
     * above first, and the monster-side maths that sizes an encounter.
     *
     * Never below 1: a d6 caster with a punishing CON still gains something for
     * surviving.
     */
    public static function hpPerLevel(int $hitDie, int $conMod): int
    {
        return max(1, intdiv($hitDie, 2) + 1 + $conMod);
    }

    /**
     * Hit points from a rolled hit die.
     *
     * Straight: the die plus the CON modifier, with no floor of any kind. A
     * natural 1 on a d6 with a negative CON is a level that gave nothing, and
     * that is the deal a player takes when they choose to roll rather than take
     * the average. hpPerLevel() clamps to 1 because nobody is watching it; this
     * does not, because somebody is.
     */
    public static function hpFromRoll(int $roll, int $conMod): int
    {
        return $roll + $conMod;
    }

    /** Levels at which a character may raise abilities or take a feat. */
    public const ASI_LEVELS = [4, 8, 12, 16, 19];

    public static function isAsiLevel(int $level): bool
    {
        return in_array($level, self::ASI_LEVELS, true);
    }

    /**
     * How many new spells a caster learns on reaching a level.
     *
     * One, for anyone who can cast at all — uniform across the classes because
     * this game already uses a known-spells model for every caster
     * (`character_spells`), including the ones that prepare from a full list in
     * the SRD. Splitting the rule by class would mean a Wizard picking one spell
     * and a Cleric picking from all of them, which is a different feature.
     *
     * Zero for a level whose class has no slots yet: a Paladin at level 1 has
     * an empty slot table, and offering them a spell to learn there would be
     * offering them one they cannot cast.
     */
    public static function spellsLearnedAt(string $class, int $level): int
    {
        if (self::spellcastingAbility($class) === null) {
            return 0;
        }
        return array_sum(self::slotTable($class, $level)) > 0 ? 1 : 0;
    }

    /**
     * The highest slot level a class can actually cast at a given level.
     *
     * The filter on what a level-up may offer. Without it a level-5 Ranger is
     * shown level-3 spells that HALF_CASTER_SLOTS will never give them a slot
     * for.
     */
    public static function maxCastableSpellLevel(string $class, int $level): int
    {
        $highest = 0;
        foreach (self::slotTable($class, $level) as $slotLevel => $count) {
            if ($count > 0) {
                $highest = max($highest, (int) $slotLevel);
            }
        }
        return $highest;
    }

    /**
     * Which slot a spell of a given level actually consumes.
     *
     * Normally itself. The exception is Pact Magic: a Warlock's table is
     * all-or-nothing per row — level 5 is [0, 0, 2] — so a Warlock who learned
     * Hellish Rebuke at level 1 has no level-1 slot left to cast it with by
     * level 5, and nothing lower to fall back on. The SRD answer is that every
     * pact spell is cast at the pact slot's level, so a lower-level spell burns
     * a pact slot.
     *
     * Both the "can I cast this" check and the "spend it" write go through
     * here. They have to agree: checking one slot level and decrementing
     * another is a caster with infinite spells.
     */
    public static function slotLevelSpent(string $class, int $level, int $spellLevel): int
    {
        if ($spellLevel <= 0 || self::classKey($class) !== 'Warlock') {
            return $spellLevel;
        }
        $pact = 0;
        foreach (self::slotTable($class, $level) as $slotLevel => $count) {
            if ($count > 0) {
                $pact = (int) $slotLevel;
            }
        }
        return ($pact > 0 && $spellLevel < $pact) ? $pact : $spellLevel;
    }

    public static function skillAbility(string $skill): string
    {
        $key = self::skillKey($skill);
        if (!isset(self::SKILLS[$key])) {
            throw new InvalidArgumentException("Unknown skill: {$skill}");
        }
        return self::SKILLS[$key];
    }

    /** @return string[] skill keys */
    public static function classSkillProficiencies(string $class): array
    {
        return self::CLASS_SKILLS[self::classKey($class)] ?? [];
    }

    /** @return string[] short ability keys */
    public static function classSaveProficiencies(string $class): array
    {
        return self::CLASS_SAVES[self::classKey($class)] ?? [];
    }

    /** @return string[] skill keys */
    public static function backgroundSkills(string $background): array
    {
        $key = ucwords(strtolower(trim($background)));
        $key = self::LEGACY_BACKGROUNDS[$key] ?? $key;
        return self::BACKGROUND_SKILLS[$key] ?? [];
    }

    /**
     * A skill check's modifier, broken into the pieces that made it.
     *
     * `parts` exists so the client can show the player why a total is what it
     * is instead of a bare number they have to trust; anything added to the
     * total has to appear there or the two will disagree in front of them.
     *
     * @return array{total:int, parts:array<int, array{label:string, value:int}>}
     */
    public static function skillCheck(array $actor, string $skill): array
    {
        $key = self::skillKey($skill);
        if (!isset(self::SKILLS[$key])) {
            throw new InvalidArgumentException("Unknown skill: {$skill}");
        }

        $norm = self::normalise($actor);
        $ability = self::SKILLS[$key];

        $parts = [[
            'label' => self::ABILITY_NAMES[$ability],
            'value' => self::abilityMod($norm[$ability]),
        ]];

        if (self::skillProficient($actor, $norm, $key)) {
            $parts[] = ['label' => 'Proficiency', 'value' => $norm['prof']];
            // Expertise is the proficiency bonus a second time, not a bonus of
            // its own — "your proficiency bonus is doubled for any ability
            // check you make that uses either of those proficiencies" — so it
            // is added as a second part rather than by doubling the first. The
            // player is owed the arithmetic: `parts` is what the client shows
            // them, and one line reading 8 explains less than two reading 4.
            if (in_array($key, self::expertiseSkills($actor), true)) {
                $parts[] = ['label' => 'Expertise', 'value' => $norm['prof']];
            }
        }

        return self::totalled($parts);
    }

    /**
     * How many skill proficiencies a class doubles at a given level.
     *
     * Rogue only, because the Rogue is the only class whose `classes.features`
     * row claims Expertise. The SRD gives it to the Bard as well at 3rd, and
     * that row says "Spellcasting, Bardic Inspiration" — the sheet is built by
     * splitting that column, so granting a Bard something their own sheet does
     * not list is the mismatch this whole change exists to remove. Add it to
     * the row and this table together, or not at all.
     *
     * Two at 1st and two more at 6th. There is no third step in the SRD, so
     * this table is complete at any cap.
     */
    public static function expertiseCount(string $class, int $level): int
    {
        if (self::classKey($class) !== 'Rogue') {
            return 0;
        }
        return $level >= 6 ? 4 : 2;
    }

    /**
     * Which of this character's skills are doubled.
     *
     * Nobody is asked to choose, for the same reason nobody is asked to choose
     * their skill proficiencies: there is no column to record a choice in, and
     * defaultSkills() already derives those from the class and background. This
     * derives from the same place and in the same order — CLASS_SKILLS is
     * written signature-skills-first, so a Rogue doubles Stealth and Perception
     * rather than the two skills their background happened to bring.
     *
     * Walking the class list rather than the character's proficiency list is
     * what buys that ordering. defaultSkills() puts the background's grants at
     * the front, which is right for "what am I proficient in" and wrong for
     * "what is this class famously good at".
     *
     * @return string[] skill keys
     */
    public static function expertiseSkills(array $actor): array
    {
        $norm = self::normalise($actor);
        $want = self::expertiseCount($norm['class'], $norm['level']);
        if ($want <= 0) {
            return [];
        }

        $out = [];
        foreach (self::CLASS_SKILLS[$norm['class']] ?? [] as $skill) {
            if (count($out) >= $want) {
                break;
            }
            if (self::skillProficient($actor, $norm, $skill)) {
                $out[] = $skill;
            }
        }

        // Then anything else they are proficient in. The class list is only a
        // preference, and a character can be proficient in skills that are not
        // on it — a background grants two regardless of class, an explicit
        // `skills` list overrides the class entirely, and Observant hands out
        // two more from outside it. Without this pass a Rogue whose
        // proficiencies all came from elsewhere would have Expertise in
        // nothing, which is a feature quietly not applying rather than a rule.
        if (count($out) < $want) {
            foreach (array_keys(self::SKILLS) as $skill) {
                if (count($out) >= $want) {
                    break;
                }
                if (!in_array($skill, $out, true) && self::skillProficient($actor, $norm, $skill)) {
                    $out[] = $skill;
                }
            }
        }

        return $out;
    }

    /**
     * Whether this actor's proficiency is doubled in a skill — the mark.
     *
     * The public door onto expertiseSkills(), for the same reason
     * isSkillProficient() is one: a sheet marks the skill, a check adds the
     * number, and neither may work it out for itself.
     */
    public static function hasExpertise(array $actor, string $skill): bool
    {
        $key = self::skillKey($skill);
        if (!isset(self::SKILLS[$key])) {
            throw new InvalidArgumentException("Unknown skill: {$skill}");
        }
        return in_array($key, self::expertiseSkills($actor), true);
    }

    /**
     * Whether this actor is proficient in a skill — the mark, not the number.
     *
     * skillCheck() already knows the answer, but it folds it into a total and
     * an explanatory `parts` list, and reading the mark back out of that means
     * string-matching the label 'Proficiency'. A printed character sheet needs
     * eighteen of these bubbles filled or empty independently of the modifier
     * beside them, so it asks the question directly rather than inferring it
     * from an answer to a different one.
     *
     * The rule itself is not repeated here: this is a public door onto the same
     * private skillProficient() that skillCheck() uses, so the bubble and the
     * bonus can never disagree.
     */
    public static function isSkillProficient(array $actor, string $skill): bool
    {
        $key = self::skillKey($skill);
        if (!isset(self::SKILLS[$key])) {
            throw new InvalidArgumentException("Unknown skill: {$skill}");
        }
        return self::skillProficient($actor, self::normalise($actor), $key);
    }

    /**
     * Whether this actor is proficient in a saving throw.
     *
     * The companion to isSkillProficient() and for the same reason. Note it
     * answers for a *character*: a monster whose stat block states a save bonus
     * outright has no separate notion of proficiency in it, and savingThrow()
     * returns that stated number whatever this says.
     */
    public static function isSaveProficient(array $actor, string $ability): bool
    {
        $norm = self::normalise($actor);
        $key = self::abilityKey($ability);
        // Resilient is the other way to be proficient in a save, and the sheet
        // must tick the same box savingThrow() adds the bonus for.
        return in_array($key, self::classSaveProficiencies($norm['class']), true)
            || in_array($key, Feats::strings($actor, 'save_proficiency'), true);
    }

    /**
     * A saving throw's modifier, broken into the pieces that made it.
     *
     * @return array{total:int, parts:array<int, array{label:string, value:int}>}
     */
    public static function savingThrow(array $actor, string $ability): array
    {
        $key = self::abilityKey($ability);
        $norm = self::normalise($actor);

        // A monster's saves_json bonus is the whole modifier from its stat
        // block — ability score and proficiency already folded in — so adding
        // anything to it would count the same proficiency twice.
        $stated = self::statedSave($actor, $key);
        if ($stated !== null) {
            return self::totalled([[
                'label' => self::ABILITY_NAMES[$key] . ' save',
                'value' => $stated,
            ]]);
        }

        $parts = [[
            'label' => self::ABILITY_NAMES[$key],
            'value' => self::abilityMod($norm[$key]),
        ]];

        // Resilient grants proficiency in one save, and it is the same
        // proficiency the class grants rather than a second one stacked on top
        // — a Fighter who takes Resilient (Constitution) is proficient once,
        // not twice, which is why this is an OR and not two `parts` entries.
        if (in_array($key, self::classSaveProficiencies($norm['class']), true)
            || in_array($key, Feats::strings($actor, 'save_proficiency'), true)) {
            $parts[] = ['label' => 'Proficiency', 'value' => $norm['prof']];
        }

        // Worn magic — properties.save_bonus summed over equipped items and
        // handed in as `equip_save_bonus`, because a static rule cannot go
        // shopping. Combat stamps it at the startEncounter crossing;
        // CharacterSheet folds it in from its own inventory read. A caller
        // that supplies neither (a dialogue check on a bare characters row)
        // simply doesn't count the ring, which is the honest default.
        $equip = (int) ($actor['equip_save_bonus'] ?? 0);
        if ($equip !== 0) {
            $parts[] = ['label' => 'Equipment', 'value' => $equip];
        }

        return self::totalled($parts);
    }

    /** What a creature notices without rolling: 10 plus the check's modifier. */
    public static function passiveScore(array $actor, string $skill): int
    {
        return 10 + self::skillCheck($actor, $skill)['total'];
    }

    /**
     * What a character hits with, and for how much.
     *
     * The SRD picks the ability, not the class: a ranged weapon uses Dexterity,
     * a finesse weapon uses whichever of Strength and Dexterity is better, and
     * everything else uses Strength. Pass null for the weapon and the answer is
     * an unarmed strike, which is the honest line to print for somebody
     * carrying nothing.
     *
     * `damage` comes back as an expression a person can read and Dice can roll
     * — `1d8+3` — because the printed sheet and the combat log both want the
     * same string.
     *
     * A NOTE ON THE OTHER COPY. CombatEngine::attackProfile() works this out
     * again for its own combatants and does not call this yet, so the two must
     * be kept in step by hand until it does. They differ in one place today,
     * deliberately: this treats a weapon with a `ranged` property as ranged,
     * where the engine looks only for `ammunition`. No item in the content sets
     * `ammunition` — the Shortbow says `{"ranged":"80/320"}` — so the engine's
     * test never fires and it swings every bow with Strength. Printing that
     * would be printing a bug onto paper somebody takes to a table, so this
     * reads the property the content actually uses. Point attackProfile() at
     * this function and the game agrees with the sheet.
     *
     * @param array<string, mixed>      $actor  a `characters` row or a combatant
     * @param array<string, mixed>|null $weapon an `items` row, or null for fists
     * @return array{name:string, bonus:int, damage:string, damage_type:string, reach:string}
     */
    public static function weaponAttack(array $actor, ?array $weapon = null): array
    {
        $norm = self::normalise($actor);
        $strMod = self::abilityMod($norm['str']);
        $dexMod = self::abilityMod($norm['dex']);

        $name = 'Unarmed strike';
        $damageExpr = '1d4';
        $damageType = 'bludgeoning';
        $ranged = false;
        $finesse = false;

        if ($weapon !== null) {
            $props = self::weaponProperties($weapon);
            $name = (string) ($weapon['name'] ?? 'Weapon');
            $damageExpr = (string) ($weapon['damage_dice'] ?? '') ?: '1d6';
            $damageType = (string) ($weapon['damage_type'] ?? '') ?: 'slashing';
            $ranged = !empty($props['ammunition']) || !empty($props['ranged']);
            $finesse = !empty($props['finesse']);
        }

        $mod = $ranged ? $dexMod : (($finesse && $dexMod > $strMod) ? $dexMod : $strMod);

        // What the enchantment is worth.
        //
        // The same two properties CombatEngine reads when it swings the thing —
        // a +1 longsword carries {"attack_bonus":1,"damage_bonus":1} — and they
        // were being read there and nowhere else. The sheet therefore printed
        // +5 for a weapon the engine rolled at +6, which is precisely the
        // disagreement CharacterSheet's own header says a printed sheet must
        // never carry: the player adds the number in front of them and has no
        // way to know it is not the number the game added.
        $atkBonus = 0;
        $dmgBonus = 0;
        if ($weapon !== null) {
            $props = self::weaponProperties($weapon);
            $atkBonus = (int) ($props['attack_bonus'] ?? 0);
            $dmgBonus = (int) ($props['damage_bonus'] ?? 0);
        }

        return [
            'name'        => $name,
            'bonus'       => $mod + $norm['prof'] + $atkBonus,
            'damage'      => $damageExpr . sprintf('%+d', $mod + $dmgBonus),
            'damage_type' => $damageType,
            'reach'       => $ranged ? 'ranged' : 'melee',
        ];
    }

    /**
     * An item's `properties` column as an array, whatever shape it arrived in.
     *
     * Already-decoded on a combatant, a JSON string on a row straight out of
     * `items`, and occasionally NULL — a weapon with no properties at all is a
     * club, not an error.
     *
     * @return array<string, mixed>
     */
    public static function weaponProperties(array $weapon): array
    {
        $props = $weapon['properties'] ?? null;
        if (!is_array($props)) {
            $props = json_decode((string) $props, true);
        }
        return is_array($props) ? $props : [];
    }

    /**
     * What a weapon can hit, in feet: melee reach, normal range, long range.
     *
     * Combat has a floor now, so "melee or ranged" is no longer an answer —
     * every attack needs the actual numbers. They already existed in the
     * content and nothing read them: the Shortbow has said `"ranged":"80/320"`
     * since it was written, and the engine only ever asked whether that key was
     * truthy.
     *
     * All three fields can be set at once, and for a thrown weapon they are: a
     * javelin has a reach of five feet when you stab with it and twenty when
     * you let go. `null` means the weapon cannot attack that way at all.
     *
     * Both spellings of every property are tolerated, matching what the content
     * actually contains rather than what it ought to: `ranged` and `ammunition`
     * both mean ranged, and each of `ranged`, `thrown` and `reach` may be a
     * range string, `true`, or absent.
     *
     * @param array<string,mixed>|null $weapon an `items` row, or null for fists
     * @return array{reach:?int, normal:?int, long:?int}
     */
    public static function weaponRange(?array $weapon = null): array
    {
        if ($weapon === null) {
            return ['reach' => 5, 'normal' => null, 'long' => null];
        }
        $props = self::weaponProperties($weapon);

        $shot = $props['ranged'] ?? $props['ammunition'] ?? null;
        if (!empty($shot)) {
            // A bare `true` gets a deliberately short guess. Anything that
            // wants to outrange a sling has to say so in the content.
            $r = is_string($shot) ? self::parseRangeText($shot) : ['normal' => null, 'long' => null];
            return [
                'reach'  => null,
                'normal' => $r['normal'] ?? 30,
                'long'   => $r['long'] ?? 120,
            ];
        }

        $reach = !empty($props['reach']) ? 10 : 5;

        $thrown = $props['thrown'] ?? null;
        if (!empty($thrown)) {
            $r = is_string($thrown) ? self::parseRangeText($thrown) : ['normal' => null, 'long' => null];
            return [
                'reach'  => $reach,
                'normal' => $r['normal'] ?? 20,
                'long'   => $r['long'] ?? 60,
            ];
        }

        return ['reach' => $reach, 'normal' => null, 'long' => null];
    }

    /**
     * A range string as numbers: `"80/320"`, `"30/60"`, `"40 ft."`, `"20 ft"`.
     *
     * Both forms appear in the monster content today. A single distance means
     * the weapon has no long range and so never takes the distance penalty.
     *
     * @return array{normal:?int, long:?int}
     */
    public static function parseRangeText(string $text): array
    {
        if (preg_match('/(\d+)\s*\/\s*(\d+)/', $text, $m)) {
            return ['normal' => (int) $m[1], 'long' => (int) $m[2]];
        }
        if (preg_match('/(\d+)/', $text, $m)) {
            return ['normal' => (int) $m[1], 'long' => null];
        }
        return ['normal' => null, 'long' => null];
    }

    /**
     * How far a spell reaches and what shape it arrives in.
     *
     * `spells.range_text` was written as prose for the spell card — "60 feet",
     * "Touch", "Self (15-foot cone)" — and is now also the rule. Every value in
     * the content parses; the fallback exists for a spell somebody adds later
     * with a range nobody anticipated.
     *
     * THAT FALLBACK IS `reaches_back_rank`, the one-bit range model this
     * replaces. It is kept rather than dropped so a garbled range still
     * behaves like the spell it was: zero meant "melee only", which is touch,
     * and one meant "reaches anything", which is sixty feet. A column that
     * still does something is better than a column that lies.
     *
     * @return array{kind:string, ft:int, aoe:?array{shape:string, ft:int}}
     */
    public static function spellRange(string $rangeText, int $reachesBackRank = 1): array
    {
        $text = trim($rangeText);

        $aoe = null;
        if (preg_match('/\((\d+)\s*-?\s*(?:foot|feet|ft\.?)\s*(cone|radius|sphere|cube|line)\)/i', $text, $m)) {
            $aoe = ['shape' => strtolower($m[2]), 'ft' => (int) $m[1]];
        }

        if (preg_match('/^self/i', $text)) {
            return ['kind' => 'self', 'ft' => 0, 'aoe' => $aoe];
        }
        if (preg_match('/^touch/i', $text)) {
            return ['kind' => 'touch', 'ft' => 5, 'aoe' => $aoe];
        }
        if (preg_match('/(\d+)\s*(?:foot|feet|ft\.?)/i', $text, $m)) {
            return ['kind' => 'ranged', 'ft' => (int) $m[1], 'aoe' => $aoe];
        }

        return $reachesBackRank === 0
            ? ['kind' => 'touch', 'ft' => 5, 'aoe' => $aoe]
            : ['kind' => 'ranged', 'ft' => 60, 'aoe' => $aoe];
    }

    /**
     * A monster's walking speed in feet, from `"30 ft."` and its variants.
     *
     * Zero is a real answer — `the_growth` does not move — so an unparseable
     * value returns the SRD default of thirty rather than nothing, and only a
     * written zero stops a creature moving.
     */
    public static function parseSpeedFt(?string $text): int
    {
        if ($text === null || $text === '') {
            return 30;
        }
        if (preg_match('/(\d+)/', $text, $m)) {
            return max(0, (int) $m[1]);
        }
        return 30;
    }

    public static function spellcastingAbility(string $class): ?string
    {
        return self::SPELLCASTING_ABILITY[self::classKey($class)] ?? null;
    }

    /**
     * The DC a target must beat to shrug off this caster's spell.
     *
     * A class with no spellcasting ability still returns a number rather than
     * null, because the alternative is every caller guarding against null on a
     * value that only reaches a spell that a non-caster could not have cast.
     */
    public static function spellSaveDc(array $actor): int
    {
        $norm = self::normalise($actor);
        return 8 + $norm['prof'] + self::castingMod($norm);
    }

    public static function spellAttackBonus(array $actor): int
    {
        $norm = self::normalise($actor);
        return $norm['prof'] + self::castingMod($norm);
    }

    /**
     * Spell slots by slot level, e.g. [1 => 4, 2 => 3, 3 => 0].
     *
     * Levels above MAX_LEVEL clamp rather than throw: a character who somehow
     * outgrows the authored content should keep casting, not crash mid-fight.
     *
     * @return array<int, int>
     */
    public static function slotTable(string $class, int $level): array
    {
        $level = max(1, min(self::MAX_LEVEL, $level));
        $key = self::classKey($class);

        if ($key === 'Warlock') {
            $row = self::WARLOCK_SLOTS[$level];
        } elseif ($key === 'Paladin' || $key === 'Ranger') {
            $row = self::HALF_CASTER_SLOTS[$level];
        } elseif (isset(self::SPELLCASTING_ABILITY[$key])) {
            $row = self::FULL_CASTER_SLOTS[$level];
        } else {
            $row = array_fill(0, self::MAX_SLOT_LEVEL, 0);
        }

        $out = [];
        foreach ($row as $i => $count) {
            $out[$i + 1] = $count;
        }
        return $out;
    }

    /**
     * What a caster has left to cast with, level by level.
     *
     * The slot table crossed with `spell_slots_json`, which records only what
     * has been spent. Lives here because it is arithmetic over a rules table
     * and it now has two readers — the game's character sheet, served by the
     * API, and the printed one — and the comment in api/index.php explaining
     * why the client is not allowed to do this sum itself applies with more
     * force to a second copy on the server.
     *
     * Levels with no slots are left out entirely rather than returned as zero:
     * a level-2 Cleric has no 2nd-level row on their sheet, they have a blank
     * space where one will be.
     *
     * @param array<string, mixed> $actor a `characters` row
     * @return array<int, array{level:int, have:int, used:int, left:int}>
     */
    public static function slotState(array $actor): array
    {
        $expended = json_decode((string) ($actor['spell_slots_json'] ?? ''), true);
        if (!is_array($expended)) {
            $expended = [];
        }

        $out = [];
        foreach (self::slotTable((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1)) as $level => $have) {
            $have = (int) $have;
            if ($have <= 0) {
                continue;
            }
            // Both key shapes: JSON objects come back with string keys, and the
            // engine has written integers.
            $used = (int) ($expended[(string) $level] ?? $expended[$level] ?? 0);
            $used = max(0, min($have, $used));
            $out[] = [
                'level' => (int) $level,
                'have'  => $have,
                'used'  => $used,
                'left'  => $have - $used,
            ];
        }
        return $out;
    }

    /**
     * Damage after the defender's resistances, immunities and vulnerabilities.
     *
     * Immunity wins outright. Resistance is applied before vulnerability so
     * that a creature with both halves first and doubles second — the SRD does
     * not name an order, and this one at least makes the pair round the same
     * way every time instead of depending on which JSON column was read first.
     * Halving rounds down, as the SRD says.
     *
     * @return array{amount:int, note:?string}
     */
    public static function damageAfterDefenses(int $amount, string $damageType, array $defender): array
    {
        $amount = max(0, $amount);
        $type = strtolower(trim($damageType));

        if ($type === '' || $amount === 0) {
            return ['amount' => $amount, 'note' => null];
        }

        if (in_array($type, self::defenceList($defender, 'immunities'), true)) {
            return ['amount' => 0, 'note' => 'immune to ' . $type];
        }

        $notes = [];
        // Petrified resists everything, and it is the only condition in the
        // SRD that touches damage rather than dice. Checked before the type
        // lists and made exclusive with them, because stone is not resistant
        // twice over: a petrified creature that also resists fire takes a
        // quarter of nothing extra.
        if (Conditions::resistsAllDamage($defender)) {
            $amount = intdiv($amount, 2);
            $notes[] = 'stone';
        } elseif (in_array($type, self::defenceList($defender, 'resistances'), true)) {
            $amount = intdiv($amount, 2);
            $notes[] = 'resistant to ' . $type;
        }
        if (in_array($type, self::defenceList($defender, 'vulnerabilities'), true)) {
            $amount *= 2;
            $notes[] = 'vulnerable to ' . $type;
        }

        return [
            'amount' => $amount,
            'note'   => $notes === [] ? null : implode(', ', $notes),
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * One reading of an actor whichever shape it arrived in.
     *
     * The combat engine hands over combatants with short keys and `prof`; the
     * dialogue engine hands over `characters` rows with spelled-out columns and
     * `proficiency_bonus`. A rule that silently read 10 for every ability
     * because it was given the other shape would look like bad luck rather than
     * a bug, so both are resolved in exactly one place.
     */
    private static function normalise(array $actor): array
    {
        $out = [];
        foreach (self::LONG_ABILITIES as $short => $long) {
            $out[$short] = (int) ($actor[$short] ?? $actor[$long] ?? 10);
        }

        $out['level'] = max(1, (int) ($actor['level'] ?? 1));

        $prof = $actor['prof'] ?? $actor['proficiency_bonus'] ?? null;
        $out['prof'] = $prof === null ? self::proficiencyBonus($out['level']) : (int) $prof;

        $out['class'] = self::classKey((string) ($actor['class'] ?? ''));
        $out['background'] = (string) ($actor['background'] ?? '');

        return $out;
    }

    private static function totalled(array $parts): array
    {
        $total = 0;
        foreach ($parts as $part) {
            $total += (int) $part['value'];
        }
        return ['total' => $total, 'parts' => $parts];
    }

    private static function castingMod(array $norm): int
    {
        $ability = self::SPELLCASTING_ABILITY[$norm['class']] ?? null;
        return $ability === null ? 0 : self::abilityMod($norm[$ability]);
    }

    /** 'Sleight of Hand', 'sleight-of-hand' and 'SLEIGHT_OF_HAND' are one skill. */
    private static function skillKey(string $skill): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($skill)));
    }

    private static function classKey(string $class): string
    {
        return ucfirst(strtolower(trim($class)));
    }

    private static function abilityKey(string $ability): string
    {
        $ability = strtolower(trim($ability));
        if (isset(self::ABILITY_NAMES[$ability])) {
            return $ability;
        }
        $short = array_search($ability, self::LONG_ABILITIES, true);
        if ($short !== false) {
            return (string) $short;
        }
        throw new InvalidArgumentException("Unknown ability: {$ability}");
    }

    /**
     * Whether this actor is proficient in a skill.
     *
     * An explicit `skills` list on the actor is authoritative and shuts off the
     * guesswork below; supply one as soon as characters record their choices.
     *
     * Takes the already-normalised actor as well as the raw one because both
     * callers have it to hand and normalising twice for one lookup is waste.
     * isSkillProficient() is the public form for anyone who does not.
     */
    private static function skillProficient(array $actor, array $norm, string $skill): bool
    {
        // A feat grant is checked before anything else, and outside the
        // explicit/derived split below, because it is neither. Observant hands
        // out Perception and Investigation on top of whatever the character
        // already had; if it were folded into the explicit list it would be
        // shut off for exactly the characters that have one.
        foreach (Feats::strings($actor, 'skill_proficiency') as $granted) {
            if (self::skillKey($granted) === $skill) {
                return true;
            }
        }

        // Keen Senses is the racial copy of the same: Perception on top of
        // whatever the character already had, outside the explicit/derived
        // split for the same reason the feat grants are.
        if ($skill === 'perception' && self::raceFeature($actor, 'keen_senses')) {
            return true;
        }

        // Quarry-Built is Keen Senses' shape for Athletics, and sits here for
        // the third time for the same reason: a Sarsen rogue whose explicit
        // skills list says stealth and sleight of hand would otherwise return
        // false at the `is_array($explicit)` branch below and lose the trait
        // exactly on the characters who wrote their proficiencies down.
        if ($skill === 'athletics' && self::raceFeature($actor, 'quarry_built')) {
            return true;
        }

        $explicit = $actor['skills'] ?? null;
        if (is_string($explicit)) {
            $explicit = array_filter(array_map('trim', explode(',', $explicit)), 'strlen');
        }
        if (is_array($explicit)) {
            foreach ($explicit as $name) {
                if (self::skillKey((string) $name) === $skill) {
                    return true;
                }
            }
            return false;
        }

        return in_array($skill, self::defaultSkills($norm['class'], $norm['background']), true);
    }

    /**
     * The proficiencies a character would have taken had anyone asked them.
     *
     * Background skills are fixed grants and so are certain; the class picks
     * are the first few of its list. Taking the whole class list instead would
     * make a Bard proficient in all eighteen skills, since the SRD lets a Bard
     * choose from every one of them.
     *
     * @return string[]
     */
    private static function defaultSkills(string $class, string $background): array
    {
        $skills = self::backgroundSkills($background);
        $remaining = self::CLASS_SKILL_CHOICES[$class] ?? 0;

        foreach (self::CLASS_SKILLS[$class] ?? [] as $skill) {
            if ($remaining <= 0) {
                break;
            }
            if (in_array($skill, $skills, true)) {
                continue;
            }
            $skills[] = $skill;
            $remaining--;
        }

        return $skills;
    }

    /** A stat block's own saving throw bonus for one ability, if it states one. */
    private static function statedSave(array $actor, string $ability): ?int
    {
        $saves = $actor['saves'] ?? null;
        if (!is_array($saves)) {
            $saves = json_decode((string) ($actor['saves_json'] ?? ''), true);
        }
        if (!is_array($saves)) {
            return null;
        }

        foreach ($saves as $key => $bonus) {
            if (is_string($key) && strtolower($key) === $ability && is_numeric($bonus)) {
                return (int) $bonus;
            }
        }
        return null;
    }

    /**
     * One of the defender's damage-type lists, lowercased.
     *
     * Accepts both the raw `*_json` column and an already-decoded array, since
     * combat state carries the decoded form and a monster row does not.
     *
     * @return string[]
     */
    private static function defenceList(array $defender, string $which): array
    {
        $list = $defender[$which] ?? null;
        if (!is_array($list)) {
            $list = json_decode((string) ($defender[$which . '_json'] ?? ''), true);
        }
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $type) {
            if (is_string($type)) {
                $out[] = strtolower(trim($type));
            }
        }
        return $out;
    }
}
