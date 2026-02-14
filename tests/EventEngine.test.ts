// tests/EventEngine.test.ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { EventEngine } from '../src/ui/EventEngine';

describe('EventEngine buffering and finalize', () => {
  let engine: EventEngine;

  beforeEach(() => {
    vi.useFakeTimers();
    engine = new EventEngine({
      onChatUpdate: () => {},
      onToolUpdate: () => {},
    });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('buffers deltas and flushes on idle then finalizes on done', () => {
    const updates: any[] = [];
    engine = new EventEngine({
      onChatUpdate: (m) => updates.push({ type: 'chat', m: m.slice() }),
    });

    // send small delta
    engine.addEvent({ type: 'delta', streamId: 's1', delta: 'Hello' });
    // no immediate flush yet (idle timer pending)
    expect(updates.length).toBe(0);

    // advance time to trigger idle flush (100ms)
    vi.advanceTimersByTime(120);
    expect(updates.length).toBe(1);
    expect(updates[0].m[0].text).toBe('Hello');
    expect(updates[0].m[0].partial).toBe(true);

    // send more delta punctuation -> immediate flush
    engine.addEvent({ type: 'delta', streamId: 's1', delta: ' world.' });
    // punctuation triggers immediate flush synchronously
    expect(updates.length).toBe(2);
    expect(updates[1].m[1].text).toContain(' world.');

    // final done
    engine.addEvent({ type: 'done', streamId: 's1', timestamp: Date.now() });
    // done should flush anything pending and mark partial=false
    const last = updates[updates.length - 1].m.slice(-1)[0];
    expect(last.partial).toBe(false);
  });
});
