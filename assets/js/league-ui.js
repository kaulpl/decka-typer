(()=>{
  const cfg=window.DeckaTyperLeagueData||{};
  const root=document.getElementById('decka-typer');
  if(!root)return;

  const q=(s,c=root)=>c.querySelector(s);
  const qa=(s,c=root)=>[...c.querySelectorAll(s)];
  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));

  // Unsaved picks are intentionally ephemeral. Changing round or reloading simply
  // discards them without browser/native confirmation dialogs.
  const originalConfirm=window.confirm.bind(window);
  window.confirm=message=>String(message||'').includes('Masz niezapisane typy')?true:originalConfirm(message);
  window.addEventListener('beforeunload',event=>{event.stopImmediatePropagation();},true);

  let activeTeams=cfg.teams||{};
  let activeRoundId=0;
  let contextSequence=0;

  const currentRoundId=()=>Number(q('#dt-round-select')?.value||0);
  const teamData=id=>activeTeams?.[String(id)]||cfg.teams?.[String(id)]||{position:null,form:[null,null,null,null,null]};

  const formDot=item=>{
    if(!item)return '<span class="dt-form-dot is-empty" aria-label="Brak danych"></span>';
    const status=item.status==='win'?'is-win':item.status==='loss'?'is-loss':'is-empty';
    const label=item.status==='win'?'Wygrana':item.status==='loss'?'Porażka':'Brak rozstrzygnięcia';
    const title=`${label}${item.opponent_name?` z ${item.opponent_name}`:''}`;
    const image=item.opponent_logo?`<img src="${esc(item.opponent_logo)}" alt="" loading="lazy">`:'';
    return `<span class="dt-form-dot ${status}" title="${esc(title)}" aria-label="${esc(title)}">${image}</span>`;
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
      form.innerHTML=items.map(formDot).join('');
    }
  };

  const loadRoundContext=async()=>{
    const roundId=currentRoundId();
    if(!roundId||!cfg.contextUrl)return;
    if(activeRoundId===roundId)return;

    const seq=++contextSequence;
    try{
      const url=new URL(cfg.contextUrl,window.location.origin);
      url.searchParams.set('round_id',String(roundId));
      const response=await fetch(url.toString(),{credentials:'same-origin',headers:{Accept:'application/json'}});
      if(!response.ok)throw new Error(`HTTP ${response.status}`);
      const data=await response.json();
      if(seq!==contextSequence||currentRoundId()!==roundId)return;
      activeRoundId=roundId;
      activeTeams=data.teams||cfg.teams||{};
      decorateMatches();
    }catch(_){
      if(seq!==contextSequence)return;
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
      loadRoundContext();
    });
  };

  let rankScheduled=false;
  const scheduleRankEnhance=()=>{
    if(rankScheduled)return;
    rankScheduled=true;
    requestAnimationFrame(()=>{rankScheduled=false;enhanceRanking();});
  };

  const matchBox=q('#dt-matches');
  if(matchBox)new MutationObserver(scheduleMatchDecorate).observe(matchBox,{childList:true,subtree:true});
  const metaBox=q('#dt-round-meta');
  if(metaBox)new MutationObserver(scheduleMatchDecorate).observe(metaBox,{childList:true,subtree:true});
  const rankBox=q('#dt-ranking');
  if(rankBox)new MutationObserver(scheduleRankEnhance).observe(rankBox,{childList:true,subtree:true});

  q('#dt-round-select')?.addEventListener('change',()=>{
    activeRoundId=0;
    contextSequence++;
    activeTeams=cfg.teams||{};
    setTimeout(scheduleMatchDecorate,0);
  });

  decorateMatches();
  enhanceRanking();
  loadRoundContext();
})();
