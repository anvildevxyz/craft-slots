/**
 * Shared JSDoc typedefs for the wizard's cross-module shapes.
 *
 * JSDoc-only — this module has no runtime exports. Reference a type with
 * `@typedef {import('./types.js').Service} Service`. The API shapes
 * are the contract emitted by the plugin's `/slots/api/v1` controllers.
 */

// ---- API response entities ------------------------------------------------

/**
 * @typedef {Object} Service
 * @property {number} id
 * @property {string} title            services expose `title` (not `name`)
 * @property {number} price
 * @property {number} duration
 */

/** @typedef {{ id: number, name: string }} Employee */
/** @typedef {{ id: number, name: string }} Location */

/** @typedef {{ time: string, availableCapacity?: number|null }} Slot */

/**
 */

/** @typedef {{ isBookable: boolean, hasAvailability: boolean, isBlackedOut: boolean }} CalendarDay */

/** @typedef {{ success?: boolean, token?: string, expiresIn?: number, expiresAt?: number|string, message?: string, error?: string }} LockResponse */

/**
 * Reservation as returned by the manage endpoint. `serviceName` is null for
 * event bookings (the endpoint has no event-title field).
 * @typedef {Object} Reservation
 * @property {number} id
 * @property {?string} serviceName
 * @property {string} status
 * @property {string} statusLabel
 * @property {string} formattedDateTime
 * @property {number} quantity
 * @property {?string} customerName
 * @property {boolean} canCancel
 */

// ---- Core shapes ----------------------------------------------------------

/**
 * @typedef {Object} WizardOptions
 * @property {'booking'|'event'} [flow]
 * @property {'book'|'manage'} [mode]
 * @property {Element|string} [mount]      renderer target; omit for headless
 * @property {number} [serviceId]          preselect
 * @property {string} [manageToken]        reservation token (manage mode)
 * @property {string} [locale]
 * @property {{ baseUrl?: string, site?: string, csrf?: { name: string, value: string }, fetch?: typeof fetch }} [api]
 * @property {{ requirePhone?: boolean, showNotes?: boolean, defaultQuantity?: number }} [config]
 * @property {Object<string,string>} [labels]
 * @property {Object<string,string>} [messages]
 * @property {{ provider: string, siteKey: string, action?: string }} [captcha]
 * @property {string} [nonce]
 * @property {import('./api.js').SlotsApi} [apiClient]  inject an API client (tests)
 */

/** A per-step content renderer. @typedef {{ mount?: Function, render: Function, unmount?: Function }} StepRenderer */

/** Discriminated `data:loaded` payload; `items` is the list for `kind`. @typedef {{ kind: string, items: any }} DataLoadedPayload */

/** Recoverable error signal. `messages`/`errors` are present for validation. @typedef {{ message?: string, code: string, recoverable: boolean, errors?: Object, messages?: Object }} ErrorPayload */

export {};
