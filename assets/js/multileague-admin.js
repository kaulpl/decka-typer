(()=>{
  const cfg=window.TypujKoszaMultiLeagueAdmin||{};
  const roundCfg=window.TypujKoszaRoundContext||{};
  const q=s=>document.querySelector(s);
  document.addEventListener('DOMContentLoaded',()=>{
    q('[data-ml-open-add]')?.addEventListener('click',()=>q('#tk-ml-add-round')?.showModal());
    document.addEventListener('click',e=>{if(e.target.closest('[data-ml-close]'))q('#tk-ml-add-round')?.close();});
    const league=q('#tk-ml-league'),group=q('#tk-ml-group');
    const syncGroup=()=>{if(!league||!group)return;const two=league.value==='2lm';group.disabled=!two;if(!two)group.value='';};
    league?.addEventListener('change',syncGroup);syncGroup();

    const relabelRounds=()=>{
      const map=roundCfg.rounds||{};
      document.querySelectorAll('select').forEach(select=>{
        [...select.options].forEach(option=>{
          const id=String(option.value||'');
          if(map[id])option.textContent=map[id];
        });
      });
    };
    relabelRounds();

    const params=new URLSearchParams(location.search);
    if(params.get('page')==='decka-typer'){
      const wrap=q('.dt-admin');
      const firstSection=wrap?.querySelector('.dt-grid,.dt-section,.dt-card');
      if(wrap&&firstSection&&!q('.tk-admin-league-overview')){
        const block=document.createElement('section');block.className='dt-card tk-admin-league-overview';
        block.innerHTML=`<div class="tk-admin-overview-head"><div><span class="dt-eyebrow">WIELOLIGOWY TYPER</span><h2>PLK · 1LM · 2LM</h2><p>Obsługa kolejek, grup 2LM i źródeł danych jest dostępna w nowych modułach administracyjnych.</p></div><span class="tk-mode-chip is-${cfg.mode||'production'}">${cfg.mode==='test'?'TRYB TESTOWY':cfg.mode==='break'?'PRZERWA':'PRODUKCYJNY'}</span></div><div class="tk-admin-league-links"><a class="button button-primary" href="admin.php?page=decka-typer-rounds">Kolejki i ligi</a><a class="button" href="admin.php?page=decka-typer-leagues">Ligi i źródła</a></div>`;
        firstSection.parentNode.insertBefore(block,firstSection);
      }
    }

    const form=document.querySelector('.dt-settings');
    if(form){
      const leagueName=form.querySelector('[name="league_name"]');
      if(leagueName){
        const label=leagueName.closest('label');
        if(label)label.style.display='none';
      }
      const source=form.querySelector('[name="source_url"]');
      if(source){
        const label=source.closest('label');
        if(label){
          const text=[...label.childNodes].find(n=>n.nodeType===Node.TEXT_NODE&&n.nodeValue.trim());
          if(text)text.nodeValue='Adres terminarza 1LM ';
        }
      }
      const card=source?.closest('.dt-card');
      if(card){
        const h=card.querySelector('h2'); if(h)h.textContent='Sezon i synchronizacja 1LM';
        const kicker=card.querySelector('.dt-eyebrow');if(kicker)kicker.textContent='1LM · AUTOMATYCZNA SYNCHRONIZACJA';
      }
    }

    if(form&&!form.querySelector('[name="site_mode"]')){
      const savebar=form.querySelector('.dt-savebar');
      const section=document.createElement('section');section.className='dt-card tk-mode-card';
      section.innerHTML=`<span class="dt-eyebrow">TRYB SERWISU</span><h2>Status TypujKosza.pl</h2><p class="dt-muted">Steruje zachowaniem publicznej strony bez wpływu na dostęp administratora do WordPressa.</p><div class="tk-mode-options"><label><input type="radio" name="site_mode" value="production" ${cfg.mode==='production'?'checked':''}><span><strong>Produkcyjny</strong><small>Normalne działanie serwisu.</small></span></label><label><input type="radio" name="site_mode" value="test" ${cfg.mode==='test'?'checked':''}><span><strong>Testowy</strong><small>Na górze strony widoczny jest żółty komunikat o wersji testowej.</small></span></label><label><input type="radio" name="site_mode" value="break" ${cfg.mode==='break'?'checked':''}><span><strong>Przerwa</strong><small>Publiczne logowanie użytkowników jest wyłączone; konta administratorów nadal mogą logować się do wp-admin.</small></span></label></div>`;
      if(savebar)form.insertBefore(section,savebar);else form.appendChild(section);
    }
  });
})();
