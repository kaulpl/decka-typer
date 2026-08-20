(()=>{
  const root=document.getElementById('decka-typer');
  if(!root)return;
  const qa=(s,c=root)=>[...c.querySelectorAll(s)];

  const ensureOutcomeBadge=(button,label,type)=>{
    let badge=button.querySelector('.dt-outcome-mark');
    if(!badge){
      badge=document.createElement('span');
      badge.className='dt-outcome-mark';
      button.appendChild(badge);
    }
    badge.classList.toggle('is-winner',type==='winner');
    badge.classList.toggle('is-loser',type==='loser');
    badge.textContent=label;
  };

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

    card.classList.add('has-resolved-outcome');
    buttons.forEach(btn=>btn.classList.remove('is-actual-winner','is-actual-loser'));
    buttons[winnerIndex].classList.add('is-actual-winner');
    buttons[loserIndex].classList.add('is-actual-loser');

    // Keep the user's own "TWÓJ TYP" marker intact and add a separate,
    // explicit match-outcome marker below the team. This means a correct pick
    // can display both "TWÓJ TYP" and "ZWYCIĘZCA" at the same time.
    ensureOutcomeBadge(buttons[winnerIndex],'ZWYCIĘZCA','winner');
    ensureOutcomeBadge(buttons[loserIndex],'PRZEGRANY','loser');
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
