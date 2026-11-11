type Gsap = typeof import('gsap').gsap;

// The process steps read like an order tracker: scrolling through the list fills
// each step's line in turn, a dot rides the tip, finished steps get a tick and the
// ones still ahead wait faded. Scrolling back up rewinds it. Skipped entirely with
// reduced motion, which leaves every step showing as normal.
export function initProcess(reduced: MediaQueryList) {
  const list = document.querySelector<HTMLElement>('[data-process]');
  if (!list) return;
  const steps = [...list.querySelectorAll<HTMLElement>(':scope > li')];
  const clamp = (value: number) => Math.max(0, Math.min(1, value));

  let gsap: Gsap | undefined;
  let loading: Promise<Gsap> | undefined;
  let tween: gsap.core.Tween | undefined;
  let near = false;
  let shown = -1;

  function render(progress: number) {
    const along = progress * steps.length;
    const current = progress >= .999 ? steps.length : Math.min(steps.length - 1, Math.floor(along));
    steps.forEach((step, i) => {
      const fill = clamp(along - i);
      step.style.setProperty('--fill', fill.toFixed(4));
      // The dot grows in as a line starts and shrinks away as it reaches the next number.
      step.style.setProperty('--dot', (i === current ? Math.min(1, fill * 9, (1 - fill) * 9) : 0).toFixed(3));
    });
    if (current === shown) return;
    shown = current;
    steps.forEach((step, i) => {
      step.classList.toggle('is-done', i < current);
      step.classList.toggle('is-current', i === current);
      step.classList.toggle('is-upcoming', i > current);
    });
  }

  function stop() {
    tween?.scrollTrigger?.kill();
    tween?.kill();
    tween = undefined;
    shown = -1;
    list!.classList.remove('is-tracking');
    steps.forEach(step => {
      step.classList.remove('is-done', 'is-current', 'is-upcoming');
      step.style.removeProperty('--fill');
      step.style.removeProperty('--dot');
    });
  }

  async function sync() {
    if (reduced.matches) { stop(); return; }
    if (!near || tween) return;
    loading ??= Promise.all([import('gsap'), import('gsap/ScrollTrigger')]).then(([core, plugin]) => {
      core.gsap.registerPlugin(plugin.ScrollTrigger);
      return core.gsap;
    });
    gsap = await loading;
    if (reduced.matches || tween) return;
    const state = { progress: 0 };
    list!.classList.add('is-tracking');
    tween = gsap.to(state, {
      progress: 1,
      ease: 'none',
      onUpdate: () => render(state.progress),
      // A little scrub lag so the line glides after the scroll instead of stepping with it.
      scrollTrigger: { trigger: list, start: 'top 72%', end: 'bottom 45%', scrub: 1.1 },
    });
    render(0);
  }

  new IntersectionObserver(entries => { near = entries.some(entry => entry.isIntersecting) || near; void sync(); }, { rootMargin: '800px 0px' }).observe(list);
  reduced.addEventListener('change', () => void sync());
}
