/* Browser regressions for the synthetic catalogue: branding, favorites, sharing and lot navigation. */
const assert = require('node:assert/strict');

const brandPages = [
  '/index.php', '/kunstenaar-interesse.php', '/kunstwerk-interesse.php',
  '/kunstenaars.php', '/goede-doel.php', '/vorige-editie.php',
];

async function visit(page, base, path) {
  const response = await page.goto(base + path);
  assert.equal(response.status(), 200, path);
}

async function checkBrand(page, base) {
  for (const path of brandPages) {
    await visit(page, base, path);
    const brand = page.getByRole('link', { name: '41 Lokeren - naar startpagina', exact: true });
    assert.equal(await brand.count(), 1, path);
    assert.equal(await brand.getAttribute('href'), '/index.php', path);
    assert.equal((await brand.textContent()).replace(/\s+/g, ' ').trim(), '41 LOKEREN KUNSTVEILING', path);
    const logo = brand.locator('.brand-mark');
    assert.equal(await logo.getAttribute('aria-hidden'), 'true');
    assert.equal(await logo.isVisible(), true);
    const logoUrl = await logo.evaluate(el => getComputedStyle(el).backgroundImage);
    assert.match(logoUrl, /logo-41lokeren\.png["']?\)/, path);
    assert.equal(await brand.locator('small').isVisible(), true);
    assert.equal(await brand.locator('small').evaluate(el => getComputedStyle(el).display), 'block');
    const imageUrl = new URL(logoUrl.match(/url\(["']?(.*?)["']?\)/)[1]);
    assert.equal(imageUrl.origin, base);
    assert.equal((await page.request.get(imageUrl.href)).status(), 200);
    await brand.evaluate(el => {
      for (const styleSheet of el.ownerDocument.styleSheets) styleSheet.disabled = true;
    });
    assert.equal((await brand.innerText()).replace(/\s+/g, ' ').trim(), '41 LOKEREN KUNSTVEILING', path);
  }
  await page.getByRole('link', { name: '41 Lokeren - naar startpagina', exact: true }).click();
  await page.waitForURL(base + '/index.php');
}

async function checkFavorites(page, base, lot) {
  const detailPath = '/kavel.php?nr=' + lot;
  const detailFavorite = page.locator('#detailFavorite');
  const cardFavorite = page.locator('.lot-card [data-favorite="' + lot + '"]');
  const checkState = async (button, active) => {
    assert.equal(await button.getAttribute('aria-pressed'), String(active));
    assert.equal(await button.evaluate(el => el.classList.contains('active')), active);
  };

  await visit(page, base, detailPath);
  await checkState(detailFavorite, false);
  await detailFavorite.click();
  await checkState(detailFavorite, true);
  await page.reload();
  await checkState(detailFavorite, true);
  await page.getByRole('link', { name: '← Terug naar de catalogus' }).click();
  await page.waitForURL(base + '/index.php#catalogus');
  await checkState(cardFavorite, true);
  await page.locator('#favoritesOnly').click();
  await page.waitForURL(url => url.searchParams.get('favorieten') === '1' && url.searchParams.get('nummers') === String(lot));
  assert.equal(await page.locator('.lot-card').count(), 1);
  await checkState(cardFavorite, true);
  await cardFavorite.click();
  await page.waitForURL(url => url.searchParams.get('favorieten') === '1' && url.searchParams.get('nummers') === '');
  assert.equal(await page.locator('.lot-card').count(), 0);
  await visit(page, base, '/index.php');
  await checkState(cardFavorite, false);
  await visit(page, base, detailPath);
  await checkState(detailFavorite, false);
}

async function checkNavigation(page, base, lots, width) {
  for (const index of [0, 1, lots.length - 1]) {
    await visit(page, base, '/kavel.php?nr=' + lots[index]);
    const nav = page.getByRole('navigation', { name: 'Navigeren tussen kavels' });
    for (const [label, target, direction] of [
      ['Vorig kavel', lots[index - 1], 'M19 12H5m7-7-7 7 7 7'],
      ['Volgend kavel', lots[index + 1], 'M5 12h14m-7-7 7 7-7 7'],
    ]) {
      const link = nav.getByRole('link', { name: label, exact: true });
      if (target === undefined) {
        assert.equal(await link.count(), 0, label);
        continue;
      }
      assert.equal(await link.getAttribute('href'), '/kavel.php?nr=' + target);
      assert.equal((await link.textContent()).trim(), label);
      assert.equal(await link.locator('.detail-side-nav-text').isVisible(), width > 767);
      const arrow = link.locator('svg');
      assert.equal(await arrow.isVisible(), width <= 767);
      assert.equal(await arrow.getAttribute('aria-hidden'), 'true');
      assert.equal(await arrow.locator('path').getAttribute('d'), direction);
      if (width <= 767) {
        const box = await link.boundingBox();
        assert(box.width >= 44 && box.height >= 48, label + ' touch target');
      }
    }
    const goBack = index === lots.length - 1;
    await nav.getByRole('link', { name: goBack ? 'Vorig kavel' : 'Volgend kavel', exact: true }).click();
    await page.waitForURL(base + '/kavel.php?nr=' + lots[index + (goBack ? -1 : 1)]);
  }
}

async function checkShare(browser, base, viewport, lot) {
  const path = '/kavel.php?nr=' + lot;
  for (const mode of ['native', 'cancel', 'clipboard', 'delayed', 'legacy', 'manual']) {
    const context = await browser.newContext({ viewport });
    try {
      if (mode === 'clipboard') await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: base });
      await context.addInitScript(mode => {
        const calls = window.shareCalls = { native: [], clipboard: [], legacy: [], manual: [] };
        const share = {
          native: async data => { calls.native.push(data); },
          cancel: async data => { calls.native.push(data); throw new DOMException('Cancelled', 'AbortError'); },
          delayed: async data => { calls.native.push(data); throw new DOMException('Unavailable', 'NotAllowedError'); },
        }[mode];
        Object.defineProperty(navigator, 'share', { value: share });
        if (mode !== 'clipboard') {
          Object.defineProperty(navigator, 'clipboard', { value: {
            writeText: async text => {
              calls.clipboard.push(text);
              if (mode === 'delayed') return new Promise(resolve => { window.finishShareCopy = resolve; });
              throw new DOMException('Clipboard unavailable', 'NotAllowedError');
            },
          } });
        }
        document.execCommand = command => {
          calls.legacy.push([command, document.activeElement.value]);
          return mode === 'legacy';
        };
        window.prompt = (message, link) => { calls.manual.push([message, link]); return null; };
      }, mode);
      const page = await context.newPage();
      await visit(page, base, path + '#fragment');
      const title = await page.title();
      const button = page.locator('#shareLot');
      await button.click();
      const link = base + path;
      if (mode === 'delayed') {
        await page.waitForFunction(() => !!window.finishShareCopy);
        assert.equal(await button.textContent(), 'Deel dit kavel');
        await page.evaluate(() => window.finishShareCopy());
      }
      if (['clipboard', 'delayed', 'legacy'].includes(mode)) {
        await button.getByText('✓ Link gekopieerd').waitFor();
      } else {
        assert.equal(await button.textContent(), 'Deel dit kavel');
      }
      if (mode === 'clipboard') assert.equal(await page.evaluate(() => navigator.clipboard.readText()), link);
      const nativeArgs = ['native', 'cancel', 'delayed'].includes(mode) ? [{ title, url: link }] : [];
      assert.deepEqual(await page.evaluate(() => window.shareCalls), {
        native: nativeArgs,
        clipboard: ['delayed', 'legacy', 'manual'].includes(mode) ? [link] : [],
        legacy: ['legacy', 'manual'].includes(mode) ? [['copy', link]] : [],
        manual: mode === 'manual' ? [['Kopieer de link naar dit kavel:', link]] : [],
      }, mode);
    } finally {
      await context.close();
    }
  }
}

async function checkPublicInteractions(browser, base) {
  for (const width of [1440, 390]) {
    const viewport = { width, height: 900 };
    const context = await browser.newContext({ viewport });
    let lots;
    try {
      const page = await context.newPage();
      await checkBrand(page, base);
      lots = await page.locator('.lot-card [data-favorite]').evaluateAll(
        elements => elements.map(el => Number(el.dataset.favorite)).sort((a, b) => a - b)
      );
      assert(lots.length >= 3, 'Navigation fixture needs at least three visible lots');
      assert.equal(lots.length, Number.parseInt(await page.locator('.result-count').innerText(), 10));
      await checkFavorites(page, base, lots[1]);
      await checkNavigation(page, base, lots, width);
    } finally {
      await context.close();
    }
    await checkShare(browser, base, viewport, lots[1]);
    console.log('PASS public interaction regressions at ' + width + ' px');
  }
}

function previewBase() {
  const url = new URL(process.env.PREVIEW_BASE_URL || 'http://localhost:8080');
  assert(url.protocol === 'http:' && ['127.0.0.1', 'localhost'].includes(url.hostname));
  return url.origin;
}

module.exports = { checkPublicInteractions, previewBase };

if (require.main === module) {
  const { chromium } = require('playwright');
  (async () => {
    const base = previewBase();
    const executablePath = process.env.PREVIEW_BROWSER_EXECUTABLE;
    const browser = await chromium.launch({ executablePath });
    try {
      console.log('Chromium ' + browser.version() + ' on ' + process.platform);
      await checkPublicInteractions(browser, base);
    } finally {
      await browser.close();
    }
  })().catch(error => {
    console.error(error);
    process.exitCode = 1;
  });
}
