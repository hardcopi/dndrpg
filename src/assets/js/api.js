/**
 * Thin Fetch wrapper for the RPG JSON API.
 */
const API = {
  base: 'api/index.php?r=',

  async request(route, options = {}) {
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
    const res = await fetch(this.base + route, opts);
    let data;
    try {
      data = await res.json();
    } catch (e) {
      throw new Error('Invalid JSON response from server');
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
