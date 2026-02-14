import React from 'react';
import { createRoot } from 'react-dom/client';
import { useEventStream } from './openclaw_useEventStream';
import { ChatView } from './openclaw_ChatView';
import { ToolTracePanel } from './openclaw_ToolTrace';
import { PresetBadge } from './openclaw_PresetBadge';

function App() {
  const { chatMessages, toolTraces, addEvent, stop, insertAssistantMessage, clear } = useEventStream();
  (window as any).__openclaw_addEvent = addEvent;

  const handleInsert = (text: string) => insertAssistantMessage(text, false);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', padding: 8 }}>
        <div style={{ fontWeight: 700 }}>OpenClaw — Chat</div>
        <div style={{ display: 'flex', gap: 8 }}>
          <PresetBadge onChange={(id) => console.log('preset', id)} />
          <button onClick={() => clear()}>Reset</button>
        </div>
      </div>
      <div style={{ flex: 1, overflow: 'auto', padding: 8 }}>
        <ChatView messages={chatMessages} onStop={(sid) => stop(sid)} onEdit={(id, text) => {}} />
      </div>
      <div>
        <ToolTracePanel traces={toolTraces} onInsert={handleInsert} />
      </div>
    </div>
  );
}

let root: any = null;
export function mountOpenClawChat(containerId = 'openclaw-chat') {
  let el = document.getElementById(containerId);
  if (!el) {
    el = document.createElement('div');
    el.id = containerId;
    Object.assign(el.style, {
      position: 'fixed',
      right: '12px',
      bottom: '12px',
      width: '420px',
      height: '560px',
      zIndex: 99999,
      boxShadow: '0 8px 24px rgba(0,0,0,0.2)',
      borderRadius: '8px',
      background: '#fff',
      overflow: 'hidden',
    });
    document.body.appendChild(el);
  }
  if (!root) {
    root = createRoot(el);
    root.render(React.createElement(App));
  }
}

export function attachOpenClawWebSocket(ws: WebSocket) {
  (window as any).__OPENCLAW_WS__ = ws;
  ws.addEventListener('message', (ev) => {
    try {
      const payload = JSON.parse(ev.data as string);
      const map = (p: any) => {
        if (!p) return null;
        if (p.type) return p;
        if (p.event === 'delta') return { type: 'delta', streamId: p.streamId || 's1', delta: p.chunk || p.data };
        if (p.event === 'done') return { type: 'done', streamId: p.streamId || 's1' };
        if (p.tool && p.tool_event === 'result') return { type: 'tool_result', id: p.tool_id, payload: p.output };
        if (p.message && p.role) return { type: p.role === 'user' ? 'user' : 'system', text: String(p.message) };
        return null;
      };
      const ev = map(payload);
      if (ev && typeof (window as any).__openclaw_addEvent === 'function') (window as any).__openclaw_addEvent(ev);
    } catch (e) {
      // ignore
    }
  });
}

// Auto-run
if (typeof window !== 'undefined') {
  setTimeout(() => {
    try {
      const ws = (window as any).ws || (window as any).__OPENCLAW_WS__;
      if (ws) attachOpenClawWebSocket(ws as WebSocket);
    } catch (e) {}
  }, 500);
}
