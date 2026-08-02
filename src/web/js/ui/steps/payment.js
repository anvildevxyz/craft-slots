/**
 * Payment step renderer — the in-page Stripe checkout for direct payments, shown
 * when `submit()` returns `paymentRequired`. Drives the core's public methods:
 * createDirectPayment() → mount the Payment Element → confirm → poll confirmDirectPayment().
 *
 * CSP: direct-pay pages must allow Stripe (script-src/frame-src js.stripe.com,
 * connect-src api.stripe.com) — the one external script the wizard needs.
 */
import { qs, setText, setHidden } from '../dom.js';

const STRIPE_JS_SRC = 'https://js.stripe.com/v3/';

// Module-level so concurrent wizards on one page share a single script load.
let stripeJsPromise = null;

/**
 * Load Stripe.js once and resolve with the global `Stripe` constructor.
 * @param {Window} win
 * @returns {Promise<Function>}
 */
export function loadStripeJs(win) {
  const w = win || (typeof window !== 'undefined' ? window : null);
  if (!w || !w.document) return Promise.reject(new Error('Stripe.js requires a browser'));
  if (w.Stripe) return Promise.resolve(w.Stripe);
  if (stripeJsPromise) return stripeJsPromise;

  stripeJsPromise = new Promise((resolve, reject) => {
    let script = w.document.querySelector('script[data-slots-stripe-js]');
    if (!script) {
      script = w.document.createElement('script');
      script.src = STRIPE_JS_SRC;
      script.async = true;
      script.setAttribute('data-slots-stripe-js', '');
      (w.document.head || w.document.body).appendChild(script);
    }
    const finish = () => (w.Stripe ? resolve(w.Stripe) : reject(new Error('Stripe.js loaded without a global')));
    const fail = () => {
      // Remove the dead <script> before allowing a retry: a failed script never
      // re-fires load/error, so re-listening to it would hang every later load.
      script.remove();
      stripeJsPromise = null;
      reject(new Error('Stripe.js failed to load'));
    };
    script.addEventListener('load', finish);
    script.addEventListener('error', fail);
  });
  return stripeJsPromise;
}

/**
 * Build the payment step renderer.
 *
 * @param {Object} [opts]
 * @param {Window} [opts.win]           injectable window (tests)
 * @param {number} [opts.pollDelayMs]   delay between confirm polls (default 1500)
 * @param {number} [opts.maxPolls]      max confirm polls before deferring to the webhook (default 8)
 */
export function createPaymentStep(opts = {}) {
  const pollDelayMs = opts.pollDelayMs ?? 1500;
  const maxPolls = opts.maxPolls ?? 8;
  const getWin = () => opts.win || (typeof window !== 'undefined' ? window : null);
  const wait = (ms) =>
    new Promise((resolve) => {
      const w = getWin();
      (w && w.setTimeout ? w.setTimeout : setTimeout)(resolve, ms);
    });

  // Per-mount state; a wizard mounts the payment step at most once per booking.
  const state = { stripe: null, elements: null, started: false, paying: false };

  return {
    async mount(region, wizard) {
      const t = (k, p) => wizard.t(k, p);
      const els = {
        status: qs('[data-slots-payment-status]', region),
        error: qs('[data-slots-payment-error]', region),
        pay: qs('[data-slots-action="pay"]', region),
        target: qs('[data-slots-payment-element]', region),
      };
      const showError = (msg) => {
        if (els.error) {
          setText(els.error, msg);
          setHidden(els.error, false);
        }
      };
      const clearError = () => {
        if (els.error) {
          setText(els.error, '');
          setHidden(els.error, true);
        }
      };
      const setStatus = (msg) => els.status && setText(els.status, msg || '');
      const setPayEnabled = (on) => {
        if (els.pay) els.pay.disabled = !on;
      };

      if (els.pay) {
        setPayEnabled(false);
        els.pay.addEventListener('click', (e) => {
          e.preventDefault();
          this._pay(wizard, { t, els, showError, clearError, setStatus, setPayEnabled });
        });
      }

      await this._begin(wizard, { t, els, showError, clearError, setStatus, setPayEnabled });
    },

    /** Create the payment, load Stripe, and mount the Payment Element. */
    async _begin(wizard, ui) {
      if (state.started) return;
      state.started = true;
      const { t, els, showError, clearError, setStatus, setPayEnabled } = ui;
      clearError();
      setStatus(t('payment.preparing'));
      try {
        const res = await wizard.createDirectPayment();
        if (!res || !res.clientSecret) throw new Error(t('payment.unavailable'));
        const publishableKey = res.config && res.config.publishableKey;
        if (!publishableKey) throw new Error(t('payment.unavailable'));

        const Stripe = await loadStripeJs(getWin());
        state.stripe = Stripe(publishableKey);
        state.elements = state.stripe.elements({ clientSecret: res.clientSecret });
        const paymentElement = state.elements.create('payment');
        paymentElement.mount(els.target || '[data-slots-payment-element]');

        setStatus('');
        setPayEnabled(true);
      } catch (err) {
        setStatus('');
        showError((err && err.message) || t('payment.unavailable'));
      }
    },

    /** Confirm the payment with Stripe, then poll the server for finalization. */
    async _pay(wizard, ui) {
      const { t, els, showError, clearError, setStatus, setPayEnabled } = ui;
      if (state.paying || !state.stripe || !state.elements) return;
      state.paying = true;
      setPayEnabled(false);
      clearError();
      setStatus(t('payment.processing'));

      // Phase 1 — confirm with Stripe (before any charge; failure is retryable).
      let confirmError = null;
      try {
        const win = getWin();
        const returnUrl = win && win.location ? win.location.href : undefined;
        const res = await state.stripe.confirmPayment({
          elements: state.elements,
          ...(returnUrl ? { confirmParams: { return_url: returnUrl } } : {}),
          redirect: 'if_required',
        });
        confirmError = res && res.error;
      } catch (err) {
        confirmError = err || new Error('confirm failed');
      }
      if (confirmError) {
        setStatus('');
        showError(confirmError.message || t('payment.failed'));
        setPayEnabled(true);
        state.paying = false;
        return;
      }

      // Phase 2 — card CHARGED: never re-enable Pay or fail on a poll error. Poll
      // for the UX transition; if it hasn't landed, the webhook will confirm.
      for (let i = 0; i < maxPolls; i++) {
        try {
          const result = await wizard.confirmDirectPayment();
          if (result && result.paid) return; // core → confirmed → success step
        } catch {
          /* transient confirm-poll error — keep polling; do NOT fail the payment */
        }
        if (i < maxPolls - 1) await wait(pollDelayMs);
      }
      setStatus(t('payment.finalizing'));
    },
  };
}
