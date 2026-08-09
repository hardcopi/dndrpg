<?php
/**
 * Bake a paperdoll avatar for an NPC.
 *
 * The premade roster is male-skewed and its female art is almost entirely
 * civilian — there is no female mercenary, watch captain or warlord anywhere in
 * the packs. So an NPC written as a woman who fights ends up either wearing a
 * man's face or a barmaid's apron. This bakes her one instead, out of the same
 * layer library the character creator uses.
 *
 * Deliberately NOT random. `randomisePaperdoll()` in create.js is right for a
 * player rolling a look they will then adjust; a named character wants picks
 * chosen for who she is, and wants them to stay the same on every rebuild.
 *
 *     docker compose exec -T php php /var/www/html/tools/bake_npc.php          # report
 *     docker compose exec -T php php /var/www/html/tools/bake_npc.php --apply
 *
 * The output key goes in `content/npcs/<key>.json` as `sprite_key`. Paperdoll's
 * `bake()` takes a free-form key — `keyFor()` is a separate convenience for
 * player characters — but the `npc_` prefix keeps generated art in its own
 * namespace, the same discipline `pc_` uses so a player cannot overwrite an
 * authored actor.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$argvFlags = [];
foreach (($_SERVER['argv'] ?? []) as $a) {
    $argvFlags[ltrim($a, '-')] = true;
}
$apply = !empty($_GET['apply']) || isset($argvFlags['apply']);

/*
 * The roster is empty, and that is the current answer rather than an oversight.
 *
 * Kessa Dunmar was baked here — `npc_kessa`, body_3, hair_2 — and it was always
 * the wrong tool for her. She is written as a HALF-ORC, and the layer library
 * has three human female bodies, no green skin, no tusks and no heavier build.
 * What came out was a blonde human woman standing in for a character the prose
 * describes as something else. Nobody misread the writing; the library simply
 * could not draw her.
 *
 * She now uses hand-made art under the plain key `kessa`, deliberately OUTSIDE
 * the `npc_` namespace this tool writes to — because a bake is a silent
 * overwrite, and the first person to run `--apply` after dropping a painted
 * bust into `npc_kessa_bust.png` would have destroyed it without a word.
 *
 * The tool stays. It is the right answer for a minor NPC the packs have no art
 * for, and the discipline it documents — generated art in its own namespace,
 * never mixed with authored art — is why swapping Kessa out was a two-line
 * content change rather than an archaeology exercise.
 *
 * THE UNDERVAULT SURFACE CAST IS NOT IN THE ROSTER, DELIBERATELY.
 *
 * `load_content.py` makes a `_sheet` and a `_face` mandatory for every
 * sprite_key, so all five needed a bake before they could load at all — but
 * only for the walk sheet. Their busts and faces are PAINTED, by
 * tools/gen_npc_art.py and tools/cut_face.py, and a bake would silently
 * destroy both. That is the Kessa hazard above, and a prefix does not prevent
 * it; what prevents it is that nothing re-bakes them. Putting them in $NPCS
 * would be exactly the loaded gun this comment was written about.
 *
 * So the picks live here as a record and not as a roster. To rebuild a walk
 * sheet, run these through Paperdoll::bake() by hand — and then repaint the
 * bust and re-cut the face, because the bake will have overwritten them.
 *
 *   wren_tallow  female  body_2 feet_1 pants_2 top_3 hair_4_alt2
 *   odger_vance  male    body_2 feet_2 pants_3 top_5 facialhair_1_alt3 hair_3_alt3
 *   marda_scole  female  body_3 feet_1 dress_2 hair_2_alt4
 *   orrin_bly    male    body_1 feet_1 pants_1 top_2 facialhair_2_alt4 hair_5_alt4
 *   dessa_kell   female  body_1 feet_2 dress_4 hair_6_alt1
 */
$NPCS = [];

foreach ($NPCS as $key => $spec) {
    printf("%-12s %s\n", $key, $spec['label']);
    foreach ($spec['picks'] as $cat => $v) {
        printf("   %-9s %s\n", $cat, $v);
    }
    if (!$apply) {
        echo "   (dry run — pass --apply to bake)\n\n";
        continue;
    }
    try {
        $out = Paperdoll::bake($spec['sex'], $spec['picks'], $key);
        printf("   baked %s\n\n", $out);
    } catch (Throwable $e) {
        printf("   FAILED %s\n\n", $e->getMessage());
    }
}

if (!$apply) {
    echo "Nothing was written.\n";
} else {
    echo "Done. Point the NPC's sprite_key at the key above, then re-run\n"
       . "tools/load_content.py so the art is validated and the DB updated.\n";
}
