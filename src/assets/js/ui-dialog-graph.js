/**
 * A conversation, drawn.
 *
 * Dialogue is the largest and least inspectable content in the game — 62 trees,
 * a thousand nodes, four thousand replies — and it was edited as one JSON
 * textarea. That is fine for changing a line and hopeless for the fault this
 * content actually suffers from, which is not bad JSON but bad SHAPE: a node
 * nothing points at, a variant ordered above the one that should have won, a
 * branch that became dead the day somebody added a reaction above it. Every one
 * of those is valid JSON and invisible in a textarea. All of them are obvious
 * as a picture.
 *
 * Built the way ui-map.js is built, deliberately: a pure `svg()` that returns a
 * string, and a separate `controls()` that attaches behaviour to what was
 * rendered. Same reasons — the string can be dropped into any container and
 * re-rendered wholesale without unbinding anything, and pan/zoom moves the
 * viewBox rather than a CSS transform, so a real zoom keeps the ink crisp and
 * the hit areas move with it for free.
 *
 * Layout is computed here and stored nowhere. Locations carry authored map_x /
 * map_y because somebody arranged that map by hand; a conversation has no such
 * coordinates and should not grow any, because the shape changes every time a
 * node is added and stale positions are worse than none.
 */
(function () {
  'use strict';

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));

  const COL_W = 240;
  const ROW_H = 78;
  const BOX_W = 200;
  const BOX_H = 58;
  const PAD = 60;

  /**
   * Place every node in a column by how far it is from the nearest way in.
   *
   * Breadth-first, so a node's column is its SHORTEST distance from a root and
   * is set exactly once.
   *
   * This was longest-path layering, on the reasoning that placing a node right
   * of its last parent keeps edges pointing forwards. That is a fine rule for a
   * DAG and meaningless here: a dialogue tree is thick with cycles, because
   * almost every conversation has a "Back." reply, and the longest path around
   * a cycle is unbounded. Depths inflated on every lap until the iteration
   * guard stopped them — Ilse's tree came out with columns at x=12540 and
   * x=167500, roughly 640 columns wide, which is what "some of them are really
   * far apart" looks like from the inside. Shortest-path terminates on a cycle
   * by construction.
   *
   * Within a column, nodes are ordered by the mean row of their parents, run a
   * few times so the ordering settles. Without it a column is in whatever order
   * the walk happened to reach it and the edges cross like knitting.
   */
  function layout(nodes, edges, roots) {
    const byKey = new Map(nodes.map((n) => [n.key, n]));
    const depth = new Map();

    const children = new Map();
    const parents = new Map();
    edges.forEach((e) => {
      if (!byKey.has(e.to)) return;
      if (!children.has(e.from)) children.set(e.from, []);
      children.get(e.from).push(e.to);
      if (!parents.has(e.to)) parents.set(e.to, []);
      parents.get(e.to).push(e.from);
    });

    const seeds = roots.filter((r) => byKey.has(r));
    seeds.forEach((r) => depth.set(r, 0));
    const queue = seeds.slice();
    while (queue.length) {
      const key = queue.shift();
      const d = depth.get(key);
      (children.get(key) || []).forEach((to) => {
        if (depth.has(to)) return;
        depth.set(to, d + 1);
        queue.push(to);
      });
    }

    // Anything the walk never reached is unreachable — a finding, not a reason
    // to leave it off the picture. Orphans get their own column past the right
    // edge so they read as what they are: written, and off the map.
    let maxDepth = 0;
    depth.forEach((d) => { maxDepth = Math.max(maxDepth, d); });
    const orphanCol = maxDepth + 1;
    let orphans = 0;
    nodes.forEach((n) => {
      if (!depth.has(n.key)) { depth.set(n.key, orphanCol); orphans++; }
    });

    const columns = new Map();
    nodes.forEach((n) => {
      const d = depth.get(n.key);
      if (!columns.has(d)) columns.set(d, []);
      columns.get(d).push(n.key);
    });

    const order = new Map();
    columns.forEach((keys) => keys.forEach((k, i) => order.set(k, i)));
    const cols = [...columns.keys()].sort((a, b) => a - b);
    for (let pass = 0; pass < 4; pass++) {
      cols.forEach((d) => {
        const keys = columns.get(d);
        const bary = new Map();
        keys.forEach((k) => {
          const ps = (parents.get(k) || []).filter((p) => order.has(p) && depth.get(p) < d);
          bary.set(k, ps.length
            ? ps.reduce((s, p) => s + order.get(p), 0) / ps.length
            : order.get(k));
        });
        keys.sort((a, b) => bary.get(a) - bary.get(b) || a.localeCompare(b));
        keys.forEach((k, i) => order.set(k, i));
      });
    }

    // A column taller than this is folded into side-by-side sub-columns.
    //
    // Depth is not evenly populated: both of Ilse's ways in fan straight out, so
    // column 1 held 31 boxes and the chart came out 1620 wide by 3096 tall. In a
    // frame that is wide and short that fits by height, which shrinks everything
    // to a smear and leaves the width empty — the second half of "really far
    // apart". Folding trades unused width for height it can actually use.
    const MAX_ROWS = 14;

    const out = new Map();
    let height = 0;
    let slot = 0;
    cols.forEach((d) => {
      const keys = columns.get(d);
      const lanes = Math.max(1, Math.ceil(keys.length / MAX_ROWS));
      const perLane = Math.ceil(keys.length / lanes);
      keys.forEach((key, i) => {
        const lane = Math.floor(i / perLane);
        out.set(key, {
          x: PAD + (slot + lane) * COL_W,
          y: PAD + (i % perLane) * ROW_H,
          col: d,
          orphan: orphans > 0 && d === orphanCol,
        });
      });
      height = Math.max(height, Math.min(keys.length, perLane) * ROW_H);
      slot += lanes;
    });

    return {
      pos: out,
      width: PAD * 2 + Math.max(0, slot - 1) * COL_W + BOX_W,
      height: PAD * 2 + height,
      start: out.get(seeds[0]) || null,
    };
  }

  function nodeClass(n, place, roots, broken) {
    return [
      'dg-node',
      roots.includes(n.key) ? 'is-root' : '',
      place.orphan ? 'is-orphan' : '',
      broken.has(n.key) ? 'is-broken' : '',
      n.terminal ? 'is-terminal' : '',
      n.check ? 'is-check' : '',
      (n.pools && n.pools.length) ? 'is-pooled' : '',
    ].filter(Boolean).join(' ');
  }

  /**
   * The whole chart as one string.
   *
   * `data` is what `content/lint_dialogue` returns: nodes, edges, roots and
   * findings. Nothing is recomputed from the document here — where a
   * conversation can go is decided once, on the server, by the same code that
   * decides whether to refuse a save.
   */
  function svg(data) {
    const nodes = data.nodes || [];
    const edges = data.edges || [];
    const roots = data.roots || [];
    if (!nodes.length) {
      return '<p class="help-hint">No conversation yet.</p>';
    }

    const broken = new Set();
    (data.findings || []).forEach((f) => {
      const m = /^\$\.nodes\.([a-z0-9_]+)/.exec(f.where || '');
      if (m && f.level === 'error') broken.add(m[1]);
    });

    const { pos, width, height } = layout(nodes, edges, roots);

    const lines = edges.map((e) => {
      const a = pos.get(e.from);
      const b = pos.get(e.to);
      if (!a) return '';
      if (!b) {
        const stub = [
          '<line class="dg-edge is-dangling"',
          `x1="${a.x + BOX_W}" y1="${a.y + BOX_H / 2}"`,
          `x2="${a.x + BOX_W + 44}" y2="${a.y + BOX_H / 2}"/>`,
          `<text class="dg-dangling-label" x="${a.x + BOX_W + 48}" y="${a.y + BOX_H / 2 + 4}">`,
          `${esc(e.to)}?</text>`,
        ].join(' ');
        return stub;
      }
      const backwards = b.x <= a.x;
      const cls = ['dg-edge', backwards ? 'is-back' : ''].filter(Boolean).join(' ');
      const x1 = a.x + BOX_W;
      const y1 = a.y + BOX_H / 2;
      const x2 = b.x;
      const y2 = b.y + BOX_H / 2;
      const mid = (x1 + x2) / 2;
      return `<path class="${cls}" d="M ${x1} ${y1} C ${mid} ${y1}, ${mid} ${y2}, ${x2} ${y2}"/>`;
    }).join('');

    const boxes = nodes.map((n) => {
      const p = pos.get(n.key);
      const tip = [
        n.key,
        `${n.variants} variant${n.variants === 1 ? '' : 's'}, ${n.choices} repl${n.choices === 1 ? 'y' : 'ies'}`,
        n.pools && n.pools.length ? `pools: ${n.pools.join(', ')}` : '',
        n.text || '',
      ].filter(Boolean).join('\n');
      const badge = [
        n.variants > 1 ? `<text class="dg-badge" x="${BOX_W - 8}" y="16">${n.variants}×</text>` : '',
        n.check ? `<text class="dg-badge is-check" x="${BOX_W - 8}" y="${BOX_H - 8}">d20</text>` : '',
      ].join('');
      return [
        `<g class="${nodeClass(n, p, roots, broken)}" transform="translate(${p.x} ${p.y})"`,
        ` data-dg-node="${esc(n.key)}" role="button" tabindex="0">`,
        `<title>${esc(tip)}</title>`,
        `<rect class="dg-box" width="${BOX_W}" height="${BOX_H}" rx="4"/>`,
        `<text class="dg-key" x="10" y="20">${esc(n.key)}</text>`,
        `<text class="dg-text" x="10" y="38">${esc((n.text || '').slice(0, 30))}</text>`,
        badge,
        '</g>',
      ].join('');
    }).join('');

    return [
      `<svg class="dialoggraph" viewBox="0 0 ${width} ${height}"`,
      ` role="img" aria-label="Conversation tree for ${esc(data.name || data.npc_key || '')}">`,
      `<g class="dg-edges">${lines}</g>`,
      `<g class="dg-nodes">${boxes}</g>`,
      '</svg>',
    ].join('');
  }

  /**
   * Pan and zoom, by moving the viewBox.
   *
   * Lifted from ui-map.js:325 and kept deliberately close to it. A CSS scale
   * would need every click coordinate transformed back by hand; shrinking the
   * viewBox is a real zoom and the node hit areas come along for free.
   */
  function controls(root) {
    const svgEl = root.querySelector('svg.dialoggraph');
    if (!svgEl) return null;

    const vb = (svgEl.getAttribute('viewBox') || '0 0 100 100').split(/\s+/).map(Number);
    const BASE = { x: vb[0], y: vb[1], w: vb[2], h: vb[3] };
    const view = { z: 1, cx: BASE.x + BASE.w / 2, cy: BASE.y + BASE.h / 2 };
    const ZOOM_MIN = 0.3;
    const ZOOM_MAX = 4;
    const ZOOM_STEP = 1.3;
    const PAN_SLOP = 4;

    function apply() {
      const w = BASE.w / view.z;
      const h = BASE.h / view.z;
      svgEl.setAttribute('viewBox', `${view.cx - w / 2} ${view.cy - h / 2} ${w} ${h}`);
      svgEl.classList.toggle('is-zoomed', view.z > 1.001);
    }

    function clientToView(ev) {
      const r = svgEl.getBoundingClientRect();
      const b = svgEl.viewBox.baseVal;
      return {
        x: b.x + ((ev.clientX - r.left) / r.width) * b.width,
        y: b.y + ((ev.clientY - r.top) / r.height) * b.height,
      };
    }

    function zoomBy(factor, at) {
      const before = at ? clientToView(at) : null;
      view.z = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, view.z * factor));
      apply();
      if (before) {
        const after = clientToView(at);
        view.cx += before.x - after.x;
        view.cy += before.y - after.y;
        apply();
      }
    }

    svgEl.addEventListener('wheel', (ev) => {
      ev.preventDefault();
      zoomBy(ev.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP, ev);
    }, { passive: false });

    let dragging = null;
    svgEl.addEventListener('pointerdown', (ev) => {
      if (ev.button !== 0 && ev.button !== 1) return;
      if (ev.button === 0 && ev.target.closest('[data-dg-node]')) return;
      dragging = { x: ev.clientX, y: ev.clientY, moved: false };
      svgEl.setPointerCapture(ev.pointerId);
      svgEl.classList.add('is-panning');
    });
    svgEl.addEventListener('pointermove', (ev) => {
      if (!dragging) return;
      const dx = ev.clientX - dragging.x;
      const dy = ev.clientY - dragging.y;
      if (!dragging.moved && Math.abs(dx) + Math.abs(dy) < PAN_SLOP) return;
      dragging.moved = true;
      const r = svgEl.getBoundingClientRect();
      const b = svgEl.viewBox.baseVal;
      view.cx -= dx * (b.width / r.width);
      view.cy -= dy * (b.height / r.height);
      dragging.x = ev.clientX;
      dragging.y = ev.clientY;
      apply();
    });
    const release = (ev) => {
      if (!dragging) return;
      dragging = null;
      svgEl.classList.remove('is-panning');
      if (svgEl.hasPointerCapture && svgEl.hasPointerCapture(ev.pointerId)) {
        svgEl.releasePointerCapture(ev.pointerId);
      }
    };
    svgEl.addEventListener('pointerup', release);
    svgEl.addEventListener('pointercancel', release);
    svgEl.addEventListener('auxclick', (ev) => ev.preventDefault());

    apply();
    return {
      zoomIn: () => zoomBy(ZOOM_STEP, null),
      zoomOut: () => zoomBy(1 / ZOOM_STEP, null),
      reset: () => {
        view.z = 1;
        view.cx = BASE.x + BASE.w / 2;
        view.cy = BASE.y + BASE.h / 2;
        apply();
      },
      focus: (key) => {
        const g = svgEl.querySelector(`[data-dg-node="${window.CSS && CSS.escape ? CSS.escape(key) : key}"]`);
        if (!g) return;
        const m = /translate\(([-\d.]+)\s+([-\d.]+)\)/.exec(g.getAttribute('transform') || '');
        if (!m) return;
        view.z = Math.max(view.z, 1.4);
        view.cx = Number(m[1]) + BOX_W / 2;
        view.cy = Number(m[2]) + BOX_H / 2;
        apply();
      },
    };
  }

  window.DialogGraph = { svg, controls };
})();
