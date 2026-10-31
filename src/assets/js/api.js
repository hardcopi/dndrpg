/**
 * Thin Fetch wrapper for the RPG JSON API.
 */
const API = {
  base: 'api/index.php?r=',

  // The session's CSRF token. Every request that can change something sends
  // it, because the cookie alone would be sent by any site that posts here.
  // Read from the page's <meta> on first use, then kept current from any
  // response that carries one — login and logout rotate it with the session id.
  csrf: null,

  csrfToken() {
    if (this.csrf === null) {
      const meta = document.querySelector('meta[name="csrf-token"]');
      this.csrf = meta ? meta.getAttribute('content') || '' : '';
    }
    return this.csrf;
  },

  setCsrfToken(token) {
    this.csrf = token;
    // Kept in the tag too, for the one caller that reads it without us:
    // the site bar's sign-out on pages that never load this file.
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
  },

  async request(route, options = {}, retried = false) {
    const opts = {
      method: options.method || 'GET',
      headers: { 'Accept': 'application/json', ...(options.headers || {}) },
      credentials: 'same-origin',
    };
    if (options.body) {
      opts.method = options.method || 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(options.body);
    }
    if (!['GET', 'HEAD'].includes(opts.method.toUpperCase())) {
      opts.headers['X-CSRF-Token'] = this.csrfToken();
    }
    const res = await fetch(this.base + route, opts);
    let data;
    try {
      data = await res.json();
    } catch (e) {
      throw new Error('Invalid JSON response from server');
    }
    if (data && typeof data.csrf_token === 'string') {
      this.setCsrfToken(data.csrf_token);
    }
    // A stale token — this tab was open across a sign-in somewhere else, or
    // the page predates the session it is talking to. The server refused
    // before doing anything, so asking for the current token and sending the
    // same request again is safe. Once: a second refusal is a real one.
    if (res.status === 403 && data && data.csrf && !retried) {
      await this.request('auth/status').catch(() => null);
      return this.request(route, options, true);
    }
    if (!res.ok || data.ok === false) {
      const err = new Error(data.error || res.statusText || 'Request failed');
      err.data = data;
      throw err;
    }
    return data;
  },

  // Optional params become query-string arguments after the route, because the
  // router reads the route from `r=` and everything else from $_GET alongside.
  get: (route, params) => {
    const qs = params ? '&' + new URLSearchParams(params).toString() : '';
    return API.request(route + qs);
  },
  post: (route, body) => API.request(route, { method: 'POST', body }),
};
