(function(){
  'use strict';
  const cfg=window.DeckaTyperArturInsights||{};
  document.addEventListener('toggle',async function(event){
    const details=event.target;if(!(details instanceof HTMLDetailsElement)||!details.open||!details.classList.contains('dt-artur-match'))return;
    const box=details.querySelector('.dt-artur-insights');if(!box||box.dataset.loaded==='1'||box.dataset.loading==='1')return;
    box.dataset.loading='1';box.innerHTML='<p class="dt-artur-loading"><span class="spinner is-active"></span>Pobieram statystyki i historię…</p>';
    const body=new URLSearchParams({action:'dt_artur_insights',nonce:cfg.nonce||'',match_id:box.dataset.matchId||'',mode:box.dataset.mode||''});
    try{const response=await fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});const json=await response.json();if(!response.ok||!json.success)throw new Error(json.data&&json.data.message||'Nie udało się pobrać analizy.');box.innerHTML=json.data.html;box.dataset.loaded='1';}
    catch(error){box.innerHTML='<p class="dt-artur-detail-error">'+String(error.message||error)+'</p>';}
    finally{delete box.dataset.loading;}
  },true);
})();
