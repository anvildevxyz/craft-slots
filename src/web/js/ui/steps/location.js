/**
 * Location step renderer — choose-one card list of locations. Selection is
 * handled by the shell's `select-location` delegated action; this renderer
 * populates the cards and reflects the current choice.
 */
import { renderCardList } from './card-list.js';

export const locationStep = {
  render(region, wizard) {
    const { context } = wizard.getState();
    renderCardList(region, {
      template: 'location-card',
      list: 'locations',
      action: 'select-location',
      items: context.locations,
      selectedId: context.locationId,
      fields: ['name'],
    });
  },
};
