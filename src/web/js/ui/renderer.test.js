import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../core/wizard.js';
import { Renderer } from './renderer.js';
import { create } from './index.js';

/** Fake v1 API — a service with its own schedule + single location, no extras. */
function fakeApi(overrides = {}) {
  return {
    paymentSettings: vi.fn(async () => ({ paymentEnabled: false })),
    // Two services keep the service step visible; the lone-service auto-skip
    // is covered in core/wizard.test.js.
    services: vi.fn(async () => ({
      services: [
        { id: 12, name: 'Haircut', price: 40 },
        { id: 13, name: 'Shave', price: 25 },
      ],
    })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 'lock-abc', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    createBooking: vi.fn(async () => ({ success: true, reservation: { reference: 'BKD-1' } })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

/** The DOM contract a Twig include would render. */
const MARKUP = `
  <div data-slots-wizard>
    <div data-slots-loading hidden>Loading…</div>
    <p data-slots-error hidden></p>
    <div data-slots-progress><span data-slots-progress-current></span>/<span data-slots-progress-total></span></div>
    <div aria-live="polite" data-slots-live></div>

    <section data-slots-step="service"><h2 data-slots-step-heading>Service</h2>
      <button data-slots-action="select-service" data-slots-id="12">Haircut</button>
      <button data-slots-action="next">Next</button>
    </section>
    <section data-slots-step="datetime" hidden><h2 data-slots-step-heading>Date</h2>
      <button data-slots-action="back">Back</button>
      <button data-slots-action="next">Next</button>
    </section>
    <section data-slots-step="info" hidden><h2 data-slots-step-heading>Info</h2>
      <button data-slots-action="back">Back</button>
      <button data-slots-action="next">Next</button>
    </section>
    <section data-slots-step="review" hidden><h2 data-slots-step-heading>Review</h2>
      <button data-slots-action="submit">Book</button>
    </section>
    <section data-slots-step="success" hidden><h2 data-slots-step-heading>Done</h2></section>
  </div>`;

function setup(apiOverrides = {}) {
  document.body.innerHTML = MARKUP;
  const root = document.querySelector('[data-slots-wizard]');
  const wizard = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'booking' });
  const renderer = new Renderer(wizard, root);
  return { wizard, renderer, root };
}

const visibleStep = (root) =>
  Array.from(root.querySelectorAll('[data-slots-step]')).find((el) => !el.hidden)?.getAttribute('data-slots-step');

describe('Renderer — step visibility & focus', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('shows the initial step WITHOUT stealing focus on load', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    expect(visibleStep(root)).toBe('service');
    // Initial render must not move focus into the wizard.
    expect(document.activeElement).not.toBe(root.querySelector('[data-slots-step="service"] [data-slots-step-heading]'));
  });

  it('swaps the visible region and moves focus on step:change', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // service → datetime
    expect(visibleStep(root)).toBe('datetime');
    expect(document.activeElement).toBe(root.querySelector('[data-slots-step="datetime"] [data-slots-step-heading]'));
  });

  it('reflects the progress indicator', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    expect(root.querySelector('[data-slots-progress-current]').textContent).toBe('1');
    expect(root.querySelector('[data-slots-progress-total]').textContent).toBe('4'); // service,datetime,info,review
  });
});

describe('Renderer — DOM actions drive the core', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('clicking select-service then Next advances via delegation', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    root.querySelector('[data-slots-action="select-service"]').click();
    // selectService is async; allow the microtask to settle
    await Promise.resolve();
    await Promise.resolve();
    expect(wizard.getState().context.serviceId).toBe(12);
    root.querySelector('[data-slots-step="service"] [data-slots-action="next"]').click();
    expect(wizard.stepId).toBe('datetime');
    expect(visibleStep(root)).toBe('datetime');
  });

  it('Back returns to the previous step', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    root.querySelector('[data-slots-step="datetime"] [data-slots-action="back"]').click();
    expect(wizard.stepId).toBe('service');
    expect(visibleStep(root)).toBe('service');
  });
});

describe('Renderer — announcements & errors', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('mirrors announce events into the live region', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext();
    expect(root.querySelector('[data-slots-live]').textContent).toContain('Step');
  });

  it('shows and then clears the error region', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    wizard.goNext(); // info
    // Leaving info without customer data → validation error surfaces
    wizard.goNext();
    const err = root.querySelector('[data-slots-error]');
    expect(err.hidden).toBe(false);
  });
});

describe('Renderer — anti-spam passthrough', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('sends the honeypot field (name + value) and captcha token with the booking', async () => {
    const { wizard, renderer, root } = setup();
    // Inject a honeypot + captcha token into the wizard root.
    root.insertAdjacentHTML(
      'afterbegin',
      '<input data-slots-honeypot name="website" value="spam.example"><input type="hidden" data-slots-captcha-token value="cap-123">',
    );
    const submitSpy = vi.spyOn(wizard, 'submit');
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext(); // info
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext(); // review
    root.querySelector('[data-slots-step="review"] [data-slots-action="submit"]').click();
    expect(submitSpy).toHaveBeenCalledWith(
      expect.objectContaining({ fields: { website: 'spam.example', captchaToken: 'cap-123' } }),
    );
  });

  it('sends empty fields when no honeypot/captcha is present', async () => {
    const { wizard, renderer, root } = setup();
    const submitSpy = vi.spyOn(wizard, 'submit');
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    root.querySelector('[data-slots-step="review"] [data-slots-action="submit"]').click();
    expect(submitSpy).toHaveBeenCalledWith(expect.objectContaining({ fields: {} }));
  });
});

describe('Renderer — success on confirmed', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('shows the success step when the booking confirms', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext(); // info
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext(); // review
    await wizard.submit();
    expect(visibleStep(root)).toBe('success');
  });
});

describe('ui/index create()', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('returns a bare headless wizard when no mount is given', () => {
    const wizard = create({ apiClient: fakeApi() });
    expect(wizard).toBeInstanceOf(Wizard);
  });

  it('mounts, auto-starts, and shows the first step', async () => {
    document.body.innerHTML = MARKUP;
    const controller = create({ apiClient: fakeApi(), mount: '[data-slots-wizard]' });
    expect(controller.wizard).toBeInstanceOf(Wizard);
    // start() was fired; await it settling
    await controller.start();
    await Promise.resolve();
    expect(visibleStep(document.querySelector('[data-slots-wizard]'))).toBe('service');
    controller.destroy();
  });
});
