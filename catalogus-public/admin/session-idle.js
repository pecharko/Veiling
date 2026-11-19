/* Issue #23: visible inactivity warning; only an explicit POST can renew.
 * Never autosave or store unsaved admin form contents in browser storage. */
(() => {
  'use strict';
  const banner = document.getElementById('admin-session-warning');
  const renew = document.getElementById('admin-session-renew');
  const login = document.getElementById('admin-session-login');
  if (!banner || !renew || !login) return;
  const text = banner.querySelector('.admin-session-warning-text');
  const endpoint = banner.dataset.renewUrl;
  const csrf = banner.dataset.csrf;
  let expiryMs = Number(banner.dataset.expires) * 1000;
  let task = null;
  if (!endpoint || !csrf || !Number.isFinite(expiryMs)) return;

  function schedule() {
    if (task !== null) window.clearTimeout(task);
    const remaining = expiryMs - Date.now();
    if (remaining <= 0) {
      banner.hidden = false;
      text.textContent = 'Je sessie is verlopen. Kopieer eventuele niet-opgeslagen wijzigingen voordat je opnieuw inlogt.';
      renew.hidden = true;
      login.hidden = false;
      return;
    }
    if (remaining <= 10 * 60 * 1000) {
      banner.hidden = false;
      text.textContent = 'Je sessie verloopt binnenkort. Sla eventuele wijzigingen op of verleng je sessie.';
      renew.hidden = false;
      login.hidden = true;
    } else {
      banner.hidden = true;
    }
    // Check again at the warning threshold or at expiry, even after sleep.
    task = window.setTimeout(schedule, Math.min(60000, Math.max(1000, remaining <= 10 * 60 * 1000 ? remaining : remaining - 10 * 60 * 1000)));
  }
  renew.addEventListener('click', async () => {
    renew.disabled = true;
    text.textContent = 'Sessie verlengen…';
    try {
      const body = new URLSearchParams({csrf});
      const response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString(),
      });
      if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) throw new Error('renewal failed');
      const payload = await response.json();
      if (payload.ok !== true || !Number.isSafeInteger(payload.expires_at) || payload.expires_at * 1000 <= Date.now()) throw new Error('invalid expiry');
      expiryMs = payload.expires_at * 1000;
      schedule();
    } catch (_) {
      banner.hidden = false;
      text.textContent = 'Sessie kon niet worden verlengd. Kopieer niet-opgeslagen wijzigingen voordat je opnieuw inlogt.';
      login.hidden = false;
    } finally {
      renew.disabled = false;
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') schedule();
  });
  schedule();
})();
