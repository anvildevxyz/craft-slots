import { describe, it, expect } from 'vitest';
import { isValidEmail, isPresent, validateCustomer, validateQuantity, canLeaveStep } from './validation.js';

describe('isValidEmail — parity with the current wizard regex', () => {
  it('accepts well-formed addresses', () => {
    for (const e of ['a@b.co', 'ada.lovelace@example.com', 'o\'brien+tag@sub.domain.io']) {
      expect(isValidEmail(e)).toBe(true);
    }
  });

  it('rejects malformed or TLD-less addresses', () => {
    for (const e of ['', 'plainstring', 'a@b', 'a@b.', '@example.com', 'a b@example.com', null, 123]) {
      expect(isValidEmail(e)).toBe(false);
    }
  });

  it('trims surrounding whitespace before validating', () => {
    expect(isValidEmail('  ada@example.com  ')).toBe(true);
  });
});

describe('isPresent', () => {
  it('is false for empty/whitespace strings and null', () => {
    expect(isPresent('')).toBe(false);
    expect(isPresent('   ')).toBe(false);
    expect(isPresent(null)).toBe(false);
    expect(isPresent(undefined)).toBe(false);
  });
  it('is true for non-empty content', () => {
    expect(isPresent('x')).toBe(true);
    expect(isPresent(0)).toBe(true);
  });
});

describe('validateCustomer', () => {
  it('passes with name + valid email and no phone requirement', () => {
    expect(validateCustomer({ name: 'Ada', email: 'ada@example.com' })).toEqual({});
  });

  it('flags a missing name', () => {
    expect(validateCustomer({ email: 'ada@example.com' })).toHaveProperty('name', 'validation.nameRequired');
  });

  it('flags a missing vs invalid email distinctly', () => {
    expect(validateCustomer({ name: 'Ada' }).email).toBe('validation.emailRequired');
    expect(validateCustomer({ name: 'Ada', email: 'nope' }).email).toBe('validation.emailInvalid');
  });

  it('requires phone only when opted in', () => {
    expect(validateCustomer({ name: 'A', email: 'a@b.co' }, { requirePhone: true }).phone).toBe(
      'validation.phoneRequired',
    );
    expect(validateCustomer({ name: 'A', email: 'a@b.co', phone: '123' }, { requirePhone: true })).toEqual({});
  });
});

describe('validateQuantity', () => {
  it('accepts an integer within range', () => {
    expect(validateQuantity(2, { min: 1, max: 5 })).toBeNull();
  });
  it('rejects non-integers', () => {
    expect(validateQuantity(1.5)).toBe('validation.quantityInvalid');
    expect(validateQuantity('x')).toBe('validation.quantityInvalid');
  });
  it('enforces min and max', () => {
    expect(validateQuantity(0, { min: 1 })).toBe('validation.quantityTooLow');
    expect(validateQuantity(9, { max: 5 })).toBe('validation.quantityTooHigh');
  });
});

describe('canLeaveStep', () => {
  it('service requires a selection', () => {
    expect(canLeaveStep('service', { serviceId: null }).ok).toBe(false);
    expect(canLeaveStep('service', { serviceId: 3 }).ok).toBe(true);
  });

  it('datetime requires date+time for a standard service', () => {
    expect(canLeaveStep('datetime', { date: '2026-08-01', time: null, lock: null }).ok).toBe(false);
    expect(canLeaveStep('datetime', { date: '2026-08-01', time: '10:00', lock: null }).ok).toBe(true);
  });

  it('a held lock satisfies the datetime step', () => {
    expect(canLeaveStep('datetime', { date: null, time: null, lock: { token: 't' } }).ok).toBe(true);
  });

  it('info validates the customer and honors requirePhone', () => {
    const ctx = { customer: { name: 'Ada', email: 'ada@example.com' } };
    expect(canLeaveStep('info', ctx).ok).toBe(true);
    expect(canLeaveStep('info', ctx, { requirePhone: true }).ok).toBe(false);
  });

  it('non-gated steps pass through', () => {
    for (const s of ['extras', 'location', 'employee', 'review']) {
      expect(canLeaveStep(s, {}).ok).toBe(true);
    }
  });
});
