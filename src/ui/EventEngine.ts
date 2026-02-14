// src/ui/EventEngine.ts
export type RawEvent =
  | { type: 'user' | 'system'; id?: string; text: string; timestamp?: number }
  | { type: 'delta'; streamId: string; delta: string; timestamp?: number }
  | { type: 'done'; streamId: string; timestamp?: number }
  | { type: 'tool_call' | 'tool_result' | 'tool_error'; id?: string; payload?: any; timestamp?: number };

export type ChatMessage = {
  id: string;
  streamId?: string;
  author: 'assistant' | 'user' | 'system';
  text: string;
  status?: 'streaming' | 'final' | 'interrupted';
  draft?: boolean;
  timestamp: number;
};

export type ToolTrace = {
  id: string;
  kind: string;
  payload: any;
  timestamp: number;
};

type Callbacks = {
  onChatUpdate?: (messages: ChatMessage[]) => void;
  onToolUpdate?: (tools: ToolTrace[]) => void;
};

export class EventEngine {
  private chatMessages: ChatMessage[] = [];
  private toolTraces: ToolTrace[] = [];
  private buffers = new Map<
    string,
    { messageId: string; text: string; lastFlush: number; timeout?: ReturnType<typeof setTimeout> }
  >();
  private cb: Callbacks;
  // flush policy
  private FLUSH_IDLE_MS = 100; // flush after 100ms idle
  private MAX_BUFFER = 512; // flush when >512 chars
  private now = () => Date.now();

  constructor(cb: Callbacks = {}) {
    this.cb = cb;
  }

  getChatMessages() {
    return this.chatMessages.slice();
  }
  getToolTraces() {
    return this.toolTraces.slice();
  }

  addEvent(e: RawEvent) {
    if (e.type === 'delta') {
      this.handleDelta(e.streamId, e.delta);
    } else if (e.type === 'done') {
      this.handleDone(e.streamId, e.timestamp ?? this.now());
    } else if (e.type === 'user' || e.type === 'system') {
      const m: ChatMessage = {
        id: `msg-${Math.random().toString(36).slice(2,9)}`,
        author: e.type === 'user' ? 'user' : 'system',
        text: e.text,
        status: 'final',
        timestamp: e.timestamp ?? this.now(),
      };
      this.chatMessages.push(m);
      this.emitChat();
    } else if (e.type === 'tool_call' || e.type === 'tool_result' || e.type === 'tool_error') {
      const t: ToolTrace = {
        id: e.id ?? `tool-${Math.random().toString(36).slice(2,9)}`,
        kind: e.type,
        payload: e.payload,
        timestamp: e.timestamp ?? this.now(),
      };
      this.toolTraces.push(t);
      this.emitTools();
    }
  }

  private handleDelta(streamId: string, delta: string) {
    let buf = this.buffers.get(streamId);
    const now = this.now();
    if (!buf) {
      const messageId = `gen-${streamId}-${Math.random().toString(36).slice(2,8)}`;
      buf = { messageId, text: '', lastFlush: now };
      this.buffers.set(streamId, buf);
      // create an initial streaming message placeholder
      const chatMsg: ChatMessage = {
        id: messageId,
        streamId,
        author: 'assistant',
        text: '',
        status: 'streaming',
        timestamp: now,
      };
      this.chatMessages.push(chatMsg);
      this.emitChat();
    }
    buf.text += delta;
    // if ends with punctuation/newline or large, flush immediately
    const immediate = /[\n.!?]$/.test(delta) || buf.text.length >= this.MAX_BUFFER;
    if (immediate) {
      this.flushBuffer(streamId, false);
    } else {
      // (re)start idle timer
      if (buf.timeout) clearTimeout(buf.timeout);
      buf.timeout = setTimeout(() => {
        this.flushBuffer(streamId, false);
      }, this.FLUSH_IDLE_MS);
    }
  }

  private flushBuffer(streamId: string, finalize: boolean) {
    const buf = this.buffers.get(streamId);
    if (!buf || !buf.text) return;
    const text = buf.text;
    buf.text = '';
    if (buf.timeout) {
      clearTimeout(buf.timeout);
      buf.timeout = undefined;
    }
    // append text to existing message
    this.chatMessages = this.chatMessages.map((m) =>
      m.id === buf.messageId ? { ...m, text: m.text + text, status: finalize ? 'final' : 'streaming' } : m
    );
    this.emitChat();
  }

  private handleDone(streamId: string, ts: number) {
    // flush any remaining buffer and mark as finalized
    const buf = this.buffers.get(streamId);
    if (buf && buf.text) {
      const text = buf.text;
      buf.text = '';
      if (buf.timeout) clearTimeout(buf.timeout);
      this.chatMessages = this.chatMessages.map((m) =>
        m.id === buf.messageId ? { ...m, text: m.text + text, status: 'final', timestamp: ts } : m
      );
    } else {
      // if nothing buffered, still push an empty done marker (optional)
    }
    this.emitChat();
    // cleanup buffer
    this.buffers.delete(streamId);
  }

  // Interrupt a stream (optimistic): flush remaining buffer and mark interrupted
  interrupt(streamId: string) {
    const buf = this.buffers.get(streamId);
    if (buf) {
      // append remaining text and mark interrupted
      const text = buf.text;
      if (buf.timeout) clearTimeout(buf.timeout);
      this.chatMessages = this.chatMessages.map((m) =>
        m.id === buf.messageId ? { ...m, text: m.text + text, status: 'interrupted' } : m
      );
      this.buffers.delete(streamId);
      this.emitChat();
    } else {
      // if no active buffer, find last message with streamId that's streaming and mark
      this.chatMessages = this.chatMessages.map((m) =>
        m.streamId === streamId && m.status === 'streaming' ? { ...m, status: 'interrupted' } : m
      );
      this.emitChat();
    }
  }

  // Insert an assistant message (e.g., from tool output). draft optional.
  insertAssistantMessage(text: string, draft = false) {
    const msg: ChatMessage = {
      id: `insert-${Math.random().toString(36).slice(2,9)}`,
      author: 'assistant',
      text,
      status: draft ? ('streaming' as const) : ('final' as const),
      draft: draft,
      timestamp: this.now(),
    };
    this.chatMessages.push(msg);
    this.emitChat();
    return msg.id;
  }

  // Clear all state (reset session)
  clear() {
    // clear buffers timers
    for (const [, buf] of this.buffers) {
      if (buf.timeout) clearTimeout(buf.timeout);
    }
    this.buffers.clear();
    this.chatMessages = [];
    this.toolTraces = [];
    this.emitChat();
    this.emitTools();
  }

  private emitChat() {
    if (this.cb.onChatUpdate) this.cb.onChatUpdate(this.getChatMessages());
  }
  private emitTools() {
    if (this.cb.onToolUpdate) this.cb.onToolUpdate(this.getToolTraces());
  }
}
