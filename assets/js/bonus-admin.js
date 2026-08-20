(()=>{
  const cfg=window.DeckaTyperBonusAdmin||{};
  const ids=new Set((cfg.matchIds||[]).map(Number));

  const decorateMatches=()=>{
    document.querySelectorAll('.dt-edit-match').forEach(edit=>{
      const cell=edit.closest('td');
      if(!cell||cell.querySelector('.dt-bonus-toggle'))return;
      let data={};
      try{data=JSON.parse(edit.dataset.match||'{}');}catch(_){return;}
      const id=Number(data.id||0);if(!id)return;
      const active=ids.has(id);
      const form=document.createElement('form');
      form.method='post';form.action=cfg.actionUrl||'';form.className='dt-bonus-toggle';
      form.innerHTML=`<input type="hidden" name="action" value="dt_toggle_bonus"><input type="hidden" name="match_id" value="${id}"><input type="hidden" name="_wpnonce" value="${String(cfg.nonce||'')}"><button type="submit" class="button ${active?'is-active':''}">${active?'★ BONUS · usuń':'★ Ustaw BONUS'}</button>`;
      cell.appendChild(form);
      if(active){
        const row=edit.closest('tr');
        if(row)row.classList.add('dt-admin-bonus-row');
      }
    });
  };

  const injectSettings=()=>{
    const form=document.querySelector('.dt-settings');
    if(!form||form.querySelector('[name="bonus_points"]'))return;
    const savebar=form.querySelector('.dt-savebar');
    const section=document.createElement('section');
    section.className='dt-card dt-bonus-settings-card';
    section.innerHTML=`<span class="dt-eyebrow">MECZ BONUS</span><h2>Dodatkowe punkty</h2><div class="dt-form-2"><label>Dodatkowe punkty za trafienie meczu BONUS<input type="number" min="0" step="1" name="bonus_points" value="${Number(cfg.points||0)}"></label></div><p class="dt-muted">To punkty dodatkowe do standardowej punktacji za poprawnego zwycięzcę. W każdej kolejce możesz oznaczyć maksymalnie jeden mecz jako BONUS.</p>`;
    if(savebar)form.insertBefore(section,savebar);else form.appendChild(section);
  };

  decorateMatches();injectSettings();
})();
