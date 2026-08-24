<?php
require_once __DIR__ . '/app/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>About / Legal — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
  <?php require APP_PATH . '/inc/site_bar.php'; ?>
  <header class="hero" style="padding:1.5rem 1rem">
    <h1>About &amp; Legal</h1>
    <p>Open game content, attribution, and license text.</p>
  </header>

  <main class="container">
    <section class="panel">
      <h2>About This Game</h2>
      <p class="legal-text" style="white-space:normal">
        <strong>Rivermark Chronicles</strong> is a browser-based, single-player role-playing game that uses
        mechanics and content from the <strong>5e System Reference Document (SRD)</strong> only.
        It is inspired by classic computer RPGs with grid exploration and tactical turn-based combat.
      </p>
      <p class="legal-text" style="white-space:normal">
        This application is <strong>not affiliated with, endorsed by, or sponsored by</strong> any company
        that holds trademarks related to popular fantasy tabletop brands. The names
        “Dungeons &amp; Dragons”, “D&amp;D”, “Forgotten Realms”, and “Wizards of the Coast” are trademarks
        of their respective owners and are <strong>not used as product branding in this game</strong>.
        In-game text refers to the rules as “the open 5e system” or “5e SRD rules”.
      </p>
    </section>

    <section class="panel">
      <h2>Content Sources</h2>
      <ul style="color:var(--text-dim);line-height:1.6">
        <li>System Reference Document 5.1 (SRD 5.1) — Open Game Content under the Open Game License 1.0a.</li>
        <li>SRD content is also available under Creative Commons Attribution 4.0 International (CC-BY 4.0).</li>
        <li>System Reference Document 5.2 (SRD 5.2) — released under CC-BY 4.0. The feats a character
            may take at an ability score increase are drawn from it, adapted to this game's progression.</li>
        <li>Races, classes, monsters, spells, and items used here are limited to SRD-listed material.</li>
        <li>Original setting names (Rivermark, Golden Flagon, Goblin Warren) are original content for this game.</li>
      </ul>
    </section>

    <section class="panel">
      <h2>CC-BY 4.0 Attribution</h2>
      <p class="legal-text" style="white-space:normal">
        This work includes material from the System Reference Document 5.1 (“SRD 5.1”) by Wizards of the Coast LLC,
        available at <a href="https://dnd.wizards.com/resources/systems-reference-document" rel="noopener" target="_blank">dnd.wizards.com/resources/systems-reference-document</a>,
        used under the Creative Commons Attribution 4.0 International License
        (<a href="https://creativecommons.org/licenses/by/4.0/" rel="noopener" target="_blank">CC BY 4.0</a>).
      </p>
      <p class="legal-text" style="white-space:normal">
        This work includes material from the System Reference Document 5.2 (“SRD 5.2”) by Wizards of the Coast LLC,
        available at <a href="https://www.dndbeyond.com/srd" rel="noopener" target="_blank">dndbeyond.com/srd</a>,
        used under the Creative Commons Attribution 4.0 International License
        (<a href="https://creativecommons.org/licenses/by/4.0/" rel="noopener" target="_blank">CC BY 4.0</a>).
        The feat list is taken from SRD 5.2 and adapted: this game keeps the 2014 progression, so feats are
        offered at the ability score increase levels rather than granted by a background, and Epic Boons —
        which need level 19 — are not carried at all.
      </p>
    </section>

    <section class="panel">
      <h2>OPEN GAME LICENSE Version 1.0a</h2>
      <div class="legal-text">The following text is the property of Wizards of the Coast, Inc. and is Copyright 2000 Wizards of the Coast, Inc ("Wizards"). All Rights Reserved.

1. Definitions: (a)"Contributors" means the copyright and/or trademark owners who have contributed Open Game Content; (b)"Derivative Material" means copyrighted material including derivative works and translations (including into other computer languages), potation, modification, correction, addition, extension, upgrade, improvement, compilation, abridgment or other form in which an existing work may be recast, transformed or adapted; (c) "Distribute" means to reproduce, license, rent, lease, sell, broadcast, publicly display, transmit or otherwise distribute; (d)"Open Game Content" means the game mechanic and includes the methods, procedures, processes and routines to the extent such content does not embody the Product Identity and is an enhancement over the prior art and any additional content clearly identified as Open Game Content by the Contributor, and means any work covered by this License, including translations and derivative works under copyright law, but specifically excludes Product Identity. (e) "Product Identity" means product and product line names, logos and identifying marks including trade dress; artifacts; creatures characters; stories, storylines, plots, thematic elements, dialogue, incidents, language, artwork, symbols, designs, depictions, likenesses, formats, poses, concepts, themes and graphic, photographic and other visual or audio representations; names and descriptions of characters, spells, enchantments, personalities, teams, personas, likenesses and special abilities; places, locations, environments, creatures, equipment, magical or supernatural abilities or effects, logos, symbols, or graphic designs; and any other trademark or registered trademark clearly identified as Product identity by the owner of the Product Identity, and which specifically excludes the Open Game Content; (f) "Trademark" means the logos, names, mark, sign, motto, designs that are used by a Contributor to identify itself or its products or the associated products contributed to the Open Game License by the Contributor (g) "Use", "Used" or "Using" means to use, Distribute, copy, edit, format, modify, translate and otherwise create Derivative Material of Open Game Content. (h) "You" or "Your" means the licensee in terms of this agreement.

2. The License: This License applies to any Open Game Content that contains a notice indicating that the Open Game Content may only be Used under and in terms of this License. You must affix such a notice to any Open Game Content that you Use. No terms may be added to or subtracted from this License except as described by the License itself. No other terms or conditions may be applied to any Open Game Content distributed using this License.

3. Offer and Acceptance: By Using the Open Game Content You indicate Your acceptance of the terms of this License.

4. Grant and Consideration: In consideration for agreeing to this License, the Contributors grant You a perpetual, worldwide, royalty-free, non-exclusive license with the exact terms of this License to Use, the Open Game Content.

5. Representation of Authority to Contribute: If You are contributing original material as Open Game Content, You represent that Your Contributions are Your original creation and/or You have sufficient rights to grant the rights conveyed by this License.

6. Notice of License Copyright: You must update the COPYRIGHT NOTICE portion of this License to include the exact text of the COPYRIGHT NOTICE of any Open Game Content You are copying, modifying or distributing, and You must add the title, the copyright date, and the copyright holder's name to the COPYRIGHT NOTICE of any original Open Game Content you Distribute.

7. Use of Product Identity: You agree not to Use any Product Identity, including as an indication as to compatibility, except as expressly licensed in another, independent Agreement with the owner of each element of that Product Identity. You agree not to indicate compatibility or co-adaptability with any Trademark or Registered Trademark in conjunction with a work containing Open Game Content except as expressly licensed in another, independent Agreement with the owner of such Trademark or Registered Trademark. The use of any Product Identity in Open Game Content does not constitute a challenge to the ownership of that Product Identity. The owner of any Product Identity used in Open Game Content shall retain all rights, title and interest in and to that Product Identity.

8. Identification: If you distribute Open Game Content You must clearly indicate which portions of the work that you are distributing are Open Game Content.

9. Updating the License: Wizards or its designated Agents may publish updated versions of this License. You may use any authorized version of this License to copy, modify and distribute any Open Game Content originally distributed under any version of this License.

10. Copy of this License: You MUST include a copy of this License with every copy of the Open Game Content You Distribute.

11. Use of Contributor Credits: You may not market or advertise the Open Game Content using the name of any Contributor unless You have written permission from the Contributor to do so.

12. Inability to Comply: If it is impossible for You to comply with any of the terms of this License with respect to some or all of the Open Game Content due to statute, judicial order, or governmental regulation then You may not Use any Open Game Material so affected.

13. Termination: This License will terminate automatically if You fail to comply with all terms herein and fail to cure such breach within 30 days of becoming aware of the breach. All sublicenses shall survive the termination of this License.

14. Reformation: If any provision of this License is held to be unenforceable, such provision shall be reformed only to the extent necessary to make it enforceable.

15. COPYRIGHT NOTICE
Open Game License v 1.0a Copyright 2000, Wizards of the Coast, Inc.

System Reference Document 5.1 Copyright 2016, Wizards of the Coast, Inc.; Authors Mike Mearls, Jeremy Crawford, Chris Perkins, Rodney Thompson, Peter Lee, James Wyatt, Robert J. Schwalb, Bruce R. Cordell, Chris Sims, and Steve Townshend, based on original material by E. Gary Gygax and Dave Arneson.

Rivermark Chronicles Copyright 2026, Independent Project. Original software, setting names, and non-SRD text.

END OF LICENSE</div>
    </section>

    <section class="panel">
      <h2>Architecture Notes</h2>
      <p class="legal-text" style="white-space:normal">
        All gameplay content (maps, tiles, NPCs, monsters, items, quests, dialogue trees) is stored in MySQL.
        PHP provides a JSON API; the browser never accesses the database directly.
        This design supports future multiplayer sessions and user-generated maps without rewriting the core engine.
      </p>
    </section>
  </main>

  <footer class="footer-legal">Rivermark Chronicles · Open 5e SRD content</footer>
</body>
</html>
