const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] });
  try {
    for (const scenario of ['complete', 'failure', 'stop']) {
      const page = await browser.newPage();
      let requests = 0;
      await page.route('http://127.0.0.1:8000/admin-ajax.php', async route => {
        requests++;
        if (scenario === 'stop') await new Promise(resolve => setTimeout(resolve, 250));
        const data = { updated: scenario === 'failure' ? 0 : 1, remaining: requests === 1 ? 1 : 0, errors: scenario === 'failure' ? ['Photo download failed'] : [] };
        await route.fulfill({ json: { success: true, data } });
      });
      await page.setContent('<button id="fc-add-photos">Add missing product photos</button><button id="fc-stop-photos" disabled>Stop</button><p id="fc-photo-status"></p><script>window.faversCobraPhotos={url:"http://127.0.0.1:8000/admin-ajax.php",nonce:"test"};</script>');
      await page.addScriptTag({ path: path.resolve('favers-cobra/assets/photos.js') });
      await page.locator('#fc-add-photos').click();
      if (scenario === 'stop') await page.locator('#fc-stop-photos').click();
      await page.waitForFunction(() => !document.getElementById('fc-add-photos').disabled);
      const message = await page.locator('#fc-photo-status').innerText();
      if (scenario === 'complete' && (!message.includes('Finished. 2') || requests !== 2)) throw Error(message);
      if (scenario === 'failure' && (!message.includes('Photo download failed') || requests !== 1)) throw Error(message);
      if (scenario === 'stop' && (!message.includes('Click Add missing product photos to resume') || requests !== 1)) throw Error(message);
      console.log('PASS: photo batch UI ' + scenario);
      await page.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
