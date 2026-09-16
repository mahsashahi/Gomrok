#!/usr/bin/env node
// One-off variant of screenshot.js that clicks a selector before capturing —
// used to verify Alpine.js interactions (e.g. the Sales screen's expandable
// row timeline) actually work, not just that the page loads.
const { chromium } = require('playwright');

async function main() {
  const [url, outPath, clickSelector, loginEmail, loginPassword] = process.argv.slice(2);
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();

  if (loginEmail) {
    const base = new URL(url).origin;
    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('#email', loginEmail);
    await page.fill('#password', loginPassword);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type=submit]'),
    ]);
  }

  await page.goto(url, { waitUntil: 'networkidle' });
  if (clickSelector) {
    await page.click(clickSelector);
    await page.waitForTimeout(150);
  }
  await page.screenshot({ path: outPath, fullPage: true });
  await browser.close();
  console.log(`Saved ${outPath}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
