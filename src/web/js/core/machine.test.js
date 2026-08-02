import { describe, it, expect, vi } from 'vitest';
import { Machine, STATES } from './machine.js';

describe('Machine — lifecycle transitions', () => {
  it('starts in idle', () => {
    expect(new Machine().state).toBe(STATES.IDLE);
  });

  it('allows the happy path idle → loading → browsing → holdingLock → submitting → confirmed', () => {
    const m = new Machine();
    expect(m.transition(STATES.LOADING)).toBe(true);
    expect(m.transition(STATES.BROWSING)).toBe(true);
    expect(m.transition(STATES.HOLDING_LOCK)).toBe(true);
    expect(m.transition(STATES.SUBMITTING)).toBe(true);
    expect(m.transition(STATES.CONFIRMED)).toBe(true);
    expect(m.state).toBe(STATES.CONFIRMED);
  });

  it('supports the payment branch submitting → paying → confirmed', () => {
    const m = new Machine();
    m.transition(STATES.LOADING);
    m.transition(STATES.BROWSING);
    m.transition(STATES.HOLDING_LOCK);
    m.transition(STATES.SUBMITTING);
    expect(m.transition(STATES.PAYING)).toBe(true);
    expect(m.transition(STATES.CONFIRMED)).toBe(true);
  });

  it('rejects an illegal transition and preserves state', () => {
    const m = new Machine();
    // idle cannot jump straight to confirmed
    expect(m.transition(STATES.CONFIRMED)).toBe(false);
    expect(m.state).toBe(STATES.IDLE);
  });

  it('can() reports legality without transitioning', () => {
    const m = new Machine();
    expect(m.can(STATES.LOADING)).toBe(true);
    expect(m.can(STATES.BROWSING)).toBe(false);
    expect(m.state).toBe(STATES.IDLE);
  });

  it('allows lock expiry from holdingLock, submitting and paying', () => {
    for (const reach of [
      [STATES.LOADING, STATES.BROWSING, STATES.HOLDING_LOCK],
      [STATES.LOADING, STATES.BROWSING, STATES.HOLDING_LOCK, STATES.SUBMITTING],
      [STATES.LOADING, STATES.BROWSING, STATES.HOLDING_LOCK, STATES.SUBMITTING, STATES.PAYING],
    ]) {
      const m = new Machine();
      for (const s of reach) m.transition(s);
      expect(m.transition(STATES.EXPIRED)).toBe(true);
    }
  });

  it('allows HOLDING_LOCK → HOLDING_LOCK (re-picking a slot while holding one)', () => {
    const m = new Machine();
    m.transition(STATES.LOADING);
    m.transition(STATES.BROWSING);
    m.transition(STATES.HOLDING_LOCK);
    expect(m.transition(STATES.HOLDING_LOCK)).toBe(true);
  });

  it('allows EXPIRED → HOLDING_LOCK (recovering by acquiring a fresh lock)', () => {
    const m = new Machine();
    m.transition(STATES.LOADING);
    m.transition(STATES.BROWSING);
    m.transition(STATES.HOLDING_LOCK);
    m.transition(STATES.EXPIRED);
    expect(m.transition(STATES.HOLDING_LOCK)).toBe(true);
    // …and from there submit can proceed.
    expect(m.can(STATES.SUBMITTING)).toBe(true);
  });

  it('lets an expired lock go back to browsing to re-pick', () => {
    const m = new Machine();
    m.transition(STATES.LOADING);
    m.transition(STATES.BROWSING);
    m.transition(STATES.HOLDING_LOCK);
    m.transition(STATES.EXPIRED);
    expect(m.transition(STATES.BROWSING)).toBe(true);
  });

  it('does not allow confirmed → anything except idle (reset)', () => {
    const m = new Machine();
    m.transition(STATES.LOADING);
    m.transition(STATES.BROWSING);
    m.transition(STATES.HOLDING_LOCK);
    m.transition(STATES.SUBMITTING);
    m.transition(STATES.CONFIRMED);
    expect(m.transition(STATES.BROWSING)).toBe(false);
    expect(m.transition(STATES.PAYING)).toBe(false);
    expect(m.transition(STATES.IDLE)).toBe(true);
  });

  it('allows error recovery back to browsing and retry to holdingLock', () => {
    const m = new Machine();
    m.transition(STATES.LOADING);
    m.transition(STATES.BROWSING);
    m.transition(STATES.ERROR);
    expect(m.can(STATES.BROWSING)).toBe(true);
    expect(m.can(STATES.HOLDING_LOCK)).toBe(true);
  });

  it('fires onChange with {from, to, meta} on each successful transition', () => {
    const onChange = vi.fn();
    const m = new Machine(onChange);
    m.transition(STATES.LOADING, { reason: 'start' });
    expect(onChange).toHaveBeenCalledTimes(1);
    expect(onChange.mock.calls[0][0]).toMatchObject({
      from: STATES.IDLE,
      to: STATES.LOADING,
      meta: { reason: 'start' },
    });
  });

  it('does not fire onChange on a rejected transition', () => {
    const onChange = vi.fn();
    const m = new Machine(onChange);
    m.transition(STATES.CONFIRMED); // illegal from idle
    expect(onChange).not.toHaveBeenCalled();
  });

  it('treats browsing → browsing as a legal self-transition (step cursor moves)', () => {
    const onChange = vi.fn();
    const m = new Machine(onChange);
    m.transition(STATES.LOADING);
    m.transition(STATES.BROWSING);
    onChange.mockClear();
    expect(m.transition(STATES.BROWSING)).toBe(true);
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('hardReset() returns to idle and announces the change when not already idle', () => {
    const onChange = vi.fn();
    const m = new Machine(onChange);
    m.transition(STATES.LOADING);
    onChange.mockClear();
    m.hardReset();
    expect(m.state).toBe(STATES.IDLE);
    expect(onChange).toHaveBeenCalledWith({ from: STATES.LOADING, to: STATES.IDLE, meta: { reason: 'reset' } });
  });

  it('hardReset() from idle is silent', () => {
    const onChange = vi.fn();
    const m = new Machine(onChange);
    m.hardReset();
    expect(onChange).not.toHaveBeenCalled();
  });
});
