(()=>{
  const cfg=window.TypujKoszaSiteMode||{};
  if(cfg.mode!=='test'||document.querySelector('.tk-test-banner'))return;
  const show=()=>{
    if(document.querySelector('.tk-test-banner'))return;
    const banner=document.createElement('div');
    banner.className='tk-test-banner';
    banner.setAttribute('role','status');
    banner.innerHTML='<strong>WERSJA TESTOWA</strong><span>TypujKosza.pl działa obecnie w trybie testowym. Wyniki, punkty i dane mogą być jeszcze korygowane przed startem sezonu.</span>';
    document.body.insertBefore(banner,document.body.firstChild);
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',show,{once:true});else show();
})();
