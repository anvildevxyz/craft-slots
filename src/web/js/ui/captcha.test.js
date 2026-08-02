import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setupCaptcha } from './captcha.js';

function root(withContainer = true) {
  document.body.innerHTML = `
    <div data-slots-wizard>
      ${withContainer ? '<div data-slots-captcha></div>' : ''}
      <input type="hidden" data-slots-captcha-token>
    </div>`;
  return document.querySelector('[data-slots-wizard]');
}

const tokenValue = (r) => r.querySelector('[data-slots-captcha-token]').value;

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('setupCaptcha', () => {
  it('returns null when captcha is not configured', async () => {
    expect(await setupCaptcha(null, root())).toBeNull();
    expect(await setupCaptcha({ provider: 'turnstile' }, root())).toBeNull(); // no siteKey
    expect(await setupCaptcha({ provider: 'nope', siteKey: 'k' }, root())).toBeNull();
  });

  it('renders an interactive widget (turnstile) and the callback sets the token', async () => {
    const loader = vi.fn(async () => {});
    let opts;
    const vendor = { render: vi.fn((_el, o) => { opts = o; return 'wid-1'; }), reset: vi.fn() };
    const r = root();
    const c = await setupCaptcha({ provider: 'turnstile', siteKey: 'site-123' }, r, {
      loader,
      getVendor: () => vendor,
    });
    expect(loader).toHaveBeenCalledWith(expect.stringContaining('turnstile'), null);
    expect(vendor.render).toHaveBeenCalled();
    expect(opts.sitekey).toBe('site-123');

    // Simulate the widget solving.
    opts.callback('tok-abc');
    expect(tokenValue(r)).toBe('tok-abc');

    // reset clears the token and resets the widget.
    c.reset();
    expect(tokenValue(r)).toBe('');
    expect(vendor.reset).toHaveBeenCalledWith('wid-1');
  });

  it('the expired-callback clears the token', async () => {
    let opts;
    const vendor = { render: vi.fn((_el, o) => { opts = o; return 1; }) };
    const r = root();
    await setupCaptcha({ provider: 'hcaptcha', siteKey: 'k' }, r, { loader: async () => {}, getVendor: () => vendor });
    opts.callback('t');
    expect(tokenValue(r)).toBe('t');
    opts['expired-callback']();
    expect(tokenValue(r)).toBe('');
  });

  it('reCAPTCHA v3 mints a fresh token on ensureToken()', async () => {
    const grecaptcha = {
      ready: (cb) => cb(),
      execute: vi.fn(async () => 'v3-token'),
    };
    const loader = vi.fn(async () => {});
    const r = root(false); // v3 has no visible container
    const c = await setupCaptcha({ provider: 'recaptcha', siteKey: 'rc-key', action: 'booking' }, r, {
      loader,
      getVendor: () => grecaptcha,
    });
    expect(loader).toHaveBeenCalledWith(expect.stringContaining('recaptcha/api.js?render=rc-key'), null);
    expect(tokenValue(r)).toBe(''); // not fetched until submit
    await c.ensureToken();
    expect(grecaptcha.execute).toHaveBeenCalledWith('rc-key', { action: 'booking' });
    expect(tokenValue(r)).toBe('v3-token');
  });

  it('applies a nonce to the injected script when given', async () => {
    const loader = vi.fn(async () => {});
    await setupCaptcha({ provider: 'turnstile', siteKey: 'k' }, root(), {
      loader,
      nonce: 'n0nce',
      getVendor: () => ({ render: () => 1 }),
    });
    expect(loader).toHaveBeenCalledWith(expect.any(String), 'n0nce');
  });
});
