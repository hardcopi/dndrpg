/**
 * Character creation UI — a five-step wizard.
 *
 * The steps are data (see STEPS) rather than five hand-wired screens: each one
 * names the section it shows, what to call it, and a `ready()` that returns null
 * when you may proceed or the reason you may not. Next is disabled and the reason
 * is printed beside it, so the button never simply refuses without saying why.
 *
 * Back is always allowed, and the rail lets you jump to any step you have already
 * completed — forward only through Next, because a later step's validity depends
 * on an earlier one's answers.
 */
(function () {
  /**
   * Cache-bust an art URL.
   *
   * This page builds its own portrait and paperdoll URLs and never loads
   * game.js, so `TILE_CACHE_VER` is not in scope here — which meant a re-slice
   * left the creator showing stale faces and busts indefinitely. `ART_VER` is
   * injected by create.php from the art manifests' mtimes, so the URL changes
   * exactly when the art does.
   *
   * Falls back to the bare URL if the global is missing, because a stale
   * portrait is a far better failure than a broken image.
   */
  const ART_VER = window.ART_VER || '';
  function artUrl(url) {
    if (!url || !ART_VER) return url;
    return url + (url.includes('?') ? '&' : '?') + 'v=' + ART_VER;
  }

  const state = {
    method: 'standard',
    /** Index into STEPS. */
    step: 0,
    /** How far Next has ever taken them, so the rail knows what is clickable. */
    reached: 0,
    /** null = unchecked, true/false = the answer from meta/name_free. */
    nameFree: null,
    nameChecked: '',
    /** Which peoples NameGen has names for, from meta/races. */
    nameTables: [],
    /** Class name => {primary: [short keys], secondary: short key}, from meta/classes. */
    keyAbilities: {},
    /**
     * The six 4d6 totals the server rolled, or null before any roll.
     *
     * Held apart from `abilities` because the player still chooses which ability
     * each total lands on, and because the step is only valid once these exist.
     */
    rolled: null,
    /**
     * Which ability each throw currently feeds, by throw index.
     *
     * The trays are the six *throws*, not six abilities — a tray showing dice that
     * add to 12 next to a score of 16 would be nonsense. So the ability is a
     * dropdown on the row, and choosing one swaps it with whichever throw was
     * holding it. One row per throw, and no second copy of the same six numbers
     * underneath.
     */
    rollAssign: null,
    races: [],
    classes: [],
    /** The origin shelf from meta/feats, and the chosen key ('' = none). */
    originFeats: [],
    feat: '',
    abilities: { strength: 15, dexterity: 14, constitution: 13, intelligence: 12, wisdom: 10, charisma: 8 },
    partyId: null,
    avatars: [],
    spriteKey: null,
    // Whether the player has picked a portrait themselves. Until they do, the
    // grid follows whatever the chosen class defaults to.
    avatarTouched: false,

    // Set when this page is re-dressing an existing character rather than
    // making a new one.
    redressId: null,

    // 'preset' picks a whole actor from the packs; 'built' stacks the modular
    // paperdoll layers. Only one is submitted.
    look: 'preset',
    paperdoll: null,        // the served catalogue
    pdSex: 'male',
    pdLayers: {},           // category -> variant key
    pdFacing: 0,            // row of the walk sheet the preview shows
  };

  /**
   * Which walk-sheet row each facing is, and what to call it.
   *
   * Row order is the one slice_paperdoll.py writes and the renderer animates:
   * S, W, E, N, then the diagonals. The preview only offers the four cardinals —
   * being able to see the back of a haircut matters, being able to see it from
   * the north-east does not.
   */
  const PD_FACINGS = [
    { row: 0, label: 'Front' },
    { row: 3, label: 'Back' },
    { row: 1, label: 'Left' },
    { row: 2, label: 'Right' },
  ];

  const $ = (s) => document.querySelector(s);
  const ABILS = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
  const ABIL_SHORT = { strength: 'STR', dexterity: 'DEX', constitution: 'CON', intelligence: 'INT', wisdom: 'WIS', charisma: 'CHA' };

  /**
   * The standard array, which is an assignment and not six free choices.
   *
   * Kept here as well as on the server because the picker has to know what it is
   * permuting. `meta/point_buy` also serves it; this is the fallback if that
   * response is missing it.
   */
  const STANDARD_ARRAY = [15, 14, 13, 12, 10, 8];

  /**
   * The wizard, in order.
   *
   * `ready()` returns null to allow Next, or the sentence to show instead. It is
   * called on every render, so it must be cheap and must not have side effects —
   * the name check is done separately and only its cached answer is read here.
   */
  const STEPS = [
    {
      id: 'identity',
      label: 'Identity',
      blurb: 'Who are they?',
      ready: () => {
        const name = $('#name').value.trim();
        if (!name) return 'A name is required.';
        if (name.length < 2) return 'That name is a little short.';
        if (state.nameChecked !== name) return 'Checking that name…';
        if (state.nameFree === false) return state.nameReason || 'That name is taken.';
        return null;
      },
    },
    {
      id: 'class',
      label: 'Calling',
      blurb: 'What do they do?',
      ready: () => ($('#class').value ? null : 'Choose a class.'),
    },
    {
      id: 'abilities',
      label: 'Abilities',
      blurb: 'What are they made of?',
      ready: () => {
        if (state.method === 'random') {
          // Nothing to assign until the dice have been thrown, and the server
          // will refuse a creation whose scores it never rolled.
          return state.rolled ? null : 'Roll for your scores.';
        }
        if (state.method !== 'point_buy') return null;
        const spent = pointBuyCost();
        const budget = state.pointBuy?.budget || 27;
        if (spent > budget) return `That is ${spent - budget} points over budget.`;
        return null;
      },
    },
    {
      id: 'look',
      label: 'Appearance',
      blurb: 'What do they look like?',
      ready: () => {
        if (state.look === 'preset') {
          return state.spriteKey ? null : 'Pick a portrait.';
        }
        if (!state.paperdoll) return 'The layer library is not available.';
        if (!state.pdLayers.body) return 'Choose a body.';
        return null;
      },
    },
    {
      id: 'gift',
      label: 'Gift',
      blurb: 'One knack from before?',
      // Taking nothing is a valid pick, so this step never blocks.
      ready: () => null,
    },
    {
      id: 'review',
      label: 'Review',
      blurb: 'Everything in order?',
      ready: () => null,
    },
  ];

  // =========================================================================
  // The wizard frame
  // =========================================================================

  /**
   * Draw the current step, the rail, and the state of the two nav buttons.
   *
   * Called after anything that could change validity, which is cheaper than
   * working out which changes could. `hidden` rather than a class, so a step
   * that is not showing is also out of the tab order.
   */
  function renderWizard() {
    // Re-dressing is one step with no wizard around it — see enterRedressMode.
    if (state.redressId) return;

    const step = STEPS[state.step];

    document.querySelectorAll('.wiz-step').forEach((sec) => {
      sec.hidden = sec.dataset.step !== step.id;
    });

    $('#wiz-title').textContent = step.label;
    $('#wiz-blurb').textContent = step.blurb;

    // The rail. A step you have reached is a button; one you have not is inert
    // text, because letting somebody jump to Review before choosing a class
    // would only show them an empty review.
    $('#wiz-rail').innerHTML = STEPS.map((s, i) => {
      const cls = ['wiz-pip'];
      if (i === state.step) cls.push('on');
      if (i < state.step) cls.push('done');
      const reachable = i <= state.reached && i !== state.step;
      return `<li>
        <button type="button" class="${cls.join(' ')}" data-goto="${i}"
          ${reachable ? '' : 'disabled'}
          ${i === state.step ? 'aria-current="step"' : ''}>
          <span class="wiz-pip-n">${i + 1}</span>
          <span class="wiz-pip-l">${s.label}</span>
        </button>
      </li>`;
    }).join('');

    const why = step.ready();
    const last = state.step === STEPS.length - 1;

    $('#wiz-back').disabled = state.step === 0;
    $('#wiz-next').classList.toggle('hidden', last);
    $('#submit-btn').classList.toggle('hidden', !last);
    $('#wiz-next').disabled = why !== null;
    // On the last step there is nothing left to block, so the reason line is
    // free for the summary's own hint.
    $('#wiz-reason').textContent = last ? '' : (why || '');
  }

  function goToStep(i) {
    if (i < 0 || i >= STEPS.length) return;
    state.step = i;
    state.reached = Math.max(state.reached, i);
    if (STEPS[i].id === 'review') renderReview();
    // Abilities is drawn on arrival for the same reason review is: it now says
    // something about the CLASS — which is primary, which is next — and the
    // class is chosen on the step before it. It used to be built once at load
    // and never again, which was true enough while the grid was six identical
    // boxes, and became a Wizard being told to raise Strength the moment they
    // carried any information.
    if (STEPS[i].id === 'abilities') renderAbilities();
    renderWizard();
    // Each step is its own screen, so start it at the top rather than wherever
    // the previous one happened to be scrolled to.
    $('.wiz-body').scrollTop = 0;
  }

  function wizardNext() {
    if (STEPS[state.step].ready() !== null) return;
    goToStep(state.step + 1);
  }

  function initWizard() {
    $('#wiz-next').addEventListener('click', wizardNext);
    $('#wiz-back').addEventListener('click', () => goToStep(state.step - 1));

    $('#wiz-rail').addEventListener('click', (e) => {
      const btn = e.target.closest('[data-goto]');
      if (btn && !btn.disabled) goToStep(Number(btn.dataset.goto));
    });

    // Enter advances rather than submitting. A form with a submit button treats
    // Enter in a text field as "create the character", which on step one would
    // post a character with no class chosen and no face.
    $('#create-form').addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      if (e.target.tagName === 'TEXTAREA') return;
      if (state.step === STEPS.length - 1) return; // let Review submit
      e.preventDefault();
      wizardNext();
    });

    // Anything at all might have changed validity; recheck rather than reason
    // about which controls matter to which step.
    $('#create-form').addEventListener('input', () => renderWizard());
    $('#create-form').addEventListener('change', () => renderWizard());
  }

  /**
   * Ask the server whether the name is going spare.
   *
   * Debounced, and the answer is cached against the exact string it was asked
   * about, so `ready()` can read it synchronously and can tell "not checked yet"
   * from "checked and taken". A network failure is treated as free: the create
   * call checks again and the UNIQUE index is the real guarantee, so the worst
   * case is the old behaviour of finding out on submit.
   */
  let nameTimer = null;

  function checkNameSoon() {
    const name = $('#name').value.trim();
    const note = $('#name-check');
    clearTimeout(nameTimer);

    if (!name || name.length < 2) {
      state.nameFree = null;
      state.nameChecked = '';
      note.textContent = '';
      note.className = 'field-note';
      return;
    }
    if (state.nameChecked === name) return;

    note.textContent = 'Checking…';
    note.className = 'field-note';
    nameTimer = setTimeout(async () => {
      let free = true;
      let reason = null;
      try {
        const r = await API.get('meta/name_free&name=' + encodeURIComponent(name));
        free = !!r.free;
        reason = r.reason || null;
      } catch (_) {
        free = true;
      }
      // Ignore a reply that arrived after they carried on typing.
      if ($('#name').value.trim() !== name) return;
      state.nameChecked = name;
      state.nameFree = free;
      state.nameReason = reason;
      note.textContent = free ? `${name} is available.` : reason;
      note.className = 'field-note ' + (free ? 'is-ok' : 'is-bad');
      renderWizard();
    }, 350);
  }

  function mod(score) {
    return Math.floor((score - 10) / 2);
  }
  function modStr(score) {
    const m = mod(score);
    return (m >= 0 ? '+' : '') + m;
  }

  async function init() {
    const params = new URLSearchParams(location.search);
    if (params.get('party_id')) state.partyId = parseInt(params.get('party_id'), 10);
    // Which game this new party is for, chosen in the picker on the landing
    // page. Only meaningful without a party_id: joining a party means taking
    // its module, and the API drops the field in that case rather than letting
    // the two disagree.
    state.module = params.get('module') || null;
    // Who is already in it, for the review line. `character/party` answers for
    // whoever the session is playing, which is the party the Recruit link came
    // from. Best effort and not awaited: not knowing the names costs nothing more
    // than a vaguer sentence.
    if (state.partyId) {
      API.get('character/party')
        .then((r) => {
          const names = (r.party || []).map((p) => p.name).filter(Boolean);
          if (names.length) state.partyNames = names.join(', ');
        })
        .catch(() => {});
    }
    // Re-dressing an existing character reuses this whole page rather than
    // growing a second copy of the picker inside the character sheet. Same
    // layers, same preview, same bake — only the submit differs.
    if (params.get('redress')) state.redressId = parseInt(params.get('redress'), 10);

    const [races, classes, pb, avatars, feats] = await Promise.all([
      API.get('meta/races'),
      API.get('meta/classes'),
      API.get('meta/point_buy'),
      API.get('meta/avatars'),
      API.get('meta/feats'),
    ]);
    state.races = races.races;
    state.nameTables = races.name_tables || [];
    state.keyAbilities = classes.key_abilities || {};
    state.classes = classes.classes;
    state.pointBuy = pb;
    state.avatars = avatars.avatars || [];
    state.originFeats = feats.feats || [];
    renderGiftGrid();

    // Unique race names
    const raceNames = [...new Set(state.races.map((r) => r.name))];
    const raceSel = $('#race');
    raceNames.forEach((n) => {
      const o = document.createElement('option');
      o.value = n;
      o.textContent = n;
      raceSel.appendChild(o);
    });
    raceSel.addEventListener('change', fillSubraces);
    fillSubraces();

    const classSel = $('#class');
    state.classes.forEach((c) => {
      const o = document.createElement('option');
      o.value = c.name;
      o.textContent = `${c.name} (d${c.hit_die})`;
      classSel.appendChild(o);
    });
    classSel.addEventListener('change', () => {
      followClassAvatar();
      updateClassPreview();
    });
    updateClassPreview();

    renderAvatars();
    followClassAvatar();
    await initPaperdoll();

    $('#name').addEventListener('input', checkNameSoon);

    document.querySelectorAll('.tab[data-method]').forEach((tab) => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('.tab[data-method]').forEach((t) => {
          const on = t === tab;
          t.classList.toggle('active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        state.method = tab.dataset.method;
        // Each method owns a different set of legal scores, so switching resets
        // to that method's starting point rather than carrying over numbers it
        // would reject — point buy cannot express a 16, standard cannot express
        // two 15s.
        if (state.method === 'standard') resetStandardArray();
        if (state.method === 'point_buy') ABILS.forEach((a) => { state.abilities[a] = 8; });
        if (state.method === 'random') {
          // Leaving a previous roll in place would show dice the session no longer
          // holds, and create would refuse the assignment.
          state.rolled = null;
          $('#roll-trays').innerHTML = '';
        }
        renderAbilities();
        renderWizard();
      });
    });

    $('#random-roll').addEventListener('click', rollAbilities);

    $('#create-form').addEventListener('submit', onSubmit);
    resetStandardArray();
    renderAbilities();

    if (!state.redressId) {
      initWizard();
      goToStep(0);
    }
  }

  /**
   * The class blurb on step two.
   *
   * The gear is here rather than only on the review because it is information you
   * want *while* choosing — whether a class starts in armour changes what it is
   * like to play at level one, and finding that out on the last step is finding
   * it out too late to act on.
   */
  function updateClassPreview() {
    const cls = state.classes.find((c) => c.name === $('#class').value);
    const note = $('#class-preview');
    if (!cls || !note) return;

    const rows = [
      ['Hit die', `d${cls.hit_die}`],
      ['Primary', cls.primary_ability],
      ['Saves', (cls.saving_throws || '').replace(/,/g, ', ')],
      ['Features', cls.features],
      ['Starts with', (cls.starting_gear || []).join(', ')],
      ['Subclass', cls.subclass_name ? `${cls.subclass_name} at level ${cls.subclass_level}` : null],
    ].filter(([, v]) => v);

    note.innerHTML = `<dl class="review-list">${
      rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')
    }</dl>`;
  }

  /**
   * The portrait grid.
   *
   * Faces rather than busts: twelve busts at 320px is most of a screen, and
   * the face is what the party rail and the initiative ribbon actually show,
   * so picking on the face is picking on what you will mostly look at.
   */
  function renderAvatars() {
    const grid = $('#avatar-grid');
    if (!grid || !state.avatars.length) return;
    grid.innerHTML = '';
    state.avatars.forEach((a) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'avatar-option';
      btn.dataset.key = a.sprite_key;
      btn.setAttribute('role', 'radio');
      btn.setAttribute('aria-checked', 'false');
      btn.title = a.label;
      btn.innerHTML =
        `<img src="${artUrl(`assets/images/npcs/${a.sprite_key}_face.png`)}" alt="" loading="lazy">` +
        `<span>${a.label}</span>`;
      btn.addEventListener('click', () => {
        // A deliberate pick is sticky: from here the class selector stops
        // moving the portrait, because silently reverting somebody's choice
        // when they reconsider their class is worse than a default that
        // no longer matches.
        state.avatarTouched = true;
        selectAvatar(a.sprite_key);
      });
      grid.appendChild(btn);
    });
  }

  function selectAvatar(key) {
    state.spriteKey = key;
    document.querySelectorAll('#avatar-grid .avatar-option').forEach((b) => {
      const on = b.dataset.key === key;
      b.classList.toggle('selected', on);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
  }

  /** Track the class default until the player overrides it. */
  function followClassAvatar() {
    if (state.avatarTouched) return;
    const cls = state.classes.find((c) => c.name === $('#class').value);
    const key = (cls && cls.sprite_key) || 'fighter';
    // Only follow to a portrait that is actually offered — the avatars table
    // is the whitelist the server validates against, and a class default
    // missing from it would select nothing at all.
    if (state.avatars.some((a) => a.sprite_key === key)) {
      selectAvatar(key);
    }
  }

  // =========================================================================
  // Building a character out of paperdoll layers
  // =========================================================================

  async function initPaperdoll() {
    document.querySelectorAll('[data-look]').forEach((tab) => {
      tab.addEventListener('click', () => {
        state.look = tab.dataset.look;
        document.querySelectorAll('[data-look]').forEach((t) => {
          const on = t === tab;
          t.classList.toggle('active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        $('#look-preset').classList.toggle('hidden', state.look !== 'preset');
        $('#look-built').classList.toggle('hidden', state.look !== 'built');
        renderWizard();
      });
    });

    try {
      state.paperdoll = await API.get('meta/paperdoll');
    } catch (e) {
      // The library has to be sliced before it can be worn. Say so where the
      // player is looking rather than failing silently on submit.
      $('#look-built').innerHTML =
        '<p class="help-hint">No layer library on this install — '
        + 'run <code>tools/slice_paperdoll.py</code>.</p>';
      document.querySelector('[data-look="built"]').disabled = true;
      return;
    }

    $('#pd-facing').addEventListener('click', () => {
      state.pdFacing = (state.pdFacing + 1) % PD_FACINGS.length;
      drawPaperdoll();
    });
    $('#pd-random').addEventListener('click', () => {
      randomisePaperdoll();
      renderPaperdollPickers();
      drawPaperdoll();
    });

    randomisePaperdoll();

    // Re-dressing starts from what they are already wearing, so the page opens
    // on the character rather than on a stranger.
    if (state.redressId) {
      await enterRedressMode();
    }

    renderPaperdollPickers();
    drawPaperdoll();
  }

  /**
   * Strip the page down to the Appearance step.
   *
   * Everything else here — abilities, race, class — is decided once at creation
   * and is not a wardrobe, so re-dressing shows one step and no wizard chrome at
   * all: no rail, no Back, no Next, just the pickers and Save. Hiding rather than
   * building a second page keeps one implementation of the picker.
   */
  async function enterRedressMode() {
    let sheet;
    try {
      sheet = await API.get('character/sheet&character_id=' + state.redressId);
    } catch (e) {
      showFormError(e.message);
      return;
    }
    const c = sheet.character;

    document.body.classList.add('redress-mode');
    // The look step is the only one that exists now, so show it and drop the
    // rest out of the document rather than merely hiding them — a hidden step
    // still holds required-looking form controls.
    document.querySelectorAll('.wiz-step').forEach((sec) => {
      sec.hidden = sec.dataset.step !== 'look';
    });
    $('#wiz-rail').remove();
    $('#wiz-back').remove();
    $('#wiz-next').remove();

    document.querySelectorAll('[data-look]').forEach((t) => {
      if (t.dataset.look === 'built') t.click();
    });
    // Choosing a shared NPC portrait is a creation-time decision, so re-dress
    // offers only the layers it can actually re-bake.
    $('[data-step="look"] .tabs')?.classList.add('hidden');
    $('#submit-btn').classList.remove('hidden');
    $('#submit-btn').textContent = 'Save appearance';
    $('#wiz-title').textContent = c.name;
    $('#wiz-blurb').textContent =
      `Level ${c.level} ${c.race} ${c.class} — change how they look.`;

    state.look = 'built';

    // What they are wearing, if they were built rather than given a portrait.
    let worn = null;
    try {
      worn = c.appearance_json ? JSON.parse(c.appearance_json) : null;
    } catch (_) {
      worn = null;
    }
    if (worn && worn.sex && state.paperdoll.sexes[worn.sex]) {
      state.pdSex = worn.sex;
      state.pdLayers = {};
      for (const [cat, key] of Object.entries(worn)) {
        if (cat !== 'sex' && key) state.pdLayers[cat] = key;
      }
    }
  }

  function showFormError(message) {
    const err = $('#form-error');
    if (!err) return;
    err.textContent = message;
    err.classList.remove('hidden');
  }

  function pdCategories() {
    return (state.paperdoll?.sexes?.[state.pdSex]) || [];
  }

  /**
   * A plausible starting character, and the Randomise button.
   *
   * Optional layers are sometimes skipped rather than always filled, because a
   * character who is always wearing a hat and an accessory reads as a costume.
   * The body is never skipped — there is nothing underneath it.
   */
  function randomisePaperdoll() {
    const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];
    state.pdLayers = {};
    for (const cat of pdCategories()) {
      // Roughly a third of optional slots are left empty, except clothes, which
      // are worn far more often than not.
      if (cat.optional) {
        const skip = ['head', 'accessory', 'facialhair', 'gloves'].includes(cat.category)
          ? Math.random() < 0.55
          : Math.random() < 0.12;
        if (skip) continue;
      }
      const style = pick(cat.styles);
      state.pdLayers[cat.category] = pick(style.colours).key;
    }
    // A dress replaces trousers rather than joining them; the server refuses
    // both, so the UI must not offer the combination it would reject.
    if (state.pdLayers.dress) delete state.pdLayers.pants;
  }

  function renderPaperdollPickers() {
    const wrap = $('#pd-pickers');
    if (!wrap) return;

    const sexes = Object.keys(state.paperdoll?.sexes || {});
    let html = `<div class="pd-group">
      <h3>Body</h3>
      <div class="pd-swatches" role="radiogroup" aria-label="Body">
        ${sexes.map((s) => `<button type="button" class="pd-chip${s === state.pdSex ? ' on' : ''}"
          data-sex="${s}">${s === 'male' ? 'Male' : 'Female'}</button>`).join('')}
      </div>
    </div>`;

    for (const cat of pdCategories()) {
      const chosen = state.pdLayers[cat.category];
      html += `<div class="pd-group">
        <h3>${cat.label}${cat.optional ? ' <span class="pd-opt">optional</span>' : ''}</h3>
        <div class="pd-swatches" role="radiogroup" aria-label="${cat.label}">
          ${cat.optional ? `<button type="button" class="pd-chip${!chosen ? ' on' : ''}"
              data-cat="${cat.category}" data-key="">None</button>` : ''}
          ${cat.styles.map((st, i) => {
            // One chip per style, showing the currently chosen colour of it.
            const mine = st.colours.find((c) => c.key === chosen);
            const shown = mine || st.colours[0];
            return `<button type="button" class="pd-chip pd-style${mine ? ' on' : ''}"
              data-cat="${cat.category}" data-key="${shown.key}"
              title="${cat.label} ${i + 1}">${i + 1}</button>`;
          }).join('')}
        </div>
        ${colourRowHtml(cat, chosen)}
      </div>`;
    }
    wrap.innerHTML = html;
    tintSwatches(wrap);

    wrap.querySelectorAll('[data-sex]').forEach((b) => {
      b.addEventListener('click', () => {
        if (b.dataset.sex === state.pdSex) return;
        state.pdSex = b.dataset.sex;
        // The libraries do not share keys, so a female dress cannot survive a
        // switch to the male body. Re-roll rather than half-clear.
        randomisePaperdoll();
        renderPaperdollPickers();
        drawPaperdoll();
      });
    });

    wrap.querySelectorAll('[data-cat]').forEach((b) => {
      b.addEventListener('click', () => {
        const cat = b.dataset.cat;
        const key = b.dataset.key;
        if (!key) delete state.pdLayers[cat];
        else state.pdLayers[cat] = key;
        if (cat === 'dress' && key) delete state.pdLayers.pants;
        if (cat === 'pants' && key) delete state.pdLayers.dress;
        renderPaperdollPickers();
        drawPaperdoll();
      });
    });
  }

  /**
   * The colour row for whichever style is currently chosen.
   *
   * The `_Alt_N` variants share an identical silhouette with their base — the
   * alpha channels match exactly — so they are the same hairstyle in another
   * colour, and showing them as separate styles would pad six haircuts into
   * thirty and hide that there are only six.
   */
  function colourRowHtml(cat, chosen) {
    const style = cat.styles.find((st) => st.colours.some((c) => c.key === chosen));
    if (!style || style.colours.length < 2) return '';
    return `<div class="pd-swatches pd-colours" role="radiogroup" aria-label="${cat.label} colour">
      ${style.colours.map((c, i) => `<button type="button"
        class="pd-chip pd-colour${c.key === chosen ? ' on' : ''}"
        data-cat="${cat.category}" data-key="${c.key}"
        title="Colour ${i + 1}"
        data-tint="${c.preview}"></button>`).join('')}
    </div>`;
  }

  /**
   * Paint each colour swatch with the colour it actually represents.
   *
   * Showing the preview frame as a background image was the obvious thing and it
   * was useless: a beard covers maybe 300 of a 128x128 frame's pixels, so
   * scaled into a 22px chip it is a grey smudge, and five smudges look
   * identical. What the player is choosing is a colour, so show the colour —
   * the mean of the non-transparent pixels, which for a single garment or head
   * of hair is exactly the thing they are picking between.
   *
   * Averaging is done once per key and cached for the page's life; the rows
   * re-render on every click and re-reading pixels each time would be silly.
   */
  const tintCache = new Map();

  function tintSwatches(root) {
    root.querySelectorAll('[data-tint]').forEach((el) => {
      const src = el.dataset.tint;
      const hit = tintCache.get(src);
      if (hit) { el.style.backgroundColor = hit; return; }
      // `src` stays the bare URL because it is also this chip's identity -- the
      // cache key and the querySelectorAll below both match on it. Only the two
      // actual fetches get versioned.
      const fetchUrl = artUrl(src);
      // Until it resolves, the frame is better than a blank chip.
      el.style.backgroundImage = `url('${fetchUrl}')`;
      const img = new Image();
      img.onload = () => {
        let colour;
        try {
          colour = meanColour(img);
        } catch (e) {
          colour = null; // tainted canvas; keep the image fallback
        }
        if (!colour) return;
        tintCache.set(src, colour);
        // The row may have re-rendered while we waited, so paint every chip
        // currently showing this source rather than the element we captured.
        document.querySelectorAll(`[data-tint="${CSS.escape(src)}"]`).forEach((c) => {
          c.style.backgroundImage = 'none';
          c.style.backgroundColor = colour;
        });
      };
      img.src = fetchUrl;
    });
  }

  function meanColour(img) {
    const cv = document.createElement('canvas');
    cv.width = img.naturalWidth;
    cv.height = img.naturalHeight;
    const cx = cv.getContext('2d', { willReadFrequently: true });
    cx.drawImage(img, 0, 0);
    const px = cx.getImageData(0, 0, cv.width, cv.height).data;
    let r = 0, g = 0, b = 0, n = 0;
    for (let i = 0; i < px.length; i += 4) {
      // Half-transparent pixels are the sprite's antialiased edge, which is a
      // blend with whatever was behind it. Including them drags every colour
      // toward the same muddy middle.
      if (px[i + 3] < 200) continue;
      r += px[i]; g += px[i + 1]; b += px[i + 2]; n++;
    }
    if (!n) return null;
    return `rgb(${Math.round(r / n)},${Math.round(g / n)},${Math.round(b / n)})`;
  }

  /**
   * Stack the chosen layers, in the order the server will bake them.
   *
   * Absolutely-positioned 128px frames on top of each other, each showing the
   * same cell of its own walk sheet. They line up because nothing in the library
   * is trimmed — every layer shares the base body's coordinate space, which is
   * the one rule the whole approach rests on.
   */
  function drawPaperdoll() {
    const stage = $('#pd-preview');
    if (!stage || !state.paperdoll) return;

    const order = state.paperdoll.layer_order || [];
    const facing = PD_FACINGS[state.pdFacing];
    const byKey = {};
    for (const cat of pdCategories()) {
      for (const st of cat.styles) {
        for (const c of st.colours) byKey[c.key] = c;
      }
    }

    // The chosen layers, in bake order, resolved to their library entry once so
    // both views draw from the same list rather than each rebuilding it.
    const worn = order
      .filter((cat) => state.pdLayers[cat])
      .map((cat) => byKey[state.pdLayers[cat]])
      .filter(Boolean);

    stage.innerHTML = worn
      .map((v) => {
        // The preview file is one 128px cell — the south frame — so the other
        // facings come from the full sheet, offset by row.
        const sheet = artUrl(v.preview.replace('_prev.png', '_sheet.png'));
        return `<img class="pd-layer" src="${sheet}" alt=""
          style="left:0;top:${-facing.row * 128}px">`;
      })
      .join('');

    drawPaperdollBust(worn);

    const n = Object.keys(state.pdLayers).length;
    $('#pd-note').textContent =
      `${facing.label} · ${n} layer${n === 1 ? '' : 's'}`;
  }

  /**
   * The same stack again as a portrait.
   *
   * Bust frames are 320px squares that share their own coordinate space exactly
   * as the walk cells share theirs, so this is the same absolute-positioning
   * trick at a different size — no facing offset, because a bust only faces you.
   *
   * Boots are the only thing in the library with no bust frame, which is correct
   * rather than missing: a portrait stops above the ankle. A layer whose bust
   * fails to load removes itself, mirroring the server's `is_file` skip, so one
   * absent file cannot leave a broken-image icon sitting on the character's
   * chest.
   */
  function drawPaperdollBust(worn) {
    const bust = $('#pd-bust');
    if (!bust) return;

    bust.innerHTML = worn
      .map((v) => `<img class="pd-bust-layer"
        src="${artUrl(v.preview.replace('_prev.png', '_bust.png'))}" alt="">`)
      .join('');

    bust.querySelectorAll('img').forEach((img) => {
      img.addEventListener('error', () => img.remove());
    });
  }

  function fillSubraces() {
    const race = $('#race').value;
    const sub = $('#subrace');
    sub.innerHTML = '';
    const rows = state.races.filter((r) => r.name === race);
    rows.forEach((r) => {
      const o = document.createElement('option');
      o.value = r.subrace || '';
      o.textContent = r.subrace || '(none)';
      sub.appendChild(o);
    });
    updateRacialPreview();
  }

  function currentRace() {
    const name = $('#race').value;
    const sub = $('#subrace').value || null;
    return state.races.find((r) => r.name === name && (r.subrace || '') === (sub || '')) || state.races.find((r) => r.name === name);
  }

  function updateRacialPreview() {
    const r = currentRace();
    if (!r) return;
    const bonuses = [];
    const map = { str_bonus: 'STR', dex_bonus: 'DEX', con_bonus: 'CON', int_bonus: 'INT', wis_bonus: 'WIS', cha_bonus: 'CHA' };
    Object.entries(map).forEach(([k, label]) => {
      if (parseInt(r[k], 10)) bonuses.push(`${label} +${r[k]}`);
    });
    $('#racial-preview').textContent = `Speed ${r.speed} ft. Bonuses: ${bonuses.join(', ') || 'none'}. ${r.traits || ''}`;
    showNameRoll(r);
    // textContent, not innerHTML: this is prose out of a database column that
    // an admin can edit, and the creator is the one page it is read on.
    const lore = $('#racial-lore');
    if (lore) lore.textContent = r.description || '';
    showRaceArt(r.name);
  }

  /**
   * The race plate, if one has been painted.
   *
   * Keyed off the race NAME, not the subrace: a Wood Elf and a High Elf are
   * both elves and there is one painting of elves. The filename rule is the
   * same one WorldState::tag() uses server-side — lowercased, and anything
   * that is not a letter or a digit becomes an underscore — so "Half-Orc"
   * looks for `half_orc.png` and nothing has to carry a second key column.
   *
   * `hidden` goes back on before the load rather than after the failure: the
   * element is reused as the player scrolls through the list, and a race with
   * no plate would otherwise keep showing the previous race's people. The
   * vhost answers a missing file with the homepage HTML and a 200, so `error`
   * is the only signal that there is no painting — a status check would pass.
   */
  function showRaceArt(name) {
    const img = $('#racial-art');
    if (!img) return;
    const key = String(name || '').toLowerCase().replace(/[^a-z0-9]+/g, '_');
    img.hidden = true;
    if (!key) return;
    img.onload = () => { img.hidden = false; };
    img.onerror = () => { img.hidden = true; };
    img.src = `assets/images/races/${encodeURIComponent(key)}.png`;
  }

  /**
   * The Roll button, offered only where there are names to roll.
   *
   * `meta/races` ships the list of peoples NameGen has tables for, beside the
   * catalogue itself. Asking the server rather than keeping a copy of the list
   * here is the same rule the rest of the creator follows — the race catalogue
   * is a database table and the client is driven off it — and it means adding
   * names for a people is one PHP file and no JavaScript.
   *
   * Keyed on the subrace first and the race second, because that is the order
   * NameGen resolves them in: a Dark Elf has their own list, every other elf
   * shares the one.
   */
  function showNameRoll(race) {
    const btn = $('#name-roll');
    const gender = $('#name-gender');
    if (!btn) return;
    const tables = state.nameTables || [];
    const has = !!race && (tables.includes(race.subrace || '') || tables.includes(race.name));
    btn.hidden = !has;
    if (gender) gender.hidden = !has;
  }

  /**
   * Put a suggestion in the box, and check it like anything typed.
   *
   * It overwrites: the button is only ever pressed by somebody who wants a
   * different name from the one in front of them. The field stays a text
   * field, so the suggestion is a starting point rather than a choice — edit
   * it, keep half of it, or press again.
   */
  async function rollName() {
    const race = currentRace();
    const btn = $('#name-roll');
    if (!race || !btn) return;

    btn.disabled = true;
    try {
      const gender = $('#name-gender')?.value || '';
      let q = 'meta/roll_name&race=' + encodeURIComponent(race.name);
      if (race.subrace) q += '&subrace=' + encodeURIComponent(race.subrace);
      if (gender) q += '&gender=' + encodeURIComponent(gender);
      if (state.redressId) q += '&except=' + state.redressId;
      const r = await API.get(q);
      if (!r.name) return;
      $('#name').value = r.name;
      // Down the same path as typing: the note, the debounce and the UNIQUE
      // index are all still what decide whether this name is allowed.
      checkNameSoon();
      renderWizard();
    } catch (_) {
      // A suggestion that could not be fetched is not worth a banner; the
      // field is untouched and the player can type.
    } finally {
      btn.disabled = false;
    }
  }

  $('#name-roll')?.addEventListener?.('click', rollName);
  $('#race')?.addEventListener?.('change', updateRacialPreview);
  document.addEventListener('change', (e) => {
    if (e.target && e.target.id === 'subrace') updateRacialPreview();
  });

  function pointBuyCost() {
    const costs = state.pointBuy?.costs || {};
    return ABILS.reduce((sum, a) => sum + (costs[state.abilities[a]] || 0), 0);
  }

  // =========================================================================
  // 4d6 drop lowest, thrown by the server and replayed here
  // =========================================================================

  /**
   * Ask the server for six 4d6 throws, then show them landing.
   *
   * The server is authoritative and this only replays: `meta/roll_abilities`
   * rolls, remembers the six totals in the session, and `character/create` will
   * accept only an arrangement of exactly those. So the dice a player watches are
   * the dice they get — which was not true before, because the roll used to
   * happen in this file and be silently re-rolled on submit.
   *
   * The six totals are held aside in `state.rolled` rather than written straight
   * into `state.abilities`, because the player still has to choose which ability
   * each one lands on.
   */
  async function rollAbilities() {
    const btn = $('#random-roll');
    btn.disabled = true;
    try {
      const res = await API.post('meta/roll_abilities', {});
      state.rolled = res.sets.map((s) => s.total);
      // Throw i feeds ability i to begin with, so the step is valid the moment the
      // dice stop; the player rearranges from there.
      state.rollAssign = [...ABILS];
      applyRollAssignment();
      renderAbilities();
      renderWizard();
      await playRolls(res.sets);
    } catch (e) {
      showFormError(e.message);
    } finally {
      btn.disabled = false;
    }
  }

  /**
   * Build a tray of four dice per ability and let them land.
   *
   * All 24 are thrown as one gesture with a small stagger, so it reads as one
   * handful rather than six separate animations — six sequential throws would be
   * about eight seconds before the player could act, every time they rerolled.
   *
   * The dropped die is dimmed and shrunk aside once they settle rather than
   * hidden, because seeing which three of the four counted is the entire reason
   * for showing four.
   */
  /** Push the throw-to-ability mapping into the scores that get submitted. */
  function applyRollAssignment() {
    if (!state.rolled || !state.rollAssign) return;
    state.rollAssign.forEach((ability, i) => {
      state.abilities[ability] = state.rolled[i];
    });
  }

  /**
   * Give this throw an ability, taking it off whichever throw had it.
   *
   * Same swap discipline as the standard array: the six throws always map onto the
   * six abilities exactly once, so the assignment can never be invalid.
   */
  function assignThrow(throwIndex, ability) {
    const other = state.rollAssign.indexOf(ability);
    if (other === throwIndex) return;
    const previous = state.rollAssign[throwIndex];
    state.rollAssign[throwIndex] = ability;
    if (other >= 0) state.rollAssign[other] = previous;
    applyRollAssignment();
  }

  async function playRolls(sets) {
    const wrap = $('#roll-trays');
    if (!wrap || !window.Dice3D || !window.Dice3D.available()) return;

    wrap.innerHTML = '';
    const throws = [];
    const dropped = [];

    sets.forEach((set, i) => {
      const tray = document.createElement('div');
      tray.className = 'roll-tray';
      tray.dataset.throw = String(i);
      tray.innerHTML =
        '<select class="roll-tray-pick" aria-label="Which ability this throw feeds"></select>'
        + '<span class="roll-tray-dice"></span>'
        + '<span class="roll-tray-total is-pending">—</span>'
        + '<span class="roll-tray-mod"></span>';
      const rail = tray.querySelector('.roll-tray-dice');

      // `dice` is kept-then-dropped, so shuffle the display order — otherwise the
      // die that is about to grey out is always the last one, and the outcome is
      // legible before the dice have landed.
      const order = set.dice.map((v, j) => ({ v, isDropped: j === set.dice.length - 1 }));
      for (let j = order.length - 1; j > 0; j--) {
        const k = Math.floor(Math.random() * (j + 1));
        [order[j], order[k]] = [order[k], order[j]];
      }

      order.forEach((d) => {
        const holder = document.createElement('span');
        holder.className = 'die-holder';
        const stage = window.Dice3D.create(6, 1);
        holder.appendChild(stage);
        rail.appendChild(holder);
        throws.push([stage, d.v]);
        if (d.isDropped) dropped.push(holder);
      });

      wrap.appendChild(tray);
    });

    wrap.classList.remove('hidden');

    await window.Dice3D.landAll(throws, { duration: 850, stagger: 45 });

    // Now say which ones counted, and what they came to.
    dropped.forEach((h) => h.classList.add('is-dropped'));
    [...wrap.querySelectorAll('.roll-tray-total')].forEach((el, i) => {
      el.textContent = String(sets[i].total);
      el.classList.remove('is-pending');
    });
    renderTrayPickers();
  }

  /**
   * The ability dropdown and modifier on each tray row.
   *
   * Re-rendered in place rather than rebuilt, because rebuilding the row would
   * throw away the dice that just landed on it.
   */
  function renderTrayPickers() {
    const wrap = $('#roll-trays');
    if (!wrap || !state.rollAssign) return;

    wrap.querySelectorAll('.roll-tray').forEach((tray) => {
      const i = Number(tray.dataset.throw);
      const sel = tray.querySelector('.roll-tray-pick');
      const mine = state.rollAssign[i];

      // The random method has no ability grid to mark, so the ranking has to
      // ride on the option text — an <option> holds no markup, and a select is
      // narrow, so it is two characters rather than the word.
      const key = classAbilities();
      sel.innerHTML = ABILS.map((a) => {
        const rank = key.primary.includes(a) ? ' · 1st' : (key.secondary === a ? ' · 2nd' : '');
        return `<option value="${a}"${a === mine ? ' selected' : ''}>${ABIL_SHORT[a]}${rank}</option>`;
      }).join('');

      if (!sel.dataset.wired) {
        sel.dataset.wired = '1';
        sel.addEventListener('change', (e) => {
          assignThrow(i, e.target.value);
          renderTrayPickers();
          renderWizard();
        });
      }

      tray.querySelector('.roll-tray-mod').textContent =
        state.rolled ? modStr(state.rolled[i]) : '';
    });
  }

  /** The standard array in its default order, ready to be rearranged. */
  function resetStandardArray() {
    const arr = state.pointBuy?.standard_array || STANDARD_ARRAY;
    ABILS.forEach((a, i) => { state.abilities[a] = arr[i]; });
  }

  /**
   * Assign a score by swapping, never by duplicating.
   *
   * Used by both methods that deal in a fixed set of six numbers: the standard
   * array, and the six totals from 4d6. Giving STR a number that DEX is holding
   * hands DEX the number STR had, which is the only move that keeps the multiset
   * intact — so the step can never become invalid, rather than being validated and
   * refused.
   *
   * This is what the standard array needed and did not have: it was six
   * independent dropdowns each offering all six numbers, so 15 across the board
   * was two clicks away and the server accepted it.
   */
  function assignScore(ability, score) {
    const holder = ABILS.find((a) => a !== ability && state.abilities[a] === score);
    const previous = state.abilities[ability];
    state.abilities[ability] = score;
    if (holder) state.abilities[holder] = previous;
  }

  /** The fixed set of six the current method is arranging, or null if it has none. */
  function assignableScores() {
    if (state.method === 'standard') {
      return state.pointBuy?.standard_array || STANDARD_ARRAY;
    }
    if (state.method === 'random') return state.rolled;
    return null;
  }

  /** Rules::ABILITIES is short keys; this page is long ones. */
  const ABIL_LONG = {
    str: 'strength', dex: 'dexterity', con: 'constitution',
    int: 'intelligence', wis: 'wisdom', cha: 'charisma',
  };

  /**
   * What the chosen class wants, as long ability names.
   *
   * The two halves are different KINDS of claim and are kept apart all the way
   * to the screen: `primary` is the SRD's own field off the class row, and
   * `secondary` is this project's advice about what to raise next. A class the
   * table has nothing for marks nothing — the grid is the same grid it was.
   */
  function classAbilities() {
    const chosen = $('#class')?.value;
    const row = chosen ? (state.keyAbilities || {})[chosen] : null;
    if (!row) return { primary: [], secondary: null };
    return {
      primary: (row.primary || []).map((k) => ABIL_LONG[k]).filter(Boolean),
      secondary: ABIL_LONG[row.secondary] || null,
    };
  }

  /**
   * The sentence over the grid: what to put where, in words.
   *
   * Said as well as marked, because a badge on a box tells somebody WHICH box
   * and not WHY, and this step is the one place in creation where a player who
   * does not already know the system has to make six decisions at once. "or"
   * for a Fighter is the SRD's own word: it is a choice between two builds
   * rather than a tie.
   */
  function abilityAdvice() {
    const { primary, secondary } = classAbilities();
    const cls = $('#class')?.value;
    if (!cls || !primary.length) return '';
    const name = (a) => ABIL_SHORT[a];
    const first = primary.map(name).join(' or ');
    return secondary
      ? `A ${cls} leans on ${first}, then ${name(secondary)}.`
      : `A ${cls} leans on ${first}.`;
  }

  function renderAbilities() {
    const box = $('#abilities');
    box.innerHTML = '';
    const key = classAbilities();
    const advice = $('#ability-advice');
    if (advice) advice.textContent = abilityAdvice();
    const isPointBuy = state.method === 'point_buy';
    const isRandom = state.method === 'random';
    const assignable = assignableScores();

    $('#random-roll').classList.toggle('hidden', !isRandom);
    $('#random-roll').textContent = state.rolled ? 'Roll again' : 'Roll the dice';
    // The trays belong to the random method and to nothing else.
    $('#roll-trays').classList.toggle('hidden', !isRandom || !state.rolled);

    const note = $('#method-note');
    note.className = 'help-hint';
    if (isPointBuy) {
      const budget = state.pointBuy?.budget || 27;
      const spent = pointBuyCost();
      const left = budget - spent;
      note.textContent = left === 0
        ? `All ${budget} points spent.`
        : (left > 0
          ? `${spent} of ${budget} points spent — ${left} still to spend.`
          : `${spent} of ${budget} points — ${-left} over.`);
      if (left < 0) note.className = 'help-hint is-bad';
    } else if (isRandom) {
      note.textContent = state.rolled
        ? 'Each row is one throw. Change the ability beside it to move that score.'
        : '4d6, drop the lowest, six times. Rolled on the server, so these are the scores you keep.';
    } else {
      note.textContent =
        `Assign ${assignable.join(', ')} — one each. Picking a number swaps it with whoever has it.`;
    }

    // The random method has no ability grid at all: each tray row already shows a
    // throw, its total and the ability it feeds, and a second grid underneath
    // repeating the same six numbers was what pushed the step off one screen.
    if (isRandom) {
      renderTrayPickers();
      return;
    }

    ABILS.forEach((a) => {
      const div = document.createElement('div');
      // The mark is on the BOX and not on the number, because what is being
      // pointed at is the ability — which score is sitting in it is the very
      // thing the player is still moving around.
      const rank = key.primary.includes(a) ? 'primary'
        : (key.secondary === a ? 'secondary' : null);
      div.className = 'ability-box' + (rank ? ` is-${rank}` : '');
      const tag = rank
        ? `<em class="ability-rank">${rank === 'primary' ? 'Primary' : 'Secondary'}</em>`
        : '';
      if (isPointBuy) {
        div.innerHTML = `<span>${ABIL_SHORT[a]}${tag}</span>
          <input type="number" min="8" max="15" value="${state.abilities[a]}" data-ab="${a}">
          <strong class="mod">${modStr(state.abilities[a])}</strong>`;
        div.querySelector('input').addEventListener('change', (e) => {
          let v = parseInt(e.target.value, 10);
          if (!Number.isFinite(v) || v < 8) v = 8;
          if (v > 15) v = 15;
          state.abilities[a] = v;
          renderAbilities();
          renderWizard();
        });
      } else {
        // Standard array and rolled dice are the same interaction: arrange a
        // fixed set of six.
        div.innerHTML = `<span>${ABIL_SHORT[a]}${tag}</span>
          <select data-ab="${a}"></select>
          <strong class="mod">${modStr(state.abilities[a])}</strong>`;
        const sel = div.querySelector('select');
        // Duplicates are possible with dice — two abilities can legitimately both
        // be holding a 13 — so the options are de-duplicated for display while the
        // swap keeps the underlying multiset intact.
        [...new Set(assignable)].sort((x, y) => y - x).forEach((n) => {
          const o = document.createElement('option');
          o.value = n;
          o.textContent = n;
          if (state.abilities[a] === n) o.selected = true;
          sel.appendChild(o);
        });
        sel.addEventListener('change', (e) => {
          assignScore(a, parseInt(e.target.value, 10));
          renderAbilities();
          renderWizard();
        });
      }
      box.appendChild(div);
    });
  }

  // =========================================================================
  // The review step
  // =========================================================================

  /**
   * The finished character, with racial bonuses applied.
   *
   * This is the only place before submitting where the numbers you will actually
   * play with appear: the abilities step shows base scores, the server adds the
   * racial bonuses, and until now the first sight of the total was the character
   * sheet after creation. HP and AC are computed the same way the server will, so
   * they are a claim this page has to keep — see Rules and
   * CharacterGenerator::unarmoredAc.
   */
  /**
   * The gift step: the origin feats as cards, "take nothing" first.
   *
   * Radio-group semantics by hand, the same shape as the portrait grid. The
   * descriptions come off the server catalogue, so a feat reworded in
   * Feats.php rewords here without anybody remembering to.
   */
  function renderGiftGrid() {
    const grid = $('#gift-grid');
    if (!grid) return;
    const card = (key, name, body, note) => `
      <button type="button" class="gift-card ${state.feat === key ? 'selected' : ''}"
              role="radio" aria-checked="${state.feat === key}" data-feat="${key}">
        <strong>${name}</strong>
        <span>${body}</span>
        ${note ? `<em class="help-hint">${note}</em>` : ''}
      </button>`;
    grid.innerHTML =
      card('', 'Nothing yet', 'Walk in plain. Every knack stays on offer at a level-up.', '')
      + state.originFeats.map((f) => card(f.key, f.name, f.description, f.partial || '')).join('');
    grid.querySelectorAll('[data-feat]').forEach((b) => {
      b.addEventListener('click', () => {
        state.feat = b.dataset.feat;
        renderGiftGrid();
      });
    });
  }

  function renderReview() {
    const race = currentRace();
    const cls = state.classes.find((c) => c.name === $('#class').value);
    const BONUS = { strength: 'str_bonus', dexterity: 'dex_bonus', constitution: 'con_bonus', intelligence: 'int_bonus', wisdom: 'wis_bonus', charisma: 'cha_bonus' };

    const final = {};
    ABILS.forEach((a) => {
      final[a] = state.abilities[a] + (race ? parseInt(race[BONUS[a]], 10) || 0 : 0);
    });

    $('#review-name').textContent = $('#name').value.trim() || 'Unnamed';
    const sub = [race?.subrace, race?.name, cls?.name].filter(Boolean).join(' ');
    $('#review-sub').textContent =
      `Level 1 ${sub} · ${$('#background').value} · ${$('#alignment').value}`;

    $('#review-abilities').innerHTML = ABILS.map((a) => {
      const bump = final[a] - state.abilities[a];
      return `<div class="ability-box">
        <span>${ABIL_SHORT[a]}</span>
        <strong>${final[a]}</strong>
        <strong class="mod">${modStr(final[a])}</strong>
        ${bump ? `<em class="ability-bump">+${bump} racial</em>` : ''}
      </div>`;
    }).join('');

    // Level 1 hit points are the full hit die plus the Constitution modifier,
    // and Hill Dwarves get one more per level.
    let hp = (cls ? cls.hit_die : 8) + mod(final.constitution);
    if (race?.subrace === 'Hill Dwarf') hp += 1;
    // Tough pays 2 per level the moment it is granted, and this is level 1.
    if (state.feat === 'tough') hp += 2;

    $('#review-stats').innerHTML = `
      <div class="review-stat"><span>Hit points</span><strong>${Math.max(1, hp)}</strong></div>
      <div class="review-stat"><span>Armour class</span><strong>${startingAc(cls, final)}</strong></div>
      <div class="review-stat"><span>Speed</span><strong>${race?.speed ?? 30} ft</strong></div>
      <div class="review-stat"><span>Proficiency</span><strong>+2</strong></div>`;

    $('#review-list').innerHTML = [
      ['Abilities from', {
        standard: 'the standard array',
        point_buy: 'point buy',
        random: '4d6 drop lowest, rolled on the server',
      }[state.method]],
      // Which game this character is joining. The two entry points into this page
      // now mean different things — the main menu starts a new party, the party
      // rail's Recruit adds to the one you are playing — so the review has to say
      // which, or the difference is invisible until afterwards.
      ['Party', state.partyId
        ? `joining your current party${state.partyNames ? ' — ' + state.partyNames : ''}`
        : 'starting a new party, and a new game'],
      ['Starting gear', (cls?.starting_gear || []).join(', ') || 'none'],
      ['Racial traits', race?.traits || 'none'],
      ['Origin feat', state.originFeats.find((f) => f.key === state.feat)?.name || 'none taken'],
      ['Look', state.look === 'built'
        ? `built from ${Object.keys(state.pdLayers).length} layers`
        : 'a portrait from the packs'],
    ].map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('');

    renderReviewPortrait();
  }

  /**
   * Whichever look they chose, shown the same size.
   *
   * A built look reuses the bust stack; a preset look has one baked bust already
   * on disk. Both land in the same box so the review does not change shape
   * depending on which tab they used.
   */
  function renderReviewPortrait() {
    const box = $('#review-bust');
    if (!box) return;

    if (state.look === 'built' && state.paperdoll) {
      const order = state.paperdoll.layer_order || [];
      const byKey = {};
      for (const cat of pdCategories()) {
        for (const st of cat.styles) for (const c of st.colours) byKey[c.key] = c;
      }
      box.innerHTML = order
        .filter((cat) => state.pdLayers[cat])
        .map((cat) => byKey[state.pdLayers[cat]])
        .filter(Boolean)
        .map((v) => `<img class="pd-bust-layer" src="${artUrl(v.preview.replace('_prev.png', '_bust.png'))}" alt="">`)
        .join('');
      box.querySelectorAll('img').forEach((im) => im.addEventListener('error', () => im.remove()));
      return;
    }

    const key = state.spriteKey || 'fighter';
    box.innerHTML =
      `<img class="pd-bust-layer" src="${artUrl(`assets/images/npcs/${key}_bust.png`)}" alt="">`;
    // Not every actor was cut with a bust; the face always exists.
    box.querySelector('img').addEventListener('error', (e) => {
      e.target.src = artUrl(`assets/images/npcs/${key}_face.png`);
    });
  }

  /**
   * The AC the character will have once created, armour and all.
   *
   * Creation equips the class kit and recalculates, so a review printing a bare
   * 10 + Dex would be contradicted by the character sheet one click later. The
   * armour's own numbers arrive on the class from `meta/classes` — served rather
   * than transcribed, because the spell slot table was copied into the client
   * once already and that is the bug this avoids repeating.
   */
  function startingAc(cls, abilities) {
    const dex = mod(abilities.dexterity);
    const armour = cls && cls.starting_armor;
    if (armour) {
      const cap = armour.dex_bonus_max == null ? 99 : Number(armour.dex_bonus_max);
      return Number(armour.armor_bonus) + (armour.dex_bonus ? Math.min(dex, cap) : 0);
    }
    // Unarmoured defence is what Barbarians and Monks have instead of armour,
    // and neither kit contains any.
    if (cls?.name === 'Barbarian') return 10 + dex + mod(abilities.constitution);
    if (cls?.name === 'Monk') return 10 + dex + mod(abilities.wisdom);
    // Draconic Resilience: the Sorcerer's subclass arrives at 1st, so the
    // scales are already on at the review. Read off the served class row,
    // same as the armour numbers above.
    if (Number(cls?.subclass_level) <= 1 && cls?.subclass_name === 'Draconic Bloodline') {
      return 13 + dex;
    }
    return 10 + dex;
  }

  async function onSubmit(e) {
    e.preventDefault();
    const err = $('#form-error');
    err.classList.add('hidden');

    // Re-dressing only ever posts the appearance; nothing else on this form is
    // theirs to change any more.
    if (state.redressId) {
      $('#submit-btn').disabled = true;
      try {
        await API.post('character/appearance', {
          character_id: state.redressId,
          sex: state.pdSex,
          layers: state.pdLayers,
        });
        window.location.href = 'game.php';
      } catch (ex) {
        showFormError(ex.message);
        $('#submit-btn').disabled = false;
      }
      return;
    }

    if (state.method === 'point_buy' && pointBuyCost() > 27) {
      err.textContent = 'Point buy exceeds 27 points.';
      err.classList.remove('hidden');
      return;
    }

    const payload = {
      name: $('#name').value.trim(),
      race: $('#race').value,
      subrace: $('#subrace').value || null,
      class: $('#class').value,
      method: state.method === 'point_buy' ? 'point_buy' : state.method,
      abilities: state.abilities,
      background: $('#background').value,
      alignment: $('#alignment').value,
      // A built look has no sprite until it is baked, and it cannot be baked
      // until the character has an id to name it after — so this is left empty
      // and the appearance is posted immediately afterwards.
      sprite_key: state.look === 'built' ? '' : (state.spriteKey || ''),
    };
    if (state.feat) payload.feat = state.feat;
    if (state.partyId) payload.party_id = state.partyId;
    if (state.module && !state.partyId) payload.module = state.module;

    try {
      $('#submit-btn').disabled = true;
      const data = await API.post('character/create', payload);

      if (state.look === 'built') {
        // Baked after creation because the sprite key is `pc_<id>`. If this
        // fails the character still exists and still has a class default to
        // wear, so the error is worth showing but not worth blocking on — they
        // can re-dress from the character sheet.
        try {
          await API.post('character/appearance', {
            character_id: data.character.id,
            sex: state.pdSex,
            layers: state.pdLayers,
          });
        } catch (ex) {
          err.textContent = 'Created, but the appearance could not be baked: ' + ex.message;
          err.classList.remove('hidden');
          await new Promise((r) => setTimeout(r, 2500));
        }
      }

      window.location.href = 'game.php';
    } catch (ex) {
      err.textContent = ex.message;
      err.classList.remove('hidden');
      $('#submit-btn').disabled = false;
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    init().catch((e) => {
      const err = $('#form-error');
      if (err) {
        err.textContent = e.message;
        err.classList.remove('hidden');
      }
    });
  });
})();
