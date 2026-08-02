/**
 * Field validation — pure functions, no DOM, no i18n.
 *
 * Validators return an error *key* (i18n resolves the message) or null when
 * valid, so the renderer and the core share one validation source.
 */

// Local part + domain with a real TLD (≥2 chars), not just "example".
const EMAIL_RE =
  /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;

export function isValidEmail(email) {
  if (!email || typeof email !== 'string') return false;
  return EMAIL_RE.test(email.trim());
}

/** True when a value has non-whitespace content. */
export function isPresent(value) {
  return typeof value === 'string' ? value.trim().length > 0 : value != null;
}

/**
 * Validate the customer-info fields for the given requirements.
 * @param {{name?:string,email?:string,phone?:string}} customer
 * @param {{requirePhone?:boolean}} [opts]
 * @returns {{[field:string]: string}} field → error key (empty object = valid)
 */
export function validateCustomer(customer = {}, opts = {}) {
  const errors = {};
  if (!isPresent(customer.name)) errors.name = 'validation.nameRequired';
  if (!isPresent(customer.email)) errors.email = 'validation.emailRequired';
  else if (!isValidEmail(customer.email)) errors.email = 'validation.emailInvalid';
  if (opts.requirePhone && !isPresent(customer.phone)) errors.phone = 'validation.phoneRequired';
  return errors;
}

/** Clamp/validate a booking quantity. Returns an error key or null. */
export function validateQuantity(quantity, { min = 1, max = Infinity } = {}) {
  const n = Number(quantity);
  if (!Number.isInteger(n)) return 'validation.quantityInvalid';
  if (n < min) return 'validation.quantityTooLow';
  if (n > max) return 'validation.quantityTooHigh';
  return null;
}

/**
 * Can the user leave `stepId`? Central per-step gate the flow/renderer consult.
 * Returns `{ ok, errors }` where `errors` maps field → error key.
 */
export function canLeaveStep(stepId, ctx, opts = {}) {
  switch (stepId) {
    case 'service':
      return { ok: ctx.serviceId != null, errors: ctx.serviceId != null ? {} : { service: 'validation.serviceRequired' } };
    case 'datetime': {
      // A held lock (slot or range) means a valid selection was made.
      const ok = ctx.lock != null || (!!ctx.date && !!ctx.time);
      return { ok, errors: ok ? {} : { datetime: 'validation.slotRequired' } };
    }
    case 'info': {
      const errors = validateCustomer(ctx.customer, { requirePhone: opts.requirePhone });
      return { ok: Object.keys(errors).length === 0, errors };
    }
    default:
      // location / employee / review have no blocking validation here.
      return { ok: true, errors: {} };
  }
}
