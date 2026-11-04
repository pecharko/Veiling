/* Local preview only: real PHP pages, synthetic catalog/admin and responsive screenshots. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const base = 'http://localhost:8080';
const output = path.join(__dirname, '.screenshots');
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    fs.mkdirSync(output, { recursive: true });
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const home = await page.goto(base + '/index.php', { waitUntil: 'networkidle' });
    assert.equal(home.status(), 200);
    assert.equal(new URL(page.url()).hostname, 'localhost');
    assert.match(await page.textContent('body'), /Abstract landschap/);
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.screenshot({ path: path.join(output, `catalogue-${width}.png`), fullPage: true, animations: 'disabled' });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `No public overflow ${width}`);
    }
    await page.goto(base + '/admin/login.php');
    await page.locator('[name=email]').fill('ci-admin@example.invalid');
    await page.locator('[name=password]').fill('ci-password-not-a-real-secret');
    await Promise.all([page.waitForURL('**/admin/index.php'), page.locator('button[type=submit]').click()]);
    assert.match(await page.textContent('body'), /Kaveloverzicht/);
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.screenshot({ path: path.join(output, `admin-${width}.png`), fullPage: true, animations: 'disabled' });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `No admin overflow ${width}`);
    }
    assert.deepEqual(errors, [], 'No browser JavaScript errors');
    await context.close();
    console.log('PASS local catalog and admin desktop/mobile screenshots: ' + output);
  } finally { await browser.close(); }
})().catch(e => { console.error(e.stack || e); process.exitCode = 1; });
