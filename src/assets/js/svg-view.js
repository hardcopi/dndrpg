/**
 * Pan and zoom for an inline SVG, by moving its viewBox.
 *
 * Extracted from ui-map.js when the battlefield wanted the same thing, which
 * is what ui-battlemap.js's header said to do rather than copy it a third
 * time. Nothing here knows what it is looking at: no nodes, no cells, no
 * tokens. It moves a rectangle over a drawing and tells the caller when.
 *
 * VIEWBOX AND NOT A CSS TRANSFORM, which is the one decision worth restating.
 * The charts are vector: shrinking the viewBox is a real zoom, so the ink
 * stays crisp at any magnification and — the part that matters — the hit areas
 * move with it for free. A CSS scale would have needed every click coordinate
 * transformed back by hand, in two callers with different geometry.
 *
 * Listeners are bound to the SVG element passed in and to nothing else, so a
 * caller that throws its SVG away and draws a new one leaks nothing: it calls
 * this again on the new element and hands back the view it saved. That is how
 * the combat board survives a redraw on every action.
 *
 *   const view = SvgView(svgEl, {
 *     base: { x: 0, y: 0, w: 16, h: 12 },   // the whole drawing, unzoomed
 *     start: { z: 1, cx: 8, cy: 6 },        // where to open
 *     canPan: (ev) => ev.button === 1,      // which drags are pans
 *     onApply: (v) => { ... },              // after every move
 *   });
 */
(function () {
  /** Movement beyond this (in screen px) is a pan, not a mis-click. */
  const PAN_SLOP = 4;

  function SvgView(svgEl, opts) {
    if (!svgEl) return null;
    const o = opts || {};
    const base = o.base;
    const zoomMin = o.zoomMin ?? 1;
    const zoomMax = o.zoomMax ?? 6;
    const zoomStep = o.zoomStep ?? 1.35;
    const slop = o.panSlop ?? PAN_SLOP;
    const canPan = o.canPan || ((ev) => ev.button === 0 || ev.button === 1);
    const onApply = o.onApply || (() => {});

    const view = {
      z: o.start?.z ?? zoomMin,
      cx: o.start?.cx ?? base.x + base.w / 2,
      cy: o.start?.cy ?? base.y + base.h / 2,
    };
    // Whether the last pointer sequence moved far enough to be a pan. Read and
    // cleared by the caller's click handler — a drag that ends over a cell must
    // not also count as having clicked it.
    let panned = false;

    function apply() {
      const w = base.w / view.z;
      const h = base.h / view.z;
      // Keep the view inside the drawing. At zoom 1 that pins it to the middle,
      // which is why the clamp is expressed as a range rather than a limit.
      const halfW = w / 2;
      const halfH = h / 2;
      view.cx = Math.min(Math.max(view.cx, base.x + halfW), base.x + base.w - halfW);
      view.cy = Math.min(Math.max(view.cy, base.y + halfH), base.y + base.h - halfH);
      svgEl.setAttribute('viewBox', `${view.cx - halfW} ${view.cy - halfH} ${w} ${h}`);
      onApply(view);
    }

    /** A screen point in viewBox units. */
    function clientToView(clientX, clientY) {
      const r = svgEl.getBoundingClientRect();
      if (!r.width || !r.height) return null;
      const vb = svgEl.viewBox.baseVal;
      return {
        x: vb.x + ((clientX - r.left) / r.width) * vb.width,
        y: vb.y + ((clientY - r.top) / r.height) * vb.height,
      };
    }

    /**
     * Zoom, keeping a point fixed under the cursor.
     *
     * Without the anchor, zooming always pulls toward the middle and the thing
     * you were looking at slides away — which is the difference between a map
     * you can read and one you fight.
     */
    function zoomAt(factor, clientX, clientY) {
      const before = clientToView(clientX, clientY);
      view.z = Math.min(Math.max(view.z * factor, zoomMin), zoomMax);
      apply();
      if (before) {
        const after = clientToView(clientX, clientY);
        if (after) {
          view.cx += before.x - after.x;
          view.cy += before.y - after.y;
          apply();
        }
      }
    }

    function zoomBy(factor) {
      const r = svgEl.getBoundingClientRect();
      zoomAt(factor, r.left + r.width / 2, r.top + r.height / 2);
    }

    // --- wheel ---------------------------------------------------------------
    svgEl.addEventListener('wheel', (e) => {
      e.preventDefault();
      zoomAt(e.deltaY < 0 ? zoomStep : 1 / zoomStep, e.clientX, e.clientY);
    }, { passive: false });

    // --- drag to pan ---------------------------------------------------------
    let panning = null;
    svgEl.addEventListener('pointerdown', (e) => {
      if (!canPan(e)) return;
      panned = false;
      panning = {
        x: e.clientX, y: e.clientY, ox: e.clientX, oy: e.clientY,
        moved: false, pointerId: e.pointerId,
      };
      // Nothing is captured and nothing is prevented yet. Both wait for the
      // slop below, and the waiting is the whole point:
      //
      // A captured pointer retargets its compatibility mouse events, `click`
      // included, at the element holding the capture. Capturing here — on the
      // press, before anyone knows whether this is a drag — meant every click
      // on the drawing arrived at the <svg> instead of at the cell under the
      // cursor, so `closest('[data-bm-cell]')` found nothing and the board
      // stopped taking move orders. It went unnoticed while only Space and the
      // middle button could pan, because neither of those is how you click a
      // square.
      //
      // preventDefault waits for the same moment for a smaller reason: on the
      // press it also eats the focus, and a board you cannot focus is a board
      // you cannot then drive from the keyboard.
    });
    svgEl.addEventListener('pointermove', (e) => {
      if (!panning || e.pointerId !== panning.pointerId) return;
      const dx = e.clientX - panning.x;
      const dy = e.clientY - panning.y;
      if (!panning.moved) {
        if (Math.hypot(e.clientX - panning.ox, e.clientY - panning.oy) < slop) return;
        panning.moved = true;
        panned = true;
        // Now it is a drag, so take the pointer: capture is what lets it carry
        // on when the cursor leaves the drawing. Guarded for the same reason
        // the release is — a pointer id the browser has already forgotten
        // throws, and a throw here would leave a half-started pan with no way
        // to end it.
        try { svgEl.setPointerCapture(e.pointerId); } catch (_) { /* not ours */ }
        svgEl.classList.add('is-panning');
      }
      // Stops the drag turning into a text selection or a native image drag.
      e.preventDefault();
      const r = svgEl.getBoundingClientRect();
      const vb = svgEl.viewBox.baseVal;
      view.cx -= (dx / r.width) * vb.width;
      view.cy -= (dy / r.height) * vb.height;
      panning.x = e.clientX;
      panning.y = e.clientY;
      apply();
    });
    const endPan = (e) => {
      if (!panning || (e && e.pointerId !== panning.pointerId)) return;
      const was = panning;
      panning = null;
      try { svgEl.releasePointerCapture(was.pointerId); } catch (_) { /* already gone */ }
      svgEl.classList.remove('is-panning');
    };
    svgEl.addEventListener('pointerup', endPan);
    svgEl.addEventListener('pointercancel', endPan);

    /**
     * Eat the click a drag leaves behind.
     *
     * A pan ends with a `click` fired wherever the pointer came to rest, and
     * whatever is under there did not ask to be clicked. This used to be
     * prevented by accident — the capture on the press retargeted that click
     * at the <svg>, where the callers' handlers looked for a cell or a place
     * name, found neither, and did nothing. Capturing on the press is what
     * broke clicking, so with that gone the guard has to be deliberate.
     *
     * Capture phase, and registered before the callers add theirs, so it runs
     * ahead of both a handler on this element and a delegated one further up.
     * `consumePan()` stays part of the interface for callers that would rather
     * ask than be protected; it simply has nothing left to report by the time
     * they get here.
     */
    svgEl.addEventListener('click', (e) => {
      if (!panned) return;
      panned = false;
      e.stopPropagation();
      e.preventDefault();
    }, true);
    // Chrome opens its autoscroll widget on middle-click unless this is eaten.
    svgEl.addEventListener('auxclick', (e) => { if (e.button === 1) e.preventDefault(); });

    apply();

    return {
      zoomIn: () => zoomBy(zoomStep),
      zoomOut: () => zoomBy(1 / zoomStep),
      zoomAt,
      /** Fit the whole drawing. The home button, not the open state. */
      reset() {
        view.z = zoomMin;
        view.cx = base.x + base.w / 2;
        view.cy = base.y + base.h / 2;
        apply();
      },
      /** Put a point in the middle of the view, clamped to the drawing as ever. */
      centerOn(x, y) { view.cx = x; view.cy = y; apply(); },
      /** Zoomed in at all, or fitted. */
      zoomed: () => view.z > zoomMin * 1.001,
      /** The live view, for a caller that wants to save and restore it. */
      state: () => ({ z: view.z, cx: view.cx, cy: view.cy }),
      /** Did the last pointer sequence pan? Asking clears it. */
      consumePan() { const was = panned; panned = false; return was; },
    };
  }

  window.SvgView = SvgView;
})();
