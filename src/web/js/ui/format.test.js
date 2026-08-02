import { describe, it, expect } from 'vitest';
import { formatPrice } from './format.js';

describe('formatPrice', () => {
  it('formats a number to two decimals with the currency symbol', () => {
    expect(formatPrice(40, 'CHF')).toBe('40.00 CHF');
    expect(formatPrice('25.5', '$')).toBe('25.50 $');
  });

  it('omits the symbol when none is given', () => {
    expect(formatPrice(12)).toBe('12.00');
  });

  it('returns an empty string for empty or non-numeric values (never "NaN")', () => {
    expect(formatPrice(null, 'CHF')).toBe('');
    expect(formatPrice('', 'CHF')).toBe('');
    expect(formatPrice(undefined)).toBe('');
    expect(formatPrice('abc', 'CHF')).toBe('');
  });
});
