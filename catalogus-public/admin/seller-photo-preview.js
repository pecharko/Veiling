/* Artist photos: mirror the new-lot form's staged previews.
 * All files remain in the seller multipart form until explicit save.
 * No network request occurs while selecting, dragging or removing previews. */
(() => {
  'use strict';
  const form = document.getElementById('seller-manager-form');
  if (!form) return;
  const types = new Set(['image/jpeg', 'image/png', 'image/webp']);
  const maxBytes = 16 * 1024 * 1024;
  const maxPixels = 48000000;

  for (const tile of form.querySelectorAll('[data-seller-photo-slot]')) {
    const input = tile.querySelector('[data-seller-photo-input]');
    const zone = tile.querySelector('[data-seller-photo-dropzone]');
    const clear = tile.querySelector('[data-seller-photo-clear]');
    const current = tile.querySelector('.seller-photo-existing');
    const status = tile.querySelector('.seller-photo-status');
    if (!input || !zone || !clear || !status) continue;
    let preview = null;
    let objectUrl = null;
    let sequence = 0;

    const resetPreview = () => {
      if (preview) preview.remove();
      preview = null;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = null;
      if (current) current.hidden = false;
      clear.hidden = true;
      tile.classList.remove('seller-photo-selected');
    };
    const reject = (message) => {
      input.value = '';
      resetPreview();
      status.textContent = message;
    };
    const show = async (file) => {
      const request = ++sequence;
      if (!file) { resetPreview(); status.textContent = ''; return; }
      if (!types.has(file.type) || file.size === 0 || file.size > maxBytes) {
        reject('Kies een JPG-, PNG- of WebP-foto van maximaal 16 MB.');
        return;
      }
      const nextUrl = URL.createObjectURL(file);
      const img = new Image();
      img.alt = 'Nog niet opgeslagen: ' + file.name;
      try {
        await new Promise((resolve, rejectLoad) => {
          img.onload = resolve;
          img.onerror = () => rejectLoad(new Error('De foto kan niet worden geopend.'));
          img.src = nextUrl;
        });
        if (request !== sequence) { URL.revokeObjectURL(nextUrl); return; }
        if (img.naturalWidth < 20 || img.naturalHeight < 20 ||
            img.naturalWidth * img.naturalHeight > maxPixels) {
          URL.revokeObjectURL(nextUrl);
          reject('De foto moet minimaal 20 × 20 pixels en maximaal 48 megapixel zijn.');
          return;
        }
        resetPreview();
        objectUrl = nextUrl;
        preview = img;
        preview.className = 'seller-photo-preview';
        tile.insertBefore(preview, zone);
        if (current) current.hidden = true;
        clear.hidden = false;
        tile.classList.add('seller-photo-selected');
        status.textContent = 'Voorbeeld geselecteerd — nog niet opgeslagen.';
      } catch (error) {
        URL.revokeObjectURL(nextUrl);
        if (request === sequence) reject('Deze afbeelding kan niet worden geopend. Kies een andere foto.');
      }
    };
    input.addEventListener('change', () => {
      void show(input.files && input.files[0]);
    });
    clear.addEventListener('click', () => {
      sequence++;
      input.value = '';
      resetPreview();
      status.textContent = current ? 'Bestaande foto blijft behouden.' : 'Selectie verwijderd.';
    });
    for (const name of ['dragenter', 'dragover']) {
      zone.addEventListener(name, event => {
        event.preventDefault();
        if (!input.disabled) zone.classList.add('is-dragover');
      });
    }
    for (const name of ['dragleave', 'drop']) {
      zone.addEventListener(name, event => {
        event.preventDefault();
        zone.classList.remove('is-dragover');
      });
    }
    zone.addEventListener('drop', event => {
      if (input.disabled) return;
      const file = event.dataTransfer && event.dataTransfer.files[0];
      if (!file) return;
      try {
        const list = new DataTransfer();
        list.items.add(file);
        input.files = list.files;
      } catch (_) {
        status.textContent = 'Slepen wordt niet ondersteund. Klik om de foto te selecteren.';
        return;
      }
      void show(file);
    });
  }
})();
