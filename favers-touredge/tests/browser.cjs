// Run after generating dist/touredge-preview.html and serving the repository on port 8000.
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    for (const [name, width] of [['desktop', 1440], ['mobile', 390]]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto('http://127.0.0.1:8000/dist/touredge-preview.html');
      const layout = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth > innerWidth,
        imagesLoaded: [...document.images].every(image => image.complete && image.naturalWidth > 0),
        cart: document.querySelector('.fte-cart')?.getAttribute('href')
      }));
      if (layout.overflow || !layout.imagesLoaded || layout.cart !== '/cart/') throw Error('Layout or asset failure: ' + JSON.stringify(layout));
      await page.screenshot({ path: 'dist/touredge-' + name + '.png', fullPage: true });
      console.log('PASS: ' + name + ' layout, assets and cart link');
    }
    await page.getByLabel('Drivers', { exact: true }).check();
    await page.getByRole('searchbox').fill('Exotics');
    await page.getByLabel('Sort products').selectOption('price-low');
    await page.getByRole('button', { name: 'Apply' }).click();
    await page.waitForURL(/touredge_type=drivers/);
    const params = new URL(page.url()).searchParams;
    if (params.get('touredge_search') !== 'Exotics' || params.get('touredge_sort') !== 'price-low') throw Error('Filter parameters missing');
    console.log('PASS: driver, search and price filter form submits correctly');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
