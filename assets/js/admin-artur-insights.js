(function(){
  'use strict';
  const cfg=window.DeckaTyperArturInsights||{};
  const post=async data=>{const response=await fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(data)});const json=await response.json();if(!response.ok||!json.success)throw new Error(json.data&&json.data.message||'Nie udało się wykonać operacji.');return json.data;};
  const warmButton=document.querySelector('[data-dt-artur-warm]');
  const warmStatus=document.querySelector('[data-dt-artur-warm-status]');
  const wait=ms=>new Promise(resolve=>window.setTimeout(resolve,ms));
  async function pollWarm(jobId){
    for(;;){await wait(1800);const state=await post({action:'dt_artur_warm_status',nonce:cfg.nonce||'',job_id:jobId});warmStatus.textContent='Pobrano '+state.done+' z '+state.total+(state.errors?' · błędy: '+state.errors:'');if(state.state==='complete'){warmStatus.textContent=state.errors?'Zakończono: '+state.done+' pozycji, błędy: '+state.errors+'.':'Gotowe — przygotowano '+state.done+' pozycji.';warmButton.disabled=false;return;}}
  }
  if(warmButton)warmButton.addEventListener('click',async function(){
    const items=Array.from(document.querySelectorAll('.dt-artur-insights[data-match-id][data-mode]')).map(box=>({match_id:box.dataset.matchId,mode:box.dataset.mode}));
    if(!items.length)return;warmButton.disabled=true;warmStatus.textContent='Tworzę kolejkę pobierania…';
    try{const job=await post({action:'dt_artur_warm_start',nonce:cfg.nonce||'',items:JSON.stringify(items)});warmStatus.textContent='Uruchomiono: 0 z '+job.total;await pollWarm(job.job_id);}catch(error){warmStatus.textContent=String(error.message||error);warmButton.disabled=false;}
  });
  document.addEventListener('toggle',async function(event){
    const details=event.target;if(!(details instanceof HTMLDetailsElement)||!details.open||!details.classList.contains('dt-artur-match'))return;
    const box=details.querySelector('.dt-artur-insights');if(!box||box.dataset.loaded==='1'||box.dataset.loading==='1')return;
    box.dataset.loading='1';box.innerHTML='<p class="dt-artur-loading"><span class="spinner is-active"></span>Pobieram statystyki i historię…</p>';
    try{const data=await post({action:'dt_artur_insights',nonce:cfg.nonce||'',match_id:box.dataset.matchId||'',mode:box.dataset.mode||''});box.innerHTML=data.html;box.dataset.loaded='1';}
    catch(error){box.innerHTML='<p class="dt-artur-detail-error">'+String(error.message||error)+'</p>';}
    finally{delete box.dataset.loading;}
  },true);
})();
