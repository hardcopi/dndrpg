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
   * This page builds its own portrait URLs and never loads game.js, so
   * `TILE_CACHE_VER` is not in scope here — which meant a re-slice left the
   * review showing a stale face indefinitely. `ART_VER` is
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

    // Set when this page is re-dressing an existing character rather than
    // making a new one.
    redressId: null,
  };

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
   * Races that exist but are not on offer.
   *
   * Put away rather than deleted, and put away in the same spirit as the
   * `hidden` flag on the 3D creator's own race table — the rows stay in the
   * `races` table, every character who already is one keeps their sheet, their
   * traits and their look, and nothing about the API changes. The only thing
   * that goes is the option to become one.
   *
   * THE LIST IS NOT HERE ANY MORE. It is `RACES_WITHHELD` in bootstrap.php,
   * handed over by create.php, because the public races page has to answer the
   * same question and a page that offered a race this picker refuses would be
   * an advertisement for something nobody can have. The fallback is an empty
   * array — everything on offer — which is the safe way to be wrong: a missing
   * global shows a race that exists rather than hiding one that does not.
   */
  const RACES_WITHHELD = Array.isArray(window.RACES_WITHHELD) ? window.RACES_WITHHELD : [];

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
      // The look gates only when its picture is known to be missing: the
      // server refuses to create somebody wearing a look nobody painted, and
      // finding that out on Review is four steps too late. A look still
      // loading does not block — the answer is a moment away, and Next
      // flickering off and on with every swatch would be worse.
      ready: () => {
        const name = $('#name').value.trim();
        if (!name) return 'A name is required.';
        if (name.length < 2) return 'That name is a little short.';
        if (state.nameChecked !== name) return 'Checking that name…';
        if (state.nameFree === false) return state.nameReason || 'That name is taken.';
        if (lookStatus === 'missing') return 'That look has not been painted yet — pick another.';
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
    // Its only gate is the look's: Save on a picture nobody painted would
    // be refused by the server, so it is refused here first.
    if (state.redressId) {
      $('#submit-btn').disabled = lookStatus === 'missing' || lookStatus === 'nolib';
      return;
    }

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
    // picker, same five choices — only the wizard chrome and the route differ.
    if (params.get('redress')) state.redressId = parseInt(params.get('redress'), 10);

    const [races, classes, pb, feats] = await Promise.all([
      API.get('meta/races'),
      API.get('meta/classes'),
      API.get('meta/point_buy'),
      API.get('meta/feats'),
    ]);
    state.races = races.races;
    state.nameTables = races.name_tables || [];
    state.keyAbilities = classes.key_abilities || {};
    state.classes = classes.classes;
    state.pointBuy = pb;
    state.originFeats = feats.feats || [];
    renderGiftGrid();

    // Unique race names, minus the ones not currently on offer.
    //
    // Filtered out of `state.races` rather than only out of the select, so the
    // subrace list, the racial line, the review and the race the creator is
    // mounted for are all reading the same catalogue. Filtering the select
    // alone would leave `currentRace()` able to return a race no option can
    // reach — which is how a hidden race comes back as a default nobody chose.
    state.races = state.races.filter((r) => !RACES_WITHHELD.includes(r.name));

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
    buildLookControls();

    const classSel = $('#class');
    state.classes.forEach((c) => {
      const o = document.createElement('option');
      o.value = c.name;
      o.textContent = `${c.name} (d${c.hit_die})`;
      classSel.appendChild(o);
    });
    classSel.addEventListener('change', updateClassPreview);
    updateClassPreview();

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

    // The look is drawn last, once the race, the class and any link are all
    // known, so the first picture fetched is the one that is wanted rather
    // than the default on its way to being replaced.
    readRaceUrl(params);
    applyClassOutfit();
    readLookUrl(params);

    // Re-dressing an existing character reuses this page rather than growing a
    // second copy of the look picker inside the character sheet — same
    // picker, only the submit differs.
    if (state.redressId) {
      await enterRedressMode(params);
    } else {
      initWizard();
      goToStep(0);
      startLook();
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

  // =========================================================================
  // The look: five choices from the creator's library
  //
  // The Unity Sidekick lived in this box, then a carousel of painted tokens.
  // Both were somebody else's character you settled for. The library is every
  // combination of body, hair and outfit for every people, painted in advance
  // by tools/bake_looks.py and filed by a naming rule (looks_options.json), so
  // a pick here is a different static file — no render, no recipe, no upload.
  // Create and re-dress post the five choices; CharacterLook resolves the same
  // file by the same rule and bakes it into the sprite namespace.
  //
  // The client builds the path for one reason only: to SHOW it. Whether a
  // look is legal is the server's question, and the options file both read is
  // what keeps the two from offering different menus.
  // =========================================================================
  const LOOK = (window.LOOK_OPTIONS && typeof window.LOOK_OPTIONS === 'object') ? window.LOOK_OPTIONS : null;
  const LOOK_LIB = window.LOOK_LIB || 'assets/looks/v1/';

  /**
   * Swatch paint, by option name. Display only: the name is what is posted,
   * and an option this table forgets is drawn in a neutral grey rather than
   * not drawn at all.
   */
  const SWATCH = {
    black: '#1c1815', 'dark brown': '#3d2718', chestnut: '#6e3b1f', auburn: '#8e3a1c',
    red: '#b5441f', blond: '#d8b26a', grey: '#8f8c88', white: '#ebe6dc', purple: '#6b3c8e',
    steel: '#9ea7b1', bronze: '#a8743a', blackened: '#2d2b2e',
    'grey-brown': '#6f6354', brown: '#6b4426',
    blue: '#2f4f8f', crimson: '#8c1c24', green: '#2f6b3a',
  };

  /**
   * The Body choices. They come from the library when there is one, and
   * stand on their own when there is not, because Body also decides which
   * names the dice roll (NameGen::GENDERS) and a missing library must not
   * take the name dice's only steer with it.
   */
  const BODIES = (LOOK && LOOK.genders.length) ? LOOK.genders : ['feminine', 'masculine'];

  /** The five picks, in the names the server takes: style_key, colour names, outfit key. */
  const look = {
    gender: BODIES[0],
    hair_style: 'shoulder',
    hair_color: 'dark brown',
    outfit: 'tunic',
    outfit_color: null,
  };
  /**
   * Whether the player has chosen an outfit themselves. Until they do, the
   * outfit follows the class — a Wizard in plate is a picture arguing with
   * the sheet beside it. Once they have, a class change leaves it alone:
   * a choice made on purpose is not a default to be overwritten.
   */
  let outfitTouched = false;
  /** 'off' until the page has settled, then loading | ok | missing | nolib. */
  let lookStatus = 'off';
  /** Bumped on every paint, so a slow image cannot land on top of a newer pick. */
  let lookSeq = 0;
  let redressRace = '';
  let redressClass = '';

  const capital = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '');

  function lookRace() {
    return redressRace || $('#race')?.value || '';
  }
  function lookClass() {
    return redressClass || $('#class')?.value || '';
  }
  /** The library's spelling of a race, or null if it has no pictures of them. */
  function libraryRace(race) {
    if (!LOOK) return null;
    return LOOK.races.find((r) => r.toLowerCase() === String(race).toLowerCase()) || null;
  }
  function isHairless(race) {
    return !!LOOK && (LOOK.hairless_races || []).includes(libraryRace(race));
  }
  function styleOf(key) {
    return (LOOK?.hair_styles || []).find((s) => s.style_key === key || s.label === key) || null;
  }
  /** Bald and hooded heads have no colour to choose. */
  function styleHasColour(key) {
    const s = styleOf(key);
    return !!s && !(LOOK.no_colour_styles || []).includes(s.label);
  }
  function outfitOf(key) {
    return (LOOK?.outfits || []).find((o) => o.key === key) || null;
  }

  /**
   * The picks as the API takes them, with the ones this body cannot have
   * set to null — so a dragonborn's request says nothing about hair rather
   * than carrying a colour from the elf the player looked at first.
   */
  function lookPicks() {
    const hairless = isHairless(lookRace());
    const coloured = !hairless && styleHasColour(look.hair_style);
    return {
      gender: look.gender,
      hair_style: hairless ? null : look.hair_style,
      hair_color: coloured ? look.hair_color : null,
      outfit: look.outfit,
      outfit_color: look.outfit === 'tunic' ? null : look.outfit_color,
    };
  }

  /** looks_options' path_template, stem_rule and variant_rule, for showing. */
  function lookPath(kind) {
    const race = libraryRace(lookRace());
    const p = lookPicks();
    const us = (s) => String(s).replace(/ /g, '_');
    const stem = p.hair_style === null ? 'base'
      : (p.hair_color === null ? p.hair_style : `${p.hair_style}__${us(p.hair_color)}`);
    const variant = p.outfit === 'tunic' ? 'tunic' : `${p.outfit}__${us(p.outfit_color)}`;
    return `${LOOK_LIB}${p.gender}_${race}/${stem}/${variant}.${kind}.webp`;
  }

  /** One line of what they are wearing, for the caption and the review. */
  function lookSummary() {
    const p = lookPicks();
    const parts = [capital(p.gender)];
    if (p.hair_style !== null) {
      const style = styleOf(p.hair_style)?.label || p.hair_style;
      parts.push(p.hair_color ? `${p.hair_color} hair, ${style}` : style);
    }
    const o = outfitOf(p.outfit);
    parts.push(p.outfit_color ? `${o?.label || p.outfit}, ${p.outfit_color}` : (o?.label || p.outfit));
    return parts.join(' · ');
  }

  /** Dress for the class, unless the player has already chosen. */
  function applyClassOutfit() {
    if (outfitTouched || !LOOK) return;
    setOutfit((LOOK.class_outfit || {})[lookClass()] || 'tunic');
  }

  /**
   * Change outfit, keeping the colour if the new one comes in it and taking
   * the first colour if not — plate in "brown" does not exist.
   */
  function setOutfit(key) {
    const o = outfitOf(key);
    if (!o) return;
    look.outfit = o.key;
    if (o.key === 'tunic') return;
    if (!o.colours.includes(look.outfit_color)) look.outfit_color = o.colours[0];
  }

  // ---- Controls -----------------------------------------------------------

  /** A row of radios dressed as paint; native radios, so arrow keys walk it. */
  function swatches(name, colours) {
    return colours.map((c) => `
      <label class="look-swatch" title="${capital(c)}">
        <input type="radio" name="${name}" value="${c}">
        <span class="look-swatch-chip" style="--sw: ${SWATCH[c] || '#777'}"></span>
        <span class="look-swatch-name">${capital(c)}</span>
      </label>`).join('');
  }

  /** Built once; syncLookControls() moves the values about afterwards. */
  function buildLookControls() {
    // Body first and unconditionally: it sits on the identity line, outside
    // the picker, and steers the name dice whether or not anything is painted.
    $('#look-gender').innerHTML = BODIES.map((g) => `
      <label class="look-seg-opt">
        <input type="radio" name="look-gender" value="${g}">
        <span>${capital(g)}</span>
      </label>`).join('');
    $('#look-gender').addEventListener('change', (e) => {
      look.gender = e.target.value;
      lookChanged();
    });
    syncBody();

    if (!LOOK) return;
    $('#look-hair-style').innerHTML = LOOK.hair_styles
      .map((s) => `<option value="${s.style_key}">${capital(s.label)}</option>`).join('');
    $('#look-hair-color').innerHTML = swatches('look-hair-color', LOOK.hair_colours);
    $('#look-outfit').innerHTML = LOOK.outfits
      .map((o) => `<option value="${o.key}">${o.label}</option>`).join('');

    $('#look-picker').addEventListener('change', (e) => {
      const t = e.target;
      if (t.id === 'look-hair-style') look.hair_style = t.value;
      else if (t.name === 'look-hair-color') look.hair_color = t.value;
      else if (t.id === 'look-outfit') { outfitTouched = true; setOutfit(t.value); }
      else if (t.name === 'look-outfit-color') { outfitTouched = true; look.outfit_color = t.value; }
      else return;
      lookChanged();
    });

    // The arrows step the select beside them and wrap, then go down the same
    // path as choosing from it, so there is one way a pick changes.
    $('#look-picker').addEventListener('click', (e) => {
      const btn = e.target.closest('[data-cycle]');
      if (!btn) return;
      const sel = document.getElementById(btn.dataset.cycle);
      const n = sel.options.length;
      sel.selectedIndex = (sel.selectedIndex + Number(btn.dataset.dir) + n) % n;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    });

    $('#look-surprise').addEventListener('click', surpriseLook);
    $('#class')?.addEventListener('change', () => {
      applyClassOutfit();
      lookChanged();
    });
  }

  /** Body's radios, which sit outside the picker and outlive a missing library. */
  function syncBody() {
    document.querySelectorAll('input[name="look-gender"]').forEach((r) => { r.checked = r.value === look.gender; });
  }

  /** Put the picks into the controls, and hide or grey what this body lacks. */
  function syncLookControls() {
    syncBody();
    if (!LOOK) return;
    const hairless = isHairless(lookRace());
    const coloured = styleHasColour(look.hair_style);

    $('#look-hair-style').value = look.hair_style;
    document.querySelectorAll('input[name="look-hair-color"]').forEach((r) => {
      r.checked = r.value === look.hair_color;
      r.disabled = !coloured;
    });
    $('#look-outfit').value = look.outfit;

    // Dragonborn have no hair at all, so both hair controls go rather than
    // sitting there inert. A bald or hooded head is a choice the player made,
    // so the colour stays where it is, greyed, and comes back if they change
    // their mind — taking the swatches away would move everything under them.
    $('#look-style-field').hidden = hairless;
    $('#look-hair-field').hidden = hairless;
    $('#look-hair-field').classList.toggle('is-off', !coloured);
    const note = $('#look-hair-note');
    note.hidden = coloured;
    note.textContent = look.hair_style === 'hood'
      ? 'The hood hides the hair, so there is no colour to choose.'
      : 'A shaven head has no colour to choose.';

    const o = outfitOf(look.outfit);
    const field = $('#look-outfit-field');
    field.hidden = !o || o.key === 'tunic';
    if (o && o.key !== 'tunic') {
      const box = $('#look-outfit-color');
      if (box.dataset.outfit !== o.key) {
        box.innerHTML = swatches('look-outfit-color', o.colours);
        box.dataset.outfit = o.key;
      }
      box.querySelectorAll('input').forEach((r) => { r.checked = r.value === look.outfit_color; });
    }
    const def = (LOOK.class_outfit || {})[lookClass()];
    $('#look-outfit-note').textContent = !outfitTouched && def
      ? `What a ${lookClass()} usually wears. Change it if you like.`
      : '';
  }

  /** Roll every pick but the body and the race, both of which were said on purpose. */
  function surpriseLook() {
    if (!LOOK) return;
    const any = (list) => list[Math.floor(Math.random() * list.length)];
    look.hair_style = any(LOOK.hair_styles).style_key;
    look.hair_color = any(LOOK.hair_colours);
    const o = any(LOOK.outfits);
    look.outfit = o.key;
    look.outfit_color = o.key === 'tunic' ? null : any(o.colours);
    // Asking for a surprise is choosing to take one: a class change after
    // this does not undo the outfit it rolled.
    outfitTouched = true;
    lookChanged();
  }

  function lookChanged() {
    syncLookControls();
    paintLook();
    syncLookUrl();
  }

  // ---- The painting -------------------------------------------------------

  /**
   * Show the picks. Preload first and swap on arrival, so the old painting
   * stays up until the new one is ready rather than flashing black between.
   *
   * A file that is not there is the normal case while the library is only
   * partly baked, and the vhost answers a missing file with the homepage and
   * a 200 — so the browser's refusal to decode it is the only signal, and
   * it is enough. The frame says so in words and the picture goes, rather
   * than leaving a different person standing there under a caption that
   * describes somebody else.
   */
  function paintLook() {
    if (lookStatus === 'off') return;
    const seq = ++lookSeq;
    const frame = $('#look-frame-full');
    const bustFrame = $('#look-frame-bust');
    const missing = $('#look-missing');
    const cap = $('#look-caption');
    const race = lookRace();

    if (!libraryRace(race)) {
      lookStatus = 'nolib';
      frame.classList.add('is-missing');
      bustFrame.classList.add('is-missing');
      missing.hidden = false;
      missing.textContent = `No looks have been painted for ${race ? 'the ' + race : 'this people'} yet. `
        + 'They will wear their class portrait until there are.';
      cap.textContent = '';
      $('#look-picker').classList.add('is-nolib');
      renderWizard();
      return;
    }
    $('#look-picker').classList.remove('is-nolib');

    lookStatus = 'loading';
    frame.classList.add('is-loading');
    cap.textContent = lookSummary();
    const fullUrl = lookPath('full');
    const bustUrl = lookPath('bust');

    const full = new Image();
    full.onload = () => {
      if (seq !== lookSeq) return;
      $('#look-full').src = fullUrl;
      $('#look-full').alt = `${race}: ${lookSummary()}`;
      frame.classList.remove('is-loading', 'is-missing');
      missing.hidden = true;
      lookStatus = 'ok';
      renderWizard();
    };
    full.onerror = () => {
      if (seq !== lookSeq) return;
      frame.classList.remove('is-loading');
      frame.classList.add('is-missing');
      bustFrame.classList.add('is-missing');
      missing.hidden = false;
      missing.textContent = 'This look has not been painted yet. Try another hair, outfit or colour.';
      lookStatus = 'missing';
      renderWizard();
    };
    full.src = fullUrl;

    const bust = new Image();
    bust.onload = () => {
      if (seq !== lookSeq) return;
      $('#look-bust').src = bustUrl;
      bustFrame.classList.remove('is-missing');
    };
    bust.onerror = () => {
      if (seq === lookSeq) bustFrame.classList.add('is-missing');
    };
    bust.src = bustUrl;
  }

  // ---- The address bar ----------------------------------------------------

  const LOOK_PARAMS = ['race', 'gender', 'hair_style', 'hair_color', 'outfit', 'outfit_color'];

  /**
   * A look is a link: the picks go into the query string as they change, so
   * a look can be sent to somebody or reopened. Only the look's own params
   * are rewritten — `redress`, `party_id` and `module` mean something else
   * here and are left exactly as they came. replaceState, not push: a
   * hundred swatch clicks are not a hundred places Back should go.
   *
   * Colours are written with underscores, as the files are named, so the
   * link has no %20 in it; both spellings are read back.
   */
  function syncLookUrl() {
    if (lookStatus === 'off' || !LOOK) return;
    const params = new URLSearchParams(location.search);
    const p = lookPicks();
    const slug = (s) => String(s).replace(/ /g, '_');
    const set = (k, v) => (v === null || v === undefined || v === '' ? params.delete(k) : params.set(k, v));
    // A re-dress cannot change race, so it does not pretend to in the link.
    set('race', state.redressId ? null : lookRace());
    set('gender', p.gender);
    set('hair_style', p.hair_style);
    set('hair_color', p.hair_color && slug(p.hair_color));
    set('outfit', p.outfit);
    set('outfit_color', p.outfit_color && slug(p.outfit_color));
    const q = params.toString();
    history.replaceState(history.state, '', location.pathname + (q ? '?' + q : '') + location.hash);
  }

  /**
   * Take what a link says, where it says something the menu has. Anything
   * else is ignored rather than refused: a stale link should open on the
   * nearest look, not on an error.
   */
  function readLookUrl(params) {
    // Gender before the guard: it is the name dice's steer as well as the
    // face's, so a link that says it still means it with no library here.
    const g = params.get('gender');
    if (BODIES.includes(g)) look.gender = g;
    if (!LOOK) return;
    const unslug = (s) => String(s || '').replace(/_/g, ' ');
    const style = styleOf(params.get('hair_style') || '');
    if (style) look.hair_style = style.style_key;
    const hc = unslug(params.get('hair_color'));
    if (LOOK.hair_colours.includes(hc)) look.hair_color = hc;
    if (outfitOf(params.get('outfit'))) {
      setOutfit(params.get('outfit'));
      outfitTouched = true;
    }
    const oc = unslug(params.get('outfit_color'));
    if (outfitOf(look.outfit)?.colours.includes(oc)) {
      look.outfit_color = oc;
      outfitTouched = true;
    }
  }

  /** The race in a link, put into the select before anything is drawn. */
  function readRaceUrl(params) {
    const want = params.get('race');
    const sel = $('#race');
    if (!want || !sel) return;
    const opt = [...sel.options].find((o) => o.value.toLowerCase() === want.toLowerCase());
    if (opt) {
      sel.value = opt.value;
      fillSubraces();
    }
  }

  /** Turn the picture on, once the race, class and any link have been read. */
  function startLook() {
    syncLookControls();
    lookStatus = 'loading';
    paintLook();
    syncLookUrl();
  }

  /**
   * Strip the page down to the look.
   *
   * Everything else here — abilities, race, class — is decided once at creation
   * and is not a wardrobe, so re-dressing shows the picker and no wizard
   * chrome at all: no rail, no Back, no Next, just the character and Save.
   * Hiding rather than building a second page keeps one implementation of it.
   *
   * The identity line goes with the chrome, all but Body. A name is not a
   * wardrobe either, and a race select that silently changed whose body was
   * showing on somebody else's sheet would be the one control on this page
   * that could change a character into a different character. Race and class
   * come off the character, which is also what the server reads them from.
   * Body is a look pick like any other, so it stays — alone on the line.
   */
  async function enterRedressMode(params) {
    let sheet;
    try {
      sheet = await API.get('character/sheet&character_id=' + state.redressId);
    } catch (e) {
      showFormError(e.message);
      return;
    }
    const c = sheet.character;

    document.body.classList.add('redress-mode');
    // The identity step is the only one that exists now, so show it and drop
    // the rest out of the document rather than merely hiding them — a hidden
    // step still holds required-looking form controls.
    document.querySelectorAll('.wiz-step').forEach((sec) => {
      sec.hidden = sec.dataset.step !== 'identity';
    });
    $('#wiz-rail').remove();
    $('#wiz-back').remove();
    $('#wiz-next').remove();
    document.querySelectorAll('.wiz-idline > .wiz-idfield:not(#look-body-field)').forEach((f) => f.remove());
    $('#name-check')?.remove();
    $('#racial-preview')?.remove();

    $('#submit-btn').classList.remove('hidden');
    $('#submit-btn').textContent = 'Save appearance';
    $('#wiz-title').textContent = c.name;
    $('#wiz-blurb').textContent =
      `Level ${c.level} ${c.race} ${c.class} — change how they look.`;

    redressRace = c.race || '';
    redressClass = c.class || '';

    // Open on the look they already wear, so re-dressing starts from the
    // character rather than from a stranger of the right people. The record
    // holds labels ("long and loose", "dark brown"); styleOf() reads either.
    // A character from before the library has no record and is dressed for
    // their class, the same default a new one gets.
    const saved = c.look;
    if (saved && BODIES.includes(saved.gender)) look.gender = saved.gender;
    if (saved && LOOK) {
      if (styleOf(saved.hair_style || '')) look.hair_style = styleOf(saved.hair_style).style_key;
      if (saved.hair_color) look.hair_color = saved.hair_color;
      if (outfitOf(saved.outfit)) {
        look.outfit = saved.outfit;
        look.outfit_color = saved.outfit_color || null;
        outfitTouched = true;
      }
    } else {
      applyClassOutfit();
    }
    readLookUrl(params);
    startLook();
  }

  function showFormError(message) {
    const err = $('#form-error');
    if (!err) return;
    err.textContent = message;
    err.classList.remove('hidden');
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
    const line = $('#racial-preview');
    if (line) {
      line.textContent = `Speed ${r.speed} ft. Bonuses: ${bonuses.join(', ') || 'none'}. ${r.traits || ''}`.trim();
    }
    showNameRoll(r);

    // Changing the race changes whose body is showing, and for a dragonborn
    // whether there is any hair to choose.
    lookChanged();
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
    if (!btn) return;
    const tables = state.nameTables || [];
    const has = !!race && (tables.includes(race.subrace || '') || tables.includes(race.name));
    btn.hidden = !has;
    // Body is not hidden with it. It shares a line with three other fields,
    // and a control that disappears for some races takes the ones beside it
    // for a walk every time the race changes.
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
      // Body always has a value, so the dice always draw from that pool:
      // the name and the face beside it are one person.
      let q = 'meta/roll_name&race=' + encodeURIComponent(race.name);
      if (race.subrace) q += '&subrace=' + encodeURIComponent(race.subrace);
      q += '&gender=' + encodeURIComponent(look.gender);
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
    // "Dark Elf Elf Barbarian" was what joining all three gave, because half
    // the subraces already say what they are a subrace of: Dark Elf, Hill
    // Dwarf, Wood Elf. The other half do not — a Lightfoot is a Halfling and a
    // Forest Gnome is a Gnome — so the race name is added only when the
    // subrace has not already said it.
    const kin = !race ? ''
      : !race.subrace ? race.name
      : race.subrace.includes(race.name) ? race.subrace
      : `${race.subrace} ${race.name}`;
    const sub = [kin, cls?.name].filter(Boolean).join(' ');
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
      ['Look', libraryRace(race?.name || '') ? lookSummary() : 'their class portrait'],
    ].map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('');

    renderReviewPortrait();
  }

  /**
   * The character, as they will actually appear.
   *
   * The bust of the look they picked — the same file the server will copy
   * into their sprite — or the class art when their people has no looks
   * painted, which is what the server will leave them wearing. Either way the
   * review cannot promise a face nobody is going to get.
   */
  function renderReviewPortrait() {
    const box = $('#review-bust');
    if (!box) return;

    const painted = !!libraryRace(lookRace()) && lookStatus !== 'missing';
    box.classList.toggle('is-look', painted);
    if (painted) {
      box.innerHTML = `<img class="pd-bust-layer" src="${lookPath('bust')}" alt="">`;
      return;
    }
    const key = ($('#class').value || 'fighter').toLowerCase();
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

    // Re-dressing only ever posts the look; nothing else on this form is theirs
    // to change any more. Race and class are not sent: the server reads them
    // off the character, so this request cannot dress anybody as anybody else.
    if (state.redressId) {
      $('#submit-btn').disabled = true;
      try {
        if (lookStatus !== 'ok') throw new Error('That look has not been painted yet — pick another.');
        await API.post('character/look', {
          character_id: state.redressId,
          look: lookPicks(),
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
    };
    // The five choices, and no sprite_key: the server finds the picture by
    // the same rule the preview did and bakes it once the row exists. A
    // people with no looks painted sends none, and is created wearing the
    // class portrait the review showed.
    if (libraryRace(payload.race)) payload.look = lookPicks();
    if (state.feat) payload.feat = state.feat;
    if (state.partyId) payload.party_id = state.partyId;
    if (state.module && !state.partyId) payload.module = state.module;

    try {
      $('#submit-btn').disabled = true;

      await API.post('character/create', payload);

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
