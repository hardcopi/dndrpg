<?php
/**
 * What the region chart says about each place beyond its location_type, for
 * clients that draw pictures rather than dots (the Amiga client, Rivermark).
 *
 *  - iconFor(): a picture word per place — inn, shop, smithy, temple, hall,
 *    stair, house — for the generic `building` type and the odd street. The
 *    authored type is still sent unchanged as `type`; `icon` is extra.
 *  - paintingFor(): an optional painted backdrop for a region: the art's base
 *    name (the client ships the picture) and a hand-set pixel spot for each
 *    place and each road out, on the painting's own grid.
 *
 * Both are additive fields on location/state's region_map. A client that does
 * not know them ignores them; a region with no painting sends null.
 */
declare(strict_types=1);

final class RegionMapArt
{
    /** Places whose picture is not in their name. */
    private const ICON_BY_KEY = [
        'flagon_common_room' => 'inn',
        'tobins_goods'       => 'shop',
        'root_ash'           => 'shop',     // the apothecary
        'the_anvil'          => 'smithy',
        'counting_house'     => 'hall',
        'flagon_cellar'      => 'stair',
        'river_stair'        => 'stair',
    ];

    /** Name words, first match wins, for building and street places. */
    private const ICON_WORDS = [
        'inn'    => ['inn', 'tavern', 'alehouse', 'flagon', 'tankard'],
        'smithy' => ['smith', 'anvil', 'forge'],
        'temple' => ['temple', 'shrine', 'chapel', 'priory', 'sanctum'],
        'stair'  => ['stair', 'hatch', 'dock', 'cellar', 'quay', 'wharf'],
        'hall'   => ['hall', 'counting', 'guild', 'bank'],
        'shop'   => ['goods', 'shop', 'store', 'emporium', 'apothecary', 'chandler'],
    ];

    /**
     * Painted regions. Spots are pixels on a `width` x `height` grid, matched
     * to the buildings by hand; `nodes` by location_key, `neighbors` by the
     * far region's region_key.
     */
    private const PAINTINGS = [
        'rivermark' => [
            'art'    => 'town_map',
            'width'  => 640,
            'height' => 480,
            'nodes'  => [
                'river_stair'        => [312, 78],
                'counting_house'     => [178, 92],
                'market_square'      => [263, 172],
                'tobins_goods'       => [132, 182],
                'the_anvil'          => [380, 135],
                'high_street'        => [330, 238],
                'east_gate'          => [556, 234],
                'granary_row'        => [410, 300],
                'flagon_common_room' => [185, 365],
                'flagon_cellar'      => [140, 428],
                'root_ash'           => [200, 288],
                'west_gate'          => [18, 236],
            ],
            'neighbors' => [
                '_freeplay_grounds' => [22, 178],
                'undertown'         => [44, 420],
                'quarry_wilds'      => [618, 330],
                'ford_road'         => [236, 468],
                'greyhythe'         => [606, 60],
            ],
        ],
    ];

    public static function iconFor(string $key, string $type, string $name, ?int $innCost = null): string
    {
        if (isset(self::ICON_BY_KEY[$key])) {
            return self::ICON_BY_KEY[$key];
        }
        if ($type !== 'building' && $type !== 'street') {
            return $type;
        }
        if ($type === 'building' && $innCost !== null && $innCost > 0) {
            return 'inn';
        }
        $words = preg_split('/[^a-z]+/', strtolower($name)) ?: [];
        foreach (self::ICON_WORDS as $icon => $list) {
            foreach ($words as $w) {
                if ($w === '') {
                    continue;
                }
                foreach ($list as $want) {
                    if ($w === $want || $w === $want . 's') {
                        return $icon;
                    }
                }
            }
        }
        return $type === 'building' ? 'house' : 'street';
    }

    /**
     * The painting for a region, with spots for the nodes and roads out that
     * the chart is actually sending (hidden places stay hidden), or null.
     *
     * @param array<int,array<string,mixed>> $nodes     region_map nodes (id, key)
     * @param array<int,array<string,mixed>> $neighbors region_map neighbors (region_key)
     */
    public static function paintingFor(string $regionKey, array $nodes, array $neighbors): ?array
    {
        $p = self::PAINTINGS[$regionKey] ?? null;
        if ($p === null) {
            return null;
        }
        $spots = [];
        foreach ($nodes as $n) {
            $k = (string) ($n['key'] ?? '');
            if (isset($p['nodes'][$k])) {
                [$x, $y] = $p['nodes'][$k];
                $spots[] = ['id' => (int) $n['id'], 'key' => $k, 'x' => $x, 'y' => $y];
            }
        }
        $roads = [];
        $seen = [];
        foreach ($neighbors as $nb) {
            $k = (string) ($nb['region_key'] ?? '');
            if (isset($p['neighbors'][$k]) && !isset($seen[$k])) {
                $seen[$k] = true;
                [$x, $y] = $p['neighbors'][$k];
                $roads[] = ['region_key' => $k, 'x' => $x, 'y' => $y];
            }
        }
        return [
            'art'       => $p['art'],
            'width'     => $p['width'],
            'height'    => $p['height'],
            'nodes'     => $spots,
            'neighbors' => $roads,
        ];
    }
}
