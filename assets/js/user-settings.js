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

  const showQrModal=()=>{
    let modal=document.getElementById('dt-pwa-qr-modal');
    if(!modal){
      modal=document.createElement('dialog');
      modal.id='dt-pwa-qr-modal';
      modal.className='dt-pwa-qr-modal';
      modal.innerHTML=`<div class="dt-pwa-qr-card"><button type="button" class="dt-pwa-qr-close" aria-label="Zamknij">&times;</button><span class="dt-front-kicker">TYPOWANIE ZAWSZE POD RĘKĄ</span><h2>Zeskanuj kod telefonem</h2><p>Otwórz aparat w telefonie, zeskanuj kod i dodaj TypujKosza.pl do ekranu głównego.</p><img src="${esc(accountCfg.pwaQrUrl||'')}" width="260" height="260" alt="Kod QR prowadzący do TypujKosza.pl"><a href="${esc(accountCfg.siteUrl||'/')}" target="_blank" rel="noopener">${esc(accountCfg.siteUrl||'TypujKosza.pl')}</a></div>`;
      document.body.appendChild(modal);
      modal.querySelector('.dt-pwa-qr-close')?.addEventListener('click',()=>modal.close());
      modal.addEventListener('click',event=>{if(event.target===modal)modal.close();});
    }
    modal.showModal();
  };

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
              <div><dt>E-mail</dt><dd>Wysyła przypomnienia na adres przypisany do Twojego konta.</dd></div>
              <div><dt>Web Push / PWA</dt><dd>Pokazuje powiadomienia systemowe w przeglądarce lub w aplikacji dodanej do ekranu telefonu.</dd></div>
              <div><dt>Tryb standardowy</dt><dd>Włącza zwykłe przypomnienia o niedokończonych typowaniach w wybranych terminach. Wyłączenie go pozostawia tylko wybrane alerty o zmianach terminarza i przełożonych meczach.</dd></div>
              <div><dt>Zmiany harmonogramu</dt><dd>Informuje, gdy synchronizacja wykryje zmianę daty lub godziny spotkania.</dd></div>
              <div><dt>Przełożone mecze</dt><dd>Ostrzega o przełożeniu meczu i konieczności ponownego wpisania wyzerowanego typu.</dd></div>
              <div><dt>Niedokończona kolejka</dt><dd>Podaje ligę, kolejkę oraz liczbę spotkań, których jeszcze nie wytypowano.</dd></div>
              <div><dt>3 dni / 6 godzin</dt><dd>Określa, jak wcześnie ma przyjść standardowe przypomnienie przed rozpoczęciem meczu.</dd></div>
            </dl>
          </div>
          <div class="dt-notification-options">
            <label><span>E-mail</span><input type="checkbox" name="notify_email" ${checked('email')}><i aria-hidden="true"></i></label>
            <label><span>Web Push / PWA</span><input type="checkbox" name="notify_push" ${checked('push')} ${data.push_ready?'':'disabled'}><i aria-hidden="true"></i></label>
            <label><span>Tryb standardowy</span><input type="checkbox" name="notify_standard" ${checked('standard')}><i aria-hidden="true"></i></label>
            <label><span>Zmiany harmonogramu</span><input type="checkbox" name="notify_schedule_changes" ${checked('schedule_changes')}><i aria-hidden="true"></i></label>
            <label><span>Przełożone mecze</span><input type="checkbox" name="notify_postponed" ${checked('postponed')}><i aria-hidden="true"></i></label>
            <label><span>Niedokończona kolejka</span><input type="checkbox" name="notify_incomplete" ${checked('incomplete')}><i aria-hidden="true"></i></label>
            <label><span>3 dni przed meczem</span><input type="checkbox" name="notify_reminder_3d" ${checked('reminder_3d')}><i aria-hidden="true"></i></label>
            <label><span>6 godzin przed meczem</span><input type="checkbox" name="notify_reminder_6h" ${checked('reminder_6h')}><i aria-hidden="true"></i></label>
          </div>
          <div class="dt-notification-actions"><button type="button" class="dt-account-button is-secondary" id="dt-enable-push" ${data.push_ready?'':'disabled'}>Włącz powiadomienia w przeglądarce</button><button type="button" class="dt-account-button is-secondary" id="dt-test-push-device" ${data.push_ready?'':'disabled'}>Wyślij test na to urządzenie</button><button type="button" class="dt-account-button is-secondary" id="dt-install-pwa">${isMobileDevice()?'Dodaj TypujKosza.pl do telefonu':'Pokaż kod QR na telefon'}</button></div>
          <small>${data.push_ready?'Po włączeniu zaakceptuj systemowe pytanie przeglądarki. Na iPhonie najpierw dodaj stronę do ekranu początkowego przez Udostępnij → Do ekranu początkowego.':'Kanał Push oczekuje na konfigurację OneSignal przez administratora. Powiadomienia e-mail działają niezależnie.'}</small>

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
    target.querySelector('#dt-enable-push')?.addEventListener('click',async event=>{const button=event.currentTarget;button.disabled=true;try{await window.DeckaTyperPwa?.enablePush();const pushToggle=target.querySelector('[name="notify_push"]');if(pushToggle)pushToggle.checked=true;button.textContent='Powiadomienia włączone';target.querySelector('#dt-profile-settings-form')?.requestSubmit();}catch(error){alert(error.message||'Nie udało się włączyć powiadomień.');button.disabled=false;}});
    target.querySelector('#dt-test-push-device')?.addEventListener('click',async event=>{const button=event.currentTarget;const label=button.textContent;button.disabled=true;button.textContent='Wysyłanie testu…';try{const result=await window.DeckaTyperPwa?.testPush();const seconds=Number(result?.deliver_in_seconds||15);button.textContent='Test zaplanowany';alert('Test został zaplanowany za '+seconds+' sekund. Zamknij teraz aplikację lub przejdź do ekranu głównego iPhone’a, aby zobaczyć systemowe powiadomienie.');}catch(error){alert(error.message||'Nie udało się wysłać testu na to urządzenie.');button.textContent=label;button.disabled=false;}});
    target.querySelector('.dt-notification-info-button')?.addEventListener('click',event=>{const button=event.currentTarget;const help=target.querySelector('#dt-notification-help');const open=button.getAttribute('aria-expanded')!=='true';button.setAttribute('aria-expanded',String(open));if(help)help.hidden=!open;});
    target.querySelector('#dt-install-pwa')?.addEventListener('click',async()=>{
      if(!isMobileDevice()){showQrModal();return;}
      const installed=await window.DeckaTyperPwa?.install();
      if(!installed)alert(/iphone|ipad|ipod/i.test(navigator.userAgent)?'W Safari wybierz Udostępnij, a następnie „Do ekranu początkowego”.':'Otwórz menu przeglądarki i wybierz „Zainstaluj aplikację” albo „Dodaj do ekranu głównego”.');
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
      const notifications={};
      ['email','push','standard','schedule_changes','postponed','incomplete','reminder_3d','reminder_6h'].forEach(key=>{notifications[key]=form.querySelector(`[name="notify_${key}"]`)?.checked?1:0;});
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
