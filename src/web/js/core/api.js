/**
 * Versioned API client — the wizard's only network surface.
 *
 * All calls target `/actions/slots/api/v1/…` (the documented headless API;
 * see docs/WIZARD_CORE_DESIGN.md §9). Craft controllers expect form-encoded
 * bodies with the CSRF token, and GETs carry the `site` handle for multi-site
 * language context — both are injected here so callers never touch CSRF or
 * site plumbing (and there are no inline `window.csrf*` globals, which keeps
 * the page CSP-clean).
 *
 * Stale-response guard: requests may be tagged with a `channel`. Firing a new
 * request on a channel aborts the in-flight one, so a late response for a
 * superseded selection can never overwrite fresher data.
 */

/** Error carrying an HTTP status and a machine-readable code. */
export class ApiError extends Error {
  constructor(message, { status = 0, code = 'error', body = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.body = body;
  }
}

/** Thrown/rejected when a request was superseded on its channel. */
export class AbortedError extends Error {
  constructor(channel) {
    super(`request aborted (channel: ${channel ?? 'none'})`);
    this.name = 'AbortedError';
    this.aborted = true;
    this.channel = channel ?? null;
  }
}

// Versioned pretty-URL base (see Slots::registerApiRoutes). Not under /actions/:
// these are site URL rules aliased onto the frontend controller actions.
const DEFAULT_BASE = '/slots/api/v1';

export class SlotsApi {
  /**
   * @param {Object} opts
   * @param {string} [opts.baseUrl]
   * @param {{name: string, value: string}} [opts.csrf]
   * @param {string} [opts.site]                 site handle for GET language context
   * @param {typeof fetch} [opts.fetch]          injectable for tests
   */
  constructor({ baseUrl = DEFAULT_BASE, csrf = null, site = null, fetch: fetchImpl } = {}) {
    this._base = baseUrl.replace(/\/$/, '');
    this._csrf = csrf;
    this._site = site;
    this._fetch = fetchImpl || (typeof fetch === 'function' ? fetch.bind(globalThis) : null);
    if (!this._fetch) {
      throw new Error('SlotsApi: no fetch implementation available; pass opts.fetch');
    }
    /** channel → AbortController of the in-flight request on that channel */
    this._channels = new Map();
  }

  /** Update the site handle (e.g. after locale change). */
  setSite(site) {
    this._site = site;
  }

  _url(path, query) {
    const url = new URL(this._base + '/' + String(path).replace(/^\//, ''), 'http://_relative_');
    if (this._site) url.searchParams.set('site', this._site);
    if (query) {
      for (const [k, v] of Object.entries(query)) {
        if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
      }
    }
    // Return a root-relative URL (strip the placeholder origin).
    return url.pathname + (url.search || '');
  }

  _encodeBody(body) {
    const form = new URLSearchParams();
    if (this._csrf && this._csrf.name) form.append(this._csrf.name, this._csrf.value ?? '');
    const append = (key, value) => {
      if (value === undefined || value === null) return;
      // Nested plain objects → key[subKey]=value for PHP array parsing.
      if (typeof value === 'object' && !Array.isArray(value)) {
        for (const [subKey, subVal] of Object.entries(value)) {
          if (subVal !== undefined && subVal !== null) form.append(`${key}[${subKey}]`, String(subVal));
        }
        return;
      }
      form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    };
    if (body) {
      for (const [k, v] of Object.entries(body)) append(k, v);
    }
    return form;
  }

  /**
   * Core request. On a non-2xx response throws ApiError; on supersession
   * throws AbortedError. Callers that pass a `channel` should treat
   * AbortedError as "ignore, newer request in flight".
   *
   * @param {'GET'|'POST'} method
   * @param {string} path
   * @param {Object} [opts]
   * @param {Object} [opts.query]   GET query params
   * @param {Object} [opts.body]    POST fields (form-encoded)
   * @param {string} [opts.channel] stale-guard channel
   */
  async request(method, path, { query, body, channel } = {}) {
    // Supersede any in-flight request on this channel.
    if (channel) {
      const prev = this._channels.get(channel);
      if (prev) prev.abort();
    }
    const controller = new AbortController();
    if (channel) this._channels.set(channel, controller);

    const init = { method, signal: controller.signal, headers: {} };
    if (method === 'POST') {
      init.body = this._encodeBody(body);
      init.headers['Accept'] = 'application/json';
      init.headers['X-Requested-With'] = 'XMLHttpRequest';
    } else {
      init.headers['Accept'] = 'application/json';
      init.headers['X-Requested-With'] = 'XMLHttpRequest';
    }

    let res;
    try {
      res = await this._fetch(this._url(path, query), init);
    } catch (err) {
      if (err && (err.name === 'AbortError' || err.aborted)) throw new AbortedError(channel);
      throw new ApiError(err && err.message ? err.message : 'network error', { code: 'network' });
    } finally {
      // Only clear the channel if we're still the current controller.
      if (channel && this._channels.get(channel) === controller) this._channels.delete(channel);
    }

    const text = await res.text();
    let data = null;
    if (text) {
      try {
        data = JSON.parse(text);
      } catch {
        data = null;
      }
    }

    if (!res.ok) {
      const message = (data && (data.error || data.message)) || `request failed (${res.status})`;
      const code = res.status === 410 ? 'expired' : res.status === 429 ? 'rate_limited' : 'http_error';
      throw new ApiError(message, { status: res.status, code, body: data });
    }
    return data;
  }

  get(path, opts) {
    return this.request('GET', path, opts);
  }

  post(path, opts) {
    return this.request('POST', path, opts);
  }

  /** Abort every in-flight channel request (wizard.destroy()). */
  abortAll() {
    for (const c of this._channels.values()) c.abort();
    this._channels.clear();
  }

  /**
   * Release a lock during page unload via navigator.sendBeacon — the only
   * transport that reliably completes on `beforeunload`. No-op outside a
   * browser or without a token. Returns whether the beacon was queued.
   */
  beaconRelease(token) {
    if (typeof navigator === 'undefined' || typeof navigator.sendBeacon !== 'function' || !token) {
      return false;
    }
    return navigator.sendBeacon(this._url('locks/release'), this._encodeBody({ token }));
  }

  // ---- Named endpoints (the v1 contract) ==============================

  // Booking data
  services() {
    return this.get('services', { channel: 'services' });
  }
  employees(serviceId, query) {
    return this.get('services/employees', { query: { serviceId, ...(query || {}) }, channel: 'employees' });
  }
  paymentSettings() {
    return this.get('payment-settings');
  }

  // Availability
  slots(body) {
    return this.post('availability/slots', { body, channel: 'slots' });
  }
  calendar(query) {
    return this.get('availability/calendar', { query, channel: 'calendar' });
  }

  // Locks
  createSlotLock(body) {
    return this.post('locks/slot', { body });
  }
  extendLock(body) {
    return this.post('locks/extend', { body });
  }
  releaseLock(body) {
    return this.post('locks/release', { body });
  }

  // Booking
  createBooking(body) {
    return this.post('bookings', { body });
  }

  // Direct payments. createPayment authorizes with the reservation's
  // confirmation token; confirmPayment is a UX poll (the webhook is the source
  // of truth). See PRD §7.3.
  createPayment(body) {
    return this.post('payment/create', { body });
  }
  confirmPayment(paymentToken) {
    return this.post('payment/confirm', { body: { paymentToken } });
  }


  // Account
  me() {
    return this.get('me');
  }

  // Management (?manage= token flow). The confirmation token is sent as
  // `manageToken`, never `token`: Craft reserves the `token` query param for
  // tokenized route access, so a value there is rejected with a 400 before the
  // controller runs. The load passes it in the query; cancel passes it in the
  // body (with action=cancel) — neither touches the reserved param.
  manageLoad({ token } = {}) {
    return this.get('manage', { query: { manageToken: token } });
  }
  manageCancel({ token, reason } = {}) {
    // Dedicated cancel route: a POST to the bare `manage` rule does not resolve
    // server-side. Token rides in the body as `manageToken` (never the query).
    return this.post('manage/cancel', { body: { manageToken: token, reason } });
  }
  manageReduce(body) {
    return this.post('manage/reduce', { body });
  }
  manageIncrease(body) {
    return this.post('manage/increase', { body });
  }
}
