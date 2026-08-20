(()=>{
  const cfg=window.DeckaTyper||{};
  const root=document.getElementById('decka-typer');
  const box=document.getElementById('dt-my-history');
  if(!root||!box||!cfg.loggedIn||!cfg.root)return;

  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
  const fmtDate=s=>{
    if(!s)return 'Termin do ustalenia';
    const d=new Date(s);if(Number.isNaN(d.getTime()))return 'Termin do ustalenia';
    return new Intl.DateTimeFormat('pl-PL',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',timeZone:cfg.timezone||'Europe/Warsaw'}).format(d).replace(',',' ·');
  };
  const fmtBonus=v=>Number.isInteger(Number(v||0))?String(Number(v||0)):Number(v||0).toFixed(1).replace('.',',');

  let lastHistory=[];
  let rendering=false;
  let refreshTimer=null;

  const apiMe=async()=>{
    const headers={'Content-Type':'application/json'};
    if(cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;
    const response=await fetch(cfg.root+'me',{credentials:'same-origin',headers});
    if(!response.ok)throw new Error('Nie udało się pobrać historii typów.');
    return response.json();
  };

  const groupHistory=history=>{
    const groups=new Map();
    history.forEach(item=>{
      const no=Number(item.round_no||0);
      if(!groups.has(no))groups.set(no,[]);
      groups.get(no).push(item);
    });
    return [...groups.entries()].sort((a,b)=>b[0]-a[0]);
  };

  const statusOf=item=>{
    if(!item.result_known)return 'pending';
    return item.scoring_code==='winner'?'hit':'miss';
  };

  const pointsLabel=item=>{
    if(!item.result_known)return '—';
    if(item.scoring_code==='winner')return `+${Number(item.points||0).toFixed(0)} pkt`;
    return '0';
  };

  const matchRow=item=>{
    const status=statusOf(item);
    const statusLabel=status==='hit'?'TRAFIONY':status==='miss'?'NIETRAFIONY':'OCZEKUJE';
    const bonus=item.is_bonus?`<span class="dt-coupon-bonus">★ BONUS +${esc(fmtBonus(item.bonus_points))} PKT</span>`:'';
    return `<div class="dt-coupon-match is-${status} ${item.is_bonus?'is-bonus':''}">
      <div class="dt-coupon-game">
        <strong>${esc(item.home_name)} <span>–</span> ${esc(item.away_name)}</strong>
        <small>${esc(fmtDate(item.starts_at_iso))}</small>
        ${bonus}
      </div>
      <div class="dt-coupon-user-pick">
        <small>TWÓJ TYP</small>
        <strong>${esc(item.selected_team_name)}</strong>
      </div>
      <div class="dt-coupon-status is-${status}">${statusLabel}</div>
      <div class="dt-coupon-points is-${status}">${esc(pointsLabel(item))}</div>
    </div>`;
  };

  const coupon=([roundNo,items],index)=>{
    const known=items.filter(x=>x.result_known);
    const hits=known.filter(x=>x.scoring_code==='winner').length;
    const points=items.reduce((sum,x)=>sum+Number(x.points||0),0);
    const bonusMatches=items.filter(x=>x.is_bonus).length;
    const complete=known.length===items.length&&items.length>0;
    const baseMeta=complete?`${hits}/${items.length} trafień · ${points.toFixed(0)} pkt`:`${known.length}/${items.length} rozliczonych`;
    const meta=bonusMatches?`${baseMeta} · ★ BONUS`:baseMeta;
    return `<details class="dt-coupon" ${index===0?'open':''}>
      <summary class="dt-coupon-summary">
        <div class="dt-coupon-title">
          <small>${esc(cfg.leagueName||'1 LIGA')}</small>
          <strong>#${roundNo} kolejka</strong>
        </div>
        <div class="dt-coupon-meta">${esc(meta)}</div>
        <span class="dt-coupon-chevron" aria-hidden="true">⌄</span>
      </summary>
      <div class="dt-coupon-body">${items.map(matchRow).join('')}</div>
    </details>`;
  };

  const render=history=>{
    lastHistory=Array.isArray(history)?history:[];
    rendering=true;
    if(!lastHistory.length){
      box.innerHTML='<div class="dt-empty-front">Nie masz jeszcze zapisanych typów.</div>';
    }else{
      box.innerHTML=groupHistory(lastHistory).map(coupon).join('');
    }
    box.dataset.couponView='1';
    queueMicrotask(()=>{rendering=false;});
  };

  const refresh=async()=>{
    try{const me=await apiMe();render(me.history||[]);}catch(_){/* Base frontend keeps its own error handling. */}
  };

  const observer=new MutationObserver(()=>{
    if(rendering)return;
    if(box.querySelector('.dt-history-row')&&lastHistory.length){
      clearTimeout(refreshTimer);
      refreshTimer=setTimeout(()=>render(lastHistory),0);
    }
  });
  observer.observe(box,{childList:true,subtree:false});

  root.addEventListener('click',e=>{
    const tab=e.target.closest('[data-tab="mine"]');
    if(tab)setTimeout(refresh,0);
  });

  refresh();
})();
