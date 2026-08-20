(()=>{
  const cfg=window.TypujKoszaMultiLeagueAdmin||{};
  const q=s=>document.querySelector(s);
  document.addEventListener('DOMContentLoaded',()=>{
    q('[data-ml-open-add]')?.addEventListener('click',()=>q('#tk-ml-add-round')?.showModal());
    document.addEventListener('click',e=>{if(e.target.closest('[data-ml-close]'))q('#tk-ml-add-round')?.close();});
    const league=q('#tk-ml-league'),group=q('#tk-ml-group');
    const syncGroup=()=>{if(!league||!group)return;const two=league.value==='2lm';group.disabled=!two;if(!two)group.value='';};
    league?.addEventListener('change',syncGroup);syncGroup();

    const form=document.querySelector('.dt-settings');
    if(form&&!form.querySelector('[name="site_mode"]')){
      const savebar=form.querySelector('.dt-savebar');
      const section=document.createElement('section');section.className='dt-card tk-mode-card';
      section.innerHTML=`<span class="dt-eyebrow">TRYB SERWISU</span><h2>Status TypujKosza.pl</h2><p class="dt-muted">Steruje zachowaniem publicznej strony bez wpływu na dostęp administratora do WordPressa.</p><div class="tk-mode-options"><label><input type="radio" name="site_mode" value="production" ${cfg.mode==='production'?'checked':''}><span><strong>Produkcyjny</strong><small>Normalne działanie serwisu.</small></span></label><label><input type="radio" name="site_mode" value="test" ${cfg.mode==='test'?'checked':''}><span><strong>Testowy</strong><small>Na górze strony widoczny jest żółty komunikat o wersji testowej.</small></span></label><label><input type="radio" name="site_mode" value="break" ${cfg.mode==='break'?'checked':''}><span><strong>Przerwa</strong><small>Publiczne logowanie jest wyłączone; wp-admin działa normalnie.</small></span></label></div>`;
      if(savebar)form.insertBefore(section,savebar);else form.appendChild(section);
    }
  });
})();
