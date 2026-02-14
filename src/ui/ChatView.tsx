// src/ui/ChatView.tsx
import React, { useState } from 'react';
import { ChatMessage } from './EventEngine';

type Props = {
  messages: ChatMessage[];
  onStop?: (streamId: string) => void;
  onEdit?: (id: string, text: string) => void;
};

export const ChatView: React.FC<Props> = ({ messages, onStop, onEdit }) => {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {messages.map((m) => (
        <ChatBubble key={m.id} m={m} onStop={onStop} onEdit={onEdit} />
      ))}
    </div>
  );
};

const ChatBubble: React.FC<{ m: ChatMessage; onStop?: (streamId: string) => void; onEdit?: (id: string, text: string) => void }> = ({ m, onStop, onEdit }) => {
  const [editText, setEditText] = useState(m.text);
  const isDraft = !!m.draft;
  return (
    <div style={{ alignSelf: m.author === 'assistant' ? 'flex-start' : 'flex-end', maxWidth: '80%' }}>
      <div style={{
        background: m.author === 'assistant' ? '#f1f5f9' : '#60a5fa',
        color: m.author === 'assistant' ? '#111827' : '#fff',
        padding: '8px 12px',
        borderRadius: 8,
        whiteSpace: 'pre-wrap',
      }}>
        <div style={{ fontSize: 13, opacity: 0.7, marginBottom: 4 }}>
          {m.author} {m.status === 'streaming' && '· typing…'} {m.status === 'interrupted' && '· interrupted'}
        </div>
        {isDraft && onEdit ? (
          <div>
            <textarea value={editText} onChange={(e) => setEditText(e.target.value)} style={{ width: '100%', minHeight: 80 }} />
            <div style={{ marginTop: 6 }}>
              <button onClick={() => onEdit && onEdit(m.id, editText)}>Save</button>
            </div>
          </div>
        ) : (
          <div>{m.text}</div>
        )}
        {m.status === 'streaming' && m.streamId && onStop && (
          <div style={{ marginTop: 6 }}>
            <button onClick={() => onStop(m.streamId)} style={{ fontSize: 12 }}>Stop</button>
          </div>
        )}
      </div>
    </div>
  );
};
