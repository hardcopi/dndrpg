/**
 * The content editors.
 *
 * Three lists and a detail pane: people (with their placement and their
 * conversation), quests (with their stages), and places — the world graph, one
 * location at a time, with the exits that join it to the rest.
 *
 * Every write goes to the server, which re-validates it against the rules
 * tools/load_content.py enforces on import — nothing here is trusted, and
 * nothing here duplicates those rules, because a second copy of a validation
 * rule is a rule that will disagree with the first one eventually.
 *
 * Dialogue is edited as JSON rather than through a node-graph interface. That
 * is a deliberate choice, not a shortcut: the documents are branching, carry
 * conditions and effects in a vocabulary of their own, and are already authored
 * as JSON by hand. A form that covered the common case would quietly refuse the
 * interesting ones. The textarea is checked for syntax as you type and the
 * server checks the structure — start node present, every choice landing on a
 * node that exists — so the two failures that actually break a conversation are
 * both caught.
 */
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  const state = { tab: 'npcs', npcs: [], quests: [], regions: [], refs: null, selected: null };

  const err = (e) => { $('#ce-error').textContent = e ? (e.message || String(e)) : ''; };

  /**
   * Everywhere in the world, as one select.
   *
   * Built from content/refs rather than from the grouped region list, so the
   * NPC's placement, a quest's marker and an exit's destination are all offered
   * in the same order — three screens sorting the same list differently is how
   * a place people swear they saw becomes one they cannot find.
   */
  const locationSelect = (attrs, selected, blank) => `
    <select ${attrs}>
      ${blank === null ? '' : `<option value="">${esc(blank)}</option>`}
      ${state.refs.locations.map((l) => `
        <option value="${l.id}"${Number(selected) === Number(l.id) ? ' selected' : ''}>
          ${esc(l.region_name)} — ${esc(l.name)}</option>`).join('')}
    </select>`;

  // =========================================================================
  // Shell
  // =========================================================================

  $$('.ce-tab').forEach((t) => t.addEventListener('click', () => {
    $$('.ce-tab').forEach((x) => x.classList.toggle('is-on', x === t));
    state.tab = t.dataset.tab;
    state.selected = null;
    $('#ce-detail').innerHTML = '<p class="help-hint">Choose something on the left.</p>';
    renderList();
  }));

  $('#btn-export').addEventListener('click', async (e) => {
    err(null);
    const btn = e.target;
    btn.disabled = true;
    const was = btn.textContent;
    btn.textContent = 'Exporting…';
    try {
      const r = await API.post('admin/export', {});
      const n = r.written.length;
      $('#export-result').textContent = n === 0
        ? 'Nothing changed — the files already match the database.'
        : `${n} file${n === 1 ? '' : 's'} written.`;
    } catch (ex) { err(ex); }
    btn.disabled = false;
    btn.textContent = was;
  });

  async function load() {
    err(null);
    try {
      const [npcs, quests, regions, refs] = await Promise.all([
        API.get('content/npcs'),
        API.get('content/quests'),
        API.get('content/regions'),
        API.get('content/refs'),
      ]);
      state.npcs = npcs.npcs;
      state.quests = quests.quests;
      state.regions = regions.regions;
      state.refs = refs;
      renderList();
    } catch (e) { err(e); }
  }

  function renderList() {
    const el = $('#ce-list');
    if (state.tab === 'npcs') {
      // The Read button is a SIBLING of the row, not a child of it: `.ce-row`
      // is itself a <button>, and a button inside a button is invalid markup
      // that browsers repair by unnesting — which would put Read outside the
      // row and leave the click landing wherever the repair moved it.
      el.innerHTML = state.npcs.map((n) => `
        <div class="ce-row-pair">
          <button type="button" class="ce-row${state.selected === n.id ? ' is-on' : ''}" data-npc="${n.id}">
            <span class="ce-row-name">${esc(n.name)}</span>
            <span class="ce-row-sub">
              ${esc(n.role || '—')}
              ${n.location_name ? ` · ${esc(n.location_name)}` : ' · nowhere'}
            </span>
            <span class="ce-row-tags">
              ${Number(n.has_dialogue) ? '<em>talks</em>' : ''}
              ${Number(n.is_merchant) ? '<em>shop</em>' : ''}
              ${Number(n.is_quest_giver) ? '<em>quests</em>' : ''}
              ${Number(n.is_ambient) ? '<em>ambient</em>' : ''}
            </span>
          </button>
          ${Number(n.has_dialogue) ? `
            <button type="button" class="ce-row-walk" data-walk="${n.id}"
                    title="Read ${esc(n.name)}'s conversation"
                    aria-label="Read ${esc(n.name)}'s conversation">Read</button>` : ''}
        </div>`).join('');
    } else if (state.tab === 'talk') {
      const talkers = state.npcs.filter((n) => Number(n.has_dialogue));
      el.innerHTML = talkers.length ? talkers.map((n) => `
        <button type="button" class="ce-row${state.selected === n.id ? ' is-on' : ''}" data-talk="${n.id}">
          <span class="ce-row-name">${esc(n.name)}</span>
          <span class="ce-row-sub">${esc(n.npc_key)}</span>
        </button>`).join('') : '<p class="help-hint">Nobody has a conversation yet.</p>';
    } else if (state.tab === 'quests') {
      el.innerHTML = state.quests.map((q) => `
        <button type="button" class="ce-row${state.selected === q.id ? ' is-on' : ''}" data-quest="${q.id}">
          <span class="ce-row-name">${esc(q.title)}</span>
          <span class="ce-row-sub">
            act ${q.act} · ${q.stages} stage${Number(q.stages) === 1 ? '' : 's'}
            ${Number(q.on_job_board) ? ' · job board' : ''}
          </span>
        </button>`).join('');
    } else {
      // Locations are listed under their region rather than in one flat run:
      // the key is global but the sense of a place is regional, and "which
      // town is this room in" is the first thing you need to know.
      // The module is named only when it changes, so a one-module install
      // never sees it and a two-module one gets a heading where the second
      // game starts. Regions arrive grouped by module, so a plain comparison
      // against the previous row is enough.
      let lastModule = null;
      el.innerHTML = state.regions.map((r) => {
        const heading = r.module_key && r.module_key !== lastModule && state.regions.some(
          (o) => o.module_key && o.module_key !== r.module_key
        ) ? `<p class="ce-module">${esc(r.module_name || r.module_key)}</p>` : '';
        lastModule = r.module_key;
        return `
        ${heading}
        <p class="ce-sub">${esc(r.name)} · ${esc(r.region_type)}</p>
        ${r.locations.map((l) => `
          <button type="button" class="ce-row${state.selected === l.id ? ' is-on' : ''}" data-loc="${l.id}">
            <span class="ce-row-name">${esc(l.name)}</span>
            <span class="ce-row-sub">
              ${esc(l.location_type)}
              · ${l.exits} exit${Number(l.exits) === 1 ? '' : 's'}
              · ${l.npcs} here
            </span>
            <span class="ce-row-tags">
              ${l.inn_cost !== null ? '<em>inn</em>' : ''}
              ${Number(l.allow_camp) ? '<em>camp</em>' : ''}
              ${Number(l.hidden_until_visited) ? '<em>hidden</em>' : ''}
            </span>
          </button>`).join('')}
        <div class="ce-actions">
          <button type="button" class="btn btn-small" data-new-loc="${r.id}">New location</button>
        </div>`;
      }).join('');
    }
  }

  $('#ce-list').addEventListener('click', (e) => {
    // Read sits beside the row rather than inside it, so it cannot be reached
    // by the row's own handler — but it returns anyway, because opening the
    // reader is the whole of what it does and selecting the person underneath
    // would move the list out from under the modal.
    const w = e.target.closest('[data-walk]');
    if (w) { walkFrom(Number(w.dataset.walk)); return; }

    const n = e.target.closest('[data-npc]');
    const q = e.target.closest('[data-quest]');
    const l = e.target.closest('[data-loc]');
    const nl = e.target.closest('[data-new-loc]');
    if (n) { state.selected = Number(n.dataset.npc); renderList(); openNpc(state.selected); }
    if (q) { state.selected = Number(q.dataset.quest); renderList(); openQuest(state.selected); }
    if (l) { state.selected = Number(l.dataset.loc); renderList(); openLocation(state.selected); }
    if (nl) { state.selected = null; renderList(); openLocation(null, Number(nl.dataset.newLoc)); }
    const t = e.target.closest('[data-talk]');
    if (t) { state.selected = Number(t.dataset.talk); renderList(); openTree(state.selected); }
  });

  // =========================================================================
  // People
  // =========================================================================

  async function openNpc(id) {
    err(null);
    let npc;
    try { npc = (await API.get('content/npc', { id })).npc; } catch (e) { return err(e); }

    $('#ce-detail').innerHTML = `
      <h2 class="ce-title">${esc(npc.name)} <code>${esc(npc.npc_key)}</code></h2>

      <div class="ce-grid">
        <label>Name<input type="text" id="f-name" value="${esc(npc.name)}"></label>
        <label>Role<input type="text" id="f-role" value="${esc(npc.role || '')}"></label>
      </div>
      <label>Description<textarea id="f-desc" rows="3">${esc(npc.description || '')}</textarea></label>

      <div class="ce-grid">
        <label>Sprite key<input type="text" id="f-sprite" value="${esc(npc.sprite_key || '')}"></label>
        <label>Pose
          <select id="f-pose">
            <option value="">none (idles)</option>
            ${state.refs.poses.map((p) => `
              <option value="${p}"${npc.pose === p ? ' selected' : ''}>${p}</option>`).join('')}
          </select>
        </label>
      </div>

      <div class="ce-checks">
        <label class="ce-check"><input type="checkbox" id="f-merchant" ${Number(npc.is_merchant) ? 'checked' : ''}> Merchant</label>
        <label class="ce-check"><input type="checkbox" id="f-giver" ${Number(npc.is_quest_giver) ? 'checked' : ''}> Gives quests</label>
        <label class="ce-check"><input type="checkbox" id="f-ambient" ${Number(npc.is_ambient) ? 'checked' : ''}> Ambient
          <span class="ce-hint">background townsfolk — walking onto them does not open a conversation</span>
        </label>
      </div>

      <div class="ce-actions"><button type="button" class="btn btn-primary" id="btn-save-npc">Save</button></div>

      <h3 class="ce-sub">Where they stand</h3>
      <label>Location${locationSelect('id="f-location"', npc.location_id, 'nowhere — not in the world')}</label>
      <p class="ce-hint">A location is a scene, not a square: any number of people may share one.</p>
      <div class="ce-actions"><button type="button" class="btn" id="btn-place">Move</button></div>

      <h3 class="ce-sub">Conversation</h3>
      <p class="ce-hint" id="dlg-status">Loading…</p>
      <textarea id="f-dialogue" class="ce-code" rows="18" spellcheck="false"></textarea>
      <div class="ce-actions">
        <span class="ce-hint" id="dlg-syntax"></span>
        <button type="button" class="btn" id="btn-clear-dlg">Remove</button>
        <button type="button" class="btn btn-primary" id="btn-save-dlg">Save conversation</button>
      </div>`;

    $('#btn-save-npc').addEventListener('click', async () => {
      err(null);
      try {
        await API.post('content/save_npc', {
          id,
          name: $('#f-name').value,
          role: $('#f-role').value,
          description: $('#f-desc').value,
          sprite_key: $('#f-sprite').value,
          pose: $('#f-pose').value,
          is_merchant: $('#f-merchant').checked,
          is_quest_giver: $('#f-giver').checked,
          is_ambient: $('#f-ambient').checked,
        });
        await refreshNpcs(id);
      } catch (e) { err(e); }
    });

    $('#btn-place').addEventListener('click', async () => {
      err(null);
      const where = $('#f-location').value;
      try {
        await API.post('content/place_npc', { id, location_id: where === '' ? null : Number(where) });
        await refreshNpcs(id);
      } catch (e) { err(e); }
    });

    loadDialogue(id);
  }

  async function refreshNpcs(keepId) {
    state.npcs = (await API.get('content/npcs')).npcs;
    renderList();
    if (keepId) openNpc(keepId);
  }

  async function loadDialogue(id) {
    const box = $('#f-dialogue');
    const status = $('#dlg-status');
    try {
      const d = (await API.get('content/dialogue', { id })).dialogue;
      if (d.dialogue) {
        box.value = JSON.stringify(d.dialogue, null, 2);
        status.textContent = `${Object.keys(d.dialogue.nodes || {}).length} nodes, starting at "${d.dialogue.start}".`;
      } else {
        box.value = '';
        status.textContent = 'This person has nothing to say yet.';
        box.placeholder = JSON.stringify(
          { start: 'hail', nodes: { hail: { text: 'Hello.', choices: [{ label: 'Leave.', end: true }] } } },
          null, 2,
        );
      }
    } catch (e) { err(e); }

    // Syntax is checked here so a typo is caught before a round trip; the
    // server still checks the structure, which is the part that matters.
    const syntax = $('#dlg-syntax');
    const check = () => {
      const raw = box.value.trim();
      if (raw === '') { syntax.textContent = ''; syntax.className = 'ce-hint'; return; }
      try { JSON.parse(raw); syntax.textContent = 'valid JSON'; syntax.className = 'ce-hint is-ok'; }
      catch (e) { syntax.textContent = e.message; syntax.className = 'ce-hint is-bad'; }
    };
    box.addEventListener('input', check);
    check();

    $('#btn-save-dlg').addEventListener('click', async () => {
      err(null);
      const raw = box.value.trim();
      let doc;
      try { doc = raw === '' ? null : JSON.parse(raw); }
      catch (e) { return err(new Error('That is not valid JSON: ' + e.message)); }
      try {
        await API.post('content/save_dialogue', { id, dialogue: doc });
        await refreshNpcs(id);
      } catch (e) { err(e); }
    });

    $('#btn-clear-dlg').addEventListener('click', async () => {
      if (!confirm('Remove this conversation entirely?')) return;
      err(null);
      try {
        await API.post('content/save_dialogue', { id, dialogue: null });
        await refreshNpcs(id);
      } catch (e) { err(e); }
    });
  }

  // =========================================================================
  // Quests
  // =========================================================================

  async function openQuest(id) {
    err(null);
    let q;
    try { q = (await API.get('content/quest', { id })).quest; } catch (e) { return err(e); }

    $('#ce-detail').innerHTML = `
      <h2 class="ce-title">${esc(q.title)} <code>${esc(q.quest_key)}</code></h2>

      <label>Title<input type="text" id="q-title" value="${esc(q.title)}"></label>
      <label>Description<textarea id="q-desc" rows="3">${esc(q.description || '')}</textarea></label>
      <div class="ce-grid ce-grid-3">
        <label>Act<input type="number" id="q-act" value="${q.act}" min="1" max="5"></label>
        <label>Required level<input type="number" id="q-level" value="${q.required_level}" min="1"></label>
        <label>Giver<input type="text" value="${esc(q.giver_key || '—')}" disabled></label>
      </div>
      <div class="ce-checks">
        <label class="ce-check"><input type="checkbox" id="q-board" ${Number(q.on_job_board) ? 'checked' : ''}> On the job board</label>
        <label class="ce-check"><input type="checkbox" id="q-active" ${Number(q.is_active) ? 'checked' : ''}> Available</label>
      </div>
      <div class="ce-actions"><button type="button" class="btn btn-primary" id="btn-save-quest">Save</button></div>

      <h3 class="ce-sub">Stages</h3>
      <div class="ce-stages">
        ${q.stages.map((s) => `
          <details class="ce-stage"${Number(s.is_terminal) ? ' open' : ''}>
            <summary>
              <strong>${esc(s.title)}</strong>
              <code>${esc(s.stage_key)}</code>
              ${Number(s.is_terminal) ? `<em class="ce-terminal">ends the quest (${esc(s.outcome)})</em>` : ''}
            </summary>
            <div class="ce-stage-body" data-stage="${s.id}">
              <label>Title<input type="text" data-f="title" value="${esc(s.title)}"></label>
              <label>Objective<textarea data-f="objective" rows="2">${esc(s.objective || '')}</textarea></label>
              <label>Journal entry<textarea data-f="journal_entry" rows="3">${esc(s.journal_entry || '')}</textarea></label>
              <label>Marker${locationSelect('data-f="target_location_id"', s.target_location_id, 'no marker')}</label>
              <p class="ce-hint">Where the tracker points while this stage is current; it routes there over the exit graph.</p>
              <div class="ce-grid ce-grid-3">
                <label class="ce-check"><input type="checkbox" data-f="is_terminal" ${Number(s.is_terminal) ? 'checked' : ''}> Ends the quest</label>
                <label>Resolution<input type="text" data-f="resolution" value="${esc(s.resolution || '')}"></label>
                <label>Outcome
                  <select data-f="outcome">
                    ${['success', 'failure', 'neutral'].map((o) => `
                      <option value="${o}"${s.outcome === o ? ' selected' : ''}>${o}</option>`).join('')}
                  </select>
                </label>
              </div>
              <div class="ce-actions"><button type="button" class="btn btn-small" data-save-stage="${s.id}">Save stage</button></div>
            </div>
          </details>`).join('')}
      </div>`;

    $('#btn-save-quest').addEventListener('click', async () => {
      err(null);
      try {
        await API.post('content/save_quest', {
          id,
          title: $('#q-title').value,
          description: $('#q-desc').value,
          act: Number($('#q-act').value),
          required_level: Number($('#q-level').value),
          on_job_board: $('#q-board').checked,
          is_active: $('#q-active').checked,
        });
        state.quests = (await API.get('content/quests')).quests;
        renderList();
        openQuest(id);
      } catch (e) { err(e); }
    });

    $$('[data-save-stage]').forEach((btn) => btn.addEventListener('click', async () => {
      err(null);
      const body = $(`[data-stage="${btn.dataset.saveStage}"]`);
      const val = (f) => {
        const el = $(`[data-f="${f}"]`, body);
        return el.type === 'checkbox' ? el.checked : el.value;
      };
      try {
        await API.post('content/save_stage', {
          id: Number(btn.dataset.saveStage),
          title: val('title'),
          objective: val('objective'),
          journal_entry: val('journal_entry'),
          is_terminal: val('is_terminal'),
          resolution: val('resolution'),
          outcome: val('outcome'),
          target_location_id: val('target_location_id'),
        });
        openQuest(id);
      } catch (e) { err(e); }
    }));
  }

  // =========================================================================
  // Places
  // =========================================================================

  /**
   * One location: its prose, its dot on the region map, and the ways out.
   *
   * Called with an id to edit, or with a region id and no id to author a new
   * one. Exits are saved a row at a time rather than with the location, because
   * an exit belongs to the pair of places it joins and half of them are
   * authored from the other end.
   */
  async function openLocation(id, regionId) {
    err(null);
    let loc;
    if (id) {
      try { loc = (await API.get('content/location', { id })).location; } catch (e) { return err(e); }
    } else {
      loc = {
        id: null, region_id: regionId, location_key: '', name: '', location_type: 'site',
        description: '', first_visit_text: '', ambience_json: null,
        map_x: 50, map_y: 50, inn_cost: null, allow_camp: 0,
        random_encounter_pct: 0, hidden_until_visited: 0,
        exits: [], npcs: [],
      };
    }

    let ambience = [];
    try { ambience = JSON.parse(loc.ambience_json || '[]') || []; } catch (e) { ambience = []; }

    $('#ce-detail').innerHTML = `
      <h2 class="ce-title">
        ${esc(loc.name || 'A new place')} <code>${esc(loc.location_key)}</code>
      </h2>

      <div class="ce-grid">
        <label>Name<input type="text" id="l-name" value="${esc(loc.name)}"></label>
        <label>Key<input type="text" id="l-key" value="${esc(loc.location_key)}"></label>
      </div>
      <div class="ce-grid">
        <label>Region
          <select id="l-region">
            ${state.regions.map((r) => `
              <option value="${r.id}"${Number(loc.region_id) === Number(r.id) ? ' selected' : ''}>${esc(r.name)}</option>`).join('')}
          </select>
        </label>
        <label>Type
          <select id="l-type">
            ${state.refs.location_types.map((t) => `
              <option value="${t}"${loc.location_type === t ? ' selected' : ''}>${t}</option>`).join('')}
          </select>
        </label>
      </div>

      <label>Description<textarea id="l-desc" rows="6">${esc(loc.description)}</textarea></label>
      <label>First visit<textarea id="l-first" rows="3">${esc(loc.first_visit_text || '')}</textarea></label>
      <p class="ce-hint">Shown once, above the description, the first time a party arrives.</p>
      <label>Ambience<textarea id="l-amb" rows="5">${esc(ambience.join('\n'))}</textarea></label>
      <p class="ce-hint">One line per entry. One is picked each time the scene is drawn.</p>

      <div class="ce-grid ce-grid-3">
        <label>Map x<input type="number" id="l-x" value="${loc.map_x}" min="0" max="100" step="0.01"></label>
        <label>Map y<input type="number" id="l-y" value="${loc.map_y}" min="0" max="100" step="0.01"></label>
        <label>Inn cost<input type="number" id="l-inn" value="${loc.inn_cost ?? ''}" min="0"></label>
      </div>
      <p class="ce-hint">x and y are percent positions on the region's map drawing. An inn cost offers a paid rest here; leave it empty for nowhere to sleep.</p>

      <div class="ce-grid ce-grid-3">
        <label>Random encounter %<input type="number" id="l-pct" value="${loc.random_encounter_pct}" min="0" max="100"></label>
        <label class="ce-check"><input type="checkbox" id="l-camp" ${Number(loc.allow_camp) ? 'checked' : ''}> Camp allowed</label>
        <label class="ce-check"><input type="checkbox" id="l-hidden" ${Number(loc.hidden_until_visited) ? 'checked' : ''}> Hidden until visited</label>
      </div>

      <div class="ce-actions">
        ${loc.id ? '<button type="button" class="btn" id="btn-del-loc">Delete</button>' : ''}
        <button type="button" class="btn btn-primary" id="btn-save-loc">Save</button>
      </div>

      <h3 class="ce-sub">Ways out</h3>
      ${loc.id ? `
        ${loc.exits.map((x) => exitRow(x)).join('')}
        ${exitRow(null)}
        <p class="ce-hint">Exits are one-way. A passage somebody can walk back down is two of them, one authored from each end.</p>
      ` : '<p class="ce-hint">Save this place first — an exit needs somewhere to leave from.</p>'}

      <h3 class="ce-sub">People here</h3>
      ${loc.id && loc.npcs.length ? `
        <ul>
          ${loc.npcs.map((n) => `
            <li>${esc(n.name)}${n.role ? ` — ${esc(n.role)}` : ''}${Number(n.is_ambient) ? ' <em>(ambient)</em>' : ''}</li>`).join('')}
        </ul>
        <p class="ce-hint">Placement is edited on the People tab, where the person is.</p>
      ` : '<p class="ce-hint">Nobody stands here.</p>'}`;

    $('#btn-save-loc').addEventListener('click', async () => {
      err(null);
      const inn = $('#l-inn').value.trim();
      try {
        const saved = (await API.post('content/save_location', {
          id: loc.id,
          location_key: $('#l-key').value.trim(),
          region_id: Number($('#l-region').value),
          name: $('#l-name').value,
          location_type: $('#l-type').value,
          description: $('#l-desc').value,
          first_visit_text: $('#l-first').value,
          ambience: $('#l-amb').value.split('\n').map((s) => s.trim()).filter(Boolean),
          map_x: $('#l-x').value,
          map_y: $('#l-y').value,
          inn_cost: inn === '' ? null : Number(inn),
          allow_camp: $('#l-camp').checked,
          random_encounter_pct: Number($('#l-pct').value),
          hidden_until_visited: $('#l-hidden').checked,
        })).location;
        await refreshPlaces(Number(saved.id));
      } catch (e) { err(e); }
    });

    if (loc.id) {
      $('#btn-del-loc').addEventListener('click', async () => {
        if (!confirm(`Remove ${loc.name} and its exits?`)) return;
        err(null);
        try {
          await API.post('content/delete_location', { id: loc.id });
          await refreshPlaces(null);
        } catch (e) { err(e); }
      });

      $$('[data-save-exit]').forEach((btn) => btn.addEventListener('click', async () => {
        err(null);
        const row = $(`[data-exit="${btn.dataset.saveExit}"]`);
        const body = {
          from_location_id: loc.id,
          to_location_id: Number($('[data-f="to_location_id"]', row).value),
          label: $('[data-f="label"]', row).value,
          is_hidden: $('[data-f="is_hidden"]', row).checked,
        };
        if (btn.dataset.saveExit !== 'new') {
          body.id = Number(btn.dataset.saveExit);
        }
        try {
          await API.post('content/save_exit', body);
          await refreshPlaces(loc.id);
        } catch (e) { err(e); }
      }));

      $$('[data-del-exit]').forEach((btn) => btn.addEventListener('click', async () => {
        err(null);
        try {
          await API.post('content/delete_exit', { id: Number(btn.dataset.delExit) });
          await refreshPlaces(loc.id);
        } catch (e) { err(e); }
      }));
    }
  }

  /** One exit, or the empty row that adds one. */
  function exitRow(exit) {
    const id = exit ? exit.id : 'new';
    return `
      <div class="ce-stage" data-exit="${id}">
        <div class="ce-grid ce-grid-3">
          <label>Label<input type="text" data-f="label" value="${esc(exit ? exit.label : '')}"
            placeholder="${exit ? '' : 'Down the High Street'}"></label>
          <label>Leads to${locationSelect('data-f="to_location_id"', exit ? exit.to_location_id : null, exit ? null : 'choose a place')}</label>
          <label class="ce-check"><input type="checkbox" data-f="is_hidden" ${exit && Number(exit.is_hidden) ? 'checked' : ''}> Hidden until found</label>
        </div>
        <div class="ce-actions">
          ${exit ? `<button type="button" class="btn btn-small" data-del-exit="${exit.id}">Delete</button>` : ''}
          <button type="button" class="btn btn-small" data-save-exit="${id}">${exit ? 'Save exit' : 'Add exit'}</button>
        </div>
      </div>`;
  }

  /**
   * Re-read the world after a write.
   *
   * refs goes with it: a location that has just been created has to appear in
   * every destination select before somebody tries to point an exit at it.
   */
  async function refreshPlaces(keepId) {
    const [regions, refs] = await Promise.all([
      API.get('content/regions'),
      API.get('content/refs'),
    ]);
    state.regions = regions.regions;
    state.refs = refs;
    state.selected = keepId || null;
    renderList();
    if (keepId) {
      openLocation(keepId);
    } else {
      $('#ce-detail').innerHTML = '<p class="help-hint">Choose something on the left.</p>';
    }
  }

  // =========================================================================
  // Conversations
  // =========================================================================

  /**
   * One NPC's dialogue as a flowchart, with everything wrong with it listed.
   *
   * The document itself is still edited as JSON — on the People tab, where it
   * has always been. This view is for the fault the textarea cannot show: shape.
   * A node nothing points at, a link to a node that is not there, a variant
   * ordered above the one that should have won. Selecting a box opens it for
   * editing on the right; the shape stays on the left where it can be seen.
   */
  const tree = { data: null, npcId: null, doc: null, sel: null, ctl: null };

  /**
   * Fetch one conversation and everything known about it.
   *
   * Separate from `openTree` because the chart is no longer the only thing that
   * needs it: the People tab opens the reader straight from a row, without ever
   * drawing a graph. Both want the same two calls in the same order and neither
   * should be the place the other borrows them from.
   */
  async function loadTree(id) {
    tree.npcId = id;
    tree.data = (await API.get('content/lint_dialogue', { id })).lint;
    tree.doc = (await API.get('content/dialogue', { id })).dialogue.dialogue;
    tree.sel = null;
  }

  async function openTree(id) {
    err(null);
    try { await loadTree(id); } catch (e) { return err(e); }
    drawTree();
  }

  /**
   * Read somebody's conversation without going to the chart for it.
   *
   * Opens at the first way in — `start` normally, and whatever `roots` puts
   * first when a tree is entered from outside. Wanting to read what an NPC says
   * is a more common errand than wanting to inspect the shape of it, and it was
   * two tabs and a click on the right box away.
   */
  async function walkFrom(id) {
    err(null);
    try { await loadTree(id); } catch (e) { return err(e); }
    const root = (tree.data.roots || [])[0];
    if (!root || !tree.doc) {
      return err(new Error(`${tree.data.name || 'That person'} has no conversation to read.`));
    }
    openWalk(root);
  }

  function findingRow(f) {
    const m = /^\$\.nodes\.([a-z0-9_]+)/.exec(f.where || '');
    const key = m ? m[1] : '';
    return `<div class="dg-finding is-${esc(f.level)}">
      <code${key ? ` data-goto="${esc(key)}"` : ''}>${esc(f.where)}</code>
      <span>${esc(f.message)}</span>
    </div>`;
  }

  function drawTree() {
    const d = tree.data;
    const errors = (d.findings || []).filter((f) => f.level === 'error').length;
    const warns = (d.findings || []).length - errors;

    $('#ce-detail').innerHTML = `
      <h2 class="ce-title">${esc(d.name || d.npc_key)}</h2>
      <p class="ce-sub">${esc(d.npc_key)} · ${d.nodes.length} nodes ·
        ${d.edges.length} links · enters at ${esc((d.roots || []).join(', ') || '—')}</p>

      <div class="dg-frame" id="dg-frame">
        ${window.DialogGraph ? window.DialogGraph.svg(d) : '<p class="help-hint">Graph unavailable.</p>'}
        <div class="dg-zoom">
          <button type="button" class="icon-btn icon-btn-sm" data-zoom="out" title="Zoom out">&minus;</button>
          <button type="button" class="icon-btn icon-btn-sm" data-zoom="reset" title="Fit">&#9633;</button>
          <button type="button" class="icon-btn icon-btn-sm" data-zoom="in" title="Zoom in">+</button>
        </div>
      </div>

      <h3 class="ce-sub">${errors} error${errors === 1 ? '' : 's'}, ${warns} warning${warns === 1 ? '' : 's'}</h3>
      <div class="dg-findings">
        ${(d.findings || []).length
          ? d.findings.map(findingRow).join('')
          : '<p class="dg-clean">Nothing wrong with this conversation.</p>'}
      </div>

      <div id="dg-node"></div>`;

    tree.ctl = window.DialogGraph ? window.DialogGraph.controls($('#dg-frame')) : null;
    $$('[data-zoom]', $('#ce-detail')).forEach((b) => b.addEventListener('click', () => {
      if (!tree.ctl) return;
      if (b.dataset.zoom === 'in') tree.ctl.zoomIn();
      if (b.dataset.zoom === 'out') tree.ctl.zoomOut();
      if (b.dataset.zoom === 'reset') tree.ctl.reset();
    }));
    $$('[data-goto]', $('#ce-detail')).forEach((c) => c.addEventListener('click', () => {
      selectNode(c.dataset.goto);
    }));
    if (tree.sel) selectNode(tree.sel, true);
  }

  // The chart is replaced wholesale on every draw, so the node click is
  // delegated to the frame rather than bound per box.
  //
  // A box opens the conversation to be READ, not to be edited. The chart
  // answers "what shape is this" and the inspector answers "what does this node
  // say", and neither answers the question an author actually arrives with,
  // which is "what is it like to have this conversation". The findings list
  // still selects for editing — you click a finding to go and fix it — so the
  // two ways in stay distinct: click the picture to walk it, click a fault to
  // repair it.
  $('#ce-detail').addEventListener('click', (e) => {
    const g = e.target.closest('[data-dg-node]');
    if (g) openWalk(g.dataset.dgNode);
  });
  $('#ce-detail').addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const g = e.target.closest && e.target.closest('[data-dg-node]');
    if (!g) return;
    e.preventDefault();
    openWalk(g.dataset.dgNode);
  });

  function selectNode(key, quiet) {
    tree.sel = key;
    $$('.dg-node', $('#ce-detail')).forEach((g) => {
      g.classList.toggle('is-on', g.dataset.dgNode === key);
    });
    if (!quiet && tree.ctl) tree.ctl.focus(key);
    renderNodeEditor(key);
  }

  /**
   * The node inspector.
   *
   * Text and the links are edited as fields, because retargeting a reply is the
   * commonest edit and a dropdown of real node keys cannot produce a dangling
   * link. Conditions, effects and checks stay as JSON: they are open-ended
   * vocabularies, and a form that covered the common case would quietly refuse
   * the interesting ones — which is the argument the JSON textarea was built on
   * and it has not stopped being true.
   */
  function renderNodeEditor(key) {
    const host = $('#dg-node');
    if (!host) return;
    const raw = tree.doc && tree.doc.nodes ? tree.doc.nodes[key] : null;
    if (!raw) { host.innerHTML = ''; return; }
    const variants = Array.isArray(raw) ? raw : [raw];
    const keys = Object.keys(tree.doc.nodes);

    const choiceRow = (c, vi, ci) => {
      const target = c.next || '';
      return `<div class="ce-stage">
        <div class="ce-grid">
          <label>Reply
            <input type="text" data-c="label" data-v="${vi}" data-i="${ci}"
                   value="${esc(c.label || '')}">
          </label>
          <label>Goes to
            <select data-c="next" data-v="${vi}" data-i="${ci}">
              <option value="">— ends, or effects only —</option>
              ${keys.map((k) => `<option value="${esc(k)}"${k === target ? ' selected' : ''}>${esc(k)}</option>`).join('')}
            </select>
          </label>
        </div>
        ${c.check ? `<p class="ce-hint">d20 ${esc(c.check.skill || '')} DC ${esc(c.check.dc)} →
           ${esc(c.check.on_success)} / ${esc(c.check.on_failure)}</p>` : ''}
        ${c.effects ? `<p class="ce-hint">effects: <code>${esc(JSON.stringify(c.effects))}</code></p>` : ''}
      </div>`;
    };

    host.innerHTML = `
      <h3 class="ce-sub">Node <code>${esc(key)}</code></h3>
      ${variants.map((v, vi) => `
        <div class="ce-stage">
          <p class="ce-hint">variant ${vi + 1} of ${variants.length}
            ${v.pool ? ` · pool <code>${esc(v.pool)}</code>` : ''}
            ${v.once ? ' · once' : ''}
            ${v.conditions ? ` · <code>${esc(JSON.stringify(v.conditions))}</code>` : ' · fallback'}
          </p>
          <label>Text
            <textarea rows="3" data-t="text" data-v="${vi}">${esc(v.text || '')}</textarea>
          </label>
          ${(v.choices || []).map((c, ci) => choiceRow(c, vi, ci)).join('')}
        </div>`).join('')}
      <div class="ce-actions">
        <button type="button" class="btn btn-primary" id="dg-save">Save conversation</button>
        <span class="ce-hint" id="dg-saved"></span>
      </div>`;

    $('#dg-save').addEventListener('click', () => saveTree(key));
  }

  // =========================================================================
  // Walking a conversation
  // =========================================================================

  /**
   * The conversation, played rather than inspected.
   *
   * The chart shows where a conversation can go and the inspector shows what
   * one node holds. Neither shows what it is LIKE, and that is the thing an
   * author is actually trying to judge — whether a reply follows from the line
   * above it, whether a branch that reads well on its own reads at all after
   * the three nodes that reach it. The text is the content here: 293 characters
   * in the median variant, and the chart renders 30 of them.
   *
   * So: the line in full, the replies as buttons, and a way back. Walking two
   * hops and returning is the commonest thing anybody wants to do with a
   * dialogue tree and it was the one thing neither view could do.
   *
   * This is a READER. It resolves nothing — no conditions are evaluated, no
   * effects applied, no `once` spent, no pool advanced. It cannot: the party
   * state that decides all of those lives in the database and belongs to a
   * playthrough, not to an editor. What it shows instead is the document, so
   * every variant is reachable from the picker and every branch is walkable
   * whether or not a party could get there. Pretending otherwise would need a
   * second copy of `DialogEngine::matchVariant`, which is the duplication this
   * codebase keeps refusing, and would make the reader lie in exactly the cases
   * — the 41-, 46-, 49- and 53-variant greetings — where it is most useful.
   */
  const walk = { key: null, vi: 0, stack: [], el: null };

  /** A condition or effect object as something readable. */
  function describeOne(c) {
    if (c === null || typeof c !== 'object') return String(c);
    if (c.not) return 'not ' + describeOne(c.not);
    if (Array.isArray(c.any)) return '(' + c.any.map(describeOne).join(' or ') + ')';
    return Object.keys(c).map((k) => {
      const v = c[k];
      // A nested object is described, not stringified: `quest_stage` carries
      // {quest, stage} and the braces are noise in a line meant to be read.
      return (v === true) ? k
        : (v && typeof v === 'object') ? `${k} ${describeOne(v)}`
          : `${k} ${v}`;
    }).join(' ');
  }

  const describeList = (list) => (Array.isArray(list) && list.length)
    ? list.map(describeOne).join(' · ')
    : '';

  /** Authored prose keeps its paragraphs — it is written with them. */
  const asProse = (s) => String(s ?? '')
    .split(/\n{2,}/)
    .map((p) => `<p>${esc(p).replace(/\n/g, '<br>')}</p>`)
    .join('');

  const walkVariants = (key) => {
    const raw = tree.doc && tree.doc.nodes ? tree.doc.nodes[key] : null;
    if (!raw) return null;
    return Array.isArray(raw) ? raw : [raw];
  };

  /**
   * Where one reply can land.
   *
   * A choice is not always one button. A skill check forks into two before the
   * player has done anything, and both branches are authored — showing only one
   * would hide half the writing behind a die roll this reader does not throw.
   */
  function choiceExits(c) {
    const out = [];
    if (c.next) out.push({ to: c.next, label: 'Then' });
    if (c.check) {
      if (c.check.on_success) out.push({ to: c.check.on_success, label: 'Succeeds' });
      if (c.check.on_failure) out.push({ to: c.check.on_failure, label: 'Fails' });
    }
    return out;
  }

  const checkHead = (c) => (c.check
    ? `d20 ${c.check.skill || 'check'} DC ${c.check.dc}${c.check.retry ? ' · may retry' : ''}`
    : '');

  function openWalk(key, vi) {
    if (!tree.doc || !walkVariants(key)) return;
    walk.key = key;
    walk.vi = vi || 0;
    walk.stack = [];
    mountWalk();
    renderWalk();
  }

  function closeWalk() {
    if (walk.el) walk.el.classList.add('hidden');
    walk.key = null;
  }

  function walkGo(key, vi) {
    if (!walkVariants(key)) return;
    walk.stack.push({ key: walk.key, vi: walk.vi });
    walk.key = key;
    walk.vi = vi || 0;
    renderWalk();
  }

  function walkBack() {
    const prev = walk.stack.pop();
    if (!prev) return;
    walk.key = prev.key;
    walk.vi = prev.vi;
    renderWalk();
  }

  /**
   * The panel, built once and hidden between openings.
   *
   * Rebuilding it per node would drop focus on every hop and lose the scroll
   * position on a long line; only the body is redrawn.
   */
  function mountWalk() {
    if (walk.el) {
      walk.el.classList.remove('hidden');
      return;
    }
    const el = document.createElement('div');
    el.className = 'modal-backdrop';
    el.id = 'dw-back';
    el.innerHTML = `
      <div class="modal modal-walk" role="dialog" aria-modal="true" aria-labelledby="dw-title">
        <div class="modal-head">
          <img class="dw-face" id="dw-face" alt="" hidden>
          <h2 id="dw-title"></h2>
          <p class="dw-trail" id="dw-trail"></p>
          <button type="button" class="icon-btn modal-x" data-dw="close"
                  title="Close (Esc)">&times;</button>
        </div>
        <div class="dw-body" id="dw-body"></div>
        <div class="modal-actions dw-actions">
          <button type="button" class="btn" data-dw="back">&larr; Back</button>
          <button type="button" class="btn" data-dw="restart">Start again</button>
          <span class="ce-hint dw-keys">Esc closes · Backspace goes back · 1–9 pick a reply</span>
          <button type="button" class="btn btn-primary" data-dw="edit">Edit this node</button>
        </div>
      </div>`;
    document.body.appendChild(el);
    walk.el = el;

    el.addEventListener('click', (e) => {
      // The backdrop closes; the panel on top of it must not.
      if (e.target === el) return closeWalk();
      const act = e.target.closest('[data-dw]');
      if (act) {
        const a = act.dataset.dw;
        if (a === 'close') closeWalk();
        if (a === 'back') walkBack();
        if (a === 'restart') {
          const root = (tree.data.roots || [])[0];
          if (root) { walk.stack = []; walk.key = root; walk.vi = 0; renderWalk(); }
        }
        if (a === 'edit') {
          const k = walk.key;
          const id = tree.npcId;
          closeWalk();
          // Opened from the chart: the inspector is already on screen under it.
          if ($('#dg-node')) { selectNode(k); return; }
          // Opened from a People row: there is no chart and no inspector to
          // render into, so Edit would quietly do nothing. Go and get one.
          const tab = $('.ce-tab[data-tab="talk"]');
          if (tab) tab.click();
          state.selected = id;
          renderList();
          openTree(id).then(() => selectNode(k));
          return;
        }
        return;
      }
      const go = e.target.closest('[data-dw-to]');
      if (go) walkGo(go.dataset.dwTo, 0);
    });

    el.addEventListener('change', (e) => {
      const sel = e.target.closest('[data-dw-variant]');
      if (!sel) return;
      walk.vi = Number(sel.value) || 0;
      renderWalk();
    });

    document.addEventListener('keydown', (e) => {
      if (!walk.key || !walk.el || walk.el.classList.contains('hidden')) return;
      if (e.key === 'Escape') { e.preventDefault(); return closeWalk(); }
      if (e.key === 'Backspace') { e.preventDefault(); return walkBack(); }
      if (/^[1-9]$/.test(e.key)) {
        const btns = $$('[data-dw-to]', walk.el);
        const b = btns[Number(e.key) - 1];
        if (b) { e.preventDefault(); walkGo(b.dataset.dwTo, 0); }
      }
    });
  }

  function renderWalk() {
    const variants = walkVariants(walk.key);
    if (!variants) return;
    const vi = Math.min(walk.vi, variants.length - 1);
    const v = variants[vi] || {};

    $('#dw-title').textContent = tree.data.name || tree.data.npc_key || 'Conversation';

    const face = $('#dw-face');
    face.hidden = !tree.data.face;
    if (tree.data.face && face.getAttribute('src') !== tree.data.face) {
      face.setAttribute('src', tree.data.face);
    }

    // The route taken, not the route available — this is the one view that
    // knows how the reader arrived, and on a tree with a hub the difference
    // between "reached from the greeting" and "reached from camp" is most of
    // what a line has to make sense against.
    const trail = walk.stack.map((s) => s.key).concat([walk.key]);
    $('#dw-trail').innerHTML = trail
      .map((k, i) => `<span class="${i === trail.length - 1 ? 'is-here' : ''}">${esc(k)}</span>`)
      .join('<i>&rsaquo;</i>');

    const picker = variants.length > 1 ? `
      <label class="dw-pick">Variant
        <select data-dw-variant>
          ${variants.map((x, i) => {
            const c = describeList(x.conditions);
            const bits = [c || 'fallback — matches when nothing above does'];
            if (x.once) bits.push('once');
            if (x.pool) bits.push(`pool ${x.pool}`);
            return `<option value="${i}"${i === vi ? ' selected' : ''}>${i + 1}. ${esc(bits.join(' · '))}</option>`;
          }).join('')}
        </select>
        <span class="ce-hint">${variants.length} variants, tried top to bottom</span>
      </label>` : '';

    const conds = describeList(v.conditions);
    const chips = [
      conds ? `<span class="dw-chip">when ${esc(conds)}</span>` : '',
      (!v.conditions && variants.length > 1) ? '<span class="dw-chip">fallback</span>' : '',
      v.once ? '<span class="dw-chip">once</span>' : '',
      v.pool ? `<span class="dw-chip">pool ${esc(v.pool)}</span>` : '',
      v.on_enter ? `<span class="dw-chip is-effect">on enter: ${esc(describeList(v.on_enter))}</span>` : '',
    ].filter(Boolean).join('');

    const interjections = (v.interjections || []).map((j) => `
      <div class="dw-aside">
        <strong>${esc(j.companion)}</strong>
        ${j.conditions ? `<span class="dw-chip">when ${esc(describeList(j.conditions))}</span>` : ''}
        ${asProse(j.text)}
      </div>`).join('');

    let n = 0;
    const choices = (v.choices || []).map((c) => {
      const exits = choiceExits(c);
      const meta = [
        c.conditions ? `<span class="dw-chip">only if ${esc(describeList(c.conditions))}</span>` : '',
        c.tag ? `<span class="dw-chip is-tag">${esc(c.tag)}</span>` : '',
        c.effects ? `<span class="dw-chip is-effect">${esc(describeList(c.effects))}</span>` : '',
      ].filter(Boolean).join('');

      if (!exits.length) {
        // `end`, or a reply that only carries effects. Both stop here, and a
        // button that looks pressable but goes nowhere is worse than a line
        // that says so.
        return `<div class="dw-choice is-end">
          <span class="dw-label">${esc(c.label || '')}</span>
          <span class="dw-meta">${c.end ? 'ends the conversation' : 'no exit — effects only'} ${meta}</span>
        </div>`;
      }
      const button = (x, withLabel) => {
        n++;
        const missing = !walkVariants(x.to);
        return `<button type="button" class="dw-go${missing ? ' is-broken' : ''}"
                  ${missing ? 'disabled' : `data-dw-to="${esc(x.to)}"`}>
          <span class="dw-num">${n}</span>
          <span class="dw-label">${esc(withLabel ? (c.label || '') : x.label)}</span>
          <span class="dw-meta">${missing
            ? `no node &ldquo;${esc(x.to)}&rdquo;` : `&rarr; ${esc(x.to)}`} ${withLabel ? meta : ''}</span>
        </button>`;
      };

      // One exit is one button. A skill check is not: it is one thing the
      // player says with two things that can happen next, and printing the
      // reply twice — once per outcome — reads as two different replies. The
      // line is said once, and the die is what branches under it.
      if (exits.length === 1 && !c.check) {
        return `<div class="dw-choice">${button(exits[0], true)}</div>`;
      }
      return `<div class="dw-choice is-fork">
        <p class="dw-forkhead">
          <span class="dw-label">${esc(c.label || '')}</span>
          <span class="dw-meta">${esc(checkHead(c))} ${meta}</span>
        </p>
        ${exits.map((x) => button(x, false)).join('')}
      </div>`;
    }).join('');

    // The face the player would be looking at while this line is said. The
    // variant names an expression and the server has already resolved each one
    // to a file it checked exists, so this is an index and a fallback, not a
    // second opinion about which portrait a number means.
    const busts = tree.data.busts || [];
    const bust = busts[Math.max(1, Number(v.expression) || 1) - 1] || busts[0] || null;
    const portrait = bust ? `
      <figure class="dw-portrait">
        <img src="${esc(bust)}" alt="${esc(tree.data.name || '')}">
        ${busts.length > 1 ? `<figcaption>expression ${Number(v.expression) || 1} of ${busts.length}</figcaption>` : ''}
      </figure>` : '';

    $('#dw-body').innerHTML = `
      <div class="dw-scene${portrait ? '' : ' is-faceless'}">
        ${portrait}
        <div class="dw-said">
          <p class="dw-node"><code>${esc(walk.key)}</code>${
            (tree.data.roots || []).includes(walk.key) ? '<em>a way in</em>' : ''}</p>
          ${picker}
          <div class="dw-chips">${chips}</div>
          <div class="dw-text">${asProse(v.text) || '<p class="help-hint">No text.</p>'}</div>
          ${interjections}
          <div class="dw-choices">${choices || '<p class="help-hint">No replies — this is where it stops.</p>'}</div>
        </div>
      </div>`;

    $$('[data-dw="back"]', walk.el).forEach((b) => { b.disabled = !walk.stack.length; });
    walk.vi = vi;
    $('#dw-body').scrollTop = 0;
  }

  async function saveTree(key) {
    const raw = tree.doc.nodes[key];
    const variants = Array.isArray(raw) ? raw : [raw];
    $$('[data-t="text"]', $('#dg-node')).forEach((el) => {
      variants[Number(el.dataset.v)].text = el.value;
    });
    $$('[data-c]', $('#dg-node')).forEach((el) => {
      const v = variants[Number(el.dataset.v)];
      const c = (v.choices || [])[Number(el.dataset.i)];
      if (!c) return;
      if (el.dataset.c === 'label') c.label = el.value;
      if (el.dataset.c === 'next') {
        if (el.value) c.next = el.value; else delete c.next;
      }
    });
    err(null);
    try {
      await API.post('content/save_dialogue', { id: tree.npcId, dialogue: tree.doc });
      $('#dg-saved').textContent = 'Saved.';
      await openTree(tree.npcId);
      selectNode(key);
    } catch (e) { err(e); }
  }

  load();
})();
