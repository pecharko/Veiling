/* Artist photo tiles: same staged/create and immediate/edit behavior as lot photos. */
(() => {
  'use strict';
  const form = document.getElementById('seller-manager-form');
  if (!form) return;
  const sellerId = form.querySelector('[name="seller_id"]').value;
  const grids = [...document.querySelectorAll('[data-seller-gallery]')];
  let busy = false, failure = false, queuedSave = false;
  const supported = new Set(['image/jpeg','image/png','image/webp']);
  const note = grid => document.querySelector('[data-seller-status="' + grid.dataset.sellerGallery + '"]');
  const update = grid => {
    const picker = grid.querySelector('[data-seller-picker]');
    const n = grid.querySelectorAll('.seller-saved-photo,.seller-pending-photo').length;
    picker.hidden = busy || n >= Number(grid.dataset.maxPhotos);
    picker.querySelector('input').disabled = busy;
  };
  const freeSlot = grid => {
    const occupied = new Set([...grid.querySelectorAll('[data-seller-slot]')].map(x => +x.dataset.sellerSlot));
    for (let i = 1; i <= +grid.dataset.maxPhotos; i++) if (!occupied.has(i)) return i;
    return 0;
  };
  const send = async (action,kind,slot,file) => {
    const data = new FormData();
    data.append('csrf',form.querySelector('[name="csrf"]').value);
    data.append('seller_id',sellerId);
    data.append('action',action);
    data.append('image_kind',kind);
    data.append('slot',String(slot));
    if (file) data.append('image',file,file.name);
    const response = await fetch(form.getAttribute('action') || location.href,{
      method:'POST',body:data,credentials:'same-origin',cache:'no-store',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
    });
    const result = await response.json();
    if (!response.ok || result.ok !== true || result.slot !== slot || result.kind !== kind)
      throw new Error(result.error || 'Fotoactie niet bevestigd.');
    return result;
  };
  form.addEventListener('submit',event => {
    if (!busy && !failure) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    queuedSave = busy && !failure;
    const status = grids.map(note).filter(Boolean)[0];
    if (status) status.textContent = failure
      ? 'Verwijder het mislukte fotovoorbeeld voor je de verkoper bewaart.'
      : 'Foto wordt eerst opgeslagen; daarna worden de verkopergegevens bewaard.';
  },true);

  let unsavedFields=false;
  form.addEventListener('input', event => {
    if (event.target.matches('input:not([type=file]),textarea,select')) unsavedFields=true;
  });
  form.addEventListener('change', event => {
    if (event.target.matches('input:not([type=file]),textarea,select')) unsavedFields=true;
  });
  document.addEventListener('click', event => {
    const link=event.target.closest('.seller-editor-cancel,.seller-form-actions a.secondary,.seller-editor-toolbar a.lot-editor-back');
    if (!link) return;
    if ((unsavedFields || document.querySelector('.seller-pending-photo')) &&
        !confirm('Je hebt niet-opgeslagen wijzigingen. Wil je deze pagina verlaten?')) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  },true);

  grids.forEach(grid => {
    const kind = grid.dataset.sellerGallery;
    const status = note(grid);
    grid.addEventListener('change',async event => {
      const input = event.target;
      if (!(input instanceof HTMLInputElement) || !input.matches('[data-seller-new-file]') || busy) return;
      const file = input.files?.[0];
      if (!file) return;
      if (!supported.has(file.type) || file.size < 1 || file.size > 16*1024*1024) {
        input.value = '';
        status.textContent = 'Kies een JPG-, PNG- of WebP-foto van maximaal 16 MB.';
        return;
      }
      const slot = freeSlot(grid);
      if (!slot) { input.value=''; return; }
      const objectUrl = URL.createObjectURL(file);
      const tile = document.createElement('div');
      tile.className = 'lot-editor-photo seller-pending-photo';
      tile.dataset.sellerSlot = String(slot);
      const img = document.createElement('img');
      img.src = objectUrl;
      img.alt = 'Nog niet opgeslagen: ' + file.name;
      const remove = document.createElement('button');
      remove.type='button';
      remove.className='lot-editor-remove-photo';
      remove.textContent='Foto verwijderen';
      tile.append(img,remove);
      grid.insertBefore(tile,grid.querySelector('[data-seller-picker]'));
      const discard = () => {
        URL.revokeObjectURL(objectUrl);
        tile.remove();
        failure=Boolean(document.querySelector('.seller-photo-upload-error'));
        update(grid);
      };
      if (!sellerId) {
        const freshInput=input.cloneNode(true);
        freshInput.value='';
        input.parentNode.append(freshInput);
        input.name='photo_' + kind + '_' + slot;
        input.setAttribute('form', 'seller-manager-form');
        input.hidden=true;
        input.style.display='none';
        tile.append(input);
        remove.addEventListener('click',discard);
        status.textContent='Voorbeeld geselecteerd — nog niet opgeslagen.';
        update(grid);
        return;
      }
      input.value='';
      busy=true;
      remove.disabled=true;
      status.textContent='Foto wordt opgeslagen…';
      update(grid);
      try {
        const result=await send('upload-photo',kind,slot,file);
        if (typeof result.image_url !== 'string' || !result.image_url.startsWith('/'))
          throw new Error('Geen bruikbare serverbevestiging voor de foto.');
        tile.classList.replace('seller-pending-photo','seller-saved-photo');
        img.src=result.image_url;
        img.alt=kind==='portrait'?'Portret van kunstenaar':'Foto van werk';
        URL.revokeObjectURL(objectUrl);
        remove.classList.add('seller-remove-saved');
        remove.dataset.slot=String(slot);
        remove.dataset.kind=kind;
        status.textContent='Foto opgeslagen.';
      } catch (error) {
        failure=true;
        queuedSave=false;
        tile.classList.add('seller-photo-upload-error');
        remove.textContent='Voorbeeld verwijderen';
        remove.addEventListener('click',discard);
        status.textContent='Upload niet bevestigd: '+error.message+' Controleer de serverstatus voordat je opnieuw probeert.';
      } finally {
        busy=false;
        remove.disabled=false;
        update(grid);
        if (queuedSave && !failure) { queuedSave=false; form.requestSubmit(); }
      }
    });
    grid.addEventListener('click',async event => {
      const button=event.target.closest('.seller-remove-saved');
      if (!button || busy || !sellerId) return;
      if (!confirm('Deze foto verwijderen?')) return;
      const tile=button.closest('.seller-saved-photo');
      if (!tile) return;
      busy=true;
      button.disabled=true;
      status.textContent='Foto wordt verwijderd…';
      update(grid);
      try {
        await send('delete-photo',kind,+button.dataset.slot);
        tile.remove();
        status.textContent='Foto verwijderd.';
      } catch (error) {
        status.textContent='Verwijderen niet bevestigd: '+error.message+' Controleer eerst de serverstatus.';
      } finally {
        busy=false;
        button.disabled=false;
        update(grid);
      }
    });
    update(grid);
  });
})();
