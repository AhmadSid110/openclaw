import './openclaw_integration';
import { mountOpenClawChat, attachOpenClawWebSocket } from './openclaw_integration';

;(window as any).mountOpenClawChat = mountOpenClawChat;
;(window as any).attachOpenClawWebSocket = attachOpenClawWebSocket;

console.info('Sibyl integration booted: mountOpenClawChat(), attachOpenClawWebSocket(ws)');
