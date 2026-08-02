/**
 * Flow-step engine — the step cursor.
 *
 * A flow is an ordered list of steps, each with a `visible(ctx)` predicate.
 * Navigation walks to the next/previous step whose predicate is true, so
 * forward and backward directions share a *single source of truth* — the two
 * can never disagree about which steps to skip.
 *
 * The engine is pure: it holds no state beyond the current index and never
 * touches the DOM. `context` is supplied by the caller on every query so
 * visibility always reflects live selection state.
 */

/**
 * @typedef {Object} FlowStep
 * @property {string} id
 * @property {(ctx: any) => boolean} [visible]  defaults to always-visible
 */

/**
 * @typedef {Object} FlowDefinition
 * @property {string} id                'booking' | 'event' | …
 * @property {FlowStep[]} steps
 */

export class Flow {
  /**
   * @param {FlowDefinition} definition
   * @param {any} context  the wizard context object (read on every query)
   */
  constructor(definition, context) {
    if (!definition || !Array.isArray(definition.steps) || definition.steps.length === 0) {
      throw new TypeError('Flow: definition.steps must be a non-empty array');
    }
    this.id = definition.id;
    this._steps = definition.steps.map((s) => ({
      id: s.id,
      visible: typeof s.visible === 'function' ? s.visible : () => true,
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
}
