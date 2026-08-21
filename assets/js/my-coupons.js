(()=>{
  const cfg=window.DeckaTyper||{};
  const accountCfg=window.DeckaTyperCoupons||window.DeckaTyperAccountConfig||{};
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
  let activeLeague='all',activeGroup='';
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
      const no=Number(item.round_no||0),league=String(item.league_key||'1lm'),group=String(item.group_key||'');
      const key=`${league}|${group}|${no}`;
      if(!groups.has(key))groups.set(key,[]);
      groups.get(key).push(item);
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
    const favoriteId=Number(accountCfg.favoriteTeamId||0);
    const isFavorite=favoriteId>0&&[Number(item.home_team_id||0),Number(item.away_team_id||0)].includes(favoriteId);
    return `<div class="dt-coupon-match is-${status} ${item.is_bonus?'is-bonus':''} ${isFavorite?'is-favorite-team':''}">
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

  const coupon=([key,items],index)=>{
    const [league,group,roundNo]=key.split('|');
    const leagueLabel=league==='plk'?'PLK':league==='2lm'?`2LM${group?` · GRUPA ${group}`:''}`:'1LM';
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
          <small>${esc(leagueLabel)}</small>
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
      const normalizeGroup=value=>String(value||'').trim().toUpperCase();
      const groups=[...new Set(lastHistory.filter(x=>String(x.league_key)==='2lm').map(x=>normalizeGroup(x.group_key)).filter(Boolean))].sort();
      if(activeLeague==='2lm'&&groups.length&&!groups.includes(normalizeGroup(activeGroup)))activeGroup=groups[0];
      const filtered=lastHistory.filter(x=>(activeLeague==='all'||String(x.league_key||'1lm')===activeLeague)&&(activeLeague!=='2lm'||!activeGroup||normalizeGroup(x.group_key)===normalizeGroup(activeGroup)));
      const labels={all:'Wszystkie',plk:'PLK','1lm':'1LM','2lm':'2LM'};
      box.innerHTML=`<div class="dt-coupon-filters"><div class="dt-filter-segmented">${['all','1lm','plk','2lm'].map(l=>`<button type="button" data-coupon-league="${esc(l)}" class="${activeLeague===l?'is-active':''}">${esc(labels[l]||l)}</button>`).join('')}</div>${activeLeague==='2lm'&&groups.length?`<div class="dt-filter-segmented dt-filter-groups">${groups.map(g=>`<button type="button" data-coupon-group="${esc(g)}" class="${normalizeGroup(activeGroup)===g?'is-active':''}">GRUPA ${esc(g)}</button>`).join('')}</div>`:''}</div>${groupHistory(filtered).map(coupon).join('')||'<div class="dt-empty-front">Brak typów dla wybranego zakresu.</div>'}`;
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
    const league=e.target.closest('[data-coupon-league]');if(league){activeLeague=league.dataset.couponLeague||'all';activeGroup='';render(lastHistory);return;}
    const group=e.target.closest('[data-coupon-group]');if(group){activeGroup=group.dataset.couponGroup||'';render(lastHistory);}
  });

  refresh();
})();
