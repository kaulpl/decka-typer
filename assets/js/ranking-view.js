(()=>{
  const cfg=window.DeckaTyper||{};
  const root=document.getElementById('decka-typer');
  const panel=root?.querySelector('[data-panel="ranking"]');
  const box=document.getElementById('dt-ranking');
  const legacyToggle=panel?.querySelector('.dt-ranking-toggle');
  if(!root||!panel||!box||!legacyToggle||!cfg.root)return;

  let scope='season';
  let season=String(cfg.season||'');
  let roundId=0;
  let month='';
  let seasons=[];
  let rounds=[];
  let months=[];
  let league='1lm',leagues=[],group='',groups=[],favoriteTeams=[],favoriteTeamId=0;
  let page=1;
  let loadingSeq=0;

  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
  const n=v=>Number(v||0);
  const fmtPoints=v=>Number.isInteger(n(v))?String(n(v)):n(v).toFixed(1).replace('.',',');
  const medal=rank=>rank===1?'🥇':rank===2?'🥈':rank===3?'🥉':'';
  const normalizeGroup=value=>String(value||'').trim().toUpperCase().replace(/^GRUPA\s+/,'');
  const monthLabel=value=>{const parts=String(value||'').split('-');if(parts.length!==2)return String(value||'');const date=new Date(Number(parts[0]),Number(parts[1])-1,1);const label=new Intl.DateTimeFormat('pl-PL',{month:'long',year:'numeric'}).format(date);return label.charAt(0).toUpperCase()+label.slice(1);};

  legacyToggle.innerHTML=`
    <button data-rank-scope="all">Wszechczasów</button>
    <button class="is-active" data-rank-scope="season">Sezon</button>
    <button data-rank-scope="month">Miesiąc</button>
    <button data-rank-scope="round">Kolejka</button>`;

  const controls=panel.querySelector('.dt-ranking-controls')||legacyToggle.parentElement;
  const filters=document.createElement('div');
  filters.className='dt-ranking-filters';
  controls.appendChild(filters);

  const api=async()=>{
    const qs=new URLSearchParams({scope});
    if(scope!=='all'&&season)qs.set('season',season);
    if(league)qs.set('league',league);
    if(group)qs.set('group',group);
    if(league==='clubs'&&favoriteTeamId)qs.set('favorite_team_id',String(favoriteTeamId));
    if(scope==='month'&&month)qs.set('month',month);
    if(scope==='round'&&roundId)qs.set('round_id',String(roundId));
    qs.set('page',String(page));
    const headers={Accept:'application/json'};
    if(cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;
    const response=await fetch(cfg.root+'ranking-view?'+qs.toString(),{credentials:'same-origin',headers});
    const data=await response.json().catch(()=>({}));
    if(!response.ok)throw new Error(data.message||'Nie udało się pobrać rankingu.');
    return data;
  };

  const renderFilters=()=>{
    filters.innerHTML='';
    filters.classList.add('is-visible');
    const leagueBar=document.createElement('div');leagueBar.className='dt-filter-segmented dt-filter-leagues';leagueBar.dataset.label='Liga lub klub';leagueBar.setAttribute('aria-label','Wybierz ligę lub ranking klubowy');
    leagueBar.innerHTML=leagues.map(l=>`<button type="button" data-filter-league="${esc(l.key)}" class="${l.key===league?'is-active':''}">${esc(l.name)}</button>`).join('');
    filters.appendChild(leagueBar);
    if(league==='2lm'&&groups.length){const groupBar=document.createElement('div');groupBar.className='dt-filter-segmented dt-filter-groups';groupBar.dataset.label='Grupa 2LM';groupBar.setAttribute('aria-label','Wybierz grupę 2LM');groupBar.innerHTML=groups.map(g=>`<button type="button" data-filter-group="${esc(normalizeGroup(g))}" class="${normalizeGroup(g)===normalizeGroup(group)?'is-active':''}">GRUPA ${esc(normalizeGroup(g))}</button>`).join('');filters.appendChild(groupBar);}
    if(league==='clubs'){
      const clubSelect=document.createElement('select');
      clubSelect.className='dt-ranking-select dt-ranking-club-select';
      clubSelect.setAttribute('aria-label','Wybierz ulubiony klub kibiców');
      clubSelect.innerHTML=favoriteTeams.length?favoriteTeams.map(team=>`<option value="${Number(team.id)}" ${Number(team.id)===favoriteTeamId?'selected':''}>${esc(team.name)} (${Number(team.supporters||0)})</option>`).join(''):'<option value="">Brak klubów wybranych przez użytkowników</option>';
      clubSelect.disabled=!favoriteTeams.length;
      clubSelect.addEventListener('change',()=>{favoriteTeamId=Number(clubSelect.value||0);roundId=0;month='';page=1;load();});
      filters.appendChild(clubSelect);
    }
    if(scope==='all')return;

    const seasonBar=document.createElement('div');seasonBar.className='dt-filter-segmented dt-filter-seasons';seasonBar.dataset.label='Sezon';seasonBar.setAttribute('aria-label','Wybierz sezon');seasonBar.innerHTML=seasons.map(s=>`<button type="button" data-filter-season="${esc(s)}" class="${s===season?'is-active':''}">${esc(s)}</button>`).join('');filters.appendChild(seasonBar);

    if(scope==='month'){
      const monthSelect=document.createElement('select');
      monthSelect.className='dt-ranking-select';
      monthSelect.setAttribute('aria-label','Wybierz miesiąc');
      monthSelect.innerHTML=months.length?months.map(item=>`<option value="${esc(item)}" ${item===month?'selected':''}>${esc(monthLabel(item))}</option>`).join(''):'<option value="">Brak miesięcy z zapisanymi typami</option>';
      monthSelect.disabled=!months.length;
      monthSelect.addEventListener('change',()=>{month=monthSelect.value||'';page=1;load();});
      filters.appendChild(monthSelect);
    }else if(scope==='round'){
      const roundSelect=document.createElement('select');
      roundSelect.className='dt-ranking-select';
      roundSelect.setAttribute('aria-label','Wybierz kolejkę');
      roundSelect.innerHTML=rounds.map(r=>`<option value="${r.id}" ${Number(r.id)===Number(roundId)?'selected':''}>${esc(r.title||`${r.round_no}. kolejka`)}</option>`).join('');
      roundSelect.addEventListener('change',()=>{
        roundId=Number(roundSelect.value||0);
        page=1;
        load();
      });
      filters.appendChild(roundSelect);
    }
  };

  const renderPagination=meta=>{
    const current=Math.max(1,Number(meta?.page||1));
    const pages=Math.max(1,Number(meta?.pages||1));
    const total=Math.max(0,Number(meta?.total||0));
    const perPage=Math.max(1,Number(meta?.per_page||15));
    if(pages<=1)return '';
    const candidates=[1,current-2,current-1,current,current+1,current+2,pages].filter(value=>value>=1&&value<=pages);
    const numbers=[...new Set(candidates)].sort((a,b)=>a-b);
    let last=0;
    const buttons=numbers.map(value=>{
      const gap=last&&value-last>1?'<span class="dt-pagination-gap" aria-hidden="true">…</span>':'';
      last=value;
      return `${gap}<button type="button" data-rank-page="${value}" class="${value===current?'is-active':''}" ${value===current?'aria-current="page"':''} aria-label="Strona ${value}">${value}</button>`;
    }).join('');
    const first=(current-1)*perPage+1;
    const lastItem=Math.min(total,current*perPage);
    return `<nav class="dt-ranking-pagination" aria-label="Strony rankingu"><span class="dt-pagination-summary">Pozycje ${first}–${lastItem} z ${total}</span><div class="dt-pagination-buttons"><button type="button" data-rank-page="${current-1}" ${current<=1?'disabled':''} aria-label="Poprzednia strona">‹</button>${buttons}<button type="button" data-rank-page="${current+1}" ${current>=pages?'disabled':''} aria-label="Następna strona">›</button></div></nav>`;
  };

  const renderRows=(rows,pagination)=>{
    if(!Array.isArray(rows)||!rows.length){
      box.innerHTML='<div class="dt-empty-front">Brak danych dla wybranego rankingu.</div>';
      return;
    }
    box.innerHTML=`
      <div class="dt-ranking-head dt-ranking-grid">
        <div>#</div><div>Użytkownik</div><div>Punkty</div><div>Trafione / typowane</div><div>Skuteczność</div><div>Perfekcyjne 8/8</div><div>BONUS</div>
      </div>
      ${rows.map(r=>{
        const rank=Number(r.rank||0);
        const icon=medal(rank);
        return `<div class="dt-rank-row dt-ranking-grid ${rank<=3?'is-podium':''} ${r.is_expert?'is-expert':''}">
          <div class="dt-rank-pos">${rank}</div>
          <div class="dt-rank-person dt-rank-person-clean"><span class="dt-rank-user-copy"><strong>${icon?`<span class="dt-rank-medal" aria-label="Miejsce ${rank}">${icon}</span>`:''}<span class="dt-rank-name">${esc(r.display_name||'Kibic')}</span>${r.is_expert?'<span class="dt-expert-badge">EKSPERT!</span>':''}</strong><small>${Number(r.predictions||0)} typów</small></span></div>
          <div class="dt-rank-points" data-label="Punkty">${fmtPoints(r.points)} pkt</div>
          <div class="dt-rank-hit-rate" data-label="Trafienia"><strong>${Number(r.winner_hits||0)}/${Number(r.predictions||0)}</strong><small>trafione / typowane</small></div>
          <div class="dt-rank-efficiency" data-label="Skuteczność"><strong>${n(r.efficiency).toFixed(1).replace('.',',')}%</strong><small>skuteczność</small></div>
          <div class="dt-rank-perfect" data-label="Perfekcyjne kolejki"><strong>${Number(r.perfect_eight_rounds||0)}</strong><small>perfekcyjne 8/8</small></div>
          <div class="dt-rank-bonus" data-label="Bonus"><strong>${n(r.bonus_points)>0?`+${fmtPoints(r.bonus_points)} pkt`:'0'}</strong><small>${Number(r.bonus_hits||0)} trafień BONUS</small></div>
        </div>`;
      }).join('')}
      ${renderPagination(pagination)}`;
  };

  const load=async()=>{
    const seq=++loadingSeq;
    box.innerHTML='<div class="dt-empty-front">Ładowanie rankingu…</div>';
    try{
      const data=await api();
      if(seq!==loadingSeq)return;
      seasons=Array.isArray(data.seasons)?data.seasons:[];
      season=String(data.season||season||seasons[0]||'');
      rounds=Array.isArray(data.rounds)?data.rounds:[];
      months=Array.isArray(data.months)?data.months:[];
      leagues=Array.isArray(data.leagues)?data.leagues:[];
      groups=Array.isArray(data.groups)?data.groups.map(normalizeGroup).filter(Boolean):[];
      favoriteTeams=Array.isArray(data.favorite_teams)?data.favorite_teams:[];
      favoriteTeamId=Number(data.favorite_team_id||0);
      league=String(data.league||league||'all');
      group=normalizeGroup(data.group||group||groups[0]||'');
      roundId=Number(data.round_id||roundId||0);
      month=String(data.month||month||months[0]||'');
      page=Math.max(1,Number(data.pagination?.page||page));
      if(scope==='round'&&!roundId&&rounds.length)roundId=Number(rounds[rounds.length-1].id);
      renderFilters();
      renderRows(data.ranking||[],data.pagination||{});
    }catch(e){
      box.innerHTML=`<div class="dt-empty-front">${esc(e.message||'Nie udało się pobrać rankingu.')}</div>`;
    }
  };

  legacyToggle.querySelectorAll('[data-rank-scope]').forEach(btn=>btn.addEventListener('click',()=>{
    scope=btn.dataset.rankScope||'all';
    legacyToggle.querySelectorAll('[data-rank-scope]').forEach(x=>x.classList.toggle('is-active',x===btn));
    if(scope==='round')roundId=0;
    if(scope==='month')month='';
    page=1;
    load();
  }));

  root.addEventListener('click',e=>{
    if(e.target.closest('[data-tab="ranking"]'))setTimeout(load,0);
    const pageButton=e.target.closest('[data-rank-page]');if(pageButton&&!pageButton.disabled){page=Math.max(1,Number(pageButton.dataset.rankPage||1));load();panel.scrollIntoView({behavior:'smooth',block:'start'});return;}
    const leagueButton=e.target.closest('[data-filter-league]');if(leagueButton){league=leagueButton.dataset.filterLeague||'all';group='';favoriteTeamId=0;roundId=0;month='';page=1;load();return;}
    const groupButton=e.target.closest('[data-filter-group]');if(groupButton){group=normalizeGroup(groupButton.dataset.filterGroup);roundId=0;month='';page=1;load();return;}
    const seasonButton=e.target.closest('[data-filter-season]');if(seasonButton){season=seasonButton.dataset.filterSeason||season;roundId=0;month='';page=1;load();}
  });

  load();
})();
