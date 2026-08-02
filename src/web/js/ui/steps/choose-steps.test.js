import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { Renderer } from '../renderer.js';
import { locationStep } from './location.js';
import { employeeStep } from './employee.js';

function fakeApi(overrides = {}) {
  return {
    paymentSettings: vi.fn(async () => ({ paymentEnabled: false })),
    // Two services keep the service step visible; the lone-service auto-skip
    // is covered in core/wizard.test.js.
    services: vi.fn(async () => ({
      services: [
        { id: 12, name: 'Cut', price: 40 },
        { id: 13, name: 'Shave', price: 25 },
      ],
    })),
    employees: vi.fn(async () => ({
      employees: [
        { id: 1, name: 'Ada', bio: 'Senior' },
        { id: 2, name: 'Grace', bio: 'Lead' },
      ],
      locations: [
        { id: 7, name: 'Downtown' },
        { id: 8, name: 'Uptown' },
      ],
      serviceHasSchedule: false,
    })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

async function startedWizard(apiOverrides = {}) {
  const w = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'booking' });
  await w.start();
  await w.selectService(12);
  return w;
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('locationStep', () => {
  const REGION = `
    <section>
      <template data-slots-template="location-card">
        <button data-slots-action="select-location"><span data-slots-field="name"></span></button>
      </template>
      <div data-slots-list="locations"></div>
    </section>`;

  it('renders a card per location with ids', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    locationStep.render(region, wizard);
    const cards = region.querySelectorAll('[data-slots-action="select-location"]');
    expect(cards).toHaveLength(2);
    expect(cards[0].getAttribute('data-slots-id')).toBe('7');
    expect(cards[0].querySelector('[data-slots-field="name"]').textContent).toBe('Downtown');
  });

  it('reflects selection via aria-pressed', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    wizard.selectLocation(8);
    locationStep.render(region, wizard);
    expect(region.querySelector('[data-slots-id="8"]').getAttribute('aria-pressed')).toBe('true');
    expect(region.querySelector('[data-slots-id="7"]').getAttribute('aria-pressed')).toBe('false');
  });

  it('re-fetches employees scoped to the chosen location, dropping non-matching staff', async () => {
    const employees = vi.fn(async (serviceId, query) =>
      query?.locationId === 8
        ? { employees: [{ id: 2, name: 'Grace' }], locations: [{ id: 7 }, { id: 8 }], serviceHasSchedule: false }
        : {
            employees: [
              { id: 1, name: 'Ada' },
              { id: 2, name: 'Grace' },
            ],
            locations: [{ id: 7 }, { id: 8 }],
            serviceHasSchedule: false,
          }
    );
    const wizard = await startedWizard({ employees });
    expect(wizard.getState().context.employees.map((e) => e.id)).toEqual([1, 2]);

    await wizard.selectLocation(8);

    // Employees were re-fetched with the location filter, and only Uptown staff remain.
    expect(employees).toHaveBeenLastCalledWith(12, { locationId: 8 });
    const ctx = wizard.getState().context;
    expect(ctx.employees.map((e) => e.id)).toEqual([2]);
    expect(ctx.employeeId).toBe(2); // lone remaining option is auto-selected
  });
});

describe('deep links', () => {
  it('a preselected serviceId skips the service step even with several services', async () => {
    const wizard = new Wizard({ apiClient: fakeApi(), flow: 'booking', serviceId: 13 });
    await wizard.start();
    const ctx = wizard.getState().context;
    expect(ctx.servicePreselected).toBe(true);
    expect(ctx.serviceId).toBe(13);
    expect(wizard.stepId).not.toBe('service');
  });

  it('a preselected locationId skips the location step and scopes employees', async () => {
    const employees = vi.fn(async (serviceId, query) =>
      query?.locationId === 8
        ? { employees: [{ id: 2, name: 'Grace' }], locations: [{ id: 7 }, { id: 8 }], serviceHasSchedule: false }
        : {
            employees: [
              { id: 1, name: 'Ada' },
              { id: 2, name: 'Grace' },
            ],
            locations: [{ id: 7 }, { id: 8 }],
            serviceHasSchedule: false,
          }
    );
    const wizard = new Wizard({
      apiClient: fakeApi({ employees }),
      flow: 'booking',
      serviceId: 12,
      locationId: 8,
    });
    await wizard.start();
    const ctx = wizard.getState().context;
    expect(ctx.locationPreselected).toBe(true);
    expect(ctx.locationId).toBe(8);
    expect(ctx.employeeId).toBe(2); // scoped employees → lone option auto-selected
    // service + location preselected, employee auto-selected → opens on the calendar.
    expect(wizard.stepId).toBe('datetime');
  });
});

describe('employeeStep', () => {
  const REGION = `
    <section>
      <template data-slots-template="employee-card">
        <button data-slots-action="select-employee">
          <span data-slots-field="name"></span><span data-slots-field="bio"></span>
        </button>
      </template>
      <div data-slots-list="employees"></div>
    </section>`;

  it('renders employees with name + bio', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    employeeStep.render(region, wizard);
    const cards = region.querySelectorAll('[data-slots-action="select-employee"]');
    expect(cards).toHaveLength(2);
    expect(cards[1].querySelector('[data-slots-field="name"]').textContent).toBe('Grace');
    expect(cards[0].querySelector('[data-slots-field="bio"]').textContent).toBe('Senior');
  });
});
