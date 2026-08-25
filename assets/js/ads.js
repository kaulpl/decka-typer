(()=>{
  const cfg=window.TypujKoszaAds||{};
  const seen=ad=>`dt_ad_seen_${ad.dataset.dtAd}`;
  const report=ad=>{
    try{if(sessionStorage.getItem(seen(ad)))return;sessionStorage.setItem(seen(ad),'1')}catch(_){ }
    fetch(`${cfg.root||'/wp-json/decka-typer/v1/'}ads/impression`,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify({ad_id:Number(ad.dataset.dtAd),token:ad.dataset.dtAdToken||''})}).catch(()=>{});
  };
  const ads=[...document.querySelectorAll('[data-dt-ad]')];
  if(!ads.length)return;
  if(!('IntersectionObserver'in window)){ads.forEach(report);return}
  const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting&&entry.intersectionRatio>=.5){report(entry.target);observer.unobserve(entry.target)}}),{threshold:[.5]});
  ads.forEach(ad=>observer.observe(ad));
})();
