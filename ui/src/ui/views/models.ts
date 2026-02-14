import { html } from "lit";
import type { AppViewState } from "../app-view-state.ts";

export function renderModels(state: AppViewState) {
  const models = state.models ?? [];
  const active = state.activeModelId ?? null;

  return html`
    <div class="card">
      <div class="card-title">Models</div>
      <div class="card-sub">Authoritative model catalog from the gateway.</div>
      <div style="padding:8px; display:flex; gap:8px; align-items:center;">
        <button @click=${() => loadModels(state)} class="btn">Refresh</button>
        <div style="color:var(--muted); font-size:12px;">${state.loading ? 'Loading…' : ''}</div>
      </div>

      ${state.callError ? html`<div style="padding:8px; background:var(--bg-contrast); border:1px solid var(--border); color:var(--danger); font-size:13px; margin:8px;">${state.callError}</div>` : ''}

      <div style="max-height:420px; overflow:auto; padding:8px;">
        <div style="margin-bottom:8px;">
          <div style="font-weight:600; margin-bottom:6px">Active</div>
          ${active
            ? html`<div style="padding:8px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;"><div>${renderModelLabel(models, active)}</div><div style="font-size:12px; color:var(--muted)">Active</div></div>`
            : html`<div class="muted">No active model configured.</div>`}
        </div>

        <div style="margin-bottom:8px;">
          <div style="font-weight:600; margin-bottom:6px">Available</div>
          ${models.filter((m: any) => m.enabled && (m.id !== active)).length === 0
            ? html`<div class="muted">No available models.</div>`
            : models.filter((m: any) => m.enabled && (m.id !== active)).map((m: any) => renderModelRow(state, m))}
        </div>

        <div>
          <div style="font-weight:600; margin-bottom:6px">Unavailable</div>
          ${models.filter((m: any) => !m.enabled).length === 0
            ? html`<div class="muted">None.</div>`
            : models.filter((m: any) => !m.enabled).map((m: any) => html`<div style="padding:8px; border-bottom:1px solid var(--border); color:var(--muted);">${m.label}</div>`)}
        </div>
      </div>
    </div>
  `;
}

function renderModelLabel(models: any[], id: string) {
  const m = models.find((x) => x.id === id);
  return m ? html`${m.label}` : html`${id}`;
}

function renderModelRow(state: AppViewState, m: any) {
  return html`<div style="padding:8px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
    <div>
      <div style="font-weight:600">${m.label}</div>
      <div style="font-size:12px; color:var(--muted)">${m.provider} ${m.context ? html`· context ${m.context}` : ''}</div>
    </div>
    <div>
      <button class="btn" ?disabled=${state.streaming || state.activating === m.id} @click=${() => onActivate(state, m)}>Activate</button>
    </div>
  </div>`;
}

async function loadModels(state: AppViewState) {
  try {
    state.loading = true;
    state.callError = undefined;
    const res = await fetch('/api/models', { method: 'GET', credentials: 'same-origin' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const body = await res.json();
    if (!body.ok) throw new Error(body.error || 'bad response');
    state.models = body.models || [];
    state.activeModelId = body.activeModelId || null;
  } catch (err) {
    state.callError = String(err);
  } finally {
    state.loading = false;
    // request a UI update if the app provides it
    if ((state as any)._requestUpdate) (state as any)._requestUpdate();
  }
}

async function onActivate(state: AppViewState, model: any) {
  const confirm = window.confirm(`Activate ${model.label}? This will become the default model for new runs.`);
  if (!confirm) return;
  try {
    state.activating = model.id;
    state.callError = undefined;
    if ((state as any)._requestUpdate) (state as any)._requestUpdate();
    const res = await fetch('/api/agent/model', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ modelId: model.id }),
    });
    const body = await res.json();
    if (!res.ok || !body.ok) {
      throw new Error(body.error || `HTTP ${res.status}`);
    }
    // refresh models
    await loadModels(state);
    // small success hint
    if ((state as any).notify) (state as any).notify(`Activated ${model.label}`);
  } catch (err) {
    state.callError = String(err);
  } finally {
    state.activating = undefined;
    if ((state as any)._requestUpdate) (state as any)._requestUpdate();
  }
}

// Auto-load when module is used in the app if state exposes a hook
// The app should call loadModels on tab open; we provide a convenience
// to wire it if present.
export function attachModelsLoader(state: AppViewState) {
  (state as any).loadModels = () => loadModels(state);
}
