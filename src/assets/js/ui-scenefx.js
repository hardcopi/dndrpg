/*
 * Rivermark ambient scene effects.
 *
 * A thin layer over a painted still: smoke, fire, birds, water, fog, dust,
 * drips, rain, fireflies, candles, glows and swaying signs. It uses one
 * <canvas> per scene with one rAF loop, plus a few DOM tiles for "sway".
 * There are no images and no dependencies.
 *
 *   const fx = RivermarkFX.mount(sceneEl, [
 *     { type: 'smoke', x: 86, y: -8, w: 13, h: 17.5, intensity: 1, wind: 0.6 },
 *     { type: 'fire',  x: 90, y: 50, w: 8,  h: 15 },
 *   ], { image: 'bg.jpg' });
 *   fx.destroy();
 *
 * Every box (x, y, w, h) is a percentage of the scene element, so the layer
 * scales with the scene. The effects pause when the tab is hidden or the
 * scene scrolls off-screen. They are off under prefers-reduced-motion and when
 * RivermarkFX.setEnabled(false) is called; that choice is saved in
 * localStorage under "rm-fx".
 */
(function (root) {
  'use strict';

  const TAU = Math.PI * 2;
  const PREF = 'rm-fx';
  const live = new Set();

  /* ---------- helpers ---------- */

  function rng(seed) {
    let s = seed >>> 0 || 1;
    return function () {
      s = (s + 0x6D2B79F5) | 0;
      let t = Math.imul(s ^ (s >>> 15), 1 | s);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  const lerp = (a, b, k) => a + (b - a) * k;
  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);

  /* Three detuned sines: flicker that never visibly repeats. 0..1 */
  function flicker(t, ph, speed) {
    const s = speed || 1;
    return clamp(0.55 + 0.22 * Math.sin(t * 7.1 * s + ph) + 0.14 * Math.sin(t * 12.9 * s + ph * 1.7)
      + 0.09 * Math.sin(t * 23.3 * s + ph * 2.3), 0, 1);
  }

  const spriteCache = new Map();
  /* A soft radial blob tinted `color` (any CSS colour), cached by colour+hardness. */
  function blob(color, hard) {
    const key = color + '|' + (hard || 0);
    let c = spriteCache.get(key);
    if (c) return c;
    const n = 64;
    c = document.createElement('canvas');
    c.width = c.height = n;
    const g = c.getContext('2d');
    const gr = g.createRadialGradient(n / 2, n / 2, 0, n / 2, n / 2, n / 2);
    const h = hard || 0;
    gr.addColorStop(0, color);
    gr.addColorStop(clamp(0.25 + h * 0.5, 0, 0.95), color);
    gr.addColorStop(1, 'rgba(0,0,0,0)');
    g.globalAlpha = 1;
    g.fillStyle = gr;
    g.fillRect(0, 0, n, n);
    // fade the core into the edge for soft puffs
    if (!h) {
      g.globalCompositeOperation = 'destination-in';
      const m = g.createRadialGradient(n / 2, n / 2, 0, n / 2, n / 2, n / 2);
      m.addColorStop(0, 'rgba(0,0,0,1)');
      m.addColorStop(0.55, 'rgba(0,0,0,0.5)');
      m.addColorStop(1, 'rgba(0,0,0,0)');
      g.fillStyle = m;
      g.fillRect(0, 0, n, n);
    }
    spriteCache.set(key, c);
    return c;
  }

  function stamp(ctx, spr, x, y, w, h, a) {
    if (a <= 0.003) return;
    ctx.globalAlpha = a > 1 ? 1 : a;
    ctx.drawImage(spr, x - w / 2, y - h / 2, w, h);
  }

  /* ---------- effects ----------
   * Each factory(cfg, rand) returns { update(dt, t, B), draw(ctx, t, B) }.
   * B is the box in CSS px: { x, y, w, h, S } where S scales sizes to the
   * scene (1.0 at a 531px-tall scene, which is the mockup's size).
   */
  const FX = {};

  /* Rising smoke from the bottom-centre of the box. wind: -1..1 drift. */
  FX.smoke = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const col = c.color || 'rgb(205,198,190)';
    const spr = blob(col);
    const wind = c.wind == null ? 0.4 : c.wind;
    const rate = 5 * I + 1;
    const ps = [];
    let acc = 0;
    return {
      update(dt, t, B) {
        acc += dt * rate;
        while (acc >= 1) {
          acc -= 1;
          ps.push({ k: 0, life: 4 + r() * 2.5, ox: (r() - 0.5) * 0.12, ph: r() * TAU, sz: 0.8 + r() * 0.5, sp: 0.85 + r() * 0.3 });
        }
        for (let i = ps.length - 1; i >= 0; i--) {
          ps[i].k += dt / ps[i].life;
          if (ps[i].k >= 1) ps.splice(i, 1);
        }
      },
      draw(ctx, t, B) {
        ctx.globalCompositeOperation = 'source-over';
        for (const p of ps) {
          const k = p.k;
          const rise = Math.pow(k, 0.85) * p.sp;
          const x = B.x + B.w * (0.5 + p.ox + wind * 0.45 * k * k + 0.04 * Math.sin(t * 0.9 + p.ph) * k);
          const y = B.y + B.h * (1 - rise);
          const d = B.w * lerp(0.13, 0.55, k) * p.sz;
          const a = (k < 0.12 ? k / 0.12 : 1) * (1 - k) * (1 - k) * 0.7 * Math.min(1, I + 0.2);
          stamp(ctx, spr, x, y, d, d * 0.85, a);
        }
      },
    };
  };

  /* Fire, hearth, brazier, torch, candle: flickering glow, light spill, sparks. */
  function flame(preset) {
    return function (c, r) {
      const I = c.intensity == null ? 1 : c.intensity;
      const P = Object.assign({}, preset, c);
      const glowSpr = blob(P.glow);
      const coreSpr = blob(P.core, 0.2);
      const sparkSpr = blob(P.spark, 0.6);
      const ph = r() * 100;
      const lickSpr = blob(P.lick || P.glow, 0.1);
      const ps = [];
      const licks = [];
      let acc = 0, lacc = 0;
      return {
        update(dt, t, B) {
          // licks: short-lived tongues of flame that make it read as crackling
          lacc += dt * P.licks * I;
          while (lacc >= 1) {
            lacc -= 1;
            licks.push({ k: 0, life: 0.22 + r() * 0.25, x0: 0.3 + r() * 0.4, s: 0.7 + r() * 0.6, lean: (r() - 0.5) * 0.15 });
          }
          for (let i = licks.length - 1; i >= 0; i--) {
            licks[i].k += dt / licks[i].life;
            if (licks[i].k >= 1) licks.splice(i, 1);
          }
          acc += dt * P.sparks * I;
          while (acc >= 1) {
            acc -= 1;
            ps.push({ k: 0, life: 0.6 + r() * 1.1, x0: 0.25 + r() * 0.5, vx: (r() - 0.5) * 0.6, ph: r() * TAU, s: 0.6 + r() * 0.8 });
          }
          for (let i = ps.length - 1; i >= 0; i--) {
            ps[i].k += dt / ps[i].life;
            if (ps[i].k >= 1) ps.splice(i, 1);
          }
        },
        draw(ctx, t, B) {
          const f = flicker(t, ph, P.speed);
          const cx = B.x + B.w * 0.5;
          const cy = B.y + B.h * P.cy;
          const R = Math.max(B.w, B.h);
          ctx.globalCompositeOperation = 'lighter';
          // wide warm spill onto walls and floor
          stamp(ctx, glowSpr, cx, cy, R * P.spill, R * P.spill * 0.8, (0.10 + 0.10 * f) * I);
          // the bright heart of the fire
          stamp(ctx, glowSpr, cx + (f - 0.5) * B.w * 0.04, cy, R * 1.1, R * 1.0, (0.10 + 0.17 * f) * I);
          stamp(ctx, coreSpr, cx, cy + B.h * 0.05, B.w * 0.5, B.h * 0.4, (0.04 + 0.12 * f) * I * P.coreA);
          for (const l of licks) {
            const k = l.k;
            const x = B.x + B.w * (l.x0 + l.lean * k);
            const y = cy + B.h * (0.12 - P.lickRise * k);
            const d = B.w * 0.16 * l.s * (1 - k * 0.6);
            stamp(ctx, lickSpr, x, y, d * 0.6, d * 1.5, Math.sin(k * Math.PI) * 0.45 * I);
          }
          for (const p of ps) {
            const k = p.k;
            const x = B.x + B.w * (p.x0 + p.vx * k * 0.5 + 0.06 * Math.sin(t * 6 + p.ph) * k);
            const y = cy - B.h * (k * P.rise);
            const d = (2.4 * p.s) * B.S;
            const a = (1 - k) * (0.6 + 0.4 * Math.sin(t * 20 + p.ph)) * I;
            stamp(ctx, sparkSpr, x, y, d, d, a);
          }
          ctx.globalCompositeOperation = 'source-over';
        },
      };
    };
  }
  const WARM = { glow: 'rgb(255,140,40)', core: 'rgb(255,214,120)', spark: 'rgb(255,190,80)', lick: 'rgb(255,170,60)' };
  FX.fire = flame(Object.assign({ licks: 16, lickRise: 0.35, sparks: 9, rise: 1.4, spill: 3.2, cy: 0.72, speed: 1, coreA: 1 }, WARM));
  FX.hearth = flame(Object.assign({ licks: 10, lickRise: 0.3, sparks: 3, rise: 0.9, spill: 3.6, cy: 0.75, speed: 0.8, coreA: 0.8 }, WARM));
  FX.torch = flame(Object.assign({ licks: 8, lickRise: 0.4, sparks: 2, rise: 1.2, spill: 4.5, cy: 0.5, speed: 1.3, coreA: 1 }, WARM));
  FX.candle = flame({ licks: 0, lickRise: 0, sparks: 0, rise: 0, spill: 5, cy: 0.5, speed: 1.8, coreA: 1.2,
    glow: 'rgb(255,170,70)', core: 'rgb(255,236,170)', spark: 'rgb(255,220,140)' });

  /* A slow pulsing light: windows, magic, phosphor, a lamp. */
  FX.glow = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const spr = blob(c.color || 'rgb(255,190,110)');
    const ph = r() * TAU;
    const per = c.period || 4;
    return {
      update() {},
      draw(ctx, t, B) {
        const k = 0.6 + 0.4 * Math.sin(t * TAU / per + ph);
        ctx.globalCompositeOperation = 'lighter';
        stamp(ctx, spr, B.x + B.w / 2, B.y + B.h / 2, B.w, B.h, 0.35 * k * I);
        ctx.globalCompositeOperation = 'source-over';
      },
    };
  };

  /* Small birds that cross the box now and then, singly or in loose flocks. */
  FX.birds = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const col = c.color || 'rgba(40,26,22,0.85)';
    const flocks = [];
    let next = 0.5 + r() * 2;
    function spawn() {
      const dir = r() < 0.5 ? 1 : -1;
      const n = 1 + Math.floor(r() * (2 + 3 * I));
      const yy = 0.15 + r() * 0.7;
      const dur = 7 + r() * 6;
      const b = [];
      for (let i = 0; i < n; i++) {
        b.push({ dx: -i * (0.025 + r() * 0.03), dy: (r() - 0.5) * 0.25, ph: r() * TAU,
          f: 7 + r() * 3, s: 0.8 + r() * 0.5 });
      }
      flocks.push({ k: 0, dur, dir, yy, b, glide: r() * TAU });
    }
    return {
      update(dt) {
        next -= dt;
        if (next <= 0) { spawn(); next = (5 + r() * 9) / Math.max(0.2, I); }
        for (let i = flocks.length - 1; i >= 0; i--) {
          flocks[i].k += dt / flocks[i].dur;
          if (flocks[i].k > 1.25) flocks.splice(i, 1);
        }
      },
      draw(ctx, t, B) {
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1;
        ctx.strokeStyle = col;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        for (const F of flocks) {
          for (const b of F.b) {
            const u = F.k + b.dx;
            if (u < -0.05 || u > 1.05) continue;
            const px = F.dir > 0 ? u : 1 - u;
            const x = B.x + B.w * px;
            const y = B.y + B.h * (F.yy + b.dy + 0.05 * Math.sin(u * 9 + b.ph));
            const s = 4.2 * b.s * B.S;
            // flap in bursts, then glide
            const flapping = Math.sin(t * 0.9 + F.glide + b.ph * 0.3) > -0.3;
            const wing = flapping ? Math.sin(t * b.f + b.ph) : 0.35;
            const wy = wing * s * 0.75;
            const edge = Math.abs(px - 0.5) > 0.45 ? (0.5 - Math.abs(px - 0.5)) / 0.05 + 1 : 1;
            ctx.globalAlpha = clamp(edge, 0, 1);
            ctx.lineWidth = Math.max(1, s * 0.28);
            ctx.beginPath();
            ctx.moveTo(x - s, y - wy);
            ctx.quadraticCurveTo(x - s * 0.45, y - wy * 0.2 - s * 0.25, x, y);
            ctx.quadraticCurveTo(x + s * 0.45, y - wy * 0.2 - s * 0.25, x + s, y - wy);
            ctx.stroke();
          }
        }
        ctx.globalAlpha = 1;
      },
    };
  };

  /* Water shimmer: little horizontal glints that bloom and die, plus faint drifting bands. */
  FX.water = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const col = c.color || 'rgb(255,236,190)';
    const n = Math.round((c.count || 40) * I);
    const gl = [];
    const reset = (g) => { g.x = r(); g.y = r(); g.l = 0.6 + r() * 1.4; };
    for (let i = 0; i < n; i++) { const g = { per: 1.4 + r() * 2.2, ph: r() }; reset(g); gl.push(g); }
    return {
      update(dt) {
        for (const g of gl) {
          const prev = g.ph;
          g.ph = (g.ph + dt / g.per) % 1;
          if (g.ph < prev) reset(g);   // new spot each cycle
        }
      },
      draw(ctx, t, B) {
        ctx.globalCompositeOperation = 'lighter';
        ctx.strokeStyle = col;
        ctx.lineCap = 'round';
        // drifting light bands
        for (let i = 0; i < 3; i++) {
          const yy = B.y + B.h * ((i + 0.5) / 3 + 0.06 * Math.sin(t * 0.4 + i * 2));
          ctx.globalAlpha = 0.05 * I * (0.6 + 0.4 * Math.sin(t * 0.7 + i));
          ctx.lineWidth = Math.max(1, B.h * 0.05);
          ctx.beginPath();
          ctx.moveTo(B.x, yy);
          for (let k = 1; k <= 8; k++) ctx.lineTo(B.x + B.w * k / 8, yy + Math.sin(t * 1.3 + k * 1.7 + i) * B.h * 0.03);
          ctx.stroke();
        }
        for (const g of gl) {
          const a = Math.pow(Math.sin(g.ph * Math.PI), 4) * 0.85 * I;
          if (a < 0.01) continue;
          const persp = 0.5 + 0.5 * g.y; // nearer water, longer glints
          const L = g.l * 5 * persp * B.S;
          const x = B.x + B.w * g.x;
          const y = B.y + B.h * g.y;
          ctx.globalAlpha = a;
          ctx.lineWidth = Math.max(1, 1.3 * persp * B.S);
          ctx.beginPath();
          ctx.moveTo(x - L, y);
          ctx.lineTo(x + L, y);
          ctx.stroke();
        }
        ctx.globalAlpha = 1;
        ctx.globalCompositeOperation = 'source-over';
      },
    };
  };

  /* Fog / mist: large soft banks drifting sideways and breathing. */
  FX.fog = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const spr = blob(c.color || 'rgb(214,220,212)');
    const n = c.count || 9;
    const speed = c.speed == null ? 1 : c.speed;
    const banks = [];
    for (let i = 0; i < n; i++) {
      banks.push({ x: r() * 1.4 - 0.2, y: 0.2 + r() * 0.65, w: 0.35 + r() * 0.45, h: 0.25 + r() * 0.35,
        v: (0.006 + r() * 0.01) * speed * (c.wind == null ? 1 : Math.sign(c.wind) || 1), ph: r() * TAU, a: 0.5 + r() * 0.5 });
    }
    return {
      update(dt) {
        for (const b of banks) {
          b.x += b.v * dt;
          if (b.x > 1.3) b.x = -0.3;
          if (b.x < -0.3) b.x = 1.3;
        }
      },
      draw(ctx, t, B) {
        ctx.globalCompositeOperation = 'source-over';
        for (const b of banks) {
          const a = 0.22 * I * b.a * (0.75 + 0.25 * Math.sin(t * 0.25 + b.ph));
          stamp(ctx, spr, B.x + B.w * b.x, B.y + B.h * b.y, B.w * b.w, B.h * b.h, a);
        }
      },
    };
  };

  /* Dust motes, with an optional slanted light shaft (shaft: true, angle in degrees). */
  FX.dust = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const col = c.color || 'rgb(255,226,170)';
    const spr = blob(col, 0.5);
    const n = Math.round((c.count || 35) * I);
    const ms = [];
    for (let i = 0; i < n; i++) ms.push({ x: r(), y: r(), vx: (r() - 0.5) * 0.01, vy: (r() - 0.3) * 0.008, ph: r() * TAU, s: 0.6 + r() * 1 });
    const slant = Math.tan(((c.angle == null ? 18 : c.angle) * Math.PI) / 180);
    return {
      update(dt, t) {
        for (const m of ms) {
          m.x += (m.vx + 0.004 * Math.sin(t * 0.5 + m.ph)) * dt;
          m.y += (m.vy + 0.003 * Math.cos(t * 0.4 + m.ph)) * dt;
          if (m.x < 0) m.x += 1; if (m.x > 1) m.x -= 1;
          if (m.y < 0) m.y += 1; if (m.y > 1) m.y -= 1;
        }
      },
      draw(ctx, t, B) {
        ctx.globalCompositeOperation = 'lighter';
        if (c.shaft) {
          const off = B.h * slant;
          const g = ctx.createLinearGradient(B.x, B.y, B.x, B.y + B.h);
          g.addColorStop(0, 'rgba(255,225,160,0.22)');
          g.addColorStop(1, 'rgba(255,225,160,0)');
          ctx.globalAlpha = I * (0.75 + 0.25 * Math.sin(t * 0.3));
          ctx.fillStyle = g;
          ctx.beginPath();
          ctx.moveTo(B.x + B.w * 0.25, B.y);
          ctx.lineTo(B.x + B.w * 0.6, B.y);
          ctx.lineTo(B.x + B.w * 0.6 + off * 0.5, B.y + B.h);
          ctx.lineTo(B.x + B.w * 0.1 + off * 0.5, B.y + B.h);
          ctx.closePath();
          ctx.fill();
        }
        for (const m of ms) {
          const tw = 0.5 + 0.5 * Math.sin(t * 1.7 + m.ph);
          const d = 2.2 * m.s * B.S;
          stamp(ctx, spr, B.x + B.w * m.x, B.y + B.h * m.y, d, d, 0.55 * tw * I);
        }
        ctx.globalAlpha = 1;
        ctx.globalCompositeOperation = 'source-over';
      },
    };
  };

  /* Drips: drops form on the top edge, fall, and ripple on the bottom edge. */
  FX.drip = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const col = c.color || 'rgb(200,220,235)';
    const n = c.count || 3;
    const src = [];
    for (let i = 0; i < n; i++) src.push({ x: 0.1 + 0.8 * (n === 1 ? 0.5 : i / (n - 1)) + (r() - 0.5) * 0.1, k: -r() * 2, per: (1.6 + r() * 2.5) / Math.max(0.3, I) });
    const FORM = 0.8, FALL = 0.45, RIP = 0.7;
    return {
      update(dt) { for (const s of src) { s.k += dt; if (s.k > s.per + FORM + FALL + RIP) s.k = 0; } },
      draw(ctx, t, B) {
        ctx.globalCompositeOperation = 'source-over';
        ctx.strokeStyle = ctx.fillStyle = col;
        for (const s of src) {
          const x = B.x + B.w * s.x;
          const k = s.k;
          if (k < 0) continue;
          const d = 1.6 * B.S;
          if (k < FORM) {                         // swelling bead
            ctx.globalAlpha = 0.7 * (k / FORM);
            ctx.beginPath(); ctx.arc(x, B.y + d, d * (0.5 + 0.5 * k / FORM), 0, TAU); ctx.fill();
          } else if (k < FORM + FALL) {           // falling, accelerating
            const u = (k - FORM) / FALL;
            const y = B.y + B.h * u * u;
            ctx.globalAlpha = 0.75;
            ctx.lineWidth = d;
            ctx.beginPath(); ctx.moveTo(x, y - d * 3); ctx.lineTo(x, y); ctx.stroke();
          } else if (k < FORM + FALL + RIP) {     // ripple
            const u = (k - FORM - FALL) / RIP;
            ctx.globalAlpha = 0.6 * (1 - u);
            ctx.lineWidth = Math.max(1, 0.8 * B.S);
            ctx.beginPath(); ctx.ellipse(x, B.y + B.h, 2 + u * 12 * B.S, 0.6 + u * 3 * B.S, 0, 0, TAU); ctx.stroke();
          }
        }
        ctx.globalAlpha = 1;
      },
    };
  };

  /* Rain streaks. wind tilts them (-1..1). */
  FX.rain = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const n = Math.round((c.count || 140) * I);
    const wind = c.wind == null ? 0.25 : c.wind;
    const drops = [];
    for (let i = 0; i < n; i++) drops.push({ x: r(), y: r(), v: 0.9 + r() * 0.5, l: 0.6 + r() * 0.6 });
    return {
      update(dt) {
        for (const d of drops) {
          d.y += d.v * dt * 1.6;
          d.x += wind * d.v * dt * 0.5;
          if (d.y > 1) { d.y -= 1; d.x = r(); }
          if (d.x > 1) d.x -= 1; if (d.x < 0) d.x += 1;
        }
      },
      draw(ctx, t, B) {
        ctx.globalCompositeOperation = 'source-over';
        ctx.strokeStyle = c.color || 'rgba(200,210,225,1)';
        ctx.lineWidth = Math.max(1, 0.9 * B.S);
        ctx.globalAlpha = 0.28 * Math.min(1, I + 0.3);
        ctx.beginPath();
        for (const d of drops) {
          const x = B.x + B.w * d.x, y = B.y + B.h * d.y, L = 14 * d.l * B.S;
          ctx.moveTo(x, y);
          ctx.lineTo(x - wind * L * 0.5, y - L);
        }
        ctx.stroke();
        ctx.globalAlpha = 1;
      },
    };
  };

  /* Fireflies: wandering, blinking motes of green-gold light. */
  FX.fireflies = function (c, r) {
    const I = c.intensity == null ? 1 : c.intensity;
    const spr = blob(c.color || 'rgb(210,255,120)', 0.15);
    const n = Math.round((c.count || 14) * I);
    const fl = [];
    for (let i = 0; i < n; i++) fl.push({ x: r(), y: r(), a: r() * TAU, sp: 0.02 + r() * 0.03, ph: r() * TAU, per: 2.5 + r() * 3 });
    return {
      update(dt, t) {
        for (const f of fl) {
          f.a += (Math.sin(t * 0.7 + f.ph) * 1.5) * dt;
          f.x = clamp(f.x + Math.cos(f.a) * f.sp * dt, 0, 1);
          f.y = clamp(f.y + Math.sin(f.a) * f.sp * dt * 0.7, 0, 1);
          if (f.x <= 0 || f.x >= 1 || f.y <= 0 || f.y >= 1) f.a += Math.PI;
        }
      },
      draw(ctx, t, B) {
        ctx.globalCompositeOperation = 'lighter';
        for (const f of fl) {
          const b = Math.max(0, Math.sin((t / f.per) * TAU + f.ph));
          const a = Math.pow(b, 3) * I;
          const x = B.x + B.w * f.x, y = B.y + B.h * f.y;
          stamp(ctx, spr, x, y, 14 * B.S, 14 * B.S, a * 0.5);
          stamp(ctx, spr, x, y, 4 * B.S, 4 * B.S, a);
        }
        ctx.globalCompositeOperation = 'source-over';
      },
    };
  };

  /*
   * Sway: lifts a rectangle of the painting (a hanging sign, a lantern, a
   * banner) into its own tile and rocks it about a pivot. The tile is the same
   * background image positioned to line up exactly, so it costs no extra art.
   * It assumes the scene image fills the box (no object-fit crop).
   * pivot: [x%, y%] inside the box (default top-centre). amp: degrees. period: s.
   */
  function makeSway(c, r, host, image) {
    const el = document.createElement('div');
    el.className = 'rmfx-sway';
    const w = c.w, h = c.h;
    Object.assign(el.style, {
      left: c.x + '%', top: c.y + '%', width: w + '%', height: h + '%',
      backgroundImage: `url("${c.image || image}")`,
      backgroundSize: `${(100 * 100) / w}% ${(100 * 100) / h}%`,
      backgroundPosition: `${w >= 100 ? 0 : (c.x / (100 - w)) * 100}% ${h >= 100 ? 0 : (c.y / (100 - h)) * 100}%`,
      transformOrigin: `${(c.pivot || [50, 0])[0]}% ${(c.pivot || [50, 0])[1]}%`,
    });
    if (typeof c.clip === 'string') el.style.clipPath = c.clip;   // CSS clip-path, in tile space
    host.appendChild(el);
    const amp = (c.amp == null ? 1.5 : c.amp) * (c.intensity == null ? 1 : c.intensity);
    const per = c.period || 3.5;
    const ph = r() * TAU;
    return {
      el,
      set(t) {
        const a = amp * (Math.sin((t * TAU) / per + ph) * 0.8 + Math.sin((t * TAU) / (per * 2.7) + ph * 2) * 0.2);
        el.style.transform = `rotate(${a.toFixed(3)}deg)`;
      },
      rest() { el.style.transform = ''; },
    };
  }

  /* ---------- the layer ---------- */

  const reduceMQ = root.matchMedia ? root.matchMedia('(prefers-reduced-motion: reduce)') : null;
  function prefOn() {
    try { return root.localStorage.getItem(PREF) !== 'off'; } catch (e) { return true; }
  }
  function allowed() { return prefOn() && !(reduceMQ && reduceMQ.matches); }

  function mount(host, effects, opts) {
    opts = opts || {};
    const r = rng(opts.seed || 1234567);
    const fps = opts.fps || 30;
    if (getComputedStyle(host).position === 'static') host.style.position = 'relative';

    const layer = document.createElement('div');
    layer.className = 'rmfx-layer';
    const canvas = document.createElement('canvas');
    canvas.className = 'rmfx-canvas';
    // Straight after the painting, so hotspots added later still sit on top.
    const img = host.querySelector(':scope > img');
    host.insertBefore(layer, opts.before || (img ? img.nextSibling : null));
    const ctx = canvas.getContext('2d');

    const image = opts.image || (host.querySelector('img') && host.querySelector('img').src) || '';
    const items = [];
    const sways = [];
    for (const c of effects || []) {
      if (c.type === 'sway') { sways.push(makeSway(c, r, layer, image)); continue; }
      const f = FX[c.type];
      if (!f) { if (root.console) console.warn('RivermarkFX: unknown effect', c.type); continue; }
      items.push({ c, fx: f(c, r) });
    }
    layer.appendChild(canvas);   // canvas above the sway tiles

    let W = 0, H = 0, dpr = 1;
    function resize() {
      const b = host.getBoundingClientRect();
      dpr = Math.min(opts.dpr || root.devicePixelRatio || 1, 1.5);
      W = Math.max(1, Math.round(b.width));
      H = Math.max(1, Math.round(b.height));
      canvas.width = Math.round(W * dpr);
      canvas.height = Math.round(H * dpr);
    }
    resize();
    let ro = null;
    if (root.ResizeObserver) { ro = new ResizeObserver(resize); ro.observe(host); }

    let t = 0;
    function box(c) {
      return { x: (c.x / 100) * W, y: (c.y / 100) * H, w: (c.w / 100) * W, h: (c.h / 100) * H, S: H / 531 };
    }
    function step(dt) {
      t += dt;
      for (const it of items) it.fx.update(dt, t, box(it.c));
    }
    function draw() {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      for (const it of items) {
        const B = box(it.c);
        ctx.save();
        if (Array.isArray(it.c.clip)) {   // polygon of [x%, y%] scene points
          ctx.beginPath();
          it.c.clip.forEach(([px, py], i) => (i ? ctx.lineTo : ctx.moveTo).call(ctx, (px / 100) * W, (py / 100) * H));
          ctx.closePath();
          ctx.clip();
        }
        it.fx.draw(ctx, t, B);
        ctx.restore();
      }
      for (const s of sways) s.set(t);
    }

    // warm up so smoke is already rising and birds already out on arrival
    for (let i = 0, n = (opts.prewarm == null ? 6 : opts.prewarm) * 15; i < n; i++) step(1 / 15);

    let raf = 0, last = 0, visible = true, running = false;
    function frame(now) {
      raf = root.requestAnimationFrame(frame);
      if (!last) last = now;
      const el = now - last;
      if (el < 1000 / fps - 2) return;
      last = now;
      step(Math.min(el / 1000, 0.1));
      draw();
    }
    function play() {
      if (opts.manual || running || !visible || !allowed() || document.hidden) return;
      running = true; last = 0; layer.hidden = false;
      raf = root.requestAnimationFrame(frame);
    }
    function pause() {
      running = false;
      if (raf) root.cancelAnimationFrame(raf);
      raf = 0;
    }
    function sync() {
      if (!allowed()) {
        pause();
        layer.hidden = true;
        sways.forEach((s) => s.rest());
        return;
      }
      layer.hidden = false;
      if (document.hidden || !visible) pause(); else play();
    }
    let io = null;
    if (root.IntersectionObserver && !opts.manual) {
      io = new IntersectionObserver((es) => { visible = es[0].isIntersecting; sync(); });
      io.observe(host);
    }
    const ctl = {
      play, pause, sync,
      /* Advance by dt seconds and draw: for tests and frame-exact capture. */
      step(dt) { step(dt); draw(); },
      destroy() {
        pause();
        live.delete(ctl);
        if (ro) ro.disconnect();
        if (io) io.disconnect();
        layer.remove();
      },
    };
    live.add(ctl);
    if (allowed()) { draw(); play(); } else sync();
    return ctl;
  }

  function syncAll() { live.forEach((c) => c.sync()); }
  document.addEventListener('visibilitychange', syncAll);
  if (reduceMQ) (reduceMQ.addEventListener ? reduceMQ.addEventListener('change', syncAll) : reduceMQ.addListener(syncAll));

  root.RivermarkFX = {
    mount,
    effects: FX,
    types: Object.keys(FX).concat('sway'),
    enabled: allowed,
    setEnabled(on) {
      try { root.localStorage.setItem(PREF, on ? 'on' : 'off'); } catch (e) { /* private mode */ }
      syncAll();
    },
  };
})(window);
