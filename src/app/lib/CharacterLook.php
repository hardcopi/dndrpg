<?php
/**
 * A character's look, chosen from the baked look library and filed as art.
 *
 * The character creator offers a closed set of choices — gender, hair style,
 * hair colour, outfit, outfit colour — over a race, and every combination it
 * can show has already been drawn: tools/bake_looks.py baked them into static
 * WebP under assets/looks/v1, four pictures each (full, bust, fig, face). So a
 * look is not a picture a player sends, the way ModelPortrait's are. It is a
 * handful of words, and the picture is looked up.
 *
 * That makes this class two halves:
 *
 *  - The RULES — normalise(), key(), paths(). They have to agree with the
 *    creator and the bake tool to the character, because the creator's key is
 *    what names a picture, and a key spelled one way here and another there is
 *    a look that resolves to nothing. They are not restated: the closed lists
 *    and the spelling rules are read out of assets/data/looks_options.json,
 *    which bake_looks.py writes from the same tables it bakes with.
 *    tools/test_character_look.php round-trips every entry in the library's
 *    manifest through them, which is what keeps the two honest.
 *
 *  - The BAKE — bake(). A sprite_key is four files, `<key>.png` and
 *    `<key>_sheet.png` for the map and `<key>_bust.png` and `<key>_face.png`
 *    for portraits, and every consumer in the game builds its URL out of the
 *    key and expects the rest to be there (see ModelPortrait's header, which
 *    is the same argument). The look adds a fifth, `<key>_fig.png`, for the
 *    battle board. The library's WebP is decoded with GD and written as PNG,
 *    never copied: the namespace under assets/images/npcs is PNG by contract,
 *    and what goes in there is a file this process drew at a size this file
 *    checked, which is ModelPortrait's rule even though the source is ours.
 *
 * Race comes from the CHARACTER, never from the request. The library is
 * filed by race, so a request that named its own race could dress a dwarf in
 * an elf's face and the sheet beside the portrait would disagree about who
 * somebody is — the same reason the 3D creator does not draw a race picker
 * for a character that already has one.
 */

declare(strict_types=1);

class CharacterLook
{
    /** Where the bake writes. The one folder ModelPortrait and Paperdoll already write. */
    private const OUT = 'assets/images/npcs';

    /** Library and options, relative to the web root. */
    private const LIBRARY = 'assets/looks/v1';
    private const OPTIONS = 'assets/data/looks_options.json';

    /**
     * Not Paperdoll's `pc_`. A baked look and a baked paperdoll are different
     * pictures, and keeping the prefixes apart is what lets the cleanup below
     * delete one kind without touching the other.
     */
    public const PREFIX = 'lk_';

    /** The sizes the bake tool writes, checked on the way through. */
    private const SIZES = [
        'bust' => [384, 512],
        'face' => [144, 144],
        'fig'  => [192, 320],
    ];

    /** Every kind a library entry has, and the four exists() asks for. */
    public const KINDS = ['full', 'bust', 'fig', 'face'];

    /** Decoded once per process: the options never change under a running request. */
    private static array $optionsCache = [];

    private array $opt;
    private string $library;
    private string $out;

    /**
     * @param string|null $library Library root; defaults to assets/looks/v1 under the web root.
     * @param string|null $out     Where baked PNGs go; defaults to assets/images/npcs.
     * @param string|null $options looks_options.json; defaults to the shipped one.
     *
     * The overrides exist for tools/test_character_look.php, which bakes from a
     * fixture library into a temp folder so a test run cannot leave files in
     * the folder the game serves.
     */
    public function __construct(?string $library = null, ?string $out = null, ?string $options = null)
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $this->library = rtrim($library ?? "{$root}/" . self::LIBRARY, '/');
        $this->out = rtrim($out ?? "{$root}/" . self::OUT, '/');
        $this->opt = self::options($options ?? "{$root}/" . self::OPTIONS);
    }

    private static function options(string $path): array
    {
        if (!isset(self::$optionsCache[$path])) {
            $opt = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (!is_array($opt) || empty($opt['races']) || empty($opt['outfits'])) {
                throw new RuntimeException('The look options are missing or unreadable.');
            }
            self::$optionsCache[$path] = $opt;
        }
        return self::$optionsCache[$path];
    }

    // ------------------------------------------------------------------ rules

    /**
     * Validate a set of picks and fill in what the creator would have.
     *
     * Returns the same shape as a manifest entry's `choices` (less `pose`,
     * which there is one of): gender, race, hair_style (the label, as the
     * creator spells it), hair_color, outfit, outfit_color. Hair style is
     * accepted as either the label or its style key, because the label is what
     * the creator stores and the key is what a hand-written client will reach
     * for; it is stored as the label either way.
     *
     * The forced nulls are the creator's own rules: a bald or hooded head has
     * no hair colour to show, a Dragonborn has no hair at all, and the tunic
     * comes in one colour. They are forced rather than refused because a
     * client that sends a colour it left selected when the style changed has
     * not asked for anything impossible, only for something that is not drawn.
     *
     * @throws InvalidArgumentException with a sentence a player can read.
     */
    public function normalise(array $picks, string $race, ?string $class = null): array
    {
        $race = $this->creatorRace($race);

        $gender = strtolower(trim((string) ($picks['gender'] ?? '')));
        if ($gender === '') {
            throw new InvalidArgumentException('Choose whether they look masculine or feminine.');
        }
        if (!in_array($gender, $this->opt['genders'], true)) {
            throw new InvalidArgumentException('That is not one of the looks on offer.');
        }

        $style = null;
        $colour = null;
        if (!in_array($race, $this->opt['hairless_races'] ?? [], true)) {
            $style = $this->styleLabel((string) ($picks['hair_style'] ?? ''));
            if (!in_array($style, $this->opt['no_colour_styles'] ?? [], true)) {
                $colour = $this->hairColour((string) ($picks['hair_color'] ?? ''));
            }
        }

        $outfitKey = trim((string) ($picks['outfit'] ?? ''));
        if ($outfitKey === '') {
            // A Barbarian in mage robes is a picture arguing with the sheet
            // beside it, so a look that says nothing about clothes is dressed
            // for the class. A class the table does not name gets the tunic,
            // which every look can wear.
            $outfitKey = (string) ($this->opt['class_outfit'][$class ?? ''] ?? 'tunic');
        }
        $outfit = $this->outfit($outfitKey);

        $outfitColour = null;
        if ($outfit['key'] !== 'tunic') {
            $want = trim((string) ($picks['outfit_color'] ?? ''));
            $outfitColour = $want === '' ? $outfit['colours'][0] : $this->outfitColour($outfit, $want);
        }

        return [
            'gender'       => $gender,
            'race'         => $race,
            'hair_style'   => $style,
            'hair_color'   => $colour,
            'outfit'       => $outfit['key'],
            'outfit_color' => $outfitColour,
        ];
    }

    /**
     * The creator's key for a normalised look.
     *
     *     p2:look:<gender>_<race>/<stem>[|<outfit>__<colour>]
     *     p2:base:<gender>_<race>[|<outfit>__<colour>]       hairless races
     *
     * No `|variant` for the tunic: the tunic is what the creator's plain looks
     * already wear, and their keys predate outfits.
     */
    public function key(array $norm): string
    {
        $char = $this->charOf($norm);
        $key = $this->isHairless($norm) ? "p2:base:{$char}" : "p2:look:{$char}/" . $this->stemOf($norm);
        $variant = $this->variantOf($norm);
        return $variant === 'tunic' ? $key : "{$key}|{$variant}";
    }

    /**
     * Library-relative paths of a look's four pictures, keyed by kind.
     *
     * `<char>/<stem>/<variant>.<kind>.webp` — path_template in the options, less
     * the library's own folder.
     */
    public function paths(array $norm): array
    {
        $dir = $this->charOf($norm) . '/' . ($this->isHairless($norm) ? 'base' : $this->stemOf($norm));
        $variant = $this->variantOf($norm);
        $paths = [];
        foreach (self::KINDS as $kind) {
            $paths[$kind] = "{$dir}/{$variant}.{$kind}.webp";
        }
        return $paths;
    }

    /**
     * Whether the library holds all four pictures of this look.
     *
     * The rules can name a look the library does not have — the closed lists
     * multiply out to a few more combinations than the creator resolves, and a
     * trial library holds ninety-odd of 12,892 — so a look that normalises is not
     * yet a look that can be worn. The manifest lists an entry only when all
     * four of its files exist; this asks the same question of the disk.
     */
    public function exists(array $norm): bool
    {
        foreach ($this->paths($norm) as $rel) {
            if (!is_file("{$this->library}/{$rel}")) {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------- bake

    /**
     * Write a character's look into the sprite namespace and return the key.
     *
     * The key is `lk_<id>_<hash6>`, hash6 being the first six hex digits of
     * sha1(look key). The id makes it this character's and nobody else's —
     * CharacterGenerator::setAvatar() refuses an lk_ key with another id in it
     * — and the hash makes a re-dress a NEW URL. Overwriting `lk_7_bust.png`
     * in place would leave every browser that has seen the old one showing it
     * until its cache let go, which reads as the change not having saved.
     *
     * All four pictures are decoded before anything is written or deleted, so
     * a library file that fails to read leaves the character exactly as they
     * were rather than half re-dressed. Then the character's previous lk_
     * files go, then the new ones are written.
     *
     * @param int    $characterId      Whose look this is. The only source of the filename.
     * @param array  $norm             As normalise() returned it.
     * @param string $fallbackSpriteKey The key they would otherwise wear, for the map sprite.
     */
    public function bake(int $characterId, array $norm, string $fallbackSpriteKey): string
    {
        if (!function_exists('imagecreatefromwebp')) {
            throw new RuntimeException('This server\'s GD has no WebP support, so a look cannot be baked.');
        }
        if ($characterId <= 0) {
            throw new RuntimeException('A look needs a character to belong to.');
        }
        if (!$this->exists($norm)) {
            throw new InvalidArgumentException('That look has not been drawn yet. Try another combination.');
        }

        $key = self::keyFor($characterId, $this->key($norm));
        $paths = $this->paths($norm);

        $images = [];
        try {
            foreach (self::SIZES as $kind => [$w, $h]) {
                $im = @imagecreatefromwebp("{$this->library}/{$paths[$kind]}");
                if (!$im || imagesx($im) !== $w || imagesy($im) !== $h) {
                    throw new RuntimeException("The look library's {$kind} picture for this look is unreadable.");
                }
                // The figure stands on the board over the floor; its alpha is
                // the whole of its outline. Bust and face are opaque in the
                // library and stay so, but saving alpha on them costs nothing.
                imagealphablending($im, false);
                imagesavealpha($im, true);
                $images[$kind] = $im;
            }

            if (!is_dir($this->out) && !@mkdir($this->out, 0775, true)) {
                throw new RuntimeException('The portrait folder is not writable.');
            }
            $this->forget($characterId);
            foreach ($images as $kind => $im) {
                if (!imagepng($im, "{$this->out}/{$key}_{$kind}.png")) {
                    throw new RuntimeException('The portrait folder is not writable.');
                }
            }
        } finally {
            foreach ($images as $im) {
                imagedestroy($im);
            }
        }

        $this->inheritWalkSprite($key, $fallbackSpriteKey);
        // No busts.json line. That file is the dialogue actors' expression
        // counts, read when content is imported; a player character is never
        // an actor, and an actor missing from it counts as one bust anyway.
        // It is also in git and deploy.sh pushes it, so a line written here
        // on hermes would be overwritten by the next deploy and dragged into
        // the repo by the next pull — drift with nothing to show for it.

        return $key;
    }

    /** `lk_<id>_<first six hex of sha1(look key)>`. */
    public static function keyFor(int $characterId, string $lookKey): string
    {
        return self::PREFIX . $characterId . '_' . substr(sha1($lookKey), 0, 6);
    }

    /**
     * The character id inside an lk_ sprite key, or null if it is not one.
     *
     * Exactly the shape keyFor() makes and nothing looser, because this is
     * what setAvatar() asks before letting a character wear one.
     */
    public static function ownerOf(string $spriteKey): ?int
    {
        return preg_match('/^lk_([1-9][0-9]*)_[0-9a-f]{6}$/', $spriteKey, $m) ? (int) $m[1] : null;
    }

    /**
     * Delete every file a previous look left for this character.
     *
     * By pattern over a directory listing, never a glob: `lk_1_*` would also
     * match `lk_12_*`, and one player re-dressing would take a stranger's face
     * off. The pattern pins the id, the underscore after it, and the six hex
     * digits, so `lk_1_` cannot reach `lk_12_`.
     */
    private function forget(int $characterId): void
    {
        $mine = '/^lk_' . $characterId . '_[0-9a-f]{6}(?:_bust|_face|_fig|_sheet)?\.png$/';
        foreach (scandir($this->out) ?: [] as $file) {
            if (preg_match($mine, $file)) {
                @unlink("{$this->out}/{$file}");
            }
        }
    }

    /**
     * Give the new key the walk sprite of the one it replaces — the class
     * sprite, usually. ModelPortrait does exactly this and says why: the map
     * asks for `<key>.png` and `<key>_sheet.png` by name, and a missing file
     * there is a hole in the world where a character should be standing.
     */
    private function inheritWalkSprite(string $key, string $fallback): void
    {
        $fallback = preg_replace('/[^a-z0-9_\-]/i', '', $fallback) ?? '';
        if ($fallback === '' || $fallback === $key) {
            return;
        }
        foreach (['', '_sheet'] as $suffix) {
            $from = "{$this->out}/{$fallback}{$suffix}.png";
            $to   = "{$this->out}/{$key}{$suffix}.png";
            if (is_file($from)) {
                @copy($from, $to);
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The creator's name for a game race.
     *
     * They are the same strings today — the creator was drawn from the
     * `races` table, Sarsen included — so this is a check rather than a map.
     * A race added to the table before the library has pictures of it is
     * refused here in words, rather than resolving to a path that is not
     * there.
     */
    private function creatorRace(string $race): string
    {
        foreach ($this->opt['races'] as $r) {
            if (strcasecmp($r, trim($race)) === 0) {
                return $r;
            }
        }
        throw new InvalidArgumentException("There are no looks drawn for a {$race} yet.");
    }

    private function styleLabel(string $want): string
    {
        $want = trim($want);
        if ($want === '') {
            throw new InvalidArgumentException('Choose a hair style.');
        }
        foreach ($this->opt['hair_styles'] as $s) {
            if ($want === $s['label'] || $want === $s['style_key']) {
                return $s['label'];
            }
        }
        throw new InvalidArgumentException('That hair style is not one of the ones on offer.');
    }

    private function hairColour(string $want): string
    {
        $want = str_replace('_', ' ', trim($want));
        if ($want === '') {
            throw new InvalidArgumentException('Choose a hair colour.');
        }
        if (!in_array($want, $this->opt['hair_colours'], true)) {
            throw new InvalidArgumentException('That hair colour is not one of the ones on offer.');
        }
        return $want;
    }

    private function outfit(string $key): array
    {
        foreach ($this->opt['outfits'] as $o) {
            if ($o['key'] === $key) {
                return $o;
            }
        }
        throw new InvalidArgumentException('That outfit is not one of the ones on offer.');
    }

    private function outfitColour(array $outfit, string $want): string
    {
        $want = str_replace('_', ' ', $want);
        if (!in_array($want, $outfit['colours'], true)) {
            throw new InvalidArgumentException("{$outfit['label']} does not come in {$want}.");
        }
        return $want;
    }

    private function charOf(array $norm): string
    {
        return "{$norm['gender']}_{$norm['race']}";
    }

    private function isHairless(array $norm): bool
    {
        return in_array($norm['race'], $this->opt['hairless_races'] ?? [], true);
    }

    /** stem_rule: `<style_key>__<colour>` or `<style_key>`; spaces in a colour become `_`. */
    private function stemOf(array $norm): string
    {
        $styleKey = null;
        foreach ($this->opt['hair_styles'] as $s) {
            if ($s['label'] === $norm['hair_style']) {
                $styleKey = $s['style_key'];
            }
        }
        if ($styleKey === null) {
            throw new InvalidArgumentException('That hair style is not one of the ones on offer.');
        }
        return $norm['hair_color'] === null
            ? $styleKey
            : $styleKey . '__' . str_replace(' ', '_', $norm['hair_color']);
    }

    /** variant_rule: `tunic`, or `<outfit>__<colour>` with spaces as `_`. */
    private function variantOf(array $norm): string
    {
        return $norm['outfit'] === 'tunic'
            ? 'tunic'
            : $norm['outfit'] . '__' . str_replace(' ', '_', (string) $norm['outfit_color']);
    }
}
