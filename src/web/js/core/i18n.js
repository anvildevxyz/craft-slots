/**
 * i18n table for strings the core generates at runtime.
 *
 * Consolidates today's split, where JS-side strings lived in `config.messages`
 * / `config.stepAnnouncements` while most labels were baked into Twig. The core
 * only owns the strings it emits itself (announcements, countdown, errors,
 * validation); the renderer still gets its static markup labels from Twig.
 *
 * Every key has a hardcoded English default here, so a missing translation
 * degrades to English — never to a raw key — matching the current behavior.
 * `{token}` placeholders are interpolated from the params object.
 */

export const DEFAULTS = Object.freeze({
  'announce.stepChanged': 'Step {position} of {total}: {title}',

  'lock.expiring': 'Your reservation is held for {minutes} more minute(s).',
  'lock.expired': 'Your reserved time has expired. Please choose a time again.',

  'calendar.prevMonth': 'Previous month',
  'calendar.nextMonth': 'Next month',

  'slot.seatsAvailable': '{count} available',

  'error.generic': 'Something went wrong. Please try again.',
  'error.booking': 'Your booking could not be completed.',
  'error.slotReserved': 'That time was just taken. Please choose another.',

  'validation.serviceRequired': 'Please choose a service.',
  'validation.slotRequired': 'Please choose a date and time.',
  'validation.nameRequired': 'Please enter your name.',
  'validation.emailRequired': 'Please enter your email address.',
  'validation.emailInvalid': 'Please enter a valid email address.',
  'validation.phoneRequired': 'Please enter your phone number.',
  'validation.quantityInvalid': 'Please enter a valid quantity.',
  'validation.quantityTooLow': 'Quantity is too low.',
  'validation.quantityTooHigh': 'Not enough capacity for that quantity.',
});

function interpolate(template, params) {
  if (!params) return template;
  return template.replace(/\{(\w+)\}/g, (match, token) =>
    Object.prototype.hasOwnProperty.call(params, token) ? String(params[token]) : match,
  );
}

export class I18n {
  /**
   * @param {Object<string,string>} [table]  overrides merged over DEFAULTS
   * @param {{locale?: string}} [opts]
   */
  constructor(table = {}, { locale = null } = {}) {
    this._table = { ...DEFAULTS, ...(table || {}) };
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
}
