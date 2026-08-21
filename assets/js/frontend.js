(()=>{
  const cfg=window.DeckaTyper||{};
  const root=document.getElementById('decka-typer');
  if(!root||!cfg.loggedIn)return;
  const $=(s,c=root)=>c.querySelector(s), $$=(s,c=root)=>[...c.querySelectorAll(s)];
  const state={boot:null,round:null,picks:new Map(),tab:'picks',rankMode:'season',saving:false};

  const icon=n=>{
    const p={calendar:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',lock:'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',check:'<path d="m5 12 4 4L19 6"/>',clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',target:'<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/>',alert:'<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.6 2.2 18a2 2 0 0 0 1.8 3h16a2 2 0 0 0 1.8-3L13.7 3.6a2 2 0 0 0-3.4 0z"/>'};
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

  const load=async()=>{
    try{
      const boot=await api('bootstrap');state.boot=boot;state.round=boot.current_round;
      hydratePicks();renderUser(boot.me);renderLeagueRounds();renderRoundSelector();renderRound(state.round);renderRanking(boot.ranking||[]);renderMine(boot.me);
    }catch(e){toast(e.message,true);const box=$('#dt-matches');if(box)box.innerHTML='<div class="dt-empty-front">Nie udało się załadować Typera. Odśwież stronę za chwilę.</div>';}
  };
  const hydratePicks=()=>{
    state.picks.clear();
    (state.round?.matches||[]).forEach(m=>{if(m.prediction?.selected_team_id)state.picks.set(Number(m.id),Number(m.prediction.selected_team_id));});
  };
  const renderUser=me=>{
    if(!me)return;
    const chip=$('#dt-user-chip');if(chip)chip.innerHTML=`<img src="${esc(me.avatar)}" alt=""><span><b>${esc(me.display_name)}</b><small>${me.rank?`#${me.rank} w rankingu`:'Czas na pierwszy kupon'}</small></span>`;
    const stats=$$('#dt-user-stats .dt-stat strong');
    if(stats[0])stats[0].textContent=me.rank?`#${me.rank}`:'—';
    if(stats[1])stats[1].textContent=Number(me.points||0).toFixed(0);
    if(stats[2])stats[2].textContent=String(me.winner_hits||0);
  };
  const renderRoundSelector=()=>{
    const sel=$('#dt-round-select');if(!sel||!state.boot)return;
    const rounds=state.boot.rounds||[];
    sel.innerHTML=rounds.map(r=>`<option value="${r.id}" ${state.round&&Number(r.id)===Number(state.round.id)?'selected':''}>${esc(r.title)}${r.is_open?' · OTWARTA':''}</option>`).join('');
    updateNav();
  };
  const renderLeagueRounds=()=>{
    const box=$('#dt-league-rounds');if(!box||!state.boot)return;
    const labels={plk:'ORLEN Basket Liga', '1lm':'1 Liga Mężczyzn','2lm':'2 Liga Mężczyzn'};
    const groups={};(state.boot.rounds||[]).forEach(r=>{const k=r.league_key||'1lm';(groups[k]??=[]).push(r);});
    box.innerHTML=Object.entries(groups).map(([key,rounds],index)=>`<details class="dt-league-section" ${rounds.some(r=>r.is_open)||index===0?'open':''}><summary><span>${esc(labels[key]||key.toUpperCase())}</span><span class="dt-league-chip">${rounds.filter(r=>r.is_open).length?`${rounds.filter(r=>r.is_open).length} otwarte`:`${rounds.length} kolejek`}</span></summary><div class="dt-league-section-body"><div class="dt-round-pills">${rounds.map(r=>`<button type="button" data-league-round="${r.id}" class="${state.round&&Number(state.round.id)===Number(r.id)?'is-active':''}">${esc(r.group_key?`Grupa ${r.group_key} · ${r.title}`:r.title)}</button>`).join('')}</div></div></details>`).join('');
  };
  const updateNav=()=>{
    if(!state.boot||!state.round)return;
    const rounds=state.boot.rounds||[],idx=rounds.findIndex(r=>Number(r.id)===Number(state.round.id));
    $('#dt-prev-round').disabled=idx<=0;$('#dt-next-round').disabled=idx<0||idx>=rounds.length-1;
  };
  const loadRound=async id=>{
    if(!id)return;
    if(hasUnsavedPicks()&&!confirm('Masz niezapisane typy. Zmienić kolejkę i je odrzucić?')){renderRoundSelector();return;}
    state.picks.clear();$('#dt-matches').innerHTML='<div class="dt-loading-card"></div><div class="dt-loading-card"></div>';
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
    $('#dt-round-title').textContent=round.title;
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
    bindTeamChoices();updateNav();updateSaveDock();
  };
  const matchCard=m=>{
    const decka=!!m.featured||/decka pelplin/i.test(`${m.home_name} ${m.away_name}`);
    const pick=state.picks.get(Number(m.id))||Number(m.prediction?.selected_team_id||0);
    const canPick=!!state.round?.can_submit;
    const homeClass=pick?pick===Number(m.home_team_id)?'is-selected':'is-rejected':'';
    const awayClass=pick?pick===Number(m.away_team_id)?'is-selected':'is-rejected':'';
    const timing=m.start_time_known?fmtDate(m.starts_at_iso,true):`${fmtDate(m.starts_at_iso,false)} · godzina do potwierdzenia`;
    const resultKnown=m.score_home!==null&&m.score_home!==undefined&&m.score_away!==null&&m.score_away!==undefined;
    let result='';
    if(resultKnown){
      const pts=m.prediction?`${Number(m.prediction.points||0).toFixed(0)} pkt`:'';
      result=`<div class="dt-result-row"><span>Wynik: <strong>${esc(m.score_home)} : ${esc(m.score_away)}</strong></span>${m.prediction?`<span class="dt-points ${Number(m.prediction.points||0)===0?'is-zero':''}">${esc(pts)}</span>`:''}</div>`;
    }
    return `<article class="dt-match ${decka?'is-decka':''} ${canPick?'':'is-locked'}" data-match-card="${m.id}">
      <div class="dt-match-head"><span class="dt-date">${icon('calendar')}${esc(timing)}</span><span class="dt-lock-pill ${canPick?'is-open':''}">${icon(canPick?'check':'lock')}${canPick?'wybierz zwycięzcę':'zamknięte'}</span></div>
      <div class="dt-winner-grid">
        <button type="button" class="dt-team-choice ${homeClass}" data-team-choice data-match="${m.id}" data-team="${m.home_team_id}" ${canPick?'':'disabled'}>${teamLogo(m.home_name,m.home_logo)}<strong>${esc(m.home_name)}</strong><span class="dt-choice-mark">${pick===Number(m.home_team_id)?'TWÓJ TYP':'GOSPODARZ'}</span></button>
        <div class="dt-vs-mark">VS</div>
        <button type="button" class="dt-team-choice ${awayClass}" data-team-choice data-match="${m.id}" data-team="${m.away_team_id}" ${canPick?'':'disabled'}>${teamLogo(m.away_name,m.away_logo)}<strong>${esc(m.away_name)}</strong><span class="dt-choice-mark">${pick===Number(m.away_team_id)?'TWÓJ TYP':'GOŚĆ'}</span></button>
      </div>${result}</article>`;
  };
  const bindTeamChoices=()=>{
    $$('[data-team-choice]').forEach(btn=>btn.addEventListener('click',()=>{
      if(!state.round?.can_submit)return;
      state.picks.set(Number(btn.dataset.match),Number(btn.dataset.team));
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
    button.disabled=selected!==total||total===0||state.saving;
  };
  const openSubmitModal=()=>{
    if(!state.round?.can_submit)return;
    const total=(state.round.matches||[]).length;if(state.picks.size!==total){toast('Wytypuj zwycięzcę każdego meczu.',true);return;}
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
      state.round=await api(`round/${state.round.id}`);hydratePicks();renderRound(state.round);
      const me=await api('me');if(state.boot)state.boot.me=me;renderUser(me);renderMine(me);
    }catch(e){toast(e.message,true);}finally{state.saving=false;updateSaveDock();}
  };
  const renderRanking=rows=>{
    const box=$('#dt-ranking');if(!box)return;const me=state.boot?.me?.user_id;
    box.innerHTML=rows.length?rows.map(r=>`<div class="dt-rank-row ${Number(r.user_id)===Number(me)?'is-me':''}"><div class="dt-rank-pos">${r.rank}</div><div class="dt-rank-person"><div class="dt-rank-avatar">${esc(initials(r.display_name))}</div><span><strong>${esc(r.display_name)}</strong><small>${r.predictions} typów</small></span></div><div class="dt-rank-points">${Number(r.points).toFixed(0)} pkt</div><div class="dt-rank-exact">${r.winner_hits||0} trafień</div></div>`).join(''):'<div class="dt-empty-front">Ranking pojawi się po rozliczeniu pierwszych typów.</div>';
  };
  const loadRanking=async mode=>{
    state.rankMode=mode;$$('[data-rank]').forEach(b=>b.classList.toggle('is-active',b.dataset.rank===mode));$('#dt-ranking').innerHTML='<div class="dt-empty-front">Ładowanie rankingu…</div>';
    try{const query=mode==='round'&&state.round?`?round_id=${state.round.id}`:'';const data=await api('ranking'+query);renderRanking(data.ranking||[]);}catch(e){toast(e.message,true);}
  };
  const renderMine=me=>{
    if(!me)return;
    const sum=$('#dt-my-summary'),hist=$('#dt-my-history');
    if(sum)sum.innerHTML=`<div class="dt-my-cards"><div class="dt-my-card"><span>Miejsce</span><strong>${me.rank?`#${me.rank}`:'—'}</strong></div><div class="dt-my-card"><span>Punkty</span><strong>${Number(me.points||0).toFixed(0)}</strong></div><div class="dt-my-card"><span>Trafienia</span><strong>${me.winner_hits||0}</strong></div><div class="dt-my-card"><span>Kupony</span><strong>${me.submissions||0}</strong></div></div>`;
    if(hist){const h=me.history||[];hist.innerHTML=h.length?h.map(x=>`<div class="dt-history-row"><div class="dt-history-round">#${x.round_no} kolejka</div><div class="dt-history-game">${esc(x.home_name)} – ${esc(x.away_name)}<small>${esc(fmtDate(x.starts_at_iso,true))}</small></div><div class="dt-history-score"><small>Twój typ</small><strong>${esc(x.selected_team_name)}</strong></div><div class="dt-history-points">${x.result_known?`${Number(x.points).toFixed(0)} pkt`:'—'}</div></div>`).join(''):'<div class="dt-empty-front">Nie masz jeszcze zapisanych typów.</div>';}
  };

  root.addEventListener('click',e=>{
    const tab=e.target.closest('[data-tab]');
    if(tab){$$('[data-tab]').forEach(b=>b.classList.toggle('is-active',b===tab));$$('[data-panel]').forEach(p=>p.classList.toggle('is-active',p.dataset.panel===tab.dataset.tab));state.tab=tab.dataset.tab;if(state.tab==='mine')api('me').then(m=>{if(state.boot)state.boot.me=m;renderUser(m);renderMine(m);}).catch(x=>toast(x.message,true));return;}
    const rank=e.target.closest('[data-rank]');if(rank){loadRanking(rank.dataset.rank);return;}
    const leagueRound=e.target.closest('[data-league-round]');if(leagueRound){loadRound(Number(leagueRound.dataset.leagueRound));return;}
    if(e.target.closest('[data-modal-close]')){closeSubmitModal();return;}
  });
  $('#dt-round-select')?.addEventListener('change',e=>loadRound(Number(e.target.value)));
  $('#dt-prev-round')?.addEventListener('click',()=>{const rs=state.boot?.rounds||[],i=rs.findIndex(r=>Number(r.id)===Number(state.round?.id));if(i>0)loadRound(rs[i-1].id);});
  $('#dt-next-round')?.addEventListener('click',()=>{const rs=state.boot?.rounds||[],i=rs.findIndex(r=>Number(r.id)===Number(state.round?.id));if(i>=0&&i<rs.length-1)loadRound(rs[i+1].id);});
  $('#dt-save-all')?.addEventListener('click',openSubmitModal);
  $('#dt-confirm-submit')?.addEventListener('click',saveCoupon);
  const refreshAchievements=async()=>{const scope=$('#dt-achievement-scope')?.value||'all',league=$('#dt-achievement-league')?.value||'all';try{const q=new URLSearchParams({scope,league});const me=await api('me?'+q.toString());renderUser(me);}catch(e){toast(e.message,true);}};
  $('#dt-achievement-scope')?.addEventListener('change',refreshAchievements);
  $('#dt-achievement-league')?.addEventListener('change',refreshAchievements);
  $('#dt-submit-modal')?.addEventListener('click',e=>{if(e.target===e.currentTarget)closeSubmitModal();});
  window.addEventListener('beforeunload',e=>{if(hasUnsavedPicks()){e.preventDefault();e.returnValue='';}});
  load();
})();
