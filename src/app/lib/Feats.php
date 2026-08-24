<?php
/**
 * Feats: the other thing a character may take when a level offers an increase.
 *
 * Why there are twenty-seven of them now, and one before
 * -----------------------------------------------------
 * This file used to hold Grappler and nothing else, and argued at length that
 * Grappler was the whole of the available material. That was true of SRD 5.1,
 * which prints exactly one feat; the large feat lists on the open web are
 * third-party OGL content, each entry carrying a copyright notice that OGL 1.0a
 * section 15 would require us to reproduce, and adopting them would have been a
 * licensing decision dressed up as a content one.
 *
 * SRD 5.2, published in 2025, changes the answer. It carries the full modern
 * feat list and it is released under Creative Commons Attribution 4.0 — the
 * same licence this project already names for SRD material in about.php and at
 * the head of sql/schema.sql. Attribution is the whole of the obligation, and
 * the attribution is already on the page. No section 15 chain, no third-party
 * notices, nothing to reproduce. So the catalogue below is SRD 5.2 content used
 * under CC-BY-4.0, and about.php now says so alongside the 5.1 notice.
 *
 * Two rulesets, one progression
 * -----------------------------
 * SRD 5.2 is the 2024 ruleset and this game is built on the 2014 one: hit dice
 * at every level, ability increases at 4/8/12/16/19 (Rules::ASI_LEVELS), no
 * weapon masteries, no backgrounds that hand out a feat at first level. The
 * feats have been brought across; the progression has NOT been rebuilt around
 * them, because that would be a different game rather than a bigger catalogue.
 *
 * What that costs, and how it is paid:
 *
 *   - 5.2 sorts feats into Origin, General, Fighting Style and Epic Boon. The
 *     `category` field carries that sorting so the level-up screen can group by
 *     it, but the catalogue itself is flat — every entry is reachable from the
 *     same list and gated only by its own prerequisites.
 *   - Origin feats are 5.2's first-level grants. Here they are simply the
 *     cheaper end of the same list, offered at every increase level. They carry
 *     no level floor, so they can also be granted outright — which is what
 *     `companions.starting_feats_json` is for. A companion authored with an
 *     Origin feat is the closest this game gets to 5.2's background feat.
 *
 *     The character creator does NOT offer one, deliberately and for now: it is
 *     a five-step wizard with a name, a race, a class, six ability scores and a
 *     face to get through, and a sixth question would be the first thing a new
 *     player meets that needs the rest of the game explained first. The
 *     consequence is worth stating plainly, because it is an asymmetry: an
 *     authored companion can start with an Origin feat and a player-made
 *     character cannot. Blanking `feats` on the four companion templates is the
 *     one-line way to remove it, and adding a step to create.js is the other.
 *   - General feats carry 5.2's "level 4 or higher" requirement as `min_level`,
 *     which every ASI level in Rules::ASI_LEVELS already satisfies. It is stated
 *     anyway, because a feat granted at creation or at recruitment does not come
 *     through a level-up and must still be refused.
 *   - Fighting Style feats require the Fighting Style feature, which in the SRD
 *     belongs to the Fighter, the Paladin and the Ranger. `classes` says so.
 *   - Epic Boons are omitted entirely — none is implemented. They require level
 *     19, which USED to put them outside Rules::MAX_LEVEL and now does not, so
 *     this is a real gap rather than a rule about the cap: a character reaching
 *     19 gets an ordinary ability increase where the SRD offers a boon.
 *   - The Ability Score Improvement is itself a feat in 5.2, and it is one here:
 *     `ability_score_improvement` is an ordinary catalogue entry, marked
 *     `repeatable` so that taking it at level 4 does not remove it from the
 *     level 8 list. That is why the level-up screen shows one list rather than
 *     an ASI-or-feat toggle — the toggle was 5.1's shape, not this one's.
 *
 * Where a feat asks the player to choose
 * --------------------------------------
 * Several 5.2 feats say "an ability of your choice" or "three skills of your
 * choice". The level-up ceremony has one question per step and nowhere to ask a
 * follow-up, so the catalogue resolves those two ways, and never by inventing a
 * third:
 *
 *   - One entry per named choice, where the choices are few and each is a
 *     different feat in practice. Resilient is six entries, one per ability,
 *     and each says which one it is in its own name.
 *   - The choice fixed and stated in the description, where splitting would
 *     multiply the list past reading. Observant names the two skills it grants
 *     rather than offering three to pick from.
 *
 * Feats whose only content is a choice this game cannot pose — Skilled's three
 * free skills, Magic Initiate's and Fey Touched's borrowed spells — are left
 * out rather than fixed arbitrarily. Picking a wizard's cantrips for them is
 * worse than not offering the feat.
 *
 * And a third kind is left out: the feats whose whole subject this game does
 * not have. Sentinel, Polearm Master and Defensive Duelist are reactions and
 * opportunity attacks, and a fight on two ranks has no ground to leave.
 * Sharpshooter is long range and cover. Mounted Combatant is a horse. Poisoner
 * and Crafter are crafting. Heavy Armour Master is heavy armour, of which the
 * content contains none. Elemental Adept turns on a resistance no creature in
 * `monsters` has. Each of them would have joined the list as a paragraph and a
 * shrug, and a level-up spent on one is a level-up the player cannot have back.
 *
 * Effects, and the honesty rule
 * -----------------------------
 * `effects` is a bag of keys, and a key does nothing until something reads it.
 * The table below is the whole contract; every key in it is read by the file
 * named beside it, and adding a key nothing reads produces a feat that is a
 * paragraph of text and no game.
 *
 *   ability_points                  LevelUpService::chooseAsi
 *   ability_increase                LevelUpService::grantFeat
 *   ability_increase_casting        LevelUpService::grantFeat
 *   hp_per_level                    LevelUpService::grantFeat, ::rollHp
 *   initiative_bonus                CombatEngine::startEncounter
 *   initiative_advantage            CombatEngine::startEncounter
 *   save_proficiency                Rules::savingThrow
 *   skill_proficiency               Rules::skillCheck
 *   check_advantage                 CheckService::dieMode
 *   concentration_save_advantage    CombatEngine::concentrationCheck
 *   luck_points                     CheckService::boosts
 *   attack_bonus                    CombatEngine::weaponFor
 *   damage_bonus                    CombatEngine::weaponFor
 *   damage_bonus_proficiency        CombatEngine::weaponFor
 *   unarmed_damage                  CombatEngine::weaponFor
 *   damage_reroll_below             CombatEngine::resolveAttack
 *   damage_reroll_best              CombatEngine::resolveAttack
 *   damage_reroll_one               CombatEngine::resolveAttack
 *   crit_extra_die                  CombatEngine::resolveAttack
 *   heal_reroll_ones                CombatEngine::healingRoll
 *   ac_bonus_armored                CharacterGenerator::recalculateAC
 *   medium_armor_dex_cap            CharacterGenerator::recalculateAC
 *   attack_advantage_vs             Conditions::attackContext
 *   ignore_blinded_melee            Conditions::attackContext
 *
 * Two fields exist to say when the text and the game disagree, and both are
 * shown to the player rather than kept as a comment:
 *
 *   `inert`    nothing in this game reads any of this feat's effects. No entry
 *              carries it today — a feat that would have earned it was left out
 *              of the catalogue instead, which is the better answer while there
 *              is a choice about whether to carry it at all. The field stays
 *              because the day a system is removed under a feat that depended on
 *              it, saying so is cheaper than deleting the feat out from under
 *              the characters who took it.
 *   `partial`  part of the feat works and part of it has nothing to act on,
 *              usually because it needs a reaction, a movement grid or a piece
 *              of equipment this game does not model. Grappler is the standing
 *              example: its +1 Strength is real and its advantage has nothing to
 *              trigger on, because nothing in this game grapples.
 *
 * Saying so on the card is deliberate. A feat that quietly does nothing is a
 * level-up the player spent and cannot get back.
 */

declare(strict_types=1);

class Feats
{
    /**
     * The 5.2 categories this game carries, in the order the screen shows them.
     *
     * The labels carry the requirement that the whole group shares, so that
     * every entry inside it does not have to repeat it. "Requires level 4+" is
     * useful once above twenty feats and noise printed twenty times.
     */
    public const CATEGORIES = [
        'special'        => 'Ability Score Improvement',
        'origin'         => 'Origin feats',
        'fighting_style' => 'Fighting Style feats — Fighter, Paladin and Ranger',
        'general'        => 'General feats — level 4 and up',
    ];

    /** Classes with the Fighting Style feature, and so with its feats. */
    private const FIGHTING_STYLE_CLASSES = ['Fighter', 'Paladin', 'Ranger'];

    /**
     * @var array<string, array{
     *     name: string, category: string, source: string,
     *     prerequisite: array<string,int>, min_level?: int, classes?: string[],
     *     spellcaster?: bool, repeatable?: bool,
     *     description: string, effects: array<string, mixed>,
     *     inert?: string, partial?: string
     * }>
     */
    public const CATALOG = [

        // ===================================================================
        // The increase itself, which 5.2 makes a feat like any other.
        // ===================================================================

        'ability_score_improvement' => [
            'name'         => 'Ability Score Improvement',
            'category'     => 'special',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            // The only entry that may be taken again. Everything else is a
            // once-only; this is the thing a character does at every increase
            // level they do not want anything else.
            'repeatable'   => true,
            'description'  => 'Raise one ability score by 2, or two ability scores by 1 each. '
                . 'Nothing may go above 20.',
            'effects'      => ['ability_points' => 2],
        ],

        // ===================================================================
        // Origin feats — 5.2 grants these at first level from a background.
        // Here they are the cheap end of the same list, and the thing a
        // companion template may be authored with.
        // ===================================================================

        'alert' => [
            'name'         => 'Alert',
            'category'     => 'origin',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'description'  => 'You are hard to catch unready. Add your proficiency bonus to your '
                . 'initiative roll.',
            'effects'      => ['initiative_bonus' => 'proficiency'],
            'partial'      => 'The initiative bonus applies; swapping initiative with an ally needs '
                . 'a turn order the player can edit, which this does not have.',
        ],

        'healer' => [
            'name'         => 'Healer',
            'category'     => 'origin',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'description'  => 'You know how to stop bleeding. Whenever you roll a die to restore '
                . 'hit points, a 1 is rerolled once and the new roll stands.',
            'effects'      => ['heal_reroll_ones' => true],
            'partial'      => 'The rerolls apply to every healing die you roll. The healer\'s kit '
                . 'action has no kit to use — there is no such item in the game.',
        ],

        'lucky' => [
            'name'         => 'Lucky',
            'category'     => 'origin',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'description'  => 'You have luck points equal to your proficiency bonus, refilled by a '
                . 'long rest. Spend one on any d20 roll to make it with advantage.',
            'effects'      => ['luck_points' => 'proficiency'],
            'partial'      => 'Spending a point for advantage works and the points come back on a '
                . 'long rest. Spending one to hamper an attack aimed at you would need a reaction, '
                . 'and party members do not take reactions here.',
        ],

        'savage_attacker' => [
            'name'         => 'Savage Attacker',
            'category'     => 'origin',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'description'  => 'Once on each of your turns, when you hit with a weapon, you roll its '
                . 'damage dice twice and keep whichever total you prefer.',
            'effects'      => ['damage_reroll_best' => true],
        ],

        'tavern_brawler' => [
            'name'         => 'Tavern Brawler',
            'category'     => 'origin',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'description'  => 'Your unarmed strike deals 1d4 damage, and a 1 on that die is '
                . 'rerolled once.',
            'effects'      => [
                'unarmed_damage'      => '1d4',
                'damage_reroll_below' => ['unarmed' => 1],
            ],
            'partial'      => 'The damage and the reroll apply. Shoving a creature back five feet '
                . 'has nowhere to shove them — combat here is two ranks, not a floor.',
        ],

        'tough' => [
            'name'         => 'Tough',
            'category'     => 'origin',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'description'  => 'Your hit point maximum rises by 2 for every level you have, and by '
                . 'another 2 each time you gain one.',
            'effects'      => ['hp_per_level' => 2],
        ],

        // ===================================================================
        // Fighting Style feats — the SRD gives the Fighting Style feature to
        // the Fighter, the Paladin and the Ranger, so those are the classes
        // that may take these.
        // ===================================================================

        'archery' => [
            'name'         => 'Fighting Style: Archery',
            'category'     => 'fighting_style',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'classes'      => self::FIGHTING_STYLE_CLASSES,
            'description'  => 'You gain a +2 bonus to attack rolls made with ranged weapons.',
            'effects'      => ['attack_bonus' => ['ranged' => 2]],
        ],

        'blind_fighting' => [
            'name'         => 'Fighting Style: Blind Fighting',
            'category'     => 'fighting_style',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'classes'      => self::FIGHTING_STYLE_CLASSES,
            'description'  => 'You fight by feel within ten feet. Being blinded costs you nothing '
                . 'against anything you can reach in melee.',
            'effects'      => ['ignore_blinded_melee' => true],
            'partial'      => 'The blindsight is worth exactly what the Blinded condition would '
                . 'have cost you in melee; there is no hiding and no darkness to see through '
                . 'otherwise.',
        ],

        'defense' => [
            'name'         => 'Fighting Style: Defense',
            'category'     => 'fighting_style',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'classes'      => self::FIGHTING_STYLE_CLASSES,
            'description'  => 'While you are wearing armour, your armour class is 1 higher.',
            'effects'      => ['ac_bonus_armored' => 1],
        ],

        'dueling' => [
            'name'         => 'Fighting Style: Duelling',
            'category'     => 'fighting_style',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'classes'      => self::FIGHTING_STYLE_CLASSES,
            'description'  => 'When you fight with a melee weapon in one hand and nothing in the '
                . 'other, you deal 2 extra damage with it.',
            'effects'      => ['damage_bonus' => ['one_handed' => 2]],
        ],

        'great_weapon_fighting' => [
            'name'         => 'Fighting Style: Great Weapon Fighting',
            'category'     => 'fighting_style',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'classes'      => self::FIGHTING_STYLE_CLASSES,
            'description'  => 'Rolling a 1 or a 2 for damage with a two-handed melee weapon, you '
                . 'reroll the die and take the new number.',
            'effects'      => ['damage_reroll_below' => ['two_handed' => 2]],
            'partial'      => 'The SRD extends this to a versatile weapon held in two hands. A '
                . 'character here has one grip and one damage die — a longsword rolls the 1d8 it '
                . 'is written with — so there is no two-handed grip on it to reward.',
        ],

        'unarmed_fighting' => [
            'name'         => 'Fighting Style: Unarmed Fighting',
            'category'     => 'fighting_style',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'classes'      => self::FIGHTING_STYLE_CLASSES,
            'description'  => 'Your unarmed strike deals 1d6 damage.',
            'effects'      => ['unarmed_damage' => '1d6'],
            'partial'      => 'The larger die applies. Crushing a grappled creature at the start of '
                . 'your turn needs a grapple, and nothing in this game grapples.',
        ],

        // ===================================================================
        // General feats — 5.2 requires level 4 for all of them.
        // ===================================================================

        'actor' => [
            'name'         => 'Actor',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Charisma +1, and you have advantage on Deception and Performance '
                . 'checks.',
            'effects'      => [
                'ability_increase' => ['charisma' => 1],
                'check_advantage'  => ['deception', 'performance'],
            ],
            'partial'      => 'The SRD limits the advantage to passing yourself off as someone '
                . 'else, and nothing here can tell when you are doing that — so it is granted on '
                . 'those two skills outright. Mimicking a voice you have heard has no check to '
                . 'make.',
        ],

        'grappler' => [
            'name'         => 'Grappler',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => ['strength' => 13],
            'min_level'    => 4,
            'description'  => 'Strength +1, and you have advantage on attack rolls against a '
                . 'creature you are grappling.',
            'effects'      => [
                'ability_increase'    => ['strength' => 1],
                'attack_advantage_vs' => 'grappled',
            ],
            // Said out loud rather than discovered later. Nothing in combat
            // applies the `grappled` condition — there is no grapple action, no
            // monster does it, no spell causes it — so the advantage has
            // nothing to trigger on. The +1 Strength is real, which is the only
            // reason this is `partial` and no longer `inert`. The day a grapple
            // action exists, the rest starts working with no change here.
            'partial'      => 'The +1 Strength applies. Nothing in combat grapples yet, so the '
                . 'advantage has nothing to trigger on.',
        ],

        'great_weapon_master' => [
            'name'         => 'Great Weapon Master',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Strength +1, and when you hit with a heavy weapon you add your '
                . 'proficiency bonus to the damage.',
            'effects'      => [
                'ability_increase'         => ['strength' => 1],
                'damage_bonus_proficiency' => ['heavy'],
            ],
            'partial'      => 'The extra damage applies on every hit with a heavy weapon. The '
                . 'follow-up swing after a critical or a kill would be a second attack, and a '
                . 'character gets one here.',
        ],

        'medium_armor_master' => [
            'name'         => 'Medium Armour Master',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Dexterity +1, and medium armour lets you add up to 3 of your '
                . 'Dexterity modifier instead of the usual 2.',
            'effects'      => [
                'ability_increase'     => ['dexterity' => 1],
                'medium_armor_dex_cap' => 3,
            ],
            'partial'      => 'The Dexterity cap applies. Medium armour imposes no stealth penalty '
                . 'here for it to lift.',
        ],

        'observant' => [
            'name'         => 'Observant',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Wisdom +1, and you are proficient in Perception and Investigation.',
            'effects'      => [
                'ability_increase'  => ['wisdom' => 1],
                'skill_proficiency' => ['perception', 'investigation'],
            ],
            'partial'      => 'The SRD lets you pick which skills; the two named above are the '
                . 'ones this catalogue fixes on, because the ceremony has nowhere to ask.',
        ],

        'piercer' => [
            'name'         => 'Piercer',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Dexterity +1. Once on each of your turns you may reroll one damage '
                . 'die of a hit that deals piercing damage, and a critical with piercing damage '
                . 'rolls one extra die.',
            'effects'      => [
                'ability_increase'  => ['dexterity' => 1],
                'damage_reroll_one' => ['piercing'],
                'crit_extra_die'    => ['piercing'],
            ],
        ],

        'skulker' => [
            'name'         => 'Skulker',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => ['dexterity' => 13],
            'min_level'    => 4,
            'description'  => 'Dexterity +1, and you roll initiative with advantage.',
            'effects'      => [
                'ability_increase'     => ['dexterity' => 1],
                'initiative_advantage' => true,
            ],
            'partial'      => 'The advantage on initiative applies. Shooting from hiding and seeing '
                . 'in the dark need a hiding place and a light level, and a rank has neither.',
        ],

        'war_caster' => [
            'name'         => 'War Caster',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'spellcaster'  => true,
            'description'  => 'Your spellcasting ability rises by 1, and you have advantage on '
                . 'Constitution saves made to keep concentration.',
            'effects'      => [
                'ability_increase_casting'     => 1,
                'concentration_save_advantage' => true,
            ],
            'partial'      => 'The increase and the concentration advantage both apply. Casting a '
                . 'spell as an opportunity attack needs opportunity attacks, and there are none '
                . 'without ground to give.',
        ],

        // Resilient, once per ability. The SRD asks the player to name one;
        // splitting it is how that question gets asked without a second step,
        // and each entry says in its own name which ability it is.
        'resilient_strength' => [
            'name'         => 'Resilient (Strength)',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Strength +1, and you become proficient in Strength saving throws.',
            'effects'      => [
                'ability_increase' => ['strength' => 1],
                'save_proficiency' => ['str'],
            ],
        ],
        'resilient_dexterity' => [
            'name'         => 'Resilient (Dexterity)',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Dexterity +1, and you become proficient in Dexterity saving throws.',
            'effects'      => [
                'ability_increase' => ['dexterity' => 1],
                'save_proficiency' => ['dex'],
            ],
        ],
        'resilient_constitution' => [
            'name'         => 'Resilient (Constitution)',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Constitution +1, and you become proficient in Constitution saving '
                . 'throws — the save that keeps a spell you are concentrating on.',
            'effects'      => [
                'ability_increase' => ['constitution' => 1],
                'save_proficiency' => ['con'],
            ],
        ],
        'resilient_intelligence' => [
            'name'         => 'Resilient (Intelligence)',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Intelligence +1, and you become proficient in Intelligence saving '
                . 'throws.',
            'effects'      => [
                'ability_increase' => ['intelligence' => 1],
                'save_proficiency' => ['int'],
            ],
        ],
        'resilient_wisdom' => [
            'name'         => 'Resilient (Wisdom)',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Wisdom +1, and you become proficient in Wisdom saving throws.',
            'effects'      => [
                'ability_increase' => ['wisdom' => 1],
                'save_proficiency' => ['wis'],
            ],
        ],
        'resilient_charisma' => [
            'name'         => 'Resilient (Charisma)',
            'category'     => 'general',
            'source'       => 'SRD 5.2',
            'prerequisite' => [],
            'min_level'    => 4,
            'description'  => 'Charisma +1, and you become proficient in Charisma saving throws.',
            'effects'      => [
                'ability_increase' => ['charisma' => 1],
                'save_proficiency' => ['cha'],
            ],
        ],
    ];

    /** @return array<string, array> every feat, keyed as in CATALOG */
    public static function all(): array
    {
        return self::CATALOG;
    }

    public static function get(string $key): ?array
    {
        return self::CATALOG[self::key($key)] ?? null;
    }

    public static function exists(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function key(string $key): string
    {
        return strtolower(trim($key));
    }

    // =======================================================================
    // What a character has
    // =======================================================================

    /**
     * The feats a character has taken, as keys.
     *
     * Reads two shapes on purpose. A `characters` row carries `feats_json`, the
     * raw column; a combat combatant carries `feats`, the decoded list stamped
     * on at the start of the fight. Combat state is rebuilt from the row once
     * and then never touches the database again, so the second shape is the
     * only one the attack roll ever sees — and a reader that understood only
     * the first would make every feat silently inert in combat, which is
     * exactly the kind of failure that looks like bad luck.
     *
     * @return string[]
     */
    public static function taken(array $character): array
    {
        $raw = $character['feats'] ?? $character['feats_json'] ?? null;

        if (is_string($raw)) {
            $raw = $raw === '' ? [] : json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && isset(self::CATALOG[self::key($entry)])) {
                $out[] = self::key($entry);
            }
        }
        return array_values(array_unique($out));
    }

    public static function has(array $character, string $key): bool
    {
        return in_array(self::key($key), self::taken($character), true);
    }

    /** The catalogue entries a character holds, for a sheet or an API. */
    public static function detail(array $character): array
    {
        $out = [];
        foreach (self::taken($character) as $key) {
            $feat = self::CATALOG[$key];
            $out[] = [
                'key'         => $key,
                'name'        => $feat['name'],
                'category'    => $feat['category'],
                'description' => $feat['description'],
                'note'        => $feat['inert'] ?? $feat['partial'] ?? null,
            ];
        }
        return $out;
    }

    // =======================================================================
    // Reading effects
    //
    // Four accessors rather than one, because the effects do not all combine
    // the same way and a single get() would leave every caller deciding. A
    // bonus adds up, a list concatenates, a flag is true if any feat sets it,
    // and a cap takes the best on offer.
    // =======================================================================

    /**
     * A numeric effect summed across every feat that carries it.
     *
     * `proficiency` is accepted as a value and resolved against the character's
     * own bonus, because Alert and Lucky are both written in the SRD as "equal
     * to your proficiency bonus" and neither has a number to store.
     */
    public static function sum(array $character, string $effect, int $proficiency = 0): int
    {
        $total = 0;
        foreach (self::taken($character) as $key) {
            $value = self::CATALOG[$key]['effects'][$effect] ?? null;
            if ($value === 'proficiency') {
                $total += $proficiency;
            } elseif (is_numeric($value)) {
                $total += (int) $value;
            }
        }
        return $total;
    }

    /**
     * The largest value any of the character's feats gives a scalar effect.
     *
     * Distinct from sum() because some numbers are caps rather than bonuses:
     * two feats that each raise the Dexterity a suit of armour will carry do
     * not raise it twice, they raise it to whichever is higher.
     */
    public static function maxOf(array $character, string $effect): int
    {
        $best = 0;
        foreach (self::taken($character) as $key) {
            $value = self::CATALOG[$key]['effects'][$effect] ?? null;
            if (is_numeric($value)) {
                $best = max($best, (int) $value);
            }
        }
        return $best;
    }

    /** True when any feat the character holds sets this effect at all. */
    public static function flag(array $character, string $effect): bool
    {
        foreach (self::taken($character) as $key) {
            if (!empty(self::CATALOG[$key]['effects'][$effect])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every string an effect names, across all the character's feats.
     *
     * Accepts a bare string as a one-item list so `attack_advantage_vs` can be
     * written as `'grappled'` rather than `['grappled']`.
     *
     * @return string[]
     */
    public static function strings(array $character, string $effect): array
    {
        $out = [];
        foreach (self::taken($character) as $key) {
            $value = self::CATALOG[$key]['effects'][$effect] ?? null;
            if (is_string($value)) {
                $out[] = strtolower($value);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $out[] = strtolower($item);
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * A keyed numeric effect, merged across feats, keeping the largest value
     * for any key two feats both name.
     *
     * Largest rather than summed: these are thresholds and caps — the die below
     * which Great Weapon Fighting rerolls, the Dexterity a suit of medium
     * armour will carry — and two sources of a cap do not raise it twice.
     *
     * @return array<string, int>
     */
    public static function best(array $character, string $effect): array
    {
        $out = [];
        foreach (self::taken($character) as $key) {
            $value = self::CATALOG[$key]['effects'][$effect] ?? null;
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $name => $number) {
                $name = strtolower((string) $name);
                $out[$name] = max($out[$name] ?? 0, (int) $number);
            }
        }
        return $out;
    }

    /**
     * The single best value of a scalar effect, or null if nobody sets it.
     *
     * Used by `unarmed_damage`, where a character holding both Tavern Brawler
     * and Unarmed Fighting punches with the better die rather than with both.
     */
    public static function bestDice(array $character, string $effect): ?string
    {
        $best = null;
        $bestAverage = 0.0;
        foreach (self::taken($character) as $key) {
            $value = self::CATALOG[$key]['effects'][$effect] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $average = self::averageOf($value);
            if ($best === null || $average > $bestAverage) {
                $best = $value;
                $bestAverage = $average;
            }
        }
        return $best;
    }

    /**
     * The mean of a bare NdX, for ranking two dice against each other.
     *
     * Deliberately not CombatEngine::expectedDamage, which does the same job:
     * everything else in this class is pure arithmetic over a constant array,
     * and reaching into the combat engine to compare 1d4 with 1d6 would make
     * the feat catalogue unloadable without it.
     */
    private static function averageOf(string $expr): float
    {
        if (!preg_match('/^(\d+)d(\d+)$/', strtolower(trim($expr)), $m)) {
            return 0.0;
        }
        return (int) $m[1] * ((int) $m[2] + 1) / 2;
    }

    // =======================================================================
    // Prerequisites
    // =======================================================================

    /**
     * Whether a character meets a feat's prerequisites.
     *
     * Checked on the server at the moment the feat is taken, not only when the
     * list is built: the list is a suggestion the client renders, and a client
     * is free to send anything.
     *
     * The four gates are the four kinds 5.2 actually uses — an ability minimum,
     * a level floor, a class feature stated as the classes that have it, and
     * "you have the Spellcasting feature". A feat already held is a fifth
     * refusal and lives here too, so that taking one twice is refused by the
     * same call that refuses taking one you do not qualify for.
     *
     * @param array<string, mixed> $character a row from `characters`
     * @return array{ok: bool, reason: ?string}
     */
    public static function qualifies(array $character, string $key): array
    {
        $feat = self::get($key);
        if ($feat === null) {
            return ['ok' => false, 'reason' => 'No such feat.'];
        }

        if (empty($feat['repeatable']) && self::has($character, $key)) {
            return ['ok' => false, 'reason' => $feat['name'] . ' has already been taken.'];
        }

        foreach ($feat['prerequisite'] as $ability => $minimum) {
            $has = (int) ($character[$ability] ?? 0);
            if ($has < $minimum) {
                return [
                    'ok'     => false,
                    'reason' => sprintf(
                        '%s needs %s %d; this character has %d.',
                        $feat['name'],
                        ucfirst($ability),
                        $minimum,
                        $has
                    ),
                ];
            }
        }

        $minLevel = (int) ($feat['min_level'] ?? 0);
        if ($minLevel > 0 && (int) ($character['level'] ?? 1) < $minLevel) {
            return [
                'ok'     => false,
                'reason' => sprintf('%s needs level %d.', $feat['name'], $minLevel),
            ];
        }

        if (!empty($feat['classes'])) {
            $class = trim((string) ($character['class'] ?? ''));
            $matched = false;
            foreach ($feat['classes'] as $allowed) {
                if (strcasecmp($allowed, $class) === 0) {
                    $matched = true;
                }
            }
            if (!$matched) {
                return [
                    'ok'     => false,
                    'reason' => sprintf(
                        '%s belongs to the Fighting Style feature — %s.',
                        $feat['name'],
                        self::listOf($feat['classes']) . ' only'
                    ),
                ];
            }
        }

        if (!empty($feat['spellcaster'])
            && Rules::spellcastingAbility((string) ($character['class'] ?? '')) === null) {
            return [
                'ok'     => false,
                'reason' => $feat['name'] . ' needs the Spellcasting feature.',
            ];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * The prerequisites this entry does not share with its whole category, as
     * one line a player can read.
     *
     * The level floor and the class list are deliberately absent: every General
     * feat wants level 4 and every Fighting Style feat wants one of three
     * classes, so those belong on the group heading in CATEGORIES and printing
     * them on each of twenty rows is noise. What is left is what actually
     * distinguishes one entry from its neighbours — an ability minimum, or
     * needing to be a caster.
     *
     * The refusal in qualifies() states every gate in full, because a player
     * looking at a greyed row wants the whole reason and not the part that is
     * unusual.
     */
    public static function prerequisiteText(string $key): string
    {
        $feat = self::get($key);
        if ($feat === null) {
            return '';
        }

        $parts = [];
        foreach ($feat['prerequisite'] as $ability => $minimum) {
            $parts[] = ucfirst((string) $ability) . ' ' . $minimum;
        }
        if (!empty($feat['spellcaster'])) {
            $parts[] = 'the Spellcasting feature';
        }
        return implode(', ', $parts);
    }

    /**
     * The feats on offer to a character, each marked with whether they qualify.
     *
     * A feat already taken is left out entirely — with one exception, and it is
     * the important one. The Ability Score Improvement is `repeatable`, because
     * in SRD 5.2 it is a feat like any other and a character is expected to take
     * it at several levels. Dropping it after the first would leave a level 8
     * character who took the increase at 4 with no way to take it again, which
     * is the version of this list that would look right and quietly remove the
     * most ordinary choice in the game.
     *
     * Ineligible feats are listed rather than hidden, greyed with the reason.
     * Knowing that Skulker wants Dexterity 13 is information; a list that
     * silently omitted it is a list that looks shorter than the game is.
     *
     * @return array<int, array>
     */
    public static function offerTo(array $character): array
    {
        $out = [];
        foreach (self::CATALOG as $key => $feat) {
            if (empty($feat['repeatable']) && self::has($character, $key)) {
                continue;
            }
            $check = self::qualifies($character, $key);
            $out[] = [
                'key'          => $key,
                'name'         => $feat['name'],
                'category'     => $feat['category'],
                'category_label' => self::CATEGORIES[$feat['category']] ?? $feat['category'],
                'description'  => $feat['description'],
                'source'       => $feat['source'],
                'prerequisite' => $feat['prerequisite'],
                'prerequisite_text' => self::prerequisiteText($key),
                'repeatable'   => !empty($feat['repeatable']),
                // The two points the Ability Score Improvement hands the player
                // to spend. Zero for everything else, which is what tells the
                // client that this entry needs no follow-up question.
                'ability_points' => (int) ($feat['effects']['ability_points'] ?? 0),
                'eligible'     => $check['ok'],
                'reason'       => $check['reason'],
                'inert'        => isset($feat['inert']),
                'note'         => $feat['inert'] ?? $feat['partial'] ?? null,
            ];
        }
        return $out;
    }

    /** "Fighter, Paladin or Ranger" — the Oxford-free house list. */
    private static function listOf(array $names): string
    {
        if (count($names) <= 1) {
            return (string) ($names[0] ?? '');
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' or ' . $last;
    }
}
