// src/ui/integration.tsx
import React from 'react';
import { createRoot } from 'react-dom/client';
import { useEventStream } from './useEventStream';
import { ChatView } from './ChatView';
import { ToolTracePanel } from './ToolTrace';
import { PresetBadge } from './PresetBadge';

// Lightweight App that mounts ChatView + ToolTrace and wires a WebSocket
function App() {
  const { chatMessages, toolTraces, addEvent, stop, insertAssistantMessage, clear } = useEventStream();

  // Handler to insert tool output into chat
  const handleInsert = (text: string) => {
    insertAssistantMessage(text, false);
  };

  // Expose addEvent globally for remote producers
  (window as any).__openclaw_addEvent = addEvent;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', padding: 8 }}>
        <div style={{ fontWeight: 700 }}>OpenClaw — Chat</div>
        <div style={{ display: 'flex', gap: 8 }}>
          <PresetBadge onChange={(id) => console.log('preset selected', id)} />
          <button onClick={() => clear()}>Reset</button>
        </div>
      </div>
      <div style={{ flex: 1, overflow: 'auto', padding: 8 }}>
        <ChatView messages={chatMessages} onStop={(sid) => stop(sid)} onEdit={(id, text) => console.log('edited', id, text)} />
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
    // attach to body as floating panel
    Object.assign(el.style, {
      position: 'fixed',
      right: '12px',
      bottom: '12px',
      width: '420px',
      height: '560px',
      zIndex: 99999,
      boxShadow: '0 8px 24px rgba(0,0,0,0.2)',
      borderRadius: '8px',
      background: '#ffffff',
      overflow: 'hidden',
    });
    document.body.appendChild(el);
  }
  if (!root) {
    root = createRoot(el);
    root.render(React.createElement(App));
  }
}

// Attach existing WebSocket and pipe messages into addEvent
export function attachOpenClawWebSocket(ws: WebSocket) {
  // expose for stop() optimistic cancel
  (window as any).__OPENCLAW_WS__ = ws;

  ws.addEventListener('message', (ev) => {
    let payload: any;
    try {
      payload = JSON.parse(ev.data as string);
    } catch (e) {
      // ignore non-json
      return;
    }
    // Heuristic mapping: if payload has 'type' use it; else try common shapes
    const mapToRawEvent = (p: any) => {
      if (!p || typeof p !== 'object') return null;
      if (p.type === 'delta' || p.type === 'done' || p.type === 'tool_call' || p.type === 'tool_result' || p.type === 'tool_error' || p.type === 'user' || p.type === 'system') return p;
      // common gateway envelope: { event: 'delta', streamId, chunk }
      if (p.event === 'delta' && (p.chunk || p.data)) return { type: 'delta', streamId: p.streamId || p.id || 's1', delta: p.chunk || p.data };
      if (p.event === 'done') return { type: 'done', streamId: p.streamId || p.id || 's1' };
      if (p.tool && p.tool_event === 'call') return { type: 'tool_call', id: p.tool_id, payload: p.input };
      if (p.tool && p.tool_event === 'result') return { type: 'tool_result', id: p.tool_id, payload: p.output };
      // fallback: messages
      if (p.message && p.role) return { type: p.role === 'user' ? 'user' : 'system', text: String(p.message) };
      return null;
    };

    const ev = mapToRawEvent(payload);
    if (ev) {
      // call the global addEvent bridge if present
      const add = (window as any).__openclaw_addEvent;
      if (typeof add === 'function') add(ev);
    }
  });
}

// Convenience: auto-mount and attach if page provides window.ws
export function autoWire() {
  try {
    mountOpenClawChat();
    const ws = (window as any).ws || (window as any).__OPENCLAW_WS__;
    if (ws) attachOpenClawWebSocket(ws as WebSocket);
  } catch (e) {
    console.warn('autoWire failed', e);
  }
}

// Auto-run when included
if (typeof window !== 'undefined') {
  setTimeout(() => {
    try { autoWire(); } catch(e) {}
  }, 500);
}
