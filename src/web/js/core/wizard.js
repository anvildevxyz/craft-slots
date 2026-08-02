/**
 * SlotsWizard — the headless facade that composes the core.
 *
 * Ties the lifecycle machine, step cursor, context, API client, lock timer,
 * i18n and validation into one driveable object. It makes NO DOM assumptions:
 * a renderer (M2) subscribes to its events; a headless caller drives it with
 * the programmatic methods. This is the semver'd public surface from
 * docs/WIZARD_CORE_DESIGN.md §4.
 */
import { Emitter } from './emitter.js';
import { Machine, STATES } from './machine.js';
import { Flow } from './flow.js';
import { Context } from './context.js';
import { SlotsApi, ApiError } from './api.js';
import { LockController } from './lock.js';
import { I18n } from './i18n.js';
import { canLeaveStep } from './validation.js';
import { bookingFlow } from './flows/booking.js';
import { manageFlow } from './flows/manage.js';

const FLOWS = { booking: bookingFlow, manage: manageFlow };

/** Steps whose selection acquires the soft lock — leaving one backwards drops the hold. */
const SLOT_STEPS = new Set(['datetime']);

/** Extract a list from a `{[key]: [...]}` JSON envelope. */
function list(payload, key) {
  return payload && Array.isArray(payload[key]) ? payload[key] : [];
}

export class Wizard {
  /** @param {import('./types.js').WizardOptions} [options] */
  constructor(options = {}) {
    this._options = options;
    this._emitter = new Emitter();

    this._config = {
      requirePhone: false,
      showNotes: true,
      defaultQuantity: 1,
      siteHandle: options.api?.site ?? null,
      ...(options.config ?? {}),
    };

    this._i18n = new I18n(
      { ...(options.labels ?? {}), ...(options.messages ?? {}) },
      { locale: options.locale ?? null },
    );

    // API client: accept an injected instance (tests) or build from config.
    this._api =
      options.apiClient ||
      new SlotsApi({
        baseUrl: options.api?.baseUrl,
        csrf: options.api?.csrf,
        site: options.api?.site,
        fetch: options.api?.fetch,
      });

    this._ctx = new Context({
      serviceId: options.serviceId ?? null,
      quantity: options.config?.defaultQuantity ?? 1,
      customer: options.customer ?? {},
      locale: options.locale ?? null,
    });

    // `?manage=` runs the management flow; otherwise the booking/event flow.
    this._mode = options.mode === 'manage' ? 'manage' : 'book';
    this._manageToken = options.manageToken ?? null;
    const flowName = this._mode === 'manage' ? 'manage' : (options.flow ?? 'booking');
    const flowDef = FLOWS[flowName];
    if (!flowDef) throw new Error(`Wizard: unknown flow "${options.flow}"`);
    this._flow = new Flow(flowDef, this._ctx);

    this._machine = new Machine(({ from, to, meta }) => {
      this._emitter.emit('state:change', { from, to, stepId: this._flow.currentId, meta });
    });

    this._lock = new LockController({ api: this._api, emit: (e, p) => this._emitter.emit(e, p) });

    // Direct-payment (Commerce-free) state: set after a `payment:required`
    // submit result and consumed by the payment step's createDirectPayment()/
    // confirmDirectPayment() calls. Null in every non-direct flow.
    this._pendingPayment = null;

    // React to the hold timer: a timer-driven expiry must clear the selection,
    // move the machine to `expired`, and send the user back to re-pick — none of
    // which happens on its own. `lock:expiring` drives the aria-live countdown.
    this._emitter.on('lock:expired', () => this._onLockExpired());
    this._emitter.on('lock:expiring', ({ remainingMs }) => {
      const minutes = Math.max(1, Math.ceil((remainingMs || 0) / 60000));
      this._emitter.emit('announce', { message: this._i18n.t('lock.expiring', { minutes }), politeness: 'polite' });
    });

    // Best-effort lock release on page unload (browser only).
    this._onUnload = null;
    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      this._onUnload = () => {
        const payload = this._lock.beaconPayload();
        if (payload) this._api.beaconRelease(payload.token);
      };
      window.addEventListener('beforeunload', this._onUnload);
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
      context: this._ctx.snapshot(),
    };
  }

  // ---- Lifecycle ======================================================

  /** Bootstrap: load payment settings + services, resolve preselects. */
  async start() {
    if (this._machine.state !== STATES.IDLE) return this.getState();
    if (this._mode === 'manage') return this._startManage();
    this._machine.transition(STATES.LOADING);
    try {
      const [payment, services] = await Promise.all([
        this._api.paymentSettings().catch(() => null),
        this._api.services(),
      ]);
      if (payment) this._applyPayment(payment);
      this._ctx.services = list(services, 'services');
      this._flow.setContext(this._ctx);
      this._emitter.emit('data:loaded', { kind: 'services', items: this._ctx.services });

      if (this._options.serviceId != null) {
        await this._loadServiceData(this._options.serviceId);
        // The integrator chose the service, so skip the service step even when
        // several services exist (the selection is still shown on review).
        this._ctx.servicePreselected = true;
      } else if (this._flow.id === 'booking' && this._ctx.services.length === 1) {
        // A lone service is auto-selected so its step can be skipped, the same
        // way a lone location/employee is handled in _loadServiceData(). Event
        // bookings carry no service, so the event flow is left alone.
        await this._loadServiceData(this._ctx.services[0].id);
      }

      // The cursor was seeded before any of the above resolved, so it can still
      // sit on a step the loaded selections just made invisible.
      this._flow.reset();

      // Deep links: an integrator may prefill location/date/time via config.
      await this._applyDeepLinks();

      this._machine.transition(STATES.BROWSING);
      this._announceStep('init');
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
    if (this._flow.id !== 'booking') return;

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
      this._flow.goTo('datetime');
    } else if (locationId != null || employeeId != null) {
      // Prefills may have hidden the step the cursor was seated on by the earlier
      // reset() (which runs before deep links); re-seat it on the first visible step.
      this._flow.reset();
    }

    if (locationId != null || employeeId != null || opts.date) {
      this._emitter.emit('deeplink:loaded', { locationId, employeeId, date: this._ctx.date, time: this._ctx.time });
    }
  }

  _applyPayment(payload) {
    this._ctx.payment = {
      enabled: !!payload.paymentEnabled,
      currency: payload.currency ?? null,
      currencySymbol: payload.currencySymbol ?? null,
    };
  }

  // ---- Selection ======================================================

  /** Load employees/locations for a service and set it in context. */
  async _loadServiceData(id) {
    const service = this._ctx.services.find((s) => s.id === id) ?? { id };
    this._ctx.setService(service);

    // Don't swallow: employees/locations are core to the flow, and a
    // failed employees load would otherwise silently drop the employee step.
    let employees;
    try {
      employees = await this._api.employees(id);
    } catch (err) {
      if (err && err.aborted) return;
      this._toError(err);
      return;
    }

    this._ctx.employees = list(employees, 'employees');
    this._ctx.locations = list(employees, 'locations');
    this._ctx.serviceHasSchedule = !!(employees && employees.serviceHasSchedule);

    // Required add-ons are pre-selected at quantity 1.

    // A lone location/employee is auto-selected so its step can be skipped.
    if (this._ctx.locations.length === 1) {
      this._ctx.selectedLocation = this._ctx.locations[0];
      this._ctx.locationId = this._ctx.locations[0].id;
    }
    if (this._ctx.employees.length === 1) {
      this._ctx.selectedEmployee = this._ctx.employees[0];
      this._ctx.employeeId = this._ctx.employees[0].id;
    }

    this._flow.setContext(this._ctx);
    this._emitter.emit('data:loaded', { kind: 'service', items: { employees: this._ctx.employees } });
  }

  async selectService(id) {
    await this._loadServiceData(id);
    this._emitter.emit('service:selected', { serviceId: id });
    return this.getState();
  }

  async selectLocation(id) {
    this._ctx.locationId = id;
    this._ctx.selectedLocation = this._ctx.locations.find((l) => l.id === id) ?? null;

    // Re-fetch employees scoped to the chosen location. Staff who can't work
    // this location must drop off the employee step — otherwise the customer can
    // pick one and land on an all-disabled calendar with no error. The backend
    // already supports the location filter.
    if (this._ctx.serviceId != null) {
      let employees;
      try {
        employees = await this._api.employees(this._ctx.serviceId, { locationId: id });
      } catch (err) {
        if (!(err && err.aborted)) this._toError(err);
        return this.getState();
      }
      this._ctx.employees = list(employees, 'employees');
      // A prior pick may not serve this location; reset, then auto-select a lone option.
      this._ctx.employeeId = null;
      this._ctx.selectedEmployee = null;
      if (this._ctx.employees.length === 1) {
        this._ctx.selectedEmployee = this._ctx.employees[0];
        this._ctx.employeeId = this._ctx.employees[0].id;
      }
      this._flow.setContext(this._ctx);
      this._emitter.emit('data:loaded', { kind: 'location', items: { employees: this._ctx.employees } });
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
    // Both counts track the picked quantity: `slotQuantity` drives lock/availability,
    // `quantity` is what the booking body posts and the price total multiplies by.
    this._ctx.slotQuantity = quantity;
    this._ctx.quantity = quantity;
    const body = this._pruned({ date, startTime: time, ...this._selectionParams({ quantity: true}) });
    return this._acquire('slot', body, () => this._emitter.emit('slot:selected', { date, time, quantity }));
  }



  async _acquire(kind, body, onSuccess, { bestEffort = false } = {}) {
    let res;
    try {
      res = await this._lock.acquire(kind, body);
    } catch (err) {
      this._syncLockAfterFailure();
      // Best-effort (events): the selection stands without a held lock.
      if (bestEffort) {
        onSuccess();
        return { acquired: false, bestEffort: true };
      }
      // The backend returns 400 (jsonError default) for a taken slot — surface it
      // as recoverable and stay on the step, not a fatal error.
      if (err && err.status === 400) {
        this._emitter.emit('error', {
          message: err.message || this._i18n.t('error.slotReserved'),
          code: 'slot_reserved',
          recoverable: true,
        });
        return { acquired: false, message: err.message };
      }
      this._toError(err);
      return { acquired: false, error: err.message };
    }
    // A concurrent acquisition was already in flight — drop this one silently.
    if (res.busy) return res;

    if (res.acquired) {
      this._ctx.lock = { token: res.token, expiresAt: res.expiresAt };
      this._machine.transition(STATES.HOLDING_LOCK);
      onSuccess();
    } else {
      // The prior lock (if any) was already released by acquire(); resync so
      // ctx.lock and the machine don't advertise a hold that no longer exists.
      this._syncLockAfterFailure();
      if (bestEffort) {
        onSuccess();
      } else {
        this._emitter.emit('error', {
          message: res.message || this._i18n.t('error.slotReserved'),
          code: 'slot_reserved',
          recoverable: true,
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
    // Send the booking flow back to re-pick a slot.
    if (this._flow.id === 'booking') {
      const from = this._flow.currentId;
      if (from !== 'datetime' && this._flow.goTo('datetime')) {
        this._emitter.emit('step:change', { from, to: 'datetime', direction: 'back' });
      }
    }
    // Surface after the step change (which clears stale errors), so the banner sticks.
    const message = this._i18n.t('lock.expired');
    this._emitter.emit('error', { message, code: 'lock_expired', recoverable: true });
    this._emitter.emit('announce', { message, politeness: 'assertive' });
  }

  // ---- Availability data (for the calendar / slot UI) =================

  /** The service/employee/location that identify a selection, plus optional quantity. */
  _selectionParams({ quantity = false } = {}) {
    const params = {
      serviceId: this._ctx.serviceId,
      employeeId: this._ctx.employeeId,
      locationId: this._ctx.locationId,
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
    const params = this._pruned({ ...this._selectionParams({ quantity: true}), year, month });
    const data = await this._load(() => this._api.calendar(params));
    if (data === null) return null;
    const calendar = data.calendar || {};
    this._emitter.emit('data:loaded', { kind: 'calendar', items: calendar });
    return calendar;
  }

  /**
   * Bookable time slots for a date — `{ slots }`, or null if
   * superseded. Records the date on the context; emits `data:loaded` ('slots').
   */
  async loadSlots({ date } = {}) {
    const params = this._pruned({ date, ...this._selectionParams({ quantity: true}) });
    const data = await this._load(() => this._api.slots(params));
    if (data === null) return null;
    const slots = data.slots || [];
    this._ctx.date = date;
    this._emitter.emit('data:loaded', { kind: 'slots', items: slots });
    return { slots };
  }





  // ---- Management mode (?manage=) =====================================

  /** Bootstrap the manage flow: load the reservation for the manage token. */
  async _startManage() {
    this._machine.transition(STATES.LOADING);
    try {
      await this._reloadReservation();
      this._machine.transition(STATES.BROWSING);
      this._announceStep('init');
      return this.getState();
    } catch (err) {
      this._toError(err);
      return this.getState();
    }
  }

  async _reloadReservation() {
    const data = await this._api.manageLoad({ token: this._manageToken });
    if (!data || data.success === false) {
      throw new ApiError((data && (data.message || data.error)) || this._i18n.t('error.generic'), { code: 'not_found' });
    }
    this._ctx.reservation = data;
    this._emitter.emit('manage:loaded', { reservation: data });
  }

  /** Cancel the managed booking. */
  manageCancel({ reason } = {}) {
    return this._manageAction(() => this._api.manageCancel({ token: this._manageToken, reason }), 'manage:cancelled');
  }

  manageReduce(reduceBy = 1) {
    return this._manageAction((res) => this._api.manageReduce({ id: res.id, token: this._manageToken, reduceBy }), 'manage:updated');
  }

  manageIncrease(increaseBy = 1) {
    return this._manageAction((res) => this._api.manageIncrease({ id: res.id, token: this._manageToken, increaseBy }), 'manage:updated');
  }

  /** Run a management mutation on the loaded reservation, then reload and emit `event`. */
  async _manageAction(call, event) {
    const res = this._ctx.reservation;
    if (!res) return { ok: false };
    try {
      const result = await call(res);
      if (result && result.success === false) {
        throw new ApiError(result.message || result.error || this._i18n.t('error.generic'), { code: 'manage' });
      }
      await this._reloadReservation().catch((err) => console.warn('Slots: reservation reload failed after update', err));
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
    this._emitter.emit('step:change', { from: stepId, to, direction: 'next' });
    this._announceStep();
    return { ok: true, stepId: to };
  }

  goBack() {
    const stepId = this._flow.currentId;
    const to = this._flow.back();
    if (to === null) return { ok: false, atStart: true };
    // Only leaving the slot-selection step itself drops the hold (the customer is
    // going back to re-pick). The lock is legitimately held across info/review, so
    // navigating back *within* those steps must NOT release it.
    if (this._lock.held && SLOT_STEPS.has(stepId)) {
      this._lock.release('back-nav');
      this._ctx.lock = null;
      this._machine.transition(STATES.BROWSING);
    }
    this._emitter.emit('step:change', { from: stepId, to, direction: 'back' });
    this._announceStep();
    return { ok: true, stepId: to };
  }

  /** Emit a recoverable `error` from a caught exception. */
  _emitRecoverableError(err) {
    this._emitter.emit('error', { message: err.message, code: err.code || 'error', recoverable: true });
  }

  /** Emit a validation error with resolved per-field messages + a summary message. */
  _emitValidationError(errors) {
    const messages = {};
    for (const [field, key] of Object.entries(errors)) {
      messages[field] = this._i18n.t(key);
    }
    const first = Object.values(messages)[0];
    this._emitter.emit('error', {
      code: 'validation',
      errors, // field → i18n key (for field targeting)
      messages, // field → resolved text
      message: first || this._i18n.t('error.generic'),
      recoverable: true,
    });
  }

  _announceStep() {
    this._emitter.emit('announce', {
      message: this._i18n.t('announce.stepChanged', {
        position: this._flow.position,
        total: this._flow.total,
        title: this._flow.currentId,
      }),
      politeness: 'polite',
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
    const check = canLeaveStep('info', this._ctx, { requirePhone: this._config.requirePhone });
    if (!check.ok) {
      this._emitValidationError(check.errors);
      return { ok: false, errors: check.errors };
    }

    await this._lock.ensureFresh();
    if (!this._machine.transition(STATES.SUBMITTING)) {
      return { ok: false, code: 'bad_state', state: this._machine.state };
    }

    try {
      const result = await this._api.createBooking(this._buildBookingBody(fields));
      if (result && result.success === false) {
        throw new ApiError(result.message || result.error || this._i18n.t('error.booking'), { code: 'booking' });
      }
      if (result && result.redirectUrl) {
        this._machine.transition(STATES.PAYING);
        this._emitter.emit('payment:redirect', { url: result.redirectUrl });
        return { ok: true, paying: true, redirectUrl: result.redirectUrl };
      }
      // Direct payment: booking created *pending*, paid in-page before confirming.
      if (result && result.paymentRequired && result.reservation) {
        // Drop the soft lock before PAYING so its expiry can't strand a paid booking.
        this._ctx.lock = null;
        this._lock.destroy();
        this._machine.transition(STATES.PAYING);
        this._ctx.reservation = result.reservation;
        this._pendingPayment = {
          reservationId: result.reservation.id,
          token: result.reservation.token,
          paymentToken: null,
        };
        this._emitter.emit('payment:required', { reservation: result.reservation });
        return { ok: true, paying: true, paymentRequired: true, reservation: result.reservation };
      }
      this._ctx.lock = null;
      this._lock.destroy();
      const reservation = result.reservation;
      // Set the reservation on the context BEFORE the CONFIRMED transition: the
      // renderer shows the success step on `state:change → confirmed`, so the
      // id/status/appointment must already be in context or the screen renders
      // with an empty booking id.
      this._ctx.reservation = reservation ?? null;
      this._machine.transition(STATES.CONFIRMED);
      this._emitter.emit('booking:confirmed', { reservation });
      return { ok: true, confirmed: true, reservation };
    } catch (err) {
      if (err && err.code === 'expired') {
        // The lock is gone server-side: tear down the client hold and emit the
        // same `lock:expired` a timer expiry would — the internal handler runs
        // the recovery (clears lock, → expired, back to re-pick).
        this._lock.destroy();
        this._emitter.emit('lock:expired', {});
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
      token: this._pendingPayment.token,
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
      // Only announce the booking as confirmed if the machine actually reached
      // CONFIRMED — never emit booking:confirmed from an inconsistent state.
      const confirmed =
        this._machine.state === STATES.CONFIRMED || this._machine.transition(STATES.CONFIRMED);
      if (confirmed) {
        this._emitter.emit('booking:confirmed', { reservation: this._ctx.reservation });
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
      siteHandle: this._config.siteHandle || '',
    });
    return { ...body, ...fields };
  }


  // ---- Lock passthrough ===============================================
  async releaseLock() {
    await this._lock.release('manual');
    this._ctx.lock = null;
    if (this._machine.state === STATES.HOLDING_LOCK) this._machine.transition(STATES.BROWSING);
  }

  // ---- Teardown / reset ===============================================
  reset() {
    this._lock.release('reset').catch(() => {});
    this._ctx = new Context({ quantity: this._config.defaultQuantity, locale: this._options.locale ?? null });
    this._flow.setContext(this._ctx);
    this._flow.reset();
    this._machine.hardReset();
    return this.getState();
  }

  destroy() {
    if (this._onUnload && typeof window !== 'undefined') {
      window.removeEventListener('beforeunload', this._onUnload);
      this._onUnload = null;
    }
    this._lock.release('destroy').catch(() => {});
    this._api.abortAll();
    this._emitter.clear();
  }

  // ---- helpers ========================================================
  _toError(err) {
    // AbortedError means a superseded request — ignore rather than surfacing.
    if (err && err.aborted) return;
    this._machine.transition(STATES.ERROR);
    const message = err instanceof ApiError ? err.message : this._i18n.t('error.generic');
    this._emitter.emit('error', {
      message,
      code: (err && err.code) || 'error',
      recoverable: true,
    });
  }

  /** Drop null/undefined keys so the encoder and backend see only real values. */
  _pruned(obj) {
    const out = {};
    for (const [k, v] of Object.entries(obj)) {
      if (v !== null && v !== undefined) out[k] = v;
    }
    return out;
  }
}

/** Public factory — the documented entry point. */
export function create(options) {
  return new Wizard(options);
}
