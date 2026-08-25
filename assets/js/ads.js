(()=>{
  const cfg=window.TypujKoszaAds||{};
  const seen=ad=>`dt_ad_seen_${ad.dataset.dtAd}`;
  const report=ad=>{
    try{if(sessionStorage.getItem(seen(ad)))return;sessionStorage.setItem(seen(ad),'1')}catch(_){ }
    fetch(`${cfg.root||'/wp-json/decka-typer/v1/'}ads/impression`,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify({ad_id:Number(ad.dataset.dtAd),token:ad.dataset.dtAdToken||''})}).catch(()=>{});
  };
  const ads=[...document.querySelectorAll('[data-dt-ad]')];
  const sides=[...document.querySelectorAll('.dt-ad-slot-s1,.dt-ad-slot-s2')];
  if(sides.length){
    let queued=false;
    const align=()=>{queued=false;const main=document.querySelector('.dt-app-main');if(!main)return;const maxTop=Math.max(20,window.innerHeight-620);const top=Math.min(maxTop,Math.max(20,main.getBoundingClientRect().top));sides.forEach(slot=>slot.style.setProperty('--dt-ad-side-top',`${Math.round(top)}px`))};
    const schedule=()=>{if(!queued){queued=true;requestAnimationFrame(align)}};
    align();window.addEventListener('scroll',schedule,{passive:true});window.addEventListener('resize',schedule,{passive:true});
  }
  if(!ads.length)return;
  if(!('IntersectionObserver'in window)){ads.forEach(report);return}
  const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting&&entry.intersectionRatio>=.5){report(entry.target);observer.unobserve(entry.target)}}),{threshold:[.5]});
  ads.forEach(ad=>observer.observe(ad));
})();
