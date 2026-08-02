/**
 * Slots headless wizard core — public entry point. Exports the `create()`
 * facade plus its building blocks.
 *
 * `version` is the core contract version, semver'd independently of the plugin:
 * additions bump minor, removals/renames bump major.
 */
export const version = '1.0.0-dev';

export { Wizard, create } from './wizard.js';
export { Emitter } from './emitter.js';
export { Machine, STATES } from './machine.js';
export { Flow } from './flow.js';
export { bookingFlow } from './flows/booking.js';
export { SlotsApi, ApiError, AbortedError } from './api.js';
export { Context } from './context.js';
export { LockController } from './lock.js';
export { I18n, DEFAULTS as I18N_DEFAULTS } from './i18n.js';
export {
  isValidEmail,
  isPresent,
  validateCustomer,
  validateQuantity,
  canLeaveStep,
} from './validation.js';

import { create } from './wizard.js';
export default { version, create };
