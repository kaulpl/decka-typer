(()=>{
  const cfg=window.DeckaTyperBonus||{};
  const ids=new Set((cfg.matchIds||[]).map(Number));
  const points=Number(cfg.points||0);
  const label=Number.isInteger(points)?String(points):points.toFixed(1).replace('.',',');

  const decorate=()=>{
    document.querySelectorAll('#dt-matches [data-match-card]').forEach(card=>{
      const id=Number(card.dataset.matchCard||0);
      const isBonus=ids.has(id);
      card.classList.toggle('is-bonus',isBonus);
      let ribbon=card.querySelector('.dt-bonus-ribbon');
      if(isBonus&&!ribbon){
        ribbon=document.createElement('div');
        ribbon.className='dt-bonus-ribbon';
        ribbon.innerHTML=`<span>★ BONUS</span><strong>+${label} PKT</strong>`;
        card.appendChild(ribbon);
      }else if(!isBonus&&ribbon){ribbon.remove();}
    });
  };

  const box=document.getElementById('dt-matches');
  if(box)new MutationObserver(()=>requestAnimationFrame(decorate)).observe(box,{childList:true,subtree:true});
  decorate();
})();
