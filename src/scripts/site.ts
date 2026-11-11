import Lenis from 'lenis';
import { initSignalTuner } from './signal-tuner';
import { initResultsCounters } from './results-counters';
import { initIndustries } from './industries';
import { initProcess } from './process';

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
const fine = window.matchMedia('(hover: hover) and (pointer: fine)');
const hero = document.querySelector<HTMLElement>('.hero-signal');
const heroObject = document.querySelector<HTMLElement>('[data-hero-object]');
const heroCopy = hero?.querySelector<HTMLElement>('.hero-copy');
const modal = document.querySelector<HTMLDialogElement>('#enquiry-modal')!;
const header = document.querySelector<HTMLElement>('.site-header')!;
const menu = document.querySelector<HTMLDialogElement>('#mobile-menu')!;
const menuButton = document.querySelector<HTMLButtonElement>('.menu-toggle')!;
let lastFocus: HTMLElement | null = null;
let lockedScroll = 0;
let lenis: Lenis | null = null;
let scrollFrame = 0;
let heroVisible = true;
let scrollClock = 0;
let lastFrameTime = 0;
let heroDistance = 800;

// Lenis is driven only while the visitor is scrolling. No persistent ticker.
function stopScrollFrame() {
  cancelAnimationFrame(scrollFrame);
  scrollFrame = 0;
  lastFrameTime = 0;
}
function positionHero() {
  if (!heroObject || !heroCopy || !heroVisible || document.hidden || modal.open || menu.open) return;
  const progress = reduced.matches ? 0 : Math.max(0, Math.min(1, window.scrollY / heroDistance));
  // Two flat layers, 42px/12px maximum desktop displacement; 14px on touch.
  heroObject.style.transform = `translate3d(0,${(progress * (fine.matches ? 42 : 14)).toFixed(2)}px,0)`;
  heroCopy.style.transform = `translate3d(0,${(progress * (fine.matches ? -12 : 0)).toFixed(2)}px,0)`;
}
function scrollTick(time: number) {
  scrollFrame = 0;
  if (document.hidden || reduced.matches || modal.open || menu.open) { lastFrameTime = 0; return; }
  // Lenis receives elapsed *active* time. Passing wall time after an idle pause
  // makes its first frame finish the entire scroll and was the old jump bug.
  scrollClock += lastFrameTime ? Math.min(50, time - lastFrameTime) : 16.667;
  lastFrameTime = time;
  lenis?.raf(scrollClock);
  positionHero();
  if (lenis?.isScrolling === 'smooth') scrollFrame = requestAnimationFrame(scrollTick);
  else lastFrameTime = 0;
}
function startScrollFrame() {
  if (!scrollFrame && !document.hidden && !reduced.matches && !modal.open && !menu.open) scrollFrame = requestAnimationFrame(scrollTick);
}
function configureScroll() {
  stopScrollFrame();
  lenis?.destroy();
  lenis = null;
  scrollClock = 0;
  if (!reduced.matches && fine.matches) {
    lenis = new Lenis({ autoRaf: false, lerp: .085, smoothWheel: true, syncTouch: false, wheelMultiplier: .95 });
    lenis.on('virtual-scroll', () => queueMicrotask(startScrollFrame));
  }
  positionHero();
}
configureScroll();
window.addEventListener('scroll', () => {
  if (heroVisible || lenis?.isScrolling === 'smooth') startScrollFrame();
}, { passive: true });

function goTo(target: HTMLElement) {
  if (lenis && !reduced.matches) {
    // Lenis reads the same CSS scroll margin/padding as native navigation.
    lenis.scrollTo(target, { duration: 1.25, easing: t => 1 - Math.pow(1-t, 4), lerp: 0 });
    startScrollFrame();
  } else {
    target.scrollIntoView({ behavior: reduced.matches ? 'instant' : 'smooth' });
  }
}

function lockPage() {
  lockedScroll = window.scrollY;
  stopScrollFrame();
  lenis?.stop();
  const scrollbar = window.innerWidth - document.documentElement.clientWidth;
  document.body.style.position = 'fixed';
  document.body.style.top = `-${lockedScroll}px`;
  document.body.style.width = '100%';
  document.body.style.paddingRight = `${scrollbar}px`;
  // The pinned header spans the viewport, which widens when the scrollbar goes; keep it still.
  header.style.paddingRight = `${scrollbar}px`;
  syncMotionState();
}
function unlockPage() {
  document.body.style.position = '';
  document.body.style.top = '';
  document.body.style.width = '';
  document.body.style.paddingRight = '';
  header.style.paddingRight = '';
  window.scrollTo({ top: lockedScroll, behavior: 'instant' });
  lenis?.start();
  lastFocus?.focus({ preventScroll: true });
  syncMotionState();
}
function openEnquiry(trigger: HTMLElement) {
  if (menu.open) menu.close();
  lastFocus = trigger;
  const service = trigger.dataset.service;
  if (service) {
    const field = modal.querySelector<HTMLSelectElement>('[name=service]')!;
    field.value = service;
  }
  modal.showModal();
  lockPage();
}
document.querySelectorAll<HTMLElement>('[data-open-enquiry]').forEach(trigger => {
  trigger.addEventListener('click', event => {
    event.preventDefault();
    openEnquiry(trigger);
  });
});
document.querySelector('[data-close-enquiry]')?.addEventListener('click', () => modal.close());
modal.addEventListener('click', event => {
  if (event.target !== modal) return;
  const rect = modal.getBoundingClientRect();
  if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) modal.close();
});
modal.addEventListener('close', unlockPage);

menuButton.addEventListener('click', () => {
  lastFocus = menuButton;
  menu.showModal();
  menuButton.setAttribute('aria-expanded', 'true');
  lockPage();
});
document.querySelector('[data-close-menu]')?.addEventListener('click', () => menu.close());
menu.addEventListener('close', () => {
  menuButton.setAttribute('aria-expanded', 'false');
  unlockPage();
});
window.matchMedia('(min-width: 768px)').addEventListener('change', event => {
  if (event.matches && menu.open) menu.close();
});

document.querySelectorAll<HTMLAnchorElement>('a[href^="#"]:not([data-open-enquiry])').forEach(link => {
  link.addEventListener('click', event => {
    const id = link.getAttribute('href')!.slice(1);
    const target = document.getElementById(id);
    if (!target) return;
    event.preventDefault();
    if (menu.open) {
      menu.close();
      // The dialog close event restores scroll before anchor navigation.
      setTimeout(() => { goTo(target); target.tabIndex = -1; target.focus({ preventScroll: true }); }, 0);
    } else goTo(target);
    history.pushState(null, '', `#${id}`);
  });
});

// Software examples: manual tabs, with native focus and arrow-key behaviour.
const tabs = [...document.querySelectorAll<HTMLButtonElement>('[data-product]')];
const highlight = document.querySelector<HTMLElement>('.option-highlight')!;
function positionHighlight(tab: HTMLElement) {
  highlight.style.height = `${tab.offsetHeight}px`;
  highlight.style.transform = `translateY(${tab.offsetTop}px)`;
}
function selectProduct(id: string, focus = false) {
  tabs.forEach(tab => {
    const selected = tab.dataset.product === id;
    tab.setAttribute('aria-selected', String(selected));
    tab.tabIndex = selected ? 0 : -1;
    document.getElementById(`panel-${tab.dataset.product}`)!.hidden = !selected;
    if (selected) { positionHighlight(tab); if (focus) tab.focus(); }
  });
}
tabs.forEach((tab, i) => {
  tab.addEventListener('click', () => selectProduct(tab.dataset.product!));
  tab.addEventListener('pointerenter', () => { if (fine.matches) positionHighlight(tab); });
  tab.addEventListener('keydown', event => {
    let next = i;
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (i + 1) % tabs.length;
    else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (i + tabs.length - 1) % tabs.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = tabs.length - 1;
    else return;
    event.preventDefault();
    selectProduct(tabs[next].dataset.product!, true);
  });
});
document.querySelector('.product-options')?.addEventListener('pointerleave', () => positionHighlight(tabs.find(t => t.getAttribute('aria-selected') === 'true')!));
const tabResize = new ResizeObserver(() => positionHighlight(tabs.find(t => t.getAttribute('aria-selected') === 'true')!));
if (tabs[0]) tabResize.observe(tabs[0].parentElement!);
document.querySelectorAll<HTMLElement>('[data-select-product]').forEach(link => link.addEventListener('click', () => selectProduct(link.dataset.selectProduct!)));

// Scroll and motion lifecycle share visibility, dialogs and reduced-motion state.
function syncMotionState() {
  document.dispatchEvent(new Event('clickfield:motion-state'));
}
if (hero) {
  const heroObserver = new IntersectionObserver(([entry]) => {
    heroVisible = entry.isIntersecting;
    const promote = heroVisible && !reduced.matches ? 'transform' : 'auto';
    if (heroObject) heroObject.style.willChange = promote;
    if (heroCopy) heroCopy.style.willChange = promote;
    if (heroVisible) positionHero();
  });
  heroObserver.observe(hero);
  new ResizeObserver(() => {
    heroDistance = hero.offsetTop + hero.offsetHeight;
    positionHero();
  }).observe(hero);
}
document.addEventListener('visibilitychange', () => {
  if (document.hidden) { stopScrollFrame(); lenis?.stop(); }
  else { lenis?.start(); positionHero(); }
});
reduced.addEventListener('change', () => {
  configureScroll();
  if (reduced.matches) {
    if (heroObject) { heroObject.style.transform = 'none'; heroObject.style.willChange = 'auto'; }
    if (heroCopy) { heroCopy.style.transform = 'none'; heroCopy.style.willChange = 'auto'; }
  }
});
fine.addEventListener('change', configureScroll);
window.addEventListener('pagehide', stopScrollFrame);
window.addEventListener('pageshow', () => { lenis?.start(); positionHero(); });
initSignalTuner();
initResultsCounters(reduced);
initIndustries(reduced, fine);
initProcess(reduced);
document.querySelectorAll<HTMLElement>('.magnetic').forEach(button => {
  button.addEventListener('pointermove', event => {
    if (!fine.matches || reduced.matches) return;
    const rect = button.getBoundingClientRect();
    button.style.transform = `translate(${(event.clientX - rect.left - rect.width / 2) * .035}px, ${(event.clientY - rect.top - rect.height / 2) * .08}px)`;
  });
  button.addEventListener('pointerleave', () => { button.style.transform = ''; });
  button.addEventListener('pointerdown', () => { button.style.transform = 'translateY(1px)'; });
  button.addEventListener('pointerup', () => { button.style.transform = ''; });
});

document.querySelectorAll<HTMLFormElement>('[data-enquiry-form]').forEach(form => {
  let pending = false;
  const message = form.querySelector<HTMLElement>('[data-form-message]')!;
  const submit = form.querySelector<HTMLButtonElement>('[type=submit]')!;
  const text = form.querySelector<HTMLElement>('[data-submit-text]')!;
  form.addEventListener('input', event => {
    (event.target as HTMLInputElement).removeAttribute('aria-invalid');
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (pending || !form.reportValidity()) return;
    pending = true;
    submit.disabled = true;
    form.setAttribute('aria-busy', 'true');
    text.textContent = 'Sending…';
    message.hidden = true;
    const controller = new AbortController();
    // Generous, because the server saves the lead and then waits on Gmail. Giving
    // up at 15s showed an error for enquiries that had in fact arrived, and the
    // visitor then either sent it twice or assumed we never got it.
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('The enquiry could not be sent. Please email info@clickfieldmedia.com or try again later.');
      const result = await response.json();
      if (!response.ok || !result.ok) {
        const fieldName = result.field || result.error?.field;
        if (fieldName && /^[a-z]+$/.test(fieldName)) {
          const field = form.querySelector<HTMLInputElement>(`[name="${fieldName}"]`);
          field?.setAttribute('aria-invalid', 'true');
          field?.focus();
        }
        throw new Error(result.message || (typeof result.error === 'string' ? result.error : result.error?.message) || 'Your enquiry could not be sent. Please try again or email info@clickfieldmedia.com.');
      }
      // Success lives on its own page, which hands straight off to Calendly, and
      // the back button can't land someone on a form they have already sent.
      window.location.assign(result.redirect ?? '/thank-you/');
      return;
    } catch (error) {
      message.textContent = error instanceof Error && error.name !== 'AbortError' && error.message !== 'Failed to fetch' ? error.message : 'The connection timed out. Please try again, or email info@clickfieldmedia.com.';
      message.dataset.state = 'error';
      message.hidden = false;
    } finally {
      clearTimeout(timeout);
      pending = false;
      submit.disabled = false;
      form.removeAttribute('aria-busy');
      text.textContent = 'Send enquiry';
    }
  });
});

// At the top the bar sits on the page as it always did; the moment the page moves it turns to
// glass. Skipped while a dialog pins the body, which zeroes scrollY and would flash the bar back.
// Clear glass shows whatever is behind it, and one section can be dark on one side and light on
// the other, so the logo and the links each read the surface directly behind them.
const lumaBehind = (x: number, y: number) => {
  let node = document.elementsFromPoint(x, y).find(el => !header.contains(el) && el !== document.body && el !== document.documentElement) ?? null;
  while (node && node !== document.body) {
    const fill = getComputedStyle(node).backgroundColor;
    const [r, g, b, a = 1] = (fill.startsWith('rgb') ? fill.match(/[\d.]+/g) ?? [] : []).map(Number);
    if (r !== undefined && a > .5) return .299 * r + .587 * g + .114 * b;
    node = node.parentElement;
  }
  return 255;
};
const darkBehind = (el: Element | null) => {
  const box = el?.getBoundingClientRect();
  return !!box && box.width > 0 && lumaBehind(box.left + box.width / 2, header.offsetHeight / 2) < 145;
};
const logoArea = header.querySelector('.brand-link');
const linkAreas = [header.querySelector('.desktop-nav'), header.querySelector('.menu-toggle')];
const stick = () => {
  if (document.body.style.position === 'fixed') return;
  const stuck = window.scrollY > 12;
  header.classList.toggle('is-stuck', stuck);
  header.classList.toggle('logo-on-dark', stuck && darkBehind(logoArea));
  header.classList.toggle('links-on-dark', stuck && linkAreas.some(darkBehind));
};
stick();
window.addEventListener('scroll', stick, { passive: true });
window.addEventListener('resize', stick, { passive: true });
// Transitions switch on after the first paint, so reloading mid-page doesn't play the change.
requestAnimationFrame(() => header.classList.add('is-ready'));
