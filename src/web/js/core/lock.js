/**
 * Soft-lock lifecycle + hold timer.
 *
 * The core owns the hold timer: it announces `lock:expiring` at the warning
 * threshold, performs a single silent auto-extend when the user commits
 * (submit/pay), and fires `lock:expired` when the hold is truly gone. Clock
 * authority stays server-side — the timer runs off the server-provided
 * `expiresIn`/`expiresAt`, never a client-guessed duration.
 *
 * The controller is transport- and DOM-agnostic: it takes an `api` (the v1
 * client's lock methods) and an `emit` function. Clock and timer functions are
 * injectable so the whole lifecycle is deterministic under test.
 */

/** Resolve an absolute expiry (ms epoch) from a lock response. */
function resolveExpiry(result, now) {
  if (!result) return null;
  if (typeof result.expiresAt === 'number') return result.expiresAt;
  if (typeof result.expiresAt === 'string') {
    const t = Date.parse(result.expiresAt);
    if (!Number.isNaN(t)) return t;
  }
  if (typeof result.expiresIn === 'number') return now() + result.expiresIn * 1000;
  return null;
}

const KIND_METHOD = {
  slot: 'createSlotLock',
};

export class LockController {
  /**
   * @param {Object} opts
   * @param {Object} opts.api                     v1 client (createSlotLock/…, extendLock, releaseLock)
   * @param {(event: string, payload: any) => void} opts.emit
   * @param {number} [opts.warningThresholdMs]    fire lock:expiring this long before expiry (default 60s)
   * @param {() => number} [opts.now]             clock (default Date.now)
   * @param {typeof setTimeout} [opts.setTimer]
   * @param {typeof clearTimeout} [opts.clearTimer]
   */
  constructor({ api, emit, warningThresholdMs = 60_000, now, setTimer, clearTimer } = {}) {
    if (!api) throw new Error('LockController: api is required');
    this._api = api;
    this._emit = typeof emit === 'function' ? emit : () => {};
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

    // Guard against concurrent acquisitions (e.g. a double-click on two slots):
    // without this, both requests see held===false and each creates a lock,
    // orphaning one server-side. The second call is dropped.
    if (this._acquiring) return { acquired: false, busy: true };
    this._acquiring = true;
    try {
      // Release any lock we're currently holding before taking a new one.
      if (this.held) await this.release('superseded');

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
      this._emit('lock:acquired', { token: this._token, expiresAt: this._expiresAt });
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
    if (this._expiresAt === null) return; // no expiry info → no timer (best-effort lock)

    const remaining = this._expiresAt - this._now();
    const warnIn = remaining - this._warn;

    if (warnIn <= 0) {
      // Already inside the warning window: announce now (once).
      if (!this._warned) {
        this._warned = true;
        this._emit('lock:expiring', { remainingMs: Math.max(0, remaining) });
      }
    } else {
      this._warnHandle = this._setTimer(() => {
        this._warnHandle = null;
        this._warned = true;
        this._emit('lock:expiring', { remainingMs: this.remainingMs });
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
    this._emit('lock:expired', {});
  }

  /**
   * Called when the user commits (submit/pay). If the hold is inside the
   * warning window and hasn't been extended yet, perform exactly one silent
   * server-side extension. Idempotent and safe to call when no lock is held.
   */
  async ensureFresh() {
    if (!this.held || this._extended) return;
    if (this.remainingMs > this._warn) return; // still plenty of time

    // Suspend the expiry timer for the duration of the extend request. Otherwise
    // the pre-armed expiry can fire mid-flight, null the token, and then the
    // successful extend would re-arm timers against a token that's gone — the
    // booking would submit with no lock while a valid one leaks server-side.
    this._clearTimers();

    let result;
    try {
      result = await this._api.extendLock({ token: this._token });
    } catch {
      // Extension failed (incl. a 410): re-arm against the current expiry so the
      // lock still expires locally on schedule.
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
    this._emit('lock:extended', { expiresAt: this._expiresAt });
  }

  /**
   * Release the current lock server-side and stop the timer. Best-effort:
   * a transport failure is swallowed (the lock expires naturally) but the
   * local state is always cleared and `lock:released` emitted.
   */
  async release(reason = 'released') {
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
      // ignore — server-side TTL will reclaim it
    }
    this._emit('lock:released', { reason });
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
}
