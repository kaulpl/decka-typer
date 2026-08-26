(()=>{
  const cfg=window.DeckaTyperNotifications||{};
  if(!cfg.userId)return;
  window.DeckaTyperPwa={installPrompt:null};
  let oneSignalInitPromise=null;
  const isIos=()=>/iphone|ipad|ipod/i.test(navigator.userAgent);
  const isStandalone=()=>window.matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;
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
        resolve(true);
      }catch(error){
        oneSignalInitPromise=null;
        reject(error);
      }
    }));
  };
})();
