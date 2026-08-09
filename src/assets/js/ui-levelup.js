/**
 * The level-up ceremony.
 *
 * Levelling used to be a line in the log. XP arrived, hit points appeared at
 * the fixed average, and nothing was ever asked. The server now records a level
 * as owed (see LevelUpService) and this is where it is claimed: roll the hit
 * die, take an ability increase or a feat, learn a spell.
 *
 * Nothing here decides anything. The die is rolled on the server and this is
 * told what it showed; the lists of feats and spells are built on the server
 * and re-checked there when a choice comes back. That is the same arrangement
 * ui-check.js has with CheckService, and for a better reason — a hit point
 * total is worth more to a cheat than one skill check is.
 */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const G = () => window.Game || {};
  const UI = () => window.UI || {};

  /** Face-cycling delays for the flat die, slowing to a stop. */
  const FACE_STEPS = [60, 60, 70, 80, 95, 115, 140, 175, 215, 260];

  let open = false; // one ceremony at a time, whatever else is on screen

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  function reducedMotion() {
    return (G().reducedMotion && G().reducedMotion())
      || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /**
   * Can the 3D roller draw this die?
   *
   * `Dice3D.available()` only says the geometry file loaded at all. Hit dice are
   * d6, d8, d10 and d12, and only the d6 and the d20 have derived geometry — so
   * the shape has to be asked for by name. A d8 falls back to the flat die
   * rather than being drawn as some other solid wearing the wrong number of
   * faces, and the day tools/gen_dice_css.py grows an octahedron this starts
   * returning true on its own.
   */
  function canRoll3d(sides) {
    return !!(window.Dice3D
      && window.Dice3D.available()
      && window.DiceGeometry
      && window.DiceGeometry.REST
      && window.DiceGeometry.REST[sides]);
  }

  // =========================================================================
  // Entry
  // =========================================================================

  /**
   * Claim anything the party is owed, one ceremony at a time.
   *
   * Called after everything that can grant experience — a fight ending, a
   * conversation closing, a quest turned in. Cheap when there is nothing owed,
   * which is almost always.
   */
  async function checkPending() {
    if (open) return;
    let pending;
    try {
      pending = (await API.get('levelup/pending')).pending || [];
    } catch (e) {
      return; // A level owed is not worth interrupting play with an error.
    }
    if (!pending.length) return;
    await runCeremony(pending[0].id);
    // A big award can owe two levels. Come back for the next one.
    await checkPending();
  }

  async function runCeremony(pendingId) {
    if (open) return;
    open = true;
    try {
      let ceremony = (await API.get('levelup/ceremony', { pending_id: pendingId })).ceremony;
      const el = UI().showModal(shell(ceremony), {
        size: 'wide',
        label: 'Level up',
        slot: 'levelup',
      });
      await steps(el, ceremony);
    } catch (e) {
      console.error('level-up ceremony failed', e);
    } finally {
      open = false;
    }
  }

  /**
   * Walk the ceremony's outstanding steps in order.
   *
   * The server decides what is outstanding — every step re-reads the ceremony
   * it returns rather than tracking state here, so a half-finished ceremony
   * reopened after a reload resumes exactly where it stopped.
   */
  async function steps(el, ceremony) {
    let c = ceremony;
    if (!c.hp.done) {
      c = (await rollStep(el, c)).ceremony;
    }
    if (c.asi.offered && !c.asi.done) {
      c = (await asiStep(el, c)).ceremony;
    }
    if (c.spells.offered && !c.spells.done) {
      c = (await spellStep(el, c)).ceremony;
    }
    await finish(el, c);
  }

  // =========================================================================
  // Chrome
  // =========================================================================

  function shell(c) {
    return `
      <div class="levelup" data-pending="${c.pending_id}">
        <header class="levelup-head">
          <p class="levelup-eyebrow">Level up</p>
          <h2 class="levelup-title">${esc(c.character.name)}</h2>
          <p class="levelup-sub">${esc(c.character.class)} &mdash; level ${c.new_level}</p>
        </header>
        <div class="levelup-body"></div>
      </div>`;
  }

  function body(el) {
    return $('.levelup-body', el);
  }

  // =========================================================================
  // Step 1 — the hit die
  // =========================================================================

  async function rollStep(el, c) {
    const sides = c.hp.hit_die;
    const mod = c.hp.con_mod;
    const solid = canRoll3d(sides);

    body(el).innerHTML = `
      <section class="levelup-step">
        <h3>Roll for hit points</h3>
        <p class="levelup-note">
          A d${sides} plus your Constitution modifier of ${mod >= 0 ? '+' : ''}${mod}.
          Whatever it lands on is what you get.
        </p>
        <div class="levelup-dice">
          <div class="die-holder">${solid ? '' : `<div class="flat-die"><span class="flat-die-face">${sides}</span></div>`}</div>
        </div>
        <p class="levelup-result" aria-live="polite"></p>
        <div class="levelup-actions">
          <button type="button" class="btn btn-primary" data-act="roll">Roll the die</button>
        </div>
      </section>`;

    const btn = $('[data-act="roll"]', el);
    return new Promise((resolve) => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = 'Rolling…';
        let result;
        try {
          result = await API.post('levelup/roll_hp', { pending_id: c.pending_id });
        } catch (err) {
          $('.levelup-result', el).textContent = err.message;
          btn.disabled = false;
          btn.textContent = 'Roll the die';
          return;
        }

        await showRoll(el, sides, result.roll, solid);

        const total = result.gained;
        const sign = mod >= 0 ? `+ ${mod}` : `− ${Math.abs(mod)}`;
        $('.levelup-result', el).innerHTML =
          `<strong>${result.roll}</strong> ${sign} = <strong>${total}</strong> hit point${
            Math.abs(total) === 1 ? '' : 's'}`
          + (total <= 0 ? ' <span class="levelup-grim">— a hard level.</span>' : '');

        $('.levelup-actions', el).innerHTML =
          '<button type="button" class="btn btn-primary" data-act="next">Continue</button>';
        $('[data-act="next"]', el).addEventListener('click', () => resolve(result), { once: true });
      }, { once: true });
    });
  }

  /** Turn the die to the face the server already rolled. */
  async function showRoll(el, sides, value, solid) {
    const holder = $('.die-holder', el);
    if (solid) {
      const stage = window.Dice3D.create(sides, value);
      holder.insertBefore(stage, holder.firstChild);
      await window.Dice3D.landAll([[stage, value]], { duration: 1150 });
      return;
    }

    const face = $('.flat-die-face', holder);
    if (reducedMotion()) {
      face.textContent = String(value);
      return;
    }
    // Cycle and settle. The last value written is always the real one — the
    // cycling never chooses anything, it only fills the wait.
    for (const delay of FACE_STEPS) {
      face.textContent = String(1 + Math.floor(Math.random() * sides));
      await sleep(delay);
    }
    face.textContent = String(value);
    holder.querySelector('.flat-die').classList.add('is-landed');
  }

  // =========================================================================
  // Step 2 — ability score increase, or a feat
  // =========================================================================

  const ABILITIES = [
    ['strength', 'Strength', 'str'],
    ['dexterity', 'Dexterity', 'dex'],
    ['constitution', 'Constitution', 'con'],
    ['intelligence', 'Intelligence', 'int'],
    ['wisdom', 'Wisdom', 'wis'],
    ['charisma', 'Charisma', 'cha'],
  ];

  /**
   * One list, not a toggle.
   *
   * This used to be an ability-score panel with "or take a feat instead"
   * bolted underneath, which was the right shape when there was exactly one
   * feat and it was the alternative to the increase. SRD 5.2 makes the
   * increase a feat, and the server now offers it as one — so the screen is a
   * list of feats of which the Ability Score Improvement happens to be the
   * first, and picking it reveals the two points to spend beneath it.
   *
   * Everything the list shows comes from the server: which entries exist, what
   * they require, whether this character qualifies and why not. The only thing
   * decided here is which one is highlighted.
   */
  async function asiStep(el, c) {
    const scores = c.character.abilities;
    const asiKey = c.asi.asi_feat || 'ability_score_improvement';

    // Grouped in the order the server sent them, which is the catalogue's own
    // order: the increase, then the cheap Origin feats, then Fighting Styles,
    // then the General list. Within a group the ones this character cannot
    // take sink to the bottom — with thirty entries, a blocked row sitting
    // between two live ones is thirty chances to misread the list.
    const groups = [];
    c.asi.feats.forEach((f) => {
      let g = groups.find((x) => x.key === f.category);
      if (!g) groups.push((g = { key: f.category, label: f.category_label || '', items: [] }));
      g.items.push(f);
    });
    groups.forEach((g) => g.items.sort((a, b) => (b.eligible ? 1 : 0) - (a.eligible ? 1 : 0)));

    const abilityPicker = `
      <div class="levelup-asi-panel" hidden>
        <p class="levelup-note">
          Two points: both into one ability, or one each into two. Nothing goes above 20.
        </p>
        <div class="levelup-abilities">
          ${ABILITIES.map(([key, label]) => {
            const score = scores[key].score;
            const capped = score >= 20;
            return `
              <div class="levelup-ability${capped ? ' is-capped' : ''}" data-ability="${key}">
                <span class="levelup-ability-name">${label}</span>
                <span class="levelup-ability-score" data-base="${score}">${score}</span>
                <span class="levelup-ability-spent" data-spent="0"></span>
                <button type="button" class="btn btn-small" data-bump="${key}"
                        ${capped ? 'disabled' : ''}>+</button>
              </div>`;
          }).join('')}
        </div>
        <p class="levelup-remaining" aria-live="polite">2 points to spend</p>
      </div>`;

    const featRow = (f) => `
      <label class="levelup-feat${f.eligible ? '' : ' is-blocked'}"
             data-feat="${esc(f.key)}"
             data-search="${esc((f.name + ' ' + f.description).toLowerCase())}">
        <input type="radio" name="feat" value="${esc(f.key)}" ${f.eligible ? '' : 'disabled'}>
        <span class="levelup-feat-name">${esc(f.name)}</span>
        ${f.prerequisite_text
          ? `<span class="levelup-feat-req">Requires ${esc(f.prerequisite_text)}</span>` : ''}
        <span class="levelup-feat-text">${esc(f.description)}</span>
        ${f.reason ? `<span class="levelup-feat-warn">${esc(f.reason)}</span>` : ''}
        ${f.note
          ? `<span class="levelup-feat-note${f.inert ? ' is-inert' : ''}">${esc(f.note)}</span>` : ''}
        ${f.key === asiKey ? abilityPicker : ''}
      </label>`;

    body(el).innerHTML = `
      <section class="levelup-step">
        <h3>Choose a feat</h3>
        <p class="levelup-note">
          Reaching level ${c.new_level} is worth a feat. Raising two ability scores is one of them,
          and it is the first on the list.
        </p>
        <input type="search" class="levelup-filter" placeholder="Filter feats…"
               aria-label="Filter feats">
        <div class="levelup-feats">
          ${groups.map((g) => `
            <div class="levelup-feat-group" data-group="${esc(g.key)}">
              ${g.label ? `<h4>${esc(g.label)}</h4>` : ''}
              ${g.items.map(featRow).join('')}
            </div>`).join('')}
          <p class="levelup-empty" hidden>Nothing matches that.</p>
        </div>
        <p class="levelup-error" aria-live="polite"></p>
        <div class="levelup-actions">
          <button type="button" class="btn btn-primary" data-act="confirm" disabled>Confirm</button>
        </div>
      </section>`;

    const spent = {};
    const confirmBtn = $('[data-act="confirm"]', el);
    const panel = $('.levelup-asi-panel', el);
    const remaining = () => 2 - Object.values(spent).reduce((a, b) => a + b, 0);
    const chosen = () => $('input[name="feat"]:checked', el);

    function redraw() {
      const pick = chosen();
      const isAsi = !!pick && pick.value === asiKey;

      if (panel) {
        panel.hidden = !isAsi;
        if (!isAsi) Object.keys(spent).forEach((k) => delete spent[k]);
      }

      if (panel && isAsi) {
        ABILITIES.forEach(([key]) => {
          const row = $(`[data-ability="${key}"]`, el);
          const base = Number($('.levelup-ability-score', row).dataset.base);
          const add = spent[key] || 0;
          $('.levelup-ability-score', row).textContent = String(base + add);
          $('.levelup-ability-spent', row).textContent = add ? `+${add}` : '';
          row.classList.toggle('is-raised', add > 0);
          const bump = $(`[data-bump="${key}"]`, el);
          // Blocked when there is nothing left, when this one has had its two,
          // or when another ability already took a point and a third would
          // spread the increase too thin.
          const wouldBeThird = !spent[key] && Object.keys(spent).length >= 2;
          bump.disabled = base + add >= 20 || remaining() <= 0 || add >= 2 || wouldBeThird;
        });
        $('.levelup-remaining', el).textContent =
          remaining() === 0 ? 'Ready' : `${remaining()} point${remaining() === 1 ? '' : 's'} to spend`;
      }

      // A feat is enough on its own; the increase also needs its two points.
      confirmBtn.disabled = !pick || (isAsi && remaining() !== 0);
    }

    $$('[data-bump]', el).forEach((b) => b.addEventListener('click', (e) => {
      // The picker lives inside the increase's own <label>, so a click on it
      // would otherwise also toggle the radio it sits under.
      e.preventDefault();
      const key = b.dataset.bump;
      spent[key] = (spent[key] || 0) + 1;
      redraw();
    }));

    $$('input[name="feat"]', el).forEach((r) => r.addEventListener('change', redraw));

    // Undo: clicking a raised ability takes the points back off it.
    $$('.levelup-ability', el).forEach((row) => row.addEventListener('click', (e) => {
      if (e.target.matches('[data-bump]')) return;
      e.preventDefault();
      const key = row.dataset.ability;
      if (spent[key]) { delete spent[key]; redraw(); }
    }));

    // The filter hides rows rather than rebuilding the list, so a selection
    // survives typing and clearing the box brings it back into view.
    const filter = $('.levelup-filter', el);
    filter.addEventListener('input', () => {
      const needle = filter.value.trim().toLowerCase();
      let shown = 0;
      $$('.levelup-feat', el).forEach((row) => {
        const hit = !needle || row.dataset.search.includes(needle)
          || row.querySelector('input').checked;
        row.hidden = !hit;
        if (hit) shown++;
      });
      $$('.levelup-feat-group', el).forEach((g) => {
        g.hidden = !$$('.levelup-feat', g).some((r) => !r.hidden);
      });
      $('.levelup-empty', el).hidden = shown > 0;
    });

    redraw();

    return new Promise((resolve) => {
      confirmBtn.addEventListener('click', async () => {
        confirmBtn.disabled = true;
        const pick = chosen();
        const isAsi = pick.value === asiKey;
        try {
          resolve(await API.post('levelup/choose_asi', {
            pending_id: c.pending_id,
            // The points travel only with the increase. Every other feat that
            // raises an ability raises it by a fixed amount the server already
            // knows, and a client that could name one would be choosing it.
            asi: isAsi ? spent : null,
            feat: pick.value,
          }));
        } catch (err) {
          $('.levelup-error', el).textContent = err.message;
          confirmBtn.disabled = false;
        }
      });
    });
  }

  // =========================================================================
  // Step 3 — new spells
  // =========================================================================

  async function spellStep(el, c) {
    const due = c.spells.offered;
    const byLevel = {};
    c.spells.available.forEach((s) => {
      (byLevel[s.level] = byLevel[s.level] || []).push(s);
    });
    const levels = Object.keys(byLevel).map(Number).sort((a, b) => a - b);

    body(el).innerHTML = `
      <section class="levelup-step">
        <h3>${due === 1 ? 'Learn a spell' : `Learn ${due} spells`}</h3>
        ${c.spells.available.length ? `
          <p class="levelup-note">Only what ${esc(c.character.class)}s can cast at this level.</p>
          <div class="levelup-spells">
            ${levels.map((lv) => `
              <div class="levelup-spell-group">
                <h4>${lv === 0 ? 'Cantrips' : `Level ${lv}`}</h4>
                ${byLevel[lv].map((s) => `
                  <label class="levelup-spell">
                    <input type="checkbox" name="spell" value="${esc(s.spell_key)}">
                    <span class="levelup-spell-name">${esc(s.name)}</span>
                    <span class="levelup-spell-school">${esc(s.school || '')}${
                      s.damage_dice ? ` &middot; ${esc(s.damage_dice)} ${esc(s.damage_type || '')}` : ''}</span>
                    <span class="levelup-spell-text">${esc(s.description || '')}</span>
                  </label>`).join('')}
              </div>`).join('')}
          </div>
          <p class="levelup-remaining" aria-live="polite"></p>
        ` : `
          <p class="levelup-note">
            There is nothing new for a ${esc(c.character.class)} to learn at this level yet.
          </p>`}
        <p class="levelup-error" aria-live="polite"></p>
        <div class="levelup-actions">
          <button type="button" class="btn btn-primary" data-act="confirm" disabled>Confirm</button>
        </div>
      </section>`;

    const confirmBtn = $('[data-act="confirm"]', el);
    const boxes = $$('input[name="spell"]', el);

    function redraw() {
      const picked = boxes.filter((b) => b.checked);
      // Stop at the allowance rather than swapping the oldest out: silently
      // unpicking something the player chose is worse than refusing the click.
      boxes.forEach((b) => { b.disabled = !b.checked && picked.length >= due; });
      const left = due - picked.length;
      const note = $('.levelup-remaining', el);
      if (note) note.textContent = left === 0 ? 'Ready' : `Choose ${left} more`;
      confirmBtn.disabled = picked.length !== due;
    }

    boxes.forEach((b) => b.addEventListener('change', redraw));
    redraw();

    return new Promise((resolve) => {
      confirmBtn.addEventListener('click', async () => {
        confirmBtn.disabled = true;
        try {
          resolve(await API.post('levelup/choose_spells', {
            pending_id: c.pending_id,
            spells: boxes.filter((b) => b.checked).map((b) => b.value),
          }));
        } catch (err) {
          $('.levelup-error', el).textContent = err.message;
          confirmBtn.disabled = false;
        }
      });
    });
  }

  // =========================================================================
  // Close
  // =========================================================================

  async function finish(el, c) {
    const gained = c.hp.gained;
    body(el).innerHTML = `
      <section class="levelup-step levelup-done">
        <h3>${esc(c.character.name)} is level ${c.new_level}</h3>
        <ul class="levelup-summary">
          <li><strong>${gained > 0 ? '+' : ''}${gained}</strong> maximum hit points
              (rolled ${c.hp.rolled} on a d${c.hp.hit_die})</li>
          ${c.asi.feat ? `<li>Feat: <strong>${esc(c.asi.feat_name || c.asi.feat)}</strong></li>` : ''}
          ${c.asi.taken ? `<li>${Object.entries(c.asi.taken)
            .map(([k, v]) => `<strong>+${v}</strong> ${esc(k)}`).join(', ')}</li>` : ''}
          ${c.spells.chosen ? `<li>Learned <strong>${c.spells.chosen.map(esc).join(', ')}</strong></li>` : ''}
        </ul>
        <div class="levelup-actions">
          <button type="button" class="btn btn-primary" data-act="close">Onward</button>
        </div>
      </section>`;

    await new Promise((resolve) => {
      $('[data-act="close"]', el).addEventListener('click', resolve, { once: true });
    });
    UI().closeTopModal();

    // The sheet, the party rail and the hit point bars are all now stale.
    if (G().refreshAll) await G().refreshAll();
  }

  window.LevelUp = { checkPending, runCeremony };
})();
