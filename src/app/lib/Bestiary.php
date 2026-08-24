<?php
/**
 * The shared monster catalogue, gathered into the shape a stat block is written
 * in.
 *
 * `monsters` is deliberately not scoped to a module — a goblin is a goblin —
 * so this class is the one place that asks the table what it holds. The
 * adventure book must not: a book that printed every creature would put the
 * Old City's bosses into Rivermark's appendix. AdventureBook still reaches
 * its own creatures through its encounters and then asks here only to format
 * them.
 *
 * PURE READS. Nothing here writes, nothing here is player state, and nothing
 * here renders HTML. `bestiary.php` and the adventure book's appendix draw
 * the same include over what this returns.
 *
 * It also means the whole catalogue can be built and inspected without
 * rendering any HTML, which is what `tools/test_bestiary.php` does.
 */

declare(strict_types=1);

final class Bestiary
{
    private const SAVE_LABELS = [
        'str' => 'Str',
        'dex' => 'Dex',
        'con' => 'Con',
        'int' => 'Int',
        'wis' => 'Wis',
        'cha' => 'Cha',
    ];

    private PDO $db;

    /** @var array<string, string>|null */
    private ?array $itemNames = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Every creature, once, in the order a manual writes them: challenge
     * then name. This is the whole book. A module's appendix is `ofKeys`.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM monsters ORDER BY challenge_rating, name'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->format($r), $rows);
    }

    /**
     * The creatures named by key, in the same order, or an empty list when
     * nothing is asked for. Unknown keys are dropped rather than invented.
     *
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    public function ofKeys(array $keys): array
    {
        $want = [];
        foreach ($keys as $k) {
            $k = (string) $k;
            if ($k !== '') {
                $want[$k] = true;
            }
        }
        if (!$want) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($want), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM monsters WHERE monster_key IN ({$marks})
              ORDER BY challenge_rating, name"
        );
        $stmt->execute(array_keys($want));
        return array_map(
            fn (array $r) => $this->format($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * A modifier as a stat block writes it: +3, -1, +0.
     *
     * Floor, not round: 11 is +0 and 9 is −1, which is the 5e rule and not a
     * display choice. Kept here so the page does no arithmetic.
     */
    public static function modOf(int $score): string
    {
        return sprintf('%+d', (int) floor(($score - 10) / 2));
    }

    /**
     * 1/8 rather than 0.125 — a challenge rating is written as a fraction.
     *
     * A loose tolerance on purpose: the column is DECIMAL and a bandit is
     * stored as 0.13, not 0.125. Tight matching printed "Challenge 0.13",
     * which is a number no stat block has ever carried.
     */
    public static function crText(mixed $cr): string
    {
        $v = (float) $cr;
        if ($v <= 0) {
            return '0';
        }
        foreach ([8 => '1/8', 4 => '1/4', 2 => '1/2'] as $d => $label) {
            if (abs($v - 1 / $d) < 0.02) {
                return $label;
            }
        }
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /**
     * The first word of a type line: "humanoid (goblinoid)" is humanoid.
     *
     * An index that grouped on the parenthetical would split goblins from
     * bandits and call that a kind. The parenthetical stays on the block.
     */
    public static function kindOf(?string $type): string
    {
        $t = strtolower(trim((string) $type));
        if ($t !== '' && preg_match('/^[a-z]+/', $t, $m) === 1) {
            return $m[0];
        }
        return 'creature';
    }

    /**
     * One row of `monsters`, with the lists a stat block actually prints.
     *
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    public function format(array $m): array
    {
        // Which file the creature's picture is in. Its own sprite key —
        // never assumed to be the monster key, because the pack's art is
        // keyed by sprite and several creatures shared one until the
        // bestiary appendix made that visible.
        $artKey = trim((string) ($m['sprite_key'] ?? '')) ?: (string) ($m['monster_key'] ?? '');
        $artFile = $artKey !== ''
            ? 'assets/images/monsters/' . $artKey . '_bust.png'
            : '';
        // Checked against the filesystem rather than assumed: a missing image
        // does not 404 on this vhost, it returns the homepage HTML with a
        // 200, and an <img> pointed at that prints as a broken icon.
        if ($artFile === '' || !is_file(APP_ROOT . '/' . $artFile)) {
            $artFile = '';
        }

        $m['art_key'] = $artKey;
        $m['art_file'] = $artFile;
        $m['cr_label'] = self::crText($m['challenge_rating'] ?? 0);
        $m['kind'] = self::kindOf(isset($m['type']) ? (string) $m['type'] : null);
        $m['traits_list'] = $this->blocks($m['traits'] ?? null);
        $m['actions_list'] = $this->blocks($m['actions'] ?? null);
        $m['legendary_list'] = $this->blocks($m['legendary_actions'] ?? null);
        $m['saves'] = $this->jsonMap($m['saves_json'] ?? null);
        $m['save_line'] = $this->saveLine($m['saves']);
        $m['resistances'] = $this->jsonList($m['resistances_json'] ?? null);
        $m['immunities'] = $this->jsonList($m['immunities_json'] ?? null);
        $m['vulnerabilities'] = $this->jsonList($m['vulnerabilities_json'] ?? null);
        $m['condition_immunities'] = $this->jsonList($m['condition_immunities_json'] ?? null);
        $m['loot_line'] = $this->lootLine($this->jsonObjects($m['loot_json'] ?? null));
        return $m;
    }

    /**
     * A monster's traits or actions, as a list of {name, text}.
     *
     * The column holds either a JSON list of objects or a blob of prose,
     * depending on how old the row is — the seeded bestiary and the authored
     * one disagree, and a stat block that printed `[{"name":...` because it
     * guessed wrong is worse than one that prints a paragraph.
     *
     * @return list<array{name: string, text: string}>
     */
    private function blocks(?string $raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $key => $entry) {
                if (is_array($entry)) {
                    $out[] = [
                        'name' => (string) ($entry['name'] ?? (is_string($key) ? $key : '')),
                        'text' => (string) ($entry['desc'] ?? $entry['text'] ?? $this->attackLine($entry)),
                    ];
                } else {
                    $out[] = ['name' => is_string($key) ? $key : '', 'text' => (string) $entry];
                }
            }
            return $out;
        }
        return [['name' => '', 'text' => $text]];
    }

    /**
     * An attack, in the sentence a stat block writes it in.
     *
     * The bestiary's `actions` column is not prose: it is the row CombatEngine
     * swings with — `{"name":"Scimitar","type":"melee","bonus":3,
     * "damage":"1d6+1","damage_type":"slashing"}`. Printed as-is that comes out
     * as a bare "Scimitar." with nothing after it, which is what the first
     * draft of the adventure book did for every creature in it.
     *
     * NO ARITHMETIC. The dice expression is printed as stored rather than
     * averaged into "4 (1d6+1)", which is how the published books write it —
     * because an average is a rule, this file is not where rules live (see
     * sheet_print.php making the same promise), and the game itself rolls the
     * expression rather than the average. Reach is the one number stated, and
     * it is stated only when the row does not carry a range of its own.
     */
    private function attackLine(array $a): string
    {
        if (!isset($a['damage']) && !isset($a['bonus']) && !isset($a['type'])) {
            return '';
        }
        $ranged = ($a['type'] ?? '') === 'ranged';
        $parts = [];
        $parts[] = $ranged ? 'Ranged attack' : 'Melee attack';
        if (isset($a['bonus'])) {
            $parts[] = sprintf('%+d to hit', (int) $a['bonus']);
        }
        // A range that already says "ft" must not grow a second one —
        // "40 ft." in the row printed "range 40 ft. ft".
        $range = trim((string) ($a['range'] ?? ''));
        $range = (string) preg_replace('/\s*ft\.?$/i', '', $range);
        // No full stop on this clause: the sentence closes below, and putting
        // one here printed "range 80/320 ft..".
        $parts[] = $ranged && $range !== ''
            ? 'range ' . $range . ' ft'
            : 'reach ' . ($a['reach'] ?? 5) . ' ft';

        $line = implode(', ', $parts) . '.';
        $damage = trim((string) ($a['damage'] ?? ''));
        if ($damage !== '' && $damage !== '0') {
            $line .= ' Hit: ' . $damage
                . ($a['damage_type'] ? ' ' . $a['damage_type'] : '') . ' damage.';
        }
        if (!empty($a['note'])) {
            $line .= ' ' . $a['note'];
        }
        return $line;
    }

    /**
     * @param array<string, mixed> $saves
     */
    private function saveLine(array $saves): string
    {
        $parts = [];
        $seen = [];
        foreach (self::SAVE_LABELS as $k => $label) {
            if (!array_key_exists($k, $saves) || !is_numeric($saves[$k])) {
                continue;
            }
            $parts[] = $label . ' ' . sprintf('%+d', (int) $saves[$k]);
            $seen[$k] = true;
        }
        foreach ($saves as $k => $v) {
            $key = strtolower((string) $k);
            if (isset($seen[$key]) || !is_numeric($v)) {
                continue;
            }
            $parts[] = (self::SAVE_LABELS[$key] ?? ucfirst($key))
                . ' ' . sprintf('%+d', (int) $v);
        }
        return implode(', ', $parts);
    }

    /**
     * Treasure as a short run: "1d6 gp (60%), Shortsword (15%)".
     *
     * Item KEYS are resolved to names. A content reload cannot orphan a loot
     * table (the row stores keys, not ids), but it can rename a thing, and a
     * manual that printed the key would be the one place the new name did not
     * appear.
     *
     * @param list<array<string, mixed>> $loot
     */
    private function lootLine(array $loot): string
    {
        $parts = [];
        foreach ($loot as $entry) {
            $bit = '';
            if (!empty($entry['gold'])) {
                $bit = trim((string) $entry['gold']) . ' gp';
            } elseif (!empty($entry['item'])) {
                $key = (string) $entry['item'];
                $bit = $this->itemNames()[$key] ?? str_replace('_', ' ', $key);
            }
            if ($bit === '') {
                continue;
            }
            if (isset($entry['chance']) && is_numeric($entry['chance']) && (int) $entry['chance'] < 100) {
                $bit .= ' (' . (int) $entry['chance'] . '%)';
            }
            $parts[] = $bit;
        }
        return implode(', ', $parts);
    }

    /** @return array<string, string> */
    private function itemNames(): array
    {
        if ($this->itemNames !== null) {
            return $this->itemNames;
        }
        $this->itemNames = [];
        foreach ($this->db->query('SELECT item_key, name FROM items')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->itemNames[(string) $row['item_key']] = (string) $row['name'];
        }
        return $this->itemNames;
    }

    /** @return list<string> */
    private function jsonList(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $v) {
            if (is_scalar($v)) {
                $out[] = (string) $v;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function jsonMap(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string, mixed>> */
    private function jsonObjects(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }
}
