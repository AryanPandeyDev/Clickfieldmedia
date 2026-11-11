type Gsap = typeof import('gsap').gsap;

// One colour bar glides between the industry rows and a card trails the pointer.
// Only with a mouse and without reduced motion; otherwise the CSS hover colour
// on each row is the whole effect and the details stay visible under the names.
export function initIndustries(reduced: MediaQueryList, fine: MediaQueryList) {
  const board = document.querySelector<HTMLElement>('[data-industries]');
  if (!board) return;
  const glide = board.querySelector<HTMLElement>('.industry-glide')!;
  const card = board.querySelector<HTMLElement>('.industry-card')!;
  const cardLine = card.querySelector<HTMLElement>('.industry-card-line')!;
  const cardTags = card.querySelector<HTMLElement>('.industry-card-tags')!;
  const rows = [...board.querySelectorAll<HTMLElement>('.industry')];
  const part = (row: HTMLElement, name: string) => row.querySelector<HTMLElement>(name)!;

  let gsap: Gsap | undefined;
  let loading: Promise<Gsap> | undefined;
  let near = false;
  let active: HTMLElement | null = null;
  let moveX: ((value: number) => void) | undefined;
  let moveY: ((value: number) => void) | undefined;
  const allowed = () => near && fine.matches && !reduced.matches;
  const on = () => board.classList.contains('is-gliding');

  function reset() {
    active?.classList.remove('is-active');
    active?.removeAttribute('data-cursor-surface');
    active = null;
    if (!gsap) return;
    gsap.killTweensOf([glide, card, ...rows.flatMap(row => [part(row, '.industry-copy'), part(row, '.industry-icon'), part(row, '.industry-arrow')])]);
    gsap.set([glide, card, ...rows.flatMap(row => [part(row, '.industry-copy'), part(row, '.industry-icon'), part(row, '.industry-arrow')])], { clearProps: 'all' });
  }

  async function sync() {
    if (!allowed()) { if (on()) { reset(); board!.classList.remove('is-gliding'); } return; }
    loading ??= import('gsap').then(module => module.gsap);
    gsap = await loading;
    if (!allowed() || on()) return;
    moveX ??= gsap.quickTo(card, 'x', { duration: .55, ease: 'power3.out' });
    moveY ??= gsap.quickTo(card, 'y', { duration: .55, ease: 'power3.out' });
    board!.classList.add('is-gliding');
  }

  function place(event: PointerEvent, instant = false) {
    if (!gsap || !moveX || !moveY) return;
    const box = board!.getBoundingClientRect();
    const px = event.clientX - box.left, py = event.clientY - box.top;
    // Sits to the right of the pointer, and flips to the left near the edge.
    const x = px + 28 + card.offsetWidth > box.width ? px - 28 - card.offsetWidth : px + 28;
    const y = Math.min(py + 22, box.height - card.offsetHeight);
    if (instant) gsap.set(card, { x, y });
    else { moveX(x); moveY(y); }
    gsap.ticker.wake();
  }

  function activate(row: HTMLElement, event: PointerEvent) {
    if (!gsap || !on() || row === active) return;
    const first = !active;
    if (active) {
      active.classList.remove('is-active');
      active.removeAttribute('data-cursor-surface');
      gsap.to(part(active, '.industry-copy'), { x: 0, duration: .45, ease: 'power3.out', overwrite: 'auto' });
      gsap.to(part(active, '.industry-arrow'), { opacity: 0, x: -12, y: 12, duration: .25, overwrite: 'auto' });
    }
    active = row;
    row.classList.add('is-active');
    row.dataset.cursorSurface = row.dataset.surface;
    const tint = getComputedStyle(row).getPropertyValue('--tint').trim();
    const to = { y: row.offsetTop, height: row.offsetHeight, backgroundColor: tint };
    if (first) {
      gsap.set(glide, { ...to, transformOrigin: 'left center' });
      gsap.fromTo(glide, { scaleX: 0 }, { scaleX: 1, duration: .6, ease: 'power3.out', overwrite: 'auto' });
      place(event, true);
      gsap.fromTo(card, { autoAlpha: 0, scale: .92 }, { autoAlpha: 1, scale: 1, duration: .35, ease: 'power2.out', overwrite: 'auto' });
    } else {
      gsap.to(glide, { ...to, scaleX: 1, duration: .5, ease: 'power3.out', overwrite: 'auto' });
      gsap.fromTo(card, { scale: .96 }, { autoAlpha: 1, scale: 1, duration: .3, ease: 'power2.out', overwrite: 'auto' });
    }
    gsap.to(part(row, '.industry-copy'), { x: 14, duration: .5, ease: 'power3.out', overwrite: 'auto' });
    gsap.fromTo(part(row, '.industry-arrow'), { opacity: 0, x: -12, y: 12 }, { opacity: 1, x: 0, y: 0, duration: .45, ease: 'back.out(2.2)', overwrite: 'auto' });
    gsap.fromTo(part(row, '.industry-icon'), { rotate: -16, scale: .8 }, { rotate: 0, scale: 1, duration: .6, ease: 'back.out(3)', overwrite: 'auto' });
    cardLine.textContent = part(row, '.industry-line').textContent;
    cardTags.innerHTML = part(row, '.industry-tags').innerHTML;
    gsap.ticker.wake();
  }

  function leave() {
    if (!gsap || !active) return;
    active.classList.remove('is-active');
    active.removeAttribute('data-cursor-surface');
    gsap.to(part(active, '.industry-copy'), { x: 0, duration: .45, ease: 'power3.out', overwrite: 'auto' });
    gsap.to(part(active, '.industry-arrow'), { opacity: 0, duration: .2, overwrite: 'auto' });
    active = null;
    gsap.set(glide, { transformOrigin: 'right center' });
    gsap.to(glide, { scaleX: 0, duration: .45, ease: 'power3.inOut', overwrite: 'auto' });
    gsap.to(card, { autoAlpha: 0, scale: .94, duration: .2, overwrite: 'auto' });
    gsap.ticker.wake();
  }

  rows.forEach(row => row.addEventListener('pointerenter', event => { if (event.pointerType === 'mouse') activate(row, event); }));
  board.addEventListener('pointermove', event => { if (event.pointerType === 'mouse' && active) place(event); });
  board.addEventListener('pointerleave', leave);

  // The animation library loads only once the section is getting close.
  new IntersectionObserver(entries => { near = entries.some(entry => entry.isIntersecting) || near; void sync(); }, { rootMargin: '600px 0px' }).observe(board);
  reduced.addEventListener('change', () => void sync());
  fine.addEventListener('change', () => void sync());
  window.addEventListener('resize', () => { if (active) leave(); }, { passive: true });
}
