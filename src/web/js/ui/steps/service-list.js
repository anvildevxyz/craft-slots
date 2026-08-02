/**
 * Service-list step renderer.
 *
 * Populates the service step from `wizard` context by cloning a
 * `data-slots-template="service-card"` per service into a
 * `data-slots-list="services"` container. Selection is handled by the shell's
 * delegated `select-service` action; this renderer only reflects which card is
 * currently selected (aria-pressed) so the UI stays in sync.
 */
import { qs, qsa, cloneTemplate, setText } from '../dom.js';
import { formatPrice } from '../format.js';

/** Fill a card fragment's `data-slots-field="…"` slots from a service. */
function fillCard(fragment, service, currencySymbol) {
  const card = fragment.querySelector('[data-slots-action="select-service"]') || fragment.firstElementChild;
  if (card) {
    card.setAttribute('data-slots-id', String(service.id));
    card.setAttribute('aria-pressed', 'false');
  }
  setText(fragment.querySelector('[data-slots-field="name"]'), service.title);
  setText(fragment.querySelector('[data-slots-field="price"]'), formatPrice(service.price, currencySymbol));
  setText(fragment.querySelector('[data-slots-field="duration"]'), service.duration);
  return fragment;
}

export const serviceListStep = {
  render(region, wizard) {
    const list = qs('[data-slots-list="services"]', region);
    if (!list) return;
    const { context } = wizard.getState();
    const services = context.services || [];
    const selectedId = context.serviceId;
    const currencySymbol = context.payment?.currencySymbol;

    // Rebuild the list from a clean slate (no innerHTML with data).
    list.replaceChildren();
    for (const service of services) {
      const frag = cloneTemplate(region, 'service-card');
      if (!frag) break; // no template → nothing to clone
      list.appendChild(fillCard(frag, service, currencySymbol));
    }

    // Reflect the current selection.
    for (const card of qsa('[data-slots-action="select-service"]', region)) {
      const isSelected = Number(card.getAttribute('data-slots-id')) === selectedId;
      card.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    }
  },
};
