export function initResultsCounters(reduced: MediaQueryList) {
  const nodes=[...document.querySelectorAll<HTMLElement>('[data-count-target]')];
  type Tween = ReturnType<typeof import('gsap').gsap.to>;
  let engine: typeof import('gsap').gsap | undefined;
  let loading: Promise<typeof import('gsap').gsap> | undefined;
  const records=nodes.map(node=>({node,target:Number(node.dataset.countTarget),visible:false,started:false,done:false,tween:null as Tween|null}));
  // HTML retains the final values for no-JS visits; prepare offscreen counters
  // before the deferred animation library arrives so the final value never flashes.
  if (!reduced.matches) records.forEach(record=>{record.node.textContent='0';});
  const unavailable=()=>document.hidden || reduced.matches || !!document.querySelector('dialog[open]');
  // A newly created GSAP tween isn't isActive() until its first render.
  // Include queued, unpaused tweens so they can actually receive that first frame.
  function sleepIfIdle(){if(!records.some(r=>!r.done && r.tween && !r.tween.paused())) engine?.ticker.sleep();}
  async function sync() {
    for(const record of records) {
      if(record.done) continue;
      if(reduced.matches) {
        record.tween?.kill(); record.node.textContent=String(record.target);
        record.done=true;record.node.dataset.counterState='complete';
      } else if(unavailable() || !record.visible) record.tween?.pause();
      else if(record.started) record.tween?.resume();
      else {
        loading ??= import('gsap').then(module=>module.gsap);
        const gsap=engine=await loading;
        if(record.started || record.done || unavailable() || !record.visible) continue;
        record.started=true;
        record.node.dataset.counterState='running';
        record.node.dataset.counterRuns=String(Number(record.node.dataset.counterRuns||0)+1);
        const number={value:0};
        record.node.textContent='0';
        record.tween=gsap.to(number,{value:record.target,duration:1.45,ease:'power2.out',onUpdate:()=>{record.node.textContent=String(Math.round(number.value));},onComplete:()=>{
          record.node.textContent=String(record.target);record.done=true;record.node.dataset.counterState='complete';sleepIfIdle();
        }});
        gsap.ticker.wake();
      }
    }
    sleepIfIdle();
  }
  const observer=new IntersectionObserver(entries=>{
    entries.forEach(entry=>{const record=records.find(r=>r.node===entry.target);if(record) record.visible=entry.isIntersecting && entry.intersectionRatio>=.6;});
    void sync();
  },{rootMargin:'0px 0px -12% 0px',threshold:.6});
  records.forEach(record=>observer.observe(record.node));
  document.addEventListener('visibilitychange',()=>void sync());
  reduced.addEventListener('change',()=>void sync());
  document.addEventListener('clickfield:motion-state',()=>void sync());
  window.addEventListener('pagehide',()=>{records.forEach(record=>record.tween?.pause());sleepIfIdle();});
  window.addEventListener('pageshow',()=>void sync());
}
