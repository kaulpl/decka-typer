(()=>{
  const cfg=window.TypujKoszaAdminBrand||{};
  document.querySelectorAll('.dt-admin-head').forEach(head=>{
    const logo=head.querySelector('img');
    if(logo&&cfg.logoHorizontal){logo.src=cfg.logoHorizontal;logo.alt='TypujKosza.pl';}
    const kicker=head.querySelector('.dt-kicker');
    if(kicker)kicker.textContent='TYPUJKOSZA.PL · TYPER';
  });
})();
