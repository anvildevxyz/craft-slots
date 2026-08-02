import { describe, it, expect, vi } from 'vitest';
import { Emitter } from './emitter.js';

describe('Emitter', () => {
  it('delivers payloads to subscribers in subscription order', () => {
    const e = new Emitter();
    const calls = [];
    e.on('x', () => calls.push('a'));
    e.on('x', () => calls.push('b'));
    e.emit('x', 42);
    expect(calls).toEqual(['a', 'b']);
  });

  it('passes the payload through', () => {
    const e = new Emitter();
    const spy = vi.fn();
    e.on('x', spy);
    e.emit('x', { n: 1 });
    expect(spy).toHaveBeenCalledWith({ n: 1 });
  });

  it('on() returns an unsubscribe that stops delivery', () => {
    const e = new Emitter();
    const spy = vi.fn();
    const off = e.on('x', spy);
    off();
    e.emit('x');
    expect(spy).not.toHaveBeenCalled();
  });

  it('once() fires exactly once', () => {
    const e = new Emitter();
    const spy = vi.fn();
    e.once('x', spy);
    e.emit('x');
    e.emit('x');
    expect(spy).toHaveBeenCalledTimes(1);
  });

  it('off() with a handler removes only that handler', () => {
    const e = new Emitter();
    const a = vi.fn();
    const b = vi.fn();
    e.on('x', a);
    e.on('x', b);
    e.off('x', a);
    e.emit('x');
    expect(a).not.toHaveBeenCalled();
    expect(b).toHaveBeenCalledTimes(1);
  });

  it('off() without a handler clears the whole event', () => {
    const e = new Emitter();
    const a = vi.fn();
    e.on('x', a);
    e.off('x');
    e.emit('x');
    expect(a).not.toHaveBeenCalled();
  });

  it('emitting an unknown event is a no-op', () => {
    const e = new Emitter();
    expect(() => e.emit('nothing', 1)).not.toThrow();
  });

  it('subscribing during emit does not fire for the in-flight emission', () => {
    const e = new Emitter();
    const late = vi.fn();
    e.on('x', () => e.on('x', late));
    e.emit('x');
    expect(late).not.toHaveBeenCalled();
  });

  it('unsubscribing during emit still runs the already-snapshotted handlers', () => {
    const e = new Emitter();
    const b = vi.fn();
    const offA = e.on('x', () => offA());
    e.on('x', b);
    e.emit('x');
    expect(b).toHaveBeenCalledTimes(1);
  });

  it('isolates a throwing handler and still runs the rest', () => {
    const e = new Emitter();
    const after = vi.fn();
    e.on('x', () => {
      throw new Error('boom');
    });
    e.on('x', after);
    e.emit('x');
    expect(after).toHaveBeenCalledTimes(1);
  });

  it('re-emits a handler exception on the error channel', () => {
    const e = new Emitter();
    const onError = vi.fn();
    e.on('error', onError);
    e.on('x', () => {
      throw new Error('boom');
    });
    e.emit('x');
    expect(onError).toHaveBeenCalledTimes(1);
    expect(onError.mock.calls[0][0]).toMatchObject({ code: 'handler_exception', message: 'boom' });
  });

  it('does not loop when an error handler itself throws', () => {
    const e = new Emitter();
    e.on('error', () => {
      throw new Error('secondary');
    });
    expect(() => e.emit('error', { message: 'primary' })).not.toThrow();
  });

  it('throws if on() is given a non-function', () => {
    const e = new Emitter();
    expect(() => e.on('x', 123)).toThrow(TypeError);
  });

  it('clear() drops every subscription', () => {
    const e = new Emitter();
    const spy = vi.fn();
    e.on('x', spy);
    e.clear();
    e.emit('x');
    expect(spy).not.toHaveBeenCalled();
  });
});
