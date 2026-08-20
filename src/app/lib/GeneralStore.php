<?php

/**
 * The general store.
 *
 * Every other shop in this game belongs to somebody: `shop_inventory` is keyed
 * by `npc_key`, so what is for sale and what it costs are facts about whose
 * counter you are standing at. That is right for the world and useless for the
 * character picker, which is a screen you are not standing anywhere on — and a
 * party that wants a second waterskin before walking into a fight should not
 * have to walk to Rivermark to find Quartermaster Oyle, in a module they may
 * not even be playing.
 *
 * So this is a shop with no shopkeeper. It reuses the machinery whole:
 * `InventoryService::buy()` reads `shop_inventory` by key and knows nothing
 * about this class, and `stock()` writes the rows it will find under a reserved
 * key with a leading underscore — the same mark PitEngine's scratch encounter
 * and DelveEngine's generated regions wear, and the same reason
 * `apply_content_safely.py` leaves anything wearing it alone.
 *
 * WHAT IT SELLS is a rule rather than a list, because a list would be a second
 * copy of the items table that stops matching it the first time content is
 * loaded. The rule is in `sells()` and it is deliberately narrow: ordinary
 * goods, the consumables a shop is for, and enchantment no stronger than +1.
 * Nothing here is the best thing in the game and nothing here should be — a
 * store you can reach from the front page in two clicks must not be where the
 * good equipment comes from, or the world stops being where you find it.
 */

declare(strict_types=1);

class GeneralStore
{
    /**
     * The counter this stock hangs off.
     *
     * Not an npc_key that exists: no `npcs` row, nobody to talk to, and nothing
     * in `content/` that mentions it. `shopFor()` and `buy()` take a string and
     * ask the shop table, so a shop with no shopkeeper costs nothing to invent.
     */
    public const NPC_KEY = '_general_store';

    /**
     * List price, exactly.
     *
     * Every authored merchant marks up — 100 to 150 per cent, and the spread is
     * part of what makes one shop different from another. This one is flat at
     * list, because it is not a character with a temperament; haggling with a
     * menu is a fiction nobody is served by.
     */
    private const MARKUP_PCT = 100;

    /**
     * The strongest enchantment on the shelf.
     *
     * Read as: every numeric bonus an item grants must be at most this. A +1
     * longsword is stock; a +2 anything is not, and neither is the ring that
     * would have been the reward for a dungeon.
     */
    public const MAX_BONUS = 1;

    /** Property keys that are a number of plusses. */
    private const BONUS_KEYS = [
        'attack_bonus', 'damage_bonus', 'ac_bonus', 'armor_bonus',
        'save_bonus', 'spell_attack_bonus', 'spell_dc_bonus',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Would a general store carry this?
     *
     * Public and static because it is the whole policy of this class and the
     * test drives it directly rather than through a database.
     *
     * @param array<string, mixed> $item a row of `items`
     */
    public static function sells(array $item): bool
    {
        // Nothing that is not for sale in the first place. A 0 gp row is a
        // quest item, a keepsake or junk; treasure is what you sell TO a shop,
        // not what you buy from one.
        if ((int) ($item['value_gp'] ?? 0) <= 0) {
            return false;
        }
        if (($item['item_type'] ?? '') === 'treasure') {
            return false;
        }
        $props = json_decode((string) ($item['properties'] ?? ''), true) ?: [];
        if (!empty($props['quest_item'])) {
            return false;
        }

        // Ordinary goods: rope, rations, a longsword, a shield. The bulk of it.
        if (($item['rarity'] ?? 'common') === 'common') {
            return true;
        }

        // Above common, two things get through and nothing else does.
        //
        // Consumables, because a shop that does not sell potions is not a shop;
        // they are spent the moment they work, so a strong one is a single good
        // afternoon rather than a permanent change to a character.
        if (in_array($item['item_type'] ?? '', ['potion', 'scroll'], true)) {
            return true;
        }

        // And enchantment of at most +1, which has to actually BE a plus: an
        // uncommon cloak that grants advantage on something, or a wand with
        // charges, is not "+2" but it is not general-store stock either. It is
        // the kind of thing the world is supposed to hand you.
        $bonuses = [];
        foreach (self::BONUS_KEYS as $key) {
            if (isset($props[$key]) && is_numeric($props[$key])) {
                $bonuses[] = (int) $props[$key];
            }
        }
        if (!$bonuses) {
            return false;
        }
        foreach ($bonuses as $bonus) {
            if ($bonus > self::MAX_BONUS) {
                return false;
            }
        }
        return true;
    }

    /**
     * Everything the rule admits, from the items table as it stands today.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM items WHERE value_gp > 0 ORDER BY item_type, value_gp, name'
        )->fetchAll();
        return array_values(array_filter($rows, static fn ($row) => self::sells($row)));
    }

    /**
     * Make the shop table say what the rule says.
     *
     * Idempotent, and called every time the store is opened rather than on a
     * schedule: the catalogue is derived from `items`, `items` changes when
     * content is loaded, and a load is exactly the moment nobody thinks to
     * re-stock a shop. Rows that no longer qualify are removed, so an item
     * retuned to +2 in the content files stops being sold here on the next
     * visit rather than lingering as the one place it can still be bought.
     *
     * `stock` is left NULL, which the buy path reads as unlimited. A general
     * store running out of rope is a fiction that only ever annoys somebody.
     */
    public function stock(): void
    {
        $want = [];
        foreach ($this->catalogue() as $item) {
            $want[(int) $item['id']] = true;
        }

        $have = [];
        $rows = $this->db->prepare('SELECT id, item_id FROM shop_inventory WHERE npc_key = ?');
        $rows->execute([self::NPC_KEY]);
        foreach ($rows->fetchAll() as $row) {
            $have[(int) $row['item_id']] = (int) $row['id'];
        }

        $add = $this->db->prepare(
            'INSERT INTO shop_inventory (npc_key, item_id, stock, markup_pct) VALUES (?, ?, NULL, ?)'
        );
        foreach (array_keys($want) as $itemId) {
            if (!isset($have[$itemId])) {
                $add->execute([self::NPC_KEY, $itemId, self::MARKUP_PCT]);
            }
        }

        $drop = $this->db->prepare('DELETE FROM shop_inventory WHERE id = ?');
        foreach ($have as $itemId => $rowId) {
            if (!isset($want[$itemId])) {
                $drop->execute([$rowId]);
            }
        }
    }
}
