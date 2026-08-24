<?php
/**
 * Variant tokens for monsters, from the Too Many Tokens pack.
 *
 * The board used to show one `_face.png` for every goblin. Three goblins
 * were three copies of the same man. The pack ships many faces per creature;
 * this is the lookup that hands each combatant a different one.
 *
 * The mapping and the files live under `assets/images/tokens/`. Nothing here
 * decides cover or combat — it only names a picture. Missing files fall
 * through to the old face, so a fresh install without the pack still plays.
 */

declare(strict_types=1);

final class TokenArt
{
    /** @var array<string, list<string>>|null */
    private static ?array $index = null;

    /**
     * Pack folder used for each of our monster keys.
     *
     * Custom Rivermark creatures take the nearest SRD face the pack actually
     * has. A key with no folder is left off the index and keeps `_face.png`.
     *
     * @var array<string, string>
     */
    public const PACK = [
        'bandit'             => 'Bandit',
        'thief_captain'      => 'Bandit Captain',
        'bugbear'            => 'Bugbear',
        'carrion_crawler'    => 'Carrion Crawler',
        'bog_crawler'        => 'Carrion Crawler',
        'pale_crawler'       => 'Carrion Crawler',
        'city_guard'         => 'Guard',
        'concern_warden'     => 'Guard',
        'warden_serjeant'    => 'Guard',
        'city_engineer'      => 'Commoner',
        'dire_wolf'          => 'Dire Wolf',
        'giant_centipede'    => 'Giant Centipede',
        'giant_rat'          => 'Giant Rat',
        'giant_spider'       => 'Giant Spider',
        'broodmother_spider' => 'Giant Spider',
        'giant_toad'         => 'Giant Toad',
        'gnoll'              => 'Gnoll',
        'goblin'             => 'Goblin',
        'grey_ooze'          => 'Gray Ooze',
        'peat_ooze'          => 'Ochre Jelly',
        'crust'              => 'Black Pudding',
        'kobold'             => 'Kobold',
        'ogre'               => 'Ogre',
        'stirge'             => 'Stirge',
        'wererat'            => 'Wererat',
        'wight'              => 'Wight',
        'wolf'               => 'Wolf',
        'worg'               => 'Worg',
        'drowned_man'        => 'Zombie',
        'drowned_clerk'      => 'Ghoul',
        'fen_horror'         => 'Shadow',
        'fen_howler'         => 'Death Dog',
        'pit_champion'       => 'Gladiator',
        'the_growth'         => 'Shambling Mound',
        'deep_gremlin'       => 'Nothic',
        'sump_gremlin'       => 'Troglodyte',
    ];

    /**
     * The token this combatant should wear, or null to keep `_face.png`.
     *
     * `$index` is which copy of this monster they are (0, 1, 2…). The seed
     * rotates the deck so two fights against three goblins do not start on
     * the same three faces; within one fight the copies are distinct for as
     * long as the pack has that many pictures.
     */
    public static function pick(?string $monsterKey, int $index, int $seed): ?string
    {
        $key = strtolower(trim((string) $monsterKey));
        if ($key === '') {
            return null;
        }
        $list = self::index()[$key] ?? [];
        if (!$list) {
            return null;
        }
        $n = count($list);
        $start = (int) (sprintf('%u', crc32($seed . '|' . $key)) % $n);
        return $list[($start + $index) % $n];
    }

    /** @return array<string, list<string>> */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $path = dirname(__DIR__, 2) . '/assets/images/tokens/index.json';
        if (!is_file($path)) {
            self::$index = [];
            return self::$index;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        self::$index = is_array($raw) ? $raw : [];
        return self::$index;
    }
}
