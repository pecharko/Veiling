'use strict';
(() => {
  const button = document.getElementById('artist-share');
  const status = document.getElementById('artist-share-status');
  if (!button || !status) return;
  button.hidden = false;
  button.addEventListener('click', async () => {
    const url = button.dataset.shareUrl;
    status.hidden = true;
    if (typeof navigator.share === 'function') {
      try {
        await navigator.share({ title: document.title, url });
        return;
      } catch (error) {
        if (error?.name === 'AbortError') return;
      }
    }
    try {
      await navigator.clipboard.writeText(url);
      status.hidden = false;
      status.textContent = 'Link gekopieerd.';
    } catch {
      window.prompt('Kopieer deze link om de kunstenaar te delen:', url);
    }
  });
})();
