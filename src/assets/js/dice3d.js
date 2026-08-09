/**
 * Throwing the CSS 3D dice.
 *
 * The geometry is generated (assets/css/dice.css for the faces, dice-geometry.js
 * for the resting orientations); this is the part that decides how a die gets
 * from wherever it is to the face the server already chose.
 *
 * The one idea worth understanding: nothing here decides anything. `land()` is
 * told the value. Every dice UI has to solve "make it look thrown but finish on a
 * predetermined number", and the trick is that a rest orientation is a plain
 * triple of Euler angles, so a throw is that triple plus some whole turns. The
 * turns have to go in as numbers on rotateX/rotateY/rotateZ rather than as a
 * matrix, because CSS interpolates two matrices by decomposing to quaternions and
 * taking the shortest arc — which discards them, and the die pivots sedately
 * instead of tumbling.
 */
(function () {
  const GEO = () => window.DiceGeometry;

  /** How far a throw tumbles, in whole turns, before settling. */
  const SPIN_TURNS = { x: 2, y: 3 };

  /**
   * Build a die. `sides` is 20 or 6; the caller inserts the returned element.
   *
   * Faces are numbered by document order, which is what dice.css's
   * `:nth-child(N)` selectors bind to — so the order is load-bearing and this is
   * the only place that should create one.
   */
  function create(sides, value) {
    const stage = document.createElement('div');
    // stageNN carries the footprint the generator measured for this solid. It has
    // to be on the stage rather than the die because a custom property inherits
    // downward only, and the stage is the parent.
    stage.className = `die-stage stage${sides}`;

    const die = document.createElement('div');
    die.className = `die die${sides}`;
    die.dataset.sides = String(sides);

    for (let n = 1; n <= sides; n++) {
      const face = document.createElement('figure');
      face.className = 'die-face';
      face.textContent = String(n);
      die.appendChild(face);
    }

    // Start somewhere: without a transform the die has no orientation at all and
    // the first throw would interpolate from the identity, which looks like the
    // die unfolding rather than turning.
    setRest(die, value || 1, 0);
    stage.appendChild(die);
    return stage;
  }

  function rest(sides, value) {
    const table = GEO()?.REST?.[sides];
    const angles = table && table[value];
    if (!angles) {
      throw new Error(`No resting orientation for a d${sides} showing ${value}`);
    }
    return angles;
  }

  /**
   * Point a die at a value with `turns` extra whole rotations on the way.
   *
   * The turns are added to the target angles rather than animated separately, so
   * one transition does the whole throw and there is no second keyframe to fall
   * out of sync with.
   */
  function setRest(die, value, turns) {
    const sides = Number(die.dataset.sides);
    const [x, y, z] = rest(sides, value);
    const spinX = x + 360 * (turns * SPIN_TURNS.x);
    const spinY = y + 360 * (turns * SPIN_TURNS.y);
    die.style.transform = `rotateX(${spinX}deg) rotateY(${spinY}deg) rotateZ(${z}deg)`;
    die.dataset.value = String(value);
  }

  const reduced = () =>
    (window.Game && window.Game.reducedMotion && window.Game.reducedMotion())
    || window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /**
   * Throw a die and resolve when it has settled.
   *
   * `duration` is jittered per die so a handful thrown together do not move as
   * one rigid object — four dice landing in perfect lockstep reads as a single
   * animation rather than four dice.
   */
  function land(stage, value, opts = {}) {
    const die = stage.querySelector('.die') || stage;
    if (reduced()) {
      die.style.transition = 'none';
      setRest(die, value, 0);
      return Promise.resolve(value);
    }

    const duration = Math.round((opts.duration || 900) * (0.9 + Math.random() * 0.2));
    const delay = opts.delay || 0;

    die.style.setProperty('--die-throw', `${duration}ms`);
    die.style.transitionDelay = `${delay}ms`;

    // Every throw approaches from a fresh random tilt, so rolling the same number
    // twice does not produce an identical animation — or, worse, no animation at
    // all because the start and end transforms happen to match.
    const jitter = () => (Math.random() - 0.5) * 40;
    const [x, y, z] = rest(Number(die.dataset.sides), value);
    die.style.transform =
      `rotateX(${x + jitter()}deg) rotateY(${y + jitter()}deg) rotateZ(${z + jitter()}deg)`;

    // Force the browser to take that as the starting point before asking for the
    // real target; without the reflow both assignments coalesce into one style
    // change and nothing transitions.
    void die.offsetWidth;

    return new Promise((resolve) => {
      let done = false;
      const finish = () => {
        if (done) return;
        done = true;
        die.style.transitionDelay = '';
        resolve(value);
      };
      // transitionend is the right signal but it does not fire if the transform
      // happens to be unchanged, or if the element is display:none by the time it
      // would land. The timer is the floor, not the plan.
      die.addEventListener('transitionend', finish, { once: true });
      setTimeout(finish, delay + duration + 120);

      requestAnimationFrame(() => setRest(die, value, 1));
    });
  }

  /**
   * Throw several dice as one gesture.
   *
   * Staggered by `stagger` ms so they land in a ripple rather than a thud, and
   * resolved together so a caller can await the whole handful.
   */
  function landAll(pairs, opts = {}) {
    const stagger = reduced() ? 0 : (opts.stagger ?? 70);
    return Promise.all(
      pairs.map(([stage, value], i) =>
        land(stage, value, { ...opts, delay: (opts.delay || 0) + i * stagger })
      )
    );
  }

  window.Dice3D = { create, land, landAll, setRest, available: () => !!GEO() };
})();
