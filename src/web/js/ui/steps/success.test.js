import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { successStep } from './success.js';

function fakeApi(overrides = {}) {
  return {
    paymentSettings: vi.fn(async () => ({ paymentEnabled: false })),
    services: vi.fn(async () => ({ services: [{ id: 12, name: 'Haircut', price: 40 }] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1, name: 'Main' }], serviceHasSchedule: true })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 'lock-abc', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    createBooking: vi.fn(async () => ({
      success: true,
      reservation: { id: 999, statusLabel: 'Confirmed', formattedDateTime: 'Aug 1, 2026 10:00' },
    })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

const REGION = `
  <section data-slots-step="success">
    <dd data-slots-summary="status"></dd>
    <dd data-slots-summary="booking-id"></dd>
    <dd data-slots-summary="appointment"></dd>
    <strong data-slots-summary="customer-email"></strong>
  </section>`;

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('successStep', () => {
  it('fills the reservation details after a confirmed booking', async () => {
    const wizard = new Wizard({ apiClient: fakeApi(), flow: 'booking' });
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    await wizard.submit();

    document.body.innerHTML = REGION;
    const region = document.querySelector('[data-slots-step="success"]');
    successStep.render(region, wizard);

    expect(region.querySelector('[data-slots-summary="status"]').textContent).toBe('Confirmed');
    expect(region.querySelector('[data-slots-summary="booking-id"]').textContent).toBe('999');
    expect(region.querySelector('[data-slots-summary="appointment"]').textContent).toBe('Aug 1, 2026 10:00');
    expect(region.querySelector('[data-slots-summary="customer-email"]').textContent).toBe('ada@example.com');
  });

  it('has the reservation on context before the confirmed transition fires', async () => {
    // The renderer shows the success step on `state:change → confirmed`, so the
    // reservation (id/status/appointment) must already be on the context at that
    // moment — otherwise the success screen renders with an empty booking id.
    const wizard = new Wizard({ apiClient: fakeApi(), flow: 'booking' });
    let reservationAtConfirm = 'unset';
    wizard.on('state:change', ({ to }) => {
      if (to === 'confirmed') reservationAtConfirm = wizard.getState().context.reservation;
    });

    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    await wizard.submit();

    expect(reservationAtConfirm).toBeTruthy();
    expect(reservationAtConfirm.id).toBe(999);
  });
});
