/**
 * Review step renderer — a read-only summary of the pending booking.
 *
 * Fills `data-slots-summary="…"` slots from the core's computed context so the
 * customer confirms what they're booking. Rows whose value is empty are hidden
 * entirely (label included), matching the legacy Alpine wizard — so an
 * appointment with no chosen employee/location and quantity 1 shows
 * only the rows that actually apply. Purely presentational; the shell owns the
 * submit action. The same renderer serves the booking and event templates.
 */
import { qs, setText, setHidden } from '../dom.js';
import { formatPrice } from '../format.js';

/**
 * Set a summary row's value, hiding both the <dd> and its paired <dt> when the
 * value is empty so no orphaned label (e.g. "Choose an employee") is shown.
 */
function setRow(region, key, value) {
  const dd = qs(`[data-slots-summary="${key}"]`, region);
  if (!dd) return;
  const empty = value === null || value === undefined || value === '';
  setText(dd, empty ? '' : value);
  setHidden(dd, empty);
  const dt = dd.previousElementSibling;
  if (dt && dt.tagName === 'DT') setHidden(dt, empty);
}

export const reviewStep = {
  render(region, wizard) {
    const { context } = wizard.getState();
    const svc = context.selectedService || {};
    const currencySymbol = context.payment?.currencySymbol;

    setRow(region, 'service', svc.title ?? '');
    // Only shown when actually chosen (mirrors the Alpine summary).
    setRow(region, 'employee', context.selectedEmployee?.name ?? '');
    setRow(region, 'location', context.selectedLocation?.name ?? '');

    setRow(region, 'date', context.date ?? '');
    setRow(region, 'time', context.time ?? '');

    // Quantity only when more than one seat.
    setRow(region, 'quantity', context.quantity > 1 ? context.quantity : '');

    setRow(region, 'customer-name', context.customer?.name ?? '');
    setRow(region, 'customer-email', context.customer?.email ?? '');

    // The total shows only when there is a price to confirm; a free service
    // (no price set) hides the row entirely rather than showing "0.00".
    setRow(region, 'total', context.totalPrice > 0 ? formatPrice(context.totalPrice, currencySymbol) : '');

    // Show the payment notice when payment applies.
    const paymentNotice = qs('[data-slots-payment-notice]', region);
    if (paymentNotice) setHidden(paymentNotice, !context.requiresPayment);
  },
};
