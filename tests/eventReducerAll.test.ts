import { describe, it, expect } from 'vitest';
import { EventEngine } from '../src/ui/EventEngine';

describe('interrupt and insert behaviors', () => {
  it('interrupts a streaming message', () => {
    const updates: any[] = [];
    const engine = new EventEngine({ onChatUpdate: (m) => updates.push(m) });
    engine.addEvent({ type: 'delta', streamId: 's2', delta: 'Partial output' });
    // simulate immediate interrupt
    engine.interrupt('s2');
    const msgs = updates[updates.length-1];
    const last = msgs[msgs.length-1];
    expect(last.status).toBe('interrupted');
  });

  it('insertAssistantMessage creates a final draft', () => {
    const updates: any[] = [];
    const engine = new EventEngine({ onChatUpdate: (m) => updates.push(m) });
    engine.insertAssistantMessage('Tool output here', false);
    const msgs = updates[updates.length-1];
    const last = msgs[msgs.length-1];
    expect(last.text).toContain('Tool output here');
    expect(last.status).toBe('final');
  });
});
