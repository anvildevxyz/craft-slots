import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { datetimeStep } from './datetime.js';

function fakeApi(overrides = {}) {
  return {
    paymentSettings: vi.fn(async () => ({ paymentEnabled: false })),
    services: vi.fn(async () => ({ services: [{ id: 12, name: 'Cut', price: 40 }] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    calendar: vi.fn(async () => ({
      calendar: { '2026-08-10': { isBookable: true }, '2026-08-11': { isBookable: false } },
    })),
    slots: vi.fn(async () => ({ slots: [{ time: '10:00', availableCapacity: 2 }, { time: '11:00', availableCapacity: 0 }] })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 'lock-1', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

const REGION = `
  <section>
    <div data-slots-calendar data-slots-initial-month="2026-08"></div>
    <div data-slots-slots></div>
    <div data-slots-slot-quantity hidden>
      <button data-slots-action="qty-decrement">−</button>
      <output data-slots-slot-qty-value>1</output>
      <button data-slots-action="qty-increment">+</button>
    </div>
  </section>`;

async function setup(apiOverrides = {}) {
  document.body.innerHTML = REGION;
  const region = document.body.firstElementChild;
  const wizard = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'booking' });
  await wizard.start();
  await wizard.selectService(12);
  datetimeStep.mount(region, wizard);
  return { region, wizard };
}

const day = (region, d) => region.querySelector(`[data-slots-date="${d}"]`);
const slot = (region, t) => region.querySelector(`[data-slots-time="${t}"]`);

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('datetimeStep — calendar', () => {
  it('builds the calendar for the initial month', async () => {
    const { region } = await setup();
    expect(region.querySelector('[role="grid"]')).not.toBeNull();
    expect(region.querySelector('[data-slots-cal="label"]').textContent).toBe('August 2026');
  });

  it('marks bookable days available and others disabled from the loaded map', async () => {
    const { region } = await setup();
    // Wait for availability to apply — a bookable day (08-10) becomes enabled.
    await vi.waitFor(() => {
      expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false);
    });
    expect(day(region, '2026-08-11').getAttribute('aria-disabled')).toBe('true');
  });

  it('reloads availability when the month changes', async () => {
    const { region, wizard } = await setup();
    const spy = vi.spyOn(wizard, 'loadCalendar');
    region.querySelector('[data-slots-cal="next"]').click();
    expect(spy).toHaveBeenCalledWith({ year: 2026, month: 9 });
  });
});

describe('datetimeStep — slots', () => {
  it('selecting an available day loads and renders the slot listbox', async () => {
    const { region } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '10:00')).not.toBeNull());
    const list = region.querySelector('[data-slots-slots]');
    expect(list.getAttribute('role')).toBe('listbox');
    expect(slot(region, '10:00').getAttribute('role')).toBe('option');
    // Zero-capacity slot is disabled.
    expect(slot(region, '11:00').getAttribute('aria-disabled')).toBe('true');
  });

  it('picking a slot acquires the lock and marks it selected', async () => {
    const { region, wizard } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '10:00')).not.toBeNull());
    slot(region, '10:00').click();
    await vi.waitFor(() => expect(wizard.state).toBe('holdingLock'));
    expect(slot(region, '10:00').getAttribute('aria-selected')).toBe('true');
    expect(wizard.getState().context.lock.token).toBe('lock-1');
  });

  it('labels a group slot with the seats still available', async () => {
    const { region } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '10:00')).not.toBeNull()); // capacity 2

    expect(slot(region, '10:00').querySelector('[data-slots-slot-seats]').textContent).toBe('2 available');
  });

  it('leaves single-seat and unlimited slots unlabelled', async () => {
    const { region } = await setup({
      slots: vi.fn(async () => ({
        slots: [{ time: '09:00', availableCapacity: 1 }, { time: '10:00', availableCapacity: null }],
      })),
    });
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '09:00')).not.toBeNull());

    expect(slot(region, '09:00').querySelector('[data-slots-slot-seats]')).toBeNull();
    expect(slot(region, '10:00').querySelector('[data-slots-slot-seats]')).toBeNull();
  });

  it('does not select a zero-capacity slot', async () => {
    const { region, wizard } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '11:00')).not.toBeNull());
    slot(region, '11:00').click();
    // still browsing — no lock acquired
    expect(wizard.state).toBe('browsing');
  });
});

describe('datetimeStep — slot quantity picker', () => {
  const qtyBox = (region) => region.querySelector('[data-slots-slot-quantity]');

  it('reveals the quantity picker for a slot with capacity > 1 and re-locks on change', async () => {
    const { region, wizard } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '10:00')).not.toBeNull()); // capacity 2
    slot(region, '10:00').click();
    await vi.waitFor(() => expect(qtyBox(region).hidden).toBe(false));

    expect(region.querySelector('[data-slots-slot-qty-value]').textContent).toBe('1');
    region.querySelector('[data-slots-action="qty-increment"]').click();
    await vi.waitFor(() => expect(region.querySelector('[data-slots-slot-qty-value]').textContent).toBe('2'));
    expect(wizard.getState().context.slotQuantity).toBe(2);
    // Capped at capacity 2 → increment disabled.
    expect(region.querySelector('[data-slots-action="qty-increment"]').disabled).toBe(true);
  });

  it('hides the quantity picker for a capacity-1 slot', async () => {
    const { region, wizard } = await setup({
      slots: vi.fn(async () => ({ slots: [{ time: '09:00', availableCapacity: 1 }] })),
    });
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '09:00')).not.toBeNull());
    slot(region, '09:00').click();
    await vi.waitFor(() => expect(wizard.state).toBe('holdingLock'));
    expect(qtyBox(region).hidden).toBe(true);
  });
});

function dayApi(service, overrides = {}) {
  return {
    paymentSettings: vi.fn(async () => ({ paymentEnabled: false })),
    services: vi.fn(async () => ({ services: [service] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    dates: vi.fn(async () => ({ availableDates: ['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-10'] })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

async function setupDay(service, overrides = {}) {
  document.body.innerHTML = REGION;
  const region = document.body.firstElementChild;
  const wizard = new Wizard({ apiClient: dayApi(service, overrides), flow: 'booking' });
  await wizard.start();
  await wizard.selectService(service.id);
  datetimeStep.mount(region, wizard);
  return { region, wizard };
}

;

;

