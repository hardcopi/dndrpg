<?php

declare(strict_types=1);

/**
 * The 3D look of a character: which Sidekick parts they wear, how their body
 * is shaped, and what colour everything is.
 *
 * Two things paint this — the Unity client and the character embed on the web
 * — and neither of them is trusted. What arrives here is whatever a browser
 * console felt like sending, so every field is checked, clamped, or dropped,
 * and what is stored is only ever the canonical shape this file writes.
 *
 * What is deliberately NOT checked is whether a part name exists. The list of
 * parts lives in the Unity build, not in this database, and a server that
 * validated against a copy of that list would have to be redeployed every time
 * an outfit pack was added — and would reject saves in the meantime. The
 * renderer skips a part it does not have, so an unknown name costs a hat.
 *
 * Kept apart from `characters.appearance_json`, which is the 2D paperdoll and
 * still what the browser game draws. The two describe the same person in two
 * media; neither is derivable from the other.
 */
final class SidekickAppearance
{
    /** Bumped when an old recipe would paint WRONGLY. Adding a field does not bump it. */
    public const VERSION = 1;

    /** The column. Nullable; a character who has never opened the creator has none. */
    public const COLUMN = 'sidekick_json';

    /**
     * A ceiling on stored JSON. A full recipe is about 1.4 KB — 38 parts, five
     * outfit colours and a handful of overrides. Four is room to grow twice
     * over and still far short of anything worth storing in a row.
     */
    public const MAX_BYTES = 4096;

    private const MAX_PARTS = 48;
    private const MAX_COLOURS = 64;
    private const OUTFIT_CHANNELS = 5;

    /**
     * Cleans a submitted recipe into the only shape this class ever stores.
     *
     * Returns null when there is nothing usable in it — no parts means no
     * character, and storing an empty recipe would make "never created one"
     * and "created a blank one" indistinguishable.
     *
     * @param mixed $input
     * @return array<string, mixed>|null
     */
    public static function normalise($input): ?array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (!is_array($input)) {
            return null;
        }

        $parts = [];
        if (isset($input['parts']) && is_array($input['parts'])) {
            foreach ($input['parts'] as $type => $name) {
                if (count($parts) >= self::MAX_PARTS) {
                    break;
                }
                $type = self::token((string) $type, 32);
                $name = self::token(is_string($name) ? $name : '', 80);
                if ($type === null || $name === null) {
                    continue;
                }
                $parts[$type] = $name;
            }
        }

        if ($parts === []) {
            return null;
        }
        ksort($parts);

        $look = [
            'v'       => self::VERSION,
            'species' => self::species($input['species'] ?? null),
            'race'    => self::race($input['race'] ?? null),
            'parts'   => $parts,
            'shape'   => self::shape($input['shape'] ?? null),
        ];

        $skin = self::label($input['skin'] ?? null);
        if ($skin !== null) {
            $look['skin'] = $skin;
        }

        $outfit = self::outfit($input['outfit'] ?? null);
        if ($outfit !== []) {
            $look['outfit'] = $outfit;
        }

        $colours = self::colours($input['colors'] ?? null);
        if ($colours !== []) {
            $look['colors'] = $colours;
        }

        return $look;
    }

    /**
     * @param array<string, mixed> $look
     */
    public static function encode(array $look): string
    {
        return (string) json_encode($look, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Reads a stored recipe back.
     *
     * Runs it through normalise() rather than trusting the column, because a
     * row written by an older version of this file, or by hand, is the same
     * problem as a row written by a hostile client.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        return self::normalise(json_decode($json, true));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function load(PDO $db, int $characterId): ?array
    {
        self::assertColumn($db);
        $stmt = $db->prepare('SELECT ' . self::COLUMN . ' FROM characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $stored = $stmt->fetchColumn();
        return is_string($stored) ? self::decode($stored) : null;
    }

    /**
     * Stores a recipe and hands back what was actually stored, which is what
     * the client should adopt: normalising can drop things, and a creator that
     * kept showing a colour the server threw away would save it again forever.
     *
     * @param array<string, mixed> $look
     * @return array<string, mixed>
     */
    public static function save(PDO $db, int $characterId, array $look): array
    {
        self::assertColumn($db);
        $json = self::encode($look);
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('That appearance is too large to store.');
        }
        $stmt = $db->prepare('UPDATE characters SET ' . self::COLUMN . ' = ? WHERE id = ?');
        $stmt->execute([$json, $characterId]);
        return $look;
    }

    /**
     * A character with no recipe of their own is not a bug and not an empty
     * space: everybody made before this shipped is one. Callers use this to
     * decide whether to open the creator on a default rather than on nothing.
     */
    public static function exists(PDO $db, int $characterId): bool
    {
        return self::load($db, $characterId) !== null;
    }

    /**
     * The migration is a separate file that somebody has to run. Saying so
     * plainly beats a PDO exception about an unknown column, which is what
     * this used to be and what sent the first person who hit it to the wrong
     * half of the codebase.
     */
    private static function assertColumn(PDO $db): void
    {
        static $checked = null;
        if ($checked === true) {
            return;
        }
        $stmt = $db->query('SHOW COLUMNS FROM characters LIKE ' . $db->quote(self::COLUMN));
        if ($stmt === false || $stmt->fetch() === false) {
            throw new RuntimeException(
                'characters.' . self::COLUMN . ' does not exist. '
                . 'Run sql/migrations/2026-08-20-sidekick-appearance.sql.'
            );
        }
        $checked = true;
    }

    private static function species($value): string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';
        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : 'HN';
    }

    /**
     * The races a portrait can be.
     *
     * Written out rather than read from Rules::RACE_FEATURES, which looks like
     * the same list and is not: it is keyed by races that *have* a racial
     * feature, so plain Human is absent from it and validating against it
     * would reject the commonest character in the game. It also carries
     * subrace keys like 'Halfling/Stout', which a portrait has no use for —
     * nothing in the creator draws a Stout halfling differently.
     *
     * Must stay in step with Races.All in the Unity client. Nothing enforces
     * that across the two repositories; a race added on one side and not the
     * other saves as Human, which is visible immediately and loses only the
     * word.
     */
    private const RACES = [
        'Human', 'Elf', 'Half-Elf', 'Dwarf', 'Gnome', 'Halfling',
        'Half-Orc', 'Dragonborn', 'Tiefling', 'Sarsen',
    ];

    /**
     * The playable race, as the creator named it.
     *
     * Anything unrecognised — including a recipe saved before this field
     * existed — falls back to Human rather than being refused: a portrait with
     * the wrong word on it is still a portrait, and rejecting the save would
     * lose the whole character over one field.
     */
    private static function race($value): string
    {
        $name = is_string($value) ? trim($value) : '';
        foreach (self::RACES as $known) {
            if (strcasecmp($known, $name) === 0) {
                return $known;
            }
        }
        return 'Human';
    }

    /**
     * @return array{type: float, size: float, muscle: float}
     */
    private static function shape($value): array
    {
        $value = is_array($value) ? $value : [];
        return [
            'type'   => self::slider($value['type'] ?? 0),
            'size'   => self::slider($value['size'] ?? 0),
            'muscle' => self::slider($value['muscle'] ?? 0),
        ];
    }

    private static function slider($value): float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return 0.0;
        }
        $number = (float) $value;
        if (is_nan($number) || is_infinite($number)) {
            return 0.0;
        }
        return round(max(-100.0, min(100.0, $number)), 2);
    }

    /**
     * @return list<string>
     */
    private static function outfit($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $channels = [];
        $any = false;
        foreach (array_slice(array_values($value), 0, self::OUTFIT_CHANNELS) as $entry) {
            $hex = self::hex($entry);
            $channels[] = $hex ?? '';
            $any = $any || $hex !== null;
        }
        return $any ? $channels : [];
    }

    /**
     * @return array<string, string>
     */
    private static function colours($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $colours = [];
        foreach ($value as $property => $hex) {
            if (count($colours) >= self::MAX_COLOURS) {
                break;
            }
            $name = self::label($property);
            $clean = self::hex($hex);
            if ($name === null || $clean === null) {
                continue;
            }
            $colours[$name] = $clean;
        }
        ksort($colours);
        return $colours;
    }

    /** A Sidekick part or part-type name: letters, digits and underscores. */
    private static function token(string $value, int $max): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 ? $value : null;
    }

    /**
     * A name from the Sidekick catalogue as a human reads it — "Human - Tan",
     * "Eye Color Left". Spaces and punctuation, but nothing that could be
     * mistaken for markup by whatever ends up printing it.
     */
    private static function label($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9 \'\-\.\/&()]+$/', $value) === 1 ? $value : null;
    }

    /** Six hex digits, upper case, no hash — how the Sidekick database writes colours. */
    private static function hex($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = ltrim(trim($value), '#');
        if (strlen($value) === 3) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }
        if (!in_array(strlen($value), [6, 8], true)) {
            return null;
        }
        return ctype_xdigit($value) ? strtoupper($value) : null;
    }
}
