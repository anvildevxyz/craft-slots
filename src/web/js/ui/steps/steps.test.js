import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { serviceListStep } from './service-list.js';
import { customerInfoStep } from './customer-info.js';
import { reviewStep } from './review.js';

function fakeApi(overrides = {}) {
  return {
    paymentSettings: vi.fn(async () => ({ paymentEnabled: false })),
    services: vi.fn(async () => ({
      services: [
        { id: 12, title: 'Haircut', price: 40, duration: 30 },
        { id: 13, title: 'Color', price: 90, duration: 90 },
      ],
    })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    createBooking: vi.fn(async () => ({ success: true, reservation: { reference: 'X' } })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

async function startedWizard(apiOverrides = {}) {
  const w = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'booking' });
  await w.start();
  return w;
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('serviceListStep', () => {
  const REGION = `
    <section>
      <template data-slots-template="service-card">
        <button data-slots-action="select-service">
          <span data-slots-field="name"></span>
          <span data-slots-field="price"></span>
        </button>
      </template>
      <div data-slots-list="services"></div>
    </section>`;

  it('renders a card per service with id + fields filled', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    serviceListStep.render(region, wizard);
    const cards = region.querySelectorAll('[data-slots-action="select-service"]');
    expect(cards).toHaveLength(2);
    expect(cards[0].getAttribute('data-slots-id')).toBe('12');
    expect(cards[0].querySelector('[data-slots-field="name"]').textContent).toBe('Haircut');
    expect(cards[1].querySelector('[data-slots-field="price"]').textContent).toBe('90.00');
  });

  it('reflects the selected service via aria-pressed', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    await wizard.selectService(13);
    serviceListStep.render(region, wizard);
    const selected = region.querySelector('[data-slots-id="13"]');
    const other = region.querySelector('[data-slots-id="12"]');
    expect(selected.getAttribute('aria-pressed')).toBe('true');
    expect(other.getAttribute('aria-pressed')).toBe('false');
  });

  it('rebuilds cleanly on re-render (no duplicates)', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    serviceListStep.render(region, wizard);
    serviceListStep.render(region, wizard);
    expect(region.querySelectorAll('[data-slots-action="select-service"]')).toHaveLength(2);
  });
});

describe('customerInfoStep', () => {
  const REGION = `
    <section>
      <input data-slots-field="name" />
      <input data-slots-field="email" />
      <input data-slots-field="phone" />
      <p id="err-name" data-slots-field-error="name" hidden></p>
      <p id="err-email" data-slots-field-error="email" hidden></p>
    </section>`;

  it('pushes input changes into the core via setCustomer', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    customerInfoStep.mount(region, wizard);
    const name = region.querySelector('[data-slots-field="name"]');
    name.value = 'Ada';
    name.dispatchEvent(new window.Event('input', { bubbles: true }));
    expect(wizard.getState().context.customer.name).toBe('Ada');
  });

  it('reflects current values on render', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    wizard.setCustomer({ email: 'ada@example.com' });
    customerInfoStep.render(region, wizard);
    expect(region.querySelector('[data-slots-field="email"]').value).toBe('ada@example.com');
  });

  it('surfaces per-field validation messages with aria-invalid', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    customerInfoStep.mount(region, wizard);
    // Reach the info step, then leave it empty to trigger validation.
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext(); // info
    wizard.goNext(); // leaving info without data → validation error
    const nameErr = region.querySelector('[data-slots-field-error="name"]');
    expect(nameErr.hidden).toBe(false);
    expect(nameErr.textContent.length).toBeGreaterThan(0);
    expect(region.querySelector('[data-slots-field="name"]').getAttribute('aria-invalid')).toBe('true');
  });
});

describe('reviewStep', () => {
  const REGION = `
    <section>
      <span data-slots-summary="service"></span>
      <span data-slots-summary="date"></span>
      <span data-slots-summary="customer-name"></span>
      <span data-slots-summary="total"></span>
      <div data-slots-payment-notice hidden></div>
    </section>`;

  it('fills the summary from context', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    reviewStep.render(region, wizard);
    expect(region.querySelector('[data-slots-summary="service"]').textContent).toBe('Haircut');
    expect(region.querySelector('[data-slots-summary="date"]').textContent).toBe('2026-08-01');
    expect(region.querySelector('[data-slots-summary="customer-name"]').textContent).toBe('Ada');
    expect(region.querySelector('[data-slots-summary="total"]').textContent).toBe('40.00');
  });

  it('hides empty rows and their labels (no orphaned "Choose an employee")', async () => {
    document.body.innerHTML = `
      <section>
        <dl>
          <dt data-dt="service">Service</dt><dd data-slots-summary="service"></dd>
          <dt data-dt="employee">Employee</dt><dd data-slots-summary="employee"></dd>
          <dt data-dt="location">Location</dt><dd data-slots-summary="location"></dd>
          <dt data-dt="quantity">Qty</dt><dd data-slots-summary="quantity"></dd>
          <dt data-dt="total">Total</dt><dd data-slots-summary="total"></dd>
        </dl>
      </section>`;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard(); // single location auto-selected, no employee, qty 1
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    reviewStep.render(region, wizard);

    // No employee chosen → both the value and its label are hidden.
    expect(region.querySelector('[data-slots-summary="employee"]').hidden).toBe(true);
    expect(region.querySelector('[data-dt="employee"]').hidden).toBe(true);
    // Quantity is 1 → hidden too.
    expect(region.querySelector('[data-slots-summary="quantity"]').hidden).toBe(true);
    expect(region.querySelector('[data-dt="quantity"]').hidden).toBe(true);
    // Populated rows stay visible.
    expect(region.querySelector('[data-slots-summary="service"]').hidden).toBe(false);
    expect(region.querySelector('[data-dt="service"]').hidden).toBe(false);
    expect(region.querySelector('[data-slots-summary="total"]').hidden).toBe(false);
  });

  it('hides the total row (and its label) when the service has no price', async () => {
    document.body.innerHTML = `
      <section>
        <dl>
          <dt data-dt="total">Total</dt><dd data-slots-summary="total"></dd>
        </dl>
      </section>`;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard({
      services: vi.fn(async () => ({
        services: [{ id: 12, title: 'Free Consult', price: 0, duration: 30 }],
      })),
    });
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    reviewStep.render(region, wizard);

    // A free service must not show a "0.00" total row.
    expect(region.querySelector('[data-slots-summary="total"]').hidden).toBe(true);
    expect(region.querySelector('[data-dt="total"]').hidden).toBe(true);
  });

  it('keeps the payment notice hidden when no payment is required', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    await wizard.selectService(12);
    reviewStep.render(region, wizard);
    expect(region.querySelector('[data-slots-payment-notice]').hidden).toBe(true);
  });

  it('shows the payment notice when payment is required', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard({ paymentSettings: vi.fn(async () => ({ paymentEnabled: true })) });
    await wizard.selectService(12);
    reviewStep.render(region, wizard);
    expect(region.querySelector('[data-slots-payment-notice]').hidden).toBe(false);
  });
});
