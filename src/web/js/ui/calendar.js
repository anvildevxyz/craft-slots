/**
 * Accessible month calendar — the framework-free replacement for Flatpickr.
 *
 * Implements the WAI-ARIA date-picker grid pattern: a `role="grid"` of
 * `gridcell` days with **roving tabindex** (one day focusable at a time),
 * full arrow-key navigation that crosses month boundaries, Home/End (week),
 * PageUp/PageDown (month), and Enter/Space to select. Unavailable and
 * out-of-range days are `aria-disabled` (focusable so they're discoverable,
 * but not selectable), and the chosen day carries `aria-selected`.
 *
 * Pure and decoupled: it knows nothing about the wizard. Availability is a
 * predicate, and month changes / selections are callbacks — the datetime step
 * renderer wires those to the core.
 */
import { setText } from './dom.js';

const DEFAULT_LABELS = {
  prevMonth: 'Previous month',
  nextMonth: 'Next month',
  weekdays: ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
  weekdaysLong: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
  months: [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ],
};

/**
 * Derive localized month + weekday names for `locale` via Intl, so the calendar
 * matches the site language without a translation catalog. Returns `{}` when no
 * locale is given (keep the English defaults) or if Intl throws (bad locale),
 * so behavior stays predictable and tests without a locale remain English.
 *
 * @param {?string} locale  BCP-47 tag (e.g. 'de', 'fr')
 * @param {number} firstDay 0=Sunday … 1=Monday (matches the grid layout)
 */
function localeLabels(locale, firstDay) {
  if (!locale) return {};
  try {
    const longMonth = new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' });
    const longDay = new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' });
    const shortDay = new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' });
    const months = [];
    for (let m = 0; m < 12; m++) months.push(longMonth.format(new Date(Date.UTC(2021, m, 1))));
    const weekdays = [];
    const weekdaysLong = [];
    // 2021-08-01 is a Sunday; walk 7 days from firstDay to match the grid order.
    for (let i = 0; i < 7; i++) {
      const dow = (firstDay + i) % 7; // 0=Sun … 6=Sat
      const d = new Date(Date.UTC(2021, 7, 1 + dow));
      weekdays.push(shortDay.format(d));
      weekdaysLong.push(longDay.format(d));
    }
    return { months, weekdays, weekdaysLong };
  } catch {
    return {};
  }
}

// ---- pure date helpers (integer y/m/d, no timezone drift) ================

function pad(n) {
  return String(n).padStart(2, '0');
}
function ymd(y, m, d) {
  return `${y}-${pad(m)}-${pad(d)}`;
}
function parse(str) {
  const [y, m, d] = str.split('-').map(Number);
  return { y, m, d };
}
function daysInMonth(y, m) {
  return new Date(y, m, 0).getDate(); // m is 1-based; day 0 of next month
}
/** 0=Mon … 6=Sun, given firstDay offset. */
function weekdayIndex(y, m, d, firstDay) {
  const js = new Date(y, m - 1, d).getDay(); // 0=Sun..6=Sat
  return (js - firstDay + 7) % 7;
}
/** Shift a YYYY-MM-DD string by n days. */
function addDays(str, n) {
  const { y, m, d } = parse(str);
  const dt = new Date(y, m - 1, d + n);
  return ymd(dt.getFullYear(), dt.getMonth() + 1, dt.getDate());
}

export class Calendar {
  /**
   * @param {Element} container
   * @param {Object} opts
   * @param {string} [opts.month]       initial 'YYYY-MM' (defaults to min or first available)
   * @param {string} [opts.min]         earliest selectable 'YYYY-MM-DD'
   * @param {string} [opts.max]         latest selectable 'YYYY-MM-DD'
   * @param {number} [opts.firstDay]    0=Sunday, 1=Monday (default 1)
   * @param {(date: string) => boolean} [opts.isAvailable]
   * @param {(date: string) => void} [opts.onSelect]
   * @param {(ym: {year:number, month:number}) => void} [opts.onMonthChange]
   * @param {Object} [opts.labels]
   */
  constructor(container, opts = {}) {
    if (!container) throw new Error('Calendar: container is required');
    this._el = container;
    this._min = opts.min ?? null;
    this._max = opts.max ?? null;
    this._firstDay = opts.firstDay ?? 1;
    this._isAvailable = typeof opts.isAvailable === 'function' ? opts.isAvailable : () => true;
    this._onSelect = opts.onSelect ?? (() => {});
    this._onMonthChange = opts.onMonthChange ?? (() => {});
    // Localized month/weekday names are derived from the locale via Intl when
    // one is given; otherwise the English DEFAULT_LABELS stand in. Caller-supplied
    // `opts.labels` (e.g. the translated prev/next aria-labels) win over both.
    this._labels = { ...DEFAULT_LABELS, ...localeLabels(opts.locale, opts.firstDay ?? 1), ...(opts.labels ?? {}) };

    // Range mode (multi-day services): two-click start → end selection.
    this._mode = opts.mode === 'range' ? 'range' : 'single';
    this._onRangeStart = opts.onRangeStart ?? (() => {});
    this._onRangeComplete = opts.onRangeComplete ?? (() => {});
    this._rangeStart = null;
    this._rangeEnd = null;
    this._selectingEnd = false;

    const start = opts.month ? parse(`${opts.month}-01`) : this._min ? parse(this._min) : parse(ymd(2026, 1, 1));
    this._year = start.y;
    this._month = start.m;
    this._selected = null;
    this._focused = ymd(this._year, this._month, 1);

    this._build();
    this.render();
  }

  // ---- public API ------------------------------------------------------

  setAvailability(fn) {
    this._isAvailable = typeof fn === 'function' ? fn : () => true;
    this.render();
  }

  setSelected(date) {
    this._selected = date;
    if (date) {
      const { y, m } = parse(date);
      this._year = y;
      this._month = m;
      this._focused = date;
    }
    this.render();
  }

  get month() {
    return `${this._year}-${pad(this._month)}`;
  }

  destroy() {
    this._el.replaceChildren();
  }

  // ---- internal --------------------------------------------------------

  _clampToMonth(date) {
    const { y, m } = parse(date);
    if (y === this._year && m === this._month) return date;
    return ymd(this._year, this._month, 1);
  }

  _selectable(date) {
    if (this._min && date < this._min) return false;
    if (this._max && date > this._max) return false;
    return this._isAvailable(date);
  }

  _build() {
    this._el.replaceChildren();
    this._el.setAttribute('role', 'group');

    // Header: prev, label, next.
    const header = document.createElement('div');
    header.className = 'slots-cal__header';
    this._prevBtn = document.createElement('button');
    this._prevBtn.type = 'button';
    this._prevBtn.setAttribute('data-slots-cal', 'prev');
    this._prevBtn.setAttribute('aria-label', this._labels.prevMonth);
    this._prevBtn.textContent = '‹';
    this._label = document.createElement('div');
    this._label.setAttribute('data-slots-cal', 'label');
    this._label.setAttribute('aria-live', 'polite');
    this._nextBtn = document.createElement('button');
    this._nextBtn.type = 'button';
    this._nextBtn.setAttribute('data-slots-cal', 'next');
    this._nextBtn.setAttribute('aria-label', this._labels.nextMonth);
    this._nextBtn.textContent = '›';
    header.append(this._prevBtn, this._label, this._nextBtn);

    this._prevBtn.addEventListener('click', () => this._changeMonth(-1));
    this._nextBtn.addEventListener('click', () => this._changeMonth(1));

    // Grid.
    this._grid = document.createElement('table');
    this._grid.setAttribute('role', 'grid');
    this._grid.setAttribute('data-slots-cal', 'grid');
    this._grid.addEventListener('keydown', (e) => this._onKeydown(e));
    this._grid.addEventListener('click', (e) => this._onClick(e));
    // Keep the internal cursor in sync with wherever DOM focus actually lands
    // (Tab into the grid, or a programmatic focus) so arrow nav starts correctly.
    this._grid.addEventListener('focusin', (e) => {
      const c = e.target.closest && e.target.closest('[data-slots-date]');
      if (c) this._focused = c.getAttribute('data-slots-date');
    });

    this._el.append(header, this._grid);
  }

  _changeMonth(delta) {
    let m = this._month + delta;
    let y = this._year;
    if (m < 1) {
      m = 12;
      y -= 1;
    } else if (m > 12) {
      m = 1;
      y += 1;
    }
    this._year = y;
    this._month = m;
    this._focused = this._clampToMonth(this._focused);
    this.render();
    this._onMonthChange({ year: y, month: m });
  }

  render() {
    // Label
    setText(this._label, `${this._labels.months[this._month - 1]} ${this._year}`);
    this._grid.replaceChildren();

    // Column headers.
    const thead = document.createElement('thead');
    const hrow = document.createElement('tr');
    for (let i = 0; i < 7; i++) {
      const th = document.createElement('th');
      th.scope = 'col';
      th.setAttribute('role', 'columnheader');
      const idx = (this._firstDay + i) % 7 === 0 ? 6 : (this._firstDay + i - 1) % 7; // map to Mo-first labels
      th.setAttribute('aria-label', this._labels.weekdaysLong[idx] ?? '');
      th.textContent = this._labels.weekdays[idx] ?? '';
      hrow.appendChild(th);
    }
    thead.appendChild(hrow);
    this._grid.appendChild(thead);

    // Body weeks.
    const tbody = document.createElement('tbody');
    const total = daysInMonth(this._year, this._month);
    const lead = weekdayIndex(this._year, this._month, 1, this._firstDay);

    let row = document.createElement('tr');
    row.setAttribute('role', 'row');
    for (let i = 0; i < lead; i++) row.appendChild(this._emptyCell());

    for (let d = 1; d <= total; d++) {
      if (row.children.length === 7) {
        tbody.appendChild(row);
        row = document.createElement('tr');
        row.setAttribute('role', 'row');
      }
      row.appendChild(this._dayCell(ymd(this._year, this._month, d), d));
    }
    while (row.children.length < 7) row.appendChild(this._emptyCell());
    tbody.appendChild(row);
    this._grid.appendChild(tbody);
  }

  _emptyCell() {
    const td = document.createElement('td');
    td.setAttribute('role', 'gridcell');
    td.setAttribute('aria-hidden', 'true');
    return td;
  }

  _dayCell(date, dayNum) {
    const td = document.createElement('td');
    td.setAttribute('role', 'gridcell');
    td.setAttribute('data-slots-date', date);
    td.textContent = String(dayNum);
    // Full, localized accessible name so a screen reader announces
    // "15 August 2026", not a bare "15" with no month/year context.
    td.setAttribute('aria-label', `${dayNum} ${this._labels.months[this._month - 1]} ${this._year}`);

    const selectable = this._selectable(date);
    const isFocused = this._focused === date;

    let isSelected;
    if (this._mode === 'range') {
      const isStart = this._rangeStart === date;
      const isEnd = this._rangeEnd === date;
      const inRange =
        this._rangeStart && this._rangeEnd && date > this._rangeStart && date < this._rangeEnd;
      isSelected = isStart || isEnd;
      if (isStart) td.setAttribute('data-range-start', 'true');
      if (isEnd) td.setAttribute('data-range-end', 'true');
      if (inRange) td.setAttribute('data-in-range', 'true');
    } else {
      isSelected = this._selected === date;
      if (isSelected) td.setAttribute('data-selected', 'true');
    }

    td.setAttribute('aria-selected', isSelected ? 'true' : 'false');
    if (!selectable) td.setAttribute('aria-disabled', 'true');
    // Roving tabindex: exactly one focusable cell.
    td.tabIndex = isFocused ? 0 : -1;
    return td;
  }

  _cellFor(date) {
    return this._grid.querySelector(`[data-slots-date="${date}"]`);
  }

  _focusDate(date, { changedMonth = false } = {}) {
    const { y, m } = parse(date);
    const crossed = y !== this._year || m !== this._month;
    this._focused = date;
    if (crossed) {
      this._year = y;
      this._month = m;
      this.render();
      if (!changedMonth) this._onMonthChange({ year: y, month: m });
    } else {
      // Update roving tabindex in place.
      for (const cell of this._grid.querySelectorAll('[data-slots-date]')) {
        cell.tabIndex = cell.getAttribute('data-slots-date') === date ? 0 : -1;
      }
    }
    const cell = this._cellFor(date);
    if (cell) cell.focus();
  }

  _onKeydown(e) {
    const current = this._focused;
    let next = null;
    switch (e.key) {
      case 'ArrowRight':
        next = addDays(current, 1);
        break;
      case 'ArrowLeft':
        next = addDays(current, -1);
        break;
      case 'ArrowDown':
        next = addDays(current, 7);
        break;
      case 'ArrowUp':
        next = addDays(current, -7);
        break;
      case 'Home':
        next = addDays(current, -weekdayIndex(...Object.values(parse(current)), this._firstDay));
        break;
      case 'End':
        next = addDays(current, 6 - weekdayIndex(...Object.values(parse(current)), this._firstDay));
        break;
      case 'PageUp':
        next = this._shiftMonth(current, -1);
        break;
      case 'PageDown':
        next = this._shiftMonth(current, 1);
        break;
      case 'Enter':
      case ' ':
        e.preventDefault();
        this._select(current);
        return;
      default:
        return;
    }
    if (next) {
      e.preventDefault();
      this._focusDate(next);
    }
  }

  _shiftMonth(date, delta) {
    const { y, m, d } = parse(date);
    let nm = m + delta;
    let ny = y;
    if (nm < 1) {
      nm = 12;
      ny -= 1;
    } else if (nm > 12) {
      nm = 1;
      ny += 1;
    }
    const clampedDay = Math.min(d, daysInMonth(ny, nm));
    return ymd(ny, nm, clampedDay);
  }

  _onClick(e) {
    const cell = e.target.closest('[data-slots-date]');
    if (cell) this._select(cell.getAttribute('data-slots-date'));
  }

  _select(date) {
    if (!this._selectable(date)) return;

    if (this._mode === 'range') {
      this._selectRange(date);
      return;
    }

    this._selected = date;
    this._focused = date;
    this.render();
    const cell = this._cellFor(date);
    if (cell) cell.focus();
    this._onSelect(date);
  }

  _selectRange(date) {
    // Picking a start (or restarting when an end earlier than the start is chosen).
    if (!this._selectingEnd || date < this._rangeStart) {
      this._rangeStart = date;
      this._rangeEnd = null;
      this._selectingEnd = true;
      this._focused = date;
      this.render();
      const cell = this._cellFor(date);
      if (cell) cell.focus();
      this._onRangeStart(date);
      return;
    }
    // Completing the range.
    this._rangeEnd = date;
    this._selectingEnd = false;
    this._focused = date;
    this.render();
    const cell = this._cellFor(date);
    if (cell) cell.focus();
    this._onRangeComplete({ start: this._rangeStart, end: date });
  }

  /** Programmatically set the selected range (e.g. a fixed-day computed end). */
  setRange(start, end) {
    this._rangeStart = start;
    this._rangeEnd = end;
    this._selectingEnd = false;
    if (start) {
      const { y, m } = parse(start);
      this._year = y;
      this._month = m;
      this._focused = start;
    }
    this.render();
  }

  get rangeStart() {
    return this._rangeStart;
  }

  get rangeEnd() {
    return this._rangeEnd;
  }
}
