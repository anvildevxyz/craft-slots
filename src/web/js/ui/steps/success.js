/**
 * Success step renderer — the confirmation screen shown after a booking confirms.
 *
 * Fills `data-slots-summary="…"` slots from the reservation the core stored on
 * the context at confirm time (id, status, appointment) plus the customer email
 * for the "you'll receive a confirmation" note. Every slot is optional, so a
 * minimal success template that only shows a heading still works.
 */
import { qs, setText } from '../dom.js';

export const successStep = {
  render(region, wizard) {
    const { context } = wizard.getState();
    const r = context.reservation || {};

    setText(qs('[data-slots-summary="status"]', region), r.statusLabel ?? r.status ?? '');
    setText(qs('[data-slots-summary="booking-id"]', region), r.id ?? r.reference ?? '');

    // Prefer the server-formatted date/time; fall back to the picked values.
    const appointment =
      r.formattedDateTime ?? [context.date, context.time].filter(Boolean).join(' ');
    setText(qs('[data-slots-summary="appointment"]', region), appointment);

    setText(qs('[data-slots-summary="customer-email"]', region), context.customer?.email ?? '');
  },
};
