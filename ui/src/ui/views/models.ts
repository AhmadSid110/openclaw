import { html } from "lit";
import type { AppViewState } from "../app-view-state.ts";

export function renderModels(state: AppViewState) {
  const models = state.debugModels ?? [];
  return html`
    <div class="card">
      <div class="card-title">Model Catalog</div>
      <div class="card-sub">Catalog from models.list.</div>
      <div style="padding:8px; display:flex; gap:8px; align-items:center;">
        <button @click=${() => { (state as any).loadModels && (state as any).loadModels(); }} class="btn">Refresh</button>
        <div style="color:var(--muted); font-size:12px;">${state.debugLoading ? 'Loading…' : ''}</div>
      </div>
      <div style="max-height:420px; overflow:auto; padding:8px;">
        ${state.debugCallError ? html`<div style="padding:8px; background:var(--bg-contrast); border:1px solid var(--border); color:var(--danger); font-size:13px; margin-bottom:8px;">Error: ${state.debugCallError}</div>` : ''}
        ${models.length === 0
          ? html`<div class="muted">No models (refresh to probe gateway catalog).</div>`
          : models.map((m: any) => html`
              <div class="card-row" style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px; border-bottom:1px solid var(--border);">
                <div style="flex:1">
                  <div style="font-weight:600">${m.provider}/${m.model}</div>
                  <div style="font-size:12px; color:var(--muted)">${m.name ?? ''} ${m.reasoning ? html`<span class="chip">reasoning</span>` : ''}</div>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                  <button @click=${() => onSetDefault(state, m)} class="btn">Set as default</button>
                </div>
              </div>
            `)}
      </div>
    </div>
  `;
}

async function onSetDefault(state: AppViewState, model: any) {
  try {
    const key = `${model.provider}/${model.model}`;
    // Call into the app method if present
    if ((state as any).setDefaultModel) {
      await (state as any).setDefaultModel(key);
      alert(`Default model set to ${key}`);
    } else if ((state as any).client) {
      // Fallback: attempt to patch config directly (best-effort)
      try {
        const res = await (state as any).client.request("config.get", {});
        const cfg = res.config ?? {};
        if (!cfg.agents) cfg.agents = {};
        if (!cfg.agents.defaults) cfg.agents.defaults = {};
        cfg.agents.defaults.model = { primary: key };
        const raw = JSON.stringify(cfg, null, 2);
        const baseHash = res.hash;
        await (state as any).client.request("config.set", { raw, baseHash });
        alert(`Default model set to ${key} (config.set)`);
      } catch (err) {
        alert(`Failed to set default model: ${String(err)}`);
      }
    } else {
      alert('Unable to set default model: no client available');
    }
  } catch (err) {
    alert(`Error: ${String(err)}`);
  }
}
