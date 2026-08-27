const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/notifications.js'),'utf8');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
async function setup({permission='default',enabled=false,active=false,ios=false,storageThrows=false,failSave=false,grantRequest=true}={}){
  const requests=[],timers=new Map(),listeners={},elements=[];let seq=0,initCount=0,promptCount=0,initOptions;const calls=[];
  const subscription={id:active?'existing-id':null,token:active?'existing-token':null,optedIn:active,addEventListener:(name,fn)=>listeners.subscription=fn,optIn:async()=>{subscription.id='fresh-id';subscription.token='fresh-token';subscription.optedIn=true;},optOut:async()=>{subscription.optedIn=false;}};
  const sdk={init:async options=>{initCount++;initOptions=options;},login:async()=>{calls.push('login');},User:{PushSubscription:subscription},Notifications:{permission:permission==='granted',addEventListener:(name,fn)=>listeners.permission=fn,requestPermission:()=>{promptCount++;calls.push('permission');sdk.Notifications.permission=grantRequest;context.window.Notification.permission=grantRequest?'granted':'denied';return Promise.resolve();}}};
  const cfg={userId:2,pushReady:true,pushEnabled:enabled,appId:'app',workerScope:'/',workerPath:'/?dt_onesignal_worker=1',welcome:{title:'Powitanie',message:'Dziękujemy'},subscriptionUrl:'/subscription',preferenceUrl:'/preference'};
  const context={window:{DeckaTyperNotifications:cfg,Notification:{permission},PushManager:class{},matchMedia:()=>({matches:ios}),addEventListener:(name,fn)=>listeners[name]=fn,OneSignalDeferred:{push:fn=>fn(sdk)}},navigator:{userAgent:ios?'iPhone':'Android',serviceWorker:{getRegistration:async()=>({active:{},pushManager:{getSubscription:async()=>subscription.token?{}:null}})}},document:{readyState:'complete',visibilityState:'visible',addEventListener:(name,fn)=>listeners[name]=fn,dispatchEvent(){},querySelector:()=>null,createElement:()=>{const children={};const el={classList:{add(){},remove(){}},setAttribute(){},querySelector:selector=>children[selector]??=({addEventListener(){}}),remove(){}};elements.push(el);return el;},body:{appendChild(){}}},localStorage:{getItem:()=>{if(storageThrows)throw Error('blocked');return null;},setItem(){if(storageThrows)throw Error('blocked');},removeItem(){}},requestAnimationFrame:fn=>fn(),setTimeout:(fn,ms)=>{timers.set(++seq,{fn,ms});return seq;},clearTimeout:id=>timers.delete(id),CustomEvent:class{},AbortController,fetch:async(url,options)=>{requests.push({url,body:JSON.parse(options.body)});return {ok:!(failSave&&url==='/subscription'),json:async()=>({ok:!(failSave&&url==='/subscription'),message:'save failed'})};}};
  vm.runInNewContext(source,context);await flush();
  return {pwa:context.window.DeckaTyperPwa,calls,cfg,sdk,context,subscription,requests,elements,get initCount(){return initCount;},get promptCount(){return promptCount;},get initOptions(){return initOptions;},async change(){listeners.subscription();for(const [id,t] of timers){if(t.ms===200){timers.delete(id);t.fn();}}await flush();}};
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
 const result=h.pwa.enablePush();assert.equal(h.promptCount,1);await result;
 assert.deepEqual(h.calls,['permission','login']);assert.equal(h.pwa.state.active,true);
});
test('iPhone actual denied response never enables subscription or sends a test',async()=>{
 const h=await setup({ios:true,permission:'denied',enabled:false,grantRequest:false});
 await assert.rejects(h.pwa.enablePush(),/Nie uzyskano zgody/);
 assert.equal(h.promptCount,1);assert.equal(h.requests.length,0);assert.equal(h.pwa.state.active,false);
 assert.doesNotMatch(h.pwa.state.message,/iPhone blokuje/);
});
test('iPhone first launch preserves permission-before-login order',async()=>{
 const h=await setup({ios:true});assert.equal(h.elements.length,1);assert.equal(h.calls.length,0);
 await h.pwa.enablePush();assert.deepEqual(h.calls,['permission','login']);
});
