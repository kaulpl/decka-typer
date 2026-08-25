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
    const align=()=>{queued=false;const landing=document.querySelector('.dt-login-wrap');const h1=document.querySelector('.dt-ad-slot-h1');const anchor=document.querySelector('#dt-ad-h1-anchor');const target=landing?(h1||anchor):document.querySelector('.dt-app-main');if(!target)return;const targetTop=target.getBoundingClientRect().top+(landing&&!h1?16:0);const maxTop=Math.max(20,window.innerHeight-625);const top=Math.min(maxTop,Math.max(20,targetTop));sides.forEach(slot=>slot.style.setProperty('--dt-ad-side-top',`${Math.round(top)}px`))};
    const schedule=()=>{if(!queued){queued=true;requestAnimationFrame(align)}};
    align();window.addEventListener('scroll',schedule,{passive:true});window.addEventListener('resize',schedule,{passive:true});
  }
  if(!ads.length)return;
  if(!('IntersectionObserver'in window)){ads.forEach(report);return}
  const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting&&entry.intersectionRatio>=.5){report(entry.target);observer.unobserve(entry.target)}}),{threshold:[.5]});
  ads.forEach(ad=>observer.observe(ad));
})();
