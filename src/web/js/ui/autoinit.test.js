import { describe, it, expect, vi, beforeEach } from 'vitest';
import { autoInit } from './index.js';

// A minimal fake fetch so create()'s auto-started wizard doesn't hit the network.
function stubFetch() {
  return vi.fn(async (url) => ({
    ok: true,
    status: 200,
    text: async () => JSON.stringify(url.includes('/services') ? { services: [] } : { paymentEnabled: false }),
  }));
}

const MARKUP = (cfg) => `
  <div data-slots-wizard data-slots-auto>
    <script type="application/json" data-slots-config>${JSON.stringify(cfg)}</script>
    <div data-slots-progress><span data-slots-progress-current></span><span data-slots-progress-total></span></div>
    <section data-slots-step="service"><h2 data-slots-step-heading>S</h2></section>
  </div>`;

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('autoInit', () => {
  it('initializes a marked wizard from its JSON config block', () => {
    document.body.innerHTML = MARKUP({ api: { fetch: undefined } });
    // Provide a global fetch the built client can use.
    globalThis.fetch = stubFetch();
    const controllers = autoInit();
    expect(controllers).toHaveLength(1);
    expect(controllers[0].wizard).toBeTruthy();
  });

  it('is idempotent — a second call does not re-init the same element', () => {
    document.body.innerHTML = MARKUP({});
    globalThis.fetch = stubFetch();
    autoInit();
    const second = autoInit();
    expect(second).toHaveLength(0);
  });

  it('ignores wizards without the data-slots-auto marker', () => {
    document.body.innerHTML = '<div data-slots-wizard><section data-slots-step="service"></section></div>';
    globalThis.fetch = stubFetch();
    expect(autoInit()).toHaveLength(0);
  });

  it('survives a malformed config block (falls back to empty config)', () => {
    document.body.innerHTML = `
      <div data-slots-wizard data-slots-auto>
        <script type="application/json" data-slots-config>{ not json </script>
        <section data-slots-step="service"><h2 data-slots-step-heading>S</h2></section>
      </div>`;
    globalThis.fetch = stubFetch();
    expect(() => autoInit()).not.toThrow();
  });
});
