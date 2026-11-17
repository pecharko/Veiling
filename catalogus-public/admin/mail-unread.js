/* Read-only badge. No OVH credentials or message metadata are ever sent to the browser. */
(() => {
  'use strict';
  const link = document.querySelector('a[data-mail-unread-url]');
  const badge = link?.querySelector('.admin-mail-badge');
  if (!link || !badge) return;
  const endpoint = link.getAttribute('data-mail-unread-url');
  if (!endpoint) return;

  const baseLabel = 'Mail openen via OVH (nieuw tabblad)';
  let inFlight = false;
  let notConfigured = false;
  function show(text, state, description) {
    badge.textContent = text;
    badge.dataset.state = state;
    badge.hidden = false;
    badge.title = description;
    link.setAttribute('aria-label', baseLabel + ', ' + description);
  }
  function clear() {
    badge.hidden = true;
    badge.textContent = '';
    badge.removeAttribute('data-state');
    badge.removeAttribute('title');
    link.setAttribute('aria-label', baseLabel);
  }

  async function update() {
    if (inFlight || notConfigured || document.visibilityState === 'hidden') return;
    inFlight = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 6000);
    try {
      const response = await fetch(endpoint, {
        method: 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Accept': 'application/json' }, signal: controller.signal
      });
      // Only the authenticated endpoint can return this non-sensitive state.
      if (response.ok) {
        const data = await response.json();
        if (data?.available === false && data.reason === 'not_configured') {
          notConfigured = true; // A new page load can retry after separate setup.
          show('Niet gekoppeld', 'disconnected', 'ongelezen teller nog niet gekoppeld');
          return;
        }
        if (data?.available === true && Number.isSafeInteger(data.unread) && data.unread >= 0) {
          clear();
          if (data.unread > 0) {
            show(data.unread > 99 ? '99+' : String(data.unread), 'count',
              data.unread + ' ongelezen berichten');
          }
          return;
        }
      }
      throw new Error('Unread status not available');
    } catch (_) {
      // Unknown never means zero; do not show a stale count.
      show('?', 'unavailable', 'ongelezen teller tijdelijk niet beschikbaar');
    } finally {
      window.clearTimeout(timeout);
      inFlight = false;
    }
  }

  void update();
  window.setInterval(() => { void update(); }, 120000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') void update();
  });
})();
