(()=>{
  const cfg=window.DeckaTyper||{};
  const trigger=document.getElementById('dt-feedback-trigger');
  const modal=document.getElementById('dt-feedback-modal');
  const form=document.getElementById('dt-feedback-form');
  const message=document.getElementById('dt-feedback-message');
  const counter=document.getElementById('dt-feedback-count');
  if(!trigger||!modal||!form||!message)return;

  const close=()=>{if(modal.open)modal.close();};
  const toast=(text,error=false)=>{
    const node=document.getElementById('dt-toast');if(!node)return;
    node.textContent=text;node.classList.toggle('is-error',error);node.classList.add('is-show');
    window.setTimeout(()=>node.classList.remove('is-show'),3600);
  };
  const updateCount=()=>{if(counter)counter.textContent=`${message.value.length}/2000`;};
  trigger.addEventListener('click',()=>{modal.showModal();window.setTimeout(()=>message.focus(),50);});
  modal.querySelectorAll('[data-feedback-close]').forEach(button=>button.addEventListener('click',close));
  modal.addEventListener('click',event=>{if(event.target===modal)close();});
  message.addEventListener('input',updateCount);
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    const value=message.value.trim();
    if(value.length<10){message.setCustomValidity('Wpisz co najmniej 10 znaków.');message.reportValidity();return;}
    message.setCustomValidity('');
    const submit=form.querySelector('.dt-feedback-submit');submit.disabled=true;submit.textContent='Wysyłanie…';
    try{
      const response=await fetch(`${String(cfg.root||'').replace(/\/$/,'')}/feedback`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce||''},body:JSON.stringify({message:value,page_url:window.location.href})});
      const data=await response.json();
      if(!response.ok)throw new Error(data?.message||'Nie udało się wysłać zgłoszenia.');
      message.value='';updateCount();close();toast(data.message||'Zgłoszenie zostało zapisane.');
    }catch(error){toast(error.message||'Nie udało się wysłać zgłoszenia.',true);}
    finally{submit.disabled=false;submit.textContent='Wyślij zgłoszenie';}
  });
})();
