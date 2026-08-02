import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { Renderer } from '../renderer.js';
import { manageStep } from './manage.js';

function fakeApi(overrides = {}) {
  return {
    manageLoad: vi.fn(async () => ({
      success: true,
      id: 55,
      serviceName: 'Haircut',
      formattedDateTime: 'Aug 1, 2026 · 10:00',
      status: 'confirmed',
      statusLabel: 'Confirmed',
      quantity: 2,
      customerName: 'Ada',
      canCancel: true,
    })),
    manageCancel: vi.fn(async () => ({ success: true })),
    manageReduce: vi.fn(async () => ({ success: true })),
    manageIncrease: vi.fn(async () => ({ success: true })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

function manageWizard(apiOverrides = {}) {
  const api = fakeApi(apiOverrides);
  const wizard = new Wizard({ apiClient: api, mode: 'manage', manageToken: 'mtok' });
  return { wizard, api };
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('Wizard — management mode (core)', () => {
  it('loads the reservation on start and lands on the manage step', async () => {
    const { wizard, api } = manageWizard();
    const onLoaded = vi.fn();
    wizard.on('manage:loaded', onLoaded);
    await wizard.start();
    expect(api.manageLoad).toHaveBeenCalledWith({ token: 'mtok' });
    expect(wizard.stepId).toBe('manage');
    expect(wizard.getState().context.reservation.id).toBe(55);
    expect(onLoaded).toHaveBeenCalled();
  });

  it('surfaces an error for an invalid manage token', async () => {
    const { wizard } = manageWizard({ manageLoad: vi.fn(async () => ({ success: false, message: 'invalid' })) });
    const onError = vi.fn();
    wizard.on('error', onError);
    await wizard.start();
    expect(onError).toHaveBeenCalled();
    expect(wizard.getState().context.reservation).toBeNull();
  });

  it('cancel posts with the token and reloads to the cancelled reservation', async () => {
    let loads = 0;
    const { wizard, api } = manageWizard({
      manageLoad: vi.fn(async () => {
        loads += 1;
        return { success: true, id: 55, quantity: 2, canCancel: loads === 1, status: loads > 1 ? 'cancelled' : 'confirmed', statusLabel: 'x' };
      }),
    });
    await wizard.start();
    const onCancelled = vi.fn();
    wizard.on('manage:cancelled', onCancelled);
    const res = await wizard.manageCancel({ reason: 'plans changed' });
    expect(res.ok).toBe(true);
    expect(api.manageCancel).toHaveBeenCalledWith({ token: 'mtok', reason: 'plans changed' });
    expect(wizard.getState().context.reservation.status).toBe('cancelled');
    expect(onCancelled).toHaveBeenCalled();
  });

  it('reduce and increase post id + token + amount', async () => {
    const { wizard, api } = manageWizard();
    await wizard.start();
    await wizard.manageReduce(1);
    expect(api.manageReduce).toHaveBeenCalledWith({ id: 55, token: 'mtok', reduceBy: 1 });
    await wizard.manageIncrease(3);
    expect(api.manageIncrease).toHaveBeenCalledWith({ id: 55, token: 'mtok', increaseBy: 3 });
  });
});

const MARKUP = `
  <div data-slots-wizard>
    <section data-slots-step="manage">
      <h2 data-slots-step-heading>Manage</h2>
      <dd data-slots-manage="service"></dd>
      <dd data-slots-manage="status"></dd>
      <dd data-slots-manage="quantity"></dd>
      <div data-slots-manage-actions hidden>
        <button data-slots-action="manage-reduce">−</button>
        <button data-slots-action="manage-increase">+</button>
        <button data-slots-action="manage-cancel">Cancel</button>
      </div>
      <p data-slots-manage-cancelled hidden>Cancelled</p>
    </section>
  </div>`;

describe('manageStep (DOM)', () => {
  it('renders the reservation and drives cancel via the shell', async () => {
    document.body.innerHTML = MARKUP;
    const root = document.querySelector('[data-slots-wizard]');
    const region = document.querySelector('[data-slots-step="manage"]');
    let loads = 0;
    const api = fakeApi({
      manageLoad: vi.fn(async () => {
        loads += 1;
        return { success: true, id: 55, serviceName: 'Haircut', quantity: 2, canCancel: loads === 1, status: loads > 1 ? 'cancelled' : 'confirmed', statusLabel: 'x' };
      }),
    });
    const wizard = new Wizard({ apiClient: api, mode: 'manage', manageToken: 'mtok' });
    const renderer = new Renderer(wizard, root);
    renderer.registerStep('manage', manageStep);
    manageStep.mount(region, wizard);
    await wizard.start();
    renderer.syncInitial();

    expect(region.querySelector('[data-slots-manage="service"]').textContent).toBe('Haircut');
    expect(region.querySelector('[data-slots-manage="quantity"]').textContent).toBe('2');
    expect(region.querySelector('[data-slots-manage-actions]').hidden).toBe(false);

    region.querySelector('[data-slots-action="manage-cancel"]').click();
    await vi.waitFor(() => expect(region.querySelector('[data-slots-manage-cancelled]').hidden).toBe(false));
    expect(region.querySelector('[data-slots-manage-actions]').hidden).toBe(true);
    renderer.destroy();
  });

  it('disables reduce when quantity is 1', async () => {
    document.body.innerHTML = MARKUP;
    const region = document.querySelector('[data-slots-step="manage"]');
    const wizard = new Wizard({
      apiClient: fakeApi({ manageLoad: vi.fn(async () => ({ success: true, id: 1, quantity: 1, canCancel: true, status: 'confirmed', statusLabel: 'x' })) }),
      mode: 'manage',
      manageToken: 't',
    });
    manageStep.mount(region, wizard);
    await wizard.start();
    manageStep.render(region, wizard);
    expect(region.querySelector('[data-slots-action="manage-reduce"]').disabled).toBe(true);
  });
});
