const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/notifications.js'),'utf8');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
async function setup({permission='default',enabled=false,active=false,ios=false,storageThrows=false,failSave=false,grantRequest=true,staleSdk=false,nativeError=false,seen=0,seenUrl=false,localSeen=0,desktop=false,standalone=ios}={}){
  const requests=[],timers=new Map(),listeners={},elements=[];let seq=0,initCount=0,promptCount=0,nativeCount=0,initOptions;const calls=[];
  const subscription={id:active?'existing-id':null,token:active?'existing-token':null,optedIn:active,addEventListener:(name,fn)=>listeners.subscription=fn,optIn:async()=>{subscription.id='fresh-id';subscription.token='fresh-token';subscription.optedIn=true;},optOut:async()=>{subscription.optedIn=false;}};
  const sdk={init:async options=>{initCount++;initOptions=options;},login:async()=>{calls.push('login');},User:{PushSubscription:subscription},Notifications:{permission:permission==='granted',addEventListener:(name,fn)=>listeners.permission=fn,requestPermission:()=>{promptCount++;calls.push('permission');sdk.Notifications.permission=grantRequest&&!staleSdk;context.window.Notification.permission=grantRequest?'granted':'denied';return Promise.resolve();}}};
  const cfg={userId:2,pushReady:true,pushEnabled:enabled,onboardingSeen:seen,onboardingSeenUrl:seenUrl?'/seen':null,appId:'app',workerScope:'/',workerPath:'/?dt_onesignal_worker=1',welcome:{title:'Powitanie',message:'Dziękujemy'},subscriptionUrl:'/subscription',preferenceUrl:'/preference'};
  const context={window:{DeckaTyperNotifications:cfg,Notification:{permission,requestPermission:()=>{nativeCount++;calls.push('native');if(nativeError)throw Error('native failed');context.window.Notification.permission=grantRequest?'granted':'denied';return Promise.resolve(grantRequest?'granted':'denied');}},isSecureContext:true,PushManager:class{},matchMedia:()=>({matches:standalone}),addEventListener:(name,fn)=>listeners[name]=fn,OneSignalDeferred:{push:fn=>fn(sdk)}},navigator:{userAgent:ios?'iPhone':desktop?'Chrome desktop':'Android',userActivation:{isActive:true},serviceWorker:{getRegistration:async()=>({active:{},pushManager:{getSubscription:async()=>subscription.token?{}:null}})}},document:{readyState:'complete',visibilityState:'visible',addEventListener:(name,fn)=>listeners[name]=fn,dispatchEvent(){},querySelector:()=>null,createElement:()=>{const children={},events={};const el={classList:{add(){},remove(){}},setAttribute(){},addEventListener:(name,fn)=>events[name]=fn,showModal(){this.open=true;},close(){this.open=false;events.close?.();},querySelector:selector=>children[selector]??=({addEventListener(name,fn){this[name]=fn;}}),remove(){this.removed=true;}};elements.push(el);return el;},body:{appendChild(){}}},localStorage:{getItem:key=>{if(storageThrows)throw Error('blocked');return key.endsWith('-seen')?String(localSeen):null;},setItem(){if(storageThrows)throw Error('blocked');},removeItem(){}},requestAnimationFrame:fn=>fn(),setTimeout:(fn,ms)=>{timers.set(++seq,{fn,ms});return seq;},clearTimeout:id=>timers.delete(id),CustomEvent:class{},AbortController,fetch:async(url,options)=>{requests.push({url,body:JSON.parse(options.body)});return {ok:!(failSave&&url==='/subscription'),json:async()=>({ok:!(failSave&&url==='/subscription'),message:'save failed'})};}};
  context.window.top=context.window;context.window.self=context.window;
  vm.runInNewContext(source,context);await flush();
  return {pwa:context.window.DeckaTyperPwa,calls,cfg,sdk,context,subscription,requests,elements,get initCount(){return initCount;},get promptCount(){return promptCount;},get nativeCount(){return nativeCount;},get initOptions(){return initOptions;},async change(){listeners.subscription();for(const [id,t] of timers){if(t.ms===200){timers.delete(id);t.fn();}}await flush();}};
}
test('Android: initialize before click, request permission synchronously, save verified token',async()=>{
 const h=await setup();assert.equal(h.initCount,1);assert.equal(h.promptCount,0);
 const result=h.pwa.enablePush();assert.equal(h.promptCount,1,'permission called before yielding');await result;
 assert.equal(h.pwa.state.active,true);assert.equal(h.requests[0].body.activate,true);assert.equal(h.requests[0].body.subscription_id,'fresh-id');assert.equal(h.initCount,1);
});
test('iPhone reinstallation: granted permission with new subscription is synchronized on open',async()=>{
 const h=await setup({ios:true,permission:'granted',enabled:true,active:true});
 assert.equal(h.initOptions.autoResubscribe,true);assert.equal(h.promptCount,0);assert.equal(h.requests[0].body.activate,false);assert.equal(h.pwa.state.active,true);
 h.subscription.id='reinstalled-id';await h.change();assert.equal(h.requests.at(-1).body.subscription_id,'reinstalled-id');
});
test('granted permission without usable subscription does not suppress recovery',async()=>{
 const h=await setup({ios:true,permission:'granted',enabled:true});assert.equal(h.pwa.state.active,false);assert.equal(h.elements.length,1);await h.pwa.enablePush();assert.equal(h.pwa.state.active,true);
});
test('Android denied permission explains system block without retrying permission prompt',async()=>{
 const h=await setup({permission:'denied',enabled:true});await assert.rejects(h.pwa.enablePush(),/zablokowane/);assert.equal(h.promptCount,0);assert.match(h.pwa.state.message,/ustawieniach/);
});
test('background synchronization cannot re-enable an opted-out account',async()=>{
 const h=await setup({permission:'granted',enabled:false,active:true});assert.equal(h.initOptions.autoResubscribe,false);assert.equal(h.requests.length,0);await h.change();assert.equal(h.requests.length,0);
 await h.pwa.enablePush();await h.pwa.disablePush();await h.change();assert.equal(h.cfg.pushEnabled,false);assert.equal(h.requests.at(-1).url,'/preference');assert.equal(h.pwa.state.active,false);
});
test('failed WordPress save does not claim success or initialize SDK twice',async()=>{
 const h=await setup({failSave:true});await assert.rejects(h.pwa.enablePush(),/save failed/);await assert.rejects(h.pwa.enablePush(),/save failed/);assert.equal(h.initCount,1);assert.equal(h.pwa.state.active,false);
});
test('blocked localStorage does not prevent onboarding or activation',async()=>{
 const h=await setup({storageThrows:true});assert.equal(h.elements.length,1);await h.pwa.enablePush();assert.equal(h.pwa.state.active,true);
});

test('iPhone shows activation dialog and calls SDK despite preflight denied state',async()=>{
 const h=await setup({ios:true,permission:'denied',enabled:false});
 assert.equal(h.elements.length,1);assert.equal(h.promptCount,0);assert.equal(h.calls.length,0);
 const result=h.pwa.enablePush();assert.equal(h.nativeCount,1);assert.equal(h.promptCount,0);await result;
 assert.deepEqual(h.calls,['native','permission','login']);assert.equal(h.pwa.state.active,true);
});
test('iPhone actual denied response never enables subscription or sends a test',async()=>{
 const h=await setup({ios:true,permission:'denied',enabled:false,grantRequest:false});
 await assert.rejects(h.pwa.enablePush(),/System iOS nie udzielił zgody/);
 assert.equal(h.nativeCount,1);assert.equal(h.promptCount,0);assert.equal(h.requests.length,0);assert.equal(h.pwa.state.active,false);
 assert.doesNotMatch(h.pwa.state.message,/iPhone blokuje/);
});
test('iPhone first launch preserves permission-before-login order',async()=>{
 const h=await setup({ios:true});assert.equal(h.elements.length,1);assert.equal(h.calls.length,0);
 await h.pwa.enablePush();assert.deepEqual(h.calls,['native','permission','login']);
});

test('iPhone native grant with stale SDK permission still registers verified subscription',async()=>{
 const h=await setup({ios:true,staleSdk:true});await h.pwa.enablePush();
 assert.equal(h.sdk.Notifications.permission,false);assert.equal(h.pwa.state.active,true);assert.equal(h.requests[0].body.activate,true);
});
test('iPhone native denial reports context and never registers with provider',async()=>{
 const h=await setup({ios:true,grantRequest:false});
 await assert.rejects(h.pwa.enablePush(),/PUSH-IOS-1: etap=permission; przed=default; wynik=denied; teraz=denied; PWA=true; HTTPS=true; gest=true; top=true/);
 assert.equal(h.requests.length,0);assert.equal(h.promptCount,0);
});
test('iPhone synchronous native error resets busy state and includes diagnostics',async()=>{
 const h=await setup({ios:true,nativeError:true});
 await assert.rejects(h.pwa.enablePush(),/PUSH-IOS-1/);await assert.rejects(h.pwa.enablePush(),/PUSH-IOS-1/);
 assert.equal(h.nativeCount,2);assert.equal(h.requests.length,0);
});

const help=h=>h.elements.find(el=>el.className==='dt-push-permission-help'&&!el.removed);
const retry=modal=>modal.querySelector('.dt-push-onboarding-enable').click();
test('Android blocked guide is explicit, singleton and never bypasses denied permission',async()=>{
 const h=await setup({permission:'denied'});assert.equal(help(h),undefined);
 await assert.rejects(h.pwa.enablePush());const modal=help(h);assert.equal(modal.open,true);
 await retry(modal);await assert.rejects(h.pwa.enablePush());
 assert.equal(h.elements.filter(el=>el.className==='dt-push-permission-help').length,1);
 assert.equal(h.promptCount,0);assert.equal(h.requests.length,0);assert.equal(h.cfg.pushEnabled,false);
 assert.match(modal.querySelector('.dt-push-onboarding-status').textContent,/zablokowane/);
 modal.querySelector('.dt-push-onboarding-later').click();assert.equal(modal.removed,true);
 await assert.rejects(h.pwa.enablePush());assert.notEqual(help(h),modal);
});
test('Android guide retries after settings change and closes only after verified save',async()=>{
 const h=await setup({permission:'denied'});await assert.rejects(h.pwa.enablePush());const modal=help(h);
 h.context.window.Notification.permission='granted';await h.change();
 assert.equal(h.requests.length,0,'returning from settings must not enable account without click');
 await retry(modal);assert.equal(h.pwa.state.active,true);assert.equal(h.cfg.pushEnabled,true);
 assert.equal(h.requests[0].body.activate,true);assert.equal(modal.removed,true);
});
test('Android reset to default requests permission directly on recovery tap',async()=>{
 const h=await setup({permission:'denied'});await assert.rejects(h.pwa.enablePush());const modal=help(h);
 h.context.window.Notification.permission='default';const pending=retry(modal);
 assert.equal(h.promptCount,1);await pending;assert.equal(modal.removed,true);
});
test('Android guide remains open when server cannot save subscription',async()=>{
 const h=await setup({permission:'denied',failSave:true});await assert.rejects(h.pwa.enablePush());const modal=help(h);
 h.context.window.Notification.permission='granted';await retry(modal);
 assert.equal(modal.open,true);assert.equal(h.pwa.state.active,false);assert.equal(h.cfg.pushEnabled,false);
 assert.match(modal.querySelector('.dt-push-onboarding-status').textContent,/save failed/);
 assert.equal(modal.querySelector('.dt-push-onboarding-enable').disabled,false);
});
test('Android newly denied prompt shows guide; iOS denial does not',async()=>{
 const android=await setup({grantRequest:false});await assert.rejects(android.pwa.enablePush());assert.equal(help(android).open,true);
 const ios=await setup({ios:true,grantRequest:false});await assert.rejects(ios.pwa.enablePush());assert.equal(help(ios),undefined);
});

const onboarding=h=>h.elements.find(el=>el.className==='dt-push-onboarding');
test('weekly reminder: no repeat before seven days, show on first visit after deadline',async()=>{
 const recent=await setup({seen:Date.now()-6*86400000});assert.equal(onboarding(recent),undefined);
 const due=await setup({seen:Date.now()-7*86400000,seenUrl:true});assert.ok(onboarding(due));
 assert.equal(due.requests.filter(r=>r.url==='/seen').length,1);assert.equal(due.promptCount,0);
 const reload=await setup({seen:due.cfg.onboardingSeen});assert.equal(onboarding(reload),undefined);
});
test('weekly reminder respects account timestamp when local storage is blocked',async()=>{
 const h=await setup({seen:Date.now(),storageThrows:true});assert.equal(onboarding(h),undefined);
 const local=await setup({localSeen:Date.now()});assert.equal(onboarding(local),undefined);
});
test('weekly reminder covers desktop and previously denied or granted-but-disabled accounts',async()=>{
 for(const permission of ['default','denied','granted']){
  const h=await setup({desktop:true,permission});assert.ok(onboarding(h));assert.equal(h.promptCount,0);assert.equal(h.cfg.pushEnabled,false);
 }
});
test('active Push never shows reminder; broken device respects seven day interval',async()=>{
 const active=await setup({enabled:true,permission:'granted',active:true});assert.equal(onboarding(active),undefined);
 const recent=await setup({enabled:true,seen:Date.now()});assert.equal(onboarding(recent),undefined);
});
test('iPhone outside PWA shows installation help without prompting or initializing SDK',async()=>{
 const h=await setup({ios:true,standalone:false});const modal=onboarding(h);assert.ok(modal);
 await modal.querySelector('.dt-push-onboarding-enable').click();
 assert.match(modal.querySelector('.dt-push-onboarding-status').textContent,/Safari\/Chrome/);
 assert.equal(h.initCount,0);assert.equal(h.nativeCount,0);assert.equal(h.promptCount,0);
});
