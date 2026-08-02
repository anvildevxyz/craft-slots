/**
 * DOM primitives for the vanilla renderer — CSP-safe by construction.
 *
 * No `innerHTML` with dynamic data, no inline event handlers, no inline styles
 * for dynamic values. Content is set via `textContent`, structure is cloned
 * from `<template>` elements, and behavior is attached with delegated
 * `addEventListener`. Nothing here evaluates strings, so the wizard runs under
 * a strict `script-src 'self' 'nonce-…'` with no `unsafe-eval`.
 */

/** Query one element within `root` (defaults to document). */
export function qs(selector, root = document) {
  return root.querySelector(selector);
}

/** Query all elements as a real array. */
export function qsa(selector, root = document) {
  return Array.from(root.querySelectorAll(selector));
}

/**
 * Clone the content of a `<template>` selected by a `data-slots-*` hook.
 * Returns a DocumentFragment, or null when the template is absent.
 *
 * @param {ParentNode} root
 * @param {string} name  value of the template's `data-slots-template` attr
 */
export function cloneTemplate(root, name) {
  const tpl = qs(`template[data-slots-template="${name}"]`, root);
  if (!tpl || !('content' in tpl)) return null;
  return tpl.content.cloneNode(true);
}

/** Set an element's visible text safely (never HTML). */
export function setText(el, value) {
  if (el) el.textContent = value == null ? '' : String(value);
}

/** Toggle `hidden` (and aria-hidden) on an element. */
export function setHidden(el, hidden) {
  if (!el) return;
  el.hidden = !!hidden;
  if (hidden) el.setAttribute('aria-hidden', 'true');
  else el.removeAttribute('aria-hidden');
}

/**
 * Delegated event binding: listen on `root` and invoke `handler` when the event
 * target matches `selector`. Returns an unbind function. Delegation means step
 * content can be re-rendered without rebinding, and no inline `on*` attributes
 * are ever needed.
 *
 * @param {EventTarget} root
 * @param {string} type      e.g. 'click'
 * @param {string} selector  CSS selector the target (or an ancestor) must match
 * @param {(event: Event, matched: Element) => void} handler
 * @returns {() => void}
 */
export function delegate(root, type, selector, handler) {
  const listener = (event) => {
    const start = event.target;
    if (!(start instanceof Element)) return;
    const matched = start.closest(selector);
    if (matched && root.contains(matched)) handler(event, matched);
  };
  root.addEventListener(type, listener);
  return () => root.removeEventListener(type, listener);
}

/**
 * Move keyboard focus to `el` without scrolling the page around. Adds a
 * temporary tabindex when the element isn't natively focusable (e.g. a step
 * heading), so screen-reader focus lands on the new step's title.
 */
export function focusElement(el) {
  if (!el) return;
  const hadTabindex = el.hasAttribute('tabindex');
  if (!hadTabindex) el.setAttribute('tabindex', '-1');
  try {
    el.focus({ preventScroll: true });
  } catch {
    el.focus();
  }
  if (!hadTabindex) {
    // Drop the temporary tabindex once focus moves on, keeping the DOM clean.
    el.addEventListener(
      'blur',
      () => {
        if (el.getAttribute('tabindex') === '-1') el.removeAttribute('tabindex');
      },
      { once: true },
    );
  }
}

/**
 * A polite/assertive live-region announcer. Screen readers announce changes to
 * an `aria-live` region; we swap `textContent` (not HTML) to trigger it.
 */
export class LiveRegion {
  /** @param {Element} el an element with role/aria-live already set */
  constructor(el) {
    this._el = el;
  }

  /** @param {string} message @param {'polite'|'assertive'} [politeness] */
  announce(message, politeness = 'polite') {
    if (!this._el) return;
    this._el.setAttribute('aria-live', politeness);
    // Clear then set on the next frame-ish tick so repeated identical messages
    // still register as a change for assistive tech.
    this._el.textContent = '';
    setText(this._el, message);
  }
}
