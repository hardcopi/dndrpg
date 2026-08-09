# Commands to run on the host

## Reload content once, to get the safe version of content.sql

`sql/content.sql` has been regenerated so that it upserts instead of
delete-and-reinsert. Loading it once replaces the dangerous file's effects with
stable ids, and from then on reloading content is safe.

```bash
cd /home/richard/code/rpg/src
python3 tools/load_content.py     # regenerate (already done, but harmless)
mysql -u web -p rpg_5e < sql/content.sql
```

## Optional: put the party's gear back

Their inventories were emptied by the old content.sql — see **What went wrong**
below. Spells are already restored; gear is a judgement call, so it is opt-in:

    http://localhost:8081/tools/repair_save.php            # report only
    http://localhost:8081/tools/repair_save.php?gear=1      # re-grant kits

It only ever grants the class starting kit, and only to a character carrying
nothing at all, so it cannot duplicate anything you still have. Anything Wren
bought or looted is genuinely gone.

## What went wrong

Every content table — spells, items, quests, npcs — is the parent of a foreign key
from player data. The old `content.sql` deleted and re-inserted every row on every
load, and because it runs with `FOREIGN_KEY_CHECKS = 0` the deletes did not
cascade: the player's rows survived, pointing at ids that no longer existed. Every
`INNER JOIN` in the app then dropped them, so a caster had no spells and a
character carried nothing while the rows sat there looking intact.

That is why `SELECT COUNT(*)` says the save is fine and the game says it is not.
The importer now upserts, retirement deletes run with foreign keys enabled so they
cascade properly, and `tools/load_content.py` refuses to emit the destructive form
at all — there is a check that fails the build if a generated DELETE targets a key
the same file re-inserts.

## Nothing else, as far as I can tell

The migration has landed — Dontontion has both `appearance_json` and a baked
`pc_173` sprite, which could not exist without the column. So the blocker that was
at the top of this file is gone, and there is nothing here you need to run.

Reload with the cache cleared (Ctrl+Shift+R) after picking up new code, as always.

## Already done — no action needed

You ran `sql/content.sql` and the earlier migration, so these are in place:

| | |
|---|---|
| Gnoll / Wererat / Bugbear art keys | `gnoll`, `ratman`, `bugbear` |
| Brother Aldric | `aldric`, the T&C premade you picked |
| 12 quest markers that pointed inside buildings | snapped to reachable ground |
| `origin_tags` | filled from race/subrace/class/background |

## Cleanup I would like a nod on

`assets/images/monsters/{skeleton,zombie,orc}_*` and
`assets/images/battlers/{skeleton,zombie,orc}.png` are duplicates I restored
because the live database still pointed at them. Nothing references them now and
they should go — say the word and I will delete them.

They currently return **403** rather than serving, because I created them with
`cp` from the sandbox and the host gives files written that way a different mode
or SELinux context than the ones Python writes — every slicer-written file serves
fine, every `cp`-written one does not. Worth knowing if you ever copy art in by
hand:

```bash
chmod 644 assets/images/monsters/{skeleton,zombie,orc}_*.png
restorecon -v assets/images/monsters/*.png 2>/dev/null || true
```

## Dontontion

He is in your original party, because the bug you hit put him there — an absent
`party_id` used to mean "the party the session is playing" instead of "a new one".
That is fixed, but it does not move him. Two options, both from the character
list, and neither needs SQL:

- **Leave him.** Party 1 is now Wren, Kessa, Aldric and Dontontion, which is a
  legal four.
- **Retire him and make him again.** Retiring now works on characters from any
  game, so this is a couple of clicks, and **Start a New Game** genuinely starts
  one. His appearance is baked as `pc_173`; a remade character gets a new id and a
  new bake, so rebuild the look rather than expecting it to follow him.

Every test character I created has been retired. Ids in the 100s and 200s rather
than single digits because `tools/test_creation.php` creates and rolls back a
character per class per test — the rollbacks worked, but AUTO_INCREMENT does not
rewind.

## Nothing left to retire

Of the three party rows, only Wren Kingsley (id 1) is a player character; Kessa
and Aldric both carry a `companion_key`, so they are recruited companions and are
no longer offered as characters you can play as. If you want a clean start,
create a new character and retire Wren from her sheet rather than by hand.

All three are sitting on 1 HP, which is just where testing left them — a long
rest at camp puts that right.
