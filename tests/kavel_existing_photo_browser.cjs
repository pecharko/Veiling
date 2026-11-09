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
      page.locator('#lot-editor-form button[type="submit"]').click(),
    ]);
    assert.equal(await page.locator('#lot-editor-form .lot-editor-side').count(), 0,
      'Existing lot has no redundant information side panel');
    assert.equal(await page.locator('#lot-editor-form .lot-editor-layout--single').count(), 1,
      'Existing lot fields take the available page width');
    const editorUrl = page.url();
    assert.match(editorUrl, /\/admin\/kavel\.php\?id=\d+/);
    const toolbarCancel = page.locator('.lot-editor-toolbar-actions a.lot-editor-cancel');
    const toolbarSave = page.locator('.lot-editor-toolbar-actions button[form="lot-editor-form"]');
    const bottomSave = page.locator('.lot-editor-bottom button[form="lot-editor-form"]');
    assert.equal(await toolbarCancel.count(), 1, 'Existing lot offers Annuleren at the top');
    assert.equal((await toolbarSave.textContent()).trim(), 'Wijzigingen opslaan',
      'Existing lot top button has the requested label');
    assert.equal((await bottomSave.textContent()).trim(), 'Wijzigingen opslaan',
      'Existing lot bottom button saves the correct form');
    const galleryBounds = await page.locator('#lot-photo-grid').boundingBox();
    const bottomBounds = await page.locator('.lot-editor-bottom').boundingBox();
    assert(galleryBounds && bottomBounds && bottomBounds.y > galleryBounds.y + galleryBounds.height,
      'Existing lot action buttons are visually below the photo gallery');
    await page.locator('input[name="title"]').fill('Temporary edit before cancel');
    page.once('dialog', dialog => {
      assert.match(dialog.message(), /wijzigingen.*niet zijn opgeslagen/i);
      return dialog.dismiss();
    });
    await toolbarCancel.click();
    assert.equal(page.url(), editorUrl, 'Cancelling an edit warning stays on the lot editor');
    // Regression: the hidden input name="action" shadows HTMLFormElement.action.
    // The real upload must target this editor URL, never that input element.
    const uploadForm = page.locator('#lot-photo-upload-form');
    assert.equal(await uploadForm.count(), 1);
    assert.equal(await uploadForm.evaluate(el => el.getAttribute('action')), null);
    const uploadResponses = [];
    page.on('response', response => {
      if (response.request().method() === 'POST' &&
          response.request().headers()['accept']?.includes('application/json')) {
        uploadResponses.push({ url: response.url(), status: response.status() });
      }
    });

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
    const addPhotoInput = page.locator('#lot-photo-grid .lot-editor-photo-add input[type=file]').first();
    assert.equal(await addPhotoInput.evaluate(el => getComputedStyle(el).opacity), '0',
      'Native file-input text is invisible within the photo-add tile');
    const fileChooserWait = page.waitForEvent('filechooser');
    await addPhotoInput.click();
    const fileChooser = await fileChooserWait;
    assert.equal(fileChooser.isMultiple(), false, 'Photo tile still opens a single-file picker');

    // Delay the first upload to reproduce clicking "Save" before the HTTP
    // response confirms photo persistence (the reported user regression).
    let delayedFirstUpload = false;
    await page.route('**/admin/kavel.php?id=*', async route => {
      const req = route.request();
      if (!delayedFirstUpload && req.method() === 'POST' &&
          req.headers()['accept']?.includes('application/json')) {
        delayedFirstUpload = true;
        await new Promise(resolve => setTimeout(resolve, 700));
      }
      await route.continue();
    });
    for (let n = 1; n <= 3; n++) {
      if (n === 1) await page.locator('input[name="title"]').fill('Edited while photo upload runs');
      await page.locator('#lot-photo-grid .lot-editor-photo-add input[type=file]').setInputFiles({
        name: 'browser-upload-' + n + '.png',
        mimeType: 'image/png',
        buffer: image,
      });
      if (n === 1) {
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
          page.locator('#lot-editor-form button[type="submit"]').click(),
        ]);
        assert(delayedFirstUpload, 'The slow-upload race must actually be exercised');
        assert.equal(await page.locator('input[name="title"]').inputValue(), 'Edited while photo upload runs',
          'Save queued during photo upload must preserve edited lot fields');
      } else {
        await page.waitForFunction(
          () => document.getElementById('lot-photo-upload-status')?.textContent?.includes('Foto opgeslagen.'),
          null,
          { timeout: 15000 },
        );
      }
      assert.equal(await savedPhotos().count(), n, 'Upload ' + n + ' must appear as a saved photo');
      await page.reload();
      assert.equal(await savedPhotos().count(), n, 'Photo ' + n + ' survives reopening the existing lot');
      assert.equal(await page.locator('#lot-photo-grid img[src^="/uploads/"]').count(), n);
    }
    assert.equal(uploadResponses.length, 3, 'Exactly three automatic photo POST requests are sent');
    assert(uploadResponses.every(entry => entry.url === editorUrl && entry.status === 200),
      'Automatic photos POST to the actual editor URL, not a shadowed form.action property');
    assert.equal(await page.locator('#lot-photo-grid .lot-editor-photo-add').count(), 0,
      'No new upload tile after three saved photos');

    await page.locator('input[name="title"]').fill('Saved with three photos');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.locator('#lot-editor-form button[type="submit"]').click(),
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
