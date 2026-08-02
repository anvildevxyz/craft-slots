/**
 * Lifecycle state machine — the "what phase is this booking in" dimension.
 *
 * Deliberately separate from the step cursor (see flow.js). Every transition is
 * declared and illegal ones are rejected, so races like back-navigation while a
 * lock is held, or slot expiry mid-payment, are explicit and guarded rather
 * than implicit side effects.
 *
 * States and legal targets mirror docs/WIZARD_CORE_DESIGN.md §3.1.
 */

export const STATES = Object.freeze({
  IDLE: 'idle',
  LOADING: 'loading',
  BROWSING: 'browsing',
  HOLDING_LOCK: 'holdingLock',
  SUBMITTING: 'submitting',
  PAYING: 'paying',
  CONFIRMED: 'confirmed',
  EXPIRED: 'expired',
  ERROR: 'error',
});

/**
 * Adjacency table: state → set of states it may transition to.
 * `browsing → browsing` is intentionally allowed so a step-cursor move can
 * re-emit `state:change` uniformly (the machine treats it as a self-transition).
 */
const TRANSITIONS = Object.freeze({
  [STATES.IDLE]: [STATES.LOADING],
  [STATES.LOADING]: [STATES.BROWSING, STATES.ERROR],
  // SUBMITTING is reachable directly for lock-less flows (events), whose
  // server-side seat lock is best-effort and may not be held.
  [STATES.BROWSING]: [STATES.BROWSING, STATES.HOLDING_LOCK, STATES.SUBMITTING, STATES.ERROR],
  // HOLDING_LOCK → HOLDING_LOCK: re-picking a slot while already holding one.
  [STATES.HOLDING_LOCK]: [STATES.HOLDING_LOCK, STATES.BROWSING, STATES.SUBMITTING, STATES.EXPIRED, STATES.ERROR],
  [STATES.SUBMITTING]: [STATES.CONFIRMED, STATES.PAYING, STATES.EXPIRED, STATES.ERROR],
  [STATES.PAYING]: [STATES.CONFIRMED, STATES.EXPIRED, STATES.ERROR],
  [STATES.CONFIRMED]: [STATES.IDLE], // via reset()
  // EXPIRED → HOLDING_LOCK: re-acquiring a fresh lock after an expiry recovers.
  [STATES.EXPIRED]: [STATES.HOLDING_LOCK, STATES.BROWSING, STATES.IDLE],
  [STATES.ERROR]: [STATES.BROWSING, STATES.HOLDING_LOCK, STATES.IDLE], // recover / retry / reset
});

export class Machine {
  /**
   * @param {(change: {from: string, to: string, meta: any}) => void} [onChange]
   *        called after every successful transition (the wizard forwards this
   *        to the emitter as `state:change`).
   */
  constructor(onChange) {
    this._state = STATES.IDLE;
    this._onChange = typeof onChange === 'function' ? onChange : () => {};
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
    if (from !== STATES.IDLE) this._onChange({ from, to: STATES.IDLE, meta: { reason: 'reset' } });
  }
}
