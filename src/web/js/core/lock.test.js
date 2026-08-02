import { describe, it, expect, vi } from 'vitest';
import { LockController } from './lock.js';

/**
 * Deterministic clock + timer queue. Timers are stored with their absolute due
 * time; advancing the clock fires everything due, in order. This lets us test
 * the warning/expiry/extend choreography without real time or fake-timer/Promise
 * interplay.
 */
function harness(startAt = 0) {
  let now = startAt;
  let seq = 0;
  const timers = new Map(); // handle → { due, fn }
  const clock = {
    now: () => now,
    setTimer: (fn, ms) => {
      const handle = ++seq;
      timers.set(handle, { due: now + ms, fn });
      return handle;
    },
    clearTimer: (handle) => timers.delete(handle),
    advance: (ms) => {
      const target = now + ms;
      // Fire due timers in due-time order until we reach the target.
      let guard = 0;
      while (true) {
        let next = null;
        for (const [h, t] of timers) {
          if (t.due <= target && (next === null || t.due < next.t.due)) next = { h, t };
        }
        if (!next) break;
        now = next.t.due;
        timers.delete(next.h);
        next.t.fn();
        if (++guard > 1000) throw new Error('timer loop runaway');
      }
      now = target;
    },
  };
  return clock;
}

function makeApi(overrides = {}) {
  return {
    createSlotLock: vi.fn(async () => ({ success: true, token: 'tok-1', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    ...overrides,
  };
}

function events() {
  const log = [];
  return { emit: (event, payload) => log.push({ event, payload }), log, names: () => log.map((e) => e.event) };
}

describe('LockController — acquire', () => {
  it('acquires a slot lock, computes expiresAt from expiresIn, emits lock:acquired', async () => {
    const clock = harness(1000);
    const api = makeApi();
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, ...clock });

    const res = await lock.acquire('slot', { date: '2026-08-01', startTime: '10:00', serviceId: 3 });

    expect(res).toMatchObject({ acquired: true, token: 'tok-1' });
    expect(lock.held).toBe(true);
    expect(lock.expiresAt).toBe(1000 + 300 * 1000);
    expect(api.createSlotLock).toHaveBeenCalledWith({ date: '2026-08-01', startTime: '10:00', serviceId: 3 });
    expect(ev.names()).toContain('lock:acquired');
  });

  it('reports acquired:false without a token (slot taken) and holds no lock', async () => {
    const clock = harness();
    const api = makeApi({ createSlotLock: vi.fn(async () => ({ success: false, message: 'slot reserved' })) });
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, ...clock });

    const res = await lock.acquire('slot', {});
    expect(res).toMatchObject({ acquired: false, message: 'slot reserved' });
    expect(lock.held).toBe(false);
    expect(ev.names()).not.toContain('lock:acquired');
  });

  it('releases an existing lock before acquiring a new one', async () => {
    const clock = harness();
    const api = makeApi();
    const lock = new LockController({ api, emit: () => {}, ...clock });
    await lock.acquire('slot', {});
    await lock.acquire('slot', {});
    expect(api.releaseLock).toHaveBeenCalledTimes(1);
  });

  it('rejects an unknown kind', async () => {
    const lock = new LockController({ api: makeApi(), emit: () => {}, ...harness() });
    await expect(lock.acquire('nope', {})).rejects.toThrow(/unknown kind/);
  });

  it('drops a concurrent acquisition (double-click) as busy — no orphaned lock', async () => {
    const clock = harness();
    let resolveFirst;
    const createSlotLock = vi.fn(() => new Promise((r) => { resolveFirst = r; }));
    const api = makeApi({ createSlotLock });
    const lock = new LockController({ api, emit: () => {}, ...clock });

    const first = lock.acquire('slot', { time: '10:00' });
    const second = await lock.acquire('slot', { time: '11:00' }); // fires while first in flight
    expect(second).toEqual({ acquired: false, busy: true });
    expect(createSlotLock).toHaveBeenCalledTimes(1); // only one lock request went out

    resolveFirst({ success: true, token: 't', expiresIn: 300 });
    await first;
    expect(lock.token).toBe('t');
  });
});

describe('LockController — hold timer', () => {
  it('fires lock:expiring at the warning threshold and lock:expired at expiry', async () => {
    const clock = harness(0);
    const api = makeApi({ createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })) });
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, warningThresholdMs: 60_000, ...clock });
    await lock.acquire('slot', {});

    clock.advance(239_000); // 239s in — before the 240s warning point
    expect(ev.names()).not.toContain('lock:expiring');

    clock.advance(2_000); // cross 240s
    expect(ev.names()).toContain('lock:expiring');
    expect(lock.held).toBe(true);

    clock.advance(60_000); // reach 300s
    expect(ev.names()).toContain('lock:expired');
    expect(lock.held).toBe(false);
    expect(lock.token).toBeNull();
  });

  it('announces expiring immediately when acquired already inside the warning window', async () => {
    const clock = harness(0);
    const api = makeApi({ createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 30 })) });
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, warningThresholdMs: 60_000, ...clock });
    await lock.acquire('slot', {});
    expect(ev.names()).toContain('lock:expiring');
  });

  it('remainingMs counts down and floors at zero', async () => {
    const clock = harness(0);
    const api = makeApi({ createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 100 })) });
    const lock = new LockController({ api, emit: () => {}, ...clock });
    await lock.acquire('slot', {});
    expect(lock.remainingMs).toBe(100_000);
    clock.advance(40_000);
    expect(lock.remainingMs).toBe(60_000);
    clock.advance(1_000_000);
    expect(lock.remainingMs).toBe(0);
  });
});

describe('LockController — auto-extend (once)', () => {
  it('extends exactly once when committing inside the warning window', async () => {
    const clock = harness(0);
    const api = makeApi({
      createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
      extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    });
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, warningThresholdMs: 60_000, ...clock });
    await lock.acquire('slot', {});

    clock.advance(250_000); // inside warning window (50s left)
    await lock.ensureFresh();
    expect(api.extendLock).toHaveBeenCalledTimes(1);
    expect(ev.names()).toContain('lock:extended');
    expect(lock.remainingMs).toBe(300_000); // refreshed

    // A second commit does not extend again.
    clock.advance(250_000);
    await lock.ensureFresh();
    expect(api.extendLock).toHaveBeenCalledTimes(1);
  });

  it('does not extend when there is still plenty of time', async () => {
    const clock = harness(0);
    const api = makeApi();
    const lock = new LockController({ api, emit: () => {}, warningThresholdMs: 60_000, ...clock });
    await lock.acquire('slot', {});
    clock.advance(10_000); // 290s left
    await lock.ensureFresh();
    expect(api.extendLock).not.toHaveBeenCalled();
  });

  it('ensureFresh is a no-op with no lock held', async () => {
    const lock = new LockController({ api: makeApi(), emit: () => {}, ...harness() });
    await lock.ensureFresh();
    // no throw, nothing extended
    expect(lock.held).toBe(false);
  });

  it('keeps the hold when the expiry timer would fire during a slow extend', async () => {
    const clock = harness(0);
    let resolveExtend;
    const api = makeApi({
      createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
      // Extend that we resolve manually, after advancing the clock past expiry.
      extendLock: vi.fn(() => new Promise((r) => { resolveExtend = r; })),
    });
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, warningThresholdMs: 60_000, ...clock });
    await lock.acquire('slot', {});

    clock.advance(250_000); // inside warning window (50s left)
    const fresh = lock.ensureFresh(); // suspends the expiry timer, awaits extend
    clock.advance(120_000); // would have crossed the original 300s expiry mid-extend
    resolveExtend({ success: true, expiresIn: 300 });
    await fresh;

    // The token survived (not nulled by a mid-flight expiry) and the hold stands.
    expect(lock.held).toBe(true);
    expect(lock.token).toBe('t');
    expect(ev.names()).not.toContain('lock:expired');
  });

  it('swallows an extend failure and keeps the existing hold', async () => {
    const clock = harness(0);
    const api = makeApi({
      createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
      extendLock: vi.fn(async () => {
        throw new Error('network');
      }),
    });
    const lock = new LockController({ api, emit: () => {}, warningThresholdMs: 60_000, ...clock });
    await lock.acquire('slot', {});
    clock.advance(250_000);
    await lock.ensureFresh();
    expect(lock.held).toBe(true); // still holding
  });
});

describe('LockController — release & teardown', () => {
  it('releases server-side, clears state, emits lock:released', async () => {
    const clock = harness(0);
    const api = makeApi();
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, ...clock });
    await lock.acquire('slot', {});
    await lock.release('back-nav');
    expect(api.releaseLock).toHaveBeenCalledWith({ token: 'tok-1' });
    expect(lock.held).toBe(false);
    expect(ev.log.find((e) => e.event === 'lock:released').payload).toEqual({ reason: 'back-nav' });
  });

  it('release with no lock is a silent no-op', async () => {
    const api = makeApi();
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, ...harness() });
    await lock.release();
    expect(api.releaseLock).not.toHaveBeenCalled();
    expect(ev.names()).not.toContain('lock:released');
  });

  it('release swallows a transport error but still clears local state', async () => {
    const clock = harness(0);
    const api = makeApi({
      releaseLock: vi.fn(async () => {
        throw new Error('offline');
      }),
    });
    const lock = new LockController({ api, emit: () => {}, ...clock });
    await lock.acquire('slot', {});
    await expect(lock.release()).resolves.toBeUndefined();
    expect(lock.held).toBe(false);
  });

  it('beaconPayload returns the token while held, null otherwise', async () => {
    const clock = harness(0);
    const lock = new LockController({ api: makeApi(), emit: () => {}, ...clock });
    expect(lock.beaconPayload()).toBeNull();
    await lock.acquire('slot', {});
    expect(lock.beaconPayload()).toEqual({ token: 'tok-1' });
  });

  it('destroy() stops timers without a network call', async () => {
    const clock = harness(0);
    const api = makeApi();
    const ev = events();
    const lock = new LockController({ api, emit: ev.emit, ...clock });
    await lock.acquire('slot', {});
    lock.destroy();
    clock.advance(1_000_000);
    expect(ev.names()).not.toContain('lock:expired'); // timer was torn down
    expect(api.releaseLock).not.toHaveBeenCalled();
  });
});
