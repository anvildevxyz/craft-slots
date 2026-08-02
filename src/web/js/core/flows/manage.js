/**
 * Manage flow — the `?manage=<token>` self-service view.
 *
 * A single step: show the reservation and offer cancel / reduce / increase.
 * Not a multi-step wizard, but modelled as a one-step flow so it rides the same
 * renderer machinery as the booking and event flows.
 */
export const manageFlow = {
  id: 'manage',
  steps: [{ id: 'manage', visible: () => true }],
};
