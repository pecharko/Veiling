/* CI-only real-browser regression: upload to an existing lot, save edits, reopen. */
'use strict';
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('Only run against the disposable GitHub Actions test database.');
  }
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  const base = 'http://localhost:8090';
  try {
    await page.goto(base + '/admin/login.php');
    await page.locator('[name="email"]').fill('ci-admin@example.invalid');
    await page.locator('[name="password"]').fill('ci-password-not-a-real-secret');
    await Promise.all([
      page.waitForURL('**/admin/index.php'),
      page.locator('button[type="submit"]').click(),
    ]);

    // A lot created in an earlier request is the prerequisite for add-photo.
    await page.goto(base + '/admin/kavel.php');
    await page.locator('input[name="lot_number"]').fill('999901');
    await page.locator('input[name="title"]').fill('Browser photo persistence fixture');
    await Promise.all([
      page.waitForURL(/\/admin\/kavel\.php\?id=\d+/),
      page.locator('button[form="lot-editor-form"]').click(),
    ]);
    const editorUrl = page.url();
    assert.match(editorUrl, /\/admin\/kavel\.php\?id=\d+/);

    // Generate a real 32x32 PNG inside the browser, no external images/data.
    const png = await page.evaluate(() => {
      const canvas = document.createElement('canvas');
      canvas.width = 32;
      canvas.height = 32;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#246384';
      ctx.fillRect(0, 0, 32, 32);
      return canvas.toDataURL('image/png').split(',')[1];
    });
    const image = Buffer.from(png, 'base64');
    assert(image.length < 1024, 'Test PNG must fit the CI upload size limit');
    const savedPhotos = () => page.locator('#lot-photo-grid .lot-editor-photo:not(.lot-editor-photo-add) img');

    for (let n = 1; n <= 3; n++) {
      await page.locator('#lot-photo-grid .lot-editor-photo-add input[type=file]').setInputFiles({
        name: 'browser-upload-' + n + '.png',
        mimeType: 'image/png',
        buffer: image,
      });
      await page.waitForFunction(
        () => document.getElementById('lot-photo-upload-status')?.textContent?.includes('Foto opgeslagen.'),
        null,
        { timeout: 15000 },
      );
      assert.equal(await savedPhotos().count(), n, 'Upload ' + n + ' must appear as a saved photo');
      await page.reload();
      assert.equal(await savedPhotos().count(), n, 'Photo ' + n + ' survives reopening the existing lot');
      assert.equal(await page.locator('#lot-photo-grid img[src^="/uploads/"]').count(), n);
    }
    assert.equal(await page.locator('#lot-photo-grid .lot-editor-photo-add').count(), 0,
      'No new upload tile after three saved photos');

    await page.locator('input[name="title"]').fill('Saved with three photos');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.locator('button[form="lot-editor-form"]').click(),
    ]);
    await page.reload();
    assert.equal(await page.locator('input[name="title"]').inputValue(), 'Saved with three photos');
    assert.equal(await savedPhotos().count(), 3,
      'All photos must remain after saving unrelated lot data and reopening');

    // Clean up the synthetic lot through the normal guarded UI.
    await page.locator('.lot-editor-danger summary').click();
    page.once('dialog', dialog => dialog.accept());
    await Promise.all([
      page.waitForURL('**/admin/index.php'),
      page.locator('.lot-editor-danger button.danger').click(),
    ]);
    console.log('PASS: Existing lot browser upload, 3-photo limit, save and reopen');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
