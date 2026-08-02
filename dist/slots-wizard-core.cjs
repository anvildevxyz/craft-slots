var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// src/web/js/core/index.js
var index_exports = {};
__export(index_exports, {
  AbortedError: () => AbortedError,
  ApiError: () => ApiError,
  Context: () => Context,
  Emitter: () => Emitter,
  Flow: () => Flow,
  I18N_DEFAULTS: () => DEFAULTS,
  I18n: () => I18n,
  LockController: () => LockController,
  Machine: () => Machine,
  STATES: () => STATES,
  SlotsApi: () => SlotsApi,
  Wizard: () => Wizard,
  bookingFlow: () => bookingFlow,
  canLeaveStep: () => canLeaveStep,
  create: () => create,
  default: () => index_default,
  isPresent: () => isPresent,
  isValidEmail: () => isValidEmail,
  validateCustomer: () => validateCustomer,
  validateQuantity: () => validateQuantity,
  version: () => version
});
module.exports = __toCommonJS(index_exports);

// src/web/js/core/emitter.js
var Emitter = class {
  constructor() {
    this._handlers = /* @__PURE__ */ new Map();
  }
  /**
   * Subscribe to `event`. Returns an unsubscribe function.
   * @returns {() => void}
   */
  on(event, handler) {
    if (typeof handler !== "function") {
      throw new TypeError("emitter.on: handler must be a function");
    }
    let set = this._handlers.get(event);
    if (!set) {
      set = /* @__PURE__ */ new Set();
      this._handlers.set(event, set);
    }
    set.add(handler);
    return () => this.off(event, handler);
  }
  /** Subscribe for a single emission. Returns an unsubscribe function. */
  once(event, handler) {
    const off = this.on(event, (payload) => {
      off();
      handler(payload);
    });
    return off;
  }
  /** Remove a specific handler, or all handlers for `event` when omitted. */
  off(event, handler) {
    const set = this._handlers.get(event);
    if (!set) return;
    if (handler) {
      set.delete(handler);
      if (set.size === 0) this._handlers.delete(event);
    } else {
      this._handlers.delete(event);
    }
  }
  /**
   * Emit `event` with `payload`. A handler that throws is isolated: its error
   * is re-emitted on the `error` channel (unless it was itself an `error`
   * emission, to avoid loops) and the remaining handlers still run.
   */
  emit(event, payload) {
    const set = this._handlers.get(event);
    if (!set || set.size === 0) return;
    for (const handler of [...set]) {
      try {
        handler(payload);
      } catch (err) {
        console.error("Slots wizard: event handler threw for", event, err);
        if (event !== "error") {
          this.emit("error", {
            message: err && err.message ? err.message : String(err),
            code: "handler_exception",
            recoverable: true
          });
        }
      }
    }
  }
  /** Drop every subscription (used by wizard.destroy()). */
  clear() {
    this._handlers.clear();
  }
};

// src/web/js/core/machine.js
var STATES = Object.freeze({
  IDLE: "idle",
  LOADING: "loading",
  BROWSING: "browsing",
  HOLDING_LOCK: "holdingLock",
  SUBMITTING: "submitting",
  PAYING: "paying",
  CONFIRMED: "confirmed",
  EXPIRED: "expired",
  ERROR: "error"
});
var TRANSITIONS = Object.freeze({
  [STATES.IDLE]: [STATES.LOADING],
  [STATES.LOADING]: [STATES.BROWSING, STATES.ERROR],
  // SUBMITTING is reachable directly for lock-less flows (events), whose
  // server-side seat lock is best-effort and may not be held.
  [STATES.BROWSING]: [STATES.BROWSING, STATES.HOLDING_LOCK, STATES.SUBMITTING, STATES.ERROR],
  // HOLDING_LOCK → HOLDING_LOCK: re-picking a slot while already holding one.
  [STATES.HOLDING_LOCK]: [STATES.HOLDING_LOCK, STATES.BROWSING, STATES.SUBMITTING, STATES.EXPIRED, STATES.ERROR],
  [STATES.SUBMITTING]: [STATES.CONFIRMED, STATES.PAYING, STATES.EXPIRED, STATES.ERROR],
  [STATES.PAYING]: [STATES.CONFIRMED, STATES.EXPIRED, STATES.ERROR],
  [STATES.CONFIRMED]: [STATES.IDLE],
  // via reset()
  // EXPIRED → HOLDING_LOCK: re-acquiring a fresh lock after an expiry recovers.
  [STATES.EXPIRED]: [STATES.HOLDING_LOCK, STATES.BROWSING, STATES.IDLE],
  [STATES.ERROR]: [STATES.BROWSING, STATES.HOLDING_LOCK, STATES.IDLE]
  // recover / retry / reset
});
var Machine = class {
  /**
   * @param {(change: {from: string, to: string, meta: any}) => void} [onChange]
   *        called after every successful transition (the wizard forwards this
   *        to the emitter as `state:change`).
   */
  constructor(onChange) {
    this._state = STATES.IDLE;
    this._onChange = typeof onChange === "function" ? onChange : () => {
    };
  }
  get state() {
    return this._state;
  }
  /** Is `to` a legal transition from the current state? */
  can(to) {
    const allowed = TRANSITIONS[this._state];
    return Array.isArray(allowed) && allowed.includes(to);
  }
  /**
   * Attempt a transition. Returns true on success. On an illegal transition it
   * returns false and leaves the state unchanged — callers guard on the return
   * value rather than relying on exceptions for expected races (e.g. an
   * expiry arriving just after the user already navigated away).
   */
  transition(to, meta) {
    if (!this.can(to)) return false;
    const from = this._state;
    this._state = to;
    this._onChange({ from, to, meta });
    return true;
  }
  /** Force the machine back to IDLE (destroy/reset paths only). */
  hardReset() {
    const from = this._state;
    this._state = STATES.IDLE;
    if (from !== STATES.IDLE) this._onChange({ from, to: STATES.IDLE, meta: { reason: "reset" } });
  }
};

// src/web/js/core/flow.js
var Flow = class {
  /**
   * @param {FlowDefinition} definition
   * @param {any} context  the wizard context object (read on every query)
   */
  constructor(definition, context) {
    if (!definition || !Array.isArray(definition.steps) || definition.steps.length === 0) {
      throw new TypeError("Flow: definition.steps must be a non-empty array");
    }
    this.id = definition.id;
    this._steps = definition.steps.map((s) => ({
      id: s.id,
      visible: typeof s.visible === "function" ? s.visible : () => true
    }));
    this._ctx = context;
    this._index = this._firstVisibleIndex();
    if (this._index === -1) {
      throw new Error(`Flow "${this.id}": no step is visible for the initial context`);
    }
  }
  /** Rebind the context (e.g. after reset). Does not move the cursor. */
  setContext(context) {
    this._ctx = context;
  }
  _isVisible(step) {
    return step.visible(this._ctx);
  }
  _firstVisibleIndex() {
    return this._steps.findIndex((s) => this._isVisible(s));
  }
  /** The current step's id, or null if somehow none is visible. */
  get currentId() {
    const step = this._steps[this._index];
    return step ? step.id : null;
  }
  /** Ordered ids of every currently-visible step (for a progress indicator). */
  get visibleIds() {
    return this._steps.filter((s) => this._isVisible(s)).map((s) => s.id);
  }
  /** 1-based position of the current step among visible steps, for display. */
  get position() {
    return this.visibleIds.indexOf(this.currentId) + 1;
  }
  /** Total count of currently-visible steps. */
  get total() {
    return this.visibleIds.length;
  }
  /** Peek the next visible step id without moving, or null at the end. */
  peekNext() {
    for (let i = this._index + 1; i < this._steps.length; i++) {
      if (this._isVisible(this._steps[i])) return this._steps[i].id;
    }
    return null;
  }
  /** Peek the previous visible step id without moving, or null at the start. */
  peekPrev() {
    for (let i = this._index - 1; i >= 0; i--) {
      if (this._isVisible(this._steps[i])) return this._steps[i].id;
    }
    return null;
  }
  get canGoNext() {
    return this.peekNext() !== null;
  }
  get canGoBack() {
    return this.peekPrev() !== null;
  }
  /**
   * Advance to the next visible step. Returns the new step id, or null if
   * already at the last visible step (caller decides what "past the end"
   * means — for the booking flow that's the `confirmed` lifecycle state).
   */
  next() {
    for (let i = this._index + 1; i < this._steps.length; i++) {
      if (this._isVisible(this._steps[i])) {
        this._index = i;
        return this._steps[i].id;
      }
    }
    return null;
  }
  /** Step back to the previous visible step. Returns the id, or null at the start. */
  back() {
    for (let i = this._index - 1; i >= 0; i--) {
      if (this._isVisible(this._steps[i])) {
        this._index = i;
        return this._steps[i].id;
      }
    }
    return null;
  }
  /**
   * Jump the cursor directly to `id` (deep-linking). The target must exist and
   * be visible for the current context; otherwise returns false and the cursor
   * does not move.
   */
  goTo(id) {
    const i = this._steps.findIndex((s) => s.id === id);
    if (i === -1 || !this._isVisible(this._steps[i])) return false;
    this._index = i;
    return true;
  }
  /**
   * Deepest visible step at or before `id` — used when a deep link targets a
   * step whose prerequisites don't fully resolve, so we land as far in as the
   * context legitimately allows rather than on a hidden step.
   */
  deepestVisibleUpTo(id) {
    const target = this._steps.findIndex((s) => s.id === id);
    if (target === -1) return this.currentId;
    let last = null;
    for (let i = 0; i <= target; i++) {
      if (this._isVisible(this._steps[i])) last = this._steps[i].id;
    }
    return last;
  }
  /** Reset the cursor to the first visible step. */
  reset() {
    this._index = this._firstVisibleIndex();
  }
};

// src/web/js/core/context.js
var Context = class {
  constructor(initial = {}) {
    this.serviceId = initial.serviceId ?? null;
    this.selectedService = initial.selectedService ?? null;
    this.locationId = initial.locationId ?? null;
    this.selectedLocation = initial.selectedLocation ?? null;
    this.employeeId = initial.employeeId ?? null;
    this.selectedEmployee = initial.selectedEmployee ?? null;
    this.servicePreselected = initial.servicePreselected ?? false;
    this.locationPreselected = initial.locationPreselected ?? false;
    this.services = initial.services ?? [];
    this.locations = initial.locations ?? [];
    this.employees = initial.employees ?? [];
    this.locale = initial.locale ?? null;
    this.date = initial.date ?? null;
    this.time = initial.time ?? null;
    this.quantity = initial.quantity ?? 1;
    this.slotQuantity = initial.slotQuantity ?? 1;
    this.serviceHasSchedule = initial.serviceHasSchedule ?? false;
    this.customer = { name: "", email: "", phone: "", notes: "", ...initial.customer ?? {} };
    this.payment = {
      enabled: false,
      currency: null,
      currencySymbol: null,
      ...initial.payment ?? {}
    };
    this.lock = initial.lock ?? null;
    this.reservation = initial.reservation ?? null;
  }
  // ---- Computed: duration / price =====================================
  /** Inclusive day count for a multi-day range; 0 until both ends are set. */
  /** The chosen event date (event flow), resolved from the loaded list, or null. */
  /** Per-service price, applying per-unit day pricing when applicable. */
  get servicePrice() {
    const basePrice = Number(this.selectedService?.price) || 0;
    return basePrice;
  }
  /**
   * Per-unit price of the current selection: the event date's price in the event
   * flow, otherwise the service price. The event's own price is never zero-rated
   * away like `selectedService` (null for events) would.
   */
  get unitPrice() {
    return this.servicePrice;
  }
  /** Display total: unitPrice × quantity. Server remains authoritative. */
  get totalPrice() {
    return this.unitPrice * this.quantity;
  }
  /** Whether the payment branch applies (Commerce enabled and a non-zero total). */
  get requiresPayment() {
    return this.payment.enabled && this.totalPrice > 0;
  }
  // ---- Mutators (thin; the wizard drives these) =======================
  setService(service) {
    this.selectedService = service ?? null;
    this.serviceId = service?.id ?? null;
    this.employeeId = null;
    this.selectedEmployee = null;
    this.locationId = null;
    this.selectedLocation = null;
    this.date = null;
    this.time = null;
  }
  setCustomer(fields) {
    this.customer = { ...this.customer, ...fields };
  }
  /** Immutable-ish snapshot for `getState()`/events (no methods/getters). */
  snapshot() {
    return {
      serviceId: this.serviceId,
      selectedService: this.selectedService,
      locationId: this.locationId,
      selectedLocation: this.selectedLocation,
      employeeId: this.employeeId,
      selectedEmployee: this.selectedEmployee,
      servicePreselected: this.servicePreselected,
      locationPreselected: this.locationPreselected,
      // Data lists the renderer needs to populate step content.
      services: this.services,
      locations: this.locations,
      employees: this.employees,
      locale: this.locale,
      date: this.date,
      time: this.time,
      quantity: this.quantity,
      slotQuantity: this.slotQuantity,
      customer: { ...this.customer },
      payment: { ...this.payment },
      lock: this.lock ? { ...this.lock } : null,
      reservation: this.reservation ? { ...this.reservation } : null,
      // computed, included for renderer convenience
      totalPrice: this.totalPrice,
      requiresPayment: this.requiresPayment
    };
  }
};

// src/web/js/core/api.js
var ApiError = class extends Error {
  constructor(message, { status = 0, code = "error", body = null } = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.code = code;
    this.body = body;
  }
};
var AbortedError = class extends Error {
  constructor(channel) {
    super(`request aborted (channel: ${channel ?? "none"})`);
    this.name = "AbortedError";
    this.aborted = true;
    this.channel = channel ?? null;
  }
};
var DEFAULT_BASE = "/slots/api/v1";
var SlotsApi = class {
  /**
   * @param {Object} opts
   * @param {string} [opts.baseUrl]
   * @param {{name: string, value: string}} [opts.csrf]
   * @param {string} [opts.site]                 site handle for GET language context
   * @param {typeof fetch} [opts.fetch]          injectable for tests
   */
  constructor({ baseUrl = DEFAULT_BASE, csrf = null, site = null, fetch: fetchImpl } = {}) {
    this._base = baseUrl.replace(/\/$/, "");
    this._csrf = csrf;
    this._site = site;
    this._fetch = fetchImpl || (typeof fetch === "function" ? fetch.bind(globalThis) : null);
    if (!this._fetch) {
      throw new Error("SlotsApi: no fetch implementation available; pass opts.fetch");
    }
    this._channels = /* @__PURE__ */ new Map();
  }
  /** Update the site handle (e.g. after locale change). */
  setSite(site) {
    this._site = site;
  }
  _url(path, query) {
    const url = new URL(this._base + "/" + String(path).replace(/^\//, ""), "http://_relative_");
    if (this._site) url.searchParams.set("site", this._site);
    if (query) {
      for (const [k, v] of Object.entries(query)) {
        if (v !== void 0 && v !== null) url.searchParams.set(k, String(v));
      }
    }
    return url.pathname + (url.search || "");
  }
  _encodeBody(body) {
    const form = new URLSearchParams();
    if (this._csrf && this._csrf.name) form.append(this._csrf.name, this._csrf.value ?? "");
    const append = (key, value) => {
      if (value === void 0 || value === null) return;
      if (typeof value === "object" && !Array.isArray(value)) {
        for (const [subKey, subVal] of Object.entries(value)) {
          if (subVal !== void 0 && subVal !== null) form.append(`${key}[${subKey}]`, String(subVal));
        }
        return;
      }
      form.append(key, typeof value === "boolean" ? value ? "1" : "0" : String(value));
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
    if (channel) {
      const prev = this._channels.get(channel);
      if (prev) prev.abort();
    }
    const controller = new AbortController();
    if (channel) this._channels.set(channel, controller);
    const init = { method, signal: controller.signal, headers: {} };
    if (method === "POST") {
      init.body = this._encodeBody(body);
      init.headers["Accept"] = "application/json";
      init.headers["X-Requested-With"] = "XMLHttpRequest";
    } else {
      init.headers["Accept"] = "application/json";
      init.headers["X-Requested-With"] = "XMLHttpRequest";
    }
    let res;
    try {
      res = await this._fetch(this._url(path, query), init);
    } catch (err) {
      if (err && (err.name === "AbortError" || err.aborted)) throw new AbortedError(channel);
      throw new ApiError(err && err.message ? err.message : "network error", { code: "network" });
    } finally {
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
      const message = data && (data.error || data.message) || `request failed (${res.status})`;
      const code = res.status === 410 ? "expired" : res.status === 429 ? "rate_limited" : "http_error";
      throw new ApiError(message, { status: res.status, code, body: data });
    }
    return data;
  }
  get(path, opts) {
    return this.request("GET", path, opts);
  }
  post(path, opts) {
    return this.request("POST", path, opts);
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
    if (typeof navigator === "undefined" || typeof navigator.sendBeacon !== "function" || !token) {
      return false;
    }
    return navigator.sendBeacon(this._url("locks/release"), this._encodeBody({ token }));
  }
  // ---- Named endpoints (the v1 contract) ==============================
  // Booking data
  services() {
    return this.get("services", { channel: "services" });
  }
  employees(serviceId, query) {
    return this.get("services/employees", { query: { serviceId, ...query || {} }, channel: "employees" });
  }
  paymentSettings() {
    return this.get("payment-settings");
  }
  // Availability
  slots(body) {
    return this.post("availability/slots", { body, channel: "slots" });
  }
  calendar(query) {
    return this.get("availability/calendar", { query, channel: "calendar" });
  }
  // Locks
  createSlotLock(body) {
    return this.post("locks/slot", { body });
  }
  extendLock(body) {
    return this.post("locks/extend", { body });
  }
  releaseLock(body) {
    return this.post("locks/release", { body });
  }
  // Booking
  createBooking(body) {
    return this.post("bookings", { body });
  }
  // Direct payments. createPayment authorizes with the reservation's
  // confirmation token; confirmPayment is a UX poll (the webhook is the source
  // of truth). See PRD §7.3.
  createPayment(body) {
    return this.post("payment/create", { body });
  }
  confirmPayment(paymentToken) {
    return this.post("payment/confirm", { body: { paymentToken } });
  }
  // Account
  me() {
    return this.get("me");
  }
  // Management (?manage= token flow). The confirmation token is sent as
  // `manageToken`, never `token`: Craft reserves the `token` query param for
  // tokenized route access, so a value there is rejected with a 400 before the
  // controller runs. The load passes it in the query; cancel passes it in the
  // body (with action=cancel) — neither touches the reserved param.
  manageLoad({ token } = {}) {
    return this.get("manage", { query: { manageToken: token } });
  }
  manageCancel({ token, reason } = {}) {
    return this.post("manage/cancel", { body: { manageToken: token, reason } });
  }
  manageReduce(body) {
    return this.post("manage/reduce", { body });
  }
  manageIncrease(body) {
    return this.post("manage/increase", { body });
  }
};

// src/web/js/core/lock.js
function resolveExpiry(result, now) {
  if (!result) return null;
  if (typeof result.expiresAt === "number") return result.expiresAt;
  if (typeof result.expiresAt === "string") {
    const t = Date.parse(result.expiresAt);
    if (!Number.isNaN(t)) return t;
  }
  if (typeof result.expiresIn === "number") return now() + result.expiresIn * 1e3;
  return null;
}
var KIND_METHOD = {
  slot: "createSlotLock"
};
var LockController = class {
  /**
   * @param {Object} opts
   * @param {Object} opts.api                     v1 client (createSlotLock/…, extendLock, releaseLock)
   * @param {(event: string, payload: any) => void} opts.emit
   * @param {number} [opts.warningThresholdMs]    fire lock:expiring this long before expiry (default 60s)
   * @param {() => number} [opts.now]             clock (default Date.now)
   * @param {typeof setTimeout} [opts.setTimer]
   * @param {typeof clearTimeout} [opts.clearTimer]
   */
  constructor({ api, emit, warningThresholdMs = 6e4, now, setTimer, clearTimer } = {}) {
    if (!api) throw new Error("LockController: api is required");
    this._api = api;
    this._emit = typeof emit === "function" ? emit : () => {
    };
    this._warn = warningThresholdMs;
    this._now = now || (() => Date.now());
    this._setTimer = setTimer || ((fn, ms) => setTimeout(fn, ms));
    this._clearTimer = clearTimer || ((h) => clearTimeout(h));
    this._token = null;
    this._expiresAt = null;
    this._extended = false;
    this._warnHandle = null;
    this._expiryHandle = null;
    this._warned = false;
  }
  get token() {
    return this._token;
  }
  get held() {
    return this._token !== null;
  }
  get expiresAt() {
    return this._expiresAt;
  }
  get remainingMs() {
    if (this._expiresAt === null) return 0;
    return Math.max(0, this._expiresAt - this._now());
  }
  /**
   * Acquire a lock of `kind` ('slot'|'range'|'event') with the given request body.
   * Returns `{ acquired, token, expiresAt, message }`. A failed acquisition
   * (slot already taken) resolves with `acquired:false` rather than throwing —
   * the wizard decides whether to block or surface it. Genuine transport errors
   * still reject (ApiError) so they can become an `error` state.
   */
  async acquire(kind, body) {
    const method = KIND_METHOD[kind];
    if (!method) throw new Error(`LockController.acquire: unknown kind "${kind}"`);
    if (this._acquiring) return { acquired: false, busy: true };
    this._acquiring = true;
    try {
      if (this.held) await this.release("superseded");
      const result = await this._api[method](body);
      const token = result && result.token;
      if (!token) {
        return { acquired: false, token: null, expiresAt: null, message: result && (result.message || result.error) };
      }
      this._token = token;
      this._expiresAt = resolveExpiry(result, this._now);
      this._extended = false;
      this._warned = false;
      this._startTimers();
      this._emit("lock:acquired", { token: this._token, expiresAt: this._expiresAt });
      return { acquired: true, token: this._token, expiresAt: this._expiresAt };
    } finally {
      this._acquiring = false;
    }
  }
  _clearTimers() {
    if (this._warnHandle !== null) {
      this._clearTimer(this._warnHandle);
      this._warnHandle = null;
    }
    if (this._expiryHandle !== null) {
      this._clearTimer(this._expiryHandle);
      this._expiryHandle = null;
    }
  }
  _startTimers() {
    this._clearTimers();
    if (this._expiresAt === null) return;
    const remaining = this._expiresAt - this._now();
    const warnIn = remaining - this._warn;
    if (warnIn <= 0) {
      if (!this._warned) {
        this._warned = true;
        this._emit("lock:expiring", { remainingMs: Math.max(0, remaining) });
      }
    } else {
      this._warnHandle = this._setTimer(() => {
        this._warnHandle = null;
        this._warned = true;
        this._emit("lock:expiring", { remainingMs: this.remainingMs });
      }, warnIn);
    }
    this._expiryHandle = this._setTimer(() => {
      this._expiryHandle = null;
      this._onExpire();
    }, Math.max(0, remaining));
  }
  _onExpire() {
    this._clearTimers();
    this._token = null;
    this._expiresAt = null;
    this._emit("lock:expired", {});
  }
  /**
   * Called when the user commits (submit/pay). If the hold is inside the
   * warning window and hasn't been extended yet, perform exactly one silent
   * server-side extension. Idempotent and safe to call when no lock is held.
   */
  async ensureFresh() {
    if (!this.held || this._extended) return;
    if (this.remainingMs > this._warn) return;
    this._clearTimers();
    let result;
    try {
      result = await this._api.extendLock({ token: this._token });
    } catch {
      this._startTimers();
      return;
    }
    if (!result || result.success === false) {
      this._startTimers();
      return;
    }
    this._extended = true;
    const next = resolveExpiry(result, this._now);
    if (next !== null) this._expiresAt = next;
    this._warned = false;
    this._startTimers();
    this._emit("lock:extended", { expiresAt: this._expiresAt });
  }
  /**
   * Release the current lock server-side and stop the timer. Best-effort:
   * a transport failure is swallowed (the lock expires naturally) but the
   * local state is always cleared and `lock:released` emitted.
   */
  async release(reason = "released") {
    const token = this._token;
    this._clearTimers();
    this._token = null;
    this._expiresAt = null;
    this._extended = false;
    this._warned = false;
    if (!token) return;
    try {
      await this._api.releaseLock({ token });
    } catch {
    }
    this._emit("lock:released", { reason });
  }
  /** Payload for a synchronous page-unload release (navigator.sendBeacon). */
  beaconPayload() {
    return this._token ? { token: this._token } : null;
  }
  /** Tear down timers without any network call (wizard.destroy path uses release()). */
  destroy() {
    this._clearTimers();
    this._token = null;
    this._expiresAt = null;
  }
};

// src/web/js/core/i18n.js
var DEFAULTS = Object.freeze({
  "announce.stepChanged": "Step {position} of {total}: {title}",
  "lock.expiring": "Your reservation is held for {minutes} more minute(s).",
  "lock.expired": "Your reserved time has expired. Please choose a time again.",
  "calendar.prevMonth": "Previous month",
  "calendar.nextMonth": "Next month",
  "slot.seatsAvailable": "{count} available",
  "error.generic": "Something went wrong. Please try again.",
  "error.booking": "Your booking could not be completed.",
  "error.slotReserved": "That time was just taken. Please choose another.",
  "validation.serviceRequired": "Please choose a service.",
  "validation.slotRequired": "Please choose a date and time.",
  "validation.nameRequired": "Please enter your name.",
  "validation.emailRequired": "Please enter your email address.",
  "validation.emailInvalid": "Please enter a valid email address.",
  "validation.phoneRequired": "Please enter your phone number.",
  "validation.quantityInvalid": "Please enter a valid quantity.",
  "validation.quantityTooLow": "Quantity is too low.",
  "validation.quantityTooHigh": "Not enough capacity for that quantity."
});
function interpolate(template, params) {
  if (!params) return template;
  return template.replace(
    /\{(\w+)\}/g,
    (match, token) => Object.prototype.hasOwnProperty.call(params, token) ? String(params[token]) : match
  );
}
var I18n = class {
  /**
   * @param {Object<string,string>} [table]  overrides merged over DEFAULTS
   * @param {{locale?: string}} [opts]
   */
  constructor(table = {}, { locale = null } = {}) {
    this._table = { ...DEFAULTS, ...table || {} };
    this._locale = locale;
  }
  get locale() {
    return this._locale;
  }
  /** Whether a key is known (in overrides or defaults). */
  has(key) {
    return Object.prototype.hasOwnProperty.call(this._table, key);
  }
  /**
   * Resolve `key` with `{token}` interpolation. Unknown keys fall through to
   * the DEFAULTS, then to the key itself (so nothing renders as blank).
   */
  t(key, params) {
    const template = this._table[key] ?? DEFAULTS[key] ?? key;
    return interpolate(template, params);
  }
  /** Merge additional strings (e.g. late-arriving config). */
  extend(table) {
    Object.assign(this._table, table || {});
  }
};

// src/web/js/core/validation.js
var EMAIL_RE = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;
function isValidEmail(email) {
  if (!email || typeof email !== "string") return false;
  return EMAIL_RE.test(email.trim());
}
function isPresent(value) {
  return typeof value === "string" ? value.trim().length > 0 : value != null;
}
function validateCustomer(customer = {}, opts = {}) {
  const errors = {};
  if (!isPresent(customer.name)) errors.name = "validation.nameRequired";
  if (!isPresent(customer.email)) errors.email = "validation.emailRequired";
  else if (!isValidEmail(customer.email)) errors.email = "validation.emailInvalid";
  if (opts.requirePhone && !isPresent(customer.phone)) errors.phone = "validation.phoneRequired";
  return errors;
}
function validateQuantity(quantity, { min = 1, max = Infinity } = {}) {
  const n = Number(quantity);
  if (!Number.isInteger(n)) return "validation.quantityInvalid";
  if (n < min) return "validation.quantityTooLow";
  if (n > max) return "validation.quantityTooHigh";
  return null;
}
function canLeaveStep(stepId, ctx, opts = {}) {
  switch (stepId) {
    case "service":
      return { ok: ctx.serviceId != null, errors: ctx.serviceId != null ? {} : { service: "validation.serviceRequired" } };
    case "datetime": {
      const ok = ctx.lock != null || !!ctx.date && !!ctx.time;
      return { ok, errors: ok ? {} : { datetime: "validation.slotRequired" } };
    }
    case "info": {
      const errors = validateCustomer(ctx.customer, { requirePhone: opts.requirePhone });
      return { ok: Object.keys(errors).length === 0, errors };
    }
    default:
      return { ok: true, errors: {} };
  }
}

// src/web/js/core/flows/booking.js
var bookingFlow = {
  id: "booking",
  steps: [
    { id: "service", visible: (ctx) => !(ctx.servicePreselected || Array.isArray(ctx.services) && ctx.services.length === 1) },
    { id: "location", visible: (ctx) => !ctx.locationPreselected && Array.isArray(ctx.locations) && ctx.locations.length > 1 },
    { id: "employee", visible: (ctx) => Array.isArray(ctx.employees) && ctx.employees.length > 1 },
    { id: "datetime", visible: () => true },
    { id: "info", visible: () => true },
    { id: "review", visible: () => true }
  ]
};

// src/web/js/core/flows/manage.js
var manageFlow = {
  id: "manage",
  steps: [{ id: "manage", visible: () => true }]
};

// src/web/js/core/wizard.js
var FLOWS = { booking: bookingFlow, manage: manageFlow };
var SLOT_STEPS = /* @__PURE__ */ new Set(["datetime"]);
function list(payload, key) {
  return payload && Array.isArray(payload[key]) ? payload[key] : [];
}
var Wizard = class {
  /** @param {import('./types.js').WizardOptions} [options] */
  constructor(options = {}) {
    this._options = options;
    this._emitter = new Emitter();
    this._config = {
      requirePhone: false,
      showNotes: true,
      defaultQuantity: 1,
      siteHandle: options.api?.site ?? null,
      ...options.config ?? {}
    };
    this._i18n = new I18n(
      { ...options.labels ?? {}, ...options.messages ?? {} },
      { locale: options.locale ?? null }
    );
    this._api = options.apiClient || new SlotsApi({
      baseUrl: options.api?.baseUrl,
      csrf: options.api?.csrf,
      site: options.api?.site,
      fetch: options.api?.fetch
    });
    this._ctx = new Context({
      serviceId: options.serviceId ?? null,
      quantity: options.config?.defaultQuantity ?? 1,
      customer: options.customer ?? {},
      locale: options.locale ?? null
    });
    this._mode = options.mode === "manage" ? "manage" : "book";
    this._manageToken = options.manageToken ?? null;
    const flowName = this._mode === "manage" ? "manage" : options.flow ?? "booking";
    const flowDef = FLOWS[flowName];
    if (!flowDef) throw new Error(`Wizard: unknown flow "${options.flow}"`);
    this._flow = new Flow(flowDef, this._ctx);
    this._machine = new Machine(({ from, to, meta }) => {
      this._emitter.emit("state:change", { from, to, stepId: this._flow.currentId, meta });
    });
    this._lock = new LockController({ api: this._api, emit: (e, p) => this._emitter.emit(e, p) });
    this._pendingPayment = null;
    this._emitter.on("lock:expired", () => this._onLockExpired());
    this._emitter.on("lock:expiring", ({ remainingMs }) => {
      const minutes = Math.max(1, Math.ceil((remainingMs || 0) / 6e4));
      this._emitter.emit("announce", { message: this._i18n.t("lock.expiring", { minutes }), politeness: "polite" });
    });
    this._onUnload = null;
    if (typeof window !== "undefined" && typeof window.addEventListener === "function") {
      this._onUnload = () => {
        const payload = this._lock.beaconPayload();
        if (payload) this._api.beaconRelease(payload.token);
      };
      window.addEventListener("beforeunload", this._onUnload);
    }
  }
  // ---- Subscriptions ==================================================
  on(event, handler) {
    return this._emitter.on(event, handler);
  }
  once(event, handler) {
    return this._emitter.once(event, handler);
  }
  off(event, handler) {
    this._emitter.off(event, handler);
  }
  /** Resolve an i18n key (with `{token}` interpolation) — the localized string. */
  t(key, params) {
    return this._i18n.t(key, params);
  }
  // ---- Introspection ==================================================
  get state() {
    return this._machine.state;
  }
  get stepId() {
    return this._flow.currentId;
  }
  getState() {
    return {
      lifecycle: this._machine.state,
      stepId: this._flow.currentId,
      position: this._flow.position,
      total: this._flow.total,
      context: this._ctx.snapshot()
    };
  }
  // ---- Lifecycle ======================================================
  /** Bootstrap: load payment settings + services, resolve preselects. */
  async start() {
    if (this._machine.state !== STATES.IDLE) return this.getState();
    if (this._mode === "manage") return this._startManage();
    this._machine.transition(STATES.LOADING);
    try {
      const [payment, services] = await Promise.all([
        this._api.paymentSettings().catch(() => null),
        this._api.services()
      ]);
      if (payment) this._applyPayment(payment);
      this._ctx.services = list(services, "services");
      this._flow.setContext(this._ctx);
      this._emitter.emit("data:loaded", { kind: "services", items: this._ctx.services });
      if (this._options.serviceId != null) {
        await this._loadServiceData(this._options.serviceId);
        this._ctx.servicePreselected = true;
      } else if (this._flow.id === "booking" && this._ctx.services.length === 1) {
        await this._loadServiceData(this._ctx.services[0].id);
      }
      this._flow.reset();
      await this._applyDeepLinks();
      this._machine.transition(STATES.BROWSING);
      this._announceStep("init");
      return this.getState();
    } catch (err) {
      this._toError(err);
      return this.getState();
    }
  }
  /**
   * Apply integrator deep-link prefills (config). A preselected location scopes
   * the employees and skips the location step; a preselected date opens the
   * calendar on that day (the customer still confirms the slot, which acquires
   * the lock — a link never carries a lock in). Only booking-flow selections a
   * loaded service actually offers are honored.
   */
  async _applyDeepLinks() {
    const opts = this._options;
    if (this._flow.id !== "booking") return;
    const locationId = opts.locationId != null ? Number(opts.locationId) : null;
    if (locationId != null && this._ctx.locations.some((l) => l.id === locationId)) {
      await this.selectLocation(locationId);
      this._ctx.locationPreselected = true;
    }
    const employeeId = opts.employeeId != null ? Number(opts.employeeId) : null;
    if (employeeId != null && this._ctx.employees.some((e) => e.id === employeeId)) {
      this._ctx.employeeId = employeeId;
      this._ctx.selectedEmployee = this._ctx.employees.find((e) => e.id === employeeId) ?? null;
    }
    if (opts.date) {
      this._ctx.date = String(opts.date);
      if (opts.time) this._ctx.time = String(opts.time);
    }
    this._flow.setContext(this._ctx);
    if (opts.date) {
      this._flow.goTo("datetime");
    } else if (locationId != null || employeeId != null) {
      this._flow.reset();
    }
    if (locationId != null || employeeId != null || opts.date) {
      this._emitter.emit("deeplink:loaded", { locationId, employeeId, date: this._ctx.date, time: this._ctx.time });
    }
  }
  _applyPayment(payload) {
    this._ctx.payment = {
      enabled: !!payload.paymentEnabled,
      currency: payload.currency ?? null,
      currencySymbol: payload.currencySymbol ?? null
    };
  }
  // ---- Selection ======================================================
  /** Load employees/locations for a service and set it in context. */
  async _loadServiceData(id) {
    const service = this._ctx.services.find((s) => s.id === id) ?? { id };
    this._ctx.setService(service);
    let employees;
    try {
      employees = await this._api.employees(id);
    } catch (err) {
      if (err && err.aborted) return;
      this._toError(err);
      return;
    }
    this._ctx.employees = list(employees, "employees");
    this._ctx.locations = list(employees, "locations");
    this._ctx.serviceHasSchedule = !!(employees && employees.serviceHasSchedule);
    if (this._ctx.locations.length === 1) {
      this._ctx.selectedLocation = this._ctx.locations[0];
      this._ctx.locationId = this._ctx.locations[0].id;
    }
    if (this._ctx.employees.length === 1) {
      this._ctx.selectedEmployee = this._ctx.employees[0];
      this._ctx.employeeId = this._ctx.employees[0].id;
    }
    this._flow.setContext(this._ctx);
    this._emitter.emit("data:loaded", { kind: "service", items: { employees: this._ctx.employees } });
  }
  async selectService(id) {
    await this._loadServiceData(id);
    this._emitter.emit("service:selected", { serviceId: id });
    return this.getState();
  }
  async selectLocation(id) {
    this._ctx.locationId = id;
    this._ctx.selectedLocation = this._ctx.locations.find((l) => l.id === id) ?? null;
    if (this._ctx.serviceId != null) {
      let employees;
      try {
        employees = await this._api.employees(this._ctx.serviceId, { locationId: id });
      } catch (err) {
        if (!(err && err.aborted)) this._toError(err);
        return this.getState();
      }
      this._ctx.employees = list(employees, "employees");
      this._ctx.employeeId = null;
      this._ctx.selectedEmployee = null;
      if (this._ctx.employees.length === 1) {
        this._ctx.selectedEmployee = this._ctx.employees[0];
        this._ctx.employeeId = this._ctx.employees[0].id;
      }
      this._flow.setContext(this._ctx);
      this._emitter.emit("data:loaded", { kind: "location", items: { employees: this._ctx.employees } });
    }
    return this.getState();
  }
  selectEmployee(id) {
    this._ctx.employeeId = id;
    this._ctx.selectedEmployee = this._ctx.employees.find((e) => e.id === id) ?? null;
    return this.getState();
  }
  setCustomer(fields) {
    this._ctx.setCustomer(fields);
    return this.getState();
  }
  // ---- Slot / range / event selection (acquire lock) ==================
  async selectSlot({ date, time, quantity = 1 } = {}) {
    this._ctx.date = date;
    this._ctx.time = time;
    this._ctx.slotQuantity = quantity;
    this._ctx.quantity = quantity;
    const body = this._pruned({ date, startTime: time, ...this._selectionParams({ quantity: true }) });
    return this._acquire("slot", body, () => this._emitter.emit("slot:selected", { date, time, quantity }));
  }
  async _acquire(kind, body, onSuccess, { bestEffort = false } = {}) {
    let res;
    try {
      res = await this._lock.acquire(kind, body);
    } catch (err) {
      this._syncLockAfterFailure();
      if (bestEffort) {
        onSuccess();
        return { acquired: false, bestEffort: true };
      }
      if (err && err.status === 400) {
        this._emitter.emit("error", {
          message: err.message || this._i18n.t("error.slotReserved"),
          code: "slot_reserved",
          recoverable: true
        });
        return { acquired: false, message: err.message };
      }
      this._toError(err);
      return { acquired: false, error: err.message };
    }
    if (res.busy) return res;
    if (res.acquired) {
      this._ctx.lock = { token: res.token, expiresAt: res.expiresAt };
      this._machine.transition(STATES.HOLDING_LOCK);
      onSuccess();
    } else {
      this._syncLockAfterFailure();
      if (bestEffort) {
        onSuccess();
      } else {
        this._emitter.emit("error", {
          message: res.message || this._i18n.t("error.slotReserved"),
          code: "slot_reserved",
          recoverable: true
        });
      }
    }
    return res;
  }
  /** Resync ctx.lock + machine to the LockController's real state after a failed acquire. */
  _syncLockAfterFailure() {
    this._ctx.lock = this._lock.held ? { token: this._lock.token, expiresAt: this._lock.expiresAt } : null;
    if (!this._lock.held && this._machine.state === STATES.HOLDING_LOCK) {
      this._machine.transition(STATES.BROWSING);
    }
  }
  /** Central handler for a lost lock (timer expiry or a 410 at submit). */
  _onLockExpired() {
    this._ctx.lock = null;
    const s = this._machine.state;
    if (s === STATES.HOLDING_LOCK || s === STATES.SUBMITTING || s === STATES.PAYING) {
      this._machine.transition(STATES.EXPIRED);
    }
    if (this._flow.id === "booking") {
      const from = this._flow.currentId;
      if (from !== "datetime" && this._flow.goTo("datetime")) {
        this._emitter.emit("step:change", { from, to: "datetime", direction: "back" });
      }
    }
    const message = this._i18n.t("lock.expired");
    this._emitter.emit("error", { message, code: "lock_expired", recoverable: true });
    this._emitter.emit("announce", { message, politeness: "assertive" });
  }
  // ---- Availability data (for the calendar / slot UI) =================
  /** The service/employee/location that identify a selection, plus optional quantity. */
  _selectionParams({ quantity = false } = {}) {
    const params = {
      serviceId: this._ctx.serviceId,
      employeeId: this._ctx.employeeId,
      locationId: this._ctx.locationId
    };
    if (quantity) params.quantity = this._ctx.slotQuantity > 1 ? this._ctx.slotQuantity : null;
    return params;
  }
  /**
   * Run an availability request. Returns its data, or null when the request was
   * superseded (a newer selection aborted it) or failed — a failure surfaces via
   * `_toError`. Callers post-process the data (pick, emit, side effects).
   */
  async _load(call) {
    try {
      return await call();
    } catch (err) {
      if (err && err.aborted) return null;
      this._toError(err);
      return null;
    }
  }
  /**
   * Month availability map for the current selection —
   * `{ 'YYYY-MM-DD': { isBookable, hasAvailability, isBlackedOut } }`, or null if
   * superseded. Emits `data:loaded` (kind 'calendar').
   */
  async loadCalendar({ year, month } = {}) {
    const params = this._pruned({ ...this._selectionParams({ quantity: true }), year, month });
    const data = await this._load(() => this._api.calendar(params));
    if (data === null) return null;
    const calendar = data.calendar || {};
    this._emitter.emit("data:loaded", { kind: "calendar", items: calendar });
    return calendar;
  }
  /**
   * Bookable time slots for a date — `{ slots }`, or null if
   * superseded. Records the date on the context; emits `data:loaded` ('slots').
   */
  async loadSlots({ date } = {}) {
    const params = this._pruned({ date, ...this._selectionParams({ quantity: true }) });
    const data = await this._load(() => this._api.slots(params));
    if (data === null) return null;
    const slots = data.slots || [];
    this._ctx.date = date;
    this._emitter.emit("data:loaded", { kind: "slots", items: slots });
    return { slots };
  }
  // ---- Management mode (?manage=) =====================================
  /** Bootstrap the manage flow: load the reservation for the manage token. */
  async _startManage() {
    this._machine.transition(STATES.LOADING);
    try {
      await this._reloadReservation();
      this._machine.transition(STATES.BROWSING);
      this._announceStep("init");
      return this.getState();
    } catch (err) {
      this._toError(err);
      return this.getState();
    }
  }
  async _reloadReservation() {
    const data = await this._api.manageLoad({ token: this._manageToken });
    if (!data || data.success === false) {
      throw new ApiError(data && (data.message || data.error) || this._i18n.t("error.generic"), { code: "not_found" });
    }
    this._ctx.reservation = data;
    this._emitter.emit("manage:loaded", { reservation: data });
  }
  /** Cancel the managed booking. */
  manageCancel({ reason } = {}) {
    return this._manageAction(() => this._api.manageCancel({ token: this._manageToken, reason }), "manage:cancelled");
  }
  manageReduce(reduceBy = 1) {
    return this._manageAction((res) => this._api.manageReduce({ id: res.id, token: this._manageToken, reduceBy }), "manage:updated");
  }
  manageIncrease(increaseBy = 1) {
    return this._manageAction((res) => this._api.manageIncrease({ id: res.id, token: this._manageToken, increaseBy }), "manage:updated");
  }
  /** Run a management mutation on the loaded reservation, then reload and emit `event`. */
  async _manageAction(call, event) {
    const res = this._ctx.reservation;
    if (!res) return { ok: false };
    try {
      const result = await call(res);
      if (result && result.success === false) {
        throw new ApiError(result.message || result.error || this._i18n.t("error.generic"), { code: "manage" });
      }
      await this._reloadReservation().catch((err) => console.warn("Slots: reservation reload failed after update", err));
      this._emitter.emit(event, { reservation: this._ctx.reservation });
      return { ok: true };
    } catch (err) {
      this._emitRecoverableError(err);
      return { ok: false, error: err.message };
    }
  }
  // ---- Navigation =====================================================
  goNext() {
    const stepId = this._flow.currentId;
    const check = canLeaveStep(stepId, this._ctx, { requirePhone: this._config.requirePhone });
    if (!check.ok) {
      this._emitValidationError(check.errors);
      return { ok: false, errors: check.errors };
    }
    const to = this._flow.next();
    if (to === null) return { ok: false, atEnd: true };
    this._emitter.emit("step:change", { from: stepId, to, direction: "next" });
    this._announceStep();
    return { ok: true, stepId: to };
  }
  goBack() {
    const stepId = this._flow.currentId;
    const to = this._flow.back();
    if (to === null) return { ok: false, atStart: true };
    if (this._lock.held && SLOT_STEPS.has(stepId)) {
      this._lock.release("back-nav");
      this._ctx.lock = null;
      this._machine.transition(STATES.BROWSING);
    }
    this._emitter.emit("step:change", { from: stepId, to, direction: "back" });
    this._announceStep();
    return { ok: true, stepId: to };
  }
  /** Emit a recoverable `error` from a caught exception. */
  _emitRecoverableError(err) {
    this._emitter.emit("error", { message: err.message, code: err.code || "error", recoverable: true });
  }
  /** Emit a validation error with resolved per-field messages + a summary message. */
  _emitValidationError(errors) {
    const messages = {};
    for (const [field, key] of Object.entries(errors)) {
      messages[field] = this._i18n.t(key);
    }
    const first = Object.values(messages)[0];
    this._emitter.emit("error", {
      code: "validation",
      errors,
      // field → i18n key (for field targeting)
      messages,
      // field → resolved text
      message: first || this._i18n.t("error.generic"),
      recoverable: true
    });
  }
  _announceStep() {
    this._emitter.emit("announce", {
      message: this._i18n.t("announce.stepChanged", {
        position: this._flow.position,
        total: this._flow.total,
        title: this._flow.currentId
      }),
      politeness: "polite"
    });
  }
  // ---- Submit =========================================================
  /**
   * Validate, refresh the hold, and create the booking. Renderer-supplied
   * fields (captchaToken, honeypot) merge into the body; the core never reads
   * the DOM. Resolves to a result object; expected domain failures surface as
   * states/events, not exceptions.
   */
  async submit({ fields = {} } = {}) {
    const check = canLeaveStep("info", this._ctx, { requirePhone: this._config.requirePhone });
    if (!check.ok) {
      this._emitValidationError(check.errors);
      return { ok: false, errors: check.errors };
    }
    await this._lock.ensureFresh();
    if (!this._machine.transition(STATES.SUBMITTING)) {
      return { ok: false, code: "bad_state", state: this._machine.state };
    }
    try {
      const result = await this._api.createBooking(this._buildBookingBody(fields));
      if (result && result.success === false) {
        throw new ApiError(result.message || result.error || this._i18n.t("error.booking"), { code: "booking" });
      }
      if (result && result.redirectUrl) {
        this._machine.transition(STATES.PAYING);
        this._emitter.emit("payment:redirect", { url: result.redirectUrl });
        return { ok: true, paying: true, redirectUrl: result.redirectUrl };
      }
      if (result && result.paymentRequired && result.reservation) {
        this._ctx.lock = null;
        this._lock.destroy();
        this._machine.transition(STATES.PAYING);
        this._ctx.reservation = result.reservation;
        this._pendingPayment = {
          reservationId: result.reservation.id,
          token: result.reservation.token,
          paymentToken: null
        };
        this._emitter.emit("payment:required", { reservation: result.reservation });
        return { ok: true, paying: true, paymentRequired: true, reservation: result.reservation };
      }
      this._ctx.lock = null;
      this._lock.destroy();
      const reservation = result.reservation;
      this._ctx.reservation = reservation ?? null;
      this._machine.transition(STATES.CONFIRMED);
      this._emitter.emit("booking:confirmed", { reservation });
      return { ok: true, confirmed: true, reservation };
    } catch (err) {
      if (err && err.code === "expired") {
        this._lock.destroy();
        this._emitter.emit("lock:expired", {});
        return { ok: false, expired: true };
      }
      this._machine.transition(STATES.ERROR);
      this._emitRecoverableError(err);
      return { ok: false, error: err.message };
    }
  }
  // ---- Direct (Commerce-free) payments ================================
  /**
   * Create the gateway payment for the pending reservation produced by a
   * direct-payment `submit()`. Returns the gateway bootstrap the payment step
   * needs to mount Stripe Elements (`clientSecret`, `publishableKey`) plus the
   * signed `paymentToken` used to poll for confirmation. Returns null when
   * there is no pending direct payment (e.g. free booking, wrong mode).
   *
   * @returns {Promise<null | {clientSecret?: string, publishableKey?: string, paymentToken?: string, [k: string]: any}>}
   */
  async createDirectPayment() {
    if (!this._pendingPayment) return null;
    const res = await this._api.createPayment({
      reservationId: this._pendingPayment.reservationId,
      token: this._pendingPayment.token
    });
    if (res && res.paymentToken) {
      this._pendingPayment.paymentToken = res.paymentToken;
    }
    return res;
  }
  /**
   * Poll the gateway for the pending payment's status. The server is the source
   * of truth — `payment/confirm` finalizes the reservation (webhook-idempotent),
   * so when it reports `paid` we move the machine to `confirmed` and emit
   * `booking:confirmed` exactly as a free/Commerce booking would.
   *
   * @returns {Promise<{paid: boolean, status?: string, [k: string]: any}>}
   */
  async confirmDirectPayment() {
    if (!this._pendingPayment || !this._pendingPayment.paymentToken) {
      return { paid: false };
    }
    const res = await this._api.confirmPayment(this._pendingPayment.paymentToken);
    if (res && res.paid) {
      this._ctx.lock = null;
      this._lock.destroy();
      const confirmed = this._machine.state === STATES.CONFIRMED || this._machine.transition(STATES.CONFIRMED);
      if (confirmed) {
        this._emitter.emit("booking:confirmed", { reservation: this._ctx.reservation });
      }
    }
    return res || { paid: false };
  }
  _buildBookingBody(fields) {
    const body = this._pruned({
      serviceId: this._ctx.serviceId,
      employeeId: this._ctx.employeeId,
      locationId: this._ctx.locationId,
      date: this._ctx.date,
      time: this._ctx.time,
      quantity: this._ctx.quantity,
      customerName: this._ctx.customer.name,
      customerEmail: this._ctx.customer.email,
      customerPhone: this._ctx.customer.phone,
      notes: this._ctx.customer.notes,
      softLockToken: this._lock.token,
      siteHandle: this._config.siteHandle || ""
    });
    return { ...body, ...fields };
  }
  // ---- Lock passthrough ===============================================
  async releaseLock() {
    await this._lock.release("manual");
    this._ctx.lock = null;
    if (this._machine.state === STATES.HOLDING_LOCK) this._machine.transition(STATES.BROWSING);
  }
  // ---- Teardown / reset ===============================================
  reset() {
    this._lock.release("reset").catch(() => {
    });
    this._ctx = new Context({ quantity: this._config.defaultQuantity, locale: this._options.locale ?? null });
    this._flow.setContext(this._ctx);
    this._flow.reset();
    this._machine.hardReset();
    return this.getState();
  }
  destroy() {
    if (this._onUnload && typeof window !== "undefined") {
      window.removeEventListener("beforeunload", this._onUnload);
      this._onUnload = null;
    }
    this._lock.release("destroy").catch(() => {
    });
    this._api.abortAll();
    this._emitter.clear();
  }
  // ---- helpers ========================================================
  _toError(err) {
    if (err && err.aborted) return;
    this._machine.transition(STATES.ERROR);
    const message = err instanceof ApiError ? err.message : this._i18n.t("error.generic");
    this._emitter.emit("error", {
      message,
      code: err && err.code || "error",
      recoverable: true
    });
  }
  /** Drop null/undefined keys so the encoder and backend see only real values. */
  _pruned(obj) {
    const out = {};
    for (const [k, v] of Object.entries(obj)) {
      if (v !== null && v !== void 0) out[k] = v;
    }
    return out;
  }
};
function create(options) {
  return new Wizard(options);
}

// src/web/js/core/index.js
var version = "1.0.0-dev";
var index_default = { version, create };
