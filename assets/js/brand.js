(()=>{
  const cfg=window.TypujKoszaBrand||{};
  const root=document.getElementById('decka-typer');
  if(!root)return;

  root.querySelectorAll('.dt-login-divider,.dt-wp-login').forEach(el=>el.remove());

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
