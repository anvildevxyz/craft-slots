import { describe, it, expect, vi } from 'vitest';
import { Wizard, create } from './wizard.js';
import { STATES } from './machine.js';

/**
 * A fake v1 API client covering the endpoints the wizard drives. Individual
 * tests override methods to exercise branches. This stands in for a running
 * Craft instance — the M1 exit criterion is a headless booking with no renderer.
 */
function fakeApi(overrides = {}) {
  return {
    paymentSettings: vi.fn(async () => ({ paymentEnabled: false })),
    // Two services, so the service step stays visible: a lone service is
    // auto-selected and its step skipped (covered separately below).
    services: vi.fn(async () => ({
      services: [
        { id: 12, name: 'Haircut', price: 40 },
        { id: 13, name: 'Shave', price: 25 },
      ],
    })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1, name: 'Main' }], serviceHasSchedule: true })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 'lock-abc', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    createBooking: vi.fn(async () => ({ success: true, reservation: { id: 999, reference: 'BKD-999' } })),
    createPayment: vi.fn(async () => ({
      success: true,
      paymentToken: 'pt-abc',
      clientSecret: 'cs_test_123',
      config: { publishableKey: 'pk_test_123' },
    })),
    confirmPayment: vi.fn(async () => ({ success: true, paid: true, status: 'paid' })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

function newWizard(apiOverrides = {}, options = {}) {
  const api = fakeApi(apiOverrides);
  const wizard = new Wizard({ apiClient: api, flow: 'booking', ...options });
  return { wizard, api };
}

describe('Wizard — bootstrap', () => {
  it('starts idle, then reaches browsing on the service step after start()', async () => {
    const { wizard } = newWizard();
    expect(wizard.state).toBe(STATES.IDLE);
    await wizard.start();
    expect(wizard.state).toBe(STATES.BROWSING);
    expect(wizard.stepId).toBe('service');
  });

  it('loads services and applies payment settings', async () => {
    const { wizard } = newWizard({ paymentSettings: vi.fn(async () => ({ paymentEnabled: true, currency: 'CHF' })) });
    await wizard.start();
    const state = wizard.getState();
    expect(state.context.payment.enabled).toBe(true);
    expect(state.context.payment.currency).toBe('CHF');
  });

  it('preselects a service passed in options', async () => {
    const { wizard, api } = newWizard({}, { serviceId: 12 });
    await wizard.start();
    expect(api.employees).toHaveBeenCalledWith(12);
    expect(wizard.getState().context.serviceId).toBe(12);
  });

  it('surfaces an error state if services fail to load', async () => {
    const onError = vi.fn();
    const { wizard } = newWizard({
      services: vi.fn(async () => {
        throw new Error('boom');
      }),
    });
    wizard.on('error', onError);
    await wizard.start();
    expect(wizard.state).toBe(STATES.ERROR);
    expect(onError).toHaveBeenCalled();
  });
});

describe('Wizard — headless booking (M1 exit demo)', () => {
  it('drives service → datetime → info → review → confirmed with no renderer', async () => {
    const { wizard, api } = newWizard();
    const seen = [];
    wizard.on('state:change', (e) => seen.push(e.to));
    wizard.on('booking:confirmed', (e) => seen.push(`confirmed:${e.reservation.reference}`));

    await wizard.start(); // browsing @ service

    // Select the service (single location auto-selected, service has own schedule → no employee step).
    await wizard.selectService(12);
    expect(wizard.stepId).toBe('service');

    // service → datetime (extras empty, 1 location, serviceHasSchedule hides employee)
    expect(wizard.goNext()).toEqual({ ok: true, stepId: 'datetime' });

    // Pick a slot → acquires the lock, enters holdingLock.
    const slot = await wizard.selectSlot({ date: '2026-08-01', time: '10:00', quantity: 1 });
    expect(slot.acquired).toBe(true);
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);

    // datetime → info
    expect(wizard.goNext().stepId).toBe('info');

    // Must fill customer info before leaving info.
    expect(wizard.goNext().ok).toBe(false);
    wizard.setCustomer({ name: 'Ada Lovelace', email: 'ada@example.com' });
    expect(wizard.goNext().stepId).toBe('review');

    // Submit → confirmed.
    const result = await wizard.submit();
    expect(result).toMatchObject({ ok: true, confirmed: true });
    expect(wizard.state).toBe(STATES.CONFIRMED);

    // The booking body carried the lock token and customer fields.
    const body = api.createBooking.mock.calls[0][0];
    expect(body).toMatchObject({
      serviceId: 12,
      date: '2026-08-01',
      time: '10:00',
      customerName: 'Ada Lovelace',
      customerEmail: 'ada@example.com',
      softLockToken: 'lock-abc',
    });

    expect(seen).toContain(STATES.CONFIRMED);
    expect(seen).toContain('confirmed:BKD-999');
  });
});

describe('Wizard — lone service/employee steps are skipped', () => {
  const oneService = { services: [{ id: 12, name: 'Haircut', price: 40 }] };

  function loneSetup(overrides = {}) {
    return {
      services: vi.fn(async () => oneService),
      employees: vi.fn(async () => ({
        employees: [{ id: 4, name: 'Ada' }],
        locations: [{ id: 1, name: 'Main' }],
        serviceHasSchedule: false,
      })),
      ...overrides,
    };
  }

  it('opens straight on the calendar with one service, one location and one employee', async () => {
    const { wizard } = newWizard(loneSetup());
    await wizard.start();

    expect(wizard.stepId).toBe('datetime');
    const state = wizard.getState();
    expect(state.context.serviceId).toBe(12);
    expect(state.context.employeeId).toBe(4);
    expect(state.context.locationId).toBe(1);
    // Only datetime, info and review remain, so the progress indicator agrees.
    expect(state.position).toBe(1);
    expect(state.total).toBe(3);
  });

  it('cannot be navigated back into a skipped step', async () => {
    const { wizard } = newWizard(loneSetup());
    await wizard.start();

    expect(wizard.goBack().ok).toBe(false);
    expect(wizard.stepId).toBe('datetime');
  });

  it('books end to end with the auto-selected service and employee', async () => {
    const { wizard, api } = newWizard(loneSetup());
    await wizard.start();

    await wizard.selectSlot({ date: '2026-08-01', time: '10:00', quantity: 1 });
    expect(wizard.goNext().stepId).toBe('info');
    wizard.setCustomer({ name: 'Ada Lovelace', email: 'ada@example.com' });
    expect(wizard.goNext().stepId).toBe('review');
    expect(await wizard.submit()).toMatchObject({ ok: true, confirmed: true });

    expect(api.createBooking.mock.calls[0][0]).toMatchObject({ serviceId: 12, employeeId: 4 });
  });

  it('still shows the employee step when there is a choice to make', async () => {
    const { wizard } = newWizard(
      loneSetup({
        employees: vi.fn(async () => ({
          employees: [{ id: 4, name: 'Ada' }, { id: 5, name: 'Grace' }],
          locations: [{ id: 1, name: 'Main' }],
          serviceHasSchedule: false,
        })),
      }),
    );
    await wizard.start();

    expect(wizard.stepId).toBe('employee');
  });

  

;
});

describe('Wizard — payment (gateway redirect)', () => {
  it('enters paying and emits payment:redirect when the backend returns a redirectUrl', async () => {
    const onRedirect = vi.fn();
    const { wizard } = newWizard({
      paymentSettings: vi.fn(async () => ({ paymentEnabled: true })),
      createBooking: vi.fn(async () => ({ success: true, redirectUrl: '/pay/redirect' })),
    });
    wizard.on('payment:redirect', onRedirect);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    const result = await wizard.submit();
    expect(result).toMatchObject({ ok: true, paying: true, redirectUrl: '/pay/redirect' });
    expect(wizard.state).toBe(STATES.PAYING);
    expect(onRedirect).toHaveBeenCalledWith({ url: '/pay/redirect' });
  });
});

describe('Wizard — payment (direct/Stripe)', () => {
  async function toSubmit(apiOverrides = {}) {
    const { wizard, api } = newWizard(apiOverrides);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    return { wizard, api };
  }

  it('enters paying and emits payment:required when the booking is created pending', async () => {
    const onRequired = vi.fn();
    const reservation = { id: 42, formattedDateTime: 'Aug 1', status: 'Pending', token: 'confirm-tok' };
    const { wizard } = await toSubmit({
      createBooking: vi.fn(async () => ({ success: true, paymentRequired: true, reservation })),
    });
    wizard.on('payment:required', onRequired);
    const result = await wizard.submit();
    expect(result).toMatchObject({ ok: true, paying: true, paymentRequired: true });
    expect(wizard.state).toBe(STATES.PAYING);
    expect(onRequired).toHaveBeenCalledWith({ reservation });
    // Not confirmed yet — payment still pending.
    expect(wizard.getState().context.reservation).toEqual(reservation);
  });

  it('createDirectPayment authorizes with the reservation id + token and stores the paymentToken', async () => {
    const reservation = { id: 42, token: 'confirm-tok' };
    const { wizard, api } = await toSubmit({
      createBooking: vi.fn(async () => ({ success: true, paymentRequired: true, reservation })),
    });
    await wizard.submit();
    const res = await wizard.createDirectPayment();
    expect(api.createPayment).toHaveBeenCalledWith({ reservationId: 42, token: 'confirm-tok' });
    expect(res).toMatchObject({ clientSecret: 'cs_test_123', config: { publishableKey: 'pk_test_123' } });
  });

  it('createDirectPayment returns null when there is no pending direct payment', async () => {
    const { wizard } = newWizard();
    expect(await wizard.createDirectPayment()).toBeNull();
  });

  it('confirmDirectPayment finalizes the booking (confirmed + booking:confirmed) when the server reports paid', async () => {
    const onConfirmed = vi.fn();
    const reservation = { id: 42, token: 'confirm-tok' };
    const { wizard, api } = await toSubmit({
      createBooking: vi.fn(async () => ({ success: true, paymentRequired: true, reservation })),
    });
    wizard.on('booking:confirmed', onConfirmed);
    await wizard.submit();
    await wizard.createDirectPayment();
    const res = await wizard.confirmDirectPayment();
    expect(api.confirmPayment).toHaveBeenCalledWith('pt-abc');
    expect(res).toMatchObject({ paid: true });
    expect(wizard.state).toBe(STATES.CONFIRMED);
    expect(onConfirmed).toHaveBeenCalledWith({ reservation });
  });

  it('confirmDirectPayment stays paying (no confirm) while the server still reports unpaid', async () => {
    const reservation = { id: 42, token: 'confirm-tok' };
    const { wizard } = await toSubmit({
      createBooking: vi.fn(async () => ({ success: true, paymentRequired: true, reservation })),
      confirmPayment: vi.fn(async () => ({ success: true, paid: false, status: 'pending' })),
    });
    await wizard.submit();
    await wizard.createDirectPayment();
    const res = await wizard.confirmDirectPayment();
    expect(res).toMatchObject({ paid: false });
    expect(wizard.state).toBe(STATES.PAYING);
  });

  it('confirmDirectPayment is a no-op before createDirectPayment (no paymentToken yet)', async () => {
    const reservation = { id: 42, token: 'confirm-tok' };
    const { wizard, api } = await toSubmit({
      createBooking: vi.fn(async () => ({ success: true, paymentRequired: true, reservation })),
    });
    await wizard.submit();
    const res = await wizard.confirmDirectPayment();
    expect(res).toEqual({ paid: false });
    expect(api.confirmPayment).not.toHaveBeenCalled();
  });
});

describe('Wizard — lock expiry at submit', () => {
  it('transitions to expired when the backend reports the lock is gone', async () => {
    const onExpired = vi.fn();
    const { wizard } = newWizard({
      createBooking: vi.fn(async () => {
        const e = new Error('lock expired');
        e.code = 'expired';
        throw e;
      }),
    });
    wizard.on('lock:expired', onExpired);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    const result = await wizard.submit();
    expect(result).toMatchObject({ ok: false, expired: true });
    expect(wizard.state).toBe(STATES.EXPIRED);
    expect(onExpired).toHaveBeenCalled();
  });
});

describe('Wizard — lock expiry recovery', () => {
  it('recovers from an expired lock: sent back to datetime, re-pick, submit succeeds', async () => {
    let bookingCalls = 0;
    const { wizard } = newWizard({
      createBooking: vi.fn(async () => {
        bookingCalls++;
        if (bookingCalls === 1) {
          const e = new Error('lock expired');
          e.code = 'expired';
          throw e;
        }
        return { success: true, reservation: { reference: 'BKD-2' } };
      }),
    });
    const errors = [];
    wizard.on('error', (e) => errors.push(e.code));
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();

    const first = await wizard.submit();
    expect(first).toMatchObject({ ok: false, expired: true });
    expect(wizard.state).toBe(STATES.EXPIRED);
    expect(wizard.stepId).toBe('datetime'); // pushed back to re-pick
    expect(wizard.getState().context.lock).toBeNull();
    expect(errors).toContain('lock_expired');

    // Re-pick recovers to holdingLock (EXPIRED → HOLDING_LOCK).
    const re = await wizard.selectSlot({ date: '2026-08-02', time: '11:00' });
    expect(re.acquired).toBe(true);
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);
    wizard.goNext();
    wizard.goNext();
    const second = await wizard.submit();
    expect(second).toMatchObject({ ok: true, confirmed: true });
  });

  it('a failed re-acquire clears the stale lock and drops back to browsing', async () => {
    let n = 0;
    const { wizard } = newWizard({
      createSlotLock: vi.fn(async () => {
        n++;
        return n === 1 ? { success: true, token: 'lock-1', expiresIn: 300 } : { success: false, message: 'taken' };
      }),
    });
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' }); // holds lock-1
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);

    const res = await wizard.selectSlot({ date: '2026-08-01', time: '11:00' }); // fails
    expect(res.acquired).toBe(false);
    expect(wizard.getState().context.lock).toBeNull(); // not stale
    expect(wizard.state).toBe(STATES.BROWSING); // demoted, no phantom hold
  });
});

describe('Wizard — back navigation releases the lock', () => {
  it('going back from datetime after holding a lock releases it and returns to browsing', async () => {
    const { wizard, api } = newWizard();
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);
    const back = wizard.goBack(); // datetime → service
    expect(back.stepId).toBe('service');
    expect(api.releaseLock).toHaveBeenCalledWith({ token: 'lock-abc' });
    expect(wizard.state).toBe(STATES.BROWSING);
  });
});

describe('Wizard — chosen quantity reaches the booking + total', () => {
  it('posts the picked slot quantity (not the default) and prices it', async () => {
    const { wizard, api } = newWizard({
      paymentSettings: vi.fn(async () => ({ paymentEnabled: true, currency: 'CHF' })),
      services: vi.fn(async () => ({ services: [{ id: 12, name: 'Haircut', price: 40 }] })),
    });
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00', quantity: 3 });
    expect(wizard.getState().context.quantity).toBe(3);
    expect(wizard.getState().context.totalPrice).toBe(120); // 40 × 3
    wizard.goNext(); // info
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext(); // review
    await wizard.submit();
    expect(api.createBooking.mock.calls[0][0].quantity).toBe(3);
  });
});

;

describe('Wizard — back navigation preserves a held lock across info/review', () => {
  it('keeps the hold when navigating back within the info/review steps', async () => {
    const { wizard, api } = newWizard();
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext(); // info
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext(); // review
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);

    const back = wizard.goBack(); // review → info: must NOT release
    expect(back.stepId).toBe('info');
    expect(api.releaseLock).not.toHaveBeenCalled();
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);
    expect(wizard.getState().context.lock).not.toBeNull();
  });
});

describe('Wizard — slot already taken', () => {
  it('emits a slot_reserved error and does not enter holdingLock', async () => {
    const onError = vi.fn();
    const { wizard } = newWizard({
      createSlotLock: vi.fn(async () => ({ success: false, message: 'slot reserved' })),
    });
    wizard.on('error', onError);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    const res = await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    expect(res.acquired).toBe(false);
    expect(wizard.state).toBe(STATES.BROWSING);
    expect(onError.mock.calls[0][0]).toMatchObject({ code: 'slot_reserved' });
  });

  it('treats a 400/409 lock response as slot_reserved, not a fatal error', async () => {
    const onError = vi.fn();
    const { wizard } = newWizard({
      createSlotLock: vi.fn(async () => {
        const e = new Error('That time was just taken.');
        e.name = 'ApiError';
        e.status = 400;
        throw e;
      }),
    });
    wizard.on('error', onError);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    const res = await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    expect(res.acquired).toBe(false);
    expect(wizard.state).toBe(STATES.BROWSING); // not ERROR
    expect(onError.mock.calls[0][0]).toMatchObject({ code: 'slot_reserved' });
  });
});

describe('Wizard — factory & teardown', () => {
  it('create() returns a Wizard', () => {
    const { api } = newWizard();
    expect(create({ apiClient: api })).toBeInstanceOf(Wizard);
  });

  it('reset() returns to idle with a fresh context', async () => {
    const { wizard } = newWizard();
    await wizard.start();
    await wizard.selectService(12);
    wizard.reset();
    expect(wizard.state).toBe(STATES.IDLE);
    expect(wizard.getState().context.serviceId).toBeNull();
  });

  it('destroy() aborts requests and releases the lock', async () => {
    const { wizard, api } = newWizard();
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.destroy();
    expect(api.abortAll).toHaveBeenCalled();
    expect(api.releaseLock).toHaveBeenCalled();
  });

  it('rejects an unknown flow', () => {
    expect(() => new Wizard({ apiClient: fakeApi(), flow: 'nope' })).toThrow(/unknown flow/);
  });
});
