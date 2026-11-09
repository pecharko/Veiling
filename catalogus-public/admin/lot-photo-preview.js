/* Automatic single-photo upload: display server-confirmed state without reloading the lot form. */
(() => {
  'use strict';
  const grid = document.getElementById('lot-photo-grid');
  const template = document.getElementById('lot-photo-picker-template');
  const form = document.getElementById('lot-photo-upload-form');
  const status = document.getElementById('lot-photo-upload-status');
  if (!grid || !template || !form || !status) return;

  const maximum = Number(grid.dataset.maxPhotos);
  const supported = new Set(['image/jpeg', 'image/png', 'image/webp']);
  let busy = false;
  const lotForm = document.getElementById('lot-editor-form');
  const saveButtons = [...document.querySelectorAll('button[form="lot-editor-form"], #lot-editor-form button[type="submit"]')];
  const setUploadBusy = (isBusy) => {
    busy = isBusy;
    saveButtons.forEach(button => { button.disabled = isBusy; });
  };
  if (lotForm) {
    lotForm.addEventListener('submit', event => {
      if (busy) {
        event.preventDefault();
        status.textContent = 'De foto wordt nog opgeslagen. Sla de kavelgegevens op zodra de upload is afgerond.';
      }
    });
  }
  const picker = () => grid.querySelector('.lot-editor-photo-add:not(.is-selected)');
  const sync = () => {
    const saved = Number(grid.dataset.existingCount);
    const pending = grid.querySelectorAll('.lot-editor-photo-add.is-selected').length;
    if (saved + pending < maximum && !picker()) {
      grid.appendChild(template.content.firstElementChild.cloneNode(true));
    }
    if (saved + pending >= maximum && picker()) picker().remove();
    grid.querySelectorAll('.lot-editor-photo-add:not(.is-selected) input[type=file]').forEach(input => {
      input.disabled = busy;
    });
  };
  const hidden = (name, value) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    return input;
  };

  grid.addEventListener('change', async (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || busy) return;
    const tile = input.closest('.lot-editor-photo-add');
    if (!tile || tile.classList.contains('is-selected')) return;
    const file = input.files && input.files[0];
    if (!file) return;
    if (!supported.has(file.type) || file.size === 0 || file.size > 16 * 1024 * 1024) {
      input.value = '';
      status.textContent = 'Kies een JPG-, PNG- of WebP-foto van maximaal 16 MB.';
      return;
    }
    if (Number(grid.dataset.existingCount) >= maximum) {
      input.value = '';
      status.textContent = 'Maximaal 3 foto’s per kavel.';
      return;
    }

    setUploadBusy(true);
    const objectUrl = URL.createObjectURL(file);
    const img = document.createElement('img');
    img.src = objectUrl;
    img.alt = 'Foto wordt toegevoegd: ' + file.name;
    tile.classList.add('is-selected');
    tile.querySelector('.lot-editor-photo-add-picker').hidden = true;
    tile.prepend(img);
    status.textContent = 'Foto wordt opgeslagen…';
    sync();

    try {
      // Explicitly append the chosen file: a picker outside the form must not
      // depend on browser-specific form-owner association behavior.
      const payload = new FormData(form);
      payload.delete('photos[]');
      payload.append('photos[]', file, file.name);
      const response = await fetch(form.action || location.href, {
        method: 'POST',
        body: payload,
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      const result = await response.json();
      if (!response.ok || result.ok !== true || !Number.isInteger(result.photo_id) ||
          typeof result.image_url !== 'string' || !result.image_url.startsWith('/')) {
        throw new Error(result.error || 'Upload niet bevestigd door de server.');
      }
      const savedForm = document.createElement('form');
      savedForm.method = 'post';
      savedForm.append(hidden('csrf', String(new FormData(form).get('csrf') || '')));
      savedForm.append(hidden('id', String(new FormData(form).get('id') || '')));
      savedForm.append(hidden('action', 'remove-photo'));
      savedForm.append(hidden('photo_id', String(result.photo_id)));
      const remove = document.createElement('button');
      remove.type = 'submit';
      remove.className = 'lot-editor-remove-photo';
      remove.textContent = 'Foto verwijderen';
      savedForm.addEventListener('submit', event => {
        if (!confirm('Deze foto verwijderen?')) event.preventDefault();
      });
      savedForm.append(remove);
      img.src = result.image_url;
      URL.revokeObjectURL(objectUrl);
      img.alt = 'Kavelfoto';
      tile.querySelector('.lot-editor-photo-add-picker').remove();
      tile.classList.remove('lot-editor-photo-add', 'is-selected');
      tile.append(savedForm);
      grid.dataset.existingCount = String(result.count);
      status.textContent = 'Foto opgeslagen.';
    } catch (error) {
      // Retain the preview on failure, instead of making it disappear briefly.
      // Do not automatically retry an ambiguous network response.
      tile.classList.add('lot-editor-photo-error');
      const dismiss = document.createElement('button');
      dismiss.type = 'button';
      dismiss.className = 'lot-editor-remove-photo';
      dismiss.textContent = 'Voorbeeld verwijderen';
      dismiss.addEventListener('click', () => {
        URL.revokeObjectURL(objectUrl);
        tile.remove();
        sync();
      });
      tile.append(dismiss);
      status.textContent = 'Upload niet bevestigd: ' + error.message + ' Controleer de melding en vernieuw zo nodig de pagina.';
    } finally {
      setUploadBusy(false);
      sync();
    }
  });
  sync();
})();
