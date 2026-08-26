(()=>{
  const cfg=window.DeckaTyper||{};
  const root=document.getElementById('decka-typer');
  if(!root)return;
  if(!cfg.loggedIn){
    const helper=root.querySelector('#dt-avatar-helper'),item=cfg.avatar?.landing_welcome;
    if(helper&&item){
      const texts=Array.isArray(item.texts)?item.texts.filter(Boolean):[item.text].filter(Boolean);
      const message=texts.length?texts[Math.floor(Math.random()*texts.length)]:(item.text||'');
      helper.querySelector('img').src=item.url||'';
      helper.querySelector('.dt-avatar-bubble').textContent=message;
      let hideTimer;
      const hide=()=>{clearTimeout(hideTimer);helper.classList.remove('is-show');setTimeout(()=>helper.hidden=true,220);};
      helper.querySelector('.dt-avatar-close')?.addEventListener('click',hide);
      setTimeout(()=>{helper.hidden=false;helper.classList.add('is-show');hideTimer=setTimeout(hide,6500);},2800);
    }
    return;
  }
  const $=(s,c=root)=>c.querySelector(s), $$=(s,c=root)=>[...c.querySelectorAll(s)];
  const state={boot:null,round:null,picks:new Map(),tab:'picks',rankMode:'season',saving:false,league:'',group:''};

  const icon=n=>{
    const p={calendar:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',lock:'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',check:'<path d="m5 12 4 4L19 6"/>',clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',target:'<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/>',alert:'<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.6 2.2 18a2 2 0 0 0 1.8 3h16a2 2 0 0 0 1.8-3L13.7 3.6a2 2 0 0 0-3.4 0z"/>',chevDownDouble:'<path d="m7 7 5 5 5-5"/><path d="m7 12 5 5 5-5"/>'};
    return `<svg class="dt-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">${p[n]||p.check}</svg>`;
  };
  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
  const api=async(path,opt={})=>{
    const headers={'Content-Type':'application/json',...(opt.headers||{})};
    if(cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;
    const response=await fetch(cfg.root+path,{credentials:'same-origin',...opt,headers});
    let data={};
    try{data=await response.json()}catch(_){data={message:'Błąd odpowiedzi serwera.'}}
    if(!response.ok)throw new Error(data.message||'Nie udało się wykonać operacji.');
    return data;
  };
  let toastTimer;
  let avatarTimer;
  const avatar=(key,duration=6500)=>{
    const helper=$('#dt-avatar-helper'),item=cfg.avatar?.[key];if(!helper||!item)return;
    const texts=Array.isArray(item.texts)?item.texts.filter(Boolean):[item.text].filter(Boolean);
    const message=texts.length?texts[Math.floor(Math.random()*texts.length)]:(item.text||'');
    helper.querySelector('img').src=item.url||'';helper.querySelector('.dt-avatar-bubble').textContent=message;
    helper.hidden=false;helper.classList.add('is-show');clearTimeout(avatarTimer);
    avatarTimer=setTimeout(()=>{helper.classList.remove('is-show');setTimeout(()=>helper.hidden=true,220);},duration);
  };
  const toast=(msg,error=false)=>{
    const t=$('#dt-toast');if(!t)return;
    t.textContent=msg;t.classList.toggle('is-error',error);t.classList.add('is-show');
    clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.classList.remove('is-show'),3000);
  };
  const parseDate=s=>s?new Date(s):null;
  const fmtDate=(s,withTime=true)=>{
    const d=parseDate(s);if(!d||Number.isNaN(d.getTime()))return 'Termin do ustalenia';
    const opts=withTime?{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',timeZone:cfg.timezone||'Europe/Warsaw'}:{day:'2-digit',month:'short',year:'numeric',timeZone:cfg.timezone||'Europe/Warsaw'};
    return new Intl.DateTimeFormat('pl-PL',opts).format(d).replace(',',' ·');
  };
  const initials=name=>String(name||'').split(/\s+/).filter(Boolean).slice(-2).map(x=>x[0]).join('').toUpperCase();
  const teamLogo=(name,url)=>url?`<div class="dt-team-logo"><img src="${esc(url)}" alt="" loading="lazy" onerror="this.parentNode.textContent='${esc(initials(name))}'"></div>`:`<div class="dt-team-logo">${esc(initials(name))}</div>`;
  const recordLabel=record=>`${Number(record?.wins||0)}-${Number(record?.losses||0)}`;
  const averageLabel=value=>value===null||value===undefined?'—':Number(value).toFixed(1).replace('.',',');
  const infoTip=text=>`<span class="dt-info-tip" tabindex="0" aria-label="Informacja"><span aria-hidden="true">i</span><span class="dt-info-tooltip" role="tooltip">${esc(text)}</span></span>`;
  const teamInsights=(name,data,venue)=>{
    const venueName=venue==='home'?'dom':'wyjazd';
    const venueHelp=venue==='home'?'Średnia zdobywanych punktów w ostatnich 3 meczach domowych.':'Średnia zdobywanych punktów w ostatnich 3 meczach wyjazdowych.';
    return `<section class="dt-team-insights"><strong>${esc(name)}</strong><dl><div><dt>Bilans ogólny</dt><dd>${esc(recordLabel(data?.overall_record))}</dd></div><div><dt>Bilans ${venueName}</dt><dd>${esc(recordLabel(data?.venue_record))}</dd></div><div><dt>Średnia pkt ${venueName} ${infoTip(venueHelp)}</dt><dd>${esc(averageLabel(data?.venue_average))}</dd></div><div><dt>Średnia pkt ${infoTip('Średnia zdobywanych punktów w ostatnich 3 meczach ogółem.')}</dt><dd>${esc(averageLabel(data?.overall_average))}</dd></div></dl></section>`;
  };

  const load=async()=>{
    try{
      const boot=await api('bootstrap');state.boot=boot;state.round=boot.current_round;
      state.league=String(state.round?.league_key||preferredLeague(boot.rounds||[]));
      state.group=state.league==='2lm'?String(state.round?.group_key||preferredGroup(boot.rounds||[])):'';
      hydratePicks();renderUser(boot.me);renderAchievements(boot.me);renderLeagueRounds();renderRoundSelector();renderRound(state.round);renderRanking(boot.ranking||[]);renderMine(boot.me);
      setTimeout(()=>avatar('welcome'),500);
    }catch(e){toast(e.message,true);const box=$('#dt-matches');if(box)box.innerHTML='<div class="dt-empty-front">Nie udało się załadować Typera. Odśwież stronę za chwilę.</div>';}
  };
  const hydratePicks=()=>{
    state.picks.clear();
    (state.round?.matches||[]).forEach(m=>{if(m.prediction?.selected_team_id)state.picks.set(Number(m.id),Number(m.prediction.selected_team_id));});
  };
  const preferredLeague=rounds=>{
    const open1lm=rounds.find(r=>r.is_open&&String(r.league_key)==='1lm');
    const open=open1lm||rounds.find(r=>r.is_open);
    return String(open?.league_key||rounds[0]?.league_key||'1lm');
  };
  const normalizeGroup=value=>String(value||'').trim().toUpperCase();
  const displayRoundTitle=round=>String(round?.title||`${round?.round_no||''}. kolejka`).replace(/\s*[·–—-]?\s*grupa\s+[a-z0-9]+\s*$/i,'').trim();
  const preferredGroup=rounds=>{
    const relevant=rounds.filter(r=>String(r.league_key)==='2lm');
    return normalizeGroup(relevant.find(r=>r.is_open)?.group_key||relevant[0]?.group_key||'');
  };
  const filteredRounds=()=>{
    const rounds=state.boot?.rounds||[];
    return rounds.filter(r=>String(r.league_key||'1lm')===state.league&&(state.league!=='2lm'||normalizeGroup(r.group_key)===normalizeGroup(state.group)));
  };
  const nearestRound=rounds=>rounds.find(r=>r.is_open)||rounds.find(r=>!r.submitted)||rounds[rounds.length-1]||null;
  const renderUser=me=>{
    if(!me)return;
    const chip=$('#dt-user-chip');if(chip)chip.innerHTML=`<img src="${esc(me.avatar)}" alt=""><span><b>${esc(me.display_name)}</b><small>${me.rank?`#${me.rank} w rankingu`:'Czas na pierwszy kupon'}</small></span>`;
  };
  const renderAchievements=me=>{
    const box=$('#dt-achievement-leagues');if(!box||!me)return;
    const data=me.league_achievements||{};
    box.innerHTML=['1lm','plk','2lm'].map(key=>{const item=data[key]||{};return `<article class="dt-achievement-league"><header><span>${key.toUpperCase()}</span><strong>${item.rank?`#${item.rank}`:'—'}</strong></header><div><span>Punkty<b>${Number(item.points||0).toFixed(0)}</b></span><span>Trafienia<b>${Number(item.winner_hits||0)}</b></span><span>Typy<b>${Number(item.predictions||0)}</b></span></div></article>`;}).join('');
  };
  const renderRoundSelector=()=>{
    const sel=$('#dt-round-select');if(!sel||!state.boot)return;
    const rounds=filteredRounds();
    sel.innerHTML=rounds.map(r=>{
      const parts=[displayRoundTitle(r),String(r.league_key||state.league||'1lm').toUpperCase()];
      if(String(r.league_key||state.league)==='2lm'&&r.group_key)parts.push(`GRUPA ${normalizeGroup(r.group_key)}`);
      parts.push(r.season||state.boot.season||cfg.season||'');
      parts.push(r.is_open?'OTWARTA':'ZAMKNIĘTA');
      return `<option value="${r.id}" ${state.round&&Number(r.id)===Number(state.round.id)?'selected':''}>${parts.filter(Boolean).map(esc).join(' · ')}</option>`;
    }).join('');
    sel.closest('.dt-round-nav')?.classList.toggle('is-hidden',!rounds.length);
    updateNav();
  };
  const renderLeagueRounds=()=>{
    const box=$('#dt-league-rounds');if(!box||!state.boot)return;
    const labels=Object.fromEntries((state.boot.leagues||[]).map(league=>[String(league.key||''),String(league.name||'')]));
    const present=new Set((state.boot.rounds||[]).map(r=>String(r.league_key||'1lm')));
    const available=['1lm','plk','2lm'].filter(key=>present.has(key));
    const groups=[...new Set((state.boot.rounds||[]).filter(r=>String(r.league_key)==='2lm').map(r=>normalizeGroup(r.group_key)).filter(Boolean))];
    box.innerHTML=`<div class="dt-segmented dt-league-segments">${available.map(key=>`<button type="button" data-league="${esc(key)}" class="${state.league===key?'is-active':''}"><span class="dt-league-name-full">${esc(labels[key]||key.toUpperCase())}</span><span class="dt-league-name-short">${esc(key.toUpperCase())}</span></button>`).join('')}</div>${state.league==='2lm'?`<div class="dt-segmented dt-group-segments">${groups.map(group=>`<button type="button" data-group="${esc(group)}" class="${normalizeGroup(state.group)===group?'is-active':''}">GRUPA ${esc(group)}</button>`).join('')}</div>`:''}`;
  };
  const updateNav=()=>{
    if(!state.boot||!state.round)return;
    const rounds=filteredRounds(),idx=rounds.findIndex(r=>Number(r.id)===Number(state.round.id));
    $('#dt-prev-round').disabled=idx<=0;$('#dt-next-round').disabled=idx<0||idx>=rounds.length-1;
  };
  const loadRound=async id=>{
    if(!id)return;
    if(hasUnsavedPicks()&&!confirm('Masz niezapisane typy. Zmienić kolejkę i je odrzucić?')){renderRoundSelector();return;}
    state.picks.clear();$('#dt-matches').innerHTML='<div class="dt-loading-card"></div><div class="dt-loading-card"></div>';
    avatar('thinking',3000);
    try{state.round=await api(`round/${id}`);hydratePicks();renderLeagueRounds();renderRoundSelector();renderRound(state.round);if(state.rankMode==='round')loadRanking('round');}catch(e){toast(e.message,true);}
  };
  const hasUnsavedPicks=()=>!!(state.round?.can_submit&&state.picks.size);
  const renderRound=round=>{
    if(!round){
      $('#dt-round-title').textContent='Brak dostępnej kolejki';
      $('#dt-round-meta').innerHTML='';
      $('#dt-matches').innerHTML='<div class="dt-empty-front">Administrator nie otworzył jeszcze kolejki do typowania.</div>';
      updateSaveDock();return;
    }
    $('#dt-round-title').textContent=displayRoundTitle(round);
    const matches=round.matches||[];
    const submitted=!!round.submission?.submitted;
    const open=!!round.is_open;
    const meta=[];
    if(open&&round.closes_at_iso)meta.push(`<span class="dt-meta-pill is-accent">${icon('clock')}Typowanie do ${esc(fmtDate(round.closes_at_iso,true))}</span>`);
    else meta.push(`<span class="dt-meta-pill">${icon('lock')}Typowanie zamknięte</span>`);
    if(submitted)meta.push(`<span class="dt-meta-pill is-success">${icon('check')}Kupon zapisany</span>`);
    meta.push(`<span class="dt-meta-pill">${icon('target')}${matches.length} meczów</span>`);
    $('#dt-round-meta').innerHTML=meta.join('');
    $('#dt-matches').innerHTML=matches.length?matches.map(matchCard).join(''):'<div class="dt-empty-front">Brak meczów w tej kolejce.</div>';
    const resolved=matches.filter(m=>m.score_home!==null&&m.score_home!==undefined&&m.score_away!==null&&m.score_away!==undefined&&m.prediction);
    if(!open&&!submitted)avatar('closed');
    else if(submitted&&resolved.length===matches.length&&matches.length){const hits=resolved.filter(m=>Number(m.prediction?.points||0)>0).length;avatar(hits===matches.length?'perfect':(hits===0?'missed':'thinking'));}
    bindTeamChoices();updateNav();updateSaveDock();
  };
  const matchCard=m=>{
    const decka=!!m.featured||/decka pelplin/i.test(`${m.home_name} ${m.away_name}`);
    const pick=state.picks.get(Number(m.id))||Number(m.prediction?.selected_team_id||0);
    const canPick=!!state.round?.can_submit;
    const homeClass=pick?pick===Number(m.home_team_id)?'is-selected':'is-rejected':'';
    const awayClass=pick?pick===Number(m.away_team_id)?'is-selected':'is-rejected':'';
    const timing=m.start_time_known?fmtDate(m.starts_at_iso,true):`${fmtDate(m.starts_at_iso,false)} · godzina do potwierdzenia`;
    const showCountdown=!!cfg.showCountdowns&&!!m.start_time_known&&!!m.starts_at_iso&&new Date(m.starts_at_iso).getTime()>Date.now();
    const resultKnown=m.score_home!==null&&m.score_home!==undefined&&m.score_away!==null&&m.score_away!==undefined;
    let result='';
    if(resultKnown){
      const pts=m.prediction?`${Number(m.prediction.points||0).toFixed(0)} pkt`:'';
      result=`<div class="dt-result-row"><span>Wynik: <strong>${esc(m.score_home)} : ${esc(m.score_away)}</strong></span>${m.prediction?`<span class="dt-points ${Number(m.prediction.points||0)===0?'is-zero':''}">${esc(pts)}</span>`:''}</div>`;
    }
    const arturButton=cfg.arturAiEnabled&&(canPick||cfg.siteMode==='test')?`<button type="button" class="dt-artur-ai-open" data-artur-ai data-round="${state.round.id}" data-match="${m.id}" data-home="${esc(m.home_name)}" data-away="${esc(m.away_name)}" aria-label="Koło ratunkowe Artura"><span aria-hidden="true">🛟</span><strong>Koło ratunkowe Artura</strong></button>`:'';
    return `<article class="dt-match ${decka?'is-decka':''} ${canPick?'':'is-locked'}" data-match-card="${m.id}">
      <div class="dt-match-head"><div class="dt-match-timing"><span class="dt-date">${icon('calendar')}${esc(timing)}</span>${showCountdown?`<span class="dt-match-countdown" data-countdown-target="${esc(m.starts_at_iso)}" data-countdown-hide-expired="1" aria-label="Do meczu pozostało"><span class="dt-countdown-icon" aria-hidden="true">${icon('clock')}</span><span class="dt-visually-hidden">Do meczu pozostało: </span><strong data-countdown-value>—</strong></span>`:''}</div><div class="dt-match-head-actions">${arturButton?`<div class="dt-artur-ai-action is-desktop">${arturButton}</div>`:''}<span class="dt-lock-pill ${canPick?'is-open':''}">${icon(canPick?'check':'lock')}${canPick?'wybierz zwycięzcę':'zamknięte'}</span></div></div>
      <div class="dt-winner-grid">
        <button type="button" class="dt-team-choice ${homeClass}" data-team-choice data-match="${m.id}" data-team="${m.home_team_id}" aria-disabled="${canPick?'false':'true'}">${teamLogo(m.home_name,m.home_logo)}<strong>${esc(m.home_name)}</strong><span class="dt-choice-mark">${pick===Number(m.home_team_id)?'TWÓJ TYP':'GOSPODARZ'}</span></button>
        <div class="dt-vs-mark">VS</div>
        <button type="button" class="dt-team-choice ${awayClass}" data-team-choice data-match="${m.id}" data-team="${m.away_team_id}" aria-disabled="${canPick?'false':'true'}">${teamLogo(m.away_name,m.away_logo)}<strong>${esc(m.away_name)}</strong><span class="dt-choice-mark">${pick===Number(m.away_team_id)?'TWÓJ TYP':'GOŚĆ'}</span></button>
      </div>${result}<details class="dt-match-more"><summary>Zobacz statystyki <span class="dt-expand-icon">${icon('chevDownDouble')}</span></summary><div class="dt-match-insights-grid">${teamInsights(m.home_name,m.home_insights,'home')}${teamInsights(m.away_name,m.away_insights,'away')}</div></details>${arturButton?`<div class="dt-artur-ai-action is-mobile">${arturButton}</div>`:''}</article>`;
  };
  const bindTeamChoices=()=>{
    $$('[data-team-choice]').forEach(btn=>btn.addEventListener('click',()=>{
      if(!state.round?.can_submit)return;
      state.picks.set(Number(btn.dataset.match),Number(btn.dataset.team));
      const match=state.round?.matches?.find(m=>Number(m.id)===Number(btn.dataset.match));if(match?.is_bonus)avatar('bonus');
      renderRound(state.round);
    }));
  };
  const updateSaveDock=()=>{
    const dock=$('#dt-save-dock'),button=$('#dt-save-all'),label=$('#dt-save-count');if(!dock||!button||!label)return;
    if(!state.round){label.textContent='Brak otwartej kolejki';button.disabled=true;return;}
    const total=(state.round.matches||[]).length,selected=state.picks.size;
    if(state.round.submission?.submitted){label.textContent='Kupon zapisany';dock.classList.add('is-locked');button.disabled=true;button.querySelector('span').textContent='Zapisano';return;}
    if(!state.round.is_open){label.textContent='Typowanie zamknięte';dock.classList.add('is-locked');button.disabled=true;return;}
    dock.classList.remove('is-locked');button.querySelector('span').textContent='Zapisz typy';
    label.textContent=selected===total&&total?`Komplet: ${selected}/${total}`:`Wybrano ${selected}/${total}`;
    button.disabled=total===0||state.saving;
  };
  const openSubmitModal=()=>{
    if(!state.round?.can_submit)return;
    const total=(state.round.matches||[]).length;if(state.picks.size!==total){toast('Wytypuj zwycięzcę każdego meczu.',true);avatar('warning');return;}
    $('#dt-submit-modal')?.showModal();
  };
  const closeSubmitModal=()=>$('#dt-submit-modal')?.close();
  const saveCoupon=async()=>{
    if(state.saving||!state.round?.can_submit)return;
    state.saving=true;closeSubmitModal();updateSaveDock();
    const payload={round_id:Number(state.round.id),picks:[...state.picks.entries()].map(([match_id,team_id])=>({match_id,team_id}))};
    try{
      await api('submission',{method:'POST',body:JSON.stringify(payload)});
      toast('Kupon zapisany. Typów nie można już edytować.');
      avatar('saved');
      state.round=await api(`round/${state.round.id}`);hydratePicks();renderRound(state.round);
      const me=await api('me');if(state.boot)state.boot.me=me;renderUser(me);renderMine(me);
    }catch(e){toast(e.message,true);}finally{state.saving=false;updateSaveDock();}
  };
  const renderRanking=rows=>{
    const box=$('#dt-ranking');if(!box)return;const me=state.boot?.me?.user_id;
    box.innerHTML=rows.length?rows.map(r=>`<div class="dt-rank-row ${Number(r.user_id)===Number(me)?'is-me':''} ${r.is_expert?'is-expert':''}"><div class="dt-rank-pos">${r.rank}</div><div class="dt-rank-person"><div class="dt-rank-avatar">${esc(initials(r.display_name))}</div><span><strong>${esc(r.display_name)}${r.is_expert?' <span class="dt-expert-badge">EKSPERT!</span>':''}</strong><small>${r.predictions} typów</small></span></div><div class="dt-rank-points">${Number(r.points).toFixed(0)} pkt</div><div class="dt-rank-exact">${r.winner_hits||0} trafień</div></div>`).join(''):'<div class="dt-empty-front">Ranking pojawi się po rozliczeniu pierwszych typów.</div>';
  };
  const loadRanking=async mode=>{
    state.rankMode=mode;$$('[data-rank]').forEach(b=>b.classList.toggle('is-active',b.dataset.rank===mode));$('#dt-ranking').innerHTML='<div class="dt-empty-front">Ładowanie rankingu…</div>';
    try{const query=mode==='round'&&state.round?`?round_id=${state.round.id}`:'';const data=await api('ranking'+query);renderRanking(data.ranking||[]);}catch(e){toast(e.message,true);}
  };
  const renderMine=me=>{
    if(!me)return;
    const sum=$('#dt-my-summary'),hist=$('#dt-my-history');
    if(sum)sum.innerHTML=`<div class="dt-my-cards"><div class="dt-my-card"><span>Miejsce</span><strong>${me.rank?`#${me.rank}`:'—'}</strong></div><div class="dt-my-card"><span>Punkty</span><strong>${Number(me.points||0).toFixed(0)}</strong></div><div class="dt-my-card"><span>Trafienia</span><strong>${me.winner_hits||0}</strong></div><div class="dt-my-card"><span>Kupony</span><strong>${me.submissions||0}</strong></div></div>`;
    if(hist){const h=me.history||[],favoriteId=Number(window.DeckaTyperAccountConfig?.favoriteTeamId||0);hist.innerHTML=h.length?h.map(x=>{const favorite=favoriteId>0&&[Number(x.home_team_id||0),Number(x.away_team_id||0)].includes(favoriteId);return `<div class="dt-history-row"><div class="dt-history-round">#${x.round_no} kolejka</div><div class="dt-history-game">${esc(x.home_name)} – ${esc(x.away_name)}<small>${esc(fmtDate(x.starts_at_iso,true))}</small>${favorite?'<span class="dt-history-favorite">♥ Ulubiona drużyna</span>':''}</div><div class="dt-history-score"><small>Twój typ</small><strong>${esc(x.selected_team_name)}</strong></div><div class="dt-history-points">${x.result_known?`${Number(x.points).toFixed(0)} pkt`:'—'}</div></div>`;}).join(''):'<div class="dt-empty-front">Nie masz jeszcze zapisanych typów.</div>';}
  };

  root.addEventListener('click',e=>{
    const tab=e.target.closest('[data-tab]');
    if(tab){$$('[data-tab]').forEach(b=>b.classList.toggle('is-active',b===tab));$$('[data-panel]').forEach(p=>p.classList.toggle('is-active',p.dataset.panel===tab.dataset.tab));state.tab=tab.dataset.tab;if(state.tab==='mine')api('me').then(m=>{if(state.boot)state.boot.me=m;renderUser(m);renderMine(m);}).catch(x=>toast(x.message,true));return;}
    const rank=e.target.closest('[data-rank]');if(rank){loadRanking(rank.dataset.rank);return;}
    const leagueButton=e.target.closest('[data-league]');if(leagueButton){state.league=leagueButton.dataset.league||'1lm';state.group=state.league==='2lm'?preferredGroup(state.boot?.rounds||[]):'';const next=nearestRound(filteredRounds());renderLeagueRounds();if(next)loadRound(Number(next.id));else{state.round=null;renderRoundSelector();renderRound(null);}return;}
    const groupButton=e.target.closest('[data-group]');if(groupButton){state.group=normalizeGroup(groupButton.dataset.group);const next=nearestRound(filteredRounds());renderLeagueRounds();if(next)loadRound(Number(next.id));else{state.round=null;renderRoundSelector();renderRound(null);}return;}
    if(e.target.closest('[data-modal-close]')){closeSubmitModal();return;}
  });
  $('#dt-round-select')?.addEventListener('change',e=>loadRound(Number(e.target.value)));
  $('#dt-prev-round')?.addEventListener('click',()=>{const rs=filteredRounds(),i=rs.findIndex(r=>Number(r.id)===Number(state.round?.id));if(i>0)loadRound(rs[i-1].id);});
  $('#dt-next-round')?.addEventListener('click',()=>{const rs=filteredRounds(),i=rs.findIndex(r=>Number(r.id)===Number(state.round?.id));if(i>=0&&i<rs.length-1)loadRound(rs[i+1].id);});
  $('#dt-save-all')?.addEventListener('click',openSubmitModal);
  $('#dt-confirm-submit')?.addEventListener('click',saveCoupon);
  let achievementScope='all';
  const refreshAchievements=async()=>{try{const q=new URLSearchParams({scope:achievementScope});const me=await api('me?'+q.toString());renderAchievements(me);}catch(e){toast(e.message,true);}};
  $$('.dt-achievement-controls [data-value]').forEach(button=>button.addEventListener('click',()=>{const parent=button.parentElement;parent.querySelectorAll('[data-value]').forEach(x=>x.classList.toggle('is-active',x===button));achievementScope=button.dataset.value||'all';refreshAchievements();}));
  $('#dt-submit-modal')?.addEventListener('click',e=>{if(e.target===e.currentTarget)closeSubmitModal();});
  $('#dt-avatar-helper .dt-avatar-close')?.addEventListener('click',()=>{clearTimeout(avatarTimer);const h=$('#dt-avatar-helper');h.classList.remove('is-show');setTimeout(()=>h.hidden=true,220);});
  root.addEventListener('dt:avatar',event=>{const key=String(event.detail?.key||'');if(key)avatar(key);});
  window.addEventListener('beforeunload',e=>{if(hasUnsavedPicks()){e.preventDefault();e.returnValue='';}});
  load();
})();
