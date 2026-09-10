import puppeteer from 'puppeteer-core';
import { AxePuppeteer } from '@axe-core/puppeteer';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
const base = process.env.BASE_URL || 'http://127.0.0.1:4321';
await mkdir('artifacts', { recursive: true });
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
await page.evaluateOnNewDocument(() => {
  const request = window.requestAnimationFrame.bind(window);
  const cancel = window.cancelAnimationFrame.bind(window);
  const pending = new Set();
  window.requestAnimationFrame = callback => {
    let id = request(time => { pending.delete(id); callback(time); });
    pending.add(id);
    return id;
  };
  window.cancelAnimationFrame = id => { pending.delete(id); cancel(id); };
  window.__pendingFrames = () => pending.size;
});
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
const report = { responsive: [], interactions: [], accessibility: [] };
try {
  for (const width of [320,375,390,430,768,1280,1440]) {
    await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
    await page.goto(base, { waitUntil: 'networkidle0' });
    await page.bringToFront();
    const layout = await page.evaluate(() => {
      const tablet = document.querySelector('.tablet').getBoundingClientRect();
      const row = document.querySelector('.lead-row').getBoundingClientRect();
      const list = document.querySelector('.lead-viewport').getBoundingClientRect();
      return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth, ratio: tablet.width / tablet.height, visibleRows: list.height / row.height,
        oneH1: document.querySelectorAll('h1').length === 1,
        unlabelledSections: [...document.querySelectorAll('section')].filter(s => !document.getElementById(s.getAttribute('aria-labelledby'))).length,
        deadAnchors: [...document.querySelectorAll('a[href^="#"]')].filter(a => !document.getElementById(a.getAttribute('href').slice(1))).length,
        formSizes: [...document.querySelectorAll('.field input,.field select,.field textarea')].every(el => parseFloat(getComputedStyle(el).fontSize) >= 16),
        externalFonts: performance.getEntriesByType('resource').filter(r => /fontshare|googleapis|gstatic/.test(r.name)).length,
      };
    });
    assert.equal(layout.width, layout.scrollWidth, `Overflow at ${width}`);
    assert.ok(Math.abs(layout.ratio - 16 / 11) < .01, `Tablet aspect at ${width}`);
    assert.ok(layout.visibleRows >= (width <= 580 ? 1.98 : 2.98), `Clipped lead row at ${width}: ${layout.visibleRows}`);
    assert.ok(layout.oneH1 && !layout.unlabelledSections && !layout.deadAnchors && layout.formSizes && !layout.externalFonts);
    report.responsive.push(layout);
  }
  console.log('Responsive layout and semantics passed at all seven widths.');
  await page.setViewport({ width:375, height:900, deviceScaleFactor:1 });
  await page.goto(base, { waitUntil:'networkidle0' });
  await page.bringToFront();
  const count = () => page.$eval('[data-dashboard]', el => Number(el.dataset.arrivals));
  await page.waitForFunction(() => Number(document.querySelector('[data-dashboard]').dataset.arrivals) > 0, { timeout:8000 });
  assert.equal(await page.$eval('[data-lead-count]', el => el.textContent), '25');
  report.interactions.push('375px dashboard receives a lead and updates its figure');
  await page.evaluate(() => document.querySelector('#results').scrollIntoView({ behavior:'instant' }));
  const paused = await count();
  await wait(5100);
  assert.equal(await count(), paused);
  assert.equal(await page.evaluate(() => window.__pendingFrames()), 0);
  report.interactions.push('Dashboard and frame callbacks stop offscreen');
  await page.evaluate(() => scrollTo({top:0,behavior:'instant'}));
  const foregroundCount = await count();
  const other = await browser.newPage();
  await other.goto('about:blank');
  await other.bringToFront();
  assert.equal(await page.evaluate(() => document.hidden), true);
  await wait(5100);
  assert.equal(await count(), foregroundCount);
  await other.close();
  await page.bringToFront();
  report.interactions.push('Background tab pauses dashboard');
  await page.click('[data-toggle-dashboard]');
  const manual = await count();
  await wait(5100);
  assert.equal(await count(), manual);
  await page.click('[data-toggle-dashboard]');
  await page.waitForFunction(() => document.querySelector('[data-dashboard]').dataset.arrivals === '6', {timeout:35000});
  await wait(1800);
  assert.equal(await page.$eval('[data-lead-count]', el => el.textContent), '30');
  assert.equal(await page.evaluate(() => window.__pendingFrames()), 0);
  report.interactions.push('Pause/resume works; sequence stops after six arrivals');
  await page.click('.hero [data-open-enquiry]');
  assert.equal(await page.$eval('#enquiry-modal', el => el.open), true);
  await page.keyboard.press('Tab');
  assert.equal(await page.evaluate(() => document.querySelector('#enquiry-modal').contains(document.activeElement)), true);
  await page.keyboard.press('Escape');
  assert.equal(await page.$eval('#enquiry-modal', el => el.open), false);
  assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-open-enquiry')), true);
  await page.click('.menu-toggle');
  assert.equal(await page.$eval('.menu-toggle', el => el.getAttribute('aria-expanded')), 'true');
  await page.click('#mobile-menu a[href="#software"]');
  await wait(250);
  assert.equal(await page.$eval('#mobile-menu', el => el.open), false);
  await page.click('#tab-portal');
  assert.equal(await page.$eval('#panel-portal', el => el.hidden), false);
  await page.keyboard.press('ArrowDown');
  assert.equal(await page.$eval('#tab-build', el => el.getAttribute('aria-selected')), 'true');
  report.interactions.push('Dialog focus/escape, mobile menu navigation and keyboard tabs pass');
  // Exercise form UI with controlled HTTP responses: no external messages.
  let responseMode = 'error';
  await page.setRequestInterception(true);
  const handleRequest = request => {
    if (!request.url().includes('/api/contact.php') || request.method() !== 'POST') return request.continue();
    return request.respond({status:responseMode === 'success' ? 200 : 422, contentType:'application/json', body:JSON.stringify(responseMode === 'success' ? {ok:true} : {ok:false,field:'email',error:'Check your work email.'})});
  };
  page.on('request', handleRequest);
  await page.evaluate(() => {
    const form = document.querySelector('#contact-form');
    const values = {name:'QA Visitor',email:'qa@example.com',phone:'+91 9000000000',company:'Local QA',service:'Software',message:'Local UI verification'};
    for (const [name,value] of Object.entries(values)) form.elements.namedItem(name).value = value;
    form.requestSubmit();
  });
  await page.waitForFunction(() => document.querySelector('#contact-form [data-form-message]').textContent.includes('Check your work email.'));
  assert.equal(await page.$eval('#contact-form [name=email]', el => el.getAttribute('aria-invalid')), 'true');
  responseMode = 'success';
  await page.evaluate(() => document.querySelector('#contact-form').requestSubmit());
  await page.waitForFunction(() => document.querySelector('#contact-form [data-form-message]').dataset.state === 'success');
  assert.equal(await page.$eval('#contact-form [type=submit]', el => el.disabled), false);
  report.interactions.push('Form validation, server field error, success and re-enabled submission pass');
  page.off('request', handleRequest);
  await page.setRequestInterception(false);
  for (const width of [375,1440]) {
    await page.setViewport({width,height:900,deviceScaleFactor:1});
    await page.goto(base,{waitUntil:'networkidle0'});
    const axe = await new AxePuppeteer(page).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
    report.accessibility.push({width, violations:axe.violations.map(v=>({id:v.id,impact:v.impact,description:v.description,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))}))});
  }
  await page.emulateMediaFeatures([{name:'prefers-reduced-motion',value:'reduce'}]);
  await page.goto(base,{waitUntil:'networkidle0'});
  await wait(5200);
  assert.equal(await count(),0);
  assert.equal(await page.evaluate(() => window.__pendingFrames()),0);
  report.interactions.push('Reduced motion freezes populated dashboard and all frame loops');
  assert.deepEqual(errors,[]);
  report.consoleErrors = errors;
  await writeFile('artifacts/verification.json', JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));
  assert.ok(report.accessibility.every(a=>a.violations.length===0),'Accessibility violations; see artifacts/verification.json');
} finally { await browser.close(); }
