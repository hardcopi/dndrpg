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
      // A painted bust is always showing for a race that has tokens, so this
      // step still gates on the name alone.
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

    // The bust is cheap to paint and the well is already in the document, so
    // coming back to this step just refreshes whose face is showing.
    if (step.id === 'identity') paintToken();

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
    // bust well, same submit of a sprite key — only the wizard chrome differs.
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
    bindTokenWell();

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

    // Re-dressing an existing character reuses this page rather than growing a
    // second copy of the look picker inside the character sheet — same bust
    // well, same sprite key, only the submit differs.
    if (state.redressId) {
      await enterRedressMode();
    } else {
      initWizard();
      goToStep(0);
      paintToken();
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
  // The look: a painted bust, not a doll
  //
  // The Unity Sidekick used to live in this box. It was a WebGL player and a
  // set of mesh bundles, and it made every new character a low-poly toy. The
  // rest of the game is a painting — Gold Box rooms, dialogue busts, race
  // plates — so the creator now picks from painted busts of the same kind.
  // Reroll and the arrows walk the list; the race select and the gender
  // select filter it. There is no recipe and no portrait post: the files
  // already live in assets/images/npcs/ as `{key}_bust.png` and `{key}_face.png`,
  // and create sends the key.
  // =========================================================================
  let tokenIndex = 0;
  let tokenRace = '';
  let redressRace = '';

  function tokenCatalog() {
    return (window.LOOK_TOKENS && typeof window.LOOK_TOKENS === 'object')
      ? window.LOOK_TOKENS : {};
  }

  function tokensFor(race, gender) {
    const rows = tokenCatalog()[race] || [];
    if (!gender) return rows.slice();
    const hit = rows.filter((t) => t.gender === gender);
    return hit.length ? hit : rows.slice();
  }

  function activeRace() {
    return redressRace || $('#race')?.value || '';
  }

  function currentToken() {
    const list = tokensFor(activeRace(), $('#name-gender')?.value || '');
    if (!list.length) return null;
    if (tokenIndex < 0 || tokenIndex >= list.length) tokenIndex = 0;
    return list[tokenIndex];
  }

  function paintToken() {
    const img = $('#look-token-bust');
    const cap = $('#look-token-caption');
    if (!img) return;
    const race = activeRace();
    const list = tokensFor(race, $('#name-gender')?.value || '');
    if (tokenRace !== race) {
      tokenRace = race;
      tokenIndex = 0;
    }
    if (!list.length) {
      img.removeAttribute('src');
      img.alt = '';
      if (cap) cap.textContent = 'No painted look for this people yet.';
      return;
    }
    if (tokenIndex >= list.length) tokenIndex = 0;
    const t = list[tokenIndex];
    img.src = artUrl('assets/images/npcs/' + t.key + '_bust.png');
    img.alt = race + ' look';
    if (cap) {
      cap.textContent = list.length > 1 ? (tokenIndex + 1) + ' of ' + list.length : '';
    }
  }

  function stepToken(delta) {
    const list = tokensFor(activeRace(), $('#name-gender')?.value || '');
    if (!list.length) return;
    tokenIndex = (tokenIndex + delta + list.length) % list.length;
    paintToken();
  }

  function rerollToken() {
    const list = tokensFor(activeRace(), $('#name-gender')?.value || '');
    if (list.length < 2) { paintToken(); return; }
    let next = tokenIndex;
    while (next === tokenIndex) next = Math.floor(Math.random() * list.length);
    tokenIndex = next;
    paintToken();
  }

  function bindTokenWell() {
    $('#look-token-prev')?.addEventListener('click', () => stepToken(-1));
    $('#look-token-next')?.addEventListener('click', () => stepToken(1));
    $('#look-token-reroll')?.addEventListener('click', rerollToken);
    $('#name-gender')?.addEventListener('change', () => {
      tokenIndex = 0;
      paintToken();
    });
  }

  /**
   * Strip the page down to the look.
   *
   * Everything else here — abilities, race, class — is decided once at creation
   * and is not a wardrobe, so re-dressing shows the painted bust and no wizard
   * chrome at all: no rail, no Back, no Next, just the character and Save.
   * Hiding rather than building a second page keeps one implementation of it.
   *
   * The identity line goes with the chrome. A name is not a wardrobe either, and
   * a race select that silently changed whose bust was showing on somebody
   * else's body would be the one control on this page that could change a
   * character into a different character.
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
    // The identity step is the only one that exists now, so show it and drop
    // the rest out of the document rather than merely hiding them — a hidden
    // step still holds required-looking form controls.
    document.querySelectorAll('.wiz-step').forEach((sec) => {
      sec.hidden = sec.dataset.step !== 'identity';
    });
    $('#wiz-rail').remove();
    $('#wiz-back').remove();
    $('#wiz-next').remove();
    $('.wiz-idline')?.remove();
    $('#name-check')?.remove();
    $('#racial-preview')?.remove();

    $('#submit-btn').classList.remove('hidden');
    $('#submit-btn').textContent = 'Save appearance';
    $('#wiz-title').textContent = c.name;
    $('#wiz-blurb').textContent =
      `Level ${c.level} ${c.race} ${c.class} — change how they look.`;

    // Open on the bust they are already wearing, so re-dressing starts from
    // the character rather than from a stranger of the right species.
    redressRace = c.race || '';
    const list = tokensFor(redressRace, '');
    const have = c.sprite_key || '';
    const found = list.findIndex((t) => t.key === have);
    tokenIndex = found >= 0 ? found : 0;
    tokenRace = redressRace;
    paintToken();
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

    // Changing the race changes whose bust is showing.
    paintToken();
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
    // The gender select is not hidden with it. It shares a line with three
    // other fields now, and a control that disappears for some races takes the
    // ones beside it for a walk every time the race changes.
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
      ['Look', 'a painted bust'],
    ].map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('');

    renderReviewPortrait();
  }

  /**
   * The character, as they will actually appear.
   *
   * The painted bust they picked, falling back to the class art if this race
   * has no tokens yet — which is the same fallback the party rail already
   * uses, so the review cannot promise a face nobody is going to get.
   */
  function renderReviewPortrait() {
    const box = $('#review-bust');
    if (!box) return;

    const token = currentToken();
    const key = token?.key || ($('#class').value || 'fighter').toLowerCase();
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
    // to change any more.
    if (state.redressId) {
      $('#submit-btn').disabled = true;
      try {
        const key = currentToken()?.key || '';
        if (!key) throw new Error('No painted look is available for this people.');
        await API.post('character/avatar', {
          character_id: state.redressId,
          sprite_key: key,
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
      // The painted bust they picked. The files already live on disk as
      // `{key}_bust.png` / `{key}_face.png`; create just sends the key.
      sprite_key: currentToken()?.key || '',
    };
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
