(()=>{
  const cfg=window.DeckaTyperLeagueData||{};
  const root=document.getElementById('decka-typer');
  if(!root)return;

  const q=(s,c=root)=>c.querySelector(s);
  const qa=(s,c=root)=>[...c.querySelectorAll(s)];
  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt',"'":'&#039;','"':'&quot;'}[m]));

  // Unsaved picks are intentionally ephemeral. Changing round or reloading simply
  // discards them without browser/native confirmation dialogs.
  const originalConfirm=window.confirm.bind(window);
  window.confirm=message=>String(message||'').includes('Masz niezapisane typy')?true:originalConfirm(message);
  window.addEventListener('beforeunload',event=>{event.stopImmediatePropagation();},true);

  let activeTeams=cfg.teams||{};
  let activeRoundId=0;
  let contextSequence=0;
  const contextCache=new Map();
  const contextRequests=new Map();

  const currentRoundId=()=>Number(q('#dt-round-select')?.value||0);
  const teamData=id=>activeTeams?.[String(id)]||cfg.teams?.[String(id)]||{position:null,form:[null,null,null,null,null]};

  const formDot=(item,teamLogo)=>{
    if(!item)return '<span class="dt-form-dot is-empty" aria-label="Brak danych"></span>';
    const status=item.status==='win'?'is-win':item.status==='loss'?'is-loss':'is-empty';
    const label=item.status==='win'?'Wygrana':item.status==='loss'?'Porażka':'Brak rozstrzygnięcia';
    const score=`${Number(item.score_for||0)} : ${Number(item.score_against||0)}`;
    const title=`${label}${item.opponent_name?` z ${item.opponent_name}`:''} · ${score}`;
    const image=item.opponent_logo?`<img src="${esc(item.opponent_logo)}" alt="" loading="lazy">`:'';
    const ownLogo=teamLogo?`<img src="${esc(teamLogo)}" alt="" loading="lazy">`:'<i aria-hidden="true"></i>';
    const opponentLogo=item.opponent_logo?`<img src="${esc(item.opponent_logo)}" alt="" loading="lazy">`:'<i aria-hidden="true"></i>';
    return `<span class="dt-form-dot ${status}" tabindex="0" role="button" aria-label="${esc(title)}">${image}<span class="dt-form-tooltip" role="tooltip"><b>${esc(label)}</b><span class="dt-form-scoreline">${ownLogo}<strong>${esc(score)}</strong>${opponentLogo}</span></span></span>`;
  };

  const decorateTeamButton=btn=>{
    const data=teamData(btn.dataset.team);
    const strong=btn.querySelector('strong');

    let position=btn.querySelector('.dt-table-position');
    if(!position){
      position=document.createElement('span');
      position.className='dt-table-position';
      btn.appendChild(position);
    }
    position.textContent=data.position?`#${data.position}`:'#–';
    position.title=data.position?`Miejsce ${data.position} w tabeli 1LM`:'Brak aktualnej pozycji w tabeli';

    if(strong){
      let form=btn.querySelector('.dt-team-form');
      if(!form){
        form=document.createElement('span');
        form.className='dt-team-form';
        strong.insertAdjacentElement('afterend',form);
      }
      const items=Array.isArray(data.form)?data.form.slice(-5):[];
      while(items.length<5)items.unshift(null);
      const teamLogo=data.logo||btn.querySelector('.dt-team-logo img')?.src||'';
      form.innerHTML=items.map(item=>formDot(item,teamLogo)).join('');
    }
  };

  const fetchContext=roundId=>{
    roundId=Number(roundId||0);
    if(!roundId||!cfg.contextUrl)return Promise.resolve(null);
    if(contextCache.has(roundId))return Promise.resolve(contextCache.get(roundId));
    if(contextRequests.has(roundId))return contextRequests.get(roundId);

    const promise=(async()=>{
      const url=new URL(cfg.contextUrl,window.location.origin);
      url.searchParams.set('round_id',String(roundId));
      const response=await fetch(url.toString(),{
        credentials:'same-origin',
        cache:'no-store',
        headers:{Accept:'application/json'}
      });
      if(!response.ok)throw new Error(`HTTP ${response.status}`);
      const data=await response.json();
      contextCache.set(roundId,data);
      return data;
    })().finally(()=>contextRequests.delete(roundId));

    contextRequests.set(roundId,promise);
    return promise;
  };

  const optionRoundIds=()=>qa('#dt-round-select option').map(o=>Number(o.value||0)).filter(Boolean);

  const prefetchNeighbors=roundId=>{
    const ids=optionRoundIds();
    const index=ids.indexOf(Number(roundId));
    if(index<0)return;
    [ids[index-1],ids[index+1]].filter(Boolean).forEach(id=>{
      if(!contextCache.has(id)&&!contextRequests.has(id)){
        // Low-priority warm-up: the next arrow click should be instant.
        setTimeout(()=>fetchContext(id).catch(()=>{}),40);
      }
    });
  };

  const applyContext=(roundId,data)=>{
    if(!data||currentRoundId()!==Number(roundId))return;
    activeRoundId=Number(roundId);
    activeTeams=data.teams||cfg.teams||{};
    decorateMatches();
    prefetchNeighbors(roundId);
  };

  const loadRoundContext=async roundId=>{
    roundId=Number(roundId||currentRoundId());
    if(!roundId||!cfg.contextUrl)return;
    if(activeRoundId===roundId&&contextCache.has(roundId))return;

    const seq=++contextSequence;
    try{
      const data=await fetchContext(roundId);
      if(seq!==contextSequence&&currentRoundId()!==roundId)return;
      applyContext(roundId,data);
    }catch(_){
      if(currentRoundId()!==roundId)return;
      activeRoundId=roundId;
      activeTeams=cfg.teams||{};
      decorateMatches();
    }
  };

  const decorateMissedRound=()=>{
    const meta=q('#dt-round-meta');
    const matches=q('#dt-matches');
    if(!meta||!matches)return;

    const labels=qa('.dt-meta-pill',meta).map(node=>String(node.textContent||'').trim());
    const closed=labels.some(text=>text.includes('Typowanie zamknięte'));
    const submitted=labels.some(text=>text.includes('Kupon zapisany'));
    let note=q('#dt-missed-round-note');

    if(closed&&!submitted&&currentRoundId()>0){
      if(!note){
        note=document.createElement('div');
        note.id='dt-missed-round-note';
        note.className='dt-missed-round-note';
        note.innerHTML='<strong>Kolejka została zamknięta</strong><span>Nie oddałeś tutaj swojego typu. Możesz przejrzeć mecze i wyniki, ale kuponu nie można już zapisać.</span>';
        matches.insertAdjacentElement('beforebegin',note);
      }
    }else if(note){
      note.remove();
    }
  };

  const decorateResolvedMatch=card=>{
    const result=card.querySelector('.dt-result-row');
    if(!result||card.dataset.resultDecorated==='1')return;

    const scoreText=result.querySelector('strong')?.textContent||'';
    const match=scoreText.match(/(\d{1,3})\s*:\s*(\d{1,3})/);
    if(!match)return;

    const homeScore=Number(match[1]);
    const awayScore=Number(match[2]);
    const buttons=qa('.dt-team-choice',card);
    if(buttons.length<2)return;

    card.dataset.resultDecorated='1';
    result.hidden=true;

    [homeScore,awayScore].forEach((score,index)=>{
      const btn=buttons[index];
      let scoreNode=btn.querySelector('.dt-team-final-score');
      if(!scoreNode){
        scoreNode=document.createElement('span');
        scoreNode.className='dt-team-final-score';
        const form=btn.querySelector('.dt-team-form');
        if(form)form.insertAdjacentElement('afterend',scoreNode);
        else btn.querySelector('strong')?.insertAdjacentElement('afterend',scoreNode);
      }
      scoreNode.textContent=String(score);
    });

    const selectedIndex=buttons[0].classList.contains('is-selected')?0:buttons[1].classList.contains('is-selected')?1:-1;
    if(selectedIndex<0||homeScore===awayScore)return;
    const winnerIndex=homeScore>awayScore?0:1;
    const correct=selectedIndex===winnerIndex;
    card.classList.toggle('is-pick-correct',correct);
    card.classList.toggle('is-pick-wrong',!correct);
  };

  const decorateMatches=()=>{
    qa('#dt-matches .dt-match').forEach(card=>{
      qa('.dt-team-choice',card).forEach(decorateTeamButton);
      decorateResolvedMatch(card);
    });
    decorateMissedRound();
  };

  const enhanceRanking=()=>{
    qa('#dt-ranking .dt-rank-row').forEach(row=>{
      if(row.dataset.rankEnhanced==='1')return;
      const small=row.querySelector('.dt-rank-person small');
      const oldHits=row.querySelector('.dt-rank-exact');
      if(!small||!oldHits)return;
      const totalMatch=small.textContent.match(/(\d+)/);
      const hitMatch=oldHits.textContent.match(/(\d+)/);
      const total=totalMatch?Number(totalMatch[1]):0;
      const hits=hitMatch?Number(hitMatch[1]):0;
      const efficiency=total>0?(hits/total)*100:0;

      row.dataset.rankEnhanced='1';
      oldHits.className='dt-rank-hit-rate';
      oldHits.innerHTML=`<strong>${hits}/${total}</strong><small>trafione / typowane</small>`;

      const eff=document.createElement('div');
      eff.className='dt-rank-efficiency';
      eff.innerHTML=`<strong>${efficiency.toFixed(1)}%</strong><small>skuteczność</small>`;
      row.appendChild(eff);
    });
  };

  let matchScheduled=false;
  const scheduleMatchDecorate=()=>{
    if(matchScheduled)return;
    matchScheduled=true;
    requestAnimationFrame(()=>{
      matchScheduled=false;
      decorateMatches();
      const id=currentRoundId();
      if(id&&activeRoundId!==id)loadRoundContext(id);
    });
  };

  let rankScheduled=false;
  const scheduleRankEnhance=()=>{
    if(rankScheduled)return;
    rankScheduled=true;
    requestAnimationFrame(()=>{rankScheduled=false;enhanceRanking();});
  };

  const matchBox=q('#dt-matches');
  const floatingTooltip=document.createElement('div');
  floatingTooltip.className='dt-form-floating-tooltip';
  floatingTooltip.hidden=true;
  document.body.appendChild(floatingTooltip);
  const showFormTooltip=dot=>{
    const content=dot.querySelector('.dt-form-tooltip');if(!content)return;
    floatingTooltip.className=`dt-form-floating-tooltip ${dot.classList.contains('is-win')?'is-win':'is-loss'}`;
    floatingTooltip.innerHTML=content.innerHTML;floatingTooltip.hidden=false;
    const rect=dot.getBoundingClientRect(),tip=floatingTooltip.getBoundingClientRect();
    floatingTooltip.style.left=`${Math.max(8,Math.min(window.innerWidth-tip.width-8,rect.left+rect.width/2-tip.width/2))}px`;
    floatingTooltip.style.top=`${Math.max(8,rect.top-tip.height-8)}px`;
  };
  const hideFormTooltip=()=>{floatingTooltip.hidden=true;};
  root.addEventListener('mouseover',event=>{const dot=event.target.closest('.dt-form-dot:not(.is-empty)');if(dot)showFormTooltip(dot);});
  root.addEventListener('mouseout',event=>{const dot=event.target.closest('.dt-form-dot:not(.is-empty)');if(dot&&!dot.contains(event.relatedTarget)&&!dot.classList.contains('is-tooltip-open'))hideFormTooltip();});
  root.addEventListener('focusin',event=>{const dot=event.target.closest('.dt-form-dot:not(.is-empty)');if(dot)showFormTooltip(dot);});
  root.addEventListener('focusout',event=>{const dot=event.target.closest('.dt-form-dot:not(.is-empty)');if(dot&&!dot.classList.contains('is-tooltip-open'))hideFormTooltip();});
  root.addEventListener('click',event=>{
    const dot=event.target.closest('.dt-form-dot:not(.is-empty)');
    if(!dot)return;
    event.preventDefault();event.stopPropagation();
    qa('.dt-form-dot.is-tooltip-open').forEach(item=>{if(item!==dot){item.classList.remove('is-tooltip-open');item.closest('.dt-team-choice')?.classList.remove('has-form-tooltip-open');}});
    dot.classList.toggle('is-tooltip-open');
    dot.closest('.dt-team-choice')?.classList.toggle('has-form-tooltip-open',dot.classList.contains('is-tooltip-open'));
    if(dot.classList.contains('is-tooltip-open'))showFormTooltip(dot);else hideFormTooltip();
  },true);
  root.addEventListener('keydown',event=>{
    const dot=event.target.closest('.dt-form-dot:not(.is-empty)');
    if(!dot||!['Enter',' '].includes(event.key))return;
    event.preventDefault();event.stopPropagation();dot.click();
  },true);
  if(matchBox)new MutationObserver(scheduleMatchDecorate).observe(matchBox,{childList:true,subtree:true});
  const metaBox=q('#dt-round-meta');
  if(metaBox)new MutationObserver(scheduleMatchDecorate).observe(metaBox,{childList:true,subtree:true});
  const rankBox=q('#dt-ranking');
  if(rankBox)new MutationObserver(scheduleRankEnhance).observe(rankBox,{childList:true,subtree:true});

  q('#dt-round-select')?.addEventListener('change',event=>{
    const targetId=Number(event.target.value||0);
    activeRoundId=0;
    contextSequence++;
    // Start league context immediately, in parallel with frontend.js round request.
    loadRoundContext(targetId);
  });

  const warmArrowTarget=direction=>{
    const select=q('#dt-round-select');
    if(!select)return;
    const index=select.selectedIndex+direction;
    const option=select.options[index];
    if(option)fetchContext(Number(option.value||0)).catch(()=>{});
  };
  q('#dt-prev-round')?.addEventListener('click',()=>warmArrowTarget(-1));
  q('#dt-next-round')?.addEventListener('click',()=>warmArrowTarget(1));

  decorateMatches();
  enhanceRanking();
  loadRoundContext();
})();
