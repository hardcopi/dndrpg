<?php
/**
 * The character creator's wardrobe, and the compositor that bakes a choice into
 * a sprite the renderer already knows how to draw.
 *
 * The T&C Humans pack ships bodies and clothing as separate layers, each with a
 * complete animation set at identical dimensions. tools/slice_paperdoll.py cuts
 * them into assets/images/paperdoll/ with an index; this reads that index, checks
 * a chosen set is wearable, and stacks it into `<key>_sheet.png`, `<key>.png`,
 * `<key>_face.png` and `<key>_bust.png` under assets/images/npcs/.
 *
 * Baking rather than stacking at runtime is the whole design. The output is
 * indistinguishable from art cut whole out of the pack, so the map renderer, the
 * party rail, the combat board, the dialogue theatre and the MVsv clips all work
 * with no changes at all — a created character is just another sprite_key.
 * Stacking eight layers per entity in the DOM would have meant touching every
 * one of those, and renderMap is already the most expensive thing in the client.
 *
 * Two rules the layers depend on, both easy to break:
 *
 *   - Nothing is trimmed until after compositing. Every layer shares the base
 *     body's coordinate space, so cropping a hat to its own bounding box moves
 *     it onto the forehead.
 *   - Composite with alpha, never paste with a mask. Pasting punches the layer's
 *     own transparency through everything already drawn underneath.
 */

declare(strict_types=1);

class Paperdoll
{
    /** Where the sliced layers live, relative to the web root. */
    private const LIB = 'assets/images/paperdoll';

    /** Where a baked character's art is written. Same folder as authored NPCs. */
    private const OUT = 'assets/images/npcs';

    /**
     * Prefix for generated keys.
     *
     * Keeps a created character's art in a namespace authored content cannot
     * collide with: tools/load_content.py validates a `sprite_key` against what
     * is on disk, and a player who happened to build something called `cleric`
     * would otherwise overwrite Leyanne.
     */
    private const PREFIX = 'pc_';

    /*
     * Must match tools/slice_paperdoll.py and tools/slice_assets.py. A baked
     * character stands beside actors cut by the other tool -- in the same party
     * rail, the same conversation -- so a cap that disagrees is visible.
     */
    public const BUST_PX = 512;
    public const FACE_PX = 144;

    /** @var array|null The decoded index, read once per request. */
    private static ?array $index = null;

    public static function index(): array
    {
        if (self::$index === null) {
            $path = APP_ROOT . '/' . self::LIB . '/index.json';
            if (!is_file($path)) {
                throw new RuntimeException(
                    'No paperdoll library. Run tools/slice_paperdoll.py first.'
                );
            }
            self::$index = json_decode((string) file_get_contents($path), true) ?: [];
        }
        return self::$index;
    }

    /**
     * What the creator may offer, as the picker needs it.
     *
     * Each variant carries the URL of its untrimmed 128px preview frame, because
     * the browser stacks those for the live preview and cannot enumerate a
     * directory.
     */
    public static function catalogue(): array
    {
        $index = self::index();
        $out = ['layer_order' => $index['layer_order'] ?? [], 'sexes' => []];

        foreach ($index['sexes'] ?? [] as $sex => $entry) {
            $cats = [];
            foreach ($entry['categories'] ?? [] as $cat => $meta) {
                $styles = [];
                foreach ($meta['styles'] ?? [] as $style => $rec) {
                    $colours = [];
                    foreach ($rec['colours'] ?? [] as $c) {
                        $colours[] = [
                            'key'     => $c['key'],
                            'alt'     => $c['alt'] ?? null,
                            'preview' => self::LIB . '/' . $sex . '/' . $c['key'] . '_prev.png',
                        ];
                    }
                    if ($colours) {
                        $styles[] = ['style' => (string) $style, 'colours' => $colours];
                    }
                }
                if ($styles) {
                    $cats[] = [
                        'category' => $cat,
                        'label'    => $meta['label'] ?? ucfirst($cat),
                        'optional' => !empty($meta['optional']),
                        'styles'   => $styles,
                    ];
                }
            }
            // Presented in layer order so the picker reads bottom-up the way the
            // character is assembled, which makes the preview legible as it fills in.
            usort($cats, static function ($a, $b) use ($index) {
                $order = $index['layer_order'] ?? [];
                return array_search($a['category'], $order, true)
                     <=> array_search($b['category'], $order, true);
            });
            $out['sexes'][$sex] = $cats;
        }
        return $out;
    }

    /**
     * Check a chosen set, and return it in layer order.
     *
     * Rejects rather than repairs. A set that names a layer which does not exist
     * is either a stale client or someone editing the request, and silently
     * dropping the layer would bake a character subtly different from the one the
     * player was shown.
     *
     * @param array $picks category => variant key
     * @return array<int, array{category:string, key:string}> bottom layer first
     */
    public static function resolve(string $sex, array $picks): array
    {
        $index = self::index();
        $entry = $index['sexes'][$sex] ?? null;
        if (!$entry) {
            throw new InvalidArgumentException("No paperdoll library for '{$sex}'.");
        }

        // Every variant key this sex offers, and the category it belongs to.
        $legal = [];
        foreach ($entry['categories'] as $cat => $meta) {
            foreach ($meta['styles'] as $rec) {
                foreach ($rec['colours'] as $c) {
                    $legal[$c['key']] = $cat;
                }
            }
        }

        $chosen = [];
        foreach ($picks as $cat => $key) {
            if ($key === null || $key === '' || $key === 'none') {
                continue;
            }
            if (!isset($legal[$key])) {
                throw new InvalidArgumentException("No such layer: {$key}");
            }
            if ($legal[$key] !== $cat) {
                throw new InvalidArgumentException(
                    "{$key} is {$legal[$key]}, not {$cat}"
                );
            }
            if (isset($chosen[$cat])) {
                throw new InvalidArgumentException("Two choices for {$cat}.");
            }
            $chosen[$cat] = $key;
        }

        $optional = $index['optional'] ?? [];
        foreach ($entry['categories'] as $cat => $meta) {
            if (!isset($chosen[$cat]) && !in_array($cat, $optional, true)) {
                throw new InvalidArgumentException("A {$cat} is required.");
            }
        }

        // A dress and trousers are both legal to own and absurd to wear at once.
        if (isset($chosen['dress']) && isset($chosen['pants'])) {
            throw new InvalidArgumentException('Wear a dress or trousers, not both.');
        }

        $ordered = [];
        foreach ($index['layer_order'] ?? [] as $cat) {
            if (isset($chosen[$cat])) {
                $ordered[] = ['category' => $cat, 'key' => $chosen[$cat]];
            }
        }
        return $ordered;
    }

    /**
     * Bake a set into a sprite_key, and return it.
     *
     * Every output is composited from the same ordered layer list, so the map
     * sprite and the dialogue bust cannot disagree about what the character is
     * wearing.
     */
    public static function bake(string $sex, array $picks, string $key): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD is not available, so appearance cannot be baked.');
        }
        $layers = self::resolve($sex, $picks);
        $dir = APP_ROOT . '/' . self::LIB . '/' . $sex;
        $out = APP_ROOT . '/' . self::OUT;

        // The walk sheet is the one output that must exist; everything else is a
        // portrait and a character can survive without one.
        $sheet = self::stack($dir, $layers, '_sheet');
        if (!$sheet) {
            throw new RuntimeException('No walk sheet could be built for that appearance.');
        }
        imagepng($sheet, "{$out}/{$key}_sheet.png");

        // The standing frame the map draws: the first south cell, trimmed now
        // that the layers are one image.
        $still = imagecreatetruecolor(128, 128);
        self::prepare($still);
        imagecopy($still, $sheet, 0, 0, 0, 0, 128, 128);
        $trimmed = self::trim($still);
        imagepng($trimmed, "{$out}/{$key}.png");
        imagedestroy($still);
        imagedestroy($trimmed);
        imagedestroy($sheet);

        foreach ([['_bust', self::BUST_PX], ['_face', self::FACE_PX]] as [$suffix, $cap]) {
            $im = self::stack($dir, $layers, $suffix);
            if (!$im) {
                continue;
            }
            $t = self::trim($im);
            $scaled = self::fit($t, $cap);
            imagepng($scaled, "{$out}/{$key}{$suffix}.png");
            imagedestroy($im);
            imagedestroy($t);
            imagedestroy($scaled);
        }

        // A created character has one bust, so busts.json has to say so or
        // DialogEngine will offer expressions that do not exist.
        self::recordBustCount($key);

        return $key;
    }

    /** The generated key for a character. */
    public static function keyFor(int $characterId): string
    {
        return self::PREFIX . $characterId;
    }

    // -----------------------------------------------------------------------

    /**
     * Composite one output from the ordered layers.
     *
     * A layer with no file for this output is skipped, not an error: an accessory
     * that does not appear in a portrait simply is not drawn in it.
     */
    private static function stack(string $dir, array $layers, string $suffix)
    {
        $canvas = null;
        foreach ($layers as $layer) {
            $path = "{$dir}/{$layer['key']}{$suffix}.png";
            if (!is_file($path)) {
                continue;
            }
            $im = @imagecreatefrompng($path);
            if (!$im) {
                continue;
            }
            imagesavealpha($im, true);

            if ($canvas === null) {
                $canvas = imagecreatetruecolor(imagesx($im), imagesy($im));
                self::prepare($canvas);
            }
            if (imagesx($im) !== imagesx($canvas) || imagesy($im) !== imagesy($canvas)) {
                // The layers only stack because they share a coordinate space.
                // One that does not belongs to a different pack or a bad slice,
                // and scaling it to fit would misalign it rather than fix it.
                //
                // This used to `continue`, which meant a half-rebuilt library --
                // say a re-cut that changed the bust cap but skipped some
                // variants -- silently baked a character with a garment missing
                // and told nobody. The player got a topless paladin and the log
                // got nothing. The condition is unrecoverable either way, so say
                // so: bake() is called from a request, so this surfaces as an
                // API error rather than as wrong art.
                $msg = sprintf(
                    'Paperdoll layer %s is %dx%d but the %s canvas is %dx%d — the '
                    . 'layer library is mixed-scale. Re-cut it with '
                    . 'tools/slice_paperdoll.py (delete the directories first; '
                    . 'do not use --resume).',
                    basename($path), imagesx($im), imagesy($im), $suffix ?: 'sheet',
                    imagesx($canvas), imagesy($canvas)
                );
                imagedestroy($im);
                imagedestroy($canvas);
                throw new RuntimeException($msg);
            }
            imagecopy($canvas, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
            imagedestroy($im);
        }
        return $canvas;
    }

    /** A transparent truecolour canvas that will composite rather than flatten. */
    private static function prepare($im): void
    {
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
    }

    /**
     * Crop away fully transparent margins.
     *
     * Done once, on the finished composite. GD has imagecropauto but its
     * threshold mode is unreliable on alpha, so the bounding box is walked here
     * where the rule is visible.
     */
    private static function trim($im)
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 120) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }
        if ($maxX < 0) {
            // Entirely transparent. Hand back a copy rather than nothing, so the
            // caller does not have to special-case an impossible character.
            $copy = imagecreatetruecolor($w, $h);
            self::prepare($copy);
            imagecopy($copy, $im, 0, 0, 0, 0, $w, $h);
            return $copy;
        }

        $cw = $maxX - $minX + 1;
        $ch = $maxY - $minY + 1;
        $out = imagecreatetruecolor($cw, $ch);
        self::prepare($out);
        imagecopy($out, $im, 0, 0, $minX, $minY, $cw, $ch);
        return $out;
    }

    /** Scale so the long edge is at most $cap, preserving aspect. */
    private static function fit($im, int $cap)
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $scale = min(1.0, $cap / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        self::prepare($out);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }

    /**
     * Tell busts.json this key has exactly one expression.
     *
     * Merged rather than replaced, for the same reason slice_assets.py merges it:
     * rewriting the file would tell the content loader that every authored actor
     * has no busts, and fail validation on content that was fine a moment ago.
     */
    private static function recordBustCount(string $key): void
    {
        $path = APP_ROOT . '/' . self::OUT . '/busts.json';
        $counts = [];
        if (is_file($path)) {
            $counts = json_decode((string) file_get_contents($path), true) ?: [];
        }
        $counts[$key] = 1;
        ksort($counts);
        file_put_contents($path, json_encode($counts, JSON_PRETTY_PRINT) . "\n");
    }
}
