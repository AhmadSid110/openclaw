// src/ui/useEventStream.tsx
import { useCallback, useRef, useState } from 'react';
import { EventEngine, RawEvent, ChatMessage, ToolTrace } from './EventEngine';

export function useEventStream() {
  const [chatMessages, setChatMessages] = useState<ChatMessage[]>([]);
  const [toolTraces, setToolTraces] = useState<ToolTrace[]>([]);
  const engineRef = useRef<EventEngine | null>(null);

  if (!engineRef.current) {
    engineRef.current = new EventEngine({
      onChatUpdate: (m) => setChatMessages(m),
      onToolUpdate: (t) => setToolTraces(t),
    });
  }

  const addEvent = useCallback((e: RawEvent) => {
    engineRef.current?.addEvent(e);
  }, []);

  // Stop/cancel a stream: optimistic interrupt + remote cancel
  const stop = useCallback(async (streamId: string) => {
    // optimistic UI
    engineRef.current?.interrupt(streamId);
    // try WS cancel if available
    try {
      // common pattern: window.__OPENCLAW_WS__ or global ws
      const ws: any = (window as any).__OPENCLAW_WS__ || (window as any).ws;
      if (ws && ws.readyState === 1) {
        ws.send(JSON.stringify({ op: 'cancel', streamId }));
        return;
      }
    } catch (e) {
      // ignore and fallback
    }
    // fallback to HTTP cancel endpoint
    try {
      await fetch('/api/cancel', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ streamId }),
      });
    } catch (e) {
      // ignore network errors; UI already updated optimistically
      console.warn('cancel request failed', e);
    }
  }, []);

  const insertAssistantMessage = useCallback((text: string, draft = false) => {
    return engineRef.current?.insertAssistantMessage(text, draft);
  }, []);

  const clear = useCallback(() => {
    engineRef.current?.clear();
  }, []);

  return {
    chatMessages,
    toolTraces,
    addEvent,
    stop,
    insertAssistantMessage,
    clear,
  };
}
