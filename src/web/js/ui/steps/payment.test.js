import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createPaymentStep, loadStripeJs } from './payment.js';

const REGION = `
  <section data-slots-step="payment">
    <p data-slots-payment-status></p>
    <p data-slots-payment-error hidden></p>
    <div data-slots-payment-element></div>
    <button data-slots-action="pay" disabled></button>
  </section>`;

function fakeWizard(overrides = {}) {
  return {
    t: (k) => k,
    createDirectPayment: vi.fn(async () => ({ clientSecret: 'cs_test', config: { publishableKey: 'pk_test' } })),
    confirmDirectPayment: vi.fn(async () => ({ paid: true })),
    ...overrides,
  };
}

function fakeStripe({ confirmError = null } = {}) {
  const paymentElement = { mount: vi.fn() };
  const elements = { create: vi.fn(() => paymentElement) };
  const stripe = {
    elements: vi.fn(() => elements),
    confirmPayment: vi.fn(async () => ({ error: confirmError })),
  };
  const ctor = vi.fn(() => stripe);
  return { ctor, stripe, elements, paymentElement };
}

function fakeWin(StripeCtor) {
  return {
    Stripe: StripeCtor,
    document,
    location: { href: 'https://example.test/book' },
    setTimeout: (fn) => fn(),
  };
}

const flush = async (n = 30) => {
  for (let i = 0; i < n; i++) await Promise.resolve();
};

function mountRegion() {
  document.body.innerHTML = REGION;
  return document.querySelector('[data-slots-step="payment"]');
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('loadStripeJs', () => {
  it('resolves immediately with an existing window.Stripe', async () => {
    const ctor = () => ({});
    const Stripe = await loadStripeJs({ Stripe: ctor, document });
    expect(Stripe).toBe(ctor);
  });

  it('rejects when handed something that is not a browser window', async () => {
    await expect(loadStripeJs({})).rejects.toThrow(/browser/);
  });

  it('removes a failed Stripe.js <script> so a later load can retry', async () => {
    delete window.Stripe;
    document.querySelectorAll('script[data-slots-stripe-js]').forEach((s) => s.remove());

    // First load fails — the dead script must be removed (not left to hang retries).
    const p1 = loadStripeJs(window);
    const s1 = document.querySelector('script[data-slots-stripe-js]');
    expect(s1).not.toBeNull();
    s1.dispatchEvent(new Event('error'));
    await expect(p1).rejects.toThrow(/failed to load/);
    expect(document.querySelector('script[data-slots-stripe-js]')).toBeNull();

    // Retry starts a fresh script and can succeed.
    const ctor = () => ({});
    const p2 = loadStripeJs(window);
    const s2 = document.querySelector('script[data-slots-stripe-js]');
    expect(s2).not.toBeNull();
    window.Stripe = ctor;
    s2.dispatchEvent(new Event('load'));
    await expect(p2).resolves.toBe(ctor);
    delete window.Stripe;
    s2.remove();
  });
});

describe('createPaymentStep — mount', () => {
  it('creates the payment, mounts the Payment Element, and enables the pay button', async () => {
    const { ctor, stripe, elements, paymentElement } = fakeStripe();
    const wizard = fakeWizard();
    const region = mountRegion();
    const step = createPaymentStep({ win: fakeWin(ctor) });

    await step.mount(region, wizard);
    await flush();

    expect(wizard.createDirectPayment).toHaveBeenCalled();
    expect(ctor).toHaveBeenCalledWith('pk_test');
    expect(stripe.elements).toHaveBeenCalledWith({ clientSecret: 'cs_test' });
    expect(elements.create).toHaveBeenCalledWith('payment');
    expect(paymentElement.mount).toHaveBeenCalled();
    expect(region.querySelector('[data-slots-action="pay"]').disabled).toBe(false);
    expect(region.querySelector('[data-slots-payment-error]').hidden).toBe(true);
  });

  it('shows an error and leaves pay disabled when the payment cannot be created', async () => {
    const { ctor } = fakeStripe();
    const wizard = fakeWizard({ createDirectPayment: vi.fn(async () => ({ clientSecret: null })) });
    const region = mountRegion();
    const step = createPaymentStep({ win: fakeWin(ctor) });

    await step.mount(region, wizard);
    await flush();

    const err = region.querySelector('[data-slots-payment-error]');
    expect(err.hidden).toBe(false);
    expect(err.textContent).toBe('payment.unavailable');
    expect(region.querySelector('[data-slots-action="pay"]').disabled).toBe(true);
  });
});

describe('createPaymentStep — pay', () => {
  it('confirms with Stripe then polls the server and finalizes on paid', async () => {
    const { ctor, stripe } = fakeStripe();
    const wizard = fakeWizard();
    const region = mountRegion();
    const step = createPaymentStep({ win: fakeWin(ctor), pollDelayMs: 0, maxPolls: 3 });

    await step.mount(region, wizard);
    await flush();

    region.querySelector('[data-slots-action="pay"]').click();
    await flush();

    expect(stripe.confirmPayment).toHaveBeenCalled();
    expect(wizard.confirmDirectPayment).toHaveBeenCalled();
    expect(region.querySelector('[data-slots-payment-error]').hidden).toBe(true);
  });

  it('surfaces a Stripe confirmation error and re-enables the pay button', async () => {
    const { ctor } = fakeStripe({ confirmError: { message: 'Card declined' } });
    const wizard = fakeWizard();
    const region = mountRegion();
    const step = createPaymentStep({ win: fakeWin(ctor), pollDelayMs: 0, maxPolls: 2 });

    await step.mount(region, wizard);
    await flush();

    region.querySelector('[data-slots-action="pay"]').click();
    await flush();

    const err = region.querySelector('[data-slots-payment-error]');
    expect(err.hidden).toBe(false);
    expect(err.textContent).toBe('Card declined');
    expect(wizard.confirmDirectPayment).not.toHaveBeenCalled();
    expect(region.querySelector('[data-slots-action="pay"]').disabled).toBe(false);
  });

  it('defers to the webhook (finalizing status) when polling never reports paid', async () => {
    const { ctor } = fakeStripe();
    const wizard = fakeWizard({ confirmDirectPayment: vi.fn(async () => ({ paid: false })) });
    const region = mountRegion();
    const step = createPaymentStep({ win: fakeWin(ctor), pollDelayMs: 0, maxPolls: 2 });

    await step.mount(region, wizard);
    await flush();

    region.querySelector('[data-slots-action="pay"]').click();
    await flush();

    expect(wizard.confirmDirectPayment).toHaveBeenCalledTimes(2);
    expect(region.querySelector('[data-slots-payment-status]').textContent).toBe('payment.finalizing');
  });

  it('does NOT re-enable Pay when the confirm-poll throws after the card was charged', async () => {
    // Regression: confirmPayment succeeded (card charged), but a transient poll
    // error must not surface as a payment failure or re-enable Pay — that would
    // invite a double charge. It defers to the webhook instead.
    const { ctor } = fakeStripe(); // confirmPayment resolves with no error → charged
    const wizard = fakeWizard({
      confirmDirectPayment: vi.fn(async () => {
        throw new Error('network blip');
      }),
    });
    const region = mountRegion();
    const step = createPaymentStep({ win: fakeWin(ctor), pollDelayMs: 0, maxPolls: 2 });

    await step.mount(region, wizard);
    await flush();
    region.querySelector('[data-slots-action="pay"]').click();
    await flush();

    const pay = region.querySelector('[data-slots-action="pay"]');
    const err = region.querySelector('[data-slots-payment-error]');
    expect(pay.disabled).toBe(true); // stays disabled — no retry after charge
    expect(err.hidden).toBe(true); // no payment-failed error shown
    expect(region.querySelector('[data-slots-payment-status]').textContent).toBe('payment.finalizing');
  });
});
