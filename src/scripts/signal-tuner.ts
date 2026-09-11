export function initSignalTuner() {
  const consoleEl = document.querySelector<HTMLElement>('[data-signal-tuner]');
  if (!consoleEl) return;
  const dial = consoleEl.querySelector<HTMLElement>('[data-audience-dial]')!;
  const grab = consoleEl.querySelector<HTMLElement>('[data-dial-grab]')!;
  const pointer =consoleEl.querySelector<HTMLElement>('[data-dial-pointer]')!;
  const waveform = consoleEl.querySelector<SVGPathElement>('[data-signal-wave]')!;
  const arc = consoleEl.querySelector<SVGPathElement>('[data-signal-arc]')!;
  const ticks = [...consoleEl.querySelectorAll('[data-dial-tick]')];
  const bars = [...consoleEl.querySelectorAll('[data-signal-bar]')];
  const stops = [...consoleEl.querySelectorAll('[data-stage-stop]')];
  const controls = [...consoleEl.querySelectorAll<HTMLButtonElement>('[data-tune-stage]')];
  const label = consoleEl.querySelector('[data-audience-stage]')!;
  const status = consoleEl.querySelector('[data-signal-status]')!;
  const message = consoleEl.querySelector('[data-signal-message]')!;
  const lockLabel = consoleEl.querySelector('[data-lock-label]')!;
  const stages = [
    {name:'Unknown', status:'Through the noise', message:'Start with the people who matter.'},
    {name:'Aware', status:'Getting noticed', message:'Give the right people a reason to look.'},
    {name:'Interested', status:'Finding the fit', message:'A message starts to resonate.'},
    {name:'Connected', status:'Connecting', message:'Trust turns attention into intent.'},
    {name:'Ready to act', status:'Audience found', message:'Now give that interest a next step.'},
  ];
  let value = 50;
  let lastStage = -1;
  let drag: {id:number; el:HTMLElement; x:number; y:number; start:number; pos:number; angle:number|null; moved:boolean; fromRing:boolean} | null = null;
  function tune(next: number) {
    value = Math.round(Math.max(0, Math.min(100, next)));
    const progress = value / 100;
    const stage = Math.round(value / 25);
    pointer.style.transform = `rotate(${-134 + progress * 268}deg)`;
    arc.setAttribute('stroke-dasharray', `${value} 100`);
    ticks.forEach((tick,i) => tick.classList.toggle('tick-active', i <= progress * (ticks.length-1)));
    bars.forEach((bar,i) => bar.classList.toggle('lit', i < Math.max(1,Math.round(progress*9))));
    // Recalculate only in response to input: no canvas or ambient frame loop.
    const points = Array.from({length:101},(_,i) => {
      const x = i*4;
      const amplitude = 5 + progress*10;
      const noise = (Math.sin(i*2.1) + Math.cos(i*1.3)*.6) * (1-progress)*7;
      const y = 26 + Math.sin(i*.19)*(amplitude+Math.sin(i*.032)*5)+noise;
      return `${i?'L':'M'}${x.toFixed(1)},${y.toFixed(1)}`;
    });
    waveform.setAttribute('d', points.join(' '));
    dial.setAttribute('aria-valuenow', String(value));
    dial.setAttribute('aria-valuetext', `${stages[stage].name}, stage ${stage+1} of 5`);
    if (stage === lastStage) return;
    lastStage = stage;
    consoleEl!.dataset.stage = String(stage);
    label.textContent = stages[stage].name;
    status.textContent = stages[stage].status;
    message.textContent = stages[stage].message;
    lockLabel.textContent = stage === 4 ? 'Signal locked' : 'Lock signal';
    controls.forEach(control => control.setAttribute('aria-pressed', String(Number(control.dataset.tuneStage) === stage)));
    stops.forEach((stop,i) => stop.classList.toggle('selected',i===stage));
  }
  dial.addEventListener('keydown',event => {
    let next = value;
    if (['ArrowRight','ArrowUp'].includes(event.key)) next = value+25;
    else if (['ArrowLeft','ArrowDown'].includes(event.key)) next=value-25;
    else if (event.key==='Home') next=0;
    else if (event.key==='End') next=100;
    else if (event.key==='PageUp') next=value+50;
    else if (event.key==='PageDown') next=value-50;
    else if (event.key==='Enter'||event.key===' ') next=value>=100?0:value+25;
    else return;
    // Only keys that actually turn the dial count as keyboard use; Shift and friends don't.
    delete dial.dataset.pointerFocus;
    event.preventDefault();
    tune(next);
  });
  // The full sweep is 268 degrees over 0-100, so one unit is 2.68 degrees of turn.
  const DEG_PER_UNIT = 2.68;
  const angleAt = (x: number, y: number) => {
    const r = dial.getBoundingClientRect();
    const dx = x - (r.left + r.width / 2);
    const dy = y - (r.top + r.height / 2);
    return {deg: Math.atan2(dx, -dy) * 180 / Math.PI, dist: Math.hypot(dx, dy)};
  };
  function startDrag(event: PointerEvent, el: HTMLElement, fromRing: boolean) {
    if (event.button!==0 || !event.isPrimary) return;
    drag={id:event.pointerId,el,x:event.clientX,y:event.clientY,start:value,pos:value,angle:null,moved:false,fromRing};
    el.setPointerCapture(event.pointerId);
    // Focus for keyboard hand-off, but flag it as pointer-driven so the ring stays hidden.
    dial.dataset.pointerFocus = '';
    dial.focus({preventScroll:true});
  }
  function moveDrag(event: PointerEvent) {
    if (!drag || event.pointerId!==drag.id) return;
    if (!drag.moved) {
      const dx=event.clientX-drag.x;
      const dy=event.clientY-drag.y;
      if (Math.abs(dx)+Math.abs(dy)<5) return;
      drag.moved=true;
      consoleEl!.classList.add('is-dragging');
    }
    // Mouse, pen and finger all follow the pointer round the centre, so circling
    // keeps turning. The dial never scrolls the page (touch-action:none in the CSS),
    // so a finger that lands on it always turns it.
    const {deg,dist} = angleAt(event.clientX,event.clientY);
    if (dist < 8) return; // Too close to the centre for a steady angle.
    if (drag.angle !== null) {
      let delta = deg - drag.angle;
      if (delta > 180) delta -= 360;
      else if (delta < -180) delta += 360;
      drag.pos += delta / DEG_PER_UNIT;
    }
    drag.angle = deg;
    // Clamp as we go, so pushing past either end never has to be unwound first.
    drag.pos = Math.max(0, Math.min(100, drag.pos));
    tune(drag.pos);
  }
  function finishDrag(event: PointerEvent) {
    if (!drag || event.pointerId!==drag.id) return;
    const previous=drag;
    drag=null;
    consoleEl!.classList.remove('is-dragging');
    if(previous.el.hasPointerCapture(event.pointerId)) previous.el.releasePointerCapture(event.pointerId);
    if(event.type==='pointercancel') tune(previous.start);
    else if(previous.moved) tune(Math.round(value/25)*25);
    // A tap on the grab ring does nothing; only the dial itself steps on a tap.
    else if(!previous.fromRing) tune(value>=100?0:value+25);
  }
  // Switching tab or app blurs the dial but leaves it focused, and the browser then
  // shows the ring on return. Only forget the click once focus really moves on.
  dial.addEventListener('blur',()=>{if(document.activeElement!==dial) delete dial.dataset.pointerFocus;});
  [[dial,false],[grab,true]].forEach(([el,fromRing]) => {
    const target = el as HTMLElement;
    target.addEventListener('pointerdown',event=>startDrag(event,target,fromRing as boolean));
    target.addEventListener('pointermove',moveDrag);
    target.addEventListener('pointerup',finishDrag);
    target.addEventListener('pointercancel',finishDrag);
  });
  controls.forEach(control => control.addEventListener('click',()=>tune(Number(control.dataset.tuneStage)*25)));
  consoleEl.querySelector('[data-lock-signal]')?.addEventListener('click',()=>tune(100));
  // No automatic demonstration: the physical object is ready on first paint.
}
