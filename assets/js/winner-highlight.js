(()=>{
  const root=document.getElementById('decka-typer');
  if(!root)return;
  const qa=(s,c=root)=>[...c.querySelectorAll(s)];

  const decorate=card=>{
    const result=card.querySelector('.dt-result-row');
    const buttons=qa('.dt-team-choice',card);
    if(!result||buttons.length<2)return;

    const scoreText=result.querySelector('strong')?.textContent||'';
    const match=scoreText.match(/(\d{1,3})\s*:\s*(\d{1,3})/);
    if(!match)return;

    const home=Number(match[1]);
    const away=Number(match[2]);
    if(!Number.isFinite(home)||!Number.isFinite(away)||home===away)return;

    const winnerIndex=home>away?0:1;
    const loserIndex=winnerIndex===0?1:0;

    buttons.forEach(btn=>btn.classList.remove('is-actual-winner','is-actual-loser'));
    buttons[winnerIndex].classList.add('is-actual-winner');
    buttons[loserIndex].classList.add('is-actual-loser');

    const winnerBadge=buttons[winnerIndex].querySelector('.dt-choice-mark');
    if(winnerBadge && !winnerBadge.dataset.actualWinnerLabel){
      winnerBadge.dataset.actualWinnerLabel='1';
      const current=winnerBadge.textContent.trim();
      if(current==='GOŚĆ'||current==='GOSPODARZ')winnerBadge.textContent='ZWYCIĘZCA';
    }
  };

  const decorateAll=()=>qa('#dt-matches .dt-match').forEach(decorate);
  let scheduled=false;
  const schedule=()=>{
    if(scheduled)return;
    scheduled=true;
    requestAnimationFrame(()=>{scheduled=false;decorateAll();});
  };

  const box=root.querySelector('#dt-matches');
  if(box)new MutationObserver(schedule).observe(box,{childList:true,subtree:true});
  decorateAll();
})();
