/**
 * Date & time step renderer — the accessible calendar + slot listbox.
 *
 * Configures the framework-free Calendar for the selected service:
 *  - standard services: single-date mode; a day → `loadSlots` → slot listbox →
 *    `selectSlot` (acquires the soft lock).
 *  - fixed-day services: range mode; one pick computes the end from the service
 *  - flexible-day services: range mode; the start pick loads valid end dates
 *
 * Step renderers are shared singletons, so per-mount state lives in a WeakMap
 * keyed by the region. The calendar is (re)built whenever the service type
 * changes, so switching services mid-flow reconfigures it correctly.
 */
import { Calendar } from '../calendar.js';
import { qs, delegate, setHidden, setText } from '../dom.js';

const state = new WeakMap();

function pad(n) {
  return String(n).padStart(2, '0');
}

/** Translated aria-labels for the calendar's month-navigation buttons. */
function calendarLabels(wizard) {
  return {
    prevMonth: wizard.t('calendar.prevMonth'),
    nextMonth: wizard.t('calendar.nextMonth'),
  };
}


export const datetimeStep = {
  mount(region, wizard) {
    const slotList = qs('[data-slots-slots]', region);
    const s = {
      calMap: {},
      availSet: new Set(),
      validEndSet: new Set(),
      pickingEnd: false,
      selectedDate: null,
      cal: null,
      calSig: null,
      qtyMax: 1, // capacity of the current selection
      qtyValue: 1,
      reacquire: null, // (quantity) => Promise — re-locks the current selection
    };
    state.set(region, s);

    this._buildCalendar(region, wizard, s);

    // Slot selection (delegated once; survives slot re-renders).
    if (slotList) {
      slotList.setAttribute('role', 'listbox');
      delegate(slotList, 'click', '[data-slots-time]', async (event, el) => {
        if (el.getAttribute('aria-disabled') === 'true') return;
        const time = el.getAttribute('data-slots-time');
        const res = await wizard.selectSlot({ date: s.selectedDate, time, quantity: 1 });
        if (res && res.acquired) {
          for (const opt of slotList.querySelectorAll('[role="option"]')) {
            opt.setAttribute('aria-selected', opt.getAttribute('data-slots-time') === time ? 'true' : 'false');
          }
          // Offer a quantity picker when this slot holds more than one seat.
          s.qtyMax = Number(el.getAttribute('data-slots-capacity')) || 1;
          s.qtyValue = 1;
          s.reacquire = (quantity) => wizard.selectSlot({ date: s.selectedDate, time, quantity });
          this._renderQuantity(region, s);
        }
      });
    }

    // Quantity stepper for capacity>1 selections (slots and multi-day ranges).
    delegate(region, 'click', '[data-slots-action="qty-increment"]', () => this._adjustQuantity(region, s, 1));
    delegate(region, 'click', '[data-slots-action="qty-decrement"]', () => this._adjustQuantity(region, s, -1));

  },

  /** (Re)build the calendar for the current service type when it changes. */
  _buildCalendar(region, wizard, s) {
    const calContainer = qs('[data-slots-calendar]', region);
    if (!calContainer) return;

    const { context } = wizard.getState();
    const sig = `${context.serviceId}:single`;
    if (s.calSig === sig && s.cal) return;
    s.calSig = sig;

    const now = new Date();
    const initialMonth =
      calContainer.getAttribute('data-slots-initial-month') || `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
    const [iy, im] = initialMonth.split('-').map(Number);

    this._buildSingleCalendar(region, wizard, s, calContainer, initialMonth, iy, im);
  },

  _buildSingleCalendar(region, wizard, s, calContainer, initialMonth, iy, im) {
    const cal = new Calendar(calContainer, {
      month: initialMonth,
      mode: 'single',
      locale: wizard.getState()?.context?.locale,
      labels: calendarLabels(wizard),
      isAvailable: (date) => s.calMap[date] && s.calMap[date].isBookable === true,
      onMonthChange: async ({ year, month }) => {
        const map = await wizard.loadCalendar({ year, month });
        if (map) {
          s.calMap = map;
          cal.setAvailability((d) => s.calMap[d] && s.calMap[d].isBookable === true);
        }
      },
      onSelect: async (date) => {
        s.selectedDate = date;
        const res = await wizard.loadSlots({ date });
        if (res) {
          this._renderSlots(region, res.slots, s, wizard);
        }
      },
    });
    s.cal = cal;
    wizard.loadCalendar({ year: iy, month: im }).then((map) => {
      if (map) {
        s.calMap = map;
        cal.setAvailability((d) => s.calMap[d] && s.calMap[d].isBookable === true);
      }
    });
  },


  render(region, wizard) {
    const s = state.get(region);
    if (!s) return;
    // Reconfigure the calendar if the service type changed since it was built.
    this._buildCalendar(region, wizard, s);
    const { context } = wizard.getState();
    if (context.date && s.cal && s.selectedDate !== context.date) {
      s.cal.setSelected(context.date);
      s.selectedDate = context.date;
    }
  },


  /** Reflect the quantity picker (shown only when the selection's capacity > 1). */
  _renderQuantity(region, s) {
    const box = qs('[data-slots-slot-quantity]', region);
    if (!box) return;
    const active = !!s.reacquire && s.qtyMax > 1;
    setHidden(box, !active);
    if (!active) return;
    setText(qs('[data-slots-slot-qty-value]', region), s.qtyValue);
    const dec = qs('[data-slots-action="qty-decrement"]', region);
    const inc = qs('[data-slots-action="qty-increment"]', region);
    if (dec) dec.disabled = s.qtyValue <= 1;
    if (inc) inc.disabled = s.qtyValue >= s.qtyMax;
  },

  /** Change the selection quantity within [1, capacity] and re-lock at the new count. */
  async _adjustQuantity(region, s, delta) {
    if (!s.reacquire) return;
    const next = Math.min(s.qtyMax, Math.max(1, s.qtyValue + delta));
    if (next === s.qtyValue) return;
    s.qtyValue = next;
    this._renderQuantity(region, s);
    await s.reacquire(next);
  },

  _renderSlots(region, slots, s, wizard) {
    const list = qs('[data-slots-slots]', region);
    if (!list) return;
    list.replaceChildren();
    // A fresh slot list means no active selection yet — hide the quantity picker.
    s.reacquire = null;
    this._renderQuantity(region, s);
    const selectedTime = wizard.getState().context.time;
    for (const slot of slots) {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.setAttribute('role', 'option');
      opt.setAttribute('data-slots-time', slot.time);
      const cap = slot.availableCapacity;
      const unavailable = cap != null && cap < 1;
      if (cap != null) opt.setAttribute('data-slots-capacity', String(cap));
      opt.setAttribute('aria-selected', slot.time === selectedTime ? 'true' : 'false');
      if (unavailable) opt.setAttribute('aria-disabled', 'true');
      opt.textContent = slot.time;

      // Group slots carry several seats, so say how many are left. One seat is
      // the ordinary one-on-one case and needs no annotation; unlimited slots
      // (null capacity) have no number to show.
      if (cap != null && cap > 1) {
        const seats = document.createElement('span');
        seats.className = 'slots-slot__seats';
        seats.setAttribute('data-slots-slot-seats', '');
        seats.textContent = wizard.t('slot.seatsAvailable', { count: cap });
        opt.appendChild(seats);
      }

      list.appendChild(opt);
    }

  },
};
