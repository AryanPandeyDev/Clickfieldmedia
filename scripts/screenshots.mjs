import puppeteer from 'puppeteer-core';
import { mkdir } from 'node:fs/promises';
await mkdir('artifacts', { recursive: true });
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
for (const width of [320, 375, 390, 430, 768, 1280, 1440]) {
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.setViewport({ width, height: width > 1000 ? 1000 : 850, deviceScaleFactor: 1 });
  await page.goto('http://127.0.0.1:4321', { waitUntil: 'networkidle0' });
  await page.evaluate(() => document.fonts.ready);
  const layout = await page.evaluate(() => ({
    width: innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    tuner: (() => {
      const console = document.querySelector('.signal-console');
      return { width: console.clientWidth, height: console.clientHeight };
    })(),
    fonts: [document.fonts.check('500 20px "Clash Display"'), document.fonts.check('400 16px Switzer')],
    missingImages: [...document.images].filter(i => i.complete && !i.naturalWidth).map(i => i.src),
  }));
  console.log(JSON.stringify({ ...layout, errors }));
  if ([320,375,1440].includes(width)) {
    await page.screenshot({ path: `artifacts/viewport-${width}.png` });
    await page.screenshot({ path: `artifacts/page-${width}.png`, fullPage: true });
    await (await page.$('.tuner-figure')).screenshot({ path: `artifacts/tuner-${width}.png` });
  }
  if (width === 320 || width === 1440) {
    await page.click('.hero [data-open-enquiry]');
    await page.screenshot({ path: `artifacts/enquiry-${width}.png` });
    await page.keyboard.press('Escape');
    if (width === 320) {
      await page.click('.menu-toggle');
      await page.screenshot({ path: 'artifacts/menu-320.png' });
    }
  }
  if (width === 1440) for (const id of ['services','software','results','contact']) {
    await (await page.$(`#${id}`)).screenshot({ path: `artifacts/${id}-1440.png` });
  }
  await page.close();
}
await browser.close();
