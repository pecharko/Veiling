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
    assert.equal(await page.locator('.catalog-preview-intro').count(), 0, 'Unsplit catalog has no introductory box');
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.screenshot({ path: path.join(output, `catalogue-${width}.png`), fullPage: true, animations: 'disabled' });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `No public overflow ${width}`);
    }
    // Public header must scroll away on mobile (including narrow tablets),
    // while staying sticky on desktop. Test both front and generic headers.
    const headerProbe = await context.newPage();
    const publicHeaderRoutes = [
      '/index.php',
      '/kunstenaars.php',
      '/kunstenaar-interesse.php',
      '/goede-doel.php',
      '/vorige-editie.php',
    ];
    for (const width of [390, 768, 800, 801, 1440]) {
      await headerProbe.setViewportSize({width, height:700});
      for (const route of publicHeaderRoutes) {
        const res = await headerProbe.goto(base + route, {waitUntil:'domcontentloaded'});
        assert.equal(res.status(), 200, 'Public route must load: ' + route);
        const header = headerProbe.locator('header.site-header');
        assert.equal(await header.count(), 1, 'Expected one shared header on ' + route);
        const position = await header.evaluate(el => getComputedStyle(el).position);
        assert.equal(position, width <= 800 ? 'relative' : 'sticky',
          'Mobile header policy for ' + route + ' at width ' + width);
        if (route === '/index.php' && (width === 390 || width === 1440)) {
          const observation = await headerProbe.evaluate(() => {
            document.documentElement.style.scrollBehavior = 'auto';
            window.scrollTo({top:350, behavior:'instant'});
            return {
              y: window.scrollY,
              headerTop: document.querySelector('header.site-header').getBoundingClientRect().top,
            };
          });
          assert(observation.y > 100, 'Homepage must be scrollable in browser');
          if (width === 390) {
            assert(observation.headerTop < -30, 'Mobile header must leave the viewport when scrolled');
          } else {
            assert(Math.abs(observation.headerTop) <= 3,
              'Desktop sticky header must remain visible during scrolling');
          }
        }
      }
    }
    await headerProbe.close();

    // Public artist profile: the lot links internally and exposes no private seller data.
    const artLot = await page.goto(base + '/kavel.php?nr=101');
    assert.equal(artLot.status(), 200, 'Published synthetic artist lot must load');
    const artistLink = page.locator('.detail-artist .lot-artist-name a[href*="kunstenaar.php?id="]');
    assert.equal(await artistLink.count(), 1, 'Artist name must link internally');
    const expectedArtist = (await artistLink.textContent()).trim();
    assert.ok(expectedArtist, 'Linked artist name must not be empty');
    await Promise.all([page.waitForURL('**/kunstenaar.php?id=*'), artistLink.click()]);
    assert.equal((await page.locator('.artist-profile h1').textContent()).trim(), expectedArtist);
    assert.match(await page.locator('.artist-profile-slogan').textContent(), /\S/);
    assert.match(await page.locator('.artist-profile-intro').textContent(), /\S/);
    assert.doesNotMatch(await page.textContent('body'), /kunst@example\\.invalid|BE68539007547034|Voorbeeldstraat/);
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.screenshot({path: path.join(output, `artist-profile-${width}.png`), fullPage: true, animations: 'disabled'});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Artist page overflow ${width}`);
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
    // Save each combination and verify what an ordinary visitor sees.
    await page.goto(base + '/admin/instellingen.php');
    const switches = [
      { name: 'show_artist_recruitment', heading: '#front-recruit-artist-title' },
      { name: 'show_artwork_recruitment', heading: '#front-recruit-lot-title' },
    ];
    for (const { name } of switches) {
      assert.equal(await page.locator(`input[name="${name}"]`).isChecked(), true);
    }
    const ordinary = await browser.newContext();
    const ordinaryPage = await ordinary.newPage();
    for (const visibility of [[false, true], [false, false], [true, false], [true, true]]) {
      for (const [index, { name }] of switches.entries()) {
        const checked = await page.locator(`input[name="${name}"]`).isChecked();
        if (checked !== visibility[index]) {
          await page.locator(`label.auction-settings-toggle:has(input[name="${name}"])`).click();
        }
      }
      const [saved] = await Promise.all([
        page.waitForResponse(response => response.request().method() === 'POST' &&
          new URL(response.url()).pathname === '/admin/instellingen.php'),
        page.locator('button.auction-settings-save').click(),
      ]);
      assert.equal(saved.status(), 303);
      await page.waitForLoadState('domcontentloaded');
      await ordinaryPage.goto(base + '/index.php');
      for (const [index, { name, heading }] of switches.entries()) {
        const expected = visibility[index];
        assert.equal(await page.locator(`input[name="${name}"]`).isChecked(), expected);
        assert.equal(await ordinaryPage.locator(heading).count(), Number(expected), `Saved ${name}: ${expected}`);
      }
      assert.equal(await ordinaryPage.locator('#catalogus').count(), 1, 'Catalogue stays visible');
    }
    console.log('PASS independent public visibility of both homepage sections');
    // Persist the split via Veilinginfo and inspect it without an admin session.
    await page.goto(base + '/admin/instellingen.php');
    const splitToggle = page.locator('input[name="catalog_split_active"]');
    assert.equal(await splitToggle.count(), 1, 'Persistent admin split toggle exists');
    // The actual styled toggle intercepts pointer events on the hidden checkbox.
    // Exercise the visible label as a real user would.
    await page.locator('label.auction-settings-toggle:has(input[name="catalog_split_active"])').click();
    await page.locator('button.auction-settings-save').click();
    await page.waitForURL('**/admin/instellingen.php');
    assert.equal(await page.locator('input[name="catalog_split_active"]').isChecked(), true);
    await ordinaryPage.goto(base + '/index.php');
    assert.equal(await ordinaryPage.locator('.catalog-preview-tabs').count(), 1, 'Saved split affects ordinary visitor');
    assert.match(await ordinaryPage.locator('.lot-estimate').first().textContent(), /Startbod/);
    await ordinary.close();
    await page.locator('label.auction-settings-toggle:has(input[name="catalog_split_active"])').click();
    await page.locator('button.auction-settings-save').click();
    await page.waitForURL('**/admin/instellingen.php');
    assert.equal(await page.locator('input[name="catalog_split_active"]').isChecked(), false);
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
    assert.equal(await preview.locator('.catalog-preview-intro .front-recruit-copy li').count(), 2);
    assert.equal(await preview.locator('.catalog-preview-tabs svg').count(), 2);
    assert.equal(await preview.locator('.catalog-preview-tabs [data-icon="auction-gavel"]').count(), 1);
    assert.equal(await preview.locator('.catalog-preview-help [data-icon="auction-gavel"]').count(), 1);
    assert.equal(await preview.locator('.catalog-preview-help svg').count(), 1);
    assert.match(await preview.locator('.catalog-preview-help strong').textContent(), /Veiling/);
    for (const width of [1440, 390]) {
      await preview.setViewportSize({width, height:900});
      await preview.screenshot({path:path.join(output,`split-veiling-${width}.png`),fullPage:true,animations:'disabled'});
      assert.equal(await preview.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true,`No split layout overflow ${width}`);
    }

    assert.equal(await preview.locator('form.toolbar').count(), 0, 'Split view has no lot filters');
    assert.match(await preview.locator('.catalog-preview-tabs').textContent(), /Veiling.*Marktplaats/s);
    assert.match(await preview.locator('.lot-estimate').first().textContent(), /Startbod/);
    const auctionBadges = await preview.locator('.lot-card').first().evaluate(card => {
      const auction = card.querySelector('.lot-preview-number');
      const lot = card.querySelector('.lot-badge--auction-bottom');
      return { auction: auction?.getBoundingClientRect().top, lot: lot?.getBoundingClientRect().top };
    });
    assert.ok(Number.isFinite(auctionBadges.auction) && Number.isFinite(auctionBadges.lot) &&
      auctionBadges.auction < auctionBadges.lot, 'Auction number must be above the lot number');

    await preview.locator('.catalog-preview-tabs a[href*="deel=markt"]').scrollIntoViewIfNeeded();
    const beforeSwitchScroll = await preview.evaluate(() => window.scrollY);
    await preview.locator('.catalog-preview-tabs a[href*="deel=markt"]').click();
    await preview.waitForFunction(() => document.querySelector('.catalog-preview-help strong')?.textContent.trim() === 'Marktplaats');
    assert.equal(await preview.evaluate(() => window.scrollY), beforeSwitchScroll, 'Tab switch must preserve scroll position');
    assert.equal(new URL(preview.url()).hash, '', 'Tab switch must not create an anchor jump');
    assert.equal(await preview.locator('a.catalog-preview-tabs a').count(), 0);
    assert.match(await preview.locator('.catalog-preview-help strong').textContent(), /Marktplaats/);
    await preview.screenshot({path:path.join(output,'split-marktplaats-1440.png'),fullPage:true,animations:'disabled'});

    assert.match(await preview.locator('.lot-estimate').first().textContent(), /Marktprijs/);
    await preview.locator('button[name="admin_preview_action"][value="split_off"]').click();
    assert.equal(await preview.locator('.catalog-preview-tabs').count(), 0);
    assert.equal(await preview.locator('.lot-estimate').count(), 0);
    await preview.close();

    // The artist interest action and Elise Van Acker brochure need a real
    // responsive browser check, not just a PHP source contract.
    const invitationContext = await browser.newContext();
    const invitationPage = await invitationContext.newPage();
    for (const width of [1440, 390]) {
      await invitationPage.setViewportSize({width, height:900});
      const response = await invitationPage.goto(base + '/index.php', {waitUntil:'domcontentloaded'});
      assert.equal(response.status(), 200, 'Public homepage should open for artist invitation');
      const action = invitationPage.locator('.front-recruit-artist').getByRole('link', {name:'Ik wil graag deelnemen'});
      assert.equal(await action.count(), 1, 'Artist participation action must be an enabled link');
      await Promise.all([
        invitationPage.waitForURL('**/kunstenaar-interesse.php'),
        action.click(),
      ]);
      assert.equal((await invitationPage.locator('h1').textContent()).trim(), 'Ik ben kunstenaar en heb interesse');
      assert.equal((await invitationPage.locator('.artist-invite-sample h3').textContent()).trim(), 'Elise Van Acker');
      assert.equal(await invitationPage.locator('.artist-invite-sample-art').count(), 2);
      const gallery = invitationPage.locator('.artist-invite-sample img');
      assert.equal(await gallery.count(), 3, 'Elise example must show one portrait and two paintings');
      const photosLoaded = await gallery.evaluateAll(async images => {
        await Promise.all(images.map(img => img.decode()));
        return images.every(img => img.naturalWidth > 0);
      });
      assert.equal(photosLoaded, true, 'The three supplied WebP images must load');
      assert.equal(await invitationPage.locator('a[href="mailto:veiling@41lokeren.be"]').count(), 2);
      const brochureWidth = await invitationPage.locator('.artist-invite-sample').evaluate(
        el => el.getBoundingClientRect().width);
      assert(brochureWidth <= 320, 'Example brochure must remain compact');
      assert.equal(await invitationPage.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth), true,
        'No artist-interest horizontal overflow at width ' + width);
      await invitationPage.screenshot({
        path:path.join(output,`artist-interest-${width}.png`), fullPage:true, animations:'disabled'});
    }
    await invitationContext.close();
    assert.deepEqual(errors, [], 'No browser JavaScript errors');
    await context.close();
    console.log('PASS local catalog and admin desktop/mobile screenshots: ' + output);
  } finally { await browser.close(); }
})().catch(e => { console.error(e.stack || e); process.exitCode = 1; });
