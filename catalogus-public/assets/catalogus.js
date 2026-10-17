'use strict';
(() => {
  const KEY = 'veiling_catalogus_favorieten_v4';
  const getSaved = () => { try { const x = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(x) ? x.filter(n => Number.isInteger(n) && n > 0).slice(0, 300) : []; } catch { return []; } };
  let saved = getSaved();
  const base = document.querySelector('#favoriteLink')?.getAttribute('href')?.split('?')[0] || 'index.php';
  function paint() {
    document.querySelectorAll('[data-favorite]').forEach(btn => {
      const n = Number(btn.dataset.favorite);
      const active = saved.includes(n);
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', String(active));
      btn.textContent = btn.id === 'detailFavorite' ? (active ? '♥ Opgeslagen als favoriet' : '♡ Bewaar als favoriet') : (active ? '♥' : '♡');
      btn.setAttribute('aria-label', active ? `Verwijder kavel ${n} uit favorieten` : `Bewaar kavel ${n}`);
    });
    const n = document.querySelector('#favoriteCount'); if (n) n.textContent = String(saved.length);
    const link = `${base}?favorieten=1&nummers=${encodeURIComponent(saved.join(','))}#catalogus`;
    for (const id of ['favoriteLink', 'favoritesOnly']) {
      const el = document.getElementById(id); if (el) el.href = link;
    }
  }
  document.querySelectorAll('[data-favorite]').forEach(btn => btn.addEventListener('click', () => {
    const n = Number(btn.dataset.favorite);
    saved = saved.includes(n) ? saved.filter(x => x !== n) : [...saved, n];
    try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch { /* privévenster */ }
    paint();
    if (document.body.dataset.favorites === '1') location.assign(`${base}?favorieten=1&nummers=${encodeURIComponent(saved.join(','))}#catalogus`);
  }));
  document.querySelectorAll('.gallery-thumb').forEach(btn => btn.addEventListener('click', () => {
    const img = document.querySelector('#mainPhoto'); if (img) img.src = btn.dataset.photo;
    document.querySelectorAll('.gallery-thumb').forEach(el => el.setAttribute('aria-pressed', String(el === btn)));
  }));
  // Native delen waar mogelijk; robuuste kopieerfunctie op desktop en bij beperkte HTTPS-context.
  const shareButton = document.getElementById('shareLot');
  if (shareButton) shareButton.addEventListener('click', async () => {
    const shareUrl = new URL(window.location.href);
    shareUrl.hash = ''; // Zorg voor een schone deelbare link.
    const link = shareUrl.toString();
    const title = document.title;
    if (typeof navigator.share === 'function') {
      try {
        await navigator.share({ title, url: link });
        return;
      } catch (err) {
        if (err && err.name === 'AbortError') return; // De gebruiker annuleerde zelf.
        // Onbeschikbare rechten / browserfouten: probeer klembord.
      }
    }
    let copied = false;
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      try {
        await navigator.clipboard.writeText(link);
        copied = true;
      } catch (_) { /* Geen permissie of geen veilige context. */ }
    }
    if (!copied) {
      const field = document.createElement('textarea');
      field.value = link;
      field.setAttribute('readonly', '');
      field.style.position = 'fixed';
      field.style.left = '-9999px';
      document.body.appendChild(field);
      field.select();
      try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
      field.remove();
    }
    if (copied) {
      const oldText = shareButton.textContent;
      shareButton.textContent = '✓ Link gekopieerd';
      window.setTimeout(() => { shareButton.textContent = oldText; }, 2500);
    } else {
      window.prompt('Kopieer de link naar dit kavel:', link);
    }
  });
  paint();
})();
