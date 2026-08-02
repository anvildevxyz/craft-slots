import { describe, it, expect, vi } from 'vitest';
import { SlotsApi, ApiError, AbortedError } from './api.js';

/** Build a fake fetch that records calls and returns a JSON body. */
function fakeFetch({ status = 200, json = {}, text } = {}) {
  const calls = [];
  const impl = vi.fn(async (url, init) => {
    calls.push({ url, init });
    return {
      ok: status >= 200 && status < 300,
      status,
      text: async () => (text !== undefined ? text : JSON.stringify(json)),
    };
  });
  impl.calls = calls;
  return impl;
}

const csrf = { name: 'CRAFT_CSRF_TOKEN', value: 'abc123' };

describe('SlotsApi — request shaping', () => {
  it('builds versioned GET URLs with the site handle', async () => {
    const f = fakeFetch({ json: { ok: true } });
    const api = new SlotsApi({ csrf, site: 'de', fetch: f });
    await api.get('services');
    const { url, init } = f.calls[0];
    expect(url).toBe('/slots/api/v1/services?site=de');
    expect(init.method).toBe('GET');
  });

  it('merges query params alongside the site handle', async () => {
    const f = fakeFetch();
    const api = new SlotsApi({ site: 'en', fetch: f });
    await api.get('availability/calendar', { query: { serviceId: 12, month: '2026-08' } });
    expect(f.calls[0].url).toBe('/slots/api/v1/availability/calendar?site=en&serviceId=12&month=2026-08');
  });

  it('omits the site param when none is configured', async () => {
    const f = fakeFetch();
    const api = new SlotsApi({ fetch: f });
    await api.get('me');
    expect(f.calls[0].url).toBe('/slots/api/v1/me');
  });

  it('form-encodes POST bodies and injects the CSRF token', async () => {
    const f = fakeFetch();
    const api = new SlotsApi({ csrf, fetch: f });
    await api.post('bookings', { body: { serviceId: 3, sendReminder: true, notes: null } });
    const body = f.calls[0].init.body;
    expect(body).toBeInstanceOf(URLSearchParams);
    expect(body.get('CRAFT_CSRF_TOKEN')).toBe('abc123');
    expect(body.get('serviceId')).toBe('3');
    expect(body.get('sendReminder')).toBe('1'); // boolean → '1'/'0'
    expect(body.has('notes')).toBe(false); // null dropped
  });

  it('encodes a nested object as key[subKey]=value for PHP array parsing', async () => {
    const f = fakeFetch();
    const api = new SlotsApi({ fetch: f });
    await api.createBooking({ serviceId: 1, fields: { 5: 2, 7: 1 } });
    const body = f.calls[0].init.body;
    expect(body.get('fields[5]')).toBe('2');
    expect(body.get('fields[7]')).toBe('1');
    expect(body.get('serviceId')).toBe('1');
  });

  it('parses a JSON response body', async () => {
    const f = fakeFetch({ json: { services: [{ id: 1 }] } });
    const api = new SlotsApi({ fetch: f });
    const data = await api.services();
    expect(data).toEqual({ services: [{ id: 1 }] });
  });

  it('respects a custom baseUrl', async () => {
    const f = fakeFetch();
    const api = new SlotsApi({ baseUrl: '/actions/slots/api/v2/', fetch: f });
    await api.get('services');
    expect(f.calls[0].url).toBe('/actions/slots/api/v2/services');
  });
});

describe('SlotsApi — errors', () => {
  it('throws ApiError with status and code on a non-2xx response', async () => {
    const f = fakeFetch({ status: 422, json: { error: 'bad input' } });
    const api = new SlotsApi({ fetch: f });
    await expect(api.createBooking({})).rejects.toMatchObject({
      name: 'ApiError',
      status: 422,
      message: 'bad input',
      code: 'http_error',
    });
  });

  it('maps 410 to the expired code (lock gone)', async () => {
    const f = fakeFetch({ status: 410, json: { error: 'lock expired' } });
    const api = new SlotsApi({ fetch: f });
    await expect(api.createBooking({})).rejects.toMatchObject({ code: 'expired', status: 410 });
  });

  it('maps 429 to rate_limited', async () => {
    const f = fakeFetch({ status: 429, json: {} });
    const api = new SlotsApi({ fetch: f });
    await expect(api.slots({})).rejects.toMatchObject({ code: 'rate_limited' });
  });

  it('wraps a network failure as ApiError code=network', async () => {
    const api = new SlotsApi({
      fetch: async () => {
        throw new Error('offline');
      },
    });
    await expect(api.services()).rejects.toMatchObject({ name: 'ApiError', code: 'network' });
  });

  it('throws if no fetch is available', () => {
    const original = globalThis.fetch;
    // eslint-disable-next-line no-global-assign
    globalThis.fetch = undefined;
    try {
      expect(() => new SlotsApi({})).toThrow(/no fetch/);
    } finally {
      globalThis.fetch = original;
    }
  });
});

describe('SlotsApi — stale-response guard (channels)', () => {
  it('aborts the in-flight request when a newer one fires on the same channel', async () => {
    const aborted = [];
    // A fetch that resolves only when its signal aborts (for the first call),
    // and immediately for the second.
    let call = 0;
    const api = new SlotsApi({
      fetch: (url, init) => {
        call++;
        const n = call;
        return new Promise((resolve, reject) => {
          if (n === 1) {
            init.signal.addEventListener('abort', () => {
              aborted.push(n);
              const e = new Error('aborted');
              e.name = 'AbortError';
              reject(e);
            });
          } else {
            resolve({ ok: true, status: 200, text: async () => JSON.stringify({ n }) });
          }
        });
      },
    });

    const first = api.slots({ date: '2026-08-01' }); // channel 'slots'
    const second = api.slots({ date: '2026-08-02' }); // supersedes

    await expect(first).rejects.toBeInstanceOf(AbortedError);
    await expect(second).resolves.toEqual({ n: 2 });
    expect(aborted).toEqual([1]);
  });

  it('requests on different channels do not abort each other', async () => {
    const api = new SlotsApi({
      fetch: async (url) => ({ ok: true, status: 200, text: async () => JSON.stringify({ url }) }),
    });
    const a = await api.slots({});
    const b = await api.calendar({});
    expect(a).toBeTruthy();
    expect(b).toBeTruthy();
  });

  it('createPayment posts to payment/create with the reservation body', async () => {
    const f = fakeFetch({ json: { success: true, paymentToken: 'sig|u|1' } });
    const api = new SlotsApi({ csrf, fetch: f });
    await api.createPayment({ reservationId: 7, token: 'conf-tok' });
    const { url, init } = f.calls[0];
    expect(url).toContain('/slots/api/v1/payment/create');
    expect(init.body.toString()).toContain('reservationId=7');
    expect(init.body.toString()).toContain('token=conf-tok');
  });

  it('confirmPayment posts the signed payment token', async () => {
    const f = fakeFetch({ json: { success: true, status: 'paid', paid: true } });
    const api = new SlotsApi({ csrf, fetch: f });
    await api.confirmPayment('sig|u|1');
    const { url, init } = f.calls[0];
    expect(url).toContain('/slots/api/v1/payment/confirm');
    expect(init.body.toString()).toContain('paymentToken=sig');
  });

  it('manageLoad sends the confirmation token as manageToken, never the reserved `token` query param', async () => {
    const f = fakeFetch({ json: { success: true } });
    const api = new SlotsApi({ site: 'en', fetch: f });
    await api.manageLoad({ token: 'abc-tok' });
    const url = f.calls[0].url;
    // Craft reserves ?token= for tokenized routes (would 400 before the controller).
    expect(url).toContain('manageToken=abc-tok');
    expect(url).not.toMatch(/[?&]token=/);
  });

  it('manageCancel posts to the dedicated cancel route with the token in the body under manageToken', async () => {
    const f = fakeFetch({ json: { success: true } });
    const api = new SlotsApi({ fetch: f });
    await api.manageCancel({ token: 'abc-tok', reason: 'x' });
    const { url, init } = f.calls[0];
    expect(url).toContain('/slots/api/v1/manage/cancel');
    expect(url).not.toMatch(/[?&]token=/);
    const body = init.body.toString();
    expect(body).toContain('manageToken=abc-tok');
  });

  it('abortAll() clears in-flight channels', async () => {
    let sawAbort = false;
    const api = new SlotsApi({
      fetch: (url, init) =>
        new Promise((_resolve, reject) => {
          init.signal.addEventListener('abort', () => {
            sawAbort = true;
            const e = new Error('aborted');
            e.name = 'AbortError';
            reject(e);
          });
        }),
    });
    const p = api.slots({});
    api.abortAll();
    await expect(p).rejects.toBeInstanceOf(AbortedError);
    expect(sawAbort).toBe(true);
  });
});
