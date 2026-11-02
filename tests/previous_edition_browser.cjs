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
    await page.getByRole('link', { name: 'Bekijk de vorige editie' }).click();
    await page.waitForURL('**/vorige-editie.php');
    assert.equal((await context.cookies()).some(cookie => cookie.name === 'catalog_admin'), false, 'No anonymous admin session');
    const photos = page.locator('[data-impression-photo]');
    assert.equal(await photos.count(), 9);
    await page.locator('[data-impression-photo] img').evaluateAll(images => images.forEach(image => { image.loading = 'eager'; }));
    await page.waitForFunction(() => [...document.querySelectorAll('[data-impression-photo] img')].every(image => image.complete && image.naturalWidth > 0));
    await photos.first().click();
    await page.locator('dialog[open]').waitFor();
    assert.equal(await page.locator('#viewer-count').textContent(), '1 / 9');
    assert.equal(await page.locator('#viewer-previous').isDisabled(), true);
    await page.keyboard.press('ArrowRight');
    assert.equal(await page.locator('#viewer-count').textContent(), '2 / 9');
    assert.equal(await page.locator('#viewer-caption').textContent(), 'Bijzondere stukken ontdekken');
    await page.getByRole('button', { name: 'Volgende foto' }).click();
    assert.equal(await page.locator('#viewer-count').textContent(), '3 / 9');
    await page.keyboard.press('Escape');
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await photos.first().evaluate(link => document.activeElement === link), true, 'Focus restored');
    await photos.last().click();
    assert.equal(await page.locator('#viewer-next').isDisabled(), true);
    await page.getByRole('button', { name: 'Fotoweergave sluiten' }).click();
    fs.mkdirSync('/tmp/previous-edition-preview', { recursive: true });
    for (const width of [1280, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 900 });
      await page.evaluate(() => {
        document.activeElement?.blur();
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
      });
      await page.waitForFunction(() => window.scrollY === 0);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `No horizontal overflow at ${width}px`);
      assert.equal(await page.getByRole('link', { name: 'Home', exact: true }).isVisible(), true, 'Mobile return navigation');
      if ([1280,390].includes(width)) await page.screenshot({ path: `/tmp/previous-edition-preview/${width}.png`, fullPage: true, animations: 'disabled' });
    }
    assert.deepEqual(errors, [], 'No browser errors or failed resources');
    await context.close();
    const fallback = await browser.newContext({ javaScriptEnabled: false });
    const plain = await fallback.newPage();
    await plain.goto('http://localhost:8090/vorige-editie.php');
    await plain.locator('[data-impression-photo]').first().click();
    await plain.waitForURL('**/vorige-editie-zaal-1600.webp');
    assert.equal(plain.url().endsWith('/vorige-editie-zaal-1600.webp'), true, 'Photos enlarge without JavaScript');
    await fallback.close();
    process.stdout.write('PASS impression images, keyboard viewer, four viewport sizes and no-JS fallback\n');
  } finally { await browser.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
