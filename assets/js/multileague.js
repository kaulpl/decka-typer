(()=>{
  const cfg=window.TypujKoszaMultiLeague||{};
  const base=window.DeckaTyper||{};
  const root=document.getElementById('decka-typer');
  if(!root||!base.loggedIn||!cfg.root)return;

  const $=(s,c=root)=>c.querySelector(s), $$=(s,c=root)=>[...c.querySelectorAll(s)];
  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
  const api=async(path,opt={})=>{
    const headers={'Content-Type':'application/json',...(opt.headers||{})};
    if(cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;
    const r=await fetch(cfg.root+path,{credentials:'same-origin',...opt,headers});
    let data={};try{data=await r.json();}catch(_){data={message:'Serwer nie zwrócił poprawnej odpowiedzi.'};}
    if(!r.ok)throw new Error(data.message||'Nie udało się wykonać operacji.');
    return data;
  };
  const fmt=s=>{
    if(!s)return 'Termin do ustalenia'; const d=new Date(s); if(Number.isNaN(d.getTime()))return 'Termin do ustalenia';
    return new Intl.DateTimeFormat('pl-PL',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',timeZone:base.timezone||'Europe/Warsaw'}).format(d).replace(',',' ·');
  };
  const initials=name=>String(name||'').split(/\s+/).filter(Boolean).slice(-2).map(x=>x[0]).join('').toUpperCase();
  const logo=(name,url)=>url?`<span class="tk-team-logo"><img src="${esc(url)}" alt="" loading="lazy" onerror="this.parentNode.textContent='${esc(initials(name))}'"></span>`:`<span class="tk-team-logo">${esc(initials(name))}</span>`;
  const leagueClass=code=>`is-${String(code||'').replace(/[^a-z0-9]/gi,'')}`;
  const toast=(msg,error=false)=>{const t=$('#dt-toast');if(!t)return;t.textContent=msg;t.classList.toggle('is-error',error);t.classList.add('is-show');setTimeout(()=>t.classList.remove('is-show'),3300);};
  const query=obj=>{const p=new URLSearchParams();Object.entries(obj).forEach(([k,v])=>{if(v!==''&&v!==null&&v!==undefined&&v!==0)p.set(k,String(v));});return p.toString();};

  if(cfg.mode==='test'){
    const banner=document.createElement('div'); banner.className='tk-test-banner';
    banner.innerHTML='<strong>WERSJA TESTOWA</strong><span>TypujKosza.pl działa obecnie w trybie testowym. Wyniki, punkty i dane mogą być jeszcze korygowane przed startem sezonu.</span>';
    document.body.insertBefore(banner,document.body.firstChild);
  }

  const renameAccount=()=>{
    const btn=root.querySelector('[data-tab="settings"] span'); if(btn)btn.textContent='Moje konto';
  };
  renameAccount(); new MutationObserver(renameAccount).observe(root,{childList:true,subtree:true});

  const state={boot:null,rounds:new Map(),rankingLoaded:false,myLoaded:false,statsScope:'all',statsLeague:'1lm',statsSeason:'',rankScope:'all',rankLeague:'1lm',rankSeason:'',rankRound:0,rankGroup:''};

  const setupAchievements=()=>{
    const old=$('#dt-user-stats'); if(old)old.classList.add('tk-legacy-hidden');
    const top=$('.dt-app-top'); if(!top||$('#tk-achievements'))return;
    const section=document.createElement('section'); section.id='tk-achievements'; section.className='tk-achievements';
    section.innerHTML=`<div class="tk-ach-head"><div><span class="dt-front-kicker">TWOJE OSIĄGNIĘCIA</span><h2>Twój wynik</h2></div><div class="tk-filter-row" id="tk-stats-filters"><div class="tk-segment"><button class="is-active" data-stat-scope="all">Wszechczasów</button><button data-stat-scope="league">Liga</button><button data-stat-scope="season">Sezon</button></div><select class="tk-filter-select" id="tk-stats-league" hidden></select><select class="tk-filter-select" id="tk-stats-season" hidden></select></div></div><div class="tk-ach-grid" id="tk-ach-grid"><div class="tk-ach-card"><span>Miejsce</span><strong>—</strong></div><div class="tk-ach-card"><span>Punkty</span><strong>—</strong></div><div class="tk-ach-card"><span>Trafienia</span><strong>—</strong></div><div class="tk-ach-card"><span>Skuteczność</span><strong>—</strong></div><div class="tk-ach-card"><span>Typowania</span><strong>—</strong></div><div class="tk-ach-card"><span>Perfekcyjne</span><strong>—</strong></div></div>`;
    top.insertAdjacentElement('afterend',section);
    section.addEventListener('click',e=>{const b=e.target.closest('[data-stat-scope]');if(!b)return;state.statsScope=b.dataset.statScope;section.querySelectorAll('[data-stat-scope]').forEach(x=>x.classList.toggle('is-active',x===b));updateStatsFilterVisibility();loadStats();});
    $('#tk-stats-league')?.addEventListener('change',e=>{state.statsLeague=e.target.value;state.statsSeason='';loadStats();});
    $('#tk-stats-season')?.addEventListener('change',e=>{state.statsSeason=e.target.value;loadStats();});
  };

  const fillLeagueSelect=(select,value)=>{
    if(!select||!state.boot)return; select.innerHTML=(state.boot.leagues||[]).map(l=>`<option value="${esc(l.code)}" ${l.code===value?'selected':''}>${esc(l.name)}</option>`).join('');
  };
  const fillSeasonSelect=(select,seasons,value)=>{
    if(!select)return; select.innerHTML=(seasons||[]).map(s=>`<option value="${esc(s)}" ${s===value?'selected':''}>${esc(s)}</option>`).join('');
  };
  const updateStatsFilterVisibility=()=>{
    const l=$('#tk-stats-league'),s=$('#tk-stats-season'); if(!l||!s)return;
    l.hidden=state.statsScope==='all'; s.hidden=state.statsScope!=='season';
  };
  const loadStats=async()=>{
    try{
      const params={scope:state.statsScope,league:state.statsLeague,season:state.statsSeason};
      const d=await api('multileague/user-stats?'+query(params));
      if(d.league)state.statsLeague=d.league; if(d.season)state.statsSeason=d.season;
      fillLeagueSelect($('#tk-stats-league'),state.statsLeague); fillSeasonSelect($('#tk-stats-season'),d.seasons,state.statsSeason||d.seasons?.[0]||'');
      if(!state.statsSeason&&d.seasons?.length)state.statsSeason=d.seasons[0]; updateStatsFilterVisibility();
      const x=d.stats||{},vals=[x.rank?`#${x.rank}`:'—',Number(x.points||0).toFixed(0),String(x.winner_hits||0),`${Number(x.efficiency||0).toFixed(1).replace('.',',')}%`,String(x.submissions||0),String(x.perfect_rounds||0)];
      $$('#tk-ach-grid strong').forEach((el,i)=>el.textContent=vals[i]??'—');
    }catch(e){toast(e.message,true);}
  };

  const setupPicks=()=>{
    const panel=$('[data-panel="picks"]'); if(!panel||$('#tk-ml-picks'))return;
    panel.classList.add('tk-ml-host');
    const box=document.createElement('div');box.id='tk-ml-picks';box.className='tk-ml-picks';
    box.innerHTML='<div class="tk-loading">Ładowanie otwartych kolejek…</div>';panel.appendChild(box);
  };

  const roundSummary=r=>`<div class="tk-round-summary"><div><span class="tk-league-badge ${leagueClass(r.league)}">${esc(r.league_label)}</span><strong>${esc(r.title)}</strong><small>Sezon ${esc(r.season)} · ${r.match_count} meczów${r.closes_at_iso?' · typowanie do '+esc(fmt(r.closes_at_iso)):''}</small></div><div class="tk-round-state ${r.submitted?'is-done':''}">${r.submitted?'TYPOWANIE ZAPISANE':'OTWARTE'}</div><span class="tk-chevron">⌄</span></div>`;
  const renderOpenRounds=()=>{
    const box=$('#tk-ml-picks');if(!box||!state.boot)return; const rounds=state.boot.open_rounds||[];
    if(!rounds.length){box.innerHTML='<div class="dt-empty-front">Aktualnie nie ma żadnej otwartej kolejki do typowania.</div>';return;}
    const grouped=new Map();rounds.forEach(r=>{const key=r.league+(r.group?'-'+r.group:'');if(!grouped.has(key))grouped.set(key,{label:r.league_label,league:r.league,items:[]});grouped.get(key).items.push(r);});
    box.innerHTML=[...grouped.values()].map(g=>`<section class="tk-league-section"><div class="tk-league-title"><span class="tk-league-badge ${leagueClass(g.league)}">${esc(g.label)}</span><span>${g.items.length} ${g.items.length===1?'otwarta kolejka':'otwarte kolejki'}</span></div>${g.items.map(r=>`<details class="tk-round" data-round-id="${r.id}"><summary>${roundSummary(r)}</summary><div class="tk-round-body" data-round-body="${r.id}"><div class="tk-loading">Ładowanie meczów…</div></div></details>`).join('')}</section>`).join('');
    box.querySelectorAll('.tk-round').forEach(d=>d.addEventListener('toggle',()=>{if(d.open)loadRound(Number(d.dataset.roundId));}));
  };

  const loadRound=async id=>{
    const body=root.querySelector(`[data-round-body="${id}"]`);if(!body)return;
    if(state.rounds.has(id)){renderRound(id);return;}
    try{const data=await api(`round/${id}`);state.rounds.set(id,{data,picks:new Map((data.matches||[]).filter(m=>m.prediction?.selected_team_id).map(m=>[Number(m.id),Number(m.prediction.selected_team_id)])),saving:false});renderRound(id);}catch(e){body.innerHTML=`<div class="dt-empty-front">${esc(e.message)}</div>`;}
  };
  const renderRound=id=>{
    const s=state.rounds.get(id),body=root.querySelector(`[data-round-body="${id}"]`);if(!s||!body)return;const d=s.data,matches=d.matches||[],submitted=!!d.submission?.submitted,can=!!d.can_submit&&!submitted;
    body.innerHTML=`<div class="tk-round-meta"><span>${submitted?'Twoje typowanie zostało zapisane.':can?'Wybierz zwycięzcę każdego meczu.':'Typowanie tej kolejki jest zamknięte.'}</span><strong>${s.picks.size}/${matches.length}</strong></div><div class="tk-match-list">${matches.map(m=>matchCard(id,m,s,can)).join('')}</div>${can?`<div class="tk-save-row"><span>Po zapisaniu nie będzie można zmienić typów.</span><button type="button" data-save-round="${id}" ${s.picks.size===matches.length&&matches.length?'':'disabled'}>Zapisz typowanie</button></div>`:''}`;
    body.querySelectorAll('[data-pick]').forEach(btn=>btn.addEventListener('click',()=>{s.picks.set(Number(btn.dataset.match),Number(btn.dataset.team));renderRound(id);}));
    body.querySelector('[data-save-round]')?.addEventListener('click',()=>saveRound(id));
  };
  const matchCard=(rid,m,s,can)=>{
    const selected=s.picks.get(Number(m.id))||0,fav=Number(cfg.favoriteTeamId||0)>0&&[Number(m.home_team_id),Number(m.away_team_id)].includes(Number(cfg.favoriteTeamId));
    const result=m.score_home!==null&&m.score_home!==undefined&&m.score_away!==null&&m.score_away!==undefined;
    return `<article class="tk-match ${fav?'is-favorite':''} ${m.is_bonus?'is-bonus':''}">${fav?'<span class="tk-fav-ribbon">ULUBIONA DRUŻYNA</span>':''}${m.is_bonus?'<span class="tk-bonus-ribbon">BONUS</span>':''}<div class="tk-match-time">${esc(fmt(m.starts_at_iso))}${result?`<strong>${m.score_home} : ${m.score_away}</strong>`:''}</div><div class="tk-team-grid"><button type="button" data-pick data-match="${m.id}" data-team="${m.home_team_id}" class="${selected===Number(m.home_team_id)?'is-selected':''}" ${can?'':'disabled'}>${logo(m.home_name,m.home_logo)}<strong>${esc(m.home_name)}</strong><small>${selected===Number(m.home_team_id)?'TWÓJ TYP':'GOSPODARZ'}</small></button><span class="tk-vs">VS</span><button type="button" data-pick data-match="${m.id}" data-team="${m.away_team_id}" class="${selected===Number(m.away_team_id)?'is-selected':''}" ${can?'':'disabled'}>${logo(m.away_name,m.away_logo)}<strong>${esc(m.away_name)}</strong><small>${selected===Number(m.away_team_id)?'TWÓJ TYP':'GOŚĆ'}</small></button></div></article>`;
  };
  const saveRound=async id=>{
    const s=state.rounds.get(id);if(!s||s.saving)return;const matches=s.data.matches||[];if(s.picks.size!==matches.length){toast('Wybierz zwycięzcę każdego meczu.',true);return;}if(!confirm('Zapisać to typowanie? Po zatwierdzeniu nie będzie można go edytować.'))return;
    s.saving=true;try{await api('submission',{method:'POST',body:JSON.stringify({round_id:id,picks:[...s.picks.entries()].map(([match_id,team_id])=>({match_id,team_id}))})});toast('Typowanie zapisane.');const fresh=await api(`round/${id}`);s.data=fresh;renderRound(id);await refreshBootstrap();await loadStats();state.myLoaded=false;}catch(e){toast(e.message,true);}finally{s.saving=false;}
  };

  const setupRanking=()=>{
    const panel=$('[data-panel="ranking"]');if(!panel||$('#tk-ml-ranking'))return;panel.classList.add('tk-ml-host');
    const box=document.createElement('div');box.id='tk-ml-ranking';box.className='tk-ml-ranking';box.innerHTML=`<div class="tk-panel-title"><div><span class="dt-front-kicker">KLASYFIKACJA</span><h1>Ranking</h1></div></div><div class="tk-ranking-controls"><div class="tk-segment"><button class="is-active" data-rank-scope="all">Wszechczasów</button><button data-rank-scope="league">Liga</button><button data-rank-scope="season">Sezon</button><button data-rank-scope="round">Kolejka</button></div><select id="tk-rank-league" class="tk-filter-select" hidden></select><select id="tk-rank-group" class="tk-filter-select" hidden><option value="">Wszystkie grupy 2LM</option><option>A</option><option>B</option><option>C</option><option>D</option></select><select id="tk-rank-season" class="tk-filter-select" hidden></select><select id="tk-rank-round" class="tk-filter-select" hidden></select></div><div id="tk-rank-list" class="tk-rank-list"><div class="tk-loading">Ładowanie rankingu…</div></div>`;panel.appendChild(box);
    box.addEventListener('click',e=>{const b=e.target.closest('[data-rank-scope]');if(!b)return;state.rankScope=b.dataset.rankScope;box.querySelectorAll('[data-rank-scope]').forEach(x=>x.classList.toggle('is-active',x===b));loadRanking();});
    $('#tk-rank-league')?.addEventListener('change',e=>{state.rankLeague=e.target.value;state.rankGroup='';state.rankSeason='';state.rankRound=0;loadRanking();});
    $('#tk-rank-group')?.addEventListener('change',e=>{state.rankGroup=e.target.value;state.rankSeason='';state.rankRound=0;loadRanking();});
    $('#tk-rank-season')?.addEventListener('change',e=>{state.rankSeason=e.target.value;state.rankRound=0;loadRanking();});
    $('#tk-rank-round')?.addEventListener('change',e=>{state.rankRound=Number(e.target.value||0);loadRanking();});
  };
  const rankingVisibility=()=>{
    const l=$('#tk-rank-league'),g=$('#tk-rank-group'),s=$('#tk-rank-season'),r=$('#tk-rank-round');if(!l)return;
    l.hidden=state.rankScope==='all'; g.hidden=state.rankScope==='all'||state.rankLeague!=='2lm'; s.hidden=!['season','round'].includes(state.rankScope); r.hidden=state.rankScope!=='round';
  };
  const loadRanking=async()=>{
    const box=$('#tk-rank-list');if(!box)return;box.innerHTML='<div class="tk-loading">Ładowanie rankingu…</div>';
    try{const d=await api('multileague/ranking?'+query({scope:state.rankScope,league:state.rankLeague,group:state.rankGroup,season:state.rankSeason,round_id:state.rankRound}));
      if(d.league)state.rankLeague=d.league;if(d.group!==undefined)state.rankGroup=d.group;if(d.season)state.rankSeason=d.season;if(d.round_id)state.rankRound=Number(d.round_id);
      fillLeagueSelect($('#tk-rank-league'),state.rankLeague);fillSeasonSelect($('#tk-rank-season'),d.seasons,state.rankSeason||d.seasons?.[0]||'');if(!state.rankSeason&&d.seasons?.length)state.rankSeason=d.seasons[0];
      const rs=$('#tk-rank-round');if(rs)rs.innerHTML=(d.rounds||[]).map(r=>`<option value="${r.id}" ${Number(r.id)===state.rankRound?'selected':''}>${esc(r.title)}${r.group_code?' · '+esc(r.group_code):''}</option>`).join('');if(!state.rankRound&&d.rounds?.length)state.rankRound=Number(d.rounds[d.rounds.length-1].id);
      const gs=$('#tk-rank-group');if(gs)gs.value=state.rankGroup;rankingVisibility();renderRanking(d.ranking||[]);
    }catch(e){box.innerHTML=`<div class="dt-empty-front">${esc(e.message)}</div>`;}
  };
  const renderRanking=rows=>{const box=$('#tk-rank-list');if(!box)return;if(!rows.length){box.innerHTML='<div class="dt-empty-front">Brak wyników dla wybranego zakresu.</div>';return;}box.innerHTML=rows.map(r=>`<div class="tk-rank-row ${Number(r.user_id)===Number(base.userId||0)?'is-me':''}"><div class="tk-rank-pos">${r.rank<=3?['🥇','🥈','🥉'][r.rank-1]:r.rank}</div><div class="tk-rank-name"><strong>${esc(r.display_name)}</strong><small>${r.predictions} typów · ${Number(r.efficiency||0).toFixed(1).replace('.',',')}%</small></div><div><span>Punkty</span><strong>${Number(r.points||0).toFixed(0)}</strong></div><div><span>Trafienia</span><strong>${r.winner_hits||0}</strong></div><div><span>Perfekcyjne</span><strong>${r.perfect_rounds||0}</strong></div></div>`).join('');};

  const setupMine=()=>{
    const panel=$('[data-panel="mine"]');if(!panel||$('#tk-ml-my'))return;panel.classList.add('tk-ml-host');const box=document.createElement('div');box.id='tk-ml-my';box.innerHTML='<div class="tk-panel-title"><div><span class="dt-front-kicker">TWOJA HISTORIA</span><h1>Moje typy</h1></div></div><div id="tk-my-list"><div class="tk-loading">Ładowanie typowań…</div></div>';panel.appendChild(box);
  };
  const loadMine=async()=>{if(state.myLoaded)return;try{const d=await api('multileague/my-types');renderMine(d.items||[]);state.myLoaded=true;}catch(e){$('#tk-my-list').innerHTML=`<div class="dt-empty-front">${esc(e.message)}</div>`;}};
  const renderMine=items=>{const box=$('#tk-my-list');if(!box)return;if(!items.length){box.innerHTML='<div class="dt-empty-front">Nie masz jeszcze zapisanych typowań.</div>';return;}box.innerHTML=items.map((x,i)=>{const known=x.matches.filter(m=>m.result_known),hits=known.filter(m=>m.scoring_code==='winner').length,pts=x.matches.reduce((a,m)=>a+Number(m.points||0),0);return `<details class="tk-my-type" ${i===0?'open':''}><summary><div><span class="tk-league-badge ${leagueClass(x.league)}">${esc(x.league_label)}</span><strong>Typowanie #${x.round_no} · ${esc(x.title)}</strong><small>Sezon ${esc(x.season)}</small></div><div class="tk-my-meta">${known.length}/${x.matches.length} rozliczonych · ${hits} trafień · ${pts.toFixed(0)} pkt</div><span class="tk-chevron">⌄</span></summary><div class="tk-my-body">${x.matches.map(m=>`<div class="tk-my-match ${m.result_known?(m.scoring_code==='winner'?'is-hit':'is-miss'):'is-pending'}"><div><strong>${esc(m.home_name)} – ${esc(m.away_name)}</strong><small>${esc(fmt(m.starts_at_iso))}</small></div><div><span>Twój typ</span><strong>${esc(m.selected_team_name)}</strong></div><div><span>Wynik</span><strong>${m.result_known?`${m.score_home}:${m.score_away}`:'—'}</strong></div><div><span>Punkty</span><strong>${m.result_known?Number(m.points||0).toFixed(0):'—'}</strong></div></div>`).join('')}</div></details>`;}).join('');};

  const bindTabs=()=>root.addEventListener('click',e=>{const tab=e.target.closest('[data-tab]');if(!tab)return;if(tab.dataset.tab==='ranking')loadRanking();if(tab.dataset.tab==='mine')loadMine();});
  const refreshBootstrap=async()=>{state.boot=await api('multileague/bootstrap');fillLeagueSelect($('#tk-stats-league'),state.statsLeague);fillLeagueSelect($('#tk-rank-league'),state.rankLeague);renderOpenRounds();};

  const init=async()=>{setupAchievements();setupPicks();setupRanking();setupMine();bindTabs();try{await refreshBootstrap();await loadStats();await loadRanking();}catch(e){toast(e.message,true);}};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
