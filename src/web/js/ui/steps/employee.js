/**
 * Employee step renderer — choose-one card list of employees. Selection is
 * handled by the shell's `select-employee` delegated action; this renderer
 * populates the cards and reflects the current choice.
 */
import { renderCardList } from './card-list.js';

export const employeeStep = {
  render(region, wizard) {
    const { context } = wizard.getState();
    renderCardList(region, {
      template: 'employee-card',
      list: 'employees',
      action: 'select-employee',
      items: context.employees,
      selectedId: context.employeeId,
      fields: ['name', 'bio'],
    });
  },
};
