(()=>{
  const cfg=window.DeckaTyper||{};
  const root=document.getElementById('decka-typer');
  if(!root||!cfg.loggedIn)return;

  const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
  const api=async(path,opt={})=>{
    const headers={'Content-Type':'application/json',...(opt.headers||{})};
    if(cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;
    const response=await fetch(cfg.root+path,{credentials:'same-origin',...opt,headers});
    let data={};
    try{data=await response.json();}catch(_){data={message:'Serwer nie zwrócił poprawnej odpowiedzi.'};}
    if(!response.ok)throw new Error(data.message||'Nie udało się wykonać operacji.');
    return data;
  };

  const gear=`<svg class="dt-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1z"/></svg>`;

  const tabs=root.querySelector('.dt-tabs');
  const main=root.querySelector('.dt-app-main');
  if(!tabs||!main)return;

  if(!tabs.querySelector('[data-tab="settings"]')){
    const button=document.createElement('button');
    button.type='button';
    button.dataset.tab='settings';
    button.innerHTML=`${gear}<span>Ustawienia</span>`;
    tabs.appendChild(button);
  }

  if(!main.querySelector('[data-panel="settings"]')){
    const panel=document.createElement('div');
    panel.className='dt-tab-panel';
    panel.dataset.panel='settings';
    panel.innerHTML=`
      <div class="dt-panel-head"><div><span class="dt-front-kicker">TWOJE KONTO</span><h1>Ustawienia</h1></div></div>
      <div id="dt-account-settings" class="dt-account-settings"><div class="dt-account-loading">Ładowanie ustawień…</div></div>`;
    main.appendChild(panel);
  }

  const box=()=>root.querySelector('#dt-account-settings');
  let loaded=false;
  let account=null;

  const providerLabel=p=>p==='google'?'Google':p==='facebook'?'Facebook':p;
  const fmtRegistered=value=>{
    if(!value)return '—';
    const parsed=new Date(value.replace(' ','T')+'Z');
    if(Number.isNaN(parsed.getTime()))return value;
    return new Intl.DateTimeFormat('pl-PL',{day:'2-digit',month:'long',year:'numeric'}).format(parsed);
  };

  const render=data=>{
    account=data;
    const target=box();if(!target)return;
    const providers=(data.providers||[]).length
      ? data.providers.map(p=>`<span class="dt-account-provider is-${esc(p)}">${esc(providerLabel(p))}</span>`).join('')
      : '<span class="dt-account-provider">konto WordPress</span>';

    target.innerHTML=`
      <section class="dt-account-card dt-account-primary">
        <div class="dt-account-card-head"><div><span class="dt-front-kicker">RANKING</span><h2>Nazwa publiczna</h2></div><span class="dt-account-hint">Widoczna dla innych użytkowników</span></div>
        <form id="dt-ranking-name-form" class="dt-account-form">
          <label for="dt-ranking-name">Nazwa użytkownika w rankingach</label>
          <div class="dt-account-input-row"><input id="dt-ranking-name" name="ranking_name" type="text" minlength="2" maxlength="40" autocomplete="nickname" value="${esc(data.ranking_name)}"><button type="submit">Zapisz</button></div>
          <small>Może mieć od 2 do 40 znaków. Zmiana nie zmienia loginu ani adresu e-mail konta.</small>
          <div id="dt-account-message" class="dt-account-message" aria-live="polite"></div>
        </form>
      </section>

      <section class="dt-account-card">
        <div class="dt-account-card-head"><div><span class="dt-front-kicker">DANE KONTA</span><h2>Twoje konto</h2></div></div>
        <div class="dt-account-grid">
          <div><span>Login</span><strong>${esc(data.username)}</strong></div>
          <div><span>E-mail</span><strong>${esc(data.email)}</strong></div>
          <div><span>Konto od</span><strong>${esc(fmtRegistered(data.registered_at))}</strong></div>
          <div><span>Logowanie</span><strong class="dt-account-providers">${providers}</strong></div>
        </div>
        <div class="dt-account-actions">
          <a class="dt-account-button" href="${esc(data.password_url)}">Ustaw / zmień hasło</a>
          <a class="dt-account-button is-secondary" href="${esc(data.logout_url)}">Wyloguj się</a>
        </div>
        <p class="dt-account-footnote">Zmiana hasła odbywa się przez standardowy, bezpieczny mechanizm WordPress i link wysłany na adres e-mail konta.</p>
      </section>`;

    target.querySelector('#dt-ranking-name-form')?.addEventListener('submit',save);
  };

  const load=async(force=false)=>{
    if(loaded&&!force)return;
    const target=box();if(target)target.innerHTML='<div class="dt-account-loading">Ładowanie ustawień…</div>';
    try{
      const data=await api('account');loaded=true;render(data);
    }catch(e){if(target)target.innerHTML=`<div class="dt-account-error">${esc(e.message)}</div>`;}
  };

  const save=async e=>{
    e.preventDefault();
    const form=e.currentTarget;
    const input=form.querySelector('#dt-ranking-name');
    const button=form.querySelector('button[type="submit"]');
    const message=form.querySelector('#dt-account-message');
    const name=String(input?.value||'').trim();
    if(name.length<2||name.length>40){message.textContent='Nazwa musi mieć od 2 do 40 znaków.';message.className='dt-account-message is-error';return;}
    button.disabled=true;message.textContent='Zapisywanie…';message.className='dt-account-message';
    try{
      const data=await api('account',{method:'POST',body:JSON.stringify({ranking_name:name})});
      account=data.account||account;
      message.textContent='Zapisano. Nowa nazwa będzie używana w rankingach.';
      message.className='dt-account-message is-success';
    }catch(err){message.textContent=err.message;message.className='dt-account-message is-error';}
    finally{button.disabled=false;}
  };

  root.addEventListener('click',e=>{
    if(e.target.closest('[data-tab="settings"]'))load(true);
  });
})();
