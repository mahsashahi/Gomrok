#!/usr/bin/env node
/**
 * Gomrok admin panel screenshot tool (Phase 27 Q2 — Playwright via Node).
 * Dev-only tooling, not part of the runtime app. Used for every phase's
 * required visual evidence from here on (CLAUDE.md's Visual and Output
 * Verification Rule).
 *
 * Usage:
 *   node screenshot.js <url> <output.png> [--login <email> <password>]
 *
 * With --login, it first POSTs the admin login form to establish a session
 * cookie, then navigates to <url> with that cookie set — so authenticated
 * screens can be captured without a browser-driven login flow each time.
 */
const { chromium } = require('playwright');

async function main() {
  const args = process.argv.slice(2);
  if (args.length < 2) {
    console.error('Usage: node screenshot.js <url> <output.png> [--login <email> <password>]');
    process.exit(1);
  }
  const [url, outPath] = args;
  const loginIndex = args.indexOf('--login');

  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();

  if (loginIndex !== -1) {
    const email = args[loginIndex + 1];
    const password = args[loginIndex + 2];
    const base = new URL(url).origin;

    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('#email', email);
    await page.fill('#password', password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type=submit]'),
    ]);
  }

  await page.goto(url, { waitUntil: 'networkidle' });
  await page.screenshot({ path: outPath, fullPage: true });
  await browser.close();

  console.log(`Saved ${outPath}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
