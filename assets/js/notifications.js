(()=>{
  const cfg=window.DeckaTyperNotifications||{};
  if(!cfg.userId)return;
  const pwa=window.DeckaTyperPwa={installPrompt:null,state:{ready:false,active:false,message:'Sprawdzanie powiadomień na tym urządzeniu…'}};
  let sdk=null,initialization=null,activating=false,syncTimer=null,lastSaved='';
  const isIos=()=>/iphone|ipad|ipod/i.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
  const isAndroid=()=>/android/i.test(navigator.userAgent);
  const isStandalone=()=>window.matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;
  const permissionState=()=>window.Notification?.permission||'default';
  let permissionHelp=null;
  // Chrome cannot reliably deep-link a web page to native permission settings.
  // Only an explicit retry may request permission and activate the account.
  const showPermissionHelp=()=>{
    if(!isAndroid()||permissionHelp)return;
    const modal=document.createElement('dialog');
    permissionHelp=modal;
    modal.className='dt-push-permission-help';
    modal.setAttribute('aria-labelledby','dt-push-permission-title');
    modal.innerHTML='<div class="dt-push-onboarding-card"><h2 id="dt-push-permission-title">Zezwól na powiadomienia w Chrome</h2><p>Przeglądarka zgłasza brak zgody. Strona nie może sama zmienić ustawień ani ponownie wyświetlić pytania przy aktywnej blokadzie.</p><ol><li>Otwórz <strong>typujkosza.pl w Chrome</strong>. Jeśli używasz aplikacji z ikony, przejdź na chwilę do Chrome.</li><li>Dotknij ikony po lewej stronie adresu → <strong>Uprawnienia → Powiadomienia → Zezwól</strong>.</li><li>Jeśli nie widzisz tej opcji: menu Chrome <strong>⋮ → Ustawienia → Ustawienia witryn → Powiadomienia</strong>. Znajdź TypujKosza.pl i zezwól na powiadomienia. Sprawdź też, czy witryny mogą pytać o zgodę.</li><li>Jeśli blokada pozostaje: <strong>Ustawienia telefonu → Aplikacje → Chrome → Powiadomienia</strong>. Zezwól na powiadomienia. Nazwy opcji mogą różnić się zależnie od telefonu.</li><li>Wróć do tego okna i naciśnij przycisk poniżej. Jeżeli strona się przeładuje, ponownie włącz Web Push w „Moje konto”.</li></ol><div class="dt-push-onboarding-actions"><button type="button" class="dt-push-onboarding-enable">Sprawdź ponownie i włącz</button><button type="button" class="dt-push-onboarding-later">Zamknij</button></div><small class="dt-push-onboarding-status" role="status" aria-live="polite"></small></div>';
    const retry=modal.querySelector('.dt-push-onboarding-enable'),close=modal.querySelector('.dt-push-onboarding-later'),status=modal.querySelector('.dt-push-onboarding-status');
    modal.addEventListener('close',()=>{permissionHelp=null;modal.remove();});
    close.addEventListener('click',()=>modal.close());
    retry.addEventListener('click',async()=>{
      retry.disabled=true;status.textContent='Sprawdzanie zgody i rejestracja urządzenia…';
      try{
        await pwa.enablePush();
        const onboarding=document.querySelector('.dt-push-onboarding');if(onboarding)closeOnboarding(onboarding);
        modal.close();
      }catch(error){status.textContent=error.message;}
      finally{retry.disabled=false;}
    });
    document.body.appendChild(modal);modal.showModal();
  };
  const storage={get:key=>{try{return localStorage.getItem(key);}catch(_){return null;}},set:(key,value)=>{try{localStorage.setItem(key,value);}catch(_){}},remove:key=>{try{localStorage.removeItem(key);}catch(_){}}};
  const publish=(message,active=false)=>{pwa.state={ready:!!sdk,active,message};document.dispatchEvent(new CustomEvent('dt:push-state',{detail:pwa.state}));};
  const blockedMessage=()=>isIos()?'Nie uzyskano zgody na powiadomienia w tej instalacji PWA. Otwórz TypujKosza z ikony na ekranie głównym. Jeśli po kliknięciu nie pojawia się pytanie i nie ma wpisu w ustawieniach iPhone’a, zgłoś ten komunikat — nie potwierdza on istnienia blokady w ustawieniach.':'Powiadomienia są zablokowane. W ustawieniach tej witryny w przeglądarce zezwól na powiadomienia. Sprawdź też uprawnienia przeglądarki w ustawieniach Androida.';
  const post=async(url,body)=>{
    const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),15000);
    try{
      const response=await fetch(url,{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},body:JSON.stringify(body)});
      let data={};try{data=await response.json();}catch(_){}
      if(!response.ok||!data.ok)throw new Error(data.message||'Nie udało się zapisać urządzenia. Odśwież stronę i zaloguj się ponownie.');
      return data;
    }catch(error){if(error.name==='AbortError')throw new Error('Serwer nie odpowiedział na zapis urządzenia. Sprawdź połączenie i spróbuj ponownie.');throw error;}
    finally{clearTimeout(timer);}
  };
  const activeSubscription=()=>((isIos()&&permissionState()==='granted')||sdk?.Notifications.permission===true)&&sdk.User.PushSubscription.optedIn===true&&!!sdk.User.PushSubscription.id&&!!sdk.User.PushSubscription.token;
  const browserSubscription=async()=>{
    const registration=await navigator.serviceWorker.getRegistration(cfg.workerScope||'/');
    return !!(registration?.active&&await registration.pushManager.getSubscription());
  };
  const waitForSubscription=async()=>{
    for(let attempt=0;attempt<60;attempt++){
      if(activeSubscription()&&await browserSubscription())return String(sdk.User.PushSubscription.id);
      await new Promise(resolve=>setTimeout(resolve,250));
    }
    throw new Error('Zgoda została udzielona, ale urządzenie nie ma aktywnej subskrypcji Push. Sprawdź połączenie i uprawnienia przeglądarki, a następnie spróbuj ponownie.');
  };
  const saveSubscription=async(id,activate=false)=>{
    if(!activate&&lastSaved===id)return;
    await post(cfg.subscriptionUrl,{subscription_id:id,activate});lastSaved=id;
  };
  const reconcile=async()=>{
    if(!sdk||activating)return;
    if(!cfg.pushEnabled){publish('Przypomnienia Web Push są wyłączone dla konta.');return;}
    if(!isIos()&&permissionState()==='denied'){publish(blockedMessage());return;}
    if(activeSubscription()&&await browserSubscription()){
      await saveSubscription(String(sdk.User.PushSubscription.id));
      publish('To urządzenie ma aktywną subskrypcję Web Push. Dostarczenie zależy także od ustawień systemu.',true);
    }else publish('To urządzenie wymaga aktywacji lub ponownego połączenia. Włącz przełącznik Web Push.');
  };
  const scheduleSync=()=>{clearTimeout(syncTimer);syncTimer=setTimeout(()=>reconcile().catch(error=>publish(error.message)),200);};
  const initialize=()=>{
    if(initialization)return initialization;
    initialization=new Promise((resolve,reject)=>{
      const timer=setTimeout(()=>reject(new Error('Nie udało się uruchomić OneSignal. Sprawdź połączenie lub blokowanie skryptów i odśwież stronę.')),20000);
      window.OneSignalDeferred=window.OneSignalDeferred||[];
      window.OneSignalDeferred.push(async OneSignal=>{
        try{
          await OneSignal.init({appId:cfg.appId,serviceWorkerPath:cfg.workerPath,serviceWorkerParam:{scope:cfg.workerScope||'/'},notifyButton:{enable:false},promptOptions:{slidedown:{prompts:[{type:'push',autoPrompt:false}]}},autoResubscribe:!!cfg.pushEnabled,welcomeNotification:{title:cfg.welcome.title,message:cfg.welcome.message,url:cfg.homeUrl},allowLocalhostAsSecureOrigin:false});
          // iPhone: restore permission -> login -> opt-in for a new installation.
          if(!isIos()||OneSignal.Notifications.permission)await OneSignal.login(String(cfg.userId));
          sdk=OneSignal;
          sdk.User.PushSubscription.addEventListener('change',scheduleSync);
          sdk.Notifications.addEventListener('permissionChange',scheduleSync);
          clearTimeout(timer);resolve();
        }catch(error){clearTimeout(timer);reject(error);}
      });
    });
    return initialization;
  };
  window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();pwa.installPrompt=event;document.dispatchEvent(new CustomEvent('dt:pwa-ready'));});
  pwa.install=async()=>{const prompt=pwa.installPrompt;if(!prompt)return false;prompt.prompt();const choice=await prompt.userChoice;pwa.installPrompt=null;return choice.outcome==='accepted';};
  pwa.enablePush=()=>{
    if(!cfg.pushReady)return Promise.reject(new Error('Push wymaga konfiguracji OneSignal przez administratora.'));
    if(isIos()&&!isStandalone())return Promise.reject(new Error('Na iPhonie otwórz aplikację z ikony na ekranie głównym.'));
    if(!sdk)return Promise.reject(new Error(pwa.state.message||'Poczekaj na przygotowanie powiadomień.'));
    if(activating)return Promise.reject(new Error('Aktywacja już trwa.'));
    if(!isIos()&&permissionState()==='denied'){publish(blockedMessage());showPermissionHelp();return Promise.reject(new Error(blockedMessage()));}
    const nativeIos=isIos();
    const attempt={stage:'permission',before:permissionState(),result:'pending',pwa:isStandalone(),secure:window.isSecureContext===true,gesture:navigator.userActivation?.isActive??'unknown',top:window.top===window.self};
    const diagnostic=()=>`[PUSH-IOS-1: etap=${attempt.stage}; przed=${attempt.before}; wynik=${attempt.result}; teraz=${permissionState()}; PWA=${attempt.pwa}; HTTPS=${attempt.secure}; gest=${attempt.gesture}; top=${attempt.top}; SDK=${!!sdk.Notifications.permission}]`;
    // Native iOS permission request must execute synchronously in this tap,
    // before SDK promises, network access or subscription registration.
    activating=true;
    let permission;
    try{permission=nativeIos?window.Notification.requestPermission():sdk.Notifications.requestPermission();}
    catch(error){activating=false;const failure=new Error(nativeIos?'Nie udało się wywołać systemowego pytania. '+diagnostic():error.message);publish(failure.message);return Promise.reject(failure);}
    return (async()=>{
      try{
        const result=await permission;
        attempt.result=nativeIos?String(result):String(sdk.Notifications.permission);
        if(nativeIos){
          if(result!=='granted')throw new Error('System iOS nie udzielił zgody. Jeśli nie było okna, prześlij poniższy kod diagnostyczny.');
          attempt.stage='onesignal';
          // With native permission granted, let the public SDK register Push.
          // Do not reject just because its cached permission getter is stale.
          await sdk.Notifications.requestPermission();
          await sdk.login(String(cfg.userId));
        }else if(!sdk.Notifications.permission){
          throw new Error(permissionState()==='denied'?blockedMessage():'Nie udzielono zgody. Kliknij przełącznik ponownie i wybierz „Zezwól”.');
        }
        attempt.stage='subscription';
        await sdk.User.PushSubscription.optIn();
        const id=await waitForSubscription();
        attempt.stage='save';
        await saveSubscription(id,true);cfg.pushEnabled=true;
        publish('To urządzenie ma aktywną subskrypcję Web Push.',true);
        return {ok:true,subscriptionId:id};
      }catch(error){const failure=new Error(nativeIos?error.message+' '+diagnostic():error.message);publish(failure.message);if(!nativeIos&&permissionState()==='denied')showPermissionHelp();throw failure;}
      finally{activating=false;}
    })();
  };
  pwa.disablePush=async()=>{
    await post(cfg.preferenceUrl,{push:false});cfg.pushEnabled=false;lastSaved='';
    try{if(sdk)await sdk.User.PushSubscription.optOut();}
    finally{publish('Przypomnienia Web Push są wyłączone dla konta.');}
  };
  pwa.testPush=async()=>{const activation=await pwa.enablePush();return post(cfg.testUrl,{subscription_id:activation.subscriptionId});};
  const onboardingKey='dt-push-onboarding-'+cfg.userId;
  const closeOnboarding=modal=>{modal.classList.remove('is-visible');setTimeout(()=>modal.remove(),220);};
  const showPushOnboarding=()=>{
    const installIos=isIos()&&!isStandalone();
    if(!cfg.pushReady||(!installIos&&!sdk)||pwa.state.active||(installIos&&cfg.pushEnabled)||document.querySelector('.dt-push-onboarding'))return;
    const lastSeen=Math.max(Number(cfg.onboardingSeen)||0,Number(storage.get(onboardingKey+'-seen'))||0,Number(storage.get(onboardingKey+'-later'))||0);
    if(lastSeen&&Date.now()-lastSeen<7*86400000)return;
    const modal=document.createElement('div');
    modal.className='dt-push-onboarding';
    modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-labelledby','dt-push-onboarding-title');
    modal.innerHTML='<div class="dt-push-onboarding-card"><img src="'+String(cfg.iconUrl||'')+'" alt="" class="dt-push-onboarding-icon"><span class="dt-push-onboarding-kicker">TYPOWANIE ZAWSZE NA CZAS</span><h2 id="dt-push-onboarding-title">Włącz powiadomienia TypujKosza.pl</h2><p>Otrzymuj przypomnienia o typowaniu, zmianach terminów i ważnych wydarzeniach. Włącz je teraz lub wróć do tego za 7 dni.</p><ul><li>Przypomnienia przed zamknięciem typowania</li><li>Informacje o zmianach terminów meczów</li><li>Powiadomienia bezpośrednio na telefon</li></ul><div class="dt-push-onboarding-actions"><button type="button" class="dt-push-onboarding-enable">Włącz powiadomienia</button><button type="button" class="dt-push-onboarding-later">Przypomnij za 7 dni</button></div><small class="dt-push-onboarding-status" aria-live="polite"></small></div>';
    cfg.onboardingSeen=Date.now();storage.set(onboardingKey+'-seen',String(cfg.onboardingSeen));
    if(cfg.onboardingSeenUrl)post(cfg.onboardingSeenUrl,{}).then(data=>{if(data.seen)cfg.onboardingSeen=data.seen;}).catch(()=>{});
    document.body.appendChild(modal);requestAnimationFrame(()=>modal.classList.add('is-visible'));
    const enable=modal.querySelector('.dt-push-onboarding-enable'),later=modal.querySelector('.dt-push-onboarding-later'),status=modal.querySelector('.dt-push-onboarding-status');
    later.addEventListener('click',()=>{storage.set(onboardingKey+'-later',String(Date.now()));closeOnboarding(modal);});
    if(installIos)enable.textContent='Jak włączyć na iPhonie?';
    if(!isIos()&&permissionState()==='denied')status.textContent=blockedMessage();
    enable.addEventListener('click',async()=>{
      if(installIos){status.textContent='Safari/Chrome: otwórz menu Udostępnij → Do ekranu początkowego. Następnie otwórz TypujKosza.pl z ikony i w „Moje konto” włącz Web Push / PWA.';return;}
      enable.disabled=true;later.disabled=true;enable.textContent='Włączanie…';status.textContent='Poczekaj na systemowe pytanie i wybierz „Zezwól”.';
      try{
        await window.DeckaTyperPwa.enablePush();
        storage.set(onboardingKey,'enabled');storage.remove(onboardingKey+'-later');
        const toggle=document.querySelector('[name="notify_push"]');if(toggle)toggle.checked=true;
        modal.classList.add('is-success');enable.textContent='Powiadomienia są włączone';status.textContent='Gotowe! Powiadomienia zostały włączone. Okno zamknie się automatycznie.';
        setTimeout(()=>closeOnboarding(modal),1800);
      }catch(error){
        enable.disabled=false;later.disabled=false;enable.textContent='Spróbuj ponownie';status.textContent=error.message||'Nie udało się włączyć powiadomień.';
      }
    });
  };
  const start=async()=>{
    if(!cfg.pushReady){publish('Web Push nie jest skonfigurowany.');return;}
    if(isIos()&&!isStandalone()){publish('iPhone: dodaj aplikację przez Safari/Chrome i otwórz ją z ikony na ekranie głównym.');showPushOnboarding();return;}
    if(!window.Notification||!navigator.serviceWorker||!window.PushManager){publish('Ta przeglądarka nie obsługuje Web Push. Otwórz stronę w aktualnym Chrome lub jako PWA na iPhonie.');return;}
    try{await initialize();await reconcile();showPushOnboarding();}
    catch(error){publish(error.message);}
  };
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')scheduleSync();});
  window.addEventListener('pageshow',scheduleSync);
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);
  else start();
})();
