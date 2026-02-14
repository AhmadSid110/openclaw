import React, { useState } from 'react';
import { ToolTrace } from './openclaw_EventEngine';

export const ToolTracePanel: React.FC<{ traces: ToolTrace[]; onInsert?: (text: string) => void }> = ({ traces, onInsert }) => {
  const [open, setOpen] = useState(false);
  return (
    <div style={{ borderTop: '1px solid #e5e7eb', padding: 8 }}>
      <button onClick={() => setOpen(!open)}>Tools ({traces.length})</button>
      {open && (
        <div>
          {traces.map((t) => (
            <div key={t.id} style={{ marginBottom: 8, padding: 8, border: '1px solid #e2e8f0' }}>
              <div style={{ fontSize: 12, fontWeight: 600 }}>{t.kind} — {t.id}</div>
              <pre style={{ maxHeight: 120, overflow: 'auto', background: '#0f172a', color: '#e2e8f0', padding: 8 }}>{typeof t.payload === 'string' ? t.payload : JSON.stringify(t.payload, null, 2)}</pre>
              <div style={{ marginTop: 6 }}>
                <button onClick={() => onInsert && onInsert(typeof t.payload === 'string' ? t.payload : JSON.stringify(t.payload))}>Insert to chat</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
