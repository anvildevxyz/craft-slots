import { describe, it, expect } from 'vitest';
import { Context } from './context.js';

describe('Context — price', () => {
  it('uses the flat service price for a standard service', () => {
    const c = new Context({ selectedService: { id: 1, price: 80 }, quantity: 1 });
    expect(c.servicePrice).toBe(80);
    expect(c.totalPrice).toBe(80);
  });

  it('multiplies price by quantity', () => {
    const c = new Context({ selectedService: { id: 1, price: 80 }, quantity: 3 });
    expect(c.totalPrice).toBe(240);
  });

  it('handles a missing service price as 0', () => {
    expect(new Context({ selectedService: { id: 1 } }).servicePrice).toBe(0);
  });
});

describe('Context — requiresPayment', () => {
  it('is false when Commerce is disabled', () => {
    const c = new Context({ selectedService: { price: 80 }, payment: { enabled: false } });
    expect(c.requiresPayment).toBe(false);
  });

  it('is false for a zero total even with Commerce enabled', () => {
    const c = new Context({ selectedService: { price: 0 }, payment: { enabled: true } });
    expect(c.requiresPayment).toBe(false);
  });

  it('is true when payment is enabled and the total is positive', () => {
    const c = new Context({ selectedService: { price: 80 }, payment: { enabled: true }, quantity: 1 });
    expect(c.requiresPayment).toBe(true);
  });
});

describe('Context — mutators', () => {
  it('setService resets downstream selections', () => {
    const c = new Context({
      employeeId: 5,
      date: '2026-08-01',
    });
    c.setService({ id: 7, price: 40 });
    expect(c.serviceId).toBe(7);
    expect(c.employeeId).toBeNull();
    expect(c.date).toBeNull();
  });

  it('setCustomer merges fields', () => {
    const c = new Context();
    c.setCustomer({ name: 'Ada' });
    c.setCustomer({ email: 'ada@example.com' });
    expect(c.customer).toMatchObject({ name: 'Ada', email: 'ada@example.com' });
  });

  it('snapshot includes computed values and is decoupled from live state', () => {
    const c = new Context({ selectedService: { price: 80 }, payment: { enabled: true }, quantity: 1 });
    const snap = c.snapshot();
    expect(snap.totalPrice).toBe(80);
    expect(snap.requiresPayment).toBe(true);
    c.quantity = 2; // mutate after snapshot
    expect(snap.totalPrice).toBe(80); // snapshot unchanged
  });
});
