(() => {
  'use strict';
  const dialog = document.getElementById('impression-viewer');
  const links = [...document.querySelectorAll('[data-impression-photo]')];
  if (!dialog || typeof dialog.showModal !== 'function' || !links.length) return;
  const image = document.getElementById('viewer-image');
  const caption = document.getElementById('viewer-caption');
  const count = document.getElementById('viewer-count');
  const previous = document.getElementById('viewer-previous');
  const next = document.getElementById('viewer-next');
  const close = dialog.querySelector('.impression-viewer-close');
  let index = 0;
  let trigger = null;
  function show(position) {
    index = Math.max(0, Math.min(links.length - 1, position));
    const link = links[index];
    image.src = link.href;
    image.alt = link.querySelector('img').alt;
    caption.textContent = link.dataset.caption;
    count.textContent = `${index + 1} / ${links.length}`;
    previous.disabled = index === 0;
    next.disabled = index === links.length - 1;
  }
  links.forEach((link, position) => link.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    trigger = link;
    show(position);
    dialog.showModal();
    document.body.classList.add('impression-modal-open');
    close.focus();
  }));
  previous.addEventListener('click', () => show(index - 1));
  next.addEventListener('click', () => show(index + 1));
  close.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => {
    const box = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
  });
  dialog.addEventListener('keydown', event => {
    if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
    if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
  });
  dialog.addEventListener('close', () => {
    document.body.classList.remove('impression-modal-open');
    image.removeAttribute('src');
    trigger?.focus({ preventScroll: true });
  });
})();
