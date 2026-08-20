(()=>{
  const cfg=window.TypujKoszaBrand||{};
  const root=document.getElementById('decka-typer');
  if(!root)return;

  root.querySelectorAll('.dt-login-divider,.dt-wp-login').forEach(el=>el.remove());

  const hero=root.querySelector('.dt-front-hero');
  if(hero){
    const inner=hero.querySelector('.dt-hero-inner');
    const brand=hero.querySelector('.dt-brand');
    const league=hero.querySelector('.dt-live-pill');

    if(inner)inner.classList.add('tk-hero-inner');
    if(league)league.remove();

    if(brand){
      brand.classList.add('tk-hero-brand');

      let logo=brand.querySelector('img');
      if(!logo){
        logo=document.createElement('img');
        brand.prepend(logo);
      }
      logo.src=cfg.logoHorizontal||'';
      logo.alt=cfg.name||'TypujKosza.pl';
      logo.className='tk-hero-logo';

      const legacy=brand.querySelector(':scope > div');
      if(legacy)legacy.remove();

      let tagline=brand.querySelector('.tk-hero-tagline');
      if(!tagline){
        tagline=document.createElement('p');
        tagline.className='tk-hero-tagline';
        brand.append(tagline);
      }
      tagline.textContent=cfg.tagline||'Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.';
    }
  }

  const copy=root.querySelector('.dt-login-copy');
  if(copy){
    const kicker=copy.querySelector('.dt-front-kicker');
    const title=copy.querySelector('h1');
    const paragraph=copy.querySelector(':scope > p');
    if(kicker)kicker.textContent='TYPUJKOSZA.PL';
    if(title)title.textContent='Wejdź do gry.';
    if(paragraph)paragraph.textContent=cfg.tagline||'Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.';
  }

  const setup=root.querySelector('.dt-setup-note');
  if(setup)setup.innerHTML=setup.innerHTML.replace(/Decka Typer/g,'TypujKosza.pl');

  const legal=root.querySelector('.dt-login-legal');
  if(legal)legal.textContent='Logując się, akceptujesz zasady TypujKosza.pl i politykę prywatności serwisu.';
})();
