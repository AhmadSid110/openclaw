// src/ui/boot.ts
import './integration';

// expose helpers globally
import { mountOpenClawChat, attachOpenClawWebSocket } from './integration';

;(window as any).mountOpenClawChat = mountOpenClawChat;
;(window as any).attachOpenClawWebSocket = attachOpenClawWebSocket;

console.info('OpenClaw UI integration booted: mountOpenClawChat(), attachOpenClawWebSocket(ws) available on window');
