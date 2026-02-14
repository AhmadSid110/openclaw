import React, { useEffect, useState } from 'react';
import presets from './openclaw_presets.json';

export const PresetBadge: React.FC<{ onChange?: (presetId: string) => void }> = ({ onChange }) => {
  const [open, setOpen] = useState(false);
  const [current, setCurrent] = useState(() => {
    try {
      return localStorage.getItem('openclaw_preset') || 'default';
    } catch (e) { return 'default'; }
  });

  useEffect(() => {
    if (onChange) onChange(current);
  }, [current]);

  const p = presets.find((x: any) => x.id === current) || presets[0];

  return (
    <div style={{ display: 'inline-block' }}>
      <button onClick={() => setOpen(!open)} style={{ padding: '6px 8px', borderRadius: 6 }}>{p.name} ▾</button>
      {open && (
        <div style={{ position: 'absolute', background: '#fff', border: '1px solid #e5e7eb', padding: 8, marginTop: 6 }}>
          {presets.map((pr: any) => (
            <div key={pr.id} style={{ marginBottom: 6 }}>
              <div style={{ fontWeight: 700 }}>{pr.name}</div>
              <div style={{ fontSize: 12, opacity: 0.8 }}>{pr.model}</div>
              <div style={{ marginTop: 6 }}>
                <button onClick={() => { setCurrent(pr.id); try { localStorage.setItem('openclaw_preset', pr.id); } catch (e) {} setOpen(false); }}>Select</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
