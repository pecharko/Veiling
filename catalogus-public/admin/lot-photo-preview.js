/* Preview selected lot photos immediately; the real upload occurs on submit. */
(() => {
  'use strict';
  const grid = document.getElementById('lot-photo-grid');
  const template = document.getElementById('lot-photo-picker-template');
  const submit = document.getElementById('lot-photo-upload-submit');
  const status = document.getElementById('lot-photo-upload-status');
  if (!grid || !template || !submit || !status) return;

  const existingCount = Number(grid.dataset.existingCount);
  const maximum = Number(grid.dataset.maxPhotos);
  const supported = new Set(['image/jpeg', 'image/png', 'image/webp']);
  const pending = () => [...grid.querySelectorAll('.lot-editor-photo-add.is-selected')];
  const picker = () => grid.querySelector('.lot-editor-photo-add:not(.is-selected)');
  const sync = () => {
    const count = pending().length;
    if (existingCount + count < maximum && !picker()) {
      grid.appendChild(template.content.firstElementChild.cloneNode(true));
    }
    if (existingCount + count >= maximum && picker()) {
      picker().remove();
    }
    submit.hidden = count === 0;
    status.textContent = count
      ? count + (count === 1 ? ' foto geselecteerd; nog niet opgeslagen.' : " foto's geselecteerd; nog niet opgeslagen.")
      : '';
  };

  grid.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
    const tile = input.closest('.lot-editor-photo-add');
    if (!tile || tile.classList.contains('is-selected')) return;
    const file = input.files && input.files[0];
    if (!file) return;
    if (!supported.has(file.type) || file.size === 0 || file.size > 16 * 1024 * 1024) {
      input.value = '';
      status.textContent = 'Kies een JPG-, PNG- of WebP-foto van maximaal 16 MB.';
      return;
    }
    if (existingCount + pending().length >= maximum) {
      input.value = '';
      status.textContent = 'Maximaal 3 foto’s per kavel.';
      return;
    }
    const objectUrl = URL.createObjectURL(file);
    const image = document.createElement('img');
    image.src = objectUrl;
    image.alt = 'Geselecteerde foto: ' + file.name;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'lot-editor-remove-photo';
    remove.textContent = 'Selectie verwijderen';
    remove.addEventListener('click', () => {
      URL.revokeObjectURL(objectUrl);
      input.value = '';
      tile.remove();
      sync();
    });
    tile.classList.add('is-selected');
    tile.querySelector('.lot-editor-photo-add-picker').hidden = true;
    tile.prepend(image);
    tile.append(remove);
    sync();
  });

  document.getElementById('lot-photo-upload-form').addEventListener('submit', (event) => {
    if (pending().length === 0) event.preventDefault();
  });
  sync();
})();
