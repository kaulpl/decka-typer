(()=>{
  const cfg=window.DeckaTyper||{},root=document.getElementById('decka-typer'),modal=document.getElementById('dt-artur-ai-modal');
  if(!root||!modal||!cfg.loggedIn)return;
  const statusBox=modal.querySelector('#dt-artur-ai-status'),historyBox=modal.querySelector('#dt-artur-ai-history'),promptsBox=modal.querySelector('#dt-artur-ai-prompts'),form=modal.querySelector('#dt-artur-ai-form'),question=modal.querySelector('#dt-artur-ai-question'),matchLabel=modal.querySelector('#dt-artur-ai-match');
  const prompts=['Która drużyna jest ostatnio w lepszej formie?','Jak gospodarze radzą sobie u siebie?','Jak goście grają na wyjazdach?','Czy można spodziewać się wysokiego wyniku?','Co pokazują mecze bezpośrednie?','Na co zwrócić uwagę przed typowaniem?'];
  let selected=null,current=null,sending=false;
  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
  const api=async(path,opt={})=>{const headers={'Content-Type':'application/json',...(opt.headers||{})};if(cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;const response=await fetch(cfg.root+path,{credentials:'same-origin',...opt,headers});let data={};try{data=await response.json()}catch(_){data={}}if(!response.ok){const error=new Error(data.message||'Nie udało się połączyć z Arturem.');error.code=String(data.code||'');throw error;}return data;};
  const loading=message=>{statusBox.className='dt-artur-ai-status is-loading';statusBox.innerHTML=`<span class="dt-artur-ai-spinner" aria-hidden="true"></span><span>${esc(message)}</span>`;};
  const publicError=()=>{statusBox.className='dt-artur-ai-status is-error';statusBox.textContent='Artur potrzebuje jeszcze chwili na analizę. Spróbuj ponownie za moment — pytanie nie zostało wykorzystane.';};
  const userErrors=new Set(['disabled','unavailable','invalid_question','invalid_match','lifeline_bound','limit_reached','save_failed']);
  const render=()=>{
    const unlimited=!!current?.unlimited,used=Number(current?.used||0),limit=Number(current?.limit||3),remaining=unlimited?Infinity:Math.max(0,limit-used),bound=unlimited?0:Number(current?.match_id||0),wrong=!unlimited&&bound&&bound!==Number(selected?.match||0);
    statusBox.className='dt-artur-ai-status'+(remaining?'':' is-used');
    statusBox.innerHTML=unlimited?'Tryb testowy: <strong>pytania bez limitu i bez blokady meczu</strong>.':(wrong?'Koło ratunkowe tej kolejki wykorzystujesz już przy innym meczu.':`Pozostałe pytania: <strong>${remaining}/${limit}</strong>${bound?' · koło przypisane do tego meczu':''}`);
    const arturImage=cfg.avatar?.thinking?.url||'';
    historyBox.innerHTML=(current?.history||[]).map((item,index)=>`<article><div class="dt-artur-question"><b>Twoje pytanie ${index+1}:</b><p>${esc(item.question)}</p></div><div class="is-artur">${arturImage?`<img class="dt-artur-chat-avatar" src="${esc(arturImage)}" alt="Artur AI">`:''}<div class="dt-artur-answer-body"><b>Odpowiedź Artura:</b><p>${esc(item.answer)}</p></div></div></article>`).join('');
    promptsBox.innerHTML=!wrong&&remaining?prompts.map(p=>`<button type="button" data-artur-prompt="${esc(p)}">${esc(p)}</button>`).join(''):'';
    form.hidden=!!wrong||!remaining||!current?.available;
    form.querySelector('button').disabled=sending;
    if(historyBox.lastElementChild)historyBox.lastElementChild.scrollIntoView({block:'nearest'});
  };
  const open=async button=>{
    selected={round:Number(button.dataset.round),match:Number(button.dataset.match),home:button.dataset.home||'',away:button.dataset.away||''};
    matchLabel.textContent=`${selected.home} – ${selected.away}`;loading('Sprawdzam dostępność…');historyBox.innerHTML='';promptsBox.innerHTML='';form.hidden=true;modal.showModal();
    try{current=await api(`artur-ai/status/${selected.round}`);render();}catch(_){publicError();}
  };
  root.addEventListener('click',e=>{const button=e.target.closest('[data-artur-ai]');if(button)open(button);});
  modal.querySelector('.dt-artur-ai-close')?.addEventListener('click',()=>modal.close());
  modal.addEventListener('click',e=>{if(e.target===modal)modal.close();const prompt=e.target.closest('[data-artur-prompt]');if(prompt){question.value=prompt.dataset.arturPrompt||'';question.focus();}});
  form.addEventListener('submit',async e=>{
    e.preventDefault();if(sending||!selected)return;const value=question.value.trim();if(value.length<5)return;
    if(!current?.unlimited&&!Number(current?.match_id||0)&&!confirm(`Wykorzystać Koło ratunkowe przy meczu ${selected.home} – ${selected.away}? Po pierwszym pytaniu nie przeniesiesz go do innego meczu tej kolejki.`))return;
    sending=true;render();loading('Artur analizuje statystyki i układa podpowiedź…');
    try{const answer=await api('artur-ai/ask',{method:'POST',body:JSON.stringify({round_id:selected.round,match_id:selected.match,question:value})});current.unlimited=!!answer.unlimited||!!current.unlimited;current.match_id=current.unlimited?null:answer.match_id;current.used=current.unlimited?Number(current.used||0)+1:Number(answer.question_no);current.remaining=current.unlimited?null:Number(answer.remaining);current.history=[...(current.history||[]),{question:value,answer:answer.answer,question_no:current.used}];question.value='';sending=false;render();}
    catch(error){sending=false;if(userErrors.has(error.code)){statusBox.className='dt-artur-ai-status is-error';statusBox.textContent=error.message;}else publicError();form.querySelector('button').disabled=false;}
  });
})();
