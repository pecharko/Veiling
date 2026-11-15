(() => {
  'use strict';
  const form=document.getElementById('lot-editor-form');
  const select=form?.querySelector('select[name="seller_id"]');
  const button=document.getElementById('lot-seller-open');
  const display=document.getElementById('lot-seller-selected');
  const selectedIcon=document.getElementById('lot-seller-selection-icon');
  const dialog=document.getElementById('lot-seller-dialog');
  const search=dialog?.querySelector('[data-seller-search]');
  const results=dialog?.querySelector('[data-seller-results]');
  const typeButtons=[...(dialog?.querySelectorAll('[data-seller-type]') || [])];
  const endpoint=button?.dataset.searchUrl;
  if (!form || !select || !button || !display || !dialog || !search || !results || !endpoint) return;

  const donation=form.querySelector('[name="donation_confirmed"]');
  const createLinks=[...document.querySelectorAll('[data-create-seller]')];
  const warning='Er is geen verkoper geselecteerd, deze kavel wordt beschouwd als geschonken voor de veiling er zal geen uitbetaling gebeuren naar de schenker, ga jij hiermee akkoord?';
  let requestNumber=0, timeout=null, controller=null, filter='', lastFocus=null, refreshOnFocus=false;
  function showSelectedIcon(item) {
    if (!selectedIcon) return;
    selectedIcon.replaceChildren();
    const fallback=item?.type==='kunstenaar'?'🎨':'👤';
    if (item?.type==='kunstenaar' && item.portrait) {
      const img=document.createElement('img');
      img.src=item.portrait;
      img.alt='';
      img.width=38;
      img.height=38;
      img.decoding='async';
      img.addEventListener('error',()=>{if(img.isConnected)selectedIcon.textContent=fallback;},{once:true});
      selectedIcon.append(img);
    } else selectedIcon.textContent=fallback;
  }
  function setSeller(item) {
    select.replaceChildren(new Option('Geen verkoper geselecteerd',''));
    if (item) {
      select.add(new Option(item.name+' · '+(item.type==='kunstenaar'?'Kunstenaar':'Particulier'),String(item.id),true,true));
      display.textContent=item.name+' · '+(item.type==='kunstenaar'?'Kunstenaar':'Particulier');
    } else {
      select.value='';
      display.textContent='Geen verkoper geselecteerd (schenking)';
    }
    showSelectedIcon(item);
    select.dispatchEvent(new Event('change',{bubbles:true}));
    if (donation) donation.value='0';
    dialog.close();
    (lastFocus?.isConnected ? lastFocus : button).focus();
  }
  function render(items) {
    results.replaceChildren();
    if (!items.length) {
      const p=document.createElement('p');
      p.className='lot-seller-empty';
      p.textContent='Geen verkopers gevonden. Probeer een andere zoekterm.';
      results.appendChild(p);
      return;
    }
    for (const item of items) {
      const choice=document.createElement('button');
      choice.type='button';
      choice.className='lot-seller-result';
      const icon=document.createElement('span');
      icon.className='lot-seller-result-icon';
      icon.setAttribute('aria-hidden','true');
      if (item.type==='kunstenaar' && item.portrait) {
        const img=document.createElement('img');
        img.src=item.portrait;
        img.alt='';
        img.loading='lazy';
        icon.append(img);
      } else {
        icon.textContent=item.type==='kunstenaar'?'🎨':'👤';
      }
      const info=document.createElement('span');
      const name=document.createElement('strong');
      name.textContent=item.name;
      const meta=document.createElement('small');
      meta.textContent=item.type==='kunstenaar'?'Kunstenaar':'Particulier';
      info.append(name,meta);
      choice.append(icon,info);
      if (String(item.id)===select.value) {
        const mark=document.createElement('span');
        mark.textContent='✓ Geselecteerd';
        mark.className='lot-seller-result-current';
        choice.append(mark);
      }
      choice.addEventListener('click',()=>setSeller(item));
      results.append(choice);
    }
  }
  async function fetchSellers(extra={}) {
    const number=++requestNumber;
    controller?.abort();
    controller=new AbortController();
    const url=new URL(endpoint,window.location.href);
    if (extra.id) url.searchParams.set('id',String(extra.id));
    else {
      url.searchParams.set('q',search.value.trim());
      if (filter) url.searchParams.set('type',filter);
    }
    if (!extra.id) results.textContent='Zoeken…';
    try {
      const response=await fetch(url.toString(),{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'},signal:controller.signal});
      if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Je sessie is mogelijk verlopen.');
      const data=await response.json();
      if (!response.ok || !Array.isArray(data.items)) throw new Error(data.error||'Zoeken mislukt.');
      if (number!==requestNumber) return null;
      if (extra.id) return data.items[0]||null;
      render(data.items);
      return data.items;
    } catch (error) {
      if (number!==requestNumber || error?.name==='AbortError') return null;
      if (!extra.id) results.textContent='Zoeken is tijdelijk niet beschikbaar. Probeer opnieuw.';
      return null;
    }
  }
  function schedule() {
    clearTimeout(timeout);
    timeout=setTimeout(()=>void fetchSellers(),180);
  }
  button.addEventListener('click',()=>{
    lastFocus=document.activeElement;
    dialog.showModal();
    search.value='';
    filter='';
    typeButtons.forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.sellerType==='')));
    search.focus();
    void fetchSellers();
  });
  dialog.querySelectorAll('[data-seller-close]').forEach(close=>close.addEventListener('click',()=>dialog.close()));
  dialog.querySelector('[data-seller-empty]')?.addEventListener('click',()=>setSeller(null));
  dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
  dialog.addEventListener('close',()=>{clearTimeout(timeout);controller?.abort();(lastFocus?.isConnected?lastFocus:button).focus();});
  search.addEventListener('input',schedule);
  typeButtons.forEach(b=>b.addEventListener('click',()=>{
    filter=b.dataset.sellerType;
    typeButtons.forEach(option=>option.setAttribute('aria-pressed',String(option===b)));
    void fetchSellers();
  }));
  createLinks.forEach(link=>link.addEventListener('click',()=>{refreshOnFocus=true;}));
  window.addEventListener('message',event=>{
    if (event.origin!==window.location.origin || event.data?.type!=='lot-seller-created') return;
    const id=Number(event.data.sellerId);
    if (!Number.isSafeInteger(id)||id<1) return;
    refreshOnFocus=false;
    void fetchSellers({id}).then(item=>{if(item)setSeller(item);});
  });
  window.addEventListener('focus',()=>{
    if (!refreshOnFocus)return;
    refreshOnFocus=false;
    if(dialog.open)void fetchSellers();
  });
  if (donation) {
    select.addEventListener('change',()=>{donation.value='0';});
    form.addEventListener('submit',event=>{
      if (select.value!=='') {donation.value='0';return;}
      if(donation.value==='1')return;
      if(!window.confirm(warning)) {event.preventDefault();donation.value='0';button.focus();return;}
      donation.value='1';
    });
  }
})();
