(()=>{
  const cfg=window.DeckaTyperSubmission||{};
  if(!cfg.ajaxUrl||!cfg.action||!cfg.nonce||typeof window.fetch!=='function')return;

  const originalFetch=window.fetch.bind(window);
  window.fetch=async(input,options={})=>{
    const url=typeof input==='string'?input:(input&&input.url?input.url:'');
    const method=String(options.method||'GET').toUpperCase();
    const isSubmission=method==='POST'&&/\/decka-typer\/v1\/submission(?:\?|$)/.test(url);
    if(!isSubmission)return originalFetch(input,options);

    let payload={};
    try{payload=options.body?JSON.parse(options.body):{};}catch(_){payload={};}

    const body=new URLSearchParams();
    body.set('action',cfg.action);
    body.set('nonce',cfg.nonce);
    body.set('payload',JSON.stringify(payload));

    let response;
    try{
      response=await originalFetch(cfg.ajaxUrl,{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:body.toString()
      });
    }catch(_){
      return new Response(JSON.stringify({message:'Nie udało się połączyć z serwerem podczas zapisu kuponu.'}),{
        status:503,
        headers:{'Content-Type':'application/json'}
      });
    }

    const raw=await response.text();
    let parsed=null;
    try{parsed=JSON.parse(raw);}catch(_){
      return new Response(JSON.stringify({message:`Serwer nie zwrócił poprawnej odpowiedzi zapisu (HTTP ${response.status||500}).`}),{
        status:response.ok?500:response.status,
        headers:{'Content-Type':'application/json'}
      });
    }

    const success=parsed&&parsed.success===true;
    const data=parsed&&parsed.data?parsed.data:{};
    const normalized=success
      ? data
      : {message:(data&&data.message)?data.message:'Nie udało się zapisać kuponu.'};

    return new Response(JSON.stringify(normalized),{
      status:success?(response.status>=200&&response.status<300?response.status:200):(response.status>=400?response.status:500),
      headers:{'Content-Type':'application/json'}
    });
  };
})();
