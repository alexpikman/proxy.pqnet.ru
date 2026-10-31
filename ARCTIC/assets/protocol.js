(() => {
  'use strict';
  const canvas = document.getElementById('cfcp-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d', { alpha: true });
  const ui = {
    play: document.getElementById('play-btn'), restart: document.getElementById('restart-btn'),
    next: document.getElementById('next-stage-btn'), status: document.getElementById('sim-status-text'),
    censorPercent: document.getElementById('censor-percent'), friendPercent: document.getElementById('friend-percent'),
    censorMeter: document.getElementById('censor-meter'), friendMeter: document.getElementById('friend-meter'),
    censorResult: document.getElementById('censor-result'), friendResult: document.getElementById('friend-result'),
    stageCounter: document.getElementById('stage-counter'), stageFill: document.getElementById('stage-progress-fill'),
    kicker: document.getElementById('stage-kicker'), title: document.getElementById('stage-title'), description: document.getElementById('stage-description'),
    tabs: [...document.querySelectorAll('.stage-tabs button')]
  };
  const stages = [
    { name:'STAGE 01 / DATAGRAM FRAGMENTATION', title:'Письмо распадается на фрагменты', description:'Отправитель формирует набор независимых частей. Цвета в этой модели — только визуальная метафора: реальные пакеты не получают «цвет» как криптографический атрибут.' },
    { name:'STAGE 02 / INTERCEPTION & ANALYSIS', title:'Цензор начинает медленный перебор', description:'Наблюдатель может собирать доступные метаданные и искать закономерности. В симуляции его прогресс намеренно медленнее сборки получателя; это иллюстрация идеи, а не измерение реального DPI.' },
    { name:'STAGE 03 / OUT-OF-BAND CONTROL', title:'«Звонок другу» — канал управления', description:'Отдельный аутентифицированный канал передаёт согласованное состояние. Он помогает получателю интерпретировать сообщения, но не становится невидимым автоматически: оба потока могут коррелировать.' },
    { name:'STAGE 04 / CASCADE ASSEMBLY', title:'Друг №2 собирает письмо каскадом', description:'Получатель сверяет контекст фрагментов, проверяет подлинность, собирает полезную нагрузку и отклоняет дубликаты. Визуальное «сгорание» означает удаление уже обработанной копии, а не магическое стирание у наблюдателя.' },
    { name:'STAGE 05 / DECRYPTION & INTEGRITY', title:'Сообщение собрано и проверено', description:'Финальный этап — успешная аутентификация, проверка целостности и передача сообщения приложению. Это проектное представление: реальная реализация требует формальной спецификации и аудита.' }
  ];
  const duration = 36000;
  const fragmentCount = 35; // Increased visual density for a richer, smoother scene.
  const particles = [];
  let width = 1000, height = 400, dpr = 1, elapsed = 0, last = 0, running = true, raf = 0, manualStage = null;
  let sparks = [];
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const colors = ['#11a7eb','#39c8f4','#087cda','#81d9f5','#ff9b2b','#ffbd63'];
  const clamp = (v,min,max) => Math.max(min,Math.min(max,v));
  const lerp = (a,b,t) => a+(b-a)*t;
  const ease = t => t<.5?4*t*t*t:1-Math.pow(-2*t+2,3)/2;
  const smooth = t => { t=clamp(t,0,1); return t*t*(3-2*t); };
  function resize(){
    const box=canvas.getBoundingClientRect();
    width=Math.max(320,box.width); height=Math.max(220,box.height);
    dpr=Math.min(window.devicePixelRatio||1,2);
    canvas.width=Math.round(width*dpr); canvas.height=Math.round(height*dpr);
    ctx.setTransform(dpr,0,0,dpr,0,0);
  }
  function reset(){
    elapsed=0; sparks=[];
    particles.length=0;
    for(let i=0;i<fragmentCount;i++){
      const lane=i%5, row=Math.floor(i/5);
      particles.push({id:i,x:0.10+(row%2)*.012,y:.27+lane*.075+(Math.random()-.5)*.025,color:colors[(i*7+lane)%colors.length],size:5+(i%4)*1.25,phase:i/fragmentCount,locked:false,lockTime:0,seed:Math.random()*Math.PI*2});
    }
    manualStage=null; updateUI(); draw(0);
  }
  function stageFor(t){return t<6000?0:t<12000?1:t<16500?2:t<30000?3:4;}
  function progress(t){return clamp(t/duration,0,1);}
  function currentStage(){return manualStage===null?stageFor(elapsed):manualStage;}
  function stageVisualProgress(s,t){
    const ranges=[[0,6000],[6000,12000],[12000,16500],[16500,30000],[30000,36000]][s];
    return smooth((t-ranges[0])/(ranges[1]-ranges[0]));
  }
  function updateUI(){
    const s=currentStage(), t=elapsed;
    const friend=clamp((t-16500)/12500,0,1), censor=clamp(.08+(t/36000)*.36,0,.44);
    ui.censorPercent.textContent=Math.round(censor*100).toString().padStart(2,'0')+'%';
    ui.friendPercent.textContent=Math.round(friend*100).toString().padStart(2,'0')+'%';
    ui.censorMeter.style.width=(censor*100)+'%'; ui.friendMeter.style.width=(friend*100)+'%';
    ui.censorResult.textContent=t>30000?'НЕПОЛНАЯ КАРТИНА':t>6000?'СОПОСТАВЛЕНИЕ...':'ОЖИДАНИЕ';
    ui.friendResult.textContent=t>30000?'ПРОВЕРЕНО ✓':t>16500?'СБОРКА...':'ОЖИДАНИЕ';
    ui.stageCounter.textContent=`ЭТАП 0${s+1} / 05`;
    ui.stageFill.style.width=((s+stageVisualProgress(s,t)) / 5 * 100)+'%';
    ui.kicker.textContent=stages[s].name; ui.title.textContent=stages[s].title; ui.description.textContent=stages[s].description;
    ui.tabs.forEach((b,i)=>b.classList.toggle('active',i===s));
    ui.status.textContent=t>=duration?'СООБЩЕНИЕ ПРОВЕРЕНО':running?'АНИМАЦИЯ ВЫПОЛНЯЕТСЯ':'АНИМАЦИЯ ПРИОСТАНОВЛЕНА';
    ui.play.textContent=running?'Ⅱ Пауза':'▶ Продолжить';
  }
  function roundRect(x,y,w,h,r,fill,stroke){ctx.beginPath();ctx.roundRect(x,y,w,h,r);if(fill){ctx.fillStyle=fill;ctx.fill()}if(stroke){ctx.strokeStyle=stroke;ctx.stroke()}}
  function drawBackground(){
    const grad=ctx.createLinearGradient(0,0,width,height);grad.addColorStop(0,'rgba(255,255,255,.35)');grad.addColorStop(.5,'rgba(225,242,255,.08)');grad.addColorStop(1,'rgba(255,245,230,.30)');ctx.fillStyle=grad;ctx.fillRect(0,0,width,height);
    const y1=height*.33,y2=height*.72;
    ctx.save();ctx.setLineDash([5,7]);ctx.strokeStyle='rgba(65,130,185,.23)';ctx.lineWidth=1;
    [y1,y2].forEach(y=>{ctx.beginPath();ctx.moveTo(width*.08,y);ctx.lineTo(width*.92,y);ctx.stroke()});ctx.restore();
    const nodes=[{x:.1,y:.5},{x:.34,y:.33},{x:.68,y:.72},{x:.9,y:.5}];
    nodes.forEach((n,i)=>{ctx.beginPath();ctx.arc(width*n.x,height*n.y,2.1,0,Math.PI*2);ctx.fillStyle=i===2?'#ff9b2c':'#36aee8';ctx.fill()});
  }
  function drawEndpoint(x,y,label,sub,color){
    ctx.save();ctx.shadowColor=color+'55';ctx.shadowBlur=18;ctx.beginPath();ctx.arc(x,y,22,0,Math.PI*2);ctx.fillStyle='#ffffff';ctx.fill();ctx.shadowBlur=0;ctx.lineWidth=1.4;ctx.strokeStyle=color;ctx.stroke();
    ctx.beginPath();ctx.arc(x,y,14,0,Math.PI*2);ctx.strokeStyle=color+'55';ctx.stroke();ctx.fillStyle=color;ctx.font='700 16px Manrope, sans-serif';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(label,x,y+1);
    ctx.fillStyle='#526d8b';ctx.font='700 10px Manrope, sans-serif';ctx.fillText(sub,x,y+39);ctx.restore();
  }
  function drawCensor(x,y,t){
    ctx.save();roundRect(x-28,y-29,56,58,13,'rgba(255,255,255,.83)','#d9e4ef');ctx.strokeStyle='#8c9fb4';ctx.lineWidth=2;ctx.beginPath();ctx.arc(x-2,y-3,10,.3,Math.PI*1.85);ctx.stroke();ctx.beginPath();ctx.moveTo(x+6,y+5);ctx.lineTo(x+15,y+15);ctx.stroke();
    ctx.strokeStyle='#aab9c9';ctx.setLineDash([3,4]);ctx.beginPath();ctx.moveTo(x-3,y+30);ctx.lineTo(x-3,y+53);ctx.stroke();ctx.setLineDash([]);
    ctx.fillStyle='#7389a2';ctx.font='700 9px Manrope';ctx.textAlign='center';ctx.fillText('ОБЗОР',x,y+68);ctx.restore();
  }
  function drawPhone(x,y,t){
    const pulse=.5+.5*Math.sin(t*.004);
    ctx.save();ctx.strokeStyle=`rgba(255,151,39,${.45+pulse*.4})`;ctx.lineWidth=1.4;ctx.setLineDash([4,5]);ctx.beginPath();ctx.moveTo(x,y-18);ctx.bezierCurveTo(x+25,y-64,x+78,y-64,x+102,y-18);ctx.stroke();ctx.setLineDash([]);
    ctx.shadowColor='#ff9a2c';ctx.shadowBlur=14;ctx.beginPath();ctx.arc(x+51,y-42,5+pulse*2,0,Math.PI*2);ctx.fillStyle='#ff9a2c';ctx.fill();ctx.restore();
  }
  function drawEnvelope(x,y,scale,complete,t){
    ctx.save();ctx.translate(x,y);ctx.scale(scale,scale);ctx.shadowColor='#0c82d955';ctx.shadowBlur=complete?24:8;
    roundRect(-38,-25,76,50,8,complete?'#ffffff':'rgba(255,255,255,.82)',complete?'#0a9ce5':'#b8d5e9');ctx.beginPath();ctx.moveTo(-36,-22);ctx.lineTo(0,6);ctx.lineTo(36,-22);ctx.strokeStyle=complete?'#0b9fe8':'#9bbbd5';ctx.lineWidth=2;ctx.stroke();
    if(complete){ctx.beginPath();ctx.arc(0,0,55+Math.sin(t*.005)*3,0,Math.PI*2);ctx.strokeStyle='rgba(21,176,228,.4)';ctx.lineWidth=2;ctx.stroke();ctx.fillStyle='#0a8bd4';ctx.font='700 9px Manrope';ctx.textAlign='center';ctx.fillText('VERIFIED',0,43)}
    ctx.restore();
  }
  function drawFragment(x,y,p,t,alpha=1){
    ctx.save();ctx.translate(x,y);ctx.rotate(Math.sin(t*.0017+p.seed)*.16);ctx.globalAlpha=alpha;ctx.shadowColor=p.color;ctx.shadowBlur=9;
    ctx.beginPath();ctx.moveTo(0,-p.size);ctx.lineTo(p.size*.82,-p.size*.35);ctx.lineTo(p.size*.82,p.size*.55);ctx.lineTo(0,p.size);ctx.lineTo(-p.size*.82,p.size*.55);ctx.lineTo(-p.size*.82,-p.size*.35);ctx.closePath();ctx.fillStyle=p.color;ctx.fill();ctx.shadowBlur=0;ctx.strokeStyle='#ffffff';ctx.lineWidth=.8;ctx.stroke();
    ctx.beginPath();ctx.moveTo(0,-p.size*.65);ctx.lineTo(0,p.size*.65);ctx.strokeStyle='rgba(255,255,255,.55)';ctx.stroke();ctx.restore();
  }
  function drawSpark(x,y,t,color){
    ctx.save();sparks.forEach(s=>{const age=(t-s.t)/680;if(age<0||age>1)return;const a=1-age;ctx.globalAlpha=a;ctx.strokeStyle=s.color;ctx.lineWidth=1.6*a;ctx.beginPath();ctx.moveTo(s.x,s.y);ctx.lineTo(s.x+s.dx*age,s.y+s.dy*age);ctx.stroke();ctx.beginPath();ctx.arc(s.x+s.dx*age,s.y+s.dy*age,1.3*a,0,Math.PI*2);ctx.fillStyle=s.color;ctx.fill()});ctx.restore();
  }
  function makeBurn(x,y,t,color){for(let j=0;j<11;j++){const ang=Math.PI*2*j/11+(Math.random()-.5)*.3;const d=12+Math.random()*26;sparks.push({x,y,t,dx:Math.cos(ang)*d,dy:Math.sin(ang)*d,color:j%3?color:'#ffb24e'})}}
  function draw(t){
    ctx.clearRect(0,0,width,height);drawBackground();
    const mobile=width<560, senderX=width*(mobile?.085:.09), censorX=width*(mobile?.43:.41), receiverX=width*(mobile?.91:.9), midY=height*.50;
    const phase=progress(t), friendStart=16500, build=clamp((t-friendStart)/12500,0,1), stage=currentStage();
    // Sender and receiver nodes.
    drawEndpoint(senderX,midY,'✉','ОТПРАВИТЕЛЬ','#138bd7');
    drawCensor(censorX,midY,t);
    drawEndpoint(receiverX,midY,'✓','ДРУГ №2','#ff982b');
    // Sender's outgoing data stream.
    ctx.save();ctx.strokeStyle='rgba(23,157,220,.25)';ctx.lineWidth=1.4;ctx.beginPath();ctx.moveTo(senderX+25,midY);ctx.lineTo(receiverX-28,midY);ctx.stroke();ctx.restore();
    // Observer's slow scan arc / candidate lines.
    const scan=clamp((t-6000)/24000,0,1);
    ctx.save();ctx.strokeStyle='rgba(132,151,173,.55)';ctx.lineWidth=1;ctx.setLineDash([3,5]);
    for(let k=0;k<3;k++){const yy=midY-31+k*31;ctx.beginPath();ctx.moveTo(censorX+24,yy);ctx.bezierCurveTo(censorX+70,yy-14,censorX+85,midY+(k-1)*10,receiverX-38,midY+(k-1)*12);ctx.stroke()}
    ctx.setLineDash([]);ctx.restore();
    if(t>=12000&&t<30000)drawPhone(senderX+25,midY,t);
    // Friend's assembly ring.
    if(t>friendStart){ctx.save();ctx.beginPath();ctx.arc(receiverX,midY,57, -Math.PI/2,-Math.PI/2+Math.PI*2*build);ctx.strokeStyle='#17a6e4';ctx.lineWidth=3;ctx.stroke();ctx.restore()}
    // Fragments are visible from the start; each arrives and is locked in a cascade.
    const cols=5, rows=7, pocketX=receiverX-(mobile?28:53), pocketY=midY+(mobile?65:72);
    particles.forEach((p,i)=>{
      const stagger=i*235;
      let travel=clamp((t-stagger)/10500,0,1);
      const curve=Math.sin(travel*Math.PI*2+p.seed)*17;
      const x=lerp(senderX+30,receiverX-43,ease(travel));
      const y=lerp(midY+(i%5-2)*9,midY+(i%5-2)*5,ease(travel))+curve;
      // During the receiver's assembly phase each accepted fragment locks in a visible grid.
      const arrive=friendStart+i*260;
      const lock=clamp((t-arrive)/550,0,1);
      if(t>=arrive&&!p.locked){p.locked=true;p.lockTime=arrive;makeBurn(x,y,arrive,p.color)}
      if(t<friendStart || t<arrive){drawFragment(x,y,p,t, .95)}
      else if(lock<1){
        const slot=i;const gx=receiverX+(slot%cols-2)*12;const gy=pocketY+Math.floor(slot/cols)*10;
        const bx=lerp(x,gx,ease(lock)),by=lerp(y,gy,ease(lock));drawFragment(bx,by,p,t,1-lock*.35);
      }else{
        const slot=i;const gx=receiverX+(slot%cols-2)*11;const gy=pocketY+Math.floor(slot/cols)*9;
        if(t-arrive<800)drawFragment(gx,gy,p,t,Math.max(0,1-(t-arrive)/800));
      }
      // The censor sees and scans fragments, but advances at a slower illustrative rate.
      if(t>6000&&t<31000&&i%3===0){const scanX=censorX+28+((i*13)%26);const scanY=midY-35+((i*17)%70);ctx.save();ctx.globalAlpha=.13+scan*.3;ctx.strokeStyle='#8399b1';ctx.beginPath();ctx.moveTo(censorX+27,midY);ctx.lineTo(scanX,scanY);ctx.stroke();ctx.restore()}
    });
    drawSpark(0,0,t);
    if(t>30000){drawEnvelope(receiverX,midY+115, .78,true,t)}
    // Small explanatory labels.
    ctx.save();ctx.font='9px "DM Mono",monospace';ctx.fillStyle='#7892ac';ctx.textAlign='center';ctx.fillText('DATA PLANE',width*.52,height*.14);if(t>=12000){ctx.fillStyle='#d98932';ctx.fillText('OUT-OF-BAND CONTROL',width*.50,height*.27)}ctx.restore();
    // A few ambient floating dust motes add visual fluidity without overloading mobile devices.
    for(let k=0;k<22;k++){const xx=(k*83+t*.012*(k%2?1:-1))%(width+10);const yy=(k*47+Math.sin(t*.0008+k)*8)%height;ctx.fillStyle=k%4?'rgba(23,155,224,.18)':'rgba(255,157,45,.22)';ctx.fillRect(xx,yy,1.5,1.5)}
    if(manualStage!==null){const activeStart=[0,6000,12000,16500,30000][manualStage];const activeEnd=[6000,12000,16500,30000,36000][manualStage];ctx.save();ctx.fillStyle='rgba(255,255,255,.10)';ctx.fillRect(0,0,width,25);ctx.restore();}
  }
  function frame(now){
    if(!last)last=now;let delta=Math.min(50,now-last);last=now;
    if(running){elapsed+=delta*(reduceMotion?.35:1);if(elapsed>=duration){elapsed=duration;running=false}manualStage=null;updateUI()}
    draw(elapsed);raf=requestAnimationFrame(frame);
  }
  function jumpStage(index){
    const starts=[0,6000,12000,16500,30000];
    elapsed=starts[index]; manualStage=index; running=false; sparks=[];
    particles.forEach(p=>{p.locked=false;p.lockTime=0});
    updateUI(); draw(elapsed);
  }
  ui.play.addEventListener('click',()=>{if(elapsed>=duration)reset();running=!running;last=0;updateUI()});
  ui.restart.addEventListener('click',()=>{running=true;reset();last=0;});
  ui.next.addEventListener('click',()=>{const next=(currentStage()+1)%5;jumpStage(next)});
  ui.tabs.forEach((b,i)=>b.addEventListener('click',()=>jumpStage(i)));
  const observer=new ResizeObserver(resize);observer.observe(canvas);
  resize();reset();
  if(reduceMotion)running=false;
  raf=requestAnimationFrame(frame);
  document.addEventListener('visibilitychange',()=>{if(document.hidden){cancelAnimationFrame(raf)}else{last=0;raf=requestAnimationFrame(frame)}});
})();
