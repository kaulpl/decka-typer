(()=>{
  const cfg=window.DeckaTyper||{};
  const root=document.getElementById('decka-typer');
  if(!root||!cfg.loggedIn)return;
  const $=(s,c=root)=>c.querySelector(s), $$=(s,c=root)=>[...c.querySelectorAll(s)];
  const state={boot:null,round:null,dirty:new Map(),tab:'picks',rankMode:'season'};

  const icon=n=>{
    const p={calendar:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',lock:'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',check:'<path d="m5 12 4 4L19 6"/>',clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',target:'<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/>',alert:'<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.6 2.2 18a2 2 0 0 0 1.8 3h16a2 2 0 0 0 1.8-3L13.7 3.6a2 2 0 0 0-3.4 0z"/>'};
    return `<svg class="dt-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">${p[n]||p.check}</svg>`;
  };
  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
  const api=async(path,opt={})=>{
    const headers={'Content-Type':'application/json',...(opt.headers||{})}; if(cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;
    const r=await fetch(cfg.root+path,{credentials:'same-origin',...opt,headers});
    let data={}; try{data=await r.json()}catch(_){data={message:'Błąd odpowiedzi serwera.'}}
    if(!r.ok)throw new Error(data.message||'Nie udało się wykonać operacji.'); return data;
  };
  let toastTimer;
  const toast=(msg,error=false)=>{const t=$('#dt-toast');if(!t)return;t.textContent=msg;t.classList.toggle('is-error',error);t.classList.add('is-show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.classList.remove('is-show'),2800)};
  const parseDate=s=>s?new Date(String(s).replace(' ','T')):null;
  const fmtDate=(s,withTime=true)=>{const d=parseDate(s);if(!d||Number.isNaN(d.getTime()))return 'Termin do ustalenia';return new Intl.DateTimeFormat('pl-PL',withTime?{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}:{day:'2-digit',month:'short',year:'numeric'}).format(d).replace(',',' ·')};
  const initials=name=>String(name||'').split(/\s+/).filter(Boolean).slice(-2).map(x=>x[0]).join('').toUpperCase();
  const teamLogo=(name,url)=>url?`<div class="dt-team-logo"><img src="${esc(url)}" alt="" loading="lazy" onerror="this.parentNode.textContent='${esc(initials(name))}'"></div>`:`<div class="dt-team-logo">${esc(initials(name))}</div>`;

  const load=async()=>{
    try{
      const boot=await api('bootstrap'); state.boot=boot; state.round=boot.current_round;
      renderUser(boot.me); renderRoundSelector(); renderRound(state.round); renderRanking(boot.ranking||[]); renderMine(boot.me);
    }catch(e){toast(e.message,true);$('#dt-matches').innerHTML=`<div class="dt-empty-front">Nie udało się załadować Typera. Odśwież stronę za chwilę.</div>`}
  };
  const renderUser=me=>{
    if(!me)return;
    const chip=$('#dt-user-chip'); if(chip)chip.innerHTML=`<img src="${esc(me.avatar)}" alt=""><span><b>${esc(me.display_name)}</b><small>${me.rank?`#${me.rank} w rankingu`:'Pierwszy typ przed Tobą'}</small></span>`;
    const stats=$$('#dt-user-stats .dt-stat strong'); if(stats[0])stats[0].textContent=me.rank?`#${me.rank}`:'—';if(stats[1])stats[1].textContent=`${Number(me.points||0).toFixed(0)}`;if(stats[2])stats[2].textContent=String(me.exact_hits||0);
  };
  const renderRoundSelector=()=>{
    const sel=$('#dt-round-select'); if(!sel||!state.boot)return;
    sel.innerHTML=(state.boot.rounds||[]).map(r=>`<option value="${r.id}" ${state.round&&r.id===state.round.id?'selected':''}>${esc(r.title)}</option>`).join('');
    updateNav();
  };
  const updateNav=()=>{
    if(!state.boot||!state.round)return;const rs=state.boot.rounds||[],idx=rs.findIndex(r=>r.id===state.round.id);$('#dt-prev-round').disabled=idx<=0;$('#dt-next-round').disabled=idx<0||idx>=rs.length-1;
  };
  const loadRound=async id=>{
    if(!id)return; if(state.dirty.size&&!confirm('Masz niezapisane typy. Zmienić kolejkę i je odrzucić?')){renderRoundSelector();return;}
    state.dirty.clear();updateSaveDock();$('#dt-matches').innerHTML='<div class="dt-loading-card"></div><div class="dt-loading-card"></div>';
    try{state.round=await api(`round/${id}`);renderRoundSelector();renderRound(state.round);if(state.rankMode==='round')loadRanking('round');}catch(e){toast(e.message,true)}
  };
  const renderRound=round=>{
    if(!round){$('#dt-round-title').textContent='Brak aktywnej kolejki';$('#dt-matches').innerHTML='<div class="dt-empty-front">Terminarz nie został jeszcze zaimportowany.</div>';return;}
    $('#dt-round-title').textContent=round.title;
    const matches=round.matches||[]; const typed=matches.filter(m=>m.prediction).length; const open=matches.filter(m=>!m.locked).length;
    const dates=matches.map(m=>parseDate(m.starts_at)).filter(Boolean).sort((a,b)=>a-b);
    $('#dt-round-meta').innerHTML=`<span class="dt-meta-pill">${icon('calendar')}${dates.length?esc(new Intl.DateTimeFormat('pl-PL',{day:'2-digit',month:'long'}).format(dates[0])):'Termin nieustalony'}</span><span class="dt-meta-pill">${icon('target')}${typed}/${matches.length} typów zapisanych</span><span class="dt-meta-pill ${open?'is-accent':''}">${icon(open?'clock':'lock')}${open?`${open} mecz${open===1?'':'ów'} otwartych`:'Typowanie zamknięte'}</span>`;
    $('#dt-matches').innerHTML=matches.length?matches.map(matchCard).join(''):'<div class="dt-empty-front">Brak meczów w tej kolejce.</div>';
    bindInputs();updateNav();updateSaveDock();
  };
  const matchCard=m=>{
    const decka=/decka pelplin/i.test(`${m.home_name} ${m.away_name}`), locked=!!m.locked,p=m.prediction;
    const input=locked?`<div class="dt-vs"><span>TWÓJ TYP</span><strong>${p?`${p.home_score}:${p.away_score}`:'—'}</strong></div>`:`<div><div class="dt-score-inputs"><input inputmode="numeric" pattern="[0-9]*" min="0" max="250" aria-label="Wynik ${esc(m.home_name)}" data-match="${m.id}" data-side="home" value="${p?esc(p.home_score):''}"><b>:</b><input inputmode="numeric" pattern="[0-9]*" min="0" max="250" aria-label="Wynik ${esc(m.away_name)}" data-match="${m.id}" data-side="away" value="${p?esc(p.away_score):''}"></div><span class="dt-input-label">Twój typ</span></div>`;
    let result='';
    if(locked){
      const real=m.score_home!==null?`${m.score_home} : ${m.score_away}`:'wynik oczekiwany'; const pts=p&&m.score_home!==null?`${Number(p.points||0).toFixed(0)} pkt`:'';
      result=`<div class="dt-result-row"><span>Wynik: <strong>${esc(real)}</strong></span>${p?`<span class="dt-points ${Number(p.points||0)===0?'is-zero':''}">${esc(pts||'oczekuje')}</span>`:'<span>Brak typu</span>'}</div>`;
    }
    const timing=m.start_time_known?fmtDate(m.starts_at,true):`${fmtDate(m.starts_at,false)} · godzina do potwierdzenia`;
    return `<article class="dt-match ${decka?'is-decka':''} ${locked?'is-locked':''}" data-match-card="${m.id}"><div class="dt-match-head"><span class="dt-date">${icon('calendar')}${esc(timing)}</span><span class="dt-lock-pill ${locked?'':'is-open'}">${icon(locked?'lock':'check')}${locked?'zamknięte':'typowanie otwarte'}</span></div><div class="dt-teams"><div class="dt-team">${teamLogo(m.home_name,m.home_logo)}<strong>${esc(m.home_short||m.home_name)}</strong></div>${input}<div class="dt-team">${teamLogo(m.away_name,m.away_logo)}<strong>${esc(m.away_short||m.away_name)}</strong></div></div>${result}</article>`;
  };
  const bindInputs=()=>{
    $$('#dt-matches input[data-match]').forEach(inp=>inp.addEventListener('input',()=>{
      const id=Number(inp.dataset.match),card=inp.closest('[data-match-card]');const h=card.querySelector('[data-side="home"]'),a=card.querySelector('[data-side="away"]');
      if(h.value!==''&&a.value!==''){state.dirty.set(id,{match_id:id,home_score:Number(h.value),away_score:Number(a.value)});}else state.dirty.delete(id);updateSaveDock();
    }));
  };
  const updateSaveDock=()=>{const n=state.dirty.size;$('#dt-save-count').textContent=n===1?'1 zmiana':`${n} zmian`;$('#dt-save-all').disabled=n===0;};
  const saveAll=async()=>{
    if(!state.dirty.size)return;for(const p of state.dirty.values()){if(!Number.isInteger(p.home_score)||!Number.isInteger(p.away_score)||p.home_score<0||p.away_score<0||p.home_score>250||p.away_score>250){toast('Sprawdź wpisane wyniki.',true);return;}if(p.home_score===p.away_score){toast('Typ meczu koszykówki nie może kończyć się remisem.',true);return;}}
    const dock=$('#dt-save-dock');dock.classList.add('is-saving');$('#dt-save-all').disabled=true;let saved=0;
    try{
      for(const p of [...state.dirty.values()]){await api('prediction',{method:'POST',body:JSON.stringify(p)});const m=state.round.matches.find(x=>x.id===p.match_id);if(m)m.prediction={home_score:p.home_score,away_score:p.away_score,points:0,scoring_code:null};state.dirty.delete(p.match_id);saved++;}
      toast(saved===1?'Typ zapisany.':`Zapisano ${saved} typów.`);renderRound(state.round);const me=await api('me');renderUser(me);renderMine(me);
    }catch(e){toast(e.message,true);updateSaveDock()}finally{dock.classList.remove('is-saving');}
  };
  const renderRanking=rows=>{
    const box=$('#dt-ranking');if(!box)return;const me=state.boot?.me?.user_id;
    box.innerHTML=rows.length?rows.map(r=>`<div class="dt-rank-row ${Number(r.user_id)===Number(me)?'is-me':''}"><div class="dt-rank-pos">${r.rank}</div><div class="dt-rank-person"><div class="dt-rank-avatar">${esc(initials(r.display_name))}</div><span><strong>${esc(r.display_name)}</strong><small>${r.predictions} typów</small></span></div><div class="dt-rank-points">${Number(r.points).toFixed(0)} pkt</div><div class="dt-rank-exact">${r.exact_hits} dokładnych</div></div>`).join(''):'<div class="dt-empty-front">Ranking pojawi się po rozliczeniu pierwszych typów.</div>';
  };
  const loadRanking=async mode=>{
    state.rankMode=mode;$$('[data-rank]').forEach(b=>b.classList.toggle('is-active',b.dataset.rank===mode));$('#dt-ranking').innerHTML='<div class="dt-empty-front">Ładowanie rankingu…</div>';
    try{const q=mode==='round'&&state.round?`?round_id=${state.round.id}`:'';const d=await api('ranking'+q);renderRanking(d.ranking||[])}catch(e){toast(e.message,true)}
  };
  const renderMine=me=>{
    if(!me)return;const sum=$('#dt-my-summary'),hist=$('#dt-my-history');if(sum)sum.innerHTML=`<div class="dt-my-cards"><div class="dt-my-card"><span>Miejsce</span><strong>${me.rank?`#${me.rank}`:'—'}</strong></div><div class="dt-my-card"><span>Punkty</span><strong>${Number(me.points||0).toFixed(0)}</strong></div><div class="dt-my-card"><span>Dokładne wyniki</span><strong>${me.exact_hits||0}</strong></div><div class="dt-my-card"><span>Wszystkie typy</span><strong>${me.predictions||0}</strong></div></div>`;
    if(hist){const h=me.history||[];hist.innerHTML=h.length?h.map(x=>`<div class="dt-history-row"><div class="dt-history-round">#${x.round_no} kolejka</div><div class="dt-history-game">${esc(x.home_name)} – ${esc(x.away_name)}<small>${esc(fmtDate(x.starts_at,true))}</small></div><div class="dt-history-score">${x.home_score}:${x.away_score}</div><div class="dt-history-points">${x.result_known?`${Number(x.points).toFixed(0)} pkt`:'—'}</div></div>`).join(''):'<div class="dt-empty-front">Nie masz jeszcze zapisanych typów.</div>';}
  };

  root.addEventListener('click',e=>{
    const tab=e.target.closest('[data-tab]');if(tab){$$('[data-tab]').forEach(b=>b.classList.toggle('is-active',b===tab));$$('[data-panel]').forEach(p=>p.classList.toggle('is-active',p.dataset.panel===tab.dataset.tab));state.tab=tab.dataset.tab;if(state.tab==='mine')api('me').then(m=>{renderUser(m);renderMine(m)}).catch(x=>toast(x.message,true));return;}
    const rank=e.target.closest('[data-rank]');if(rank){loadRanking(rank.dataset.rank);return;}
  });
  $('#dt-round-select')?.addEventListener('change',e=>loadRound(Number(e.target.value)));
  $('#dt-prev-round')?.addEventListener('click',()=>{const rs=state.boot?.rounds||[],i=rs.findIndex(r=>r.id===state.round?.id);if(i>0)loadRound(rs[i-1].id)});
  $('#dt-next-round')?.addEventListener('click',()=>{const rs=state.boot?.rounds||[],i=rs.findIndex(r=>r.id===state.round?.id);if(i>=0&&i<rs.length-1)loadRound(rs[i+1].id)});
  $('#dt-save-all')?.addEventListener('click',saveAll);
  window.addEventListener('beforeunload',e=>{if(state.dirty.size){e.preventDefault();e.returnValue='';}});
  load();
})();
