/* New lots: stage up to three photo previews until the first lot save.
 * Files remain in form-associated inputs so the normal multipart POST persists them. */
(() => {
  'use strict';
  const grid = document.getElementById('lot-new-photo-grid');
  const template = document.getElementById('lot-new-photo-picker-template');
  const status = document.getElementById('lot-new-photo-status');
  const form = document.getElementById('lot-editor-form');
  if (!grid || !template || !status || !form) return;

  const maximum = Number(grid.dataset.maxPhotos);
  const supported = new Set(['image/jpeg', 'image/png', 'image/webp']);
  const selectedTiles = () => [...grid.querySelectorAll('.lot-editor-photo-add.is-selected')];
  const emptyTile = () => grid.querySelector('.lot-editor-photo-add:not(.is-selected)');

  const sync = () => {
    const selected = selectedTiles().length;
    if (selected < maximum && !emptyTile()) {
      grid.append(template.content.firstElementChild.cloneNode(true));
    } else if (selected >= maximum && emptyTile()) {
      emptyTile().remove();
    }
    status.textContent = selected
      ? selected + ' van maximaal ' + maximum + ' foto(’s) geselecteerd. Ze worden samen met het nieuwe kavel opgeslagen.'
      : 'Foto\'s worden opgeslagen wanneer je op Kavel opslaan klikt.';
  };

  grid.addEventListener('change', event => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
    const tile = input.closest('.lot-editor-photo-add:not(.is-selected)');
    const file = input.files && input.files[0];
    if (!tile || !file) return;
    if (!supported.has(file.type) || file.size === 0 || file.size > 16 * 1024 * 1024) {
      input.value = '';
      status.textContent = 'Kies een JPG-, PNG- of WebP-foto van maximaal 16 MB.';
      return;
    }
    if (selectedTiles().length >= maximum) {
      input.value = '';
      status.textContent = 'Maximaal 3 foto’s per kavel.';
      return;
    }

    const objectUrl = URL.createObjectURL(file);
    const photo = document.createElement('img');
    photo.src = objectUrl;
    photo.alt = 'Nog niet opgeslagen: ' + file.name;
    tile.classList.add('is-selected');
    tile.querySelector('.lot-editor-photo-add-picker').hidden = true;
    tile.prepend(photo);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'lot-editor-remove-photo';
    remove.textContent = 'Foto verwijderen';
    remove.addEventListener('click', () => {
      URL.revokeObjectURL(objectUrl);
      tile.remove(); // Also removes its form-owned file input from the submitted POST.
      sync();
    });
    tile.append(remove);
    sync();
  });
  sync();
})();
