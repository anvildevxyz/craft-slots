import { describe, it, expect } from 'vitest';
import { I18n, DEFAULTS } from './i18n.js';

describe('I18n', () => {
  it('resolves a default English string', () => {
    const i = new I18n();
    expect(i.t('error.slotReserved')).toBe('That time was just taken. Please choose another.');
  });

  it('interpolates {token} placeholders', () => {
    const i = new I18n();
    expect(i.t('lock.expiring', { minutes: 3 })).toBe('Your reservation is held for 3 more minute(s).');
    expect(i.t('announce.stepChanged', { position: 2, total: 4, title: 'Info' })).toBe('Step 2 of 4: Info');
  });

  it('leaves an unknown placeholder untouched', () => {
    const i = new I18n({ 'x.y': 'Hi {name}, {missing}' });
    expect(i.t('x.y', { name: 'Ada' })).toBe('Hi Ada, {missing}');
  });

  it('overrides defaults with a supplied table (translation)', () => {
    const i = new I18n({ 'announce.loading': 'Wird geladen…' }, { locale: 'de' });
    expect(i.t('announce.loading')).toBe('Wird geladen…');
    expect(i.locale).toBe('de');
  });

  it('falls back to the key itself for a truly unknown string', () => {
    const i = new I18n();
    expect(i.t('nope.unknown')).toBe('nope.unknown');
  });

  it('degrades a partially-overridden table to English defaults for absent keys', () => {
    const i = new I18n({ 'announce.loading': 'X' });
    // not overridden → English default, never a raw key
    expect(i.t('lock.expired')).toBe(DEFAULTS['lock.expired']);
  });

  it('has() reports known keys', () => {
    const i = new I18n({ 'custom.key': 'v' });
    expect(i.has('custom.key')).toBe(true);
    expect(i.has('lock.expired')).toBe(true);
    expect(i.has('nope')).toBe(false);
  });

  it('extend() merges late-arriving strings', () => {
    const i = new I18n();
    i.extend({ 'late.key': 'Late {n}' });
    expect(i.t('late.key', { n: 1 })).toBe('Late 1');
  });

  it('every default value is a non-empty string with balanced braces', () => {
    for (const [key, val] of Object.entries(DEFAULTS)) {
      expect(typeof val, key).toBe('string');
      expect(val.length, key).toBeGreaterThan(0);
      const opens = (val.match(/\{/g) || []).length;
      const closes = (val.match(/\}/g) || []).length;
      expect(opens, key).toBe(closes);
    }
  });
});
