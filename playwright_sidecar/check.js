const { chromium } = require('playwright-chromium');
(async () => {
  const url = 'https://openclaw.tailccdbda.ts.net/';
  const result = { error: null };
  const logs = [];
  try {
    const browser = await chromium.launch({
      headless: true,
      args: [
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--no-sandbox',
        '--disable-background-timer-throttling',
        '--disable-renderer-backgrounding',
        '--disable-features=site-per-process'
      ],
      timeout: 60000
    });
    const context = await browser.newContext({
      ignoreHTTPSErrors: true
    });
    const page = await context.newPage();

    page.on('console', msg => logs.push({type: msg.type(), text: msg.text()}));
    page.on('pageerror', err => logs.push({type: 'pageerror', text: err.message}));
    page.on('requestfailed', req => logs.push({type: 'requestfailed', url: req.url(), err: req.failure() ? req.failure().errorText : null}));

    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
    } catch (e) {
      logs.push({type: 'goto-error', text: String(e)});
    }

    const types = await page.evaluate(() => ({
      mount: typeof window.mountOpenClawChat,
      attach: typeof window.attachOpenClawWebSocket,
      addEvent: typeof window.__openclaw_addEvent,
      ws: typeof window.__OPENCLAW_WS__
    }));

    let mountResult = null;
    if (types.mount === 'function') {
      try {
        mountResult = await page.evaluate(() => {
          try {
            if (!document.getElementById('openclaw-chat-test')) {
              const d = document.createElement('div');
              d.id = 'openclaw-chat-test';
              d.style.width = '200px'; d.style.height = '200px';
              document.body.appendChild(d);
            }
            try {
              window.mountOpenClawChat('openclaw-chat-test');
              return {ok: true};
            } catch (e) {
              return {ok: false, error: '' + e};
            }
          } catch (e) {
            return {ok: false, error: 'eval:' + e};
          }
        });
      } catch (e) {
        mountResult = {ok: false, error: '' + e};
      }
    }

    let attachResult = null;
    if (types.attach === 'function') {
      try {
        attachResult = await page.evaluate(() => {
          try {
            const stub = {
              send: function() {},
              addEventListener: function() {},
              close: function() {},
              readyState: 1
            };
            try {
              window.attachOpenClawWebSocket(stub);
              return {ok: true};
            } catch (e) {
              return {ok: false, error: '' + e};
            }
          } catch (e) {
            return {ok: false, error: 'eval:' + e};
          }
        });
      } catch (e) {
        attachResult = {ok: false, error: '' + e};
      }
    }

    result.types = types;
    result.mountResult = mountResult;
    result.attachResult = attachResult;
    result.logs = logs;

    console.log(JSON.stringify(result, null, 2));
    await browser.close();
  } catch (err) {
    console.error('FATAL', String(err));
    process.exit(3);
  }
})();
