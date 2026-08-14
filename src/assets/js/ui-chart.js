/**
 * The studio's town and wilderness desk.
 *
 * Click parchment → a place. Drag the name → map_x / map_y. Click two places
 * with Road → a pair of exits. The drawing is window.WorldMap.svg, the same
 * renderer the game uses. The plate is scenery; the nodes are the map.
 */
(function () {
  'use strict';

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  function svgPoint(svg, ev) {
    const pt = svg.createSVGPoint();
    pt.x = ev.clientX;
    pt.y = ev.clientY;
    const ctm = svg.getScreenCTM();
    if (!ctm) return null;
    const p = pt.matrixTransform(ctm.inverse());
    return { x: p.x, y: p.y };
  }

  function clamp(n, lo, hi) {
    return Math.max(lo, Math.min(hi, n));
  }

  /**
   * @param {Element} host
   * @param {{
   *   chart: object,
   *   prefix: string,
   *   onSelect?: (node: object) => void,
   *   onCreate?: (pos: {x:number,y:number}) => void,
   *   onMove?: (node: object, pos: {x:number,y:number}) => void,
   *   onLink?: (from: object, to: object) => void,
   *   onPlate?: (dataUrl: string) => void,
   * }} opts
   */
  function mount(host, opts) {
    if (!host) return { destroy() {} };
    const state = { tool: 'select', from: null, drag: null };

    function paint() {
      host.innerHTML = `
        <div class="st-chart">
          <div class="st-plan-tools" role="toolbar" aria-label="Chart tools">
            <button type="button" class="btn st-plan-tool" data-tool="select">Select</button>
            <button type="button" class="btn st-plan-tool" data-tool="place">Place</button>
            <button type="button" class="btn st-plan-tool" data-tool="road">Road</button>
          </div>
          <p class="help-hint st-plan-hint">Click a name to open the scene.
            Place puts a new spot on the parchment. Road joins two places.</p>
          <div class="loc-map is-bare" id="st-chart-map"></div>
          ${opts.onPlate ? `
            <label class="st-upload">Region plate (PNG, 4:3)
              <input type="file" id="st-plate" accept="image/png,image/jpeg">
            </label>` : ''}
        </div>`;

      host.querySelectorAll('[data-tool]').forEach((btn) => {
        btn.classList.toggle('is-on', btn.getAttribute('data-tool') === state.tool);
        btn.addEventListener('click', () => {
          state.tool = btn.getAttribute('data-tool');
          state.from = null;
          paint();
        });
      });

      const map = host.querySelector('#st-chart-map');
      if (map && window.WorldMap && opts.chart) {
        map.innerHTML = window.WorldMap.svg(opts.chart, {});
        if (window.WorldMap.controls) window.WorldMap.controls(map, null, 1.1);
        bindMap(map);
      }

      const plate = host.querySelector('#st-plate');
      if (plate && opts.onPlate) {
        plate.addEventListener('change', () => {
          const file = plate.files && plate.files[0];
          if (!file) return;
          const reader = new FileReader();
          reader.onload = () => opts.onPlate(String(reader.result || ''));
          reader.readAsDataURL(file);
        });
      }
    }

    function bindMap(map) {
      const svg = map.querySelector('svg');
      if (!svg) return;

      svg.addEventListener('pointerdown', (ev) => {
        if (ev.button != null && ev.button !== 0) return;
        const nodeEl = ev.target.closest('[data-map-node]');
        const id = nodeEl ? Number(nodeEl.getAttribute('data-map-node')) : null;
        const node = id != null
          ? (opts.chart.nodes || []).find((n) => Number(n.id) === id)
          : null;

        if (state.tool === 'place' && !node) {
          const p = svgPoint(svg, ev);
          if (p && opts.onCreate) opts.onCreate({ x: clamp(p.x, 0, 100), y: clamp(p.y, 0, 75) });
          return;
        }

        if (state.tool === 'road' && node) {
          if (state.from == null) {
            state.from = node;
          } else if (Number(state.from.id) !== Number(node.id) && opts.onLink) {
            opts.onLink(state.from, node);
            state.from = null;
          }
          return;
        }

        if (state.tool === 'select' && node) {
          state.drag = {
            id: node.id,
            x0: ev.clientX,
            y0: ev.clientY,
            moved: false,
          };
          svg.setPointerCapture(ev.pointerId);
          if (opts.onSelect) opts.onSelect(node);
        }
      });

      svg.addEventListener('pointermove', (ev) => {
        if (!state.drag) return;
        const dx = ev.clientX - state.drag.x0;
        const dy = ev.clientY - state.drag.y0;
        if (Math.hypot(dx, dy) > 4) state.drag.moved = true;
        if (!state.drag.moved) return;
        const node = (opts.chart.nodes || []).find((n) => Number(n.id) === state.drag.id);
        const g = svg.querySelector(`[data-map-node="${state.drag.id}"]`);
        const p = svgPoint(svg, ev);
        if (!node || !g || !p) return;
        node.x = clamp(p.x, 0, 100);
        node.y = clamp(p.y, 0, 75);
        g.setAttribute('transform', `translate(${node.x} ${node.y})`);
      });

      const endDrag = (ev) => {
        if (!state.drag) return;
        const drag = state.drag;
        state.drag = null;
        if (!drag.moved) return;
        const node = (opts.chart.nodes || []).find((n) => Number(n.id) === drag.id);
        if (node && opts.onMove) opts.onMove(node, { x: node.x, y: node.y });
      };
      svg.addEventListener('pointerup', endDrag);
      svg.addEventListener('pointercancel', endDrag);
    }

    paint();
    return {
      refresh(chart) {
        opts.chart = chart;
        paint();
      },
      destroy() { host.innerHTML = ''; },
    };
  }

  window.ChartDesk = { mount };
})();
