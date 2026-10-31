(()=>{
  const q=(s,c=document)=>c.querySelector(s), qa=(s,c=document)=>[...c.querySelectorAll(s)];
  const open=id=>{const d=document.getElementById(id); if(d && typeof d.showModal==='function') d.showModal();};
  const close=d=>{if(d?.open)d.close();};
  document.addEventListener('click',e=>{
    const media=e.target.closest('[data-dt-media]');
    if(media&&window.wp?.media){
      e.preventDefault();
      const frame=wp.media({title:'Wybierz grafikę reklamy',button:{text:'Użyj tej grafiki'},multiple:false});
      frame.on('select',()=>{const item=frame.state().get('selection').first().toJSON();const input=media.closest('.dt-media-field')?.querySelector('[data-dt-media-input]');if(input)input.value=item.url||''});
      frame.open();return;
    }
    const o=e.target.closest('[data-dt-open]'); if(o){e.preventDefault();open(o.dataset.dtOpen);return;}
    const c=e.target.closest('[data-dt-close]'); if(c){e.preventDefault();close(c.closest('dialog'));return;}
    const edit=e.target.closest('.dt-edit-match');
    if(edit){
      e.preventDefault(); let m={}; try{m=JSON.parse(edit.dataset.match||'{}')}catch(_){return;}
      const d=q('#dt-match-modal'); if(!d)return;
      q('[data-field="id"]',d).value=m.id||'';
      q('[data-field="starts_at"]',d).value=m.starts_at||'';
      q('[data-field="home_score"]',d).value=m.home_score===null||m.home_score===undefined?'':m.home_score;
      q('[data-field="away_score"]',d).value=m.away_score===null||m.away_score===undefined?'':m.away_score;
      const lock=q('[data-field="manual_lock"]',d); if(lock)lock.checked=true;
      const title=q('h2',d); if(title)title.textContent=`${m.home||''} – ${m.away||''}`;
      open('dt-match-modal');
    }
  });
  qa('dialog.dt-modal').forEach(d=>d.addEventListener('click',e=>{if(e.target===d)close(d)}));

  let liveTimer=0,liveController=null;
  const liveLoad=async(form,url=null)=>{
    const selector=form.dataset.dtLiveTarget;if(!selector)return;
    const target=q(selector);if(!target)return;
    const params=new URLSearchParams(new FormData(form));params.delete('dt_paged');
    const endpoint=new URL(form.action||location.href,location.href);endpoint.search='';
    const requestUrl=url||`${endpoint.toString()}?${params.toString()}`;
    if(liveController)liveController.abort();liveController=new AbortController();
    form.classList.add('is-loading');q('.spinner',form)?.classList.add('is-active');target.setAttribute('aria-busy','true');
    try{
      const response=await fetch(requestUrl,{credentials:'same-origin',signal:liveController.signal,headers:{'X-Requested-With':'XMLHttpRequest'}});
      if(!response.ok)throw new Error('Nie udało się pobrać wyników.');
      const html=await response.text(),doc=new DOMParser().parseFromString(html,'text/html'),fresh=doc.querySelector(selector);
      if(!fresh)throw new Error('Brak wyników w odpowiedzi.');
      target.innerHTML=fresh.innerHTML;history.replaceState({},'',requestUrl);
    }catch(error){if(error.name!=='AbortError')target.innerHTML='<div class="notice notice-error"><p>Nie udało się odświeżyć wyników.</p></div>';}
    finally{form.classList.remove('is-loading');q('.spinner',form)?.classList.remove('is-active');target.removeAttribute('aria-busy');}
  };
  document.addEventListener('submit',e=>{const form=e.target.closest('.dt-admin-live-filter');if(!form)return;e.preventDefault();liveLoad(form);});
  document.addEventListener('input',e=>{const input=e.target.closest('.dt-admin-live-filter input[type="search"]');if(!input)return;clearTimeout(liveTimer);liveTimer=setTimeout(()=>liveLoad(input.form),Number(window.DeckaTyperAdmin?.searchDelay||300));});
  document.addEventListener('change',e=>{const field=e.target.closest('.dt-prediction-filters select,.dt-prediction-filters input:not([type="search"])');if(field)liveLoad(field.form);});
  document.addEventListener('click',e=>{const link=e.target.closest('[data-dt-live-results] .dt-pagination a');if(!link)return;const form=q('.dt-admin-live-filter[data-dt-live-target=".'+link.closest('[data-dt-live-results]').classList[0]+'"]')||q('.dt-admin-live-filter');if(form){e.preventDefault();liveLoad(form,link.href);}});
})();
