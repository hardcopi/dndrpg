/**
 * The studio's dungeon plan desk.
 *
 * Rooms are rectangles on DungeonGen's 9×7 coarse plan. Halls are pairs of
 * rooms. The client draws the graph paper and refuses an overlap the server
 * would refuse; it does not route corridors. Save compiles through
 * content/save_plan, and Look shows window.WorldMap.svg of what came back —
 * the same renderer the game uses.
 */
(function () {
  'use strict';

  // Must match DungeonGen::GRID_W / GRID_H. A cell of clearance between rooms
  // is the same rule DungeonGen::fits() enforces: without it the router has
  // nowhere to run.
  const GRID_W = 9;
  const GRID_H = 7;
  const CELL = 10;
  const DOORS = ['open', 'arch', 'door', 'stuck', 'locked', 'portcullis'];

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  function clone(plan) {
    const src = plan && typeof plan === 'object' ? plan : {};
    return {
      rooms: (src.rooms || []).map((r, i) => ({
        id: r.id != null ? Number(r.id) : i,
        key: String(r.key || ''),
        name: String(r.name || 'A room'),
        gx: Number(r.gx),
        gy: Number(r.gy),
        w: Math.max(1, Number(r.w) || 1),
        h: Math.max(1, Number(r.h) || 1),
      })),
      halls: (src.halls || []).map((h, i) => ({
        id: h.id != null ? Number(h.id) : i,
        a: Number(h.a),
        b: Number(h.b),
        door: DOORS.includes(h.door) ? h.door : 'open',
        hidden: !!h.hidden,
      })),
    };
  }

  function fits(rooms, gx, gy, w, h, exceptId) {
    if (gx < 0 || gy < 0 || w < 1 || h < 1 || gx + w > GRID_W || gy + h > GRID_H) {
      return false;
    }
    for (const r of rooms) {
      if (exceptId != null && r.id === exceptId) continue;
      if (gx <= r.gx + r.w && r.gx <= gx + w && gy <= r.gy + r.h && r.gy <= gy + h) {
        return false;
      }
    }
    return true;
  }

  function roomAt(rooms, gx, gy) {
    return rooms.find((r) => gx >= r.gx && gy >= r.gy && gx < r.gx + r.w && gy < r.gy + r.h) || null;
  }

  function nextId(items) {
    return items.reduce((m, it) => Math.max(m, Number(it.id) || 0), -1) + 1;
  }

  function uniqueKey(rooms, prefix) {
    const used = new Set(rooms.map((r) => r.key));
    let n = rooms.length + 1;
    let key;
    do {
      key = prefix + '_room_' + n;
      n += 1;
    } while (used.has(key));
    return key;
  }

  function hallBetween(halls, a, b) {
    return halls.find((h) => (h.a === a && h.b === b) || (h.a === b && h.b === a)) || null;
  }

  function centre(r) {
    return { x: (r.gx + r.w / 2) * CELL, y: (r.gy + r.h / 2) * CELL };
  }

  function cellOf(svg, ev) {
    const pt = svg.createSVGPoint();
    pt.x = ev.clientX;
    pt.y = ev.clientY;
    const ctm = svg.getScreenCTM();
    if (!ctm) return null;
    const p = pt.matrixTransform(ctm.inverse());
    const gx = Math.floor(p.x / CELL);
    const gy = Math.floor(p.y / CELL);
    if (gx < 0 || gy < 0 || gx >= GRID_W || gy >= GRID_H) return null;
    return { gx, gy, x: p.x, y: p.y };
  }

  function handleAt(room, gx, gy) {
    const x1 = room.gx;
    const y1 = room.gy;
    const x2 = room.gx + room.w - 1;
    const y2 = room.gy + room.h - 1;
    const onX = gx === x1 || gx === x2;
    const onY = gy === y1 || gy === y2;
    if (!onX && !onY) return null;
    if (gx < x1 || gx > x2 || gy < y1 || gy > y2) return null;
    const hx = gx === x1 ? 'w' : gx === x2 ? 'e' : '';
    const hy = gy === y1 ? 'n' : gy === y2 ? 's' : '';
    return (hy + hx) || null;
  }

  function resize(room, handle, gx, gy) {
    let x1 = room.gx;
    let y1 = room.gy;
    let x2 = room.gx + room.w - 1;
    let y2 = room.gy + room.h - 1;
    if (handle.includes('w')) x1 = gx;
    if (handle.includes('e')) x2 = gx;
    if (handle.includes('n')) y1 = gy;
    if (handle.includes('s')) y2 = gy;
    const nx = Math.min(x1, x2);
    const ny = Math.min(y1, y2);
    return { gx: nx, gy: ny, w: Math.abs(x2 - x1) + 1, h: Math.abs(y2 - y1) + 1 };
  }

  function nearHall(halls, rooms, x, y) {
    let best = null;
    let bestD = 2.2;
    for (const h of halls) {
      const a = rooms.find((r) => r.id === h.a);
      const b = rooms.find((r) => r.id === h.b);
      if (!a || !b) continue;
      const p = centre(a);
      const q = centre(b);
      const d = distToSeg(x, y, p.x, p.y, q.x, q.y);
      if (d < bestD) {
        bestD = d;
        best = h;
      }
    }
    return best;
  }

  function distToSeg(px, py, x1, y1, x2, y2) {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const len = dx * dx + dy * dy;
    if (len === 0) return Math.hypot(px - x1, py - y1);
    let t = ((px - x1) * dx + (py - y1) * dy) / len;
    t = Math.max(0, Math.min(1, t));
    return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
  }

  function mount(host, opts) {
    if (!host) return { destroy() {} };
    const prefix = String(opts.prefix || 'room').replace(/[^a-z0-9_]/g, '_') || 'room';
    const state = {
      plan: clone(opts.plan),
      tool: 'select',
      look: false,
      dirty: false,
      sel: null,
      hallFrom: null,
      drag: null,
      saving: false,
    };
    let inspectSel = null;

    host.innerHTML = `
      <div class="st-plan">
        <div class="st-plan-tools" role="toolbar" aria-label="Plan tools">
          <button type="button" class="btn st-plan-tool" data-tool="select">Select</button>
          <button type="button" class="btn st-plan-tool" data-tool="room">Room</button>
          <button type="button" class="btn st-plan-tool" data-tool="hall">Hall</button>
          <button type="button" class="btn" id="st-plan-look">Look</button>
          <button type="button" class="btn btn-primary" id="st-plan-save">Save</button>
        </div>
        <p class="help-hint st-plan-hint">Rooms need a cell of rock between them.
          A hall is the way you walk. Look shows the last saved floor as the
          game will draw it.</p>
        <div class="st-plan-stage" id="st-plan-stage"></div>
        <div class="st-plan-inspect" id="st-plan-inspect"></div>
      </div>`;

    const stage = host.querySelector('#st-plan-stage');
    const inspect = host.querySelector('#st-plan-inspect');

    host.querySelectorAll('[data-tool]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.tool = btn.getAttribute('data-tool');
        state.hallFrom = null;
        state.look = false;
        paint();
      });
    });
    host.querySelector('#st-plan-look').addEventListener('click', () => {
      state.look = !state.look;
      state.drag = null;
      paint();
    });
    host.querySelector('#st-plan-save').addEventListener('click', save);

    function markDirty() {
      state.dirty = true;
      const btn = host.querySelector('#st-plan-save');
      if (btn) btn.classList.add('is-dirty');
    }

    function snapshot() {
      return {
        rooms: state.plan.rooms.map((r) => ({
          id: r.id, key: r.key, name: r.name, gx: r.gx, gy: r.gy, w: r.w, h: r.h,
        })),
        halls: state.plan.halls.map((h) => ({
          id: h.id, a: h.a, b: h.b, door: h.door, hidden: !!h.hidden,
        })),
      };
    }

    async function save() {
      if (state.saving || !opts.onSave) return;
      state.saving = true;
      const btn = host.querySelector('#st-plan-save');
      if (btn) btn.disabled = true;
      try {
        await opts.onSave(snapshot());
        state.dirty = false;
        if (btn) btn.classList.remove('is-dirty');
      } finally {
        state.saving = false;
        if (btn) btn.disabled = false;
      }
    }

    function select(kind, id) {
      state.sel = kind && id != null ? { kind, id } : null;
      if (kind === 'room' && opts.onSelect) {
        const room = state.plan.rooms.find((r) => r.id === id);
        if (room) opts.onSelect(room);
      }
      paintInspect();
      paintStage();
    }

    function addRoom(gx, gy, w, h) {
      if (!fits(state.plan.rooms, gx, gy, w, h, null)) return false;
      const room = {
        id: nextId(state.plan.rooms),
        key: uniqueKey(state.plan.rooms, prefix),
        name: 'A room',
        gx, gy, w, h,
      };
      state.plan.rooms.push(room);
      markDirty();
      select('room', room.id);
      return true;
    }

    function addHall(a, b) {
      if (a === b || hallBetween(state.plan.halls, a, b)) return;
      const hall = {
        id: nextId(state.plan.halls),
        a, b, door: 'open', hidden: false,
      };
      state.plan.halls.push(hall);
      markDirty();
      select('hall', hall.id);
    }

    function dropSelected() {
      if (!state.sel) return;
      if (state.sel.kind === 'room') {
        const id = state.sel.id;
        if (state.plan.rooms.length <= 1) return;
        state.plan.rooms = state.plan.rooms.filter((r) => r.id !== id);
        state.plan.halls = state.plan.halls.filter((h) => h.a !== id && h.b !== id);
      } else {
        state.plan.halls = state.plan.halls.filter((h) => h.id !== state.sel.id);
      }
      state.sel = null;
      markDirty();
      paint();
    }

    function paint() {
      host.querySelectorAll('[data-tool]').forEach((btn) => {
        btn.classList.toggle('is-on', !state.look && btn.getAttribute('data-tool') === state.tool);
      });
      const lookBtn = host.querySelector('#st-plan-look');
      if (lookBtn) lookBtn.classList.toggle('is-on', state.look);
      paintStage();
      paintInspect();
    }

    function paintStage() {
      if (state.look) {
        if (opts.chart && window.WorldMap) {
          stage.className = 'st-plan-stage loc-map is-bare';
          stage.innerHTML = window.WorldMap.svg(opts.chart, {});
          if (window.WorldMap.controls) window.WorldMap.controls(stage, null, 1.2);
        } else {
          stage.className = 'st-plan-stage';
          stage.innerHTML = '<p class="help-hint">Save the plan to see how it will run.</p>';
        }
        return;
      }
      stage.className = 'st-plan-stage';
      const vb = `0 0 ${GRID_W * CELL} ${GRID_H * CELL}`;
      const cells = [];
      for (let y = 0; y < GRID_H; y++) {
        for (let x = 0; x < GRID_W; x++) {
          cells.push(`<rect class="st-plan-cell" x="${x * CELL}" y="${y * CELL}"
            width="${CELL}" height="${CELL}"/>`);
        }
      }
      const halls = state.plan.halls.map((h) => {
        const a = state.plan.rooms.find((r) => r.id === h.a);
        const b = state.plan.rooms.find((r) => r.id === h.b);
        if (!a || !b) return '';
        const p = centre(a);
        const q = centre(b);
        const on = state.sel && state.sel.kind === 'hall' && state.sel.id === h.id;
        const cls = [
          'st-plan-hall',
          on ? 'is-selected' : '',
          h.hidden ? 'is-secret' : '',
        ].filter(Boolean).join(' ');
        return `<line class="${cls}" x1="${p.x}" y1="${p.y}" x2="${q.x}" y2="${q.y}"/>`;
      }).join('');

      const draft = state.drag && state.drag.kind === 'draw' ? state.drag.rect : null;
      const rooms = state.plan.rooms.map((r) => roomMarkup(r, false)).join('');
      const ghost = draft ? roomMarkup({
        id: -1, name: '', gx: draft.gx, gy: draft.gy, w: draft.w, h: draft.h,
      }, true) : '';

      stage.innerHTML = `<svg class="st-plan-svg" viewBox="${vb}" role="img"
        aria-label="Dungeon plan, ${GRID_W} by ${GRID_H} cells">
        <g class="st-plan-paper">${cells.join('')}</g>
        <g class="st-plan-ways">${halls}</g>
        <g class="st-plan-rooms">${rooms}${ghost}</g>
      </svg>`;

      const size = inspect.querySelector('#st-rm-size');
      if (size && state.sel && state.sel.kind === 'room') {
        const room = state.plan.rooms.find((r) => r.id === state.sel.id);
        if (room) size.textContent = room.w + '×' + room.h;
      }

      const svg = stage.querySelector('svg');
      svg.addEventListener('pointerdown', onDown);
      svg.addEventListener('pointermove', onMove);
      svg.addEventListener('pointerup', onUp);
      svg.addEventListener('pointercancel', onUp);
    }

    function roomMarkup(r, ghost) {
      const on = !ghost && state.sel && state.sel.kind === 'room' && state.sel.id === r.id;
      const from = state.hallFrom === r.id;
      const bad = ghost && !fits(state.plan.rooms, r.gx, r.gy, r.w, r.h, null);
      const cls = [
        'st-plan-room',
        on ? 'is-selected' : '',
        from ? 'is-from' : '',
        ghost ? 'is-draft' : '',
        bad ? 'is-bad' : '',
      ].filter(Boolean).join(' ');
      const x = r.gx * CELL;
      const y = r.gy * CELL;
      const w = r.w * CELL;
      const h = r.h * CELL;
      const label = r.name
        ? `<text class="st-plan-label" x="${x + w / 2}" y="${y + h / 2}">${esc(r.name)}</text>`
        : '';
      let handles = '';
      if (on) {
        const spots = [
          [r.gx, r.gy], [r.gx + r.w - 1, r.gy],
          [r.gx, r.gy + r.h - 1], [r.gx + r.w - 1, r.gy + r.h - 1],
        ];
        handles = spots.map(([hx, hy]) =>
          `<rect class="st-plan-handle" x="${hx * CELL + CELL / 2 - 1.1}"
            y="${hy * CELL + CELL / 2 - 1.1}" width="2.2" height="2.2"/>`
        ).join('');
      }
      return `<g class="${cls}">
        <rect x="${x}" y="${y}" width="${w}" height="${h}"/>
        ${label}${handles}
      </g>`;
    }

    function onDown(ev) {
      if (ev.button != null && ev.button !== 0) return;
      const svg = ev.currentTarget;
      const cell = cellOf(svg, ev);
      if (!cell) return;
      svg.setPointerCapture(ev.pointerId);

      if (state.tool === 'room') {
        state.drag = {
          kind: 'draw',
          x0: cell.gx, y0: cell.gy,
          rect: { gx: cell.gx, gy: cell.gy, w: 1, h: 1 },
        };
        paintStage();
        return;
      }

      if (state.tool === 'hall') {
        const room = roomAt(state.plan.rooms, cell.gx, cell.gy);
        if (!room) {
          state.hallFrom = null;
          paintStage();
          return;
        }
        if (state.hallFrom == null) {
          state.hallFrom = room.id;
          select('room', room.id);
        } else {
          addHall(state.hallFrom, room.id);
          state.hallFrom = null;
        }
        return;
      }

      const room = roomAt(state.plan.rooms, cell.gx, cell.gy);
      if (room) {
        const handle = state.sel && state.sel.kind === 'room' && state.sel.id === room.id
          ? handleAt(room, cell.gx, cell.gy)
          : null;
        if (handle) {
          state.drag = { kind: 'resize', id: room.id, handle, ox: room.gx, oy: room.gy };
        } else {
          state.drag = {
            kind: 'move', id: room.id,
            dx: cell.gx - room.gx, dy: cell.gy - room.gy,
            ox: room.gx, oy: room.gy,
          };
        }
        select('room', room.id);
        return;
      }

      const hall = nearHall(state.plan.halls, state.plan.rooms, cell.x, cell.y);
      if (hall) {
        select('hall', hall.id);
        return;
      }
      select(null, null);
    }

    function onMove(ev) {
      if (!state.drag) return;
      const svg = ev.currentTarget;
      const cell = cellOf(svg, ev);
      if (!cell) return;
      if (state.drag.kind === 'draw') {
        const x1 = Math.min(state.drag.x0, cell.gx);
        const y1 = Math.min(state.drag.y0, cell.gy);
        const x2 = Math.max(state.drag.x0, cell.gx);
        const y2 = Math.max(state.drag.y0, cell.gy);
        state.drag.rect = { gx: x1, gy: y1, w: x2 - x1 + 1, h: y2 - y1 + 1 };
        paintStage();
        return;
      }
      const room = state.plan.rooms.find((r) => r.id === state.drag.id);
      if (!room) return;
      if (state.drag.kind === 'move') {
        const gx = cell.gx - state.drag.dx;
        const gy = cell.gy - state.drag.dy;
        if (fits(state.plan.rooms, gx, gy, room.w, room.h, room.id)) {
          room.gx = gx;
          room.gy = gy;
          paintStage();
        }
        return;
      }
      if (state.drag.kind === 'resize') {
        const next = resize(room, state.drag.handle, cell.gx, cell.gy);
        if (fits(state.plan.rooms, next.gx, next.gy, next.w, next.h, room.id)) {
          room.gx = next.gx;
          room.gy = next.gy;
          room.w = next.w;
          room.h = next.h;
          paintStage();
        }
      }
    }

    function onUp() {
      if (!state.drag) return;
      if (state.drag.kind === 'draw') {
        const r = state.drag.rect;
        state.drag = null;
        if (!addRoom(r.gx, r.gy, r.w, r.h)) paintStage();
        return;
      }
      const room = state.plan.rooms.find((r) => r.id === state.drag.id);
      if (room && (room.gx !== state.drag.ox || room.gy !== state.drag.oy
          || state.drag.kind === 'resize')) {
        markDirty();
      }
      state.drag = null;
      paintStage();
    }

    function paintInspect() {
      const key = state.sel ? state.sel.kind + ':' + state.sel.id : '';
      if (key === inspectSel && inspect.childElementCount) return;
      inspectSel = key;
      if (!state.sel) {
        inspect.innerHTML = '';
        return;
      }
      if (state.sel.kind === 'room') {
        const room = state.plan.rooms.find((r) => r.id === state.sel.id);
        if (!room) {
          inspect.innerHTML = '';
          return;
        }
        inspect.innerHTML = `
          <div class="ce-grid">
            <label>Name<input type="text" id="st-rm-name" value="${esc(room.name)}"></label>
            <label>Key<input type="text" id="st-rm-key" value="${esc(room.key)}"
              autocapitalize="none" spellcheck="false"></label>
          </div>
          <p class="help-hint"><span id="st-rm-size">${room.w}×${room.h}</span> on the plan.
            Drag a corner to resize, or the room to move it.</p>
          <div class="ce-actions">
            <button type="button" class="btn" id="st-rm-del"
              ${state.plan.rooms.length <= 1 ? 'disabled' : ''}>Remove room</button>
          </div>`;
        inspect.querySelector('#st-rm-name').addEventListener('input', (e) => {
          room.name = e.target.value;
          markDirty();
          paintStage();
        });
        inspect.querySelector('#st-rm-key').addEventListener('input', (e) => {
          const v = e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
          if (v && !state.plan.rooms.some((o) => o.id !== room.id && o.key === v)) {
            room.key = v;
            markDirty();
          }
        });
        inspect.querySelector('#st-rm-del').addEventListener('click', dropSelected);
        return;
      }
      const hall = state.plan.halls.find((h) => h.id === state.sel.id);
      if (!hall) {
        inspect.innerHTML = '';
        return;
      }
      const a = state.plan.rooms.find((r) => r.id === hall.a);
      const b = state.plan.rooms.find((r) => r.id === hall.b);
      const opts = DOORS.map((d) =>
        `<option value="${d}"${d === hall.door ? ' selected' : ''}>${d}</option>`
      ).join('');
      inspect.innerHTML = `
        <p class="help-hint">${esc(a ? a.name : 'A room')} ↔ ${esc(b ? b.name : 'A room')}</p>
        <div class="ce-grid">
          <label>Door<select id="st-hl-door">${opts}</select></label>
          <label class="st-plan-check"><input type="checkbox" id="st-hl-secret"
            ${hall.hidden ? 'checked' : ''}> Secret</label>
        </div>
        <div class="ce-actions">
          <button type="button" class="btn" id="st-hl-del">Remove hall</button>
        </div>`;
      inspect.querySelector('#st-hl-door').addEventListener('change', (e) => {
        hall.door = e.target.value;
        markDirty();
        paintStage();
      });
      inspect.querySelector('#st-hl-secret').addEventListener('change', (e) => {
        hall.hidden = e.target.checked;
        markDirty();
        paintStage();
      });
      inspect.querySelector('#st-hl-del').addEventListener('click', dropSelected);
    }

    function onKey(ev) {
      if (ev.target && /^(INPUT|TEXTAREA|SELECT)$/.test(ev.target.tagName)) return;
      if (ev.key === 'Delete' || ev.key === 'Backspace') {
        ev.preventDefault();
        dropSelected();
      } else if (ev.key === 'Escape') {
        state.hallFrom = null;
        state.drag = null;
        select(null, null);
      } else if (ev.key === 'r') {
        state.tool = 'room';
        state.look = false;
        paint();
      } else if (ev.key === 'h') {
        state.tool = 'hall';
        state.look = false;
        paint();
      } else if (ev.key === 'v') {
        state.tool = 'select';
        state.look = false;
        paint();
      }
    }

    host.tabIndex = 0;
    host.addEventListener('keydown', onKey);
    paint();

    return {
      destroy() {
        host.removeEventListener('keydown', onKey);
        host.innerHTML = '';
      },
    };
  }

  window.PlanDesk = { mount, GRID_W, GRID_H };
})();
