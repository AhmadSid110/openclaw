const { chromium } = require('playwright-chromium');
const fs = require('fs');
(async () => {
  const url = 'https://openclaw.tailccdbda.ts.net/';
  const out = { logs: [], types: null, mountResult: null, attachResult: null };
  try {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox','--disable-gpu','--disable-dev-shm-usage'], timeout: 60000 });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    page.on('console', msg => out.logs.push({type: msg.type(), text: msg.text()}));
    page.on('pageerror', err => out.logs.push({type: 'pageerror', text: err.message}));
    page.on('requestfailed', req => out.logs.push({type: 'requestfailed', url: req.url(), err: req.failure() ? req.failure().errorText : null}));
    await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 }).catch(e => out.logs.push({type: 'goto-error', text: ''+e}));
    // take screenshot
    await page.screenshot({ path: 'screenshot_before.png', fullPage: true }).catch(()=>{});
    out.types = await page.evaluate(() => ({
      mount: typeof window.mountOpenClawChat,
      attach: typeof window.attachOpenClawWebSocket,
      addEvent: typeof window.__openclaw_addEvent,
      ws: typeof window.__OPENCLAW_WS__
    }));
    if (out.types.mount === 'function') {
      out.mountResult = await page.evaluate(() => {
        try {
          if (!document.getElementById('openclaw-chat-test')) {
            const d = document.createElement('div'); d.id='openclaw-chat-test'; document.body.appendChild(d);
          }
          window.mountOpenClawChat('openclaw-chat-test');
          return {ok:true};
        } catch (e) { return {ok:false, error: ''+e}; }
      });
    }
    if (out.types.attach === 'function') {
      out.attachResult = await page.evaluate(() => {
        try {
          const stub = { send: () => {}, addEventListener: () => {}, close: () => {}, readyState: 1 };
          window.attachOpenClawWebSocket(stub);
          return {ok:true};
        } catch (e) { return {ok:false, error: ''+e}; }
      });
    }
    // screenshot after mount
    await page.screenshot({ path: 'screenshot_after.png', fullPage: true }).catch(()=>{});
    fs.writeFileSync('check2_result.json', JSON.stringify(out, null, 2));
    console.log('WROTE check2_result.json and screenshots');
    await browser.close();
  } catch (err) {
    console.error('FATAL', String(err));
    process.exit(3);
  }
})();
