#!/usr/bin/env node
// One-off variant that performs a real HTML5-ish drag (mouse down/move/up)
// between two selectors before capturing — used to verify the Packaging &
// Pricing screen's drag-to-reorder (Phase 27 Increment B) actually moves
// rows, not just that the markup has `draggable`.
const { chromium } = require('playwright');

async function main() {
  const [url, outPath, sourceSelector, targetSelector, loginEmail, loginPassword] = process.argv.slice(2);
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

  const source = page.locator(sourceSelector);
  const target = page.locator(targetSelector);
  const sourceBox = await source.boundingBox();
  const targetBox = await target.boundingBox();

  await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
  await page.mouse.down();
  await page.waitForTimeout(80);
  await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2, { steps: 10 });
  await page.waitForTimeout(80);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.mouse.up(),
  ]);
  await page.screenshot({ path: outPath, fullPage: true });
  await browser.close();
  console.log(`Saved ${outPath}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
