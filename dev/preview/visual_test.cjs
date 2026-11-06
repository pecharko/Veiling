/* Local preview only: real PHP pages, synthetic catalog/admin and responsive screenshots. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const base = 'http://localhost:8080';
const output = path.join(__dirname, '.screenshots');
(async () => {
  const browserPath = process.env.PREVIEW_BROWSER_EXECUTABLE;
  const browser = await chromium.launch({
    headless: true,
    ...(browserPath ? { executablePath: browserPath } : {}),
  });
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
    assert.equal(await page.locator('form.toolbar').count(), 0, 'No public lot filters');
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
    // Open the real public website through the administrator's preview action.
    const [preview] = await Promise.all([
      context.waitForEvent('page'),
      page.locator('button:has-text("Bekijk website")').click(),
    ]);
    await preview.waitForLoadState('domcontentloaded');
    assert.match(await preview.textContent('body'), /ADMINVOORBEELD/);
    assert.equal(await preview.locator('.catalog-preview-tabs').count(), 0);
    assert.equal(await preview.locator('.lot-estimate').count(), 0, 'Unsplit catalog shows no prices');
    await Promise.all([
      preview.waitForLoadState('domcontentloaded'),
      preview.locator('button[name="admin_preview_action"][value="split_on"]').click(),
    ]);
    assert.equal(await preview.locator('.catalog-preview-tabs').count(), 1);
    assert.equal(await preview.locator('form.toolbar').count(), 0, 'Split view has no lot filters');
    assert.match(await preview.locator('.catalog-preview-tabs').textContent(), /Veiling.*Marktplaats/s);
    assert.match(await preview.locator('.lot-estimate').first().textContent(), /Startbod/);
    await preview.locator('.catalog-preview-tabs a[href*="deel=markt"]').click();
    assert.match(await preview.locator('.lot-estimate').first().textContent(), /Marktprijs/);
    await preview.locator('button[name="admin_preview_action"][value="split_off"]').click();
    assert.equal(await preview.locator('.catalog-preview-tabs').count(), 0);
    assert.equal(await preview.locator('.lot-estimate').count(), 0);
    await preview.close();
    assert.deepEqual(errors, [], 'No browser JavaScript errors');
    await context.close();
    console.log('PASS local catalog and admin desktop/mobile screenshots: ' + output);
  } finally { await browser.close(); }
})().catch(e => { console.error(e.stack || e); process.exitCode = 1; });
