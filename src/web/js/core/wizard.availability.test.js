import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from './wizard.js';

function fakeApi(overrides = {}) {
  return {
    paymentSettings: vi.fn(async () => ({ paymentEnabled: false })),
    services: vi.fn(async () => ({ services: [{ id: 12, name: 'Cut', price: 40 }] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    calendar: vi.fn(async () => ({
      success: true,
      calendar: { '2026-08-10': { isBookable: true }, '2026-08-11': { isBookable: false } },
    })),
    slots: vi.fn(async () => ({ success: true, slots: [{ time: '10:00', availableCapacity: 2 }] })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
    abortAll: vi.fn(),
    ...overrides,
  };
}

async function started(apiOverrides = {}) {
  const api = fakeApi(apiOverrides);
  const wizard = new Wizard({ apiClient: api, flow: 'booking' });
  await wizard.start();
  await wizard.selectService(12);
  return { wizard, api };
}

beforeEach(() => {});

describe('Wizard.loadCalendar', () => {
  it('returns the availability map and passes the current selection', async () => {
    const { wizard, api } = await started();
    const map = await wizard.loadCalendar({ year: 2026, month: 8 });
    expect(map['2026-08-10'].isBookable).toBe(true);
    expect(api.calendar).toHaveBeenCalledWith(expect.objectContaining({ serviceId: 12, year: 2026, month: 8 }));
  });

  it('emits data:loaded with kind calendar', async () => {
    const { wizard } = await started();
    const seen = [];
    wizard.on('data:loaded', (e) => seen.push(e.kind));
    await wizard.loadCalendar({ year: 2026, month: 8 });
    expect(seen).toContain('calendar');
  });

  it('returns null (not an error) when the request is superseded', async () => {
    const aborted = new Error('superseded');
    aborted.aborted = true;
    const { wizard } = await started({ calendar: vi.fn(async () => { throw aborted; }) });
    const onError = vi.fn();
    wizard.on('error', onError);
    const map = await wizard.loadCalendar({ year: 2026, month: 8 });
    expect(map).toBeNull();
    expect(onError).not.toHaveBeenCalled();
  });
});

describe('Wizard.loadSlots', () => {

  it('emits data:loaded with kind slots', async () => {
    const { wizard } = await started();
    const seen = [];
    wizard.on('data:loaded', (e) => seen.push(e));
    await wizard.loadSlots({ date: '2026-08-10' });
    const slotsEvent = seen.find((e) => e.kind === 'slots');
    expect(slotsEvent.items).toHaveLength(1);
  });
});

