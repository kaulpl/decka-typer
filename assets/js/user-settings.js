(()=>{
  const cfg=window.DeckaTyper||{};
  const accountCfg=window.DeckaTyperAccountConfig||{};
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
  const infoIcon=`<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.1"/></svg>`;
  const isMobileDevice=()=>matchMedia('(max-width: 760px), (hover: none) and (pointer: coarse)').matches;
  const isIos=()=>/iphone|ipad|ipod/i.test(navigator.userAgent);
  const isAndroid=()=>/android/i.test(navigator.userAgent);
  const isStandalone=()=>matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;

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
  let favoriteTeamId=Number(accountCfg.favoriteTeamId||0);
  let favoriteLeague='';

  const providerLabel=p=>p==='google'?'Google':p==='facebook'?'Facebook':p;
  const fmtRegistered=value=>{
    if(!value)return '—';
    const parsed=new Date(value.replace(' ','T')+'Z');
    if(Number.isNaN(parsed.getTime()))return value;
    return new Intl.DateTimeFormat('pl-PL',{day:'2-digit',month:'long',year:'numeric'}).format(parsed);
  };

  const decorateFavoriteMatches=()=>{
    root.querySelectorAll('#dt-matches [data-match-card]').forEach(card=>{
      card.classList.remove('is-favorite-team');
      card.querySelectorAll(':scope > .dt-favorite-ribbon').forEach(ribbon=>ribbon.remove());
      card.querySelectorAll('.dt-team-choice[data-team]').forEach(team=>{
        const isFavorite=favoriteTeamId>0&&Number(team.dataset.team||0)===favoriteTeamId;
        team.classList.toggle('is-favorite-team-choice',isFavorite);
        let ribbon=team.querySelector(':scope > .dt-favorite-ribbon');
        if(isFavorite&&!ribbon){
          ribbon=document.createElement('span');
          ribbon.className='dt-favorite-ribbon';
          ribbon.textContent='ULUBIONA DRUŻYNA';
          team.appendChild(ribbon);
        }else if(!isFavorite&&ribbon){
          ribbon.remove();
        }
      });
    });
  };

  const matchBox=root.querySelector('#dt-matches');
  if(matchBox)new MutationObserver(()=>requestAnimationFrame(decorateFavoriteMatches)).observe(matchBox,{childList:true,subtree:true});
  decorateFavoriteMatches();

  const render=data=>{
    account=data;
    favoriteTeamId=Number(data.favorite_team_id||0);
    const target=box();if(!target)return;
    const providers=(data.providers||[]).length
      ? data.providers.map(p=>`<span class="dt-account-provider is-${esc(p)}">${esc(providerLabel(p))}</span>`).join('')
      : '<span class="dt-account-provider">konto WordPress</span>';
    const teams=Array.isArray(data.teams)?data.teams:[];
    const favoriteTeam=teams.find(team=>Number(team.id||0)===favoriteTeamId);
    if(!favoriteLeague)favoriteLeague=String(favoriteTeam?.leagues?.[0]||'1lm').toLowerCase();
    const leagueTeams=teams.filter(team=>(team.leagues||[]).map(String).map(x=>x.toLowerCase()).includes(favoriteLeague));
    const teamOptions=['<option value="0">Wybierz drużynę</option>',...leagueTeams.map(team=>`<option value="${Number(team.id||0)}" ${Number(team.id||0)===favoriteTeamId?'selected':''}>${esc(team.name)}</option>`)].join('');
    const leagueButtons=['1lm','plk','2lm'].map(league=>`<button type="button" data-favorite-league="${league}" class="${favoriteLeague===league?'is-active':''}">${league.toUpperCase()}</button>`).join('');
    const notifications=data.notifications||{};
    const checked=key=>notifications[key]?'checked':'';
    const installButton=isMobileDevice()&&!isStandalone()?'<button type="button" class="dt-account-button is-secondary" id="dt-install-pwa">Dodaj do ekranu głównego</button>':'';

    target.innerHTML=`
      <section class="dt-account-card dt-account-primary">
        <div class="dt-account-card-head"><div><span class="dt-front-kicker">PERSONALIZACJA</span><h2>Twój profil Typera</h2></div><span class="dt-account-hint">Widoczne w Typerze</span></div>
        <form id="dt-profile-settings-form" class="dt-account-form">
          <label for="dt-ranking-name">Nazwa użytkownika w rankingach</label>
          <input id="dt-ranking-name" name="ranking_name" type="text" minlength="2" maxlength="40" autocomplete="nickname" value="${esc(data.ranking_name)}">
          <small>Może mieć od 2 do 40 znaków. Zmiana nie zmienia loginu ani adresu e-mail konta.</small>

          <div class="dt-account-form-divider"></div>
          <label for="dt-favorite-team">Ulubiona drużyna</label>
          <div class="dt-favorite-league-picker" role="group" aria-label="Wybierz ligę ulubionej drużyny">${leagueButtons}</div>
          <div class="dt-account-select-wrap"><select id="dt-favorite-team" name="favorite_team_id">${teamOptions}</select></div>
          <small>Najpierw wybierz ligę, a następnie jedną z aktualnych drużyn. Jej mecze będą oznaczone niebieską szarfą „ULUBIONA DRUŻYNA”.</small>

          <div class="dt-account-form-divider"></div>
          <div class="dt-notification-heading"><span class="dt-front-kicker">PRZYPOMNIENIA</span><button type="button" class="dt-notification-info-button" aria-expanded="false" aria-controls="dt-notification-help">${infoIcon}<span>Jak działają?</span></button></div>
          <div class="dt-notification-help" id="dt-notification-help" hidden>
            <strong>Jak działają powiadomienia?</strong>
            <dl>
              <div><dt>E-mail</dt><dd>Wysyła przypomnienia o typowaniu i zmianach terminów na adres przypisany do Twojego konta.</dd></div>
              <div><dt>Web Push / PWA</dt><dd>Pokazuje te same przypomnienia jako powiadomienia systemowe na komputerze lub telefonie.</dd></div>
            </dl>
          </div>
          <div class="dt-notification-options">
            <label><span>E-mail</span><input type="checkbox" name="notify_email" ${checked('email')}><i aria-hidden="true"></i></label>
            <label><span>Web Push / PWA</span><input type="checkbox" name="notify_push" ${checked('push')} ${data.push_ready?'':'disabled'}><i aria-hidden="true"></i></label>
          </div>
          <div class="dt-notification-actions">${installButton}</div>
          <small>${data.push_ready?'Wybierz kanały, którymi chcesz otrzymywać przypomnienia o typowaniu i zmianach terminów.':'Kanał Push oczekuje na konfigurację OneSignal przez administratora. Powiadomienia e-mail działają niezależnie.'}</small>

          <div class="dt-account-save-row"><button type="submit">Zapisz ustawienia</button></div>
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
        <p class="dt-account-footnote">Sesja Typera jest utrzymywana na tym urządzeniu. Wylogowanie następuje dopiero po użyciu opcji „Wyloguj się” albo po usunięciu danych/cookies przeglądarki.</p>
      </section>`;

    target.querySelector('#dt-profile-settings-form')?.addEventListener('submit',save);
    target.querySelectorAll('[data-favorite-league]').forEach(button=>button.addEventListener('click',()=>{
      favoriteLeague=String(button.dataset.favoriteLeague||'1lm');
      render(account);
    }));
    target.querySelector('[name="notify_push"]')?.addEventListener('change',async event=>{const toggle=event.currentTarget;if(!toggle.checked)return;toggle.disabled=true;try{await window.DeckaTyperPwa.enablePush();toggle.checked=true;}catch(error){toggle.checked=false;alert(error.message||'Nie udało się włączyć powiadomień.');}finally{toggle.disabled=false;}});
    target.querySelector('.dt-notification-info-button')?.addEventListener('click',event=>{const button=event.currentTarget;const help=target.querySelector('#dt-notification-help');const open=button.getAttribute('aria-expanded')!=='true';button.setAttribute('aria-expanded',String(open));if(help)help.hidden=!open;});
    target.querySelector('#dt-install-pwa')?.addEventListener('click',async()=>{
      if(isIos()){alert('Na iPhonie dotknij ikony Udostępnij w Safari/Chrome, a następnie wybierz „Do ekranu początkowego” i potwierdź „Dodaj”.');return;}
      if(isAndroid()){
        const installed=await window.DeckaTyperPwa?.install();
        if(!installed)alert('Otwórz menu Chrome (trzy kropki) i wybierz „Dodaj do ekranu głównego” albo „Zainstaluj aplikację”.');
      }
    });
    decorateFavoriteMatches();
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
    const favorite=form.querySelector('#dt-favorite-team');
    const button=form.querySelector('button[type="submit"]');
    const message=form.querySelector('#dt-account-message');
    const name=String(input?.value||'').trim();
    const selectedFavorite=Number(favorite?.value||0);
    const previousFavorite=favoriteTeamId;
    if(name.length<2||name.length>40){message.textContent='Nazwa musi mieć od 2 do 40 znaków.';message.className='dt-account-message is-error';return;}
    button.disabled=true;message.textContent='Zapisywanie…';message.className='dt-account-message';
    try{
      const notifications={
        email:form.querySelector('[name="notify_email"]')?.checked?1:0,
        push:form.querySelector('[name="notify_push"]')?.checked?1:0,
        standard:1,schedule_changes:1,postponed:1,incomplete:1,reminder_3d:1,reminder_6h:1
      };
      const data=await api('account',{method:'POST',body:JSON.stringify({ranking_name:name,favorite_team_id:selectedFavorite,notifications})});
      account=data.account||account;
      favoriteTeamId=Number(account?.favorite_team_id||0);
      accountCfg.favoriteTeamId=favoriteTeamId;
      decorateFavoriteMatches();
      message.textContent='Zapisano ustawienia profilu.';
      message.className='dt-account-message is-success';
      if(selectedFavorite>0&&selectedFavorite!==previousFavorite)root.dispatchEvent(new CustomEvent('dt:avatar',{detail:{key:'favorite'}}));
    }catch(err){message.textContent=err.message;message.className='dt-account-message is-error';}
    finally{button.disabled=false;}
  };

  root.addEventListener('click',e=>{
    if(e.target.closest('[data-tab="settings"]'))load(true);
  });

  const requireRankingName=async()=>{
    try{
      const data=await api('account');
      if(data.ranking_name_set)return;
      const modal=document.createElement('dialog');
      modal.className='dt-name-onboarding';
      modal.innerHTML=`<form method="dialog"><span class="dt-front-kicker">WITAJ W TYPOWANIU</span><h2>Ustaw nazwę, która będzie przy Tobie wyświetlana</h2><p>Logujesz się jako <strong>${esc(data.username)}</strong>. Wybierz własną nazwę widoczną w rankingach i przy Twoich typach.</p><label for="dt-onboarding-name">Twoja nazwa w TypujKosza.pl</label><input id="dt-onboarding-name" type="text" minlength="2" maxlength="40" autocomplete="nickname" value="${esc(data.username)}" required autofocus><small>Od 2 do 40 znaków. Nazwę będzie można później zmienić w „Moim koncie”.</small><button type="submit">Zapisz nazwę i przejdź do typowania</button><div class="dt-onboarding-message" aria-live="polite"></div></form>`;
      root.appendChild(modal);modal.showModal();
      modal.addEventListener('cancel',e=>e.preventDefault());
      modal.querySelector('form').addEventListener('submit',async e=>{
        e.preventDefault();const input=modal.querySelector('input'),button=modal.querySelector('button'),message=modal.querySelector('.dt-onboarding-message'),name=String(input.value||'').trim();
        if(name.length<2||name.length>40){message.textContent='Nazwa musi mieć od 2 do 40 znaków.';return;}
        button.disabled=true;message.textContent='Zapisywanie…';
        try{await api('account',{method:'POST',body:JSON.stringify({ranking_name:name})});window.location.reload();}
        catch(err){message.textContent=err.message;button.disabled=false;}
      });
    }catch(_){/* Formularz pojawi się ponownie po następnym odświeżeniu. */}
  };
  requireRankingName();
})();
