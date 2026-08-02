/**
 * Customer-info step renderer.
 *
 * Two-way binds the name/email/phone/notes inputs to the core: `mount` wires
 * one delegated `input` listener that pushes changes into `wizard.setCustomer`;
 * `render` reflects current values and surfaces per-field validation messages
 * (from the core's resolved `error.messages`) into `data-slots-field-error`
 * slots, with `aria-invalid`/`aria-describedby` for assistive tech.
 */
import { qs, qsa, setText, setHidden, delegate } from '../dom.js';

const FIELDS = ['name', 'email', 'phone', 'notes'];
// Per-region teardown callbacks, so unmount() can release the core subscription.
const cleanups = new WeakMap();

export const customerInfoStep = {
  mount(region, wizard) {
    // One delegated listener covers every field, and survives re-renders.
    const offInput = delegate(region, 'input', '[data-slots-field]', (event, el) => {
      const field = el.getAttribute('data-slots-field');
      if (FIELDS.includes(field)) wizard.setCustomer({ [field]: el.value });
    });

    // Surface validation messages when the core reports them for this step.
    const offError = wizard.on('error', (payload) => {
      if (!payload || payload.code !== 'validation' || !payload.messages) return;
      this._applyErrors(region, payload.messages);
    });

    cleanups.set(region, () => {
      offInput();
      offError();
    });
  },

  unmount(region) {
    const off = cleanups.get(region);
    if (off) {
      off();
      cleanups.delete(region);
    }
  },

  render(region, wizard) {
    const { context } = wizard.getState();
    for (const field of FIELDS) {
      const input = qs(`[data-slots-field="${field}"]`, region);
      if (input && document.activeElement !== input) input.value = context.customer?.[field] ?? '';
    }
    this._clearErrors(region);
  },

  _applyErrors(region, messages) {
    this._clearErrors(region);
    for (const [field, message] of Object.entries(messages)) {
      const input = qs(`[data-slots-field="${field}"]`, region);
      const errorEl = qs(`[data-slots-field-error="${field}"]`, region);
      if (input) {
        input.setAttribute('aria-invalid', 'true');
        if (errorEl && errorEl.id) input.setAttribute('aria-describedby', errorEl.id);
      }
      if (errorEl) {
        setText(errorEl, message);
        setHidden(errorEl, false);
      }
    }
  },

  _clearErrors(region) {
    for (const input of qsa('[data-slots-field]', region)) {
      input.removeAttribute('aria-invalid');
    }
    for (const errorEl of qsa('[data-slots-field-error]', region)) {
      setText(errorEl, '');
      setHidden(errorEl, true);
    }
  },
};
