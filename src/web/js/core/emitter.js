/**
 * Tiny synchronous event emitter — the core's only pub/sub primitive.
 *
 * Zero dependencies, no DOM. Handlers run in subscription order. A throwing
 * handler must not stop the others or corrupt the wizard, so throws are
 * isolated and reported through the reserved `error` channel where possible.
 */
export class Emitter {
  constructor() {
    /** @type {Map<string, Set<Function>>} */
    this._handlers = new Map();
  }

  /**
   * Subscribe to `event`. Returns an unsubscribe function.
   * @returns {() => void}
   */
  on(event, handler) {
    if (typeof handler !== 'function') {
      throw new TypeError('emitter.on: handler must be a function');
    }
    let set = this._handlers.get(event);
    if (!set) {
      set = new Set();
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
    // Snapshot so on/off during emit doesn't mutate the iteration.
    for (const handler of [...set]) {
      try {
        handler(payload);
      } catch (err) {
        // A subscriber's throw must not break the others. Log with its stack (a
        // handler bug is a programmer error), then surface a recoverable signal.
        console.error('Slots wizard: event handler threw for', event, err);
        if (event !== 'error') {
          this.emit('error', {
            message: err && err.message ? err.message : String(err),
            code: 'handler_exception',
            recoverable: true,
          });
        }
      }
    }
  }

  /** Drop every subscription (used by wizard.destroy()). */
  clear() {
    this._handlers.clear();
  }
}
