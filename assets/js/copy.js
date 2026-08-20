(()=>{
  const pairs=[
    ['Czas na pierwszy kupon','Czas na pierwsze typowanie'],
    ['czas na pierwszy kupon','czas na pierwsze typowanie'],
    ['zapisz jeden kompletny kupon','zapisz kompletne typowanie'],
    ['Zapisz jeden kompletny kupon','Zapisz kompletne typowanie'],
    ['zapisz jeden kupon','zapisz swoje typowanie'],
    ['Zapisz jeden kupon','Zapisz swoje typowanie'],
    ['jeden kompletny kupon','kompletne typowanie'],
    ['Jeden kompletny kupon','Kompletne typowanie'],
    ['Jeden kupon','Jedno typowanie'],
    ['jeden kupon','jedno typowanie'],
    ['Ten kupon został już zapisany i nie można go edytować.','To typowanie zostało już zapisane i nie można go edytować.'],
    ['ten kupon został już zapisany i nie można go edytować.','to typowanie zostało już zapisane i nie można go edytować.'],
    ['Kupon zapisany','Typowanie zapisane'],
    ['kupon zapisany','typowanie zapisane'],
    ['Tak, zapisz kupon','Tak, zapisz typowanie'],
    ['Zapisz kupon','Zapisz typowanie'],
    ['zapisz kupon','zapisz typowanie'],
    ['Po zapisaniu kuponu','Po zapisaniu typowania'],
    ['po zapisaniu kuponu','po zapisaniu typowania'],
    ['Po zatwierdzeniu kuponu','Po zatwierdzeniu typowania'],
    ['po zatwierdzeniu kuponu','po zatwierdzeniu typowania'],
    ['kupon jest zamknięty','typowanie jest zamknięte'],
    ['Kupon jest zamknięty','Typowanie jest zamknięte'],
    ['Edycja kuponu','Edycja typowania'],
    ['edycja kuponu','edycja typowania'],
    ['zamknięcia kuponów','zamknięcia typowania'],
    ['Zamknięcia kuponów','Zamknięcia typowania'],
    ['Kupony są nieedytowalne','Typowania są nieedytowalne'],
    ['kupony są nieedytowalne','typowania są nieedytowalne'],
    ['nieedytowalny kupon','nieedytowalne typowanie'],
    ['Nieedytowalny kupon','Nieedytowalne typowanie'],
    ['podczas zapisu kuponu','podczas zapisu typowania'],
    ['Podczas zapisu kuponu','Podczas zapisu typowania'],
    ['dane kuponu','dane typowania'],
    ['Dane kuponu','Dane typowania'],
    ['blokady kuponu','blokady typowania'],
    ['Blokady kuponu','Blokady typowania'],
    ['zatwierdzić kuponu','zatwierdzić typowania'],
    ['Zatwierdzić kuponu','Zatwierdzić typowania'],
    ['zapisać kuponu','zapisać typowania'],
    ['Zapisać kuponu','Zapisać typowania'],
    ['Kuponów','Typowań'],['kuponów','typowań'],
    ['Kupony','Typowania'],['kupony','typowania'],
    ['Kuponu','Typowania'],['kuponu','typowania'],
    ['Kuponem','Typowaniem'],['kuponem','typowaniem'],
    ['Kuponie','Typowaniu'],['kuponie','typowaniu'],
    ['Kupon','Typowanie'],['kupon','typowanie']
  ];

  const rewrite=value=>{
    let out=String(value??'');
    for(const [from,to] of pairs)out=out.split(from).join(to);
    return out;
  };

  const rewriteNode=node=>{
    if(!node)return;
    if(node.nodeType===Node.TEXT_NODE){
      const p=node.parentElement;
      if(!p||/^(SCRIPT|STYLE|TEXTAREA|CODE|PRE)$/i.test(p.tagName))return;
      const next=rewrite(node.nodeValue);
      if(next!==node.nodeValue)node.nodeValue=next;
      return;
    }
    if(node.nodeType!==Node.ELEMENT_NODE)return;
    const el=node;
    for(const attr of ['title','aria-label','placeholder']){
      if(!el.hasAttribute(attr))continue;
      const current=el.getAttribute(attr)||'';
      const next=rewrite(current);
      if(next!==current)el.setAttribute(attr,next);
    }
    const walker=document.createTreeWalker(el,NodeFilter.SHOW_TEXT);
    let text;
    while((text=walker.nextNode()))rewriteNode(text);
  };

  const start=()=>{
    if(!document.body)return;
    rewriteNode(document.body);
    const observer=new MutationObserver(mutations=>{
      for(const mutation of mutations){
        if(mutation.type==='characterData')rewriteNode(mutation.target);
        for(const node of mutation.addedNodes||[])rewriteNode(node);
      }
    });
    observer.observe(document.body,{subtree:true,childList:true,characterData:true});
  };

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();
