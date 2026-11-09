/* Show a chosen photo immediately and upload it without a second confirmation. */
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
  const pending = () => [...grid.querySelectorAll('.lot-editor-photo-add.is-selected')];
  const picker = () => grid.querySelector('.lot-editor-photo-add:not(.is-selected)');
  const sync = () => {
    const existing = Number(grid.dataset.existingCount);
    const count = pending().length;
    if (existing + count < maximum && !picker()) {
      grid.appendChild(template.content.firstElementChild.cloneNode(true));
    }
    if (existing + count >= maximum && picker()) picker().remove();
    grid.querySelectorAll('.lot-editor-photo-add:not(.is-selected) input[type=file]').forEach(input => {
      input.disabled = busy;
    });
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

    busy = true;
    const oldCount = Number(grid.dataset.existingCount);
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
      // Preserve edited lot fields: only the independent photo form is sent.
      const response = await fetch(form.action || location.href, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        cache: 'no-store',
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const updatedGrid = page.getElementById('lot-photo-grid');
      if (!updatedGrid || Number(updatedGrid.dataset.existingCount) <= oldCount) {
        throw new Error('Foto kon niet worden opgeslagen.');
      }
      grid.innerHTML = updatedGrid.innerHTML;
      grid.dataset.existingCount = updatedGrid.dataset.existingCount;
      status.textContent = 'Foto toegevoegd.';
    } catch (error) {
      // Keep the preview and original input so the user can retry or choose again.
      tile.classList.remove('is-selected');
      img.remove();
      tile.querySelector('.lot-editor-photo-add-picker').hidden = false;
      input.value = '';
      status.textContent = 'Upload mislukt. Kies de foto opnieuw. (' + error.message + ')';
    } finally {
      URL.revokeObjectURL(objectUrl);
      busy = false;
      sync();
    }
  });
  sync();
})();
