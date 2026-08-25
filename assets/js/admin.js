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
})();
