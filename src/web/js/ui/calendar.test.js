import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Calendar } from './calendar.js';

function mountCal(opts = {}) {
  document.body.innerHTML = '<div id="cal"></div>';
  const el = document.getElementById('cal');
  const cal = new Calendar(el, { month: '2026-08', firstDay: 1, ...opts });
  return { cal, el };
}

const cell = (el, date) => el.querySelector(`[data-slots-date="${date}"]`);
const key = (el, k) =>
  el.querySelector('[data-slots-cal="grid"]').dispatchEvent(new window.KeyboardEvent('keydown', { key: k, bubbles: true }));

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('Calendar — rendering', () => {
  it('renders a grid with 7 weekday column headers', () => {
    const { el } = mountCal();
    expect(el.querySelector('[role="grid"]')).not.toBeNull();
    expect(el.querySelectorAll('[role="columnheader"]')).toHaveLength(7);
  });

  it('renders every day of the month as a gridcell', () => {
    const { el } = mountCal({ month: '2026-08' }); // August = 31 days
    expect(el.querySelectorAll('[data-slots-date]')).toHaveLength(31);
    expect(cell(el, '2026-08-01')).not.toBeNull();
    expect(cell(el, '2026-08-31')).not.toBeNull();
  });

  it('gives each day a full, localized accessible name (not a bare number)', () => {
    const { el } = mountCal({ month: '2026-08' });
    expect(cell(el, '2026-08-15').getAttribute('aria-label')).toBe('15 August 2026');
  });

  it('localizes month + weekday + day names when a locale is given (Intl)', () => {
    const { el } = mountCal({ month: '2026-08', locale: 'fr' });
    expect(el.querySelector('[data-slots-cal="label"]').textContent).toBe('août 2026');
    // First column header is Monday in French (firstDay: 1).
    expect(el.querySelector('[role="columnheader"]').getAttribute('aria-label')).toBe('lundi');
    expect(cell(el, '2026-08-15').getAttribute('aria-label')).toBe('15 août 2026');
  });

  it('uses caller-supplied labels (e.g. translated prev/next) over derived ones', () => {
    const { el } = mountCal({ locale: 'fr', labels: { prevMonth: 'Mois précédent', nextMonth: 'Mois suivant' } });
    expect(el.querySelector('[data-slots-cal="prev"]').getAttribute('aria-label')).toBe('Mois précédent');
    expect(el.querySelector('[data-slots-cal="next"]').getAttribute('aria-label')).toBe('Mois suivant');
  });

  it('shows the month/year label', () => {
    const { el } = mountCal({ month: '2026-08' });
    expect(el.querySelector('[data-slots-cal="label"]').textContent).toBe('August 2026');
  });

  it('places Aug 1 2026 (a Saturday) in the correct weekday column (Mon-first)', () => {
    const { el } = mountCal({ month: '2026-08' });
    const row = cell(el, '2026-08-01').parentElement;
    const idx = Array.from(row.children).indexOf(cell(el, '2026-08-01'));
    expect(idx).toBe(5); // Mon=0 … Sat=5
  });
});

describe('Calendar — roving tabindex', () => {
  it('exposes exactly one focusable day at a time', () => {
    const { el } = mountCal();
    const focusable = Array.from(el.querySelectorAll('[data-slots-date]')).filter((c) => c.tabIndex === 0);
    expect(focusable).toHaveLength(1);
  });

  it('moves the tabindex as focus moves', () => {
    const { el } = mountCal();
    cell(el, '2026-08-01').focus();
    key(el, 'ArrowRight');
    expect(cell(el, '2026-08-02').tabIndex).toBe(0);
    expect(cell(el, '2026-08-01').tabIndex).toBe(-1);
  });
});

describe('Calendar — keyboard navigation', () => {
  it('ArrowRight/Left move by a day, ArrowDown/Up by a week', () => {
    const { el } = mountCal();
    cell(el, '2026-08-01').focus(); // focusin syncs the cursor here
    key(el, 'ArrowRight');
    expect(document.activeElement).toBe(cell(el, '2026-08-02'));
    key(el, 'ArrowDown');
    expect(document.activeElement).toBe(cell(el, '2026-08-09'));
    key(el, 'ArrowUp');
    expect(document.activeElement).toBe(cell(el, '2026-08-02'));
    key(el, 'ArrowLeft');
    expect(document.activeElement).toBe(cell(el, '2026-08-01'));
  });

  it('ArrowLeft on the first day crosses into the previous month', () => {
    const onMonthChange = vi.fn();
    const { el } = mountCal({ onMonthChange });
    cell(el, '2026-08-01').focus();
    key(el, 'ArrowLeft'); // → 2026-07-31
    expect(el.querySelector('[data-slots-cal="label"]').textContent).toBe('July 2026');
    expect(cell(el, '2026-07-31')).not.toBeNull();
    expect(onMonthChange).toHaveBeenCalledWith({ year: 2026, month: 7 });
  });

  it('Home/End jump to the start/end of the focused week', () => {
    const { el } = mountCal();
    cell(el, '2026-08-12').focus(); // a Wednesday
    key(el, 'Home');
    expect(document.activeElement.getAttribute('data-slots-date')).toBe('2026-08-10'); // Monday
    key(el, 'End');
    expect(document.activeElement.getAttribute('data-slots-date')).toBe('2026-08-16'); // Sunday
  });

  it('PageDown/PageUp move by a month', () => {
    const { el } = mountCal();
    cell(el, '2026-08-15').focus();
    key(el, 'PageDown');
    expect(el.querySelector('[data-slots-cal="label"]').textContent).toBe('September 2026');
    key(el, 'PageUp');
    expect(el.querySelector('[data-slots-cal="label"]').textContent).toBe('August 2026');
  });
});

describe('Calendar — selection', () => {
  it('Enter selects the focused day and fires onSelect', () => {
    const onSelect = vi.fn();
    const { el } = mountCal({ onSelect });
    cell(el, '2026-08-05').focus();
    key(el, 'Enter');
    expect(onSelect).toHaveBeenCalledWith('2026-08-05');
    expect(cell(el, '2026-08-05').getAttribute('aria-selected')).toBe('true');
  });

  it('Space selects too', () => {
    const onSelect = vi.fn();
    const { el } = mountCal({ onSelect });
    cell(el, '2026-08-07').focus();
    key(el, ' ');
    expect(onSelect).toHaveBeenCalledWith('2026-08-07');
  });

  it('clicking a day selects it', () => {
    const onSelect = vi.fn();
    const { el } = mountCal({ onSelect });
    cell(el, '2026-08-20').click();
    expect(onSelect).toHaveBeenCalledWith('2026-08-20');
    expect(cell(el, '2026-08-20').getAttribute('aria-selected')).toBe('true');
  });

  it('marks exactly one selected day', () => {
    const { el } = mountCal();
    cell(el, '2026-08-03').click();
    cell(el, '2026-08-04').click();
    const selected = Array.from(el.querySelectorAll('[aria-selected="true"]'));
    expect(selected).toHaveLength(1);
    expect(selected[0].getAttribute('data-slots-date')).toBe('2026-08-04');
  });
});

describe('Calendar — availability & range', () => {
  it('marks unavailable days aria-disabled and refuses to select them', () => {
    const onSelect = vi.fn();
    const { el } = mountCal({
      isAvailable: (d) => d !== '2026-08-15',
      onSelect,
    });
    expect(cell(el, '2026-08-15').getAttribute('aria-disabled')).toBe('true');
    cell(el, '2026-08-15').click();
    expect(onSelect).not.toHaveBeenCalled();
    expect(cell(el, '2026-08-15').getAttribute('aria-selected')).toBe('false');
  });

  it('disables days before min and after max', () => {
    const { el } = mountCal({ min: '2026-08-10', max: '2026-08-20' });
    expect(cell(el, '2026-08-09').getAttribute('aria-disabled')).toBe('true');
    expect(cell(el, '2026-08-21').getAttribute('aria-disabled')).toBe('true');
    expect(cell(el, '2026-08-15').hasAttribute('aria-disabled')).toBe(false);
  });

  it('setAvailability re-renders disabled state', () => {
    const { cal, el } = mountCal();
    expect(cell(el, '2026-08-15').hasAttribute('aria-disabled')).toBe(false);
    cal.setAvailability((d) => d !== '2026-08-15');
    expect(cell(el, '2026-08-15').getAttribute('aria-disabled')).toBe('true');
  });

  it('next-month button changes the month and notifies', () => {
    const onMonthChange = vi.fn();
    const { el } = mountCal({ onMonthChange });
    el.querySelector('[data-slots-cal="next"]').click();
    expect(el.querySelector('[data-slots-cal="label"]').textContent).toBe('September 2026');
    expect(onMonthChange).toHaveBeenCalledWith({ year: 2026, month: 9 });
  });
});

describe('Calendar — range mode', () => {
  it('first pick sets the start and fires onRangeStart', () => {
    const onRangeStart = vi.fn();
    const onRangeComplete = vi.fn();
    const { el } = mountCal({ mode: 'range', onRangeStart, onRangeComplete });
    cell(el, '2026-08-05').click();
    expect(onRangeStart).toHaveBeenCalledWith('2026-08-05');
    expect(onRangeComplete).not.toHaveBeenCalled();
    expect(cell(el, '2026-08-05').getAttribute('data-range-start')).toBe('true');
  });

  it('second pick completes the range and marks in-between days', () => {
    const onRangeComplete = vi.fn();
    const { el } = mountCal({ mode: 'range', onRangeComplete });
    cell(el, '2026-08-05').click();
    cell(el, '2026-08-08').click();
    expect(onRangeComplete).toHaveBeenCalledWith({ start: '2026-08-05', end: '2026-08-08' });
    expect(cell(el, '2026-08-05').getAttribute('data-range-start')).toBe('true');
    expect(cell(el, '2026-08-08').getAttribute('data-range-end')).toBe('true');
    expect(cell(el, '2026-08-06').getAttribute('data-in-range')).toBe('true');
    expect(cell(el, '2026-08-07').getAttribute('data-in-range')).toBe('true');
    expect(cell(el, '2026-08-08').hasAttribute('data-in-range')).toBe(false);
  });

  it('picking an earlier end restarts the range from that day', () => {
    const onRangeStart = vi.fn();
    const onRangeComplete = vi.fn();
    const { el } = mountCal({ mode: 'range', onRangeStart, onRangeComplete });
    cell(el, '2026-08-10').click(); // start
    cell(el, '2026-08-06').click(); // earlier → new start
    expect(onRangeComplete).not.toHaveBeenCalled();
    expect(onRangeStart).toHaveBeenLastCalledWith('2026-08-06');
    expect(cell(el, '2026-08-06').getAttribute('data-range-start')).toBe('true');
    expect(cell(el, '2026-08-10').hasAttribute('data-range-start')).toBe(false);
  });

  it('setRange highlights a programmatic range (fixed-day services)', () => {
    const { cal, el } = mountCal({ mode: 'range' });
    cal.setRange('2026-08-03', '2026-08-05');
    expect(cal.rangeStart).toBe('2026-08-03');
    expect(cal.rangeEnd).toBe('2026-08-05');
    expect(cell(el, '2026-08-04').getAttribute('data-in-range')).toBe('true');
  });
});
