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
})();
