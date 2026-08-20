(()=>{
  document.addEventListener('click',event=>{
    const link=event.target.closest('a.dt-account-button.is-secondary');
    if(!link)return;
    const raw=link.getAttribute('href')||'';
    if(!raw.includes('dt_typer_logout'))return;
    const fixed=raw.replace(/&amp;/g,'&');
    if(fixed===raw)return;
    event.preventDefault();
    window.location.assign(fixed);
  });
})();
