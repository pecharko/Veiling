/* Actual image decoding, keyboard interaction, mobile layout and no-JS fallback. */
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const fs = require('node:fs');
(async () => {
  assert.equal(process.env.GITHUB_ACTIONS, 'true', 'Disposable CI fixture only');
  const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 400) errors.push(`${response.status()} ${response.url()}`); });
    await page.goto('http://localhost:8090/index.php');
    await page.locator('.front-hero-actions').getByRole('link', { name: 'Vorige editie', exact: true }).click();
    await page.waitForURL('**/vorige-editie.php');
    assert.equal((await context.cookies()).some(cookie => cookie.name === 'catalog_admin'), false, 'No anonymous admin session');
    const photos = page.locator('.impression-photo-image img');
    assert.equal(await photos.count(), 9);
    assert.equal(await page.locator('figcaption, .impression-enlarge, [data-impression-photo], dialog').count(), 0);
    await photos.evaluateAll(images => images.forEach(image => { image.loading = 'eager'; }));
    await page.waitForFunction(() => [...document.querySelectorAll('.impression-photo-image img')].every(image => image.complete && image.naturalWidth > 0));
    await photos.evaluateAll(images => Promise.all(images.map(image => image.decode())));
    const beforeClick = page.url();
    await photos.first().click();
    assert.equal(page.url(), beforeClick, 'Clicking image does not navigate');
    assert.equal(await page.locator('dialog[open]').count(), 0, 'Clicking image does not open a modal');
    fs.mkdirSync('/tmp/previous-edition-preview', { recursive: true });
    for (const width of [1280, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 900 });
      await page.evaluate(() => {
        document.activeElement?.blur();
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
      });
      await page.waitForFunction(() => window.scrollY === 0);
      await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all([...document.querySelectorAll('.impression-photo-image img')].map(image => image.decode()));
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `No horizontal overflow at ${width}px`);
      for (const section of ['.impression-top-actions', '.impression-next-actions']) {
        const link = page.locator(section).getByRole('link', { name: '← Terug naar startpagina', exact: true });
        assert.equal(await link.isVisible(), true, `Homepage return link at ${width}px`);
        assert.equal(await link.getAttribute('href'), '/index.php');
      }
      const desktopNav = page.locator('.site-header .header-nav');
      const mobileMenu = page.locator('.public-mobile-menu');
      const summary = mobileMenu.locator('summary');
      assert.equal(await desktopNav.isVisible(), width > 767, `Desktop navigation at ${width}px`);
      assert.equal(await summary.isVisible(), width <= 767, `Mobile menu at ${width}px`);
      if (width > 767) {
        assert.equal(await desktopNav.getByRole('link', { name: 'Home', exact: true }).isVisible(), true, 'Desktop header retains Home');
      } else {
        const artists = mobileMenu.getByRole('link', { name: 'Kunstenaars', exact: true });
        assert.equal(await artists.isVisible(), false, 'Mobile menu starts closed');
        await summary.focus();
        await page.keyboard.press('Enter');
        assert.equal(await artists.isVisible(), true, 'Keyboard opens mobile menu');
        assert.equal(await artists.getAttribute('href'), '/kunstenaars.php');
        await page.keyboard.press('Enter');
        assert.equal(await artists.isVisible(), false, 'Keyboard closes mobile menu');
      }
      if ([1280,390].includes(width)) await page.screenshot({ path: `/tmp/previous-edition-preview/${width}.png`, fullPage: true, animations: 'disabled' });
    }
    assert.deepEqual(errors, [], 'No browser errors or failed resources');
    await context.close();
    const fallback = await browser.newContext({ javaScriptEnabled: false });
    const plain = await fallback.newPage();
    await plain.goto('http://localhost:8090/vorige-editie.php');
    const plainUrl = plain.url();
    await plain.locator('.impression-photo-image img').first().click();
    assert.equal(plain.url(), plainUrl, 'No-JavaScript image click does not navigate');
    await fallback.close();
    process.stdout.write('PASS nine inert impression images, four viewport sizes and no-JS inert fallback\n');
  } finally { await browser.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
