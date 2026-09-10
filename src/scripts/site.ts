import Lenis from 'lenis';
let gsap: typeof import('gsap').gsap | undefined;

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
const fine = window.matchMedia('(hover: hover) and (pointer: fine)');
const tablet = document.querySelector<HTMLElement>('[data-dashboard]');
const modal = document.querySelector<HTMLDialogElement>('#enquiry-modal')!;
const menu = document.querySelector<HTMLDialogElement>('#mobile-menu')!;
const menuButton = document.querySelector<HTMLButtonElement>('.menu-toggle')!;
let lastFocus: HTMLElement | null = null;
let lockedScroll = 0;
let lenis: Lenis | null = null;
let scrollFrame = 0;
let tabletVisible = true;

// Lenis is driven only while the visitor is scrolling. No persistent ticker.
function stopScrollFrame() {
  cancelAnimationFrame(scrollFrame);
  scrollFrame = 0;
}
function scrollTick(time: number) {
  scrollFrame = 0;
  if (!lenis || document.hidden || modal.open || menu.open) return;
  lenis.raf(time);
  if (lenis.isScrolling === 'smooth') scrollFrame = requestAnimationFrame(scrollTick);
}
function startScrollFrame() {
  if (!scrollFrame && lenis && !document.hidden) scrollFrame = requestAnimationFrame(scrollTick);
}
function configureScroll() {
  stopScrollFrame();
  lenis?.destroy();
  lenis = null;
  if (!reduced.matches && fine.matches) {
    lenis = new Lenis({ autoRaf: false, duration: .8, smoothWheel: true, syncTouch: false });
  }
}
configureScroll();
window.addEventListener('wheel', startScrollFrame, { passive: true });

function goTo(target: HTMLElement) {
  if (lenis && !reduced.matches) {
    lenis.scrollTo(target, { offset: -30 });
    startScrollFrame();
  } else {
    target.scrollIntoView({ behavior: reduced.matches || !fine.matches ? 'instant' : 'smooth' });
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
  syncDashboard();
}
function unlockPage() {
  document.body.style.position = '';
  document.body.style.top = '';
  document.body.style.width = '';
  document.body.style.paddingRight = '';
  window.scrollTo({ top: lockedScroll, behavior: 'instant' });
  lenis?.start();
  lastFocus?.focus({ preventScroll: true });
  syncDashboard();
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

// Finite content changes. A fixed pool of rows moves exclusively by transform.
// Nothing is inserted into layout and all data is populated in the server HTML.
const rows = tablet ? [...tablet.querySelectorAll<HTMLElement>('[data-lead-row]')] : [];
const arrivals = [
  ['Meera Joshi', 'M', '+91 •••• 9284', 'Meta'],
  ['Arjun Singh', 'A', '+91 •••• 7513', 'Google'],
  ['Isha Gupta', 'I', '+91 •••• 3067', 'Website'],
  ['Dev Malhotra', 'D', '+91 •••• 8142', 'Google'],
  ['Sara Khan', 'S', '+91 •••• 5726', 'Meta'],
  ['Vivek Nair', 'V', '+91 •••• 6908', 'Website'],
];
let arrivalIndex = 0;
let leadTimer: ReturnType<typeof setTimeout> | undefined;
let tintTimer: ReturnType<typeof setTimeout> | undefined;
let manuallyPaused = false;
let rowOrder = [...rows];
const pauseButton = document.querySelector<HTMLButtonElement>('[data-toggle-dashboard]')!;
function canUpdate() { return tabletVisible && !document.hidden && !reduced.matches && !manuallyPaused && !modal.open && !menu.open && arrivalIndex < arrivals.length; }
function settleRows() {
  gsap?.killTweensOf(rows);
  rowOrder.forEach((row, i) => {
    row.style.transform = `translateY(${i * 100}%)`;
    row.classList.remove('is-new');
  });
  gsap?.ticker.sleep();
}
async function nextLead() {
  leadTimer = undefined;
  if (!canUpdate()) return;
  // The animation engine is not part of the initial download or reduced-motion path.
  const engine = gsap ??= (await import('gsap')).gsap;
  engine.config({ autoSleep: 1 });
  if (!canUpdate()) { engine.ticker.sleep(); return; }
  const [name, initial, phone, source] = arrivals[arrivalIndex];
  const recycled = rowOrder.pop()!;
  recycled.querySelector('.lead-avatar')!.textContent = initial;
  recycled.querySelector('.lead-person b')!.textContent = name;
  recycled.querySelector('.lead-person>span')!.textContent = phone;
  recycled.querySelector('.lead-source')!.textContent = source;
  const status = recycled.querySelector('.lead-status')!;
  status.textContent = 'New';
  status.classList.remove('assigned');
  recycled.classList.add('is-new');
  // Set the recycled row above the clipped viewport, then translate the pool.
  engine.set(recycled, { y: 0, yPercent: -100 });
  rowOrder.unshift(recycled);
  arrivalIndex++;
  tablet!.dataset.arrivals = String(arrivalIndex);
  tablet!.querySelector('[data-lead-count]')!.textContent = String(24 + arrivalIndex);
  rowOrder.forEach((row, i) => engine.to(row, { y: 0, yPercent: i * 100, duration: .52, ease: 'power2.out', overwrite: true, onComplete: i === rowOrder.length - 1 ? () => engine.ticker.sleep() : undefined }));
  tintTimer = setTimeout(() => { recycled.classList.remove('is-new'); tintTimer = undefined; }, 1600);
  updatePauseLabel();
  if (canUpdate()) leadTimer = setTimeout(nextLead, 4700);
}
function updatePauseLabel() {
  if (reduced.matches) {
    pauseButton.hidden = true;
  } else {
    pauseButton.hidden = false;
    pauseButton.textContent = arrivalIndex >= arrivals.length ? 'Replay ↻' : manuallyPaused ? 'Play ▷' : 'Pause Ⅱ';
    pauseButton.setAttribute('aria-label', arrivalIndex >= arrivals.length ? 'Replay dashboard updates' : manuallyPaused ? 'Resume dashboard updates' : 'Pause dashboard updates');
  }
}
function syncDashboard() {
  clearTimeout(leadTimer);
  clearTimeout(tintTimer);
  leadTimer = undefined;
  tintTimer = undefined;
  if (canUpdate()) leadTimer = setTimeout(nextLead, 4700);
  else settleRows();
  updatePauseLabel();
}
pauseButton.addEventListener('click', () => {
  if (arrivalIndex >= arrivals.length) { arrivalIndex = 0; manuallyPaused = false; tablet!.dataset.arrivals = '0'; tablet!.querySelector('[data-lead-count]')!.textContent = '24'; }
  else manuallyPaused = !manuallyPaused;
  syncDashboard();
});
if (tablet) {
  const observer = new IntersectionObserver(([entry]) => {
    tabletVisible = entry.isIntersecting && entry.intersectionRatio >= .2;
    syncDashboard();
  }, { threshold: [0, .2] });
  observer.observe(tablet);
}

// The tablet follows current scroll position with no easing or trailing loop.
function positionTablet() {
  if (!tablet || !tabletVisible || reduced.matches || modal.open || menu.open) return;
  const angle = fine.matches ? Math.min(1.5, window.scrollY / 230) : 0;
  tablet.style.transform = `rotate(${angle}deg)`;
}
window.addEventListener('scroll', positionTablet, { passive: true });
document.addEventListener('visibilitychange', () => {
  if (document.hidden) { stopScrollFrame(); lenis?.stop(); }
  else lenis?.start();
  syncDashboard();
});
reduced.addEventListener('change', () => {
  configureScroll();
  if (tablet) tablet.style.transform = 'none';
  syncDashboard();
});
fine.addEventListener('change', configureScroll);
window.addEventListener('pagehide', () => { stopScrollFrame(); clearTimeout(leadTimer); clearTimeout(tintTimer); settleRows(); });
window.addEventListener('pageshow', () => syncDashboard());

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
    const timeout = setTimeout(() => controller.abort(), 15000);
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
      message.textContent = 'Enquiry received. We’ll review your details and get back to you about the work.';
      message.dataset.state = 'success';
      message.hidden = false;
      form.reset();
      message.focus();
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
