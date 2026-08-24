/**
 * The character embed, from the page's side.
 *
 * Three ways in, one mechanism. Underneath is always an iframe holding the
 * Unity build and a postMessage channel; on top there is
 *
 *   mountCharacter(el, options)      an object with methods, for our own pages
 *   <rivermark-character …>          an element, for pages built out of markup
 *   the raw protocol                 documented at the bottom, for anyone else
 *
 * The element is a thin wrapper over the function and the function is a thin
 * wrapper over the protocol, so there is one place where anything actually
 * happens and two places that spell it differently.
 *
 * Nothing here knows what a character looks like. A recipe is an opaque object
 * that goes in and comes out; the meaning of it lives in the Unity build and in
 * SidekickAppearance.php, and a third copy of that knowledge in a file the
 * browser caches for a year is a third copy to keep in step.
 */

const PREFIX = 'rivermark:';

/** Options a host page can pass, and how they reach the embed's URL. */
const QUERY = {
  mode: 'mode',
  character: 'character',
  // Only read when the character has no saved look yet, so a page that is
  // making somebody can say what they are before they exist to be fetched.
  race: 'race',
  api: 'api',
  token: 'token',
  bundles: 'bundles',
  background: 'bg',
  origin: 'origin',
};

const DEFAULTS = {
  src: '/embed/',
  mode: 'create',
  background: '1E1A15',
  spin: true,
  save: true,
};

/**
 * Puts a character embed inside `host` and returns the handle to it.
 *
 * `host` is emptied first: an embed owns its box. Give it a box with a size —
 * the iframe fills it, and an iframe in a zero-height div is a blank page that
 * looks exactly like a broken build.
 */
export function mountCharacter(host, options = {}) {
  if (!host) throw new Error('mountCharacter needs an element to mount into.');

  const config = { ...DEFAULTS, ...options };
  const frame = document.createElement('iframe');
  frame.className = 'rivermark-character';
  frame.title = config.mode === 'view' ? 'Character' : 'Character creator';
  frame.setAttribute('loading', options.loading || 'lazy');
  // No allow-downloads, no allow-popups, no allow-top-navigation. The embed
  // draws a character and talks to its own origin; everything else it might do
  // it has no business doing.
  frame.setAttribute('allow', 'autoplay');
  frame.style.cssText = 'display:block;width:100%;height:100%;border:0;background:transparent;';
  frame.src = embedUrl(config);

  host.textContent = '';
  host.appendChild(frame);

  const target = originOf(frame.src);
  const queued = [];
  let ready = false;
  let recipe = config.recipe || null;
  let dead = false;

  const listeners = {
    ready: [], change: [], save: [], cancel: [], error: [], portrait: [],
  };
  for (const name of Object.keys(listeners)) {
    const handler = config['on' + name[0].toUpperCase() + name.slice(1)];
    if (typeof handler === 'function') listeners[name].push(handler);
  }

  function emit(name, detail) {
    for (const handler of listeners[name] || []) {
      try {
        handler(detail);
      } catch (e) {
        // A host page's callback throwing is not the embed's problem to have,
        // but swallowing it silently makes it nobody's, so it goes to the
        // console and the other listeners still run.
        console.error('rivermark-character: ' + name + ' handler threw', e);
      }
    }
  }

  function send(message) {
    if (dead) return;
    if (!ready) {
      queued.push(message);
      return;
    }
    frame.contentWindow.postMessage(message, target);
  }

  function onMessage(event) {
    if (dead) return;
    // Two checks, both needed: the origin says who sent it and the source says
    // which frame. A page with two embeds on it gets both answers from the same
    // origin, and without the source check they would each act on the other's.
    if (target !== '*' && event.origin !== target) return;
    if (event.source !== frame.contentWindow) return;

    const message = event.data;
    if (!message || typeof message !== 'object') return;
    if (typeof message.type !== 'string' || !message.type.startsWith(PREFIX)) return;

    switch (message.type) {
      case PREFIX + 'ready':
        ready = true;
        while (queued.length) frame.contentWindow.postMessage(queued.shift(), target);
        if (recipe) send({ type: PREFIX + 'load', recipe });
        emit('ready', { version: message.version, modes: message.modes || [] });
        break;

      case PREFIX + 'change':
        recipe = message.recipe || null;
        emit('change', { recipe });
        break;

      case PREFIX + 'saved':
        recipe = message.recipe || recipe;
        emit('save', { recipe });
        break;

      case PREFIX + 'cancel':
        emit('cancel', { recipe });
        break;

      case PREFIX + 'portrait':
        emit('portrait', { bust: message.bust || '', face: message.face || '' });
        break;

      case PREFIX + 'error':
        emit('error', { message: message.message || 'The character embed reported a problem.' });
        break;
    }
  }

  window.addEventListener('message', onMessage);

  return {
    frame,

    /** The last recipe the embed reported. Null until it says something. */
    get recipe() {
      return recipe;
    },

    /** Paint this recipe, and treat it as the saved state to revert to. */
    load(next) {
      recipe = next || null;
      send({ type: PREFIX + 'load', recipe });
    },

    /** Ask the creator to save. Answered with a `save` event, not a return value. */
    save() {
      send({ type: PREFIX + 'save' });
    },

    cancel() {
      send({ type: PREFIX + 'cancel' });
    },

    surprise() {
      send({ type: PREFIX + 'surprise' });
    },

    /**
     * Ask for stills of the character. Answered with a `portrait` event
     * carrying two base64 PNGs — a bust and a tighter face — not a return
     * value. They are two shots rather than one at two sizes, because a face
     * beside the art packs' own faces is a head filling a square. What they get
     * cropped and scaled to is the host's business.
     *
     * Its own call rather than something `save` includes, because rendering a
     * frame and base64-ing it is work no host that only wants the recipe
     * should be made to do.
     */
    portrait() {
      send({ type: PREFIX + 'portrait' });
    },

    setMode(mode) {
      send({ type: PREFIX + 'mode', mode: mode === 'view' ? 'view' : 'create' });
    },

    setSpin(spin) {
      send({ type: PREFIX + 'spin', spin: !!spin });
    },

    setBackground(background) {
      send({ type: PREFIX + 'bg', bg: String(background || '') });
    },

    on(name, handler) {
      if (listeners[name] && typeof handler === 'function') listeners[name].push(handler);
      return this;
    },

    off(name, handler) {
      if (!listeners[name]) return this;
      listeners[name] = listeners[name].filter((h) => h !== handler);
      return this;
    },

    destroy() {
      dead = true;
      window.removeEventListener('message', onMessage);
      frame.remove();
    },
  };
}

/**
 * The embed's URL. Everything the first frame needs is in the query string, so
 * a viewer needs no script at all — a bare iframe with the right URL is a
 * working embed, and this function is only the tidy way to write one.
 */
export function embedUrl(config) {
  const base = config.src || DEFAULTS.src;
  const url = new URL(base, window.location.href);

  for (const [option, param] of Object.entries(QUERY)) {
    const value = config[option];
    if (value === undefined || value === null || value === '') continue;
    url.searchParams.set(param, String(value));
  }

  if (config.spin === false) url.searchParams.set('spin', '0');
  if (config.save === false) url.searchParams.set('save', '0');

  // The embed answers only this origin, and posts only to it. Left unset it
  // will talk to anybody, which is right for a file:// test and wrong
  // everywhere else — so it is set here rather than left to the caller.
  if (!config.origin) url.searchParams.set('origin', window.location.origin);

  // A recipe small enough to fit goes in the URL, so the character is right on
  // the first frame instead of appearing and then changing. A big one waits for
  // the ready handshake.
  if (config.recipe) {
    const packed = JSON.stringify(config.recipe);
    if (packed.length <= 1500) url.searchParams.set('recipe', packed);
  }

  return url.toString();
}

function originOf(src) {
  try {
    return new URL(src, window.location.href).origin;
  } catch (e) {
    return '*';
  }
}

/**
 * <rivermark-character src="/embed/" mode="view" character="42"></rivermark-character>
 *
 * Attributes are the options above in kebab case. `recipe` takes JSON. The
 * element fires `ready`, `change`, `save`, `cancel` and `error`, each carrying
 * the same detail the callbacks get, and exposes `recipe`, `save()`,
 * `cancel()`, `surprise()` and `setMode()`.
 *
 * It sizes itself: 16:9 by default, or whatever `height` says. An element with
 * no height is the commonest way to make a working embed look broken, so it has
 * one whether the page thought about it or not.
 */
export class RivermarkCharacter extends HTMLElement {
  static get observedAttributes() {
    return ['src', 'mode', 'character', 'race', 'recipe', 'api', 'token', 'bundles',
            'background', 'origin', 'spin', 'save', 'height'];
  }

  constructor() {
    super();
    this._embed = null;
    this._root = document.createElement('div');
    this._root.style.cssText = 'display:block;width:100%;height:100%;';
  }

  connectedCallback() {
    if (!this.style.display) this.style.display = 'block';
    if (!this.style.height) {
      this.style.height = this.getAttribute('height') || '';
      if (!this.style.height) this.style.aspectRatio = this.getAttribute('aspect') || '16 / 9';
    }
    if (!this.contains(this._root)) this.appendChild(this._root);
    this._build();
  }

  disconnectedCallback() {
    if (this._embed) this._embed.destroy();
    this._embed = null;
  }

  attributeChangedCallback(name, before, after) {
    if (before === after || !this.isConnected) return;

    // Mode, spin and background are settings the running embed can be told
    // about. Everything else is part of its URL, and changing it means a new
    // one — which costs a reload, so it only happens for things that cannot be
    // said any other way.
    if (this._embed && name === 'mode') return this._embed.setMode(after);
    if (this._embed && name === 'spin') return this._embed.setSpin(after !== null && after !== 'false');
    if (this._embed && name === 'background') return this._embed.setBackground(after);
    if (this._embed && name === 'recipe') return this._embed.load(this._recipeAttribute());

    this._build();
  }

  _build() {
    if (this._embed) this._embed.destroy();

    const options = {
      src: this.getAttribute('src') || DEFAULTS.src,
      mode: this.getAttribute('mode') || DEFAULTS.mode,
      character: this.getAttribute('character') || undefined,
      api: this.getAttribute('api') || undefined,
      token: this.getAttribute('token') || undefined,
      bundles: this.getAttribute('bundles') || undefined,
      background: this.getAttribute('background') || DEFAULTS.background,
      origin: this.getAttribute('origin') || undefined,
      spin: !this.hasAttribute('spin') ? DEFAULTS.spin : this.getAttribute('spin') !== 'false',
      save: !this.hasAttribute('save') ? DEFAULTS.save : this.getAttribute('save') !== 'false',
      recipe: this._recipeAttribute(),
    };

    this._embed = mountCharacter(this._root, options);
    for (const name of ['ready', 'change', 'save', 'cancel', 'error']) {
      this._embed.on(name, (detail) => {
        this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true, composed: true }));
      });
    }
  }

  _recipeAttribute() {
    const raw = this.getAttribute('recipe');
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (e) {
      console.warn('rivermark-character: recipe attribute is not JSON', e);
      return null;
    }
  }

  get recipe() {
    return this._embed ? this._embed.recipe : this._recipeAttribute();
  }

  set recipe(value) {
    if (this._embed) this._embed.load(value);
    else this.setAttribute('recipe', JSON.stringify(value));
  }

  save() {
    if (this._embed) this._embed.save();
  }

  cancel() {
    if (this._embed) this._embed.cancel();
  }

  surprise() {
    if (this._embed) this._embed.surprise();
  }

  setMode(mode) {
    this.setAttribute('mode', mode);
  }
}

if (typeof customElements !== 'undefined' && !customElements.get('rivermark-character')) {
  customElements.define('rivermark-character', RivermarkCharacter);
}

export default mountCharacter;

/*
 * The protocol, for anything that is neither of the above.
 *
 * To the embed, posted to the iframe's contentWindow:
 *
 *   {type: 'rivermark:load',     recipe: {...}}  paint this, and revert to it
 *   {type: 'rivermark:save'}                     save, and answer with saved
 *   {type: 'rivermark:cancel'}                   back to the last saved recipe
 *   {type: 'rivermark:surprise'}                 roll a whole character
 *   {type: 'rivermark:mode',     mode: 'view'}   swap creator and viewer
 *   {type: 'rivermark:spin',     spin: false}    stop the turntable
 *   {type: 'rivermark:bg',       bg: '1E1A15'}   or '0' for transparent
 *
 * From the embed, posted to the parent window:
 *
 *   {type: 'rivermark:ready',    version, modes} it is up; anything sent before
 *                                               this was queued, not lost
 *   {type: 'rivermark:change',   recipe}         an edit, at most ~8 a second
 *   {type: 'rivermark:saved',    recipe}         saved, and stored if it owns a
 *                                               character id
 *   {type: 'rivermark:cancel'}                   reverted
 *   {type: 'rivermark:error',    message}        something a person should read
 */
