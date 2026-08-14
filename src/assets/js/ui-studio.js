/**
 * The adventure studio.
 *
 * A shelf of modules and a three-pane desk for one of them. Writes go through
 * ContentEditor — this file draws what that class already knows how to save.
 * The map is WorldMap.svg or PlanDesk; talk is TalkCards; the run is inferred.
 */
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  const err = (e) => {
    const el = $('#st-error');
    if (el) el.textContent = e ? (e.message || String(e)) : '';
  };

  const state = {
    modules: [],
    module: null,
    chart: null,
    here: null,
    refs: { monsters: [], items: [] },
    beats: [],
    view: 'scene',
    talk: null,
    talkNpc: null,
  };

  async function boot() {
    err(null);
    try {
      state.modules = (await API.get('content/modules')).modules || [];
    } catch (e) {
      return err(e);
    }
    const key = window.STUDIO_MODULE || '';
    if (!key) {
      renderShelf();
      return;
    }
    const row = state.modules.find((m) => m.module_key === key);
    if (!row) {
      err(new Error('No adventure keyed ' + key + '.'));
      renderShelf();
      return;
    }
    state.module = row;
    try {
      const pack = await Promise.all([
        API.get('content/chart', { module_id: row.id }),
        API.get('content/refs'),
        API.get('content/run', { module_id: row.id }),
      ]);
      state.chart = pack[0].chart;
      state.refs = pack[1];
      state.beats = pack[2].beats || [];
      const hereId = (state.chart.nodes || []).find((n) => n.current)?.id;
      if (hereId) {
        state.here = (await API.get('content/location', { id: hereId })).location;
      }
    } catch (e) {
      return err(e);
    }
    renderDesk();
  }

  function renderShelf() {
    $('#st-title').textContent = 'Studio';
    $('#st-nav').innerHTML = `
      <a class="btn" href="content.php">Copy desk</a>
      <a class="btn" href="index.php">Shelf</a>`;

    const cards = state.modules.map((m) => {
      const key = encodeURIComponent(m.module_key);
      const draft = Number(m.is_active) === 1 ? '' : '<em class="st-draft">draft</em>';
      const cover = `assets/images/modules/${encodeURIComponent(m.module_key)}.jpg`;
      return `
        <section class="panel st-card">
          <img class="st-cover" src="${cover}" alt=""
            onerror="this.classList.add('is-missing');this.removeAttribute('src')">
          <h2>${esc(m.name)} ${draft}</h2>
          <p class="help-hint">Levels ${esc(m.level_min)}–${esc(m.level_max)}
            · ${esc(m.regions)} region${Number(m.regions) === 1 ? '' : 's'}</p>
          <p>${esc(m.blurb || '')}</p>
          <p class="st-card-actions">
            <a class="btn btn-primary" href="studio.php?module=${key}">Open</a>
            <a class="btn" href="create.php?module=${key}">Play</a>
            <a class="btn" href="adventure_print.php?module=${key}">Book</a>
          </p>
        </section>`;
    }).join('');

    $('#st-main').innerHTML = `
      <p class="help-hint">One card is one game. A draft stays off the public
        shelf until you turn it on. The copy desk is still there for the
        world that already exists.</p>
      <div class="home-grid st-shelf">${cards || '<p class="help-hint">No adventures yet.</p>'}</div>
      <section class="panel st-new">
        <h2>Import an adventure</h2>
        <p class="help-hint">A <code>.adventure.json</code> downloaded from another
          studio. Shared monsters and items must already be on this install.</p>
        <label class="st-upload">File
          <input type="file" id="n-import" accept="application/json,.json">
        </label>
      </section>
      <section class="panel st-new">
        <h2>New adventure</h2>
        <div class="ce-grid">
          <label>Name<input type="text" id="n-name" placeholder="The Hollow Stair"></label>
          <label>Key<input type="text" id="n-key" placeholder="hollow_stair"
            autocapitalize="none" spellcheck="false"></label>
        </div>
        <label>Blurb<textarea id="n-blurb" rows="3"
          placeholder="What the shelf card says under the name."></textarea></label>
        <div class="ce-grid">
          <label>Kind
            <select id="n-kind">
              <option value="dungeon">Dungeon (plan on graph paper)</option>
              <option value="town">Town (chart of places)</option>
              <option value="wilderness">Wilderness (chart of places)</option>
            </select>
          </label>
          <label>Levels
            <span class="st-levels">
              <input type="number" id="n-min" value="1" min="1" max="20">
              <span>–</span>
              <input type="number" id="n-max" value="4" min="1" max="20">
            </span>
          </label>
        </div>
        <div class="ce-actions">
          <button type="button" class="btn btn-primary" id="n-go">Create</button>
        </div>
      </section>`;

    $('#n-name').addEventListener('input', () => {
      const key = $('#n-key');
      if (key.dataset.touched) return;
      key.value = $('#n-name').value.toLowerCase()
        .replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    });
    $('#n-key').addEventListener('input', () => { $('#n-key').dataset.touched = '1'; });

    const imp = $('#n-import');
    if (imp) imp.addEventListener('change', async () => {
      const file = imp.files && imp.files[0];
      if (!file) return;
      err(null);
      try {
        const pack = JSON.parse(await file.text());
        const made = (await API.post('content/import_bundle', { bundle: pack })).imported;
        location.href = 'studio.php?module=' + encodeURIComponent(made.module.module_key);
      } catch (e) { err(e); }
    });
    $('#n-go').addEventListener('click', async () => {
      err(null);
      const btn = $('#n-go');
      btn.disabled = true;
      try {
        const made = (await API.post('content/create_module', {
          name: $('#n-name').value,
          module_key: $('#n-key').value,
          blurb: $('#n-blurb').value,
          region_type: $('#n-kind').value,
          level_min: Number($('#n-min').value),
          level_max: Number($('#n-max').value),
        })).created;
        location.href = 'studio.php?module=' + encodeURIComponent(made.module.module_key);
      } catch (e) {
        err(e);
        btn.disabled = false;
      }
    });
  }

  function renderDesk() {
    const m = state.module;
    const key = encodeURIComponent(m.module_key);
    $('#st-title').textContent = m.name;
    $('#st-nav').innerHTML = `
      <a class="btn" href="studio.php">All adventures</a>
      <a class="btn" href="create.php?module=${key}">Play</a>
      <a class="btn" href="adventure_print.php?module=${key}">Book</a>
      <button type="button" class="btn" id="st-export">Export to files</button>
      <button type="button" class="btn" id="st-bundle">Download</button>
      <a class="btn" href="content.php">Copy desk</a>`;

    const live = Number(m.is_active) === 1;
    $('#st-main').innerHTML = `
      <p class="help-hint st-meta">${esc(m.blurb || 'A draft.')}
        · Levels ${esc(m.level_min)}–${esc(m.level_max)}
        · ${live ? 'on the shelf' : 'draft'}
        <button type="button" class="btn" id="st-active">${live ? 'Take off shelf' : 'Put on shelf'}</button>
        <span id="st-export-out"></span>
      </p>
      <label class="st-upload st-cover-up">Cover
        <input type="file" id="st-cover" accept="image/png,image/jpeg">
      </label>
      <div class="st-desk">
        <section class="st-pane st-map">
          <h2>Map</h2>
          <div id="st-chart"></div>
        </section>
        <section class="st-pane st-scene">
          <h2 id="st-scene-title">Scene</h2>
          <div class="st-plan-tools">
            <button type="button" class="btn st-plan-tool is-on" id="st-view-edit">Write</button>
            <button type="button" class="btn st-plan-tool" id="st-view-walk">Walk</button>
          </div>
          <div id="st-scene"></div>
        </section>
        <section class="st-pane st-run">
          <h2>Run</h2>
          <div id="st-run"></div>
        </section>
      </div>`;

    $('#st-active').addEventListener('click', async () => {
      err(null);
      try {
        state.module = (await API.post('content/set_active', {
          id: m.id,
          is_active: live ? 0 : 1,
        })).module;
        renderDesk();
      } catch (e) { err(e); }
    });
    const bundle = $('#st-bundle');
    if (bundle) bundle.addEventListener('click', async () => {
      err(null);
      try {
        const pack = (await API.get('content/bundle', { module_id: m.id })).bundle;
        const blob = new Blob([JSON.stringify(pack, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = (m.module_key || 'adventure') + '.adventure.json';
        a.click();
        URL.revokeObjectURL(a.href);
      } catch (e) { err(e); }
    });
    const exp = $('#st-export');
    if (exp) exp.addEventListener('click', async () => {
      err(null);
      const out = $('#st-export-out');
      try {
        const r = await API.post('admin/export', {});
        const n = (r.written || []).length;
        if (out) {
          out.textContent = n === 0
            ? 'Nothing to write — the files already match.'
            : 'Wrote ' + n + ' file' + (n === 1 ? '' : 's') + '.';
        }
      } catch (e) { err(e); }
    });
    $('#st-view-edit').addEventListener('click', () => {
      state.view = 'scene';
      renderScene();
    });
    $('#st-view-walk').addEventListener('click', () => {
      state.view = 'walk';
      renderScene();
    });
    $('#st-cover').addEventListener('change', async (ev) => {
      const file = ev.target.files && ev.target.files[0];
      if (!file) return;
      err(null);
      const reader = new FileReader();
      reader.onload = async () => {
        try {
          await API.post('content/save_art', {
            kind: 'cover',
            key: state.module.module_key,
            image: reader.result,
          });
        } catch (e) { err(e); }
      };
      reader.readAsDataURL(file);
    });

    state.view = 'scene';
    renderScene();
    renderRun();
    mountMap();
  }

  async function loadHere(id) {
    err(null);
    try {
      state.here = (await API.get('content/location', { id })).location;
      state.view = 'scene';
      state.talk = null;
      state.talkNpc = null;
      renderScene();
    } catch (e) { err(e); }
  }

  async function reloadChart() {
    state.chart = (await API.get('content/chart', { module_id: state.module.id })).chart;
    state.beats = (await API.get('content/run', { module_id: state.module.id })).beats || [];
    renderRun();
    mountMap();
  }

  async function reloadHere() {
    if (state.here && state.here.id) {
      state.here = (await API.get('content/location', { id: state.here.id })).location;
    }
    state.beats = (await API.get('content/run', { module_id: state.module.id })).beats || [];
    renderScene();
    renderRun();
  }

  function renderScene() {
    const box = $('#st-scene');
    const title = $('#st-scene-title');
    if (!box) return;
    if (state.view === 'talk' && state.talkNpc) {
      if (title) title.textContent = 'Talk';
      return;
    }
    if (state.view === 'walk') {
      if (title) title.textContent = 'Walk';
      renderWalk();
      return;
    }
    if (title) title.textContent = 'Scene';
    const editBtn = $('#st-view-edit');
    const walkBtn = $('#st-view-walk');
    if (editBtn) editBtn.classList.add('is-on');
    if (walkBtn) walkBtn.classList.remove('is-on');
    const here = state.here;
    if (!here || !here.id) {
      box.innerHTML = here
        ? `<h3 class="ce-title">${esc(here.name)}</h3><p class="help-hint">${esc(here.description)}</p>`
        : '<p class="help-hint">No start room.</p>';
      return;
    }

    const people = (here.npcs || []).map((n) => `
      <li>
        <button type="button" class="btn" data-talk="${n.id}">${esc(n.name)}</button>
        ${n.role ? '<span class="help-hint"> — ' + esc(n.role) + '</span>' : ''}
      </li>`).join('');

    const fights = (here.encounters || []).map((f) =>
      `<li>${esc(f.name)}${Number(f.is_ambush) ? ' (ambush)' : ''}</li>`
    ).join('');

    const loot = (here.items || []).map((it) =>
      `<li>${esc(it.name)} <button type="button" class="btn" data-drop-item="${it.id}">✕</button></li>`
    ).join('');

    const exits = (here.exits || []).map((x) =>
      `<li><button type="button" class="btn" data-go="${x.to_location_id}">${esc(x.label)}</button>
        → ${esc(x.to_name)}</li>`
    ).join('');

    const monOpts = (state.refs.monsters || []).map((m) =>
      `<option value="${esc(m.monster_key)}">${esc(m.name)}</option>`
    ).join('');
    const itemOpts = (state.refs.items || []).map((it) =>
      `<option value="${esc(it.item_key)}">${esc(it.name)}</option>`
    ).join('');
    box.innerHTML = `
      <h3 class="ce-title">${esc(here.name)} <code>${esc(here.location_key)}</code></h3>
      <label>Name<input type="text" id="sc-name" value="${esc(here.name)}"></label>
      <label>The scene<textarea id="sc-desc" rows="5">${esc(here.description || '')}</textarea></label>
      <label>First visit<textarea id="sc-first" rows="2"
        placeholder="Shown once, the first time they arrive.">${esc(here.first_visit_text || '')}</textarea></label>
      <div class="ce-actions">
        <button type="button" class="btn btn-primary" id="sc-save">Save scene</button>
      </div>

      <h3 class="ce-sub">People here</h3>
      ${people ? `<ul class="st-list">${people}</ul>` : '<p class="help-hint">Nobody stands here yet.</p>'}
      <details class="st-add">
        <summary>Add a person</summary>
        <div class="ce-grid">
          <label>Name<input type="text" id="np-name" placeholder="The warden"></label>
          <label>Key<input type="text" id="np-key" autocapitalize="none" spellcheck="false"></label>
        </div>
        <label>Role<input type="text" id="np-role" placeholder="Doorkeeper"></label>
        <label>Face
          <select id="np-sprite">
            <option value="">none yet — they will be a name</option>
            ${(state.refs.sprites || []).map((s) =>
              `<option value="${esc(s)}">${esc(s)}</option>`).join('')}
          </select>
        </label>
        <div class="ce-actions"><button type="button" class="btn" id="np-go">Create</button></div>
      </details>

      <h3 class="ce-sub">Fight waiting here</h3>
      ${fights ? `<ul>${fights}</ul>` : '<p class="help-hint">No fight is placed.</p>'}
      <details class="st-add">
        <summary>Place a fight</summary>
        <label>Name<input type="text" id="ft-name" placeholder="Something in the dark"></label>
        <div class="ce-grid">
          <label>Creature<select id="ft-mon">${monOpts}</select></label>
          <label>How many<input type="number" id="ft-qty" value="2" min="1" max="12"></label>
        </div>
        <label class="ce-check"><input type="checkbox" id="ft-ambush"> Ambush</label>
        <div class="ce-actions"><button type="button" class="btn" id="ft-go">Place</button></div>
      </details>

      <h3 class="ce-sub">On the ground</h3>
      ${loot ? `<ul class="st-list">${loot}</ul>` : '<p class="help-hint">Nothing to pick up.</p>'}
      <div class="ce-grid">
        <label>Item<select id="lt-item">${itemOpts}</select></label>
      </div>
      <div class="ce-actions"><button type="button" class="btn" id="lt-go">Drop it here</button></div>

      <h3 class="ce-sub">Ways out</h3>
      ${exits ? `<ul class="st-list">${exits}</ul>` : '<p class="help-hint">No way onward yet.</p>'}
    `;

    bindScene();
  }

  function bindScene() {
    const here = state.here;
    $('#sc-save').addEventListener('click', async () => {
      err(null);
      try {
        let ambience = null;
        try {
          if (here.ambience_json) ambience = JSON.parse(here.ambience_json);
        } catch (e) { ambience = null; }
        state.here = (await API.post('content/save_location', {
          id: here.id,
          location_key: here.location_key,
          region_id: here.region_id,
          name: $('#sc-name').value,
          description: $('#sc-desc').value,
          first_visit_text: $('#sc-first').value,
          location_type: here.location_type,
          map_x: here.map_x,
          map_y: here.map_y,
          inn_cost: here.inn_cost,
          allow_camp: Number(here.allow_camp) === 1 ? 1 : 0,
          random_encounter_pct: here.random_encounter_pct,
          hidden_until_visited: Number(here.hidden_until_visited) === 1 ? 1 : 0,
          ambience,
        })).location;
        renderScene();
        await reloadChart();
      } catch (e) { err(e); }
    });

    $('#np-name').addEventListener('input', () => {
      const key = $('#np-key');
      if (key.dataset.touched) return;
      key.value = (state.module.module_key + '_' + $('#np-name').value.toLowerCase())
        .replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    });
    $('#np-key').addEventListener('input', () => { $('#np-key').dataset.touched = '1'; });
    $('#np-go').addEventListener('click', async () => {
      err(null);
      try {
        await API.post('content/create_npc', {
          npc_key: $('#np-key').value,
          name: $('#np-name').value,
          role: $('#np-role').value,
          sprite_key: $('#np-sprite').value,
          location_id: here.id,
        });
        await reloadHere();
      } catch (e) { err(e); }
    });

    $('#ft-go').addEventListener('click', async () => {
      err(null);
      try {
        const name = $('#ft-name').value.trim() || $('#ft-mon').selectedOptions[0].textContent;
        await API.post('content/create_encounter', {
          encounter_key: uniqueKey(state.module.module_key + '_fight'),
          name,
          location_id: here.id,
          is_ambush: $('#ft-ambush').checked ? 1 : 0,
          monsters: [{ monster_key: $('#ft-mon').value, quantity: Number($('#ft-qty').value) || 1 }],
        });
        await reloadHere();
      } catch (e) { err(e); }
    });

    $('#lt-go').addEventListener('click', async () => {
      err(null);
      try {
        state.here = (await API.post('content/add_item', {
          location_id: here.id,
          item_key: $('#lt-item').value,
        })).location;
        renderScene();
      } catch (e) { err(e); }
    });

    document.querySelectorAll('#st-scene [data-talk]').forEach((btn) => {
      btn.addEventListener('click', () => openTalk(Number(btn.getAttribute('data-talk'))));
    });
    document.querySelectorAll('#st-scene [data-go]').forEach((btn) => {
      btn.addEventListener('click', () => loadHere(Number(btn.getAttribute('data-go'))));
    });
    document.querySelectorAll('#st-scene [data-drop-item]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        err(null);
        try {
          state.here = (await API.post('content/remove_item', {
            id: Number(btn.getAttribute('data-drop-item')),
          })).location;
          renderScene();
        } catch (e) { err(e); }
      });
    });
  }

  function renderWalk() {
    const box = $('#st-scene');
    const editBtn = $('#st-view-edit');
    const walkBtn = $('#st-view-walk');
    if (editBtn) editBtn.classList.remove('is-on');
    if (walkBtn) walkBtn.classList.add('is-on');
    if (!box) return;
    const here = state.here;
    if (!here || !here.id) {
      box.innerHTML = '<p class="help-hint">Save the plan first. There is nowhere to stand.</p>';
      return;
    }
    const beats = (state.beats || []).filter((b) => Number(b.location_id) === Number(here.id));
    const people = (here.npcs || []).map((n) =>
      `<li><button type="button" class="btn" data-talk="${n.id}">${esc(n.name)}</button>
        ${n.role ? '<span class="help-hint"> — ' + esc(n.role) + '</span>' : ''}</li>`
    ).join('');
    const exits = (here.exits || []).map((x) =>
      `<li><button type="button" class="btn" data-go="${x.to_location_id}">${esc(x.label)}</button></li>`
    ).join('');
    const fights = (here.encounters || []).map((f) =>
      `<li>${esc(f.name)}${Number(f.is_ambush) ? ' — waiting as an ambush' : ''}</li>`
    ).join('');
    const loot = (here.items || []).map((it) => `<li>${esc(it.name)}</li>`).join('');
    box.innerHTML = `
      <p class="help-hint">Walking. Nothing is written. A beat listed here is
        what would fire if a party stood in this room.</p>
      <h3 class="ce-title">${esc(here.name)}</h3>
      ${here.first_visit_text ? `<p class="st-walk-line">${esc(here.first_visit_text)}</p>` : ''}
      <p>${esc(here.description)}</p>
      ${beats.length ? `<h3 class="ce-sub">Beats here</h3>
        <ul>${beats.map((b) => `<li><em>${esc(b.kind)}</em> ${esc(b.title)}</li>`).join('')}</ul>` : ''}
      <h3 class="ce-sub">People</h3>
      ${people ? `<ul class="st-list">${people}</ul>` : '<p class="help-hint">Nobody.</p>'}
      ${fights ? `<h3 class="ce-sub">A fight</h3><ul>${fights}</ul>` : ''}
      ${loot ? `<h3 class="ce-sub">On the ground</h3><ul>${loot}</ul>` : ''}
      <h3 class="ce-sub">Ways out</h3>
      ${exits ? `<ul class="st-list">${exits}</ul>` : '<p class="help-hint">No way onward.</p>'}`;
    box.querySelectorAll('[data-talk]').forEach((btn) => {
      btn.addEventListener('click', () => openTalk(Number(btn.getAttribute('data-talk'))));
    });
    box.querySelectorAll('[data-go]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        await loadHere(Number(btn.getAttribute('data-go')));
        state.view = 'walk';
        renderScene();
      });
    });
  }

  function uniqueKey(base) {
    return base + '_' + Math.random().toString(36).slice(2, 6);
  }

  async function openTalk(npcId) {
    err(null);
    let npc;
    let dlg;
    try {
      npc = (await API.get('content/npc', { id: npcId })).npc;
      dlg = (await API.get('content/dialogue', { id: npcId })).dialogue;
    } catch (e) { return err(e); }

    state.view = 'talk';
    state.talkNpc = npc;
    const title = $('#st-scene-title');
    if (title) title.textContent = npc.name;
    const box = $('#st-scene');
    box.innerHTML = `
      <p><button type="button" class="btn" id="tk-back">← Scene</button></p>
      <h3 class="ce-title">${esc(npc.name)} <code>${esc(npc.npc_key)}</code></h3>
      <div class="ce-grid">
        <label>Name<input type="text" id="tk-name" value="${esc(npc.name)}"></label>
        <label>Role<input type="text" id="tk-role" value="${esc(npc.role || '')}"></label>
      </div>
      <label>Face
        <select id="tk-sprite">
          <option value="">none yet</option>
          ${(state.refs.sprites || []).map((s) =>
            `<option value="${esc(s)}"${s === (npc.sprite_key || '') ? ' selected' : ''}>${esc(s)}</option>`
          ).join('')}
        </select>
      </label>
      <div class="ce-actions">
        <button type="button" class="btn" id="tk-save-npc">Save person</button>
      </div>
      <h3 class="ce-sub">Work they can hand out</h3>
      <div id="tk-quests"></div>
      <details class="st-add">
        <summary>Give them work</summary>
        <label>Title<input type="text" id="qk-title" placeholder="Look in the far room"></label>
        <label>Go to
          <select id="qk-target">
            <option value="">— nowhere yet —</option>
            ${(state.chart.nodes || []).map((n) =>
              `<option value="${n.id}">${esc(n.name)}</option>`).join('')}
          </select>
        </label>
        <div class="ce-actions"><button type="button" class="btn" id="qk-go">Create quest</button></div>
      </details>
      <h3 class="ce-sub">What they say</h3>
      <div id="tk-cards"></div>
      <div class="ce-actions">
        <button type="button" class="btn btn-primary" id="tk-save">Save conversation</button>
      </div>`;

    $('#tk-back').addEventListener('click', () => {
      state.view = 'scene';
      state.talk = null;
      state.talkNpc = null;
      renderScene();
    });
    $('#tk-save-npc').addEventListener('click', async () => {
      err(null);
      try {
        await API.post('content/save_npc', {
          id: npc.id,
          name: $('#tk-name').value,
          role: $('#tk-role').value,
          description: npc.description,
          sprite_key: $('#tk-sprite').value,
          pose: npc.pose || '',
          is_merchant: npc.is_merchant,
          is_quest_giver: 1,
          is_ambient: npc.is_ambient,
        });
      } catch (e) { err(e); }
    });
    $('#qk-go').addEventListener('click', async () => {
      err(null);
      try {
        const title = $('#qk-title').value;
        const q = (await API.post('content/create_quest', {
          quest_key: uniqueKey(state.module.module_key + '_job'),
          title,
          giver_npc_id: npc.id,
        })).quest;
        const target = $('#qk-target').value;
        if (target) {
          await API.post('content/add_stage', {
            quest_id: q.id,
            stage_key: 'go',
            title: 'Go there',
            objective: 'Walk to the place.',
            target_location_id: Number(target),
          });
        }
        await API.post('content/save_npc', {
          id: npc.id,
          name: npc.name,
          role: npc.role,
          description: npc.description,
          sprite_key: npc.sprite_key || '',
          is_quest_giver: 1,
          is_ambient: npc.is_ambient,
        });
        state.beats = (await API.get('content/run', { module_id: state.module.id })).beats || [];
        renderRun();
        openTalk(npcId);
      } catch (e) { err(e); }
    });

    await paintTalkQuests(npc.id);
    const quests = await giverQuests(npc.id);
    const fights = (state.here.encounters || []).map((f) => ({
      key: f.encounter_key, name: f.name,
    }));
    let lint = null;
    try {
      lint = (await API.get('content/lint_dialogue', { id: npc.id })).lint;
    } catch (e) { /* none yet */ }

    const fullQuests = [];
    for (const q of quests) {
      try {
        const got = (await API.get('content/quest', { id: q.id })).quest;
        fullQuests.push({
          key: got.quest_key,
          title: got.title,
          stages: (got.stages || []).map((s) => ({ key: s.stage_key, title: s.title })),
        });
      } catch (e) {
        fullQuests.push({ key: q.key, title: q.title, stages: [] });
      }
    }
    state.talk = window.TalkCards.mount($('#tk-cards'), {
      doc: dlg.dialogue,
      quests: fullQuests,
      fights,
      items: (state.refs.items || []).map((it) => ({ key: it.item_key, name: it.name })),
      companions: (state.refs.companions || []).map((c) => ({
        key: c.companion_key, name: c.name,
      })),
      locations: (state.chart.nodes || []).map((n) => ({ key: n.key, name: n.name })),
      lint: lint && lint.nodes && lint.nodes.length ? lint : null,
    });

    $('#tk-save').addEventListener('click', async () => {
      err(null);
      try {
        await API.post('content/save_dialogue', {
          id: npc.id,
          dialogue: state.talk.getDoc(),
        });
        const next = (await API.get('content/lint_dialogue', { id: npc.id })).lint;
        if (state.talk.setLint) state.talk.setLint(next);
      } catch (e) { err(e); }
    });
  }

  async function giverQuests(npcId) {
    try {
      const all = (await API.get('content/quests', { module_id: state.module.id })).quests || [];
      return all
        .filter((q) => Number(q.giver_npc_id) === Number(npcId))
        .map((q) => ({ key: q.quest_key, title: q.title, id: q.id }));
    } catch (e) {
      return [];
    }
  }

  async function paintTalkQuests(npcId) {
    const host = $('#tk-quests');
    if (!host) return;
    const mine = await giverQuests(npcId);
    if (!mine.length) {
      host.innerHTML = '<p class="help-hint">No work yet. A reply can start one once it exists.</p>';
      return;
    }
    const full = [];
    for (const row of mine) {
      try {
        full.push((await API.get('content/quest', { id: row.id })).quest);
      } catch (e) { /* skip */ }
    }
    const locOpts = (id) =>
      `<option value="">—</option>` + (state.chart.nodes || []).map((n) =>
        `<option value="${n.id}"${Number(n.id) === Number(id) ? ' selected' : ''}>${esc(n.name)}</option>`
      ).join('');

    host.innerHTML = full.map((q) => `
      <article class="st-quest" data-quest="${q.id}">
        <h4>${esc(q.title)} <code>${esc(q.quest_key)}</code></h4>
        <ol class="st-stages">
          ${(q.stages || []).map((s) => `
            <li data-stage="${s.id}">
              <input type="text" data-st-title value="${esc(s.title)}" ${Number(s.is_terminal) ? '' : ''}>
              <select data-st-target>${locOpts(s.target_location_id)}</select>
              ${Number(s.is_terminal) ? '<em>end</em>' : ''}
              <button type="button" class="btn" data-st-save>Save</button>
              ${Number(s.is_terminal) ? '' : '<button type="button" class="btn" data-st-drop>✕</button>'}
            </li>`).join('')}
        </ol>
        <div class="ce-grid">
          <label>Another stage<input type="text" data-st-new placeholder="Come back"></label>
        </div>
        <div class="ce-actions">
          <button type="button" class="btn" data-st-add>Add stage</button>
        </div>
      </article>`).join('');

    host.querySelectorAll('[data-st-save]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const li = btn.closest('[data-stage]');
        const art = btn.closest('[data-quest]');
        if (!li || !art) return;
        err(null);
        try {
          const q = full.find((x) => Number(x.id) === Number(art.getAttribute('data-quest')));
          const st = q && (q.stages || []).find((s) => Number(s.id) === Number(li.getAttribute('data-stage')));
          if (!st) return;
          await API.post('content/save_stage', {
            id: st.id,
            title: li.querySelector('[data-st-title]').value,
            objective: st.objective,
            journal_entry: st.journal_entry,
            is_terminal: st.is_terminal,
            outcome: st.outcome,
            target_location_id: li.querySelector('[data-st-target]').value || null,
          });
          state.beats = (await API.get('content/run', { module_id: state.module.id })).beats || [];
          renderRun();
        } catch (e) { err(e); }
      });
    });
    host.querySelectorAll('[data-st-drop]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const li = btn.closest('[data-stage]');
        if (!li) return;
        err(null);
        try {
          await API.post('content/delete_stage', { id: Number(li.getAttribute('data-stage')) });
          await paintTalkQuests(npcId);
          state.beats = (await API.get('content/run', { module_id: state.module.id })).beats || [];
          renderRun();
        } catch (e) { err(e); }
      });
    });
    host.querySelectorAll('[data-st-add]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const art = btn.closest('[data-quest]');
        if (!art) return;
        const title = (art.querySelector('[data-st-new]') || {}).value || '';
        if (!title.trim()) return;
        err(null);
        try {
          await API.post('content/add_stage', {
            quest_id: Number(art.getAttribute('data-quest')),
            stage_key: uniqueKey('beat'),
            title: title.trim(),
          });
          await paintTalkQuests(npcId);
          state.beats = (await API.get('content/run', { module_id: state.module.id })).beats || [];
          renderRun();
        } catch (e) { err(e); }
      });
    });
  }

  function renderRun() {
    const box = $('#st-run');
    if (!box) return;
    const beats = state.beats || [];
    if (!beats.length) {
      box.innerHTML = `<p class="help-hint">The spine of the adventure, once there
        is one. A beat is arrive, talk, take work, fight, or end. Empty is the
        point of a blank page.</p>`;
      return;
    }
    box.innerHTML = `<ol class="st-beats">${beats.map((b, i) => `
      <li>
        <button type="button" class="st-beat is-${esc(b.kind)}" data-beat="${i}">
          <em>${esc(b.kind)}</em>
          <span>${esc(b.title)}</span>
        </button>
      </li>`).join('')}</ol>`;
    box.querySelectorAll('[data-beat]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const b = beats[Number(btn.getAttribute('data-beat'))];
        if (b && b.location_id) loadHere(b.location_id);
        if (b && b.npc_id) openTalk(b.npc_id);
      });
    });
  }

  function mountMap() {
    const host = $('#st-chart');
    if (!host || !state.chart) return;
    host.innerHTML = '';
    host.className = '';
    const dungeon = state.chart.region_type === 'dungeon'
      && window.PlanDesk
      && state.chart.plan
      && (state.chart.plan.rooms || []).length;
    if (dungeon) {
      window.PlanDesk.mount(host, {
        plan: state.chart.plan,
        prefix: state.module.module_key,
        get chart() { return state.chart; },
        onSave: async (plan) => {
          err(null);
          const saved = (await API.post('content/save_plan', {
            region_id: state.chart.region_id,
            plan,
          })).saved;
          state.chart = saved.chart;
          if (state.here && state.here.id) {
            try {
              state.here = (await API.get('content/location', { id: state.here.id })).location;
            } catch (e) { /* renamed */ }
          }
          renderScene();
        },
        onSelect: async (room) => {
          const node = (state.chart.nodes || []).find((n) => n.key === room.key);
          if (!node) {
            state.here = {
              name: room.name,
              location_key: room.key,
              description: 'Not saved yet. Save the plan to make this a place.',
              npcs: [],
              exits: [],
            };
            state.view = 'scene';
            renderScene();
            return;
          }
          await loadHere(node.id);
        },
      });
      return;
    }
    if (window.ChartDesk) {
      window.ChartDesk.mount(host, {
        chart: state.chart,
        prefix: state.module.module_key,
        onSelect: (node) => loadHere(node.id),
        onCreate: async (pos) => {
          err(null);
          try {
            const saved = (await API.post('content/save_location', {
              location_key: uniqueKey(state.module.module_key + '_place'),
              region_id: state.chart.region_id,
              name: 'A place',
              description: 'Nobody has written what is here yet.',
              location_type: state.chart.region_type === 'town' ? 'site' : 'site',
              map_x: pos.x,
              map_y: pos.y,
            })).location;
            await reloadChart();
            await loadHere(saved.id);
          } catch (e) { err(e); }
        },
        onMove: async (node, pos) => {
          err(null);
          try {
            await API.post('content/save_position', {
              id: node.id, map_x: pos.x, map_y: pos.y,
            });
          } catch (e) { err(e); }
        },
        onLink: async (from, to) => {
          err(null);
          try {
            await API.post('content/save_exit', {
              from_location_id: from.id,
              to_location_id: to.id,
              label: 'On to ' + to.name,
            });
            await API.post('content/save_exit', {
              from_location_id: to.id,
              to_location_id: from.id,
              label: 'Back to ' + from.name,
            });
            await reloadChart();
            if (state.here && state.here.id) await reloadHere();
          } catch (e) { err(e); }
        },
        onPlate: state.chart.region_type === 'dungeon' ? null : async (dataUrl) => {
          err(null);
          try {
            await API.post('content/save_art', {
              kind: 'plate',
              key: state.chart.region_key,
              image: dataUrl,
            });
            await reloadChart();
          } catch (e) { err(e); }
        },
      });
      return;
    }
    host.className = 'loc-map is-bare';
    if (window.WorldMap) {
      host.innerHTML = window.WorldMap.svg(state.chart, {});
      if (window.WorldMap.controls) window.WorldMap.controls(host, null, 1.2);
    }
  }

  boot();
})();
