/**
 * The d20 ceremony: the two-phase check, drawn.
 *
 * Phase one shows what is being asked — the DC, the modifier broken into the
 * pieces that made it, the odds, and everything the party could spend to move
 * them — and waits. Phase two throws the die on the server and animates the
 * result the server sends back.
 *
 * The die never decides anything here. `check/resolve` has already rolled by
 * the time this file sees a number, and the animation exists to land on
 * `result.natural` and nothing else. That is not a stylistic choice: a client
 * that picked the number could be reloaded until it picked a better one, which
 * is exactly why CheckService splits request from resolve and keeps the DC and
 * the modifier on the server between them.
 */
(function () {
  const G = () => window.Game;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => window.Game.esc(s);

  // =========================================================================
  // Odds
  //
  // Everything below has to agree with CheckService::successChance in PHP. The
  // server computes the chance once, for the check as offered with nothing
  // spent, and this recomputes it as the player toggles boosts; if the two
  // disagree the number visibly jumps the moment a checkbox is touched, which
  // reads as the game changing its mind about the odds.
  //
  // Counted face by face rather than from a closed form, for the same reason
  // the PHP does: a natural 20 always succeeds and a natural 1 always fails,
  // whatever the DC, and no formula for "d20 + mod beats DC" agrees with that
  // at either end of the die.
  // =========================================================================

  function faceSucceeds(face, modifier, dc) {
    if (face === 20) return true;
    if (face === 1) return false;
    return face + modifier >= dc;
  }

  /** Mirror of CheckService::successChance, as a probability rather than a percent. */
  function successProbability(modifier, dc, dieMode) {
    let hits = 0;
    for (let face = 1; face <= 20; face++) {
      if (faceSucceeds(face, modifier, dc)) hits++;
    }
    let p = hits / 20;
    if (dieMode === 'advantage') p = 1 - (1 - p) ** 2;
    else if (dieMode === 'disadvantage') p = p ** 2;
    return p;
  }

  /** Chance a die thrown in this mode comes up a natural 1. */
  function probNaturalOne(dieMode) {
    if (dieMode === 'advantage') return 1 / 400;      // both dice must be 1
    if (dieMode === 'disadvantage') return 39 / 400;  // either die is 1
    return 1 / 20;
  }

  /**
   * The distribution of a dice expression, as value → probability.
   *
   * Only `NdM` and a bare number ever appear in a boost — CheckService writes
   * `bonus_dice` as '1d4', '1d6' or '1d8' and `bonus_flat` separately — so this
   * does not try to be a dice parser. Anything it does not recognise
   * contributes nothing rather than throwing, because a boost the odds cannot
   * price is still a boost the player may spend.
   */
  function diceDistribution(expr) {
    const m = /^\s*(\d*)d(\d+)\s*$/i.exec(String(expr || ''));
    if (!m) return new Map([[0, 1]]);
    const count = parseInt(m[1] || '1', 10);
    const sides = parseInt(m[2], 10);
    let dist = new Map([[0, 1]]);
    for (let i = 0; i < count; i++) {
      const next = new Map();
      dist.forEach((p, total) => {
        for (let face = 1; face <= sides; face++) {
          const key = total + face;
          next.set(key, (next.get(key) || 0) + p / sides);
        }
      });
      dist = next;
    }
    return dist;
  }

  function convolve(a, b) {
    const out = new Map();
    a.forEach((pa, va) => {
      b.forEach((pb, vb) => {
        const key = va + vb;
        out.set(key, (out.get(key) || 0) + pa * pb);
      });
    });
    return out;
  }

  /** A boost's die mode folded in, exactly as CheckService::combineMode does. */
  function combineMode(mode, granted) {
    if (granted !== 'advantage' && granted !== 'disadvantage') return mode;
    if (mode === 'normal' || mode === granted) return granted;
    return 'normal';
  }

  /**
   * The odds with a set of boosts spent, as a whole percent.
   *
   * Halfling Lucky is folded in the way resolvePending() applies it: the mode
   * is rolled first, and only then is a natural 1 thrown away for one fresh,
   * ordinary d20. Treating it as a flat bonus would flatter it.
   */
  function chanceWith(check, chosen) {
    let mode = check.die_mode || 'normal';
    let bonusDist = new Map([[0, 1]]);
    let rerollOnes = false;

    chosen.forEach((b) => {
      if (b.die_mode) mode = combineMode(mode, b.die_mode);
      if (b.reroll_ones) rerollOnes = true;
      const flat = parseInt(b.bonus_flat, 10) || 0;
      const dice = String(b.bonus_dice || '').trim();
      if (!flat && !dice) return;
      let d = dice ? diceDistribution(dice) : new Map([[0, 1]]);
      if (flat) d = convolve(d, new Map([[flat, 1]]));
      bonusDist = convolve(bonusDist, d);
    });

    let p = 0;
    bonusDist.forEach((prob, bonus) => {
      const mod = check.base + bonus;
      let q = successProbability(mod, check.dc, mode);
      if (rerollOnes) {
        // The reroll is a plain d20 whatever the original mode was.
        q += probNaturalOne(mode) * successProbability(mod, check.dc, 'normal');
      }
      p += prob * q;
    });

    return Math.round(Math.min(1, p) * 100);
  }

  /** The modifier as it currently stands, plus the dice the boosts add. */
  function totalLabel(check, chosen) {
    const flat = chosen.reduce((n, b) => n + (parseInt(b.bonus_flat, 10) || 0), check.base);
    const dice = chosen.map((b) => String(b.bonus_dice || '').trim()).filter(Boolean);
    return window.Game.fmtMod(flat) + (dice.length ? ' + ' + dice.join(' + ') : '');
  }

  // =========================================================================
  // The overlay
  // =========================================================================

  const FACE_STEPS = [40, 45, 55, 65, 80, 100, 130, 170, 220, 290, 380];

  /**
   * Whether to throw a real icosahedron or the flat hexagon.
   *
   * The 3D die is the default now that there is one whose geometry is derived
   * rather than eyeballed, but the hexagon stays reachable with `?dice=flat` so
   * the two can be compared on the same throw, and so there is something to fall
   * back to if a machine renders the solid badly. If the generated geometry did
   * not load at all the question does not arise.
   */
  function use3dDice() {
    if (!window.Dice3D || !window.Dice3D.available()) return false;
    const want = new URLSearchParams(location.search).get('dice');
    if (want === 'flat') return false;
    if (want === '3d') return true;
    return true;
  }

  let current = null; // the open ceremony, so nothing can open two

  /**
   * Run a check from its offer to its verdict.
   *
   * `opts.resolve(checkId, boostKeys)` is what actually throws the die, and it
   * differs by caller: a conversation resolves through `dialogue/resolve_check`
   * so the reply it was waiting on can continue in the same round trip, while
   * anything else goes to `check/resolve`. Either way it must return the roll
   * result CheckService produces.
   *
   * @returns {Promise<object|null>} the roll result, or null if the player walked away
   */
  function open(check, opts = {}) {
    if (current) return Promise.resolve(null);

    return new Promise((resolve) => {
      const root = $('#check-root');
      const chosen = new Map(); // key → boost
      let settled = false;
      let release = null;

      const finish = (value) => {
        if (settled) return;
        settled = true;
        current = null;
        if (release) release();
        root.classList.add('hidden');
        root.innerHTML = '';
        resolve(value);
      };

      root.innerHTML = phaseOneHtml(check, opts);
      root.classList.remove('hidden');
      const panel = $('.check-panel', root);
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      panel.setAttribute('aria-label', `${check.label} check, DC ${check.dc}`);
      release = window.UI.pushOverlay(panel, () => finish(null));
      current = { finish };

      const readout = () => {
        const list = [...chosen.values()];
        $('.check-total-value', root).textContent = totalLabel(check, list);
        const pct = chanceWith(check, list);
        $('.check-chance-value', root).textContent = pct + '%';
        $('.check-chance-bar span', root).style.width = pct + '%';
        // Boosts can change the mode as well as the number — Lucky does not,
        // but nothing stops content offering one that grants advantage.
        let mode = check.die_mode || 'normal';
        list.forEach((b) => { if (b.die_mode) mode = combineMode(mode, b.die_mode); });
        const badge = $('.check-mode', root);
        badge.className = 'check-mode mode-' + mode;
        badge.textContent = mode === 'normal' ? 'One die' : mode;
      };

      $$('.boost-row', root).forEach((row) => {
        row.addEventListener('click', () => {
          const boost = check.boosts.find((b) => b.key === row.dataset.key);
          if (!boost) return;
          if (chosen.has(boost.key)) chosen.delete(boost.key);
          else chosen.set(boost.key, boost);
          const on = chosen.has(boost.key);
          row.classList.toggle('on', on);
          row.setAttribute('aria-pressed', on ? 'true' : 'false');
          readout();
        });
      });
      readout();

      $('[data-cancel]', root)?.addEventListener('click', () => finish(null));

      $('[data-roll]', root).addEventListener('click', async () => {
        $$('button', root).forEach((b) => { b.disabled = true; });
        let result;
        try {
          const resolver = opts.resolve || defaultResolve;
          result = await resolver(check.check_id, [...chosen.keys()]);
        } catch (e) {
          G().showError(e.message);
          finish(null);
          return;
        }
        window.UI.refreshRolls();
        // Read the opt-out before playResult replaces the panel's contents.
        const automate = !!$('[data-automate]', root)?.checked;
        await playResult(root, check, result);
        // The panel now shows the verdict; the button underneath dismisses it.
        $('[data-done]', root).addEventListener('click', () => finish({ ...result, automate }));
        $('[data-done]', root).focus();
      });
    });
  }

  async function defaultResolve(checkId, boosts) {
    const r = await API.post('check/resolve', { check_id: checkId, boosts });
    return r.result;
  }

  function phaseOneHtml(check, opts = {}) {
    const bust = G().spriteArtUrl(check.character?.sprite_key || 'fighter', '_bust');
    const parts = (check.parts || [])
      .map((p) => `<div class="mod-part"><span>${esc(p.label)}</span><em>${window.Game.fmtMod(p.value)}</em></div>`)
      .join('');
    const reasons = (check.die_reasons || []).length
      ? `<p class="check-reasons">${(check.die_reasons || []).map(esc).join(' · ')}</p>` : '';

    const boosts = (check.boosts || []).length ? `
      <div class="boost-list" role="group" aria-label="What you may spend">
        ${check.boosts.map((b) => `
          <button type="button" class="boost-row" data-key="${esc(b.key)}" aria-pressed="false">
            <span class="boost-name">${esc(b.label)}</span>
            <span class="boost-detail">${esc(b.detail || '')}</span>
            <span class="boost-adds">${esc(boostAdds(b))}</span>
            <span class="boost-cost cost-${esc(b.cost)}">${esc(costLabel(b.cost))}</span>
          </button>`).join('')}
      </div>`
      : '<p class="help-hint">Nothing to spend on this one.</p>';

    return `<div class="check-panel">
      <div class="check-head">
        <img class="check-bust" src="${esc(bust)}" alt="">
        <div class="check-title">
          <div class="check-who">${esc(check.character?.name || '')}</div>
          <h2>${esc(check.label)}</h2>
          <div class="check-mode mode-normal">One die</div>
          ${reasons}
        </div>
        <div class="check-dc"><span>DC</span><strong>${esc(check.dc)}</strong></div>
      </div>

      <div class="check-body">
        <div class="check-mods">
          <h3>Your modifier</h3>
          ${parts}
          <div class="mod-part mod-total"><span>Total</span><em class="check-total-value">+0</em></div>
        </div>
        <div class="check-odds">
          <h3>Odds</h3>
          <div class="check-chance-value">0%</div>
          <div class="check-chance-bar"><span style="width:0%"></span></div>
        </div>
      </div>

      <div class="check-boosts">
        <h3>Spend something?</h3>
        ${boosts}
      </div>

      ${opts.automateLabel ? `
      <label class="check-automate">
        <input type="checkbox" data-automate>
        <span>${esc(opts.automateLabel)}</span>
      </label>` : ''}

      <div class="check-actions">
        <button type="button" class="btn btn-primary" data-roll>Roll the die</button>
        <button type="button" class="btn" data-cancel>Step back</button>
      </div>
    </div>`;
  }

  function boostAdds(b) {
    if (b.reroll_ones) return 'reroll a 1';
    const dice = String(b.bonus_dice || '').trim();
    const flat = parseInt(b.bonus_flat, 10) || 0;
    if (dice && flat) return `+${dice} ${window.Game.fmtMod(flat)}`;
    if (dice) return '+' + dice;
    if (flat) return window.Game.fmtMod(flat);
    if (b.die_mode) return b.die_mode;
    return '';
  }

  function costLabel(cost) {
    if (cost === 'free') return 'free';
    if (cost === 'use') return 'costs a use';
    if (cost === 'concentration') return 'costs concentration';
    return cost || '';
  }

  // =========================================================================
  // Phase two: land the die on the number the server already rolled
  // =========================================================================

  /**
   * Which of the thrown dice was the one that counted.
   *
   * `rolls` is what Dice::d20Mode produced, in order, with a Lucky reroll
   * appended after the fact — so a third entry is always the reroll and always
   * the one that stands, and with two entries the mode decides.
   */
  function keptIndex(result) {
    const rolls = result.rolls || [];
    if (rolls.length <= 1) return 0;
    if (rolls.length > 2) return rolls.length - 1;
    if (result.die_mode === 'advantage') return rolls[0] >= rolls[1] ? 0 : 1;
    if (result.die_mode === 'disadvantage') return rolls[0] <= rolls[1] ? 0 : 1;
    return rolls.indexOf(result.natural) >= 0 ? rolls.indexOf(result.natural) : 0;
  }

  async function playResult(root, check, result) {
    const rolls = result.rolls && result.rolls.length ? result.rolls : [result.natural];
    const kept = keptIndex(result);
    const reduced = G().reducedMotion();

    const solid = use3dDice();

    const panel = $('.check-panel', root);
    panel.innerHTML = `
      <div class="check-throw outcome-${esc(result.outcome)}">
        <div class="dice-row">
          ${rolls.map((_, i) => `<div class="die-holder ${i === kept ? 'kept' : 'discarded'}" data-die="${i}">
            ${solid ? '' : `<div class="d20"><span class="d20-face">—</span></div>`}
            ${rolls.length > 1 ? `<span class="d20-tag">${i === kept ? 'kept' : 'dropped'}</span>` : ''}
          </div>`).join('')}
        </div>
        <div class="throw-breakdown hidden" id="throw-breakdown"></div>
        <div class="throw-verdict hidden" id="throw-verdict"></div>
        <div class="check-actions">
          <button type="button" class="btn btn-primary hidden" data-done>Go on</button>
        </div>
      </div>`;

    const holders = $$('.die-holder', panel);

    if (solid) {
      // A real icosahedron, turned to the face the server already rolled. The
      // geometry is generated — see tools/gen_dice_css.py — and nothing here
      // chooses anything: `land` is told the number.
      const stages = holders.map((h, i) => {
        const stage = window.Dice3D.create(20, rolls[i]);
        h.insertBefore(stage, h.firstChild);
        return stage;
      });
      await window.Dice3D.landAll(
        stages.map((s, i) => [s, rolls[i]]),
        { duration: 1150, stagger: 140 }
      );
    } else {
      const faces = $$('.d20', panel);
      const setFace = (i, n) => { faces[i].querySelector('.d20-face').textContent = String(n); };
      if (reduced) {
        // Straight to the answer. Someone who has asked their OS to stop things
        // moving has not asked to be told the result more slowly.
        rolls.forEach((n, i) => setFace(i, n));
      } else {
        // Cycle faces with a decreasing frequency and settle. The last value
        // written is always the real one — the cycling never chooses anything.
        for (const delay of FACE_STEPS) {
          rolls.forEach((_, i) => setFace(i, 1 + Math.floor(Math.random() * 20)));
          await G().sleep(delay);
        }
        rolls.forEach((n, i) => setFace(i, n));
        faces.forEach((f) => f.classList.add('landed'));
        await G().sleep(320);
      }
    }

    holders.forEach((h, i) => h.classList.toggle('is-kept', i === kept));

    // The itemised total, then the verdict — in that order, because the point
    // of showing the parts is that the player can see the total add up before
    // being told what it bought.
    const breakdown = $('#throw-breakdown', panel);
    const parts = (result.parts || []).map(
      (p) => `<div class="mod-part"><span>${esc(p.label)}</span><em>${window.Game.fmtMod(p.value)}</em></div>`
    ).join('');
    breakdown.innerHTML = `
      <div class="mod-part"><span>Die</span><em>${esc(result.natural)}</em></div>
      ${parts}
      <div class="mod-part mod-total"><span>Total</span><em>${esc(result.total)}</em></div>
      <div class="mod-part mod-dc"><span>Needed</span><em>${esc(result.dc)}</em></div>`;
    breakdown.classList.remove('hidden');
    if (!reduced) await G().sleep(420);

    const verdict = $('#throw-verdict', panel);
    verdict.textContent = verdictWord(result);
    verdict.className = 'throw-verdict verdict-' + result.outcome;
    verdict.classList.remove('hidden');

    G().log(
      `${check.character?.name || 'You'} — ${check.label} DC ${result.dc}: `
      + `${result.natural} → ${result.total}, ${result.outcome}.`,
      result.outcome === 'critical' ? 'success' : result.outcome === 'fumble' ? 'danger' : ''
    );

    if (!reduced) await G().sleep(260);
    $('[data-done]', panel).classList.remove('hidden');
  }

  /**
   * A natural 20 reads as a critical and a natural 1 as a fumble whatever the
   * DC — see the note on CheckService::outcomeOf. That house rule is the whole
   * reason this screen exists, so it gets the loud treatment here.
   */
  function verdictWord(result) {
    if (result.outcome === 'critical') return 'Critical success!';
    if (result.outcome === 'fumble') return 'Fumble!';
    if (result.outcome === 'success') return `Success by ${result.margin}`;
    return `Failure by ${Math.abs(result.margin)}`;
  }

  window.Check = { open, successProbability, chanceWith };
})();
