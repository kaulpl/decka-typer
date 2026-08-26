(()=>{
  const cfg=window.DeckaTyperNotifications||{};
  if(!cfg.userId)return;
  window.DeckaTyperPwa={installPrompt:null};
  window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();window.DeckaTyperPwa.installPrompt=event;document.dispatchEvent(new CustomEvent('dt:pwa-ready'));});
  window.DeckaTyperPwa.install=async()=>{
    const prompt=window.DeckaTyperPwa.installPrompt;
    if(prompt){prompt.prompt();await prompt.userChoice;window.DeckaTyperPwa.installPrompt=null;return true;}
    return false;
  };
  window.DeckaTyperPwa.enablePush=async()=>{
    if(!cfg.pushReady)throw new Error('Powiadomienia Push wymagają konfiguracji OneSignal przez administratora.');
    window.OneSignalDeferred=window.OneSignalDeferred||[];
    return new Promise((resolve,reject)=>window.OneSignalDeferred.push(async OneSignal=>{
      try{
        await OneSignal.init({appId:cfg.appId,serviceWorkerPath:cfg.workerPath,serviceWorkerParam:{scope:'/'},notifyButton:{enable:false},allowLocalhostAsSecureOrigin:false});
        await OneSignal.login(String(cfg.userId));
        await OneSignal.Notifications.requestPermission();
        if(!OneSignal.Notifications.permission)throw new Error('Przeglądarka nie otrzymała zgody na powiadomienia.');
        resolve(true);
      }catch(error){reject(error);}
    }));
  };
})();
