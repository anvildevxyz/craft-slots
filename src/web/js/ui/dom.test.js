import { describe, it, expect, vi } from 'vitest';
import { qs, qsa, cloneTemplate, setText, setHidden, delegate, focusElement, LiveRegion } from './dom.js';

function mount(html) {
  document.body.innerHTML = html;
  return document.body;
}

describe('dom — query & text', () => {
  it('qs / qsa scope to a root', () => {
    const root = mount('<div class="a"><span class="b">x</span><span class="b">y</span></div>');
    expect(qs('.a', root)).not.toBeNull();
    expect(qsa('.b', root)).toHaveLength(2);
  });

  it('setText writes textContent, never HTML', () => {
    const root = mount('<p id="t"></p>');
    setText(qs('#t', root), '<b>bold</b>');
    expect(qs('#t', root).textContent).toBe('<b>bold</b>');
    expect(qs('#t b', root)).toBeNull(); // not parsed as HTML
  });

  it('setText coerces null/undefined to empty', () => {
    const root = mount('<p id="t">old</p>');
    setText(qs('#t', root), null);
    expect(qs('#t', root).textContent).toBe('');
  });
});

describe('dom — cloneTemplate', () => {
  it('clones a named template fragment', () => {
    const root = mount('<template data-slots-template="card"><div class="card">hi</div></template>');
    const frag = cloneTemplate(root, 'card');
    expect(frag).not.toBeNull();
    expect(frag.querySelector('.card').textContent).toBe('hi');
  });

  it('returns null for an unknown template', () => {
    const root = mount('<div></div>');
    expect(cloneTemplate(root, 'nope')).toBeNull();
  });
});

describe('dom — setHidden', () => {
  it('toggles hidden and aria-hidden', () => {
    const root = mount('<div id="d"></div>');
    const el = qs('#d', root);
    setHidden(el, true);
    expect(el.hidden).toBe(true);
    expect(el.getAttribute('aria-hidden')).toBe('true');
    setHidden(el, false);
    expect(el.hidden).toBe(false);
    expect(el.hasAttribute('aria-hidden')).toBe(false);
  });
});

describe('dom — delegate', () => {
  it('fires for matching targets, including descendants', () => {
    const root = mount('<div id="root"><button class="pick"><span>go</span></button></div>');
    const handler = vi.fn();
    delegate(qs('#root', root), 'click', '.pick', handler);
    qs('span', root).dispatchEvent(new window.Event('click', { bubbles: true }));
    expect(handler).toHaveBeenCalledTimes(1);
    expect(handler.mock.calls[0][1].classList.contains('pick')).toBe(true);
  });

  it('does not fire for non-matching targets', () => {
    const root = mount('<div id="root"><a class="other">x</a></div>');
    const handler = vi.fn();
    delegate(qs('#root', root), 'click', '.pick', handler);
    qs('.other', root).dispatchEvent(new window.Event('click', { bubbles: true }));
    expect(handler).not.toHaveBeenCalled();
  });

  it('unbind stops delivery', () => {
    const root = mount('<div id="root"><button class="pick">x</button></div>');
    const handler = vi.fn();
    const off = delegate(qs('#root', root), 'click', '.pick', handler);
    off();
    qs('.pick', root).dispatchEvent(new window.Event('click', { bubbles: true }));
    expect(handler).not.toHaveBeenCalled();
  });
});

describe('dom — focusElement', () => {
  it('focuses a non-focusable element via a temporary tabindex', () => {
    const root = mount('<h2 id="h">Step</h2>');
    const h = qs('#h', root);
    focusElement(h);
    expect(document.activeElement).toBe(h);
    expect(h.getAttribute('tabindex')).toBe('-1');
  });

  it('does nothing for a null element', () => {
    expect(() => focusElement(null)).not.toThrow();
  });
});

describe('dom — LiveRegion', () => {
  it('announces by setting textContent and updates politeness', () => {
    const root = mount('<div id="live" aria-live="polite"></div>');
    const region = new LiveRegion(qs('#live', root));
    region.announce('Loaded 3 times', 'assertive');
    expect(qs('#live', root).textContent).toBe('Loaded 3 times');
    expect(qs('#live', root).getAttribute('aria-live')).toBe('assertive');
  });
});
