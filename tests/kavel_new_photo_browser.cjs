/* CI regression: new-lot photo tiles retain selected files through first save. */
'use strict';
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('Test only runs against the isolated GitHub Actions database.');
  }
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  const base = 'http://localhost:8090';
  try {
    await page.goto(base + '/admin/login.php');
    await page.locator('[name=email]').fill('ci-admin@example.invalid');
    await page.locator('[name=password]').fill('ci-password-not-a-real-secret');
    await Promise.all([
      page.waitForURL('**/admin/index.php'),
      page.locator('button[type=submit]').click(),
    ]);
    await page.goto(base + '/admin/kavel.php');
    assert.equal(await page.locator('#lot-new-photo-grid').count(), 1, 'New lot has photo tiles');
    assert.equal(await page.locator('.lot-editor-uploader').count(), 0, 'Old multipicker is removed');
    const input = () => page.locator('#lot-new-photo-grid .lot-editor-photo-add:not(.is-selected) input[type=file]');
    const tiles = () => page.locator('#lot-new-photo-grid .lot-editor-photo-add.is-selected');
    assert.equal(await input().evaluate(el => getComputedStyle(el).opacity), '0',
      'Native file chooser text remains hidden');

    const pngBase64 = await page.evaluate(() => {
      const canvas = document.createElement('canvas');
      canvas.width = 32;
      canvas.height = 32;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#175a79';
      ctx.fillRect(0, 0, 32, 32);
      return canvas.toDataURL('image/png').split(',')[1];
    });
    const buffer = Buffer.from(pngBase64, 'base64');
    assert(buffer.length < 1024, 'CI image respects the 1K HTTP upload limit');
    const choose = async name => input().setInputFiles({ name, mimeType: 'image/png', buffer });
    await choose('first.png');
    await choose('removed.png');
    await choose('third.png');
    assert.equal(await tiles().count(), 3, 'Three staged photos visible');
    assert.equal(await input().count(), 0, 'Fourth tile is absent at the maximum');
    await tiles().nth(1).locator('button.lot-editor-remove-photo').click();
    assert.equal(await tiles().count(), 2, 'Remove only the selected photo');
    assert.equal(await input().count(), 1, 'Upload tile reappears');
    await choose('replacement.png');

    const staged = await page.locator('#lot-editor-form').evaluate(form =>
      new FormData(form).getAll('photos[]').filter(file => file.name).map(file => file.name));
    assert.deepEqual(staged, ['first.png', 'third.png', 'replacement.png'],
      'All three retained file inputs belong to the new-lot save form');
    assert.equal(await tiles().count(), 3, 'Three previews remain before save');
    await page.locator('#lot-editor-form input[name=lot_number]').fill('999902');
    await page.locator('#lot-editor-form input[name=title]').fill('CI new-lot photo tiles');
    await Promise.all([
      page.waitForURL(/\/admin\/kavel\.php\?id=\d+/, { timeout: 15000 }),
      page.locator('#lot-editor-form button[type=submit]').click(),
    ]);
    assert.equal(await page.locator('#lot-photo-grid .lot-editor-photo:not(.lot-editor-photo-add) img').count(), 3,
      'Three saved photos appear after creating the lot');
    const imageUrls = await page.locator('#lot-photo-grid .lot-editor-photo:not(.lot-editor-photo-add) img')
      .evaluateAll(imgs => imgs.map(img => img.getAttribute('src')));
    assert.equal(new Set(imageUrls).size, 3, 'Every photo has its own stored filename');
    for (const url of imageUrls) {
      const result = await page.request.get(new URL(url, base).href);
      assert.equal(result.status(), 200, 'Uploaded photo can be loaded from the server');
      assert.match(result.headers()['content-type'] || '', /image\/webp/,
        'Uploaded photo was optimized to WebP');
    }
    await page.reload();
    assert.equal(await page.locator('#lot-photo-grid .lot-editor-photo:not(.lot-editor-photo-add) img').count(), 3,
      'All photos survive reopening the newly created lot');

    // Remove only the CI-created lot. Never touch production or user fixtures.
    await page.locator('.lot-editor-danger summary').click();
    page.once('dialog', dialog => dialog.accept());
    await Promise.all([
      page.waitForURL('**/admin/index.php'),
      page.locator('.lot-editor-danger button.danger').click(),
    ]);
    console.log('PASS new lot staged photos, replacement, first save, reopen');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
