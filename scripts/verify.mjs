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
      const console = document.querySelector('.signal-console').getBoundingClientRect();
      const shell = document.querySelector('.hero .shell').getBoundingClientRect();
      return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth, tunerWidth: console.width, tunerHeight: console.height,
        shellWidth: shell.width, tunerFlush: Math.abs(console.width - shell.width) <= 1,
        headlineSize: parseFloat(getComputedStyle(document.querySelector('h1')).fontSize),
        tunerInside: console.left >= 0 && console.right <= innerWidth,
        controlSizes: [...document.querySelectorAll('.satellite-control,.signal-lock,.audience-dial')].every(el => {const r=el.getBoundingClientRect();return r.width >= 44 && r.height >= 44;}),
        oneH1: document.querySelectorAll('h1').length === 1,
        unlabelledSections: [...document.querySelectorAll('section')].filter(s => !document.getElementById(s.getAttribute('aria-labelledby'))).length,
        deadAnchors: [...document.querySelectorAll('a[href^="#"]')].filter(a => !document.getElementById(a.getAttribute('href').slice(1))).length,
        formSizes: [...document.querySelectorAll('.field input,.field select,.field textarea')].every(el => parseFloat(getComputedStyle(el).fontSize) >= 16),
        externalFonts: performance.getEntriesByType('resource').filter(r => /fontshare|googleapis|gstatic/.test(r.name)).length,
      };
    });
    assert.equal(layout.width, layout.scrollWidth, `Overflow at ${width}`);
    assert.ok(layout.tunerInside && layout.controlSizes, `Tuner bounds/targets at ${width}`);
    // Below 640 the console is the same object scaled to the shell, so it spans the content width exactly.
    assert.ok(width < 640 ? layout.tunerFlush : layout.tunerWidth <= 510, `Tuner width at ${width}`);
    assert.ok(layout.tunerHeight < 420 && layout.headlineSize >= 43, `Hero proportions at ${width}`);
    assert.ok(layout.oneH1 && !layout.unlabelledSections && !layout.deadAnchors && layout.formSizes && !layout.externalFonts);
    report.responsive.push(layout);
  }
  console.log('Responsive layout and semantics passed at all seven widths.');
  await page.setViewport({ width:1440, height:900, deviceScaleFactor:1 });
  await page.goto(base, { waitUntil:'networkidle0' });
  await page.bringToFront();
  const dialValue = () => page.$eval('[data-audience-dial]',el => Number(el.getAttribute('aria-valuenow')));
  await page.focus('[data-audience-dial]');
  await page.keyboard.press('Home'); assert.equal(await dialValue(),0);
  await page.keyboard.press('ArrowRight'); assert.equal(await dialValue(),25);
  await page.keyboard.press('End'); assert.equal(await dialValue(),100);
  assert.equal(await page.$eval('[data-lock-label]',el=>el.textContent),'Signal locked');
  await page.click('[data-tune-stage="2"]'); assert.equal(await dialValue(),50);
  await page.click('[data-lock-signal]'); assert.equal(await dialValue(),100);
  await page.focus('[data-audience-dial]'); await page.keyboard.press('Home');
  const dial = await (await page.$('[data-audience-dial]')).boundingBox();
  await page.mouse.move(dial.x+dial.width/2,dial.y+dial.height/2);
  await page.mouse.down(); await page.mouse.move(dial.x+dial.width/2+120,dial.y+dial.height/2,{steps:12}); await page.mouse.up();
  assert.equal(await dialValue(),75);
  report.interactions.push('Dial keyboard, presets, lock and pointer drag update the signal and accessible values');
  await wait(500);
  assert.equal(await page.evaluate(()=>window.__pendingFrames()),0);
  assert.equal(await page.$eval('html',el=>el.classList.contains('lenis')),true);
  const wheelSamples=[];
  for (const delta of [260,300]) {
    const before=await page.evaluate(()=>scrollY);
    await page.mouse.move(710,500); await page.mouse.wheel({deltaY:delta}); await wait(90);
    const during=await page.evaluate(()=>scrollY);
    assert.ok(during>before+1 && during<before+delta*.94,`Wheel must interpolate: ${before} -> ${during}`);
    await wait(1800);
    const after=await page.evaluate(()=>scrollY);
    assert.ok(Math.abs(after-before-delta*.95)<3,`Wheel destination: ${before} -> ${after}`);
    assert.equal(await page.evaluate(()=>window.__pendingFrames()),0);
    wheelSamples.push({before,during,after});
  }
  const parallax=await page.$eval('[data-hero-object]',el=>new DOMMatrixReadOnly(getComputedStyle(el).transform).m42);
  assert.ok(parallax>0 && parallax<=42);
  report.interactions.push({test:'Repeated wheel after idle stays smooth; bounded hero parallax; zero idle callbacks',wheelSamples,parallax});
  await page.evaluate(()=>scrollTo({top:0,behavior:'instant'})); await wait(100);
  const anchorTop=await page.$eval('#results',el=>el.getBoundingClientRect().top+scrollY-parseFloat(getComputedStyle(el).scrollMarginTop)-parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop));
  await page.click('.hero a[href="#results"]'); await wait(160);
  const anchorDuring=await page.evaluate(()=>scrollY);
  assert.ok(anchorDuring>0 && anchorDuring<anchorTop-20);
  await wait(1500); assert.ok(Math.abs(await page.evaluate(()=>scrollY)-anchorTop)<3);
  report.interactions.push('Section links use a smooth bounded scroll and reach the correct destination');
  // Restart at the top so every result can be observed before their one-time run.
  await page.goto(base,{waitUntil:'networkidle0'});
  const counterSelector='[data-count-target="28"]';
  const number=()=>page.$eval(counterSelector,el=>Number(el.textContent));
  await page.$eval(counterSelector,el=>el.scrollIntoView({block:'center',behavior:'instant'}));
  await page.waitForFunction(()=>{const el=document.querySelector('[data-count-target="28"]');return Number(el.textContent)>0 && Number(el.textContent)<28;});
  await page.evaluate(()=>document.querySelector('.hero [data-open-enquiry]').click());
  const modalPaused=await number(); await wait(350); assert.equal(await number(),modalPaused);
  await page.keyboard.press('Escape');
  await wait(80);
  const counterScroll=await page.evaluate(()=>scrollY);
  await page.evaluate(()=>scrollTo({top:0,behavior:'instant'})); await wait(50);
  const offscreenPaused=await number(); await wait(350); assert.equal(await number(),offscreenPaused);
  await page.evaluate(top=>scrollTo({top,behavior:'instant'}),counterScroll); await wait(80);
  const other=await browser.newPage(); await other.goto('about:blank'); await other.bringToFront();
  assert.equal(await page.evaluate(()=>document.hidden),true);
  const hiddenPaused=await number(); await wait(350); assert.equal(await number(),hiddenPaused);
  await other.close(); await page.bringToFront();
  await page.waitForFunction(()=>[...document.querySelectorAll('[data-count-target]')].every(el=>el.dataset.counterState==='complete'));
  assert.deepEqual(await page.$$eval('[data-count-target]',els=>els.map(el=>el.textContent)),['28','86','24']);
  await page.evaluate(()=>scrollTo({top:0,behavior:'instant'})); await page.$eval(counterSelector,el=>el.scrollIntoView({block:'center',behavior:'instant'}));
  await wait(400);
  assert.deepEqual(await page.$$eval('[data-count-target]',els=>els.map(el=>el.dataset.counterRuns)),['1','1','1']);
  assert.equal(await page.evaluate(()=>window.__pendingFrames()),0);
  report.interactions.push('Results count through intermediate values to 28, 86 and 24 exactly once; dialog, offscreen and background pause/resume pass');
  await page.setViewport({width:375,height:900,deviceScaleFactor:1});
  await page.goto(base,{waitUntil:'networkidle0'});
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
  await wait(1500);
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
  const touch=await browser.newPage();
  await touch.setViewport({width:375,height:900,deviceScaleFactor:1,isMobile:true,hasTouch:true});
  await touch.goto(base,{waitUntil:'networkidle0'});
  await touch.bringToFront();
  assert.equal(await touch.$eval('html',el=>el.classList.contains('lenis')),false);
  const touchDial=await (await touch.$('[data-audience-dial]')).boundingBox();
  const cx=touchDial.x+touchDial.width/2, cy=touchDial.y+touchDial.height/2;
  await touch.touchscreen.tap(cx,cy);
  assert.equal(await touch.$eval('[data-audience-dial]',el=>el.getAttribute('aria-valuenow')),'75');
  const client=await touch.createCDPSession();
  const swipe=async(dx,dy)=>{
    await client.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:cx,y:cy}]});
    for(let i=1;i<=8;i++){
      await client.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:cx+dx*i/8,y:cy+dy*i/8}]});
      await wait(20);
    }
    await client.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
  };
  await swipe(-80,0);
  assert.equal(await touch.$eval('[data-audience-dial]',el=>el.getAttribute('aria-valuenow')),'25');
  await swipe(0,-170); await wait(350);
  assert.ok(await touch.evaluate(()=>scrollY)>70,'Dial preserves native vertical swiping');
  assert.equal(await touch.$eval('[data-audience-dial]',el=>el.getAttribute('aria-valuenow')),'25');
  const touchParallax=await touch.$eval('[data-hero-object]',el=>new DOMMatrixReadOnly(getComputedStyle(el).transform).m42);
  assert.ok(touchParallax>=0 && touchParallax<=14);
  for(const target of ['28','86','24']){
    await touch.$eval(`[data-count-target="${target}"]`,el=>el.scrollIntoView({block:'center',behavior:'instant'}));
    await touch.waitForFunction(t=>document.querySelector(`[data-count-target="${t}"]`).dataset.counterState==='complete',{},target);
  }
  report.interactions.push('Touch tap/drag works; vertical dial swipes scroll natively; mobile parallax stays under 14px; all three mobile counters finish');
  await touch.close(); await page.bringToFront();
  for (const width of [375,1440]) {
    await page.setViewport({width,height:900,deviceScaleFactor:1});
    await page.goto(base,{waitUntil:'networkidle0'});
    const axe = await new AxePuppeteer(page).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
    report.accessibility.push({width, violations:axe.violations.map(v=>({id:v.id,impact:v.impact,description:v.description,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))}))});
  }
  await page.emulateMediaFeatures([{name:'prefers-reduced-motion',value:'reduce'}]);
  await page.goto(base,{waitUntil:'networkidle0'});
  await page.$eval('[data-count-target]',el=>el.scrollIntoView({block:'center',behavior:'instant'})); await wait(100);
  assert.deepEqual(await page.$$eval('[data-count-target]',els=>els.map(el=>el.textContent)),['28','86','24']);
  assert.equal(await page.$eval('html',el=>el.classList.contains('lenis')),false);
  assert.equal(await page.$eval('[data-hero-object]',el=>getComputedStyle(el).transform),'none');
  assert.equal(await page.evaluate(() => window.__pendingFrames()),0);
  report.interactions.push('Reduced motion shows final figures immediately, disables Lenis/parallax and leaves zero callbacks');
  assert.deepEqual(errors,[]);
  report.consoleErrors = errors;
  await writeFile('artifacts/verification.json', JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));
  assert.ok(report.accessibility.every(a=>a.violations.length===0),'Accessibility violations; see artifacts/verification.json');
} finally { await browser.close(); }
