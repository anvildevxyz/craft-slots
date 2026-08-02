/**
 * Slots wizard — default (rendered) entry point.
 *
 * This is what the Twig include loads and exposes as `SlotsWizard`. It builds
 * the headless core Wizard and, when a `mount` target is given, attaches the
 * vanilla Renderer to it. Omitting `mount` yields the pure headless core — the
 * same object, no DOM touched — which is the "bring your own frontend" path.
 *
 * The headless-only build (`core/index.js`) ships the core without this layer.
 */
import { Wizard } from '../core/wizard.js';
import { Renderer } from './renderer.js';
import { setupCaptcha } from './captcha.js';
import { serviceListStep } from './steps/service-list.js';
import { locationStep } from './steps/location.js';
import { employeeStep } from './steps/employee.js';
import { datetimeStep } from './steps/datetime.js';
import { customerInfoStep } from './steps/customer-info.js';
import { reviewStep } from './steps/review.js';
import { successStep } from './steps/success.js';
import { manageStep } from './steps/manage.js';
import { createPaymentStep } from './steps/payment.js';

export const version = '1.0.0';

/** Register the built-in step content renderers on a Renderer instance. */
function registerDefaultSteps(renderer) {
  // Booking flow
  renderer.registerStep('service', serviceListStep);
  renderer.registerStep('location', locationStep);
  renderer.registerStep('employee', employeeStep);
  renderer.registerStep('datetime', datetimeStep);
  // Shared
  renderer.registerStep('info', customerInfoStep);
  renderer.registerStep('review', reviewStep);
  renderer.registerStep('success', successStep);
  // Direct (Commerce-free) Stripe payment; shown on `payment:required`.
  renderer.registerStep('payment', createPaymentStep());
  // Manage flow
  renderer.registerStep('manage', manageStep);
}

/** Resolve a mount option (selector string or element) to an element. */
function resolveMount(mount) {
  if (!mount) return null;
  if (typeof mount === 'string') return document.querySelector(mount);
  if (mount instanceof Element) return mount;
  return null;
}

/**
 * Create a wizard. With `options.mount`, a Renderer is attached and the wizard
 * is started automatically; the returned object carries `wizard`, `renderer`,
 * and a `destroy()` that tears both down. Without `mount`, returns the bare
 * headless Wizard.
 *
 * @param {Object} options  same shape as core Wizard options, plus `mount`
 */
export function create(options = {}) {
  const wizard = new Wizard(options);
  const root = resolveMount(options.mount);

  if (!root) {
    return wizard; // headless
  }

  const renderer = new Renderer(wizard, root);
  registerDefaultSteps(renderer);

  // Optional captcha: loads the vendor widget and feeds the token to submit.
  if (options.captcha && options.captcha.provider) {
    setupCaptcha(options.captcha, root, { nonce: options.nonce })
      .then((captcha) => captcha && renderer.setCaptcha(captcha))
      .catch((err) => console.warn('Slots: captcha setup failed', err));
  }
  // Show the initial step the first time the core reaches `browsing` (the very
  // first transition is idle→loading, so `once` would miss browsing).
  const offReady = wizard.on('state:change', ({ to }) => {
    if (to === 'browsing') {
      offReady();
      renderer.syncInitial();
    }
  });

  const controller = {
    wizard,
    renderer,
    start: () => wizard.start(),
    destroy: () => {
      renderer.destroy();
      wizard.destroy();
    },
  };

  if (options.autoStart !== false) {
    // Fire and forget; consumers can await controller.start() instead.
    wizard.start();
  }

  return controller;
}

/**
 * Auto-initialize every `[data-slots-wizard][data-slots-auto]` element from a
 * JSON config block it contains (`<script type="application/json"
 * data-slots-config>`). Reading config from a non-executable JSON script keeps
 * the page CSP-clean — the template needs no inline executable script. Runs
 * automatically on load; also callable for dynamically inserted wizards.
 *
 * @param {ParentNode} [root]
 * @returns {Array} the created controllers
 */
export function autoInit(root = typeof document !== 'undefined' ? document : null) {
  if (!root || typeof root.querySelectorAll !== 'function') return [];
  const controllers = [];
  for (const el of root.querySelectorAll('[data-slots-wizard][data-slots-auto]')) {
    if (el.__slotsController) continue; // idempotent
    let config = {};
    const cfgEl = el.querySelector('script[type="application/json"][data-slots-config]');
    if (cfgEl) {
      try {
        config = JSON.parse(cfgEl.textContent || '{}');
      } catch (err) {
        // Author-rendered JSON — a parse error is a template bug worth surfacing.
        console.error('Slots: invalid wizard config JSON', err);
      }
    }
    const controller = create({ ...config, mount: el });
    el.__slotsController = controller;
    controllers.push(controller);
  }
  return controllers;
}

// Bootstrap on load in a browser. Guarded so importing the module in Node/tests
// (or the headless build) never touches the DOM.
if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => autoInit());
  } else {
    Promise.resolve().then(() => autoInit());
  }
}

export { Wizard, Renderer };
export default { version, create, autoInit };
