import { LitElement, html, css, nothing } from "lit";
import { customElement, property, state, query } from "lit/decorators.js";
import type { ChatAttachment } from "../ui-types.js";

@customElement("chat-input")
export class ChatInput extends LitElement {
  @property() draft = "";
  @property() status: "idle" | "streaming" | "loading" = "idle";
  @property({ type: Boolean }) disabled = false;
  @property({ attribute: false }) attachments: ChatAttachment[] = [];

  @property({ attribute: false }) onSend?: (text: string) => void;
  @property({ attribute: false }) onNewSession?: () => void;
  @property({ attribute: false }) onDraftChange?: (text: string) => void;
  @property({ attribute: false }) onAttachmentsChange?: (attachments: ChatAttachment[]) => void;

  @state() private menuOpen = false;
  @state() private confirmNewSession = false;
  @query("textarea") private textarea!: HTMLTextAreaElement;

  static styles = css`
    :host {
      display: block;
      width: 100%;
    }

    * {
      box-sizing: border-box;
    }

    .container {
      width: 100%;
      display: grid;
      grid-template-columns: auto 1fr auto;
      gap: 12px;
      align-items: flex-end;
      padding: 0;
    }

    .action-launcher {
      position: relative;
      flex-shrink: 0;
    }

    .menu-popover {
      position: absolute;
      bottom: 52px;
      left: 0;
      width: 260px;
      background: var(--popover);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-xl);
      overflow: hidden;
      z-index: 100;
      animation: fade-in-up 0.15s var(--ease-out);
      transform-origin: bottom left;
    }

    @keyframes fade-in-up {
      from {
        opacity: 0;
        transform: translateY(4px) scale(0.98);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .menu-item {
      display: flex;
      align-items: center;
      gap: 12px;
      width: 100%;
      padding: 12px 16px;
      background: none;
      border: none;
      text-align: left;
      cursor: pointer;
      font-size: 14px;
      color: var(--text);
      transition: background 0.1s;
      font-family: inherit;
    }

    .menu-item:hover {
      background: var(--bg-hover);
    }

    .menu-item:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .menu-divider {
      height: 1px;
      background: var(--border);
      margin: 4px 0;
    }

    .menu-confirm {
      padding: 18px;
    }

    .menu-confirm p {
      margin: 0 0 8px 0;
      font-size: 14px;
      color: var(--text);
      font-weight: 600;
    }

    .menu-confirm-detail {
      font-size: 13px !important;
      margin-bottom: 16px !important;
      color: var(--muted) !important;
      font-weight: normal !important;
      line-height: 1.4;
    }

    .menu-confirm-actions {
      display: flex;
      gap: 8px;
    }

    .btn-confirm {
      flex: 1;
      padding: 8px 12px;
      border-radius: var(--radius-md);
      border: none;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.1s;
      font-family: inherit;
    }

    .btn-confirm.start {
      background: var(--danger);
      color: var(--danger-foreground, #fff);
    }
    .btn-confirm.start:hover {
      background: var(--danger-muted);
    }

    .btn-confirm.cancel {
      background: var(--bg-hover);
      color: var(--text);
      border: 1px solid var(--border);
    }
    .btn-confirm.cancel:hover {
      background: var(--border);
    }

    .plus-btn {
      width: 42px;
      height: 42px;
      border-radius: var(--radius-lg);
      border: 1px solid var(--border);
      background: var(--bg-elevated);
      color: var(--muted);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.15s;
    }

    .plus-btn:hover {
      background: var(--bg-hover);
      color: var(--text);
      border-color: var(--border-hover);
      box-shadow: var(--shadow-sm);
    }

    .plus-btn.active {
      background: var(--accent);
      color: var(--accent-foreground);
      border-color: var(--accent);
      box-shadow: 0 0 0 1px var(--accent-subtle);
    }

    .input-wrapper {
      flex: 1;
      min-width: 0;
      background: var(--panel-strong);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 22px;
      padding: 12px 16px;
      transition:
        border 0.2s,
        box-shadow 0.2s;
      display: flex;
      flex-direction: column;
      gap: 10px;
      box-shadow: inset 0 0 0 1px transparent;
    }

    .input-wrapper:focus-within {
      border-color: rgba(255, 255, 255, 0.2);
      box-shadow: 0 0 0 1px var(--accent-subtle);
    }

    .attachments {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      padding: 4px 0 0;
    }

    .attachment-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 9999px;
      padding: 4px 10px;
      font-size: 12px;
      font-weight: 500;
      color: var(--text);
      animation: zoom-in 0.2s;
    }

    .attachment-chip button {
      background: none;
      border: none;
      padding: 2px;
      cursor: pointer;
      color: var(--muted);
      display: flex;
      border-radius: 50%;
      transition:
        color 0.2s,
        background 0.2s;
    }
    .attachment-chip button:hover {
      color: var(--destructive);
      background: var(--bg-hover);
    }

    textarea {
      width: 100%;
      background: transparent;
      border: none;
      resize: none;
      outline: none;
      font-family: var(--font-body);
      font-size: 16px;
      line-height: 1.6;
      color: var(--text);
      padding: 0;
      margin: 0;
      min-height: 80px;
      max-height: 280px;
      overflow-y: auto;
      white-space: pre-wrap;
      word-break: break-word;
    }

    textarea::placeholder {
      color: var(--muted);
      opacity: 0.8;
    }

    .send-btn {
      width: 48px;
      height: 48px;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--accent), var(--accent-hover));
      color: var(--accent-foreground);
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition:
        transform 0.15s,
        box-shadow 0.15s;
      flex-shrink: 0;
      box-shadow: var(--shadow-glow);
    }

    .send-btn:hover {
      transform: translateY(-1px) scale(1.02);
      box-shadow: 0 0 20px rgba(255, 92, 92, 0.4);
    }

    .send-btn:active {
      transform: translateY(0) scale(1);
      box-shadow: 0 0 12px rgba(255, 92, 92, 0.35);
    }

    .send-btn:disabled {
      background: var(--bg-muted);
      color: var(--muted);
      cursor: not-allowed;
      box-shadow: none;
      transform: none;
    }
  `;
  private handleKeyDown(e: KeyboardEvent) {
    const shouldSend = e.key === "Enter" && (e.ctrlKey || e.metaKey);
    if (shouldSend) {
      e.preventDefault();
      this.submit();
    }
  }

  private handleInput(e: Event) {
    const target = e.target as HTMLTextAreaElement;
    this.adjustHeight(target);
    this.onDraftChange?.(target.value);
  }

  private adjustHeight(el: HTMLTextAreaElement) {
    el.style.height = "auto";
    const nextHeight = Math.min(el.scrollHeight, 280);
    el.style.height = `${nextHeight}px`;
  }

  private submit() {
    if (this.isSendDisabled()) {
      return;
    }
    this.onSend?.(this.draft);
  }

  private isSendDisabled() {
    return (
      this.disabled ||
      this.status === "streaming" ||
      (this.draft.trim().length === 0 && this.attachments.length === 0)
    );
  }

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener("click", this.handleWindowClick);
    if (this.draft) {
      requestAnimationFrame(() => {
        if (this.textarea) {
          this.adjustHeight(this.textarea);
        }
      });
    }
  }

  disconnectedCallback() {
    window.removeEventListener("click", this.handleWindowClick);
    super.disconnectedCallback();
  }

  private handleWindowClick = (e: MouseEvent) => {
    if (!this.menuOpen) {
      return;
    }
    const path = e.composedPath();
    if (!path.includes(this)) {
      this.menuOpen = false;
      this.confirmNewSession = false;
    }
  };

  private addAttachment(type: "image" | "video" | "file") {
    // Mock implementation
    const id = Math.random().toString(36).substr(2, 9);
    console.log("Add attachment clicked:", type);
    this.menuOpen = false;
  }

  render() {
    return html`
      <div class="container">
        
        <!-- Action Launcher -->
        <div class="action-launcher">
          ${
            this.menuOpen
              ? html`
            <div class="menu-popover">
              ${
                !this.confirmNewSession
                  ? html`
                <button class="menu-item" @click=${() => this.addAttachment("image")}>
                  <span style="font-size: 18px">🖼️</span> Add image
                </button>
                <button class="menu-item" @click=${() => this.addAttachment("video")}>
                  <span style="font-size: 18px">🎥</span> Add video
                </button>
                <button class="menu-item" disabled>
                  <span style="font-size: 18px">📄</span> Attach file (soon)
                </button>
                <div class="menu-divider"></div>
                <button class="menu-item" style="color: var(--danger); font-weight: 500;" @click=${(
                  e: Event,
                ) => {
                  e.stopPropagation();
                  this.confirmNewSession = true;
                }}>
                  <span style="font-size: 18px">🆕</span> New session
                </button>
              `
                  : html`
                <div class="menu-confirm">
                  <p>Start a new session?</p>
                  <p class="menu-confirm-detail">Current conversation will be cleared.</p>
                  <div class="menu-confirm-actions">
                    <button class="btn-confirm start" @click=${() => {
                      this.onNewSession?.();
                      this.menuOpen = false;
                      this.confirmNewSession = false;
                    }}>Start New</button>
                    <button class="btn-confirm cancel" @click=${(e: Event) => {
                      e.stopPropagation();
                      this.confirmNewSession = false;
                    }}>Cancel</button>
                  </div>
                </div>
              `
              }
            </div>
          `
              : nothing
          }
          
          <button 
            class="plus-btn ${this.menuOpen ? "active" : ""}" 
            @click=${(e: Event) => {
              e.stopPropagation();
              this.menuOpen = !this.menuOpen;
              this.confirmNewSession = false;
            }}
            aria-label="More actions"
            title="More actions"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
          </button>
        </div>

        <!-- Input Area -->
        <div class="input-wrapper">
          ${
            this.attachments.length > 0
              ? html`
            <div class="attachments">
              ${this.attachments.map(
                (att) => html`
                <div class="attachment-chip">
                  <span style="font-size: 14px">🖼️</span>
                  <span>${att.id ? "Image" : "Attachment"}</span>
                  <button @click=${() => {
                    const next = this.attachments.filter((a) => a.id !== att.id);
                    this.onAttachmentsChange?.(next);
                  }}>✕</button>
                </div>
              `,
              )}
            </div>
          `
              : nothing
          }
          
          <textarea
            .value=${this.draft}
            @input=${this.handleInput}
            @keydown=${this.handleKeyDown}
            placeholder="Message the agent..."
            ?disabled=${this.disabled || this.status === "streaming"}
            rows="1"
            aria-label="Message input"
          ></textarea>
        </div>

        <!-- Send Button -->
        <button 
          class="send-btn"
          @click=${this.submit}
          ?disabled=${this.isSendDisabled()}
          aria-label="Send message"
          title="Send message"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
          </svg>
        </button>

      </div>
    `;
  }
}
