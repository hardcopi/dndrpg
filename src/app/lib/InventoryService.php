<?php
/**
 * Inventory equip/use/shop operations.
 */

declare(strict_types=1);

class InventoryService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function list(int $characterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ci.*, i.name, i.description, i.item_type, i.rarity, i.weight, i.value_gp,
                    i.damage_dice, i.damage_type, i.armor_bonus, i.armor_type, i.slot,
                    i.properties, i.image_url
             FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.character_id = ?
             ORDER BY i.item_type, i.name'
        );
        $stmt->execute([$characterId]);
        // Where each thing would go if it were put on, or null for something
        // that cannot be. The rule is slotOf()'s and stays there — a client
        // that worked it out from `item_type` and `slot` would be a second
        // copy of it, and would offer an Equip button the route then refuses.
        return array_map(
            static fn (array $row): array => $row + ['equip_slot' => self::slotOf($row)],
            $stmt->fetchAll()
        );
    }

    /** How many of one slot a body offers. Two hands' worth of fingers is 2. */
    private const SLOT_CAPACITY = ['ring' => 2];

    /**
     * The slot an item occupies when worn, or null for something that cannot
     * be equipped. Three slots are derived — a weapon is a weapon, a shield
     * is carried, body armour is worn — and everything else must say where it
     * sits in `items.slot` (ring, amulet, cloak, boots).
     */
    private static function slotOf(array $item): ?string
    {
        if ($item['item_type'] === 'weapon') {
            return 'weapon';
        }
        if ($item['item_type'] === 'armor') {
            return $item['armor_type'] === 'shield' ? 'shield' : 'body';
        }
        $slot = trim((string) ($item['slot'] ?? ''));
        return $slot !== '' ? $slot : null;
    }

    public function equip(int $characterId, int $inventoryId, bool $equip = true): array
    {
        $stmt = $this->db->prepare(
            'SELECT ci.*, i.item_type, i.armor_type, i.slot FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.id = ? AND ci.character_id = ?'
        );
        $stmt->execute([$inventoryId, $characterId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Item not in inventory.');
        }
        $slot = self::slotOf($row);
        if ($slot === null) {
            throw new InvalidArgumentException('That item cannot be equipped.');
        }

        if ($equip) {
            // One body, one of each slot — except fingers. A slot with room to
            // spare fills up rather than swapping, and a full one swaps the
            // oldest piece out, which is what a player reaching for the new
            // ring means by it.
            $capacity = self::SLOT_CAPACITY[$slot] ?? 1;
            $worn = $this->equippedInSlot($characterId, $slot);
            while (count($worn) >= $capacity) {
                $off = array_shift($worn);
                $this->db->prepare('UPDATE character_inventory SET is_equipped = 0 WHERE id = ?')
                    ->execute([(int) $off['id']]);
            }
            $this->db->prepare('UPDATE character_inventory SET is_equipped = 1 WHERE id = ?')
                ->execute([$inventoryId]);
        } else {
            $this->db->prepare('UPDATE character_inventory SET is_equipped = 0 WHERE id = ?')
                ->execute([$inventoryId]);
        }

        $gen = new CharacterGenerator($this->db);
        $gen->recalculateAC($characterId);

        return [
            'ok' => true,
            'inventory' => $this->list($characterId),
            'character' => $gen->getCharacter($characterId),
        ];
    }

    /**
     * What is currently worn in one slot, oldest first.
     *
     * @return array<int, array{id:int}>
     */
    private function equippedInSlot(int $characterId, string $slot): array
    {
        $stmt = $this->db->prepare(
            'SELECT ci.id, i.item_type, i.armor_type, i.slot
               FROM character_inventory ci
              INNER JOIN items i ON i.id = ci.item_id
              WHERE ci.character_id = ? AND ci.is_equipped = 1
              ORDER BY ci.id'
        );
        $stmt->execute([$characterId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            if (self::slotOf($row) === $slot) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * How much a potion restores.
     *
     * Authored potions carry it in `properties.heals`; the older seeded ones
     * kept it in `damage_dice`, a column named for the opposite thing. Both are
     * read, in that order, because the fallback used to be a bare '2d4+2' —
     * which meant a Greater Potion of Healing quietly restored exactly as much
     * as an ordinary one, and looked like it worked.
     */
    public static function healExpression(array $item): string
    {
        $props = json_decode($item['properties'] ?? '{}', true) ?: [];
        $expr = trim((string) ($props['heals'] ?? $item['damage_dice'] ?? ''));
        return $expr !== '' ? $expr : '2d4+2';
    }

    /**
     * The consumable vocabulary, shared with CombatEngine::doUseItem:
     * `properties.use_effect = {"boon": {...}, "rounds": N}` marks an item
     * whose worth is a combat boon — an antitoxin's save advantage, an oil's
     * extra die — and those are refused here because there is no boon store
     * outside a fight. A plain potion, or a use_effect with only `heals`
     * semantics, drinks the same as ever.
     */
    public function usePotion(int $characterId, int $inventoryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ci.*, i.name, i.item_type, i.damage_dice, i.properties
             FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.id = ? AND ci.character_id = ?'
        );
        $stmt->execute([$inventoryId, $characterId]);
        $item = $stmt->fetch();
        $props = $item ? (json_decode($item['properties'] ?? '{}', true) ?: []) : [];
        $use = is_array($props['use_effect'] ?? null) ? $props['use_effect'] : null;
        $usable = $item && ($item['item_type'] === 'potion'
            || (in_array($item['item_type'], ['misc', 'scroll'], true) && $use !== null));
        if (!$usable) {
            throw new InvalidArgumentException('Not a potion.');
        }
        if ($use !== null && isset($use['boon'])) {
            throw new InvalidArgumentException(
                "{$item['name']} is for a fight — its edge fades in moments."
            );
        }

        // A scroll is the spell without the slot. Heals only out here, the
        // same rule Spellbook::castOutOfCombat states, and the reader is the
        // target — a scroll has no aim beyond whoever reads it out.
        if ($use !== null && isset($use['cast'])) {
            $spellStmt = $this->db->prepare('SELECT * FROM spells WHERE spell_key = ?');
            $spellStmt->execute([(string) $use['cast']]);
            $spell = $spellStmt->fetch();
            if (!$spell || ($spell['resolution'] ?? '') !== 'heal') {
                throw new InvalidArgumentException(
                    "{$item['name']} is for a fight — read it there."
                );
            }
            $char = $this->db->prepare('SELECT current_hp, max_hp FROM characters WHERE id = ?');
            $char->execute([$characterId]);
            $c = $char->fetch();
            $expr = (string) ($spell['damage_dice'] ?? '') ?: '1d8';
            $roll = Dice::roll($expr);
            $newHp = min((int) $c['max_hp'], (int) $c['current_hp'] + $roll['total']);
            $this->db->prepare('UPDATE characters SET current_hp = ? WHERE id = ?')
                ->execute([$newHp, $characterId]);
            $this->consumeOne($inventoryId, (int) $item['quantity']);
            return [
                'ok' => true,
                'message' => "The scroll crumbles as {$spell['name']} takes — {$roll['total']} HP.",
                'healed' => $roll['total'],
                'inventory' => $this->list($characterId),
                'character' => (new CharacterGenerator($this->db))->getCharacter($characterId),
            ];
        }

        $char = $this->db->prepare('SELECT current_hp, max_hp FROM characters WHERE id = ?');
        $char->execute([$characterId]);
        $c = $char->fetch();
        $roll = Dice::roll(self::healExpression($item));
        $newHp = min((int) $c['max_hp'], (int) $c['current_hp'] + $roll['total']);
        $this->db->prepare('UPDATE characters SET current_hp = ? WHERE id = ?')
            ->execute([$newHp, $characterId]);

        $this->consumeOne($inventoryId, (int) $item['quantity']);

        return [
            'ok' => true,
            'message' => "You drink {$item['name']} and recover {$roll['total']} HP.",
            'healed' => $roll['total'],
            'inventory' => $this->list($characterId),
            'character' => (new CharacterGenerator($this->db))->getCharacter($characterId),
        ];
    }

    /** One swallowed, one fewer — or the row gone with the last of them. */
    private function consumeOne(int $inventoryId, int $quantity): void
    {
        if ($quantity <= 1) {
            $this->db->prepare('DELETE FROM character_inventory WHERE id = ?')->execute([$inventoryId]);
        } else {
            $this->db->prepare('UPDATE character_inventory SET quantity = quantity - 1 WHERE id = ?')
                ->execute([$inventoryId]);
        }
    }

    /**
     * Hand something to somebody else in the party.
     *
     * WITHIN THE PARTY AND NOWHERE ELSE. The ownership rule in `Ownership`
     * already says the two characters belong to whoever is signed in, and that
     * is not enough on its own: an account with two parties in two modules
     * could otherwise walk a Warded Shield out of the Old City and into
     * Rivermark by way of the sheet, which is the same "moved without walking"
     * fault the job board and the defeat haven both had. Party membership is
     * the rule the world is actually kept apart by, so it is the rule here.
     *
     * A stack splits. Handing over three of five potions leaves two, and the
     * receiving end MERGES into a stack they already have rather than growing a
     * second row of the same thing — otherwise a party that passes potions
     * around ends up with four rows of Potion of Healing on one sheet and a bag
     * that reads as a bug.
     *
     * Equipped gear comes off first. The alternative is refusing, which reads
     * as the game being fussy about a thing the player can obviously do by
     * pressing Unequip and then Give; and leaving it equipped would put armour
     * on one sheet and its AC on another.
     *
     * @param int|null $qty how many to hand over; null or more than the stack
     *                      holds means all of it.
     */
    public function give(int $fromId, int $inventoryId, int $toId, ?int $qty = null): array
    {
        if ($fromId === $toId) {
            throw new InvalidArgumentException('They are already carrying it.');
        }
        $stmt = $this->db->prepare(
            'SELECT ci.*, i.name FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.id = ? AND ci.character_id = ?'
        );
        $stmt->execute([$inventoryId, $fromId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Item not in inventory.');
        }
        if (!$this->sameParty($fromId, $toId)) {
            throw new InvalidArgumentException('They are not travelling with you.');
        }

        $held = max(1, (int) $row['quantity']);
        $move = $qty === null ? $held : max(1, min($qty, $held));
        $itemId = (int) $row['item_id'];

        if ((int) $row['is_equipped'] === 1) {
            $this->db->prepare('UPDATE character_inventory SET is_equipped = 0 WHERE id = ?')
                ->execute([$inventoryId]);
        }

        // Somewhere for it to land: an unequipped stack of the same item that
        // the receiver already has.
        $find = $this->db->prepare(
            'SELECT id, quantity FROM character_inventory
              WHERE character_id = ? AND item_id = ? AND is_equipped = 0
              ORDER BY id LIMIT 1'
        );
        $find->execute([$toId, $itemId]);
        $into = $find->fetch();

        if ($into) {
            $this->db->prepare('UPDATE character_inventory SET quantity = quantity + ? WHERE id = ?')
                ->execute([$move, (int) $into['id']]);
            $this->takeFrom($inventoryId, $held, $move);
        } elseif ($move >= $held) {
            // The whole stack, and nothing to merge with: the row itself walks
            // across, which keeps the id stable for anything holding one.
            $this->db->prepare(
                'UPDATE character_inventory SET character_id = ?, is_equipped = 0 WHERE id = ?'
            )->execute([$toId, $inventoryId]);
        } else {
            $this->db->prepare(
                'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped)
                 VALUES (?, ?, ?, 0)'
            )->execute([$toId, $itemId, $move]);
            $this->takeFrom($inventoryId, $held, $move);
        }

        // Both sheets can have changed: the giver may have taken armour off,
        // and neither AC is recalculated by anything else here.
        $gen = new CharacterGenerator($this->db);
        $gen->recalculateAC($fromId);
        $gen->recalculateAC($toId);

        return [
            'ok'        => true,
            'moved'     => $move,
            'item'      => $row['name'],
            'inventory' => $this->list($fromId),
            'character' => $gen->getCharacter($fromId),
            'recipient' => $gen->getCharacter($toId),
        ];
    }

    /** Take `$move` off a stack of `$held`, removing the row when it empties. */
    private function takeFrom(int $inventoryId, int $held, int $move): void
    {
        if ($move >= $held) {
            $this->db->prepare('DELETE FROM character_inventory WHERE id = ?')->execute([$inventoryId]);
            return;
        }
        $this->db->prepare('UPDATE character_inventory SET quantity = quantity - ? WHERE id = ?')
            ->execute([$move, $inventoryId]);
    }

    /**
     * Do these two march together?
     *
     * Asked of `character_party` rather than of the characters' modules,
     * because a party IS the unit of a playthrough — two parties in the same
     * module are still two separate games, and kit should no more cross between
     * them than a quest flag does.
     */
    private function sameParty(int $a, int $b): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM character_party x
               INNER JOIN character_party y ON y.party_id = x.party_id
              WHERE x.character_id = ? AND y.character_id = ? LIMIT 1'
        );
        $stmt->execute([$a, $b]);
        return (bool) $stmt->fetchColumn();
    }

    /*
     * shopCatalog() lived here — one global SELECT that offered every item to
     * every merchant, quest items included at 0 gp. Deleted rather than kept
     * around: an unused "sell everything to anyone" query is exactly the thing
     * a future route reaches for by accident.
     */

    /**
     * What one merchant sells, at their prices.
     *
     * This replaces a global catalog that offered every item to every merchant —
     * including the quest items, which cost 0 gp and could therefore be bought
     * off any counter before the quest that plants them had even started. A
     * shop is now an authored list in content/npcs/*.json, and an NPC with no
     * list has no shop, whatever `is_merchant` says.
     */
    public function shopFor(string $npcKey): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, s.stock, s.markup_pct,
                    CEIL(i.value_gp * s.markup_pct / 100) AS price
               FROM shop_inventory s
               INNER JOIN items i ON i.id = s.item_id
              WHERE s.npc_key = ?
              ORDER BY price, i.name'
        );
        $stmt->execute([$npcKey]);
        return $stmt->fetchAll();
    }

    /**
     * What a character could sell, priced.
     *
     * Half list price, floored — the classic spread that makes a shop a shop
     * and not a bank. Items worth nothing are excluded outright: a 0 gp row is
     * either a quest item or junk, and neither belongs in a sale list where
     * "sell all" would eat the plot. Equipped gear stays out too; sell it off
     * someone's back and the AC on their sheet becomes a lie.
     */
    public function sellablesFor(int $characterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ci.id AS inventory_id, ci.quantity, i.name, i.item_type,
                    i.value_gp, FLOOR(i.value_gp / 2) AS sale_price
               FROM character_inventory ci
               INNER JOIN items i ON i.id = ci.item_id
              WHERE ci.character_id = ? AND ci.is_equipped = 0 AND i.value_gp > 0
              ORDER BY sale_price DESC, i.name'
        );
        $stmt->execute([$characterId]);
        return $stmt->fetchAll();
    }

    public function sell(int $characterId, int $inventoryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ci.*, i.name, i.value_gp FROM character_inventory ci
              INNER JOIN items i ON i.id = ci.item_id
              WHERE ci.id = ? AND ci.character_id = ?'
        );
        $stmt->execute([$inventoryId, $characterId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('That is not in your pack.');
        }
        if ((int) $row['is_equipped'] === 1) {
            throw new InvalidArgumentException('Unequip it first.');
        }
        $price = (int) floor(((int) $row['value_gp']) / 2);
        if ($price <= 0) {
            throw new InvalidArgumentException('No merchant will pay for that.');
        }

        // Only open a transaction if nobody above us already has — PDO has no
        // nested transactions, and the test harness wraps everything in one so
        // it can roll a scratch character back. Same guard as
        // LevelUpService::rollHp, for the same reason.
        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }
        try {
            if ((int) $row['quantity'] > 1) {
                $this->db->prepare('UPDATE character_inventory SET quantity = quantity - 1 WHERE id = ?')
                    ->execute([$inventoryId]);
            } else {
                $this->db->prepare('DELETE FROM character_inventory WHERE id = ?')
                    ->execute([$inventoryId]);
            }
            $this->db->prepare('UPDATE characters SET gold = gold + ? WHERE id = ?')
                ->execute([$price, $characterId]);
            if ($owned) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($owned) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'ok' => true,
            'message' => "Sold {$row['name']} for {$price} gp.",
            'inventory' => $this->list($characterId),
            'character' => (new CharacterGenerator($this->db))->getCharacter($characterId),
        ];
    }

    public function buy(int $characterId, int $itemId, string $npcKey): array
    {
        // The merchant's own row, not the global items table: which shop you
        // are standing in decides both whether the item is for sale at all and
        // what it costs here.
        $item = $this->db->prepare(
            'SELECT i.*, s.id AS shop_row, s.stock,
                    CEIL(i.value_gp * s.markup_pct / 100) AS price
               FROM shop_inventory s
               INNER JOIN items i ON i.id = s.item_id
              WHERE s.npc_key = ? AND s.item_id = ?'
        );
        $item->execute([$npcKey, $itemId]);
        $row = $item->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Item not sold here.');
        }
        if ($row['stock'] !== null && (int) $row['stock'] <= 0) {
            throw new InvalidArgumentException('Sold out.');
        }
        $price = (int) $row['price'];
        $char = $this->db->prepare('SELECT gold FROM characters WHERE id = ?');
        $char->execute([$characterId]);
        $gold = (int) $char->fetchColumn();
        if ($gold < $price) {
            throw new InvalidArgumentException('Not enough gold.');
        }
        $this->db->prepare('UPDATE characters SET gold = gold - ? WHERE id = ?')
            ->execute([$price, $characterId]);
        if ($row['stock'] !== null) {
            $this->db->prepare('UPDATE shop_inventory SET stock = stock - 1 WHERE id = ?')
                ->execute([$row['shop_row']]);
        }
        $this->db->prepare(
            'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped) VALUES (?,?,1,0)'
        )->execute([$characterId, $itemId]);

        return [
            'ok' => true,
            'message' => "Purchased {$row['name']} for {$price} gp.",
            'inventory' => $this->list($characterId),
            'character' => (new CharacterGenerator($this->db))->getCharacter($characterId),
        ];
    }
}
