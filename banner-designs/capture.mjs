#!/usr/bin/env node

// Run with: npx puppeteer@latest node capture.mjs
// Or after: npm install puppeteer (temp)

const puppeteer = await import('puppeteer');
const { fileURLToPath } = await import('url');
const path = await import('path');

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(__dirname, '..', '.wordpress-org');

const browser = await puppeteer.default.launch({ headless: true });
const page = await browser.newPage();

const filePath = `file://${path.join(__dirname, 'variation-1-dark-tech.html')}`;

// === BANNER 1544x500 (retina) ===
await page.setViewport({ width: 1544, height: 500, deviceScaleFactor: 1 });
await page.goto(filePath, { waitUntil: 'networkidle0' });
await page.evaluate(() => {
  document.body.style.padding = '0';
  document.body.style.gap = '0';
  document.querySelector('.icon-section').style.display = 'none';
});

const bannerBox = await (await page.$('.banner')).boundingBox();
await page.screenshot({
  path: path.join(outDir, 'banner-1544x500.png'),
  type: 'png',
  clip: { x: bannerBox.x, y: bannerBox.y, width: 1544, height: 500 }
});
console.log('✓ banner-1544x500.png');

// === BANNER 772x250 (standard — render at 1544x500, capture at 0.5 scale) ===
await page.setViewport({ width: 1544, height: 500, deviceScaleFactor: 0.5 });
await page.goto(filePath, { waitUntil: 'networkidle0' });
await page.evaluate(() => {
  document.body.style.padding = '0';
  document.body.style.gap = '0';
  document.querySelector('.icon-section').style.display = 'none';
});
const bannerBox2 = await (await page.$('.banner')).boundingBox();
await page.screenshot({
  path: path.join(outDir, 'banner-772x250.png'),
  type: 'png',
  clip: { x: bannerBox2.x, y: bannerBox2.y, width: 1544, height: 500 }
});
console.log('✓ banner-772x250.png');

// === ICON 256x256 ===
await page.setViewport({ width: 800, height: 800, deviceScaleFactor: 1 });
await page.goto(filePath, { waitUntil: 'networkidle0' });
await page.evaluate(() => {
  document.body.style.padding = '0';
  document.body.style.gap = '0';
  document.querySelector('.banner').style.display = 'none';
  document.querySelector('.icon-section h2').style.display = 'none';
});
const iconBox = await (await page.$('.icon-box')).boundingBox();
await page.screenshot({
  path: path.join(outDir, 'icon-256x256.png'),
  type: 'png',
  clip: { x: iconBox.x, y: iconBox.y, width: 256, height: 256 }
});
console.log('✓ icon-256x256.png');

// === ICON 128x128 ===
await page.setViewport({ width: 800, height: 800, deviceScaleFactor: 0.5 });
await page.goto(filePath, { waitUntil: 'networkidle0' });
await page.evaluate(() => {
  document.body.style.padding = '0';
  document.body.style.gap = '0';
  document.querySelector('.banner').style.display = 'none';
  document.querySelector('.icon-section h2').style.display = 'none';
});
const iconBox2 = await (await page.$('.icon-box')).boundingBox();
await page.screenshot({
  path: path.join(outDir, 'icon-128x128.png'),
  type: 'png',
  clip: { x: iconBox2.x, y: iconBox2.y, width: 256, height: 256 }
});
console.log('✓ icon-128x128.png');

await browser.close();
console.log('\nAll assets saved to .wordpress-org/');
