(()=>{
  const cfg=window.DeckaTyperNotifications||{};
  if(!cfg.userId)return;
  window.DeckaTyperPwa={installPrompt:null};
  let oneSignalInitPromise=null;
  const isIos=()=>/iphone|ipad|ipod/i.test(navigator.userAgent);
  const isStandalone=()=>window.matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;
  const waitForSubscriptionId=async OneSignal=>{
    for(let attempt=0;attempt<40;attempt++){
      const id=String(OneSignal.User?.PushSubscription?.id||'').trim();
      if(id)return id;
      await new Promise(resolve=>setTimeout(resolve,250));
    }
    throw new Error('OneSignal nie utworzył identyfikatora subskrypcji dla tego urządzenia.');
  };
  const saveSubscription=async subscriptionId=>{
    const response=await fetch(cfg.subscriptionUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},body:JSON.stringify({subscription_id:subscriptionId})});
    let data={};
    try{data=await response.json();}catch(_){}
    if(!response.ok||!data.ok)throw new Error(data.message||'Nie udało się przypisać urządzenia do konta Typera.');
    return data;
  };
  window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();window.DeckaTyperPwa.installPrompt=event;document.dispatchEvent(new CustomEvent('dt:pwa-ready'));});
  window.DeckaTyperPwa.install=async()=>{
    const prompt=window.DeckaTyperPwa.installPrompt;
    if(prompt){prompt.prompt();await prompt.userChoice;window.DeckaTyperPwa.installPrompt=null;return true;}
    return false;
  };
  window.DeckaTyperPwa.enablePush=async()=>{
    if(!cfg.pushReady)throw new Error('Powiadomienia Push wymagają konfiguracji OneSignal przez administratora.');
    if(isIos()&&!isStandalone())throw new Error('Na iPhonie otwórz TypujKosza.pl z ikony dodanej do ekranu początkowego, a następnie włącz powiadomienia w aplikacji.');
    window.OneSignalDeferred=window.OneSignalDeferred||[];
    return new Promise((resolve,reject)=>window.OneSignalDeferred.push(async OneSignal=>{
      try{
        if(!oneSignalInitPromise){
          oneSignalInitPromise=OneSignal.init({
            appId:cfg.appId,
            serviceWorkerPath:cfg.workerPath,
            serviceWorkerParam:{scope:cfg.workerScope||'/'},
            notifyButton:{enable:false},
            allowLocalhostAsSecureOrigin:false
          });
        }
        await oneSignalInitPromise;
        await OneSignal.Notifications.requestPermission();
        if(!OneSignal.Notifications.permission)throw new Error('Przeglądarka nie otrzymała zgody na powiadomienia.');
        await OneSignal.login(String(cfg.userId));
        if(OneSignal.User?.PushSubscription?.optIn)await OneSignal.User.PushSubscription.optIn();
        if(OneSignal.User?.PushSubscription?.optedIn===false)throw new Error('Subskrypcja powiadomień nie została aktywowana na tym urządzeniu.');
        const subscriptionId=await waitForSubscriptionId(OneSignal);
        await saveSubscription(subscriptionId);
        resolve({ok:true,subscriptionId});
      }catch(error){
        oneSignalInitPromise=null;
        reject(error);
      }
    }));
  };
  window.DeckaTyperPwa.testPush=async()=>{
    const activation=await window.DeckaTyperPwa.enablePush();
    const response=await fetch(cfg.testUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},body:JSON.stringify({subscription_id:activation.subscriptionId})});
    let data={};try{data=await response.json();}catch(_){}
    if(!response.ok||!data.ok)throw new Error(data.message||data.response||'OneSignal nie dostarczył testu na to urządzenie.');
    return data;
  };
  const onboardingKey='dt-push-onboarding-'+cfg.userId;
  const permissionState=()=>window.Notification?.permission||'default';
  const closeOnboarding=modal=>{modal.classList.remove('is-visible');setTimeout(()=>modal.remove(),220);};
  const showPushOnboarding=()=>{
    if(!cfg.pushReady||!isIos()||!isStandalone()||permissionState()!=='default'||document.querySelector('.dt-push-onboarding'))return;
    const postponed=Number(localStorage.getItem(onboardingKey+'-later')||0);
    if(postponed&&Date.now()-postponed<86400000)return;
    const modal=document.createElement('div');
    modal.className='dt-push-onboarding';
    modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-labelledby','dt-push-onboarding-title');
    modal.innerHTML='<div class="dt-push-onboarding-card"><img src="'+String(cfg.iconUrl||'')+'" alt="" class="dt-push-onboarding-icon"><span class="dt-push-onboarding-kicker">TYPOWANIE ZAWSZE NA CZAS</span><h2 id="dt-push-onboarding-title">Włącz powiadomienia TypujKosza.pl</h2><p>Otrzymuj przypomnienia o typowaniu, zmianach terminów i ważnych wydarzeniach. Wymagane jest tylko jedno kliknięcie.</p><ul><li>Przypomnienia przed zamknięciem typowania</li><li>Informacje o zmianach terminów meczów</li><li>Powiadomienia bezpośrednio na iPhone’a</li></ul><div class="dt-push-onboarding-actions"><button type="button" class="dt-push-onboarding-enable">Włącz powiadomienia</button><button type="button" class="dt-push-onboarding-later">Może później</button></div><small class="dt-push-onboarding-status" aria-live="polite"></small></div>';
    document.body.appendChild(modal);requestAnimationFrame(()=>modal.classList.add('is-visible'));
    const enable=modal.querySelector('.dt-push-onboarding-enable'),later=modal.querySelector('.dt-push-onboarding-later'),status=modal.querySelector('.dt-push-onboarding-status');
    later.addEventListener('click',()=>{localStorage.setItem(onboardingKey+'-later',String(Date.now()));closeOnboarding(modal);});
    enable.addEventListener('click',async()=>{
      enable.disabled=true;later.disabled=true;enable.textContent='Włączanie…';status.textContent='Poczekaj na systemowe pytanie i wybierz „Zezwól”.';
      try{
        await window.DeckaTyperPwa.testPush();
        localStorage.setItem(onboardingKey,'enabled');localStorage.removeItem(onboardingKey+'-later');
        const toggle=document.querySelector('[name="notify_push"]');if(toggle)toggle.checked=true;
        modal.classList.add('is-success');enable.textContent='Powiadomienia są włączone';status.textContent='Gotowe! Okno zamknie się automatycznie, a test nadejdzie za około 15 sekund.';
        setTimeout(()=>closeOnboarding(modal),1800);
      }catch(error){
        enable.disabled=false;later.disabled=false;enable.textContent='Spróbuj ponownie';status.textContent=error.message||'Nie udało się włączyć powiadomień.';
      }
    });
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>setTimeout(showPushOnboarding,700));
  else setTimeout(showPushOnboarding,700);
})();
