/**
 * Playing a scene's recorded reading.
 *
 * A line of dialogue is not one recording. It is an ordered handful of them —
 * the narrator, then the character, then the narrator again — because the prose
 * and the speech inside it are read by different voices. The cut was made when
 * the audio was generated (`tools/gen_voiceover.py`); what arrives here is the
 * list, already in order, on the node the server sent. This file's whole job is
 * to play that list end to end and to stop cleanly when the scene moves on.
 *
 * Stopping cleanly is the part with teeth. A player who clicks through four
 * beats faster than the audio can read them must not end up with four voices
 * talking over each other, and `ended` fires on a clip that was abandoned two
 * beats ago just as readily as on one that was listened to. So every playthrough
 * carries a token, and a handler whose token is stale does nothing. Pausing the
 * element is not enough on its own — the event is already queued.
 *
 * The preference is remembered in localStorage rather than on the character,
 * because it is a fact about the room you are sitting in and the people in it,
 * not about your rogue. Somebody who turns speech off at midnight wants it off
 * for every save on that machine.
 *
 * Audio is allowed to be missing and allowed to be refused. An NPC nobody has
 * recorded ships an empty list; a browser that blocks autoplay rejects `play()`.
 * Both are silence, and neither interrupts the conversation — the text is the
 * game and the voice is an ornament on it.
 */
(function () {
  const KEY = 'rpg.voiceover';

  let on = false;
  try {
    on = window.localStorage.getItem(KEY) === '1';
  } catch (e) {
    // Private browsing, or storage disabled. The toggle still works, it just
    // forgets between sessions.
  }

  // Bumped on every stop and every new queue. A callback holding an older token
  // belongs to a scene the player has already left.
  let token = 0;
  let queue = [];
  let at = 0;

  function stop() {
    token += 1;
    queue.forEach((a) => {
      a.pause();
      // Detaching the source cancels a download still in flight; a queue of
      // abandoned clips otherwise goes on fetching after the scene has changed.
      a.removeAttribute('src');
      a.load();
    });
    queue = [];
    at = 0;
  }

  /**
   * Play one beat's clips, in order, replacing whatever was playing.
   *
   * @param clips the `vo` array from a node or an interjection: `{src}` each,
   *              or nothing at all if the line was never recorded
   */
  function play(clips) {
    stop();
    if (!on || !clips || !clips.length) return;

    // Built up front, all of them, so the next clip is already buffered when the
    // current one ends. The alternative is a beat of silence at every quotation
    // mark, which is exactly where the ear expects the reading to be seamless.
    queue = clips.map((c) => {
      const a = new Audio();
      a.preload = 'auto';
      a.src = c.src;
      return a;
    });
    step(token);
  }

  function step(mine) {
    if (mine !== token || at >= queue.length) return;
    const audio = queue[at];
    at += 1;
    const next = () => { if (mine === token) step(mine); };
    audio.addEventListener('ended', next, { once: true });
    // A clip that 404s or will not decode must not strand the rest of the line.
    audio.addEventListener('error', next, { once: true });
    const started = audio.play();
    if (started && started.catch) {
      started.catch(() => {
        // Autoplay refused, or the element was torn down mid-call. Either way
        // there is nothing useful to say and the scene carries on in silence.
      });
    }
  }

  function enabled() {
    return on;
  }

  function setEnabled(value) {
    on = !!value;
    try {
      window.localStorage.setItem(KEY, on ? '1' : '0');
    } catch (e) { /* see above */ }
    if (!on) stop();
    return on;
  }

  window.Voice = { play, stop, enabled, setEnabled };
})();
