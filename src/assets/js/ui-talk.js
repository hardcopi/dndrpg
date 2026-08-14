/**
 * Conversation cards for the studio.
 *
 * Every simple condition and effect the engine already knows is a field.
 * Compound conditions (`not` / `any` / `all`) and anything the card cannot
 * name stay under Advanced, which is the original reason the JSON textarea
 * existed. Cards write the object-shaped node the engine already accepts.
 */
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  const CONDS = [
    { id: 'flag', label: 'If flag' },
    { id: 'quest', label: 'If quest' },
    { id: 'companion', label: 'If companion' },
    { id: 'origin', label: 'If origin' },
    { id: 'level', label: 'If level ≥' },
    { id: 'ability', label: 'If ability' },
    { id: 'item', label: 'If carrying' },
    { id: 'gold', label: 'If gold ≥' },
  ];

  const FX = [
    { id: 'start_quest', label: 'Start quest' },
    { id: 'fail_quest', label: 'Fail quest' },
    { id: 'quest_stage', label: 'Advance quest' },
    { id: 'start_combat', label: 'Start fight' },
    { id: 'set_flag', label: 'Set flag' },
    { id: 'clear_flag', label: 'Clear flag' },
    { id: 'increment_flag', label: 'Increment flag' },
    { id: 'pressure', label: 'Pressure' },
    { id: 'approval', label: 'Approval' },
    { id: 'give_item', label: 'Give item' },
    { id: 'take_item', label: 'Take item' },
    { id: 'give_gold', label: 'Give gold' },
    { id: 'take_gold', label: 'Take gold' },
    { id: 'xp', label: 'Give XP' },
    { id: 'heal', label: 'Heal' },
    { id: 'recruit', label: 'Recruit' },
    { id: 'companion_leaves', label: 'Companion leaves' },
    { id: 'romance', label: 'Romance stage' },
    { id: 'open_shop', label: 'Open shop' },
    { id: 'rest', label: 'Rest' },
    { id: 'travel', label: 'Travel' },
  ];

  const ABILS = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
  const QSTAT = ['active', 'available', 'completed', 'failed'];
  const REST = ['inn', 'camp'];

  function emptyDoc() {
    return {
      start: 'hail',
      nodes: {
        hail: { text: '', choices: [{ label: 'Leave.', end: true }] },
      },
    };
  }

  function asObject(node) {
    if (!node) return { text: '', choices: [] };
    if (Array.isArray(node)) {
      const v = node[0] && typeof node[0] === 'object' ? node[0] : {};
      return {
        text: String(v.text || ''),
        choices: Array.isArray(v.choices) ? v.choices.map(copyChoice) : [],
      };
    }
    return {
      text: String(node.text || ''),
      choices: Array.isArray(node.choices) ? node.choices.map(copyChoice) : [],
    };
  }

  function copyChoice(c) {
    const out = { label: String((c && c.label) || '') };
    if (c && c.end) out.end = true;
    else if (c && c.next) out.next = String(c.next);
    if (c && Array.isArray(c.effects) && c.effects.length) {
      out.effects = c.effects.map((e) => Object.assign({}, e));
    }
    if (c && Array.isArray(c.conditions) && c.conditions.length) {
      out.conditions = c.conditions.map((e) => Object.assign({}, e));
    }
    return out;
  }

  function condId(c) {
    if (!c || typeof c !== 'object') return 'raw';
    if (c.flag) return 'flag';
    if (c.quest) return 'quest';
    if (c.companion) return 'companion';
    if (c.origin) return 'origin';
    if (c.level_at_least != null) return 'level';
    if (c.ability) return 'ability';
    if (c.has_item) return 'item';
    if (c.gold_at_least != null) return 'gold';
    return 'raw';
  }

  function fxId(e) {
    if (!e || typeof e !== 'object') return 'raw';
    for (let i = 0; i < FX.length; i++) {
      if (Object.prototype.hasOwnProperty.call(e, FX[i].id)) return FX[i].id;
    }
    return 'raw';
  }

  function condFields(c) {
    const id = condId(c);
    if (id === 'flag') return { id, a: c.flag || '', b: c.at_least != null ? String(c.at_least) : (c.equals || '') };
    if (id === 'quest') return { id, a: c.quest || '', b: c.status || c.stage || c.resolution || 'active' };
    if (id === 'companion') {
      return {
        id,
        a: c.companion || '',
        b: c.status || (c.approval_at_least != null ? 'ap:' + c.approval_at_least : 'active'),
      };
    }
    if (id === 'origin') return { id, a: c.origin || '', b: '' };
    if (id === 'level') return { id, a: String(c.level_at_least || 1), b: '' };
    if (id === 'ability') return { id, a: c.ability || 'strength', b: String(c.at_least || 13) };
    if (id === 'item') return { id, a: c.has_item || '', b: '' };
    if (id === 'gold') return { id, a: String(c.gold_at_least || 0), b: '' };
    return { id: 'raw', a: JSON.stringify(c), b: '' };
  }

  function fieldsToCond(id, a, b) {
    if (id === 'flag') {
      const row = { flag: a };
      if (b !== '' && /^\d+$/.test(b)) row.at_least = Number(b);
      else if (b !== '') row.equals = b;
      return row;
    }
    if (id === 'quest') {
      const row = { quest: a };
      if (QSTAT.indexOf(b) !== -1) row.status = b;
      else if (b) row.stage = b;
      return row;
    }
    if (id === 'companion') {
      const row = { companion: a };
      if (b.indexOf('ap:') === 0) row.approval_at_least = Number(b.slice(3)) || 0;
      else if (b === 'locked') row.romance_locked = true;
      else row.status = b || 'active';
      return row;
    }
    if (id === 'origin') return { origin: a };
    if (id === 'level') return { level_at_least: Number(a) || 1 };
    if (id === 'ability') return { ability: a || 'strength', at_least: Number(b) || 13 };
    if (id === 'item') return { has_item: a };
    if (id === 'gold') return { gold_at_least: Number(a) || 0 };
    try { return JSON.parse(a); } catch (e) { return { flag: a }; }
  }

  function fxFields(e) {
    const id = fxId(e);
    if (id === 'start_quest' || id === 'fail_quest') return { id, a: e[id] || '', b: '' };
    if (id === 'quest_stage') {
      const s = e.quest_stage || {};
      return { id, a: s.quest || '', b: s.stage || '' };
    }
    if (id === 'start_combat') return { id, a: e.start_combat || '', b: '' };
    if (id === 'set_flag') return { id, a: e.set_flag || '', b: String(e.value == null ? 1 : e.value) };
    if (id === 'clear_flag') return { id, a: e.clear_flag || '', b: '' };
    if (id === 'increment_flag') return { id, a: e.increment_flag || '', b: String(e.by == null ? 1 : e.by) };
    if (id === 'pressure') return { id, a: String(e.pressure || 1), b: '' };
    if (id === 'approval') {
      const keys = Object.keys(e.approval || {});
      return { id, a: keys[0] || '', b: String((e.approval || {})[keys[0]] || 0) };
    }
    if (id === 'give_item' || id === 'take_item') {
      return { id, a: e[id] || '', b: String(e.quantity == null ? 1 : e.quantity) };
    }
    if (id === 'give_gold' || id === 'take_gold' || id === 'xp' || id === 'heal') {
      return { id, a: String(e[id] || 0), b: '' };
    }
    if (id === 'recruit' || id === 'companion_leaves') return { id, a: e[id] || '', b: '' };
    if (id === 'romance') {
      const s = e.romance || {};
      return { id, a: s.companion || '', b: String(s.stage == null ? 1 : s.stage) };
    }
    if (id === 'open_shop') return { id, a: e.open_shop || '', b: '' };
    if (id === 'rest') return { id, a: e.rest || 'inn', b: '' };
    if (id === 'travel') {
      const t = e.travel;
      return { id, a: (t && t.location) || (typeof t === 'string' ? t : ''), b: '' };
    }
    return { id: 'raw', a: JSON.stringify(e), b: '' };
  }

  function fieldsToFx(id, a, b) {
    if (id === 'start_quest') return { start_quest: a };
    if (id === 'fail_quest') return { fail_quest: a };
    if (id === 'quest_stage') return { quest_stage: { quest: a, stage: b } };
    if (id === 'start_combat') return { start_combat: a };
    if (id === 'set_flag') return { set_flag: a, value: b === '' ? '1' : b };
    if (id === 'clear_flag') return { clear_flag: a };
    if (id === 'increment_flag') return { increment_flag: a, by: Number(b) || 1 };
    if (id === 'pressure') return { pressure: Math.max(1, Math.min(5, Number(a) || 1)) };
    if (id === 'approval') {
      const row = { approval: {} };
      if (a) row.approval[a] = Number(b) || 0;
      return row;
    }
    if (id === 'give_item') return { give_item: a, quantity: Number(b) || 1 };
    if (id === 'take_item') return { take_item: a, quantity: Number(b) || 1 };
    if (id === 'give_gold') return { give_gold: Number(a) || 0 };
    if (id === 'take_gold') return { take_gold: Number(a) || 0 };
    if (id === 'xp') return { xp: Number(a) || 0 };
    if (id === 'heal') return { heal: Number(a) || 0 };
    if (id === 'recruit') return { recruit: a };
    if (id === 'companion_leaves') return { companion_leaves: a };
    if (id === 'romance') return { romance: { companion: a, stage: Number(b) || 1 } };
    if (id === 'open_shop') return { open_shop: a };
    if (id === 'rest') return { rest: REST.indexOf(a) !== -1 ? a : 'inn' };
    if (id === 'travel') return { travel: { location: a } };
    try { return JSON.parse(a); } catch (e) { return { set_flag: a, value: '1' }; }
  }

  function fxNote(e) {
    const id = fxId(e);
    if (id === 'start_quest') return 'starts ' + e.start_quest;
    if (id === 'fail_quest') return 'fails ' + e.fail_quest;
    if (id === 'quest_stage') return 'advances ' + (e.quest_stage.quest || '') + ' → ' + (e.quest_stage.stage || '');
    if (id === 'start_combat') return 'fights ' + e.start_combat;
    if (id === 'set_flag') return 'sets ' + e.set_flag;
    if (id === 'clear_flag') return 'clears ' + e.clear_flag;
    if (id === 'increment_flag') return e.increment_flag + ' += ' + (e.by || 1);
    if (id === 'pressure') return 'pressure +' + e.pressure;
    if (id === 'approval') {
      return Object.keys(e.approval || {}).map((k) => k + ' ' + (e.approval[k] > 0 ? '+' : '') + e.approval[k]).join(', ');
    }
    if (id === 'give_item') return 'gives ' + e.give_item;
    if (id === 'take_item') return 'takes ' + e.take_item;
    if (id === 'give_gold') return '+' + e.give_gold + ' gp';
    if (id === 'take_gold') return '−' + e.take_gold + ' gp';
    if (id === 'xp') return '+' + e.xp + ' xp';
    if (id === 'heal') return 'heals ' + e.heal;
    if (id === 'recruit') return 'recruits ' + e.recruit;
    if (id === 'companion_leaves') return e.companion_leaves + ' leaves';
    if (id === 'romance') return (e.romance.companion || '') + ' romance ' + (e.romance.stage || '');
    if (id === 'open_shop') return 'shop';
    if (id === 'rest') return 'rests (' + e.rest + ')';
    if (id === 'travel') return 'goes to ' + ((e.travel && e.travel.location) || '');
    return 'effect';
  }

  function slug(s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'node';
  }

  function uniqueNode(nodes, base) {
    let k = base;
    let n = 2;
    while (nodes[k]) {
      k = base + '_' + n;
      n += 1;
    }
    return k;
  }

  function optsHtml(list, val, labelOf) {
    return list.map((it) => {
      const v = typeof it === 'string' ? it : it.key;
      const lab = typeof it === 'string' ? it : (labelOf ? labelOf(it) : it.title || it.name || it.key);
      return `<option value="${esc(v)}"${v === val ? ' selected' : ''}>${esc(lab)}</option>`;
    }).join('');
  }

  function normalize(doc) {
    const src = doc && typeof doc === 'object' ? doc : emptyDoc();
    const nodes = {};
    const raw = src.nodes && typeof src.nodes === 'object' ? src.nodes : {};
    Object.keys(raw).forEach((k) => { nodes[k] = asObject(raw[k]); });
    if (!Object.keys(nodes).length) nodes.hail = asObject(null);
    const start = src.start && nodes[src.start] ? src.start : Object.keys(nodes)[0];
    return { start, nodes };
  }

  function mount(host, opts) {
    if (!host) return { getDoc: () => emptyDoc(), destroy() {} };
    const state = { doc: normalize(opts.doc), walk: null };
    const lists = {
      quests: opts.quests || [],
      fights: opts.fights || [],
      items: opts.items || [],
      companions: opts.companions || [],
      locations: opts.locations || [],
    };

    function emit() {
      if (opts.onChange) opts.onChange(state.doc);
    }

    function paint() {
      const keys = Object.keys(state.doc.nodes);
      const cards = keys.map((key) => {
        const node = state.doc.nodes[key];
        const start = state.doc.start === key;
        const choices = (node.choices || []).map((c, i) => choiceRow(key, c, i, keys)).join('');
        return `
          <article class="st-card-node${start ? ' is-start' : ''}" data-node="${esc(key)}">
            <header class="st-card-head">
              <code>${esc(key)}</code>
              ${start ? '<em>start</em>' : `<button type="button" class="btn" data-start="${esc(key)}">Start here</button>`}
              ${keys.length > 1 && !start
                ? `<button type="button" class="btn" data-drop-node="${esc(key)}">Remove</button>` : ''}
            </header>
            <label>They say
              <textarea rows="4" data-text="${esc(key)}">${esc(node.text)}</textarea>
            </label>
            <h4 class="ce-sub">Replies</h4>
            ${choices || '<p class="help-hint">No replies yet.</p>'}
            <div class="ce-actions">
              <button type="button" class="btn" data-add-choice="${esc(key)}">Add reply</button>
            </div>
          </article>`;
      }).join('');

      const graph = opts.lint && window.DialogGraph
        ? `<div class="st-talk-graph" id="st-talk-graph">${window.DialogGraph.svg(opts.lint)}</div>`
        : '';

      host.innerHTML = `
        <div class="st-talk">
          ${graph}
          <div class="st-talk-cards">${cards}</div>
          <div class="ce-actions">
            <button type="button" class="btn" id="st-talk-add">Add line</button>
            <button type="button" class="btn" id="st-talk-walk">Walk it</button>
            <button type="button" class="btn" id="st-talk-adv">Advanced</button>
          </div>
          <div class="st-talk-walk" id="st-talk-walkbox" hidden></div>
          <label class="st-talk-json" id="st-talk-jsonbox" hidden>JSON
            <textarea id="st-talk-json" class="ce-code" rows="12" spellcheck="false">${esc(JSON.stringify(state.doc, null, 2))}</textarea>
          </label>
        </div>`;

      host.querySelectorAll('[data-text]').forEach((el) => {
        el.addEventListener('input', () => {
          const n = state.doc.nodes[el.getAttribute('data-text')];
          if (n) n.text = el.value;
          emit();
        });
      });
      host.querySelectorAll('[data-start]').forEach((el) => {
        el.addEventListener('click', () => {
          state.doc.start = el.getAttribute('data-start');
          emit();
          paint();
        });
      });
      host.querySelectorAll('[data-drop-node]').forEach((el) => {
        el.addEventListener('click', () => {
          const k = el.getAttribute('data-drop-node');
          delete state.doc.nodes[k];
          Object.values(state.doc.nodes).forEach((n) => {
            (n.choices || []).forEach((ch) => {
              if (ch.next === k) { delete ch.next; ch.end = true; }
            });
          });
          emit();
          paint();
        });
      });
      host.querySelectorAll('[data-add-choice]').forEach((el) => {
        el.addEventListener('click', () => {
          const n = state.doc.nodes[el.getAttribute('data-add-choice')];
          if (!n) return;
          n.choices = n.choices || [];
          n.choices.push({ label: 'Go on.', end: true });
          emit();
          paint();
        });
      });
      bindChoices();
      const add = $('#st-talk-add', host);
      if (add) add.addEventListener('click', () => {
        const k = uniqueNode(state.doc.nodes, 'line');
        state.doc.nodes[k] = { text: '', choices: [{ label: 'Leave.', end: true }] };
        emit();
        paint();
      });
      const walkBtn = $('#st-talk-walk', host);
      if (walkBtn) walkBtn.addEventListener('click', () => {
        state.walk = state.doc.start;
        paintWalk();
      });
      const adv = $('#st-talk-adv', host);
      if (adv) adv.addEventListener('click', () => {
        const box = $('#st-talk-jsonbox', host);
        if (box) box.hidden = !box.hidden;
      });
      const frame = $('#st-talk-graph', host);
      if (frame && window.DialogGraph && window.DialogGraph.controls) {
        window.DialogGraph.controls(frame);
      }
      const json = $('#st-talk-json', host);
      if (json) json.addEventListener('change', () => {
        try {
          state.doc = normalize(JSON.parse(json.value));
          emit();
          paint();
        } catch (e) { /* save will refuse */ }
      });
    }

    function condValueHtml(f) {
      if (f.id === 'quest') {
        return `<select data-a><option value="">—</option>${optsHtml(lists.quests, f.a)}</select>
          <select data-b>${optsHtml(QSTAT, f.b)}</select>`;
      }
      if (f.id === 'companion') {
        return `<select data-a><option value="">—</option>${optsHtml(lists.companions, f.a, (c) => c.name)}</select>
          <input type="text" data-b value="${esc(f.b)}" placeholder="active / ap:10 / locked">`;
      }
      if (f.id === 'item') {
        return `<select data-a><option value="">—</option>${optsHtml(lists.items, f.a, (it) => it.name)}</select>`;
      }
      if (f.id === 'ability') {
        return `<select data-a>${optsHtml(ABILS, f.a)}</select>
          <input type="number" data-b value="${esc(f.b)}" min="1" max="30">`;
      }
      if (f.id === 'flag' || f.id === 'origin' || f.id === 'raw') {
        return `<input type="text" data-a value="${esc(f.a)}" placeholder="${f.id === 'raw' ? 'JSON' : 'name'}">
          ${f.id === 'flag' ? `<input type="text" data-b value="${esc(f.b)}" placeholder="equals / ≥ n">` : ''}`;
      }
      return `<input type="number" data-a value="${esc(f.a)}" min="0">`;
    }

    function fxValueHtml(f) {
      if (f.id === 'start_quest' || f.id === 'fail_quest') {
        return `<select data-a><option value="">—</option>${optsHtml(lists.quests, f.a)}</select>`;
      }
      if (f.id === 'quest_stage') {
        const q = lists.quests.find((x) => x.key === f.a);
        const stages = (q && q.stages) || [];
        return `<select data-a><option value="">—</option>${optsHtml(lists.quests, f.a)}</select>
          <input type="text" data-b value="${esc(f.b)}" placeholder="stage key" list="st-stage-keys">
          ${stages.length ? '' : ''}`;
      }
      if (f.id === 'start_combat') {
        return `<select data-a><option value="">—</option>${optsHtml(lists.fights, f.a, (x) => x.name)}</select>`;
      }
      if (f.id === 'give_item' || f.id === 'take_item') {
        return `<select data-a><option value="">—</option>${optsHtml(lists.items, f.a, (it) => it.name)}</select>
          <input type="number" data-b value="${esc(f.b)}" min="1" max="99">`;
      }
      if (f.id === 'approval' || f.id === 'recruit' || f.id === 'companion_leaves' || f.id === 'romance') {
        return `<select data-a><option value="">—</option>${optsHtml(lists.companions, f.a, (c) => c.name)}</select>
          ${f.id === 'approval' || f.id === 'romance'
            ? `<input type="number" data-b value="${esc(f.b)}">` : ''}`;
      }
      if (f.id === 'travel') {
        return `<select data-a><option value="">—</option>${optsHtml(lists.locations, f.a, (l) => l.name)}</select>`;
      }
      if (f.id === 'rest') {
        return `<select data-a>${optsHtml(REST, f.a)}</select>`;
      }
      if (f.id === 'open_shop') {
        return `<input type="text" data-a value="${esc(f.a)}" placeholder="npc key">`;
      }
      if (f.id === 'set_flag' || f.id === 'clear_flag' || f.id === 'increment_flag') {
        return `<input type="text" data-a value="${esc(f.a)}" placeholder="flag">
          ${f.id === 'clear_flag' ? '' : `<input type="text" data-b value="${esc(f.b)}" placeholder="${f.id === 'set_flag' ? 'value' : 'by'}">`}`;
      }
      if (f.id === 'raw') {
        return `<input type="text" data-a value="${esc(f.a)}" placeholder="JSON">`;
      }
      return `<input type="number" data-a value="${esc(f.a)}" min="0">`;
    }

    function choiceRow(nodeKey, c, i, keys) {
      const dest = c.end ? 'end' : (c.next || 'end');
      const destOpts = ['<option value="end"' + (dest === 'end' ? ' selected' : '') + '>End</option>']
        .concat(keys.filter((k) => k !== nodeKey).map((k) =>
          `<option value="${esc(k)}"${dest === k ? ' selected' : ''}>→ ${esc(k)}</option>`
        ))
        .concat(['<option value="__new__">→ new line…</option>'])
        .join('');
      const conds = (c.conditions || []).map((row, j) => condRow(condFields(row), j)).join('');
      const fxs = (c.effects || []).map((row, j) => effectRow(fxFields(row), j)).join('');
      return `
        <div class="st-reply" data-reply="${esc(nodeKey)}:${i}">
          <div class="st-reply-main">
            <input type="text" data-label value="${esc(c.label)}" placeholder="What they can say">
            <select data-dest>${destOpts}</select>
            <button type="button" class="btn" data-drop-choice>✕</button>
          </div>
          <div class="st-reply-when">${conds}
            <button type="button" class="btn" data-add-cond>Add when</button>
          </div>
          <div class="st-reply-does">${fxs}
            <button type="button" class="btn" data-add-fx>Add does</button>
          </div>
        </div>`;
    }

    function condRow(f, j) {
      return `<div class="st-pred" data-ci="${j}">
        <select data-kind>${CONDS.map((x) =>
          `<option value="${x.id}"${x.id === f.id ? ' selected' : ''}>${x.label}</option>`).join('')}
          <option value="raw"${f.id === 'raw' ? ' selected' : ''}>Raw…</option>
        </select>
        ${condValueHtml(f)}
        <button type="button" class="btn" data-drop-cond>✕</button>
      </div>`;
    }

    function effectRow(f, j) {
      return `<div class="st-pred" data-ei="${j}">
        <select data-kind>${FX.map((x) =>
          `<option value="${x.id}"${x.id === f.id ? ' selected' : ''}>${x.label}</option>`).join('')}
          <option value="raw"${f.id === 'raw' ? ' selected' : ''}>Raw…</option>
        </select>
        ${fxValueHtml(f)}
        <button type="button" class="btn" data-drop-fx>✕</button>
      </div>`;
    }

    function readPair(el) {
      const a = el.querySelector('[data-a]');
      const b = el.querySelector('[data-b]');
      return { a: a ? a.value : '', b: b ? b.value : '' };
    }

    function bindChoices() {
      host.querySelectorAll('.st-reply').forEach((row) => {
        const [nodeKey, idxS] = (row.getAttribute('data-reply') || '').split(':');
        const i = Number(idxS);
        const node = state.doc.nodes[nodeKey];
        if (!node || !node.choices[i]) return;
        const c = node.choices[i];
        const label = row.querySelector('[data-label]');
        const dest = row.querySelector('[data-dest]');
        if (label) label.addEventListener('input', () => { c.label = label.value; emit(); });
        if (dest) dest.addEventListener('change', () => {
          if (dest.value === '__new__') {
            const k = uniqueNode(state.doc.nodes, slug(c.label) || 'line');
            state.doc.nodes[k] = { text: '', choices: [{ label: 'Leave.', end: true }] };
            delete c.end;
            c.next = k;
          } else if (dest.value === 'end') {
            delete c.next;
            c.end = true;
          } else {
            delete c.end;
            c.next = dest.value;
          }
          emit();
          paint();
        });
        row.querySelectorAll('[data-drop-choice]').forEach((btn) => {
          btn.addEventListener('click', () => {
            node.choices.splice(i, 1);
            emit();
            paint();
          });
        });
        const addCond = row.querySelector('[data-add-cond]');
        if (addCond) addCond.addEventListener('click', () => {
          c.conditions = c.conditions || [];
          c.conditions.push({ flag: '' });
          emit();
          paint();
        });
        const addFx = row.querySelector('[data-add-fx]');
        if (addFx) addFx.addEventListener('click', () => {
          c.effects = c.effects || [];
          c.effects.push({ start_quest: '' });
          emit();
          paint();
        });
        row.querySelectorAll('[data-ci]').forEach((el) => {
          const j = Number(el.getAttribute('data-ci'));
          const kind = el.querySelector('[data-kind]');
          const sync = () => {
            const p = readPair(el);
            const id = kind ? kind.value : 'flag';
            if (!p.a && id !== 'raw') return;
            c.conditions[j] = fieldsToCond(id, p.a, p.b);
            emit();
          };
          if (kind) kind.addEventListener('change', () => {
            c.conditions[j] = fieldsToCond(kind.value, '', '');
            emit();
            paint();
          });
          el.querySelectorAll('[data-a],[data-b]').forEach((inp) => {
            inp.addEventListener('input', sync);
            inp.addEventListener('change', sync);
          });
          const drop = el.querySelector('[data-drop-cond]');
          if (drop) drop.addEventListener('click', () => {
            c.conditions.splice(j, 1);
            if (!c.conditions.length) delete c.conditions;
            emit();
            paint();
          });
        });
        row.querySelectorAll('[data-ei]').forEach((el) => {
          const j = Number(el.getAttribute('data-ei'));
          const kind = el.querySelector('[data-kind]');
          const sync = () => {
            const p = readPair(el);
            const id = kind ? kind.value : 'start_quest';
            c.effects[j] = fieldsToFx(id, p.a, p.b);
            emit();
          };
          if (kind) kind.addEventListener('change', () => {
            c.effects[j] = fieldsToFx(kind.value, '', '');
            emit();
            paint();
          });
          el.querySelectorAll('[data-a],[data-b]').forEach((inp) => {
            inp.addEventListener('input', sync);
            inp.addEventListener('change', sync);
          });
          const drop = el.querySelector('[data-drop-fx]');
          if (drop) drop.addEventListener('click', () => {
            c.effects.splice(j, 1);
            if (!c.effects.length) delete c.effects;
            emit();
            paint();
          });
        });
      });
    }

    function paintWalk() {
      const box = $('#st-talk-walkbox', host);
      if (!box) return;
      const key = state.walk;
      const node = key && state.doc.nodes[key];
      if (!node) {
        box.hidden = true;
        return;
      }
      box.hidden = false;
      const replies = (node.choices || []).map((c, i) => {
        const note = (c.effects || []).map(fxNote).filter(Boolean);
        return `<button type="button" class="btn" data-walk-c="${i}">${esc(c.label || '…')}
          ${note.length ? '<em class="st-walk-fx">' + esc(note.join(' · ')) + '</em>' : ''}</button>`;
      }).join('');
      box.innerHTML = `
        <p class="st-walk-line">${esc(node.text || '…')}</p>
        <div class="ce-actions">${replies}
          <button type="button" class="btn" data-walk-stop>Close</button>
        </div>`;
      box.querySelectorAll('[data-walk-c]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const c = node.choices[Number(btn.getAttribute('data-walk-c'))];
          if (!c || c.end || !c.next || !state.doc.nodes[c.next]) {
            box.hidden = true;
            state.walk = null;
            return;
          }
          state.walk = c.next;
          paintWalk();
        });
      });
      const stop = box.querySelector('[data-walk-stop]');
      if (stop) stop.addEventListener('click', () => {
        box.hidden = true;
        state.walk = null;
      });
    }

    paint();
    return {
      getDoc: () => state.doc,
      setLint(lint) {
        opts.lint = lint;
        paint();
      },
      destroy() { host.innerHTML = ''; },
    };
  }

  window.TalkCards = { mount, emptyDoc, normalize, fieldsToFx, fieldsToCond };
})();
