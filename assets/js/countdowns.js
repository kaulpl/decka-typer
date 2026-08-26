(()=>{
  const root=document.getElementById('decka-typer');
  if(!root)return;

  const format=milliseconds=>{
    const total=Math.max(0,Math.floor(milliseconds/1000));
    const days=Math.floor(total/86400);
    const hours=Math.floor((total%86400)/3600);
    const minutes=Math.floor((total%3600)/60);
    const seconds=total%60;
    return `${days}d ${hours}h ${minutes}m ${seconds}s`;
  };

  const formatCompact=milliseconds=>{
    const total=Math.max(0,Math.floor(milliseconds/1000));
    const days=Math.floor(total/86400);
    const hours=Math.floor((total%86400)/3600);
    const minutes=Math.floor((total%3600)/60);
    if(days>0)return `${days}d ${hours}h`;
    if(hours>0)return `${hours}h ${minutes}m`;
    return `${minutes}m`;
  };

  const update=()=>{
    const now=Date.now();
    root.querySelectorAll('[data-countdown-target]').forEach(element=>{
      const target=new Date(element.dataset.countdownTarget||'').getTime();
      if(!Number.isFinite(target))return;
      const value=element.querySelector('[data-countdown-value]');
      if(target>now){
        if(value)value.textContent=element.dataset.countdownCompact==='1'?formatCompact(target-now):format(target-now);
        return;
      }
      if(element.dataset.countdownHideExpired==='1'){
        element.remove();
        return;
      }
      const expired=element.dataset.countdownExpired||'Rozpoczęto';
      element.classList.add('is-expired');
      const label=element.querySelector('small');
      if(label)label.textContent='';
      if(value)value.textContent=expired;
      element.removeAttribute('data-countdown-target');
    });
  };

  update();
  window.setInterval(update,1000);
})();
