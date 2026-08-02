/**
 * Wizard context — the selection state plus read-only computed getters.
 *
 * This is the single object the flow predicates and the renderer read. The
 * pricing/duration getters compute a client-side display total; the server
 * stays authoritative at booking time.
 *
 * The context holds no DOM and emits nothing; mutations go through small
 * setters so the wizard can react and the flow predicates see live values.
 */

export class Context {
  constructor(initial = {}) {
    // Selection
    this.serviceId = initial.serviceId ?? null;
    this.selectedService = initial.selectedService ?? null;
    this.locationId = initial.locationId ?? null;
    this.selectedLocation = initial.selectedLocation ?? null;
    this.employeeId = initial.employeeId ?? null;
    this.selectedEmployee = initial.selectedEmployee ?? null;
    // Set when the integrator preselected the service/location (deep link /
    // config), so that step is skipped even when several options exist.
    this.servicePreselected = initial.servicePreselected ?? false;
    this.locationPreselected = initial.locationPreselected ?? false;

    // Data lists (drive flow visibility predicates)
    this.services = initial.services ?? [];
    this.locations = initial.locations ?? [];
    this.employees = initial.employees ?? [];

    // Event flow selection

    // Site locale (BCP-47) — drives the calendar's localized month/weekday names.
    this.locale = initial.locale ?? null;


    // Date/time
    this.date = initial.date ?? null;
    this.time = initial.time ?? null;
    this.quantity = initial.quantity ?? 1;
    this.slotQuantity = initial.slotQuantity ?? 1;

    // Flags resolved from service/employee data loads
    this.serviceHasSchedule = initial.serviceHasSchedule ?? false;

    // Customer
    this.customer = { name: '', email: '', phone: '', notes: '', ...(initial.customer ?? {}) };

    // Payment context (from payment-settings)
    this.payment = {
      enabled: false,
      currency: null,
      currencySymbol: null,
      ...(initial.payment ?? {}),
    };

    // Soft-lock: { token, expiresAt } | null
    this.lock = initial.lock ?? null;

    // Manage mode: the loaded reservation | null
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
    // Selecting a service invalidates downstream selections.
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
      requiresPayment: this.requiresPayment,
    };
  }
}
