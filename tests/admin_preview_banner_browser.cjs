/* Isolated fixture only: actual login, menu popup, responsive sticky layout. */
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
(async () => {
  assert.equal(process.env.GITHUB_ACTIONS, 'true', 'Only the disposable CI fixture');
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext();
    const admin = await context.newPage();
    await admin.goto('http://localhost:8090/admin/login.php');
    await admin.locator('[name=email]').fill('ci-admin@example.invalid');
    await admin.locator('[name=password]').fill('ci-password-not-a-real-secret');
    await Promise.all([admin.waitForURL('**/admin/index.php'), admin.locator('button[type=submit]').click()]);
    const popup = context.waitForEvent('page');
    await admin.getByRole('button', { name: 'Bekijk website' }).click();
    const preview = await popup;
    await preview.waitForLoadState();
    const banner = preview.getByRole('status', { name: 'Adminvoorbeeld' });
    await banner.waitFor();
    // Ensure scrollability even if the disposable catalogue contains no lots.
    await preview.evaluate(() => { const space = document.createElement('div'); space.style.height = '3000px'; document.body.append(space); });
    for (const width of [1280, 390]) {
      await preview.setViewportSize({ width, height: 800 });
      await preview.evaluate(() => window.scrollTo({ top: 1500, behavior: 'instant' }));
      await preview.waitForFunction(() => window.scrollY > 1000);
      await preview.waitForFunction(() => {
        const b = document.querySelector('.admin-preview-banner');
        return b && b.offsetHeight === parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--admin-preview-height'));
      });
      const box = await banner.boundingBox();
      assert.ok(box && Math.abs(box.y) < 1 && box.height > 0 && box.height < 800, 'Banner stays at viewport top');
      const header = await preview.locator('.site-header').boundingBox();
      assert.ok(header && header.y >= box.height - 1, 'Sticky navigation stays below the wrapped banner');
      assert.equal(await preview.evaluate(() => window.scrollY > 1000), true, 'Actually scrolled');
    }
    await context.close();
    process.stdout.write('PASS actual desktop/mobile preview banner scrolling and header offset\n');
  } finally { await browser.close(); }
})().catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
