/* Focused real-browser acceptance for the artist-interest page (localhost only). */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const base = 'http://localhost:8080';
const browserPath = process.env.PREVIEW_BROWSER_EXECUTABLE ||
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
(async () => {
  assert(fs.existsSync(browserPath), 'Chrome is required at the configured browser path');
  const browser = await chromium.launch({headless:true, executablePath:browserPath});
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    for (const width of [1440, 390]) {
      await page.setViewportSize({width,height:900});
      const home = await page.goto(base+'/index.php',{waitUntil:'domcontentloaded'});
      assert.equal(home.status(),200);
      const artistAction = page.getByRole('link',{name:'Ik ben kunstenaar en heb interesse'});
      assert.equal(await artistAction.count(),1,'Artist participation button must be an enabled link');
      assert.equal(await artistAction.getAttribute('href'),'/kunstenaar-interesse.php');
      await Promise.all([
        page.waitForURL('**/kunstenaar-interesse.php'),
        artistAction.click(),
      ]);
      assert.equal(new URL(page.url()).hostname,'localhost');
      assert.equal(await page.locator('h1').textContent(),'Ik ben kunstenaar en heb interesse');
      assert.equal(await page.locator('.artist-invite-sample').count(),1);
      assert.equal(await page.locator('.artist-invite-sample-art').count(),3);
      assert.equal(await page.locator('a[href="mailto:veiling@41lokeren.be"]').count(),2);
      assert.match(await page.textContent('body'),/Je bepaalt bovendien helemaal zelf welk deel van de verkoopprijs/);
      assert.match(await page.textContent('body'),/Samen geven we kunst een podium én maken we het verschil/);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true,
        'No horizontal overflow at width '+width);
      const target = path.join(__dirname,'.screenshots', 'artist-interest-'+width+'.png');
      fs.mkdirSync(path.dirname(target),{recursive:true});
      await page.screenshot({path:target,fullPage:true,animations:'disabled'});
    }
    await context.close();
    console.log('PASS artist interest page from homepage, mail link, brochure and responsive desktop/mobile');
  } finally {
    await browser.close();
  }
})().catch(error=>{console.error(error.stack||error);process.exitCode=1;});
