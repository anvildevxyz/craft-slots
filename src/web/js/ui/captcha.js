/**
 * Captcha integration for the vanilla wizard.
 *
 * Loads a vendor widget — Cloudflare **Turnstile**, **hCaptcha**, or Google
 * **reCAPTCHA v3** — and feeds its token into `[data-slots-captcha-token]`,
 * which the renderer sends with the booking. Everything is opt-in: with no
 * captcha config, nothing loads and the wizard stays fully CSP-`self`.
 *
 * Captcha inherently needs the vendor's external script, so a site using it must
 * allowlist the provider's origin in its CSP (`script-src`, and `frame-src` for
 * the interactive widgets). A `nonce` is applied to the injected script when
 * provided, for nonce-based CSP.
 *
 * The vendor API and script loader are injectable so the plumbing is testable
 * without the real third-party scripts.
 */

const VENDORS = {
  turnstile: { src: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', global: 'turnstile' },
  hcaptcha: { src: 'https://js.hcaptcha.com/1/api.js?render=explicit', global: 'hcaptcha' },
  recaptcha: { srcFor: (key) => `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(key)}`, global: 'grecaptcha' },
};

/** Inject a vendor script once, resolving when it has loaded. */
function loadScript(src, nonce) {
  return new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[src="${src}"]`);
    if (existing) {
      if (existing.dataset.slotsLoaded) resolve();
      else existing.addEventListener('load', () => resolve(), { once: true });
      return;
    }
    const el = document.createElement('script');
    el.src = src;
    el.async = true;
    el.defer = true;
    if (nonce) el.nonce = nonce;
    el.addEventListener('load', () => {
      el.dataset.slotsLoaded = '1';
      resolve();
    }, { once: true });
    el.addEventListener('error', () => reject(new Error('captcha: vendor script failed to load')), { once: true });
    document.head.appendChild(el);
  });
}

/**
 * Set up captcha for a wizard root. Returns a controller `{ ensureToken, reset }`
 * (or null when captcha isn't configured/supported).
 *
 * @param {{provider:string, siteKey:string, action?:string}} config
 * @param {Element} root
 * @param {{nonce?:string, loader?:Function, getVendor?:(g:string)=>any}} [deps]
 */
export async function setupCaptcha(config, root, { nonce = null, loader = loadScript, getVendor } = {}) {
  if (!config || !config.provider || !config.siteKey || !root) return null;
  const vendor = VENDORS[config.provider];
  if (!vendor) return null;

  const resolveVendor = getVendor || ((g) => (typeof window !== 'undefined' ? window[g] : undefined));
  const tokenInput = root.querySelector('[data-slots-captcha-token]');
  const setToken = (t) => {
    if (tokenInput) tokenInput.value = t || '';
  };

  // reCAPTCHA v3: no visible widget — fetch a fresh token per submit.
  if (config.provider === 'recaptcha') {
    await loader(vendor.srcFor(config.siteKey), nonce);
    return {
      async ensureToken() {
        const g = resolveVendor('grecaptcha');
        if (!g || typeof g.execute !== 'function') return;
        if (typeof g.ready === 'function') await new Promise((r) => g.ready(r));
        setToken(await g.execute(config.siteKey, { action: config.action || 'booking' }));
      },
      reset() {
        setToken('');
      },
    };
  }

  // Turnstile / hCaptcha: render an interactive widget; a callback sets the token.
  await loader(vendor.src, nonce);
  const g = resolveVendor(vendor.global);
  const container = root.querySelector('[data-slots-captcha]');
  let widgetId = null;
  if (g && typeof g.render === 'function' && container) {
    widgetId = g.render(container, {
      sitekey: config.siteKey,
      callback: (token) => setToken(token),
      'expired-callback': () => setToken(''),
      'error-callback': () => setToken(''),
    });
  }
  return {
    // The interactive widgets populate the token on solve; nothing to do here.
    async ensureToken() {},
    reset() {
      setToken('');
      const vg = resolveVendor(vendor.global);
      if (vg && typeof vg.reset === 'function' && widgetId !== null) vg.reset(widgetId);
    },
  };
}
