/* Read-only badge; nothing about the OVH session or mailbox contents enters the browser. */
(() => {
  'use strict';
  const link = document.querySelector('a[data-mail-unread-url]');
  const badge = link?.querySelector('.admin-mail-badge');
  if (!link || !badge) return;
  const endpoint = link.getAttribute('data-mail-unread-url');
  if (!endpoint) return;
  let inFlight = false;
  const baseLabel = 'Mail openen via OVH (nieuw tabblad)';
  async function update() {
    if (inFlight || document.visibilityState === 'hidden') return;
    inFlight = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 6000);
    try {
      const response = await fetch(endpoint, {
        method: 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Accept': 'application/json' }, signal: controller.signal
      });
      if (!response.ok) throw new Error('Mail counter unavailable');
      const data = await response.json();
      if (data?.available !== true || !Number.isSafeInteger(data.unread) || data.unread < 0) {
        throw new Error('Mail counter unavailable');
      }
      const count = data.unread;
      badge.hidden = count === 0;
      badge.textContent = count > 99 ? '99+' : String(count);
      link.setAttribute('aria-label', count
        ? baseLabel + ', ' + count + ' ongelezen berichten'
        : baseLabel);
    } catch (_) {
      // Unknown is not zero: never display a stale count after an IMAP failure.
      badge.hidden = true;
      badge.textContent = '';
      link.setAttribute('aria-label', baseLabel);
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
