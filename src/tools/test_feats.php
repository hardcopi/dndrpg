<?php
/**
 * Test harness for feats: the catalogue, the prerequisites, taking one, and
 * every system that reads one.
 *
 * Run over HTTP — `curl -s http://localhost:8081/tools/test_feats.php` — or with
 * `docker compose exec -T php php /var/www/html/tools/test_feats.php`.
 *
 * Like test_levelup.php it NEEDS the database, and for the same reason: half of
 * what a feat does happens at the moment it is written to a character, and
 * there is nothing to check about that without somewhere to write it. It works
 * on scratch characters it creates, inside a transaction it rolls back, so it
 * can be run against a database somebody is playing on.
 *
 * The other half is arithmetic, and that half is driven directly against the
 * public statics rather than through a fight: a feat that changes a damage die
 * should be checkable without a party, a location and an encounter.
 *
 * What is deliberately NOT covered: the initiative bonus, which is one line
 * inside startEncounter() and cannot be reached without building a whole
 * encounter; and the level-up ceremony itself, which test_levelup.php owns.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$RESULTS = ['pass' => 0, 'fail' => 0];

function ok(string $name, bool $condition, string $detail = ''): void
{
    global $RESULTS;
    if ($condition) {
        $RESULTS['pass']++;
        return;
    }
    $RESULTS['fail']++;
    echo 'FAIL  ' . $name . ($detail === '' ? '' : '  (' . $detail . ')') . PHP_EOL;
}

function same(string $name, $expected, $actual): void
{
    ok($name, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function section(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

function threw(string $name, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        ok($name, false, 'no exception raised');
    } catch (Throwable $e) {
        ok(
            $name,
            $needle === '' || stripos($e->getMessage(), $needle) !== false,
            'message was: ' . $e->getMessage()
        );
    }
}

$db = db();
$levels = new LevelUpService($db);
$gen = new CharacterGenerator($db);
$engine = new CombatEngine($db);

/** A character row that exists only in this transaction. */
function scratch(PDO $db, string $class, array $overrides = []): int
{
    $a = array_merge([
        'level' => 4, 'proficiency_bonus' => 2,
        'strength' => 10, 'dexterity' => 10, 'constitution' => 14,
        'intelligence' => 10, 'wisdom' => 10, 'charisma' => 10,
        'max_hp' => 30, 'armor_class' => 10,
    ], $overrides);
    $db->prepare(
        'INSERT INTO characters
            (name, race, class, background, level, experience, strength, dexterity, constitution,
             intelligence, wisdom, charisma, max_hp, current_hp, armor_class, proficiency_bonus)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        '_feat_' . bin2hex(random_bytes(4)), 'Human', $class, 'Soldier',
        $a['level'],
        $a['strength'], $a['dexterity'], $a['constitution'],
        $a['intelligence'], $a['wisdom'], $a['charisma'],
        $a['max_hp'], $a['max_hp'], $a['armor_class'], $a['proficiency_bonus'],
    ]);
    return (int) $db->lastInsertId();
}

function charRow(PDO $db, int $id): array
{
    $s = $db->prepare('SELECT * FROM characters WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch();
}

/** Put an item in a character's hands (or on their back). */
function equip(PDO $db, int $characterId, string $itemKey): void
{
    $find = $db->prepare('SELECT id FROM items WHERE item_key = ?');
    $find->execute([$itemKey]);
    $id = $find->fetchColumn();
    if ($id === false) {
        return;
    }
    $db->prepare(
        'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped) VALUES (?,?,1,1)'
    )->execute([$characterId, (int) $id]);
}

/** A combatant, as startEncounter() would have built one. */
function fighter(array $overrides = []): array
{
    return array_merge([
        'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'rank' => 'front',
        'name' => 'Test', 'hp' => 20, 'max_hp' => 20, 'ac' => 14, 'level' => 4, 'prof' => 2,
        'str' => 16, 'dex' => 12, 'con' => 14, 'int' => 10, 'wis' => 10, 'cha' => 10,
        'class' => 'Fighter', 'alive' => true, 'conditions' => [], 'boons' => [],
        'feats' => [],
    ], $overrides);
}

$db->beginTransaction();

try {
    // =======================================================================
    section('The catalogue holds together');

    $catalog = Feats::all();
    ok('there is a real catalogue now', count($catalog) >= 25, count($catalog) . ' entries');

    // Every effect key must be one a system actually reads. This is the check
    // that stops a feat being added as a paragraph of text and no game: add an
    // effect nobody consumes and this fails by name.
    $wired = [
        'ability_points', 'ability_increase', 'ability_increase_casting', 'hp_per_level',
        'initiative_bonus', 'initiative_advantage', 'save_proficiency', 'skill_proficiency',
        'check_advantage', 'concentration_save_advantage', 'luck_points',
        'attack_bonus', 'damage_bonus', 'damage_bonus_proficiency', 'unarmed_damage',
        'damage_reroll_below', 'damage_reroll_best', 'damage_reroll_one', 'crit_extra_die',
        'heal_reroll_ones', 'ac_bonus_armored', 'medium_armor_dex_cap',
        'attack_advantage_vs', 'ignore_blinded_melee',
    ];
    $unread = [];
    $shapeless = [];
    $emptyEffects = [];
    foreach ($catalog as $key => $feat) {
        foreach (array_keys($feat['effects']) as $effect) {
            if (!in_array($effect, $wired, true)) {
                $unread[] = "{$key}.{$effect}";
            }
        }
        foreach (['name', 'category', 'source', 'prerequisite', 'description', 'effects'] as $field) {
            if (!array_key_exists($field, $feat)) {
                $shapeless[] = "{$key}.{$field}";
            }
        }
        if ($feat['effects'] === [] && !isset($feat['inert'])) {
            $emptyEffects[] = $key;
        }
    }
    same('every effect key is read by something', [], $unread);
    same('every entry carries every required field', [], $shapeless);
    same('a feat with no effects at all admits to being inert', [], $emptyEffects);

    $badKeys = array_values(array_filter(
        array_keys($catalog),
        static fn ($k) => !preg_match('/^[a-z0-9_]+$/', $k)
    ));
    same('keys are lowercase identifiers', [], $badKeys);

    $badCategories = [];
    foreach ($catalog as $key => $feat) {
        if (!isset(Feats::CATEGORIES[$feat['category']])) {
            $badCategories[] = $key;
        }
    }
    same('every category is one the screen groups by', [], $badCategories);

    same('the source is SRD 5.2 throughout', ['SRD 5.2'], array_values(array_unique(
        array_column($catalog, 'source')
    )));

    // Exactly one entry is repeatable, and it is the increase.
    $repeatable = array_keys(array_filter($catalog, static fn ($f) => !empty($f['repeatable'])));
    same('only the increase repeats', [LevelUpService::ASI_FEAT], $repeatable);

    same('lookup is case- and space-insensitive', 'Tough', Feats::get('  TOUGH ')['name']);
    ok('and a feat that does not exist is null', Feats::get('sharpshooter') === null);

    // =======================================================================
    section('Reading what a character holds');

    $withFeats = ['feats_json' => '["tough","alert"]', 'level' => 4, 'proficiency_bonus' => 3];
    same('feats_json is read', ['tough', 'alert'], Feats::taken($withFeats));
    // The shape a combatant carries. This is the one that would silently make
    // every feat inert in combat if it were not understood here.
    same('and so is the decoded list a combatant carries', ['tough', 'alert'], Feats::taken([
        'feats' => ['tough', 'alert'],
    ]));
    same('a key the catalogue dropped is ignored, not fatal', ['tough'], Feats::taken([
        'feats_json' => '["tough","sharpshooter"]',
    ]));
    same('nothing at all is no feats', [], Feats::taken([]));
    same('and neither is broken JSON', [], Feats::taken(['feats_json' => 'not json']));
    ok('has() finds one', Feats::has($withFeats, 'alert'));
    ok('and does not find one that is absent', !Feats::has($withFeats, 'lucky'));

    // =======================================================================
    section('Effect accessors combine the right way');

    same('a flat number sums', 2, Feats::sum(['feats' => ['tough']], 'hp_per_level'));
    same('"proficiency" resolves against the bonus given', 3,
        Feats::sum(['feats' => ['alert']], 'initiative_bonus', 3));
    same('a feat that does not carry it is worth nothing', 0,
        Feats::sum(['feats' => ['tough']], 'initiative_bonus', 3));

    ok('flag() is true when any feat sets it', Feats::flag(['feats' => ['savage_attacker']], 'damage_reroll_best'));
    ok('and false otherwise', !Feats::flag(['feats' => ['tough']], 'damage_reroll_best'));

    same('strings() reads a list', ['perception', 'investigation'],
        Feats::strings(['feats' => ['observant']], 'skill_proficiency'));
    same('and a bare string as a list of one', ['grappled'],
        Feats::strings(['feats' => ['grappler']], 'attack_advantage_vs'));

    same('best() keys a number by tag', ['ranged' => 2],
        Feats::best(['feats' => ['archery']], 'attack_bonus'));
    same('maxOf() takes the largest, not the sum', 3,
        Feats::maxOf(['feats' => ['medium_armor_master']], 'medium_armor_dex_cap'));

    // A character with both punching feats has one pair of hands.
    same('two unarmed dice do not stack, the better wins', '1d6',
        Feats::bestDice(['feats' => ['tavern_brawler', 'unarmed_fighting']], 'unarmed_damage'));
    ok('and no such feat is no die at all',
        Feats::bestDice(['feats' => ['tough']], 'unarmed_damage') === null);

    // =======================================================================
    section('Prerequisites');

    $weak = ['class' => 'Fighter', 'level' => 4, 'strength' => 8, 'dexterity' => 8];
    $strong = ['class' => 'Fighter', 'level' => 4, 'strength' => 16, 'dexterity' => 16];

    same('an ability minimum is enforced', false, Feats::qualifies($weak, 'grappler')['ok']);
    ok('and says which ability and how much',
        str_contains((string) Feats::qualifies($weak, 'grappler')['reason'], 'Strength 13'));
    same('and passes when it is met', true, Feats::qualifies($strong, 'grappler')['ok']);

    same('a general feat is refused below level 4', false,
        Feats::qualifies(['class' => 'Fighter', 'level' => 3, 'strength' => 16], 'great_weapon_master')['ok']);
    same('an origin feat is not', true,
        Feats::qualifies(['class' => 'Fighter', 'level' => 1], 'tough')['ok']);

    same('a fighting style needs the class that has the feature', true,
        Feats::qualifies(['class' => 'Ranger', 'level' => 1], 'archery')['ok']);
    same('and is refused to one that does not', false,
        Feats::qualifies(['class' => 'Wizard', 'level' => 4], 'archery')['ok']);

    same('War Caster needs a spellcaster', true,
        Feats::qualifies(['class' => 'Cleric', 'level' => 4], 'war_caster')['ok']);
    same('and a Barbarian is not one', false,
        Feats::qualifies(['class' => 'Barbarian', 'level' => 4], 'war_caster')['ok']);

    $held = ['class' => 'Fighter', 'level' => 4, 'feats_json' => '["tough"]'];
    same('a feat already held is refused', false, Feats::qualifies($held, 'tough')['ok']);
    // The one that would have been quietly wrong: the increase must stay on
    // offer at every level that offers one.
    same('but the increase is not', true, Feats::qualifies(
        ['class' => 'Fighter', 'level' => 8, 'feats_json' => '["ability_score_improvement"]'],
        LevelUpService::ASI_FEAT
    )['ok']);

    same('an unknown key is refused by name', false, Feats::qualifies($strong, 'sharpshooter')['ok']);

    same('prerequisites read as a line', 'Strength 13',
        Feats::prerequisiteText('grappler'));
    same('an origin feat requires nothing', '', Feats::prerequisiteText('tough'));

    // =======================================================================
    section('The offer');

    $offer = Feats::offerTo(['class' => 'Fighter', 'level' => 4, 'strength' => 8,
                             'dexterity' => 8, 'feats_json' => '["tough"]']);
    $byKey = array_column($offer, null, 'key');

    ok('a feat already taken is dropped from the list', !isset($byKey['tough']));
    ok('the increase is offered even though it is "taken"', isset($byKey[LevelUpService::ASI_FEAT]));
    ok('an ineligible feat is listed, not hidden', isset($byKey['grappler']));
    same('and marked so', false, $byKey['grappler']['eligible']);
    ok('with the reason on it', (string) $byKey['grappler']['reason'] !== '');
    same('the increase reports the points it hands over', 2,
        $byKey[LevelUpService::ASI_FEAT]['ability_points']);
    same('an ordinary feat hands over none', 0, $byKey['grappler']['ability_points']);
    same('Grappler is honest about being half-wired', false, $byKey['grappler']['inert']);
    ok('and says so in a note', str_contains((string) $byKey['grappler']['note'], 'grapples'));
    ok('every entry carries a category label',
        count(array_filter($offer, static fn ($f) => $f['category_label'] === '')) === 0);

    // =======================================================================
    section('Taking a feat writes more than a row');

    $barb = scratch($db, 'Barbarian', ['strength' => 16, 'max_hp' => 40]);
    $before = charRow($db, $barb);
    $result = $levels->grantFeat($barb, 'tough');
    $after = charRow($db, $barb);

    same('the feat is recorded', ['tough'], Feats::taken($after));
    same('Tough is worth 2 hit points for every level', 8, $result['hp']);
    same('and they are on the character', (int) $before['max_hp'] + 8, (int) $after['max_hp']);
    same('current hit points move with the maximum',
        (int) $before['current_hp'] + 8, (int) $after['current_hp']);
    threw('and it cannot be taken twice', fn () => $levels->grantFeat($barb, 'tough'), 'already');

    // A feat that carries an ability increase applies it, and a Constitution
    // one pays out retroactively exactly as the ability score increase does.
    $con13 = scratch($db, 'Fighter', ['constitution' => 13, 'level' => 4, 'max_hp' => 30]);
    $applied = $levels->grantFeat($con13, 'resilient_constitution');
    same('the +1 is applied', ['constitution' => 1], $applied['abilities']);
    same('Constitution moved', 14, (int) charRow($db, $con13)['constitution']);
    same('crossing a modifier boundary pays four levels of hit points',
        34, (int) charRow($db, $con13)['max_hp']);

    // The clamp. A feat's increase stops at 20 rather than being refused.
    $maxed = scratch($db, 'Fighter', ['strength' => 20]);
    $clamped = $levels->grantFeat($maxed, 'great_weapon_master');
    same('an increase into a 20 is trimmed to nothing', [], $clamped['abilities']);
    same('and the score stays at 20', 20, (int) charRow($db, $maxed)['strength']);
    same('the feat is still taken', ['great_weapon_master'], Feats::taken(charRow($db, $maxed)));

    // War Caster raises whichever ability the class casts with.
    $cleric = scratch($db, 'Cleric', ['wisdom' => 16]);
    $levels->grantFeat($cleric, 'war_caster');
    same('War Caster raises a Cleric\'s Wisdom', 17, (int) charRow($db, $cleric)['wisdom']);
    $wizard = scratch($db, 'Wizard', ['intelligence' => 16]);
    $levels->grantFeat($wizard, 'war_caster');
    same('and a Wizard\'s Intelligence', 17, (int) charRow($db, $wizard)['intelligence']);

    threw('a feat the character does not qualify for is refused',
        fn () => $levels->grantFeat(scratch($db, 'Wizard'), 'archery'), 'Fighting Style');
    threw('and one that does not exist', fn () => $levels->grantFeat($barb, 'nonesuch'), 'No such feat');

    // =======================================================================
    section('Armour class');

    $armoured = scratch($db, 'Fighter', ['dexterity' => 14]);
    equip($db, $armoured, 'leather_armor');
    $gen->recalculateAC($armoured);
    $plain = (int) charRow($db, $armoured)['armor_class'];
    same('leather and DEX 14 is AC 13', 13, $plain);

    $levels->grantFeat($armoured, 'defense');
    same('the Defense fighting style is worth 1 more', $plain + 1,
        (int) charRow($db, $armoured)['armor_class']);

    // And nothing, with nothing on.
    $bare = scratch($db, 'Fighter', ['dexterity' => 14]);
    $levels->grantFeat($bare, 'defense');
    $gen->recalculateAC($bare);
    same('but not while unarmoured', 12, (int) charRow($db, $bare)['armor_class']);

    // Medium Armour Master lifts the Dexterity a chain shirt will carry.
    $medium = scratch($db, 'Fighter', ['dexterity' => 18]);
    equip($db, $medium, 'chain_shirt');
    $gen->recalculateAC($medium);
    same('a chain shirt caps DEX at 2', 15, (int) charRow($db, $medium)['armor_class']);
    $levels->grantFeat($medium, 'medium_armor_master');
    // The feat also raises Dexterity by 1, to 19 — still a +4 modifier, so the
    // whole change here is the cap moving from 2 to 3.
    same('and the feat lifts it to 3', 16, (int) charRow($db, $medium)['armor_class']);

    // =======================================================================
    section('Skills and saving throws');

    $observant = ['class' => 'Fighter', 'background' => 'Soldier', 'level' => 4,
                  'wisdom' => 14, 'intelligence' => 10, 'proficiency_bonus' => 2,
                  'feats_json' => '["observant"]'];
    $plainFighter = $observant;
    $plainFighter['feats_json'] = null;

    ok('a Soldier Fighter is not proficient in Investigation',
        !Rules::isSkillProficient($plainFighter, 'investigation'));
    ok('Observant makes them so', Rules::isSkillProficient($observant, 'investigation'));
    same('and the bonus moves with it',
        Rules::skillCheck($plainFighter, 'investigation')['total'] + 2,
        Rules::skillCheck($observant, 'investigation')['total']);

    // An explicit skills list used to shut off every other source. A feat
    // grant has to survive it, or Observant would work for exactly the
    // characters that have not recorded their proficiencies.
    $explicit = $observant;
    $explicit['skills'] = 'athletics,intimidation';
    ok('a feat grant survives an explicit skill list',
        Rules::isSkillProficient($explicit, 'perception'));

    $resilient = ['class' => 'Wizard', 'level' => 4, 'constitution' => 14,
                  'proficiency_bonus' => 2, 'feats_json' => '["resilient_constitution"]'];
    $plainWizard = $resilient;
    $plainWizard['feats_json'] = null;
    ok('a Wizard has no Constitution save proficiency',
        !Rules::isSaveProficient($plainWizard, 'con'));
    ok('Resilient (Constitution) grants it', Rules::isSaveProficient($resilient, 'con'));
    same('worth the proficiency bonus', 4, Rules::savingThrow($resilient, 'con')['total']);
    same('and the class save is not counted twice',
        Rules::savingThrow($plainWizard, 'int')['total'],
        Rules::savingThrow(array_merge($resilient, ['feats_json' => '["resilient_intelligence"]']), 'int')['total']);

    // =======================================================================
    section('Advantage, from the attack context');

    $grappler = fighter(['feats' => ['grappler']]);
    $free = fighter(['cid' => 'mon_1', 'side' => 'foe']);
    $held = fighter(['cid' => 'mon_2', 'side' => 'foe', 'conditions' => [['key' => 'grappled']]]);

    same('no advantage against a target standing free', false,
        Conditions::attackContext($grappler, $free)['advantage']);
    same('advantage against one being grappled', true,
        Conditions::attackContext($grappler, $held)['advantage']);
    same('and none for an attacker without the feat', false,
        Conditions::attackContext(fighter(), $held)['advantage']);

    $blind = ['key' => 'blinded'];
    $blinded = fighter(['conditions' => [$blind], 'attack_kind' => 'melee']);
    $blindFighter = fighter(['conditions' => [$blind], 'attack_kind' => 'melee',
                             'feats' => ['blind_fighting']]);
    same('being blinded normally costs an attacker advantage', true,
        Conditions::attackContext($blinded, $free)['disadvantage']);
    same('Blind Fighting pays that cost in melee', false,
        Conditions::attackContext($blindFighter, $free)['disadvantage']);
    $atRange = array_merge($blindFighter, ['attack_kind' => 'ranged']);
    same('but not at range', true, Conditions::attackContext($atRange, $free)['disadvantage']);

    // =======================================================================
    section('The attack itself');

    // Archery, on a weapon the engine now correctly reads as ranged.
    $archer = scratch($db, 'Ranger', ['dexterity' => 16, 'strength' => 8]);
    equip($db, $archer, 'shortbow');
    $bowman = fighter(['character_id' => $archer, 'class' => 'Ranger', 'dex' => 16, 'str' => 8]);
    $plainShot = $engine->weaponFor($bowman);
    same('a shortbow is a ranged weapon', 'ranged', $plainShot['reach']);
    same('and is fired with Dexterity', 5, $plainShot['bonus']);

    $withArchery = $engine->weaponFor(array_merge($bowman, ['feats' => ['archery']]));
    same('Archery is +2 to the attack roll', $plainShot['bonus'] + 2, $withArchery['bonus']);
    same('and nothing to the damage', $plainShot['damage'], $withArchery['damage']);

    // Great Weapon Master, on something heavy.
    $brute = scratch($db, 'Fighter', ['strength' => 16]);
    equip($db, $brute, 'greataxe');
    $swinger = fighter(['character_id' => $brute]);
    $plainSwing = $engine->weaponFor($swinger);
    ok('a greataxe is tagged heavy', in_array('heavy', $plainSwing['tags'], true));
    same('and is not one-handed', false, in_array('one_handed', $plainSwing['tags'], true));
    same('a greataxe swings for 1d12+3', '1d12+3', $plainSwing['damage']);
    same('Great Weapon Master adds the proficiency bonus', '1d12+5',
        $engine->weaponFor(array_merge($swinger, ['feats' => ['great_weapon_master']]))['damage']);
    same('but Duelling does not reach a two-handed weapon', '1d12+3',
        $engine->weaponFor(array_merge($swinger, ['feats' => ['dueling']]))['damage']);

    // Duelling, on something held in one hand.
    $duellist = scratch($db, 'Fighter', ['strength' => 16]);
    equip($db, $duellist, 'longsword');
    $blade = fighter(['character_id' => $duellist]);
    ok('a longsword is one-handed', in_array('one_handed', $engine->weaponFor($blade)['tags'], true));
    same('and Duelling is worth 2 damage on it', '1d8+5',
        $engine->weaponFor(array_merge($blade, ['feats' => ['dueling']]))['damage']);
    same('while Archery does not touch a melee attack',
        $engine->weaponFor($blade)['bonus'],
        $engine->weaponFor(array_merge($blade, ['feats' => ['archery']]))['bonus']);

    // Fists.
    $barehanded = scratch($db, 'Monk', ['strength' => 14]);
    $fists = fighter(['character_id' => $barehanded, 'class' => 'Monk', 'str' => 14]);
    same('an unarmed strike is 1d4', '1d4+2', $engine->weaponFor($fists)['damage']);
    same('Tavern Brawler leaves it at 1d4', '1d4+2',
        $engine->weaponFor(array_merge($fists, ['feats' => ['tavern_brawler']]))['damage']);
    same('Unarmed Fighting makes it 1d6', '1d6+2',
        $engine->weaponFor(array_merge($fists, ['feats' => ['unarmed_fighting']]))['damage']);

    same('featBonus only pays on a tag the weapon carries', 2,
        CombatEngine::featBonus(['feats' => ['archery']], 'attack_bonus', ['ranged', 'two_handed']));
    same('and nothing on one it does not', 0,
        CombatEngine::featBonus(['feats' => ['archery']], 'attack_bonus', ['melee']));

    // =======================================================================
    section('Damage dice, as the feats leave them');

    same('the die size is read off the expression', 12, CombatEngine::dieSides('1d12+3'));
    same('a bare number rolls nothing', 0, CombatEngine::dieSides('4'));

    $rolled = ['rolls' => [1, 2, 5, 6], 'dice_total' => 14, 'modifier' => 3, 'total' => 17];
    $kept = CombatEngine::rerollUnder($rolled, 6, 0);
    same('a floor of zero changes nothing', $rolled, $kept);

    $lifted = CombatEngine::rerollUnder($rolled, 6, 2);
    same('the dice that were above the floor are untouched', [5, 6],
        array_slice($lifted['rolls'], 2));
    same('and the total is re-added rather than left stale',
        array_sum($lifted['rolls']) + 3, $lifted['total']);

    // Great Weapon Fighting rerolls once and takes what it gets, so a 1 can
    // come back — but over many rolls it must come back far less often than a
    // straight 1-in-6. Driven enough times that the assertion is about the
    // rule and not about luck.
    $lowAfter = 0;
    for ($i = 0; $i < 400; $i++) {
        $roll = CombatEngine::rerollUnder(
            ['rolls' => [1], 'dice_total' => 1, 'modifier' => 0, 'total' => 1],
            6,
            2
        );
        if ($roll['rolls'][0] <= 2) {
            $lowAfter++;
        }
    }
    ok('a rerolled 1 usually comes back better', $lowAfter < 220, "{$lowAfter}/400 stayed low");

    // The whole path, with the once-per-turn allowance the state carries.
    $savage = fighter(['feats' => ['savage_attacker'], 'feat_turn' => []]);
    $state = ['combatants' => [$savage], 'log' => [], 'events' => []];
    $weapon = ['name' => 'Greataxe', 'bonus' => 5, 'damage' => '1d12+3',
               'damage_type' => 'slashing', 'reach' => 'melee', 'tags' => ['heavy', 'two_handed', 'melee']];

    $first = $engine->featDamageRoll($state, 'pc_1', $weapon, false);
    ok('Savage Attacker says what it did', count($first['notes']) === 1, json_encode($first['notes']));
    ok('and spends its allowance', in_array(
        'damage_reroll_best',
        $first['state']['combatants'][0]['feat_turn'],
        true
    ));
    $second = $engine->featDamageRoll($first['state'], 'pc_1', $weapon, false);
    same('a second swing in the same turn does not get it again', [], $second['notes']);

    // A character with no feats must take the plain path and cost nothing.
    $plainState = ['combatants' => [fighter()], 'log' => [], 'events' => []];
    $plainDamage = $engine->featDamageRoll($plainState, 'pc_1', $weapon, false);
    same('a character with no feats is untouched', [], $plainDamage['notes']);
    ok('and rolls inside the die', $plainDamage['roll']['total'] >= 4
        && $plainDamage['roll']['total'] <= 15, (string) $plainDamage['roll']['total']);

    // Piercer, on a critical.
    $piercer = fighter(['feats' => ['piercer'], 'feat_turn' => []]);
    $pState = ['combatants' => [$piercer], 'log' => [], 'events' => []];
    $arrow = ['name' => 'Shortbow', 'bonus' => 5, 'damage' => '1d6+3',
              'damage_type' => 'piercing', 'reach' => 'ranged', 'tags' => ['ranged']];
    $critical = $engine->featDamageRoll($pState, 'pc_1', $arrow, true);
    same('a critical doubles the dice and Piercer adds a third', 3,
        count($critical['roll']['rolls']));
    // And on slashing damage, the same feat does nothing at all.
    $sState = ['combatants' => [fighter(['feats' => ['piercer'], 'feat_turn' => []])],
               'log' => [], 'events' => []];
    same('Piercer stays out of a slashing hit', 2,
        count($engine->featDamageRoll($sState, 'pc_1', $weapon, true)['roll']['rolls']));

    // =======================================================================
    section('Healing');

    $healer = ['feats' => ['healer']];
    $ordinary = ['feats' => []];

    // A 1 on a d4 that is rerolled once can come back a 1, so this is a
    // frequency test and not an absolute one: roughly a sixteenth with the
    // feat against roughly a quarter without it.
    $withFeat = 0;
    $without = 0;
    for ($i = 0; $i < 400; $i++) {
        $withFeat += CombatEngine::healingRoll($healer, '1d4+2')['rolls'][0] === 1 ? 1 : 0;
        $without += CombatEngine::healingRoll($ordinary, '1d4+2')['rolls'][0] === 1 ? 1 : 0;
    }
    ok('Healer rerolls the 1s out of a healing die', $withFeat < 55, "{$withFeat}/400 were still 1");
    ok('and somebody without the feat keeps them', $without > 55, "{$without}/400 were 1");
    ok('the modifier still lands either way',
        CombatEngine::healingRoll($healer, '1d4+2')['total'] >= 3);

    // =======================================================================
    section('Lucky, through the check machinery');

    // A luck point buys a second d20 and nothing else, which is the shape that
    // slipped past the spend loop: a boost with no dice and no flat bonus was
    // never recorded as spent, and an uncharged luck point is an endless one.
    $pending = [
        'check_id' => 'test', 'party_id' => 0, 'kind' => 'skill', 'label' => 'Stealth',
        'dc' => 12, 'modifier' => 3, 'parts' => [], 'die_mode' => 'normal',
        'boosts' => [[
            'key' => 'lucky_feat', 'label' => 'Lucky', 'detail' => '2 left',
            'bonus_dice' => '', 'bonus_flat' => 0, 'die_mode' => 'advantage',
            'reroll_ones' => false, 'source_character_id' => 7, 'cost' => 'use',
        ]],
    ];

    $unspent = CheckService::resolvePending($pending, []);
    same('not spending a point rolls one die', 'normal', $unspent['die_mode']);
    same('and costs nothing', [], $unspent['spent']);

    $spentOne = CheckService::resolvePending($pending, ['lucky_feat']);
    same('spending one rolls with advantage', 'advantage', $spentOne['die_mode']);
    same('two dice are thrown', 2, count($spentOne['rolls']));
    same('and the point is charged for', 'lucky_feat', $spentOne['spent'][0]['key']);
    same('exactly once', 1, count($spentOne['spent']));
    same('against the character who owns it', 7, $spentOne['spent'][0]['source_character_id']);

    // Cancelling a disadvantage is a legitimate spend, and still a spend.
    $hampered = array_merge($pending, ['die_mode' => 'disadvantage']);
    $cancelled = CheckService::resolvePending($hampered, ['lucky_feat']);
    same('a point spent against disadvantage evens the roll', 'normal', $cancelled['die_mode']);
    same('and is still charged for', 1, count($cancelled['spent']));

    // A boost that adds dice is charged for exactly as before.
    $bardic = array_merge($pending, ['boosts' => [[
        'key' => 'bardic_inspiration', 'label' => 'Bardic Inspiration', 'detail' => '',
        'bonus_dice' => '1d6', 'bonus_flat' => 0, 'die_mode' => null,
        'reroll_ones' => false, 'source_character_id' => 9, 'cost' => 'use',
    ]]]);
    $inspired = CheckService::resolvePending($bardic, ['bardic_inspiration']);
    same('a dice boost is still charged for once', 1, count($inspired['spent']));
    ok('and still adds its die', $inspired['total'] > $inspired['natural'] + 3);

    // =======================================================================
    section('Lucky and Actor, offered for real');

    // A party, because CheckService::request reads one. Rolled back with
    // everything else.
    $rogue = scratch($db, 'Rogue', ['dexterity' => 16, 'charisma' => 14]);
    $db->prepare('INSERT INTO parties (name, leader_character_id) VALUES (?, ?)')
        ->execute(['_feat_party', $rogue]);
    $partyId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,1)')
        ->execute([$partyId, $rogue]);

    // A fresh CheckService per question. Each one holds a WorldState that
    // caches the party's flags for the life of the request it was made for, so
    // a harness that changed a flag behind an old instance would be testing the
    // cache rather than the rule.
    $ask = static fn (array $spec, int $who) =>
        (new CheckService($db))->request($partyId, $spec, $who);
    $stealth = ['skill' => 'stealth', 'dc' => 12];

    $offered = $ask($stealth, $rogue);
    ok('a character without Lucky is offered no luck points',
        !in_array('lucky_feat', array_column($offered['boosts'], 'key'), true));

    $levels->grantFeat($rogue, 'lucky');
    $offered = $ask($stealth, $rogue);
    $lucky = array_column($offered['boosts'], null, 'key')['lucky_feat'] ?? null;
    ok('with the feat, the points are on the table', $lucky !== null);
    same('two of them, at proficiency 2', '2 luck points left', $lucky['detail']);

    // Spend them all and the option withdraws itself rather than being offered
    // and then refused.
    (new WorldState($db))->set($partyId, 'luck_points_used_' . $rogue, '2');
    ok('spent out, it is no longer offered',
        !in_array('lucky_feat', array_column($ask($stealth, $rogue)['boosts'], 'key'), true));

    (new CheckService($db))->resetOnLongRest($partyId);
    ok('a long rest gives them back',
        in_array('lucky_feat', array_column($ask($stealth, $rogue)['boosts'], 'key'), true));

    // Actor, on the two skills it names and on nothing else.
    $bard = scratch($db, 'Bard', ['charisma' => 16]);
    $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,2)')
        ->execute([$partyId, $bard]);
    $deception = ['skill' => 'deception', 'dc' => 12];
    same('a plain Bard rolls Deception straight', 'normal', $ask($deception, $bard)['die_mode']);
    $levels->grantFeat($bard, 'actor');
    same('Actor rolls it with advantage', 'advantage', $ask($deception, $bard)['die_mode']);
    same('and leaves Stealth alone', 'normal', $ask($stealth, $bard)['die_mode']);

    // =======================================================================
    section('The ceremony, with the increase as one of the feats');

    /** A character owed exactly one level 4, and the id of that ceremony. */
    $ceremonyFor = static function (PDO $db, LevelUpService $levels, string $class, array $o = []) {
        $id = scratch($db, $class, $o + ['level' => 4]);
        $levels->record($id, 3, 4);
        $row = $db->prepare('SELECT id FROM pending_level_ups WHERE character_id = ? AND new_level = 4');
        $row->execute([$id]);
        return [$id, (int) $row->fetchColumn()];
    };

    // The shape the client sends now: the increase named as a feat, with the
    // points beside it.
    [$namedId, $namedPending] = $ceremonyFor($db, $levels, 'Fighter', ['strength' => 14]);
    $levels->chooseAsi($namedPending, ['str' => 2], LevelUpService::ASI_FEAT);
    same('the increase named as a feat still raises the score',
        16, (int) charRow($db, $namedId)['strength']);
    same('and is not recorded as a feat on the character', [], Feats::taken(charRow($db, $namedId)));

    // The shape the client sent before the increase was a feat. Still honoured,
    // because a page left open across a deploy would otherwise fail on confirm.
    [$oldId, $oldPending] = $ceremonyFor($db, $levels, 'Fighter', ['dexterity' => 12]);
    $levels->chooseAsi($oldPending, ['dex' => 2], null);
    same('the old shape of the request still works', 14, (int) charRow($db, $oldId)['dexterity']);

    // A feat that carries an increase records both, so the summary can say both.
    [$bothId, $bothPending] = $ceremonyFor($db, $levels, 'Rogue', ['dexterity' => 14]);
    $c = $levels->chooseAsi($bothPending, null, 'resilient_dexterity')['ceremony'];
    same('the feat is on the ceremony', 'resilient_dexterity', $c['asi']['feat']);
    same('by name as well as by key', 'Resilient (Dexterity)', $c['asi']['feat_name']);
    same('and the increase it came with is too', ['dexterity' => 1], $c['asi']['taken']);
    same('which landed', 15, (int) charRow($db, $bothId)['dexterity']);
    ok('the step is settled either way', $c['asi']['done']);

    // The increase must remain takeable at the next increase level.
    [$againId, $againPending] = $ceremonyFor($db, $levels, 'Fighter', ['strength' => 12]);
    $levels->chooseAsi($againPending, ['str' => 2], LevelUpService::ASI_FEAT);
    $db->prepare('UPDATE characters SET level = 8 WHERE id = ?')->execute([$againId]);
    $levels->record($againId, 7, 8);
    $row = $db->prepare('SELECT id FROM pending_level_ups WHERE character_id = ? AND new_level = 8');
    $row->execute([$againId]);
    $eight = (int) $row->fetchColumn();
    $offered = array_column($levels->ceremony($eight)['asi']['feats'], null, 'key');
    ok('the increase is still on the list at level 8', isset($offered[LevelUpService::ASI_FEAT]));
    $levels->chooseAsi($eight, ['str' => 2], LevelUpService::ASI_FEAT);
    same('and can be taken again', 16, (int) charRow($db, $againId)['strength']);

    // Tough keeps paying at every level after the one it was taken at.
    [$toughId, $toughPending] = $ceremonyFor($db, $levels, 'Fighter', ['constitution' => 10]);
    $levels->chooseAsi($toughPending, null, 'tough');
    $hpAfterFeat = (int) charRow($db, $toughId)['max_hp'];
    $db->prepare('UPDATE characters SET level = 5 WHERE id = ?')->execute([$toughId]);
    $levels->record($toughId, 4, 5);
    $row = $db->prepare('SELECT id FROM pending_level_ups WHERE character_id = ? AND new_level = 5');
    $row->execute([$toughId]);
    $rolled = $levels->rollHp((int) $row->fetchColumn());
    same('the next level is worth the die plus 2 more',
        $rolled['roll'] + 2, (int) $rolled['gained']);
    same('and the hit points arrived', $hpAfterFeat + (int) $rolled['gained'],
        (int) charRow($db, $toughId)['max_hp']);

    // =======================================================================
    section('Companion templates');

    // Every feat any companion is authored with must be one they can actually
    // take at the level they are written at, or recruiting them throws in the
    // middle of the scene where they agree to come.
    $templates = $db->query(
        'SELECT companion_key, name, class, level, starting_feats_json FROM companions'
    )->fetchAll();
    $badTemplates = [];
    foreach ($templates as $t) {
        foreach ((array) json_decode((string) $t['starting_feats_json'], true) as $key) {
            if (!Feats::exists((string) $key)) {
                $badTemplates[] = $t['companion_key'] . ': no such feat ' . $key;
                continue;
            }
            $check = Feats::qualifies([
                'class' => $t['class'],
                'level' => (int) $t['level'],
                'strength' => 20, 'dexterity' => 20, 'constitution' => 20,
                'intelligence' => 20, 'wisdom' => 20, 'charisma' => 20,
            ], (string) $key);
            if (!$check['ok']) {
                $badTemplates[] = $t['companion_key'] . ': ' . $check['reason'];
            }
        }
    }
    same('every authored companion feat is one they could take', [], $badTemplates);
} finally {
    $db->rollBack();
}

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
