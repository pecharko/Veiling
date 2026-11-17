'use strict';
// Offline DOM/fetch simulation. Never reaches OVH or uses mailbox credentials.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const client = fs.readFileSync(path.join(__dirname, '..', 'catalogus-public', 'admin', 'mail-unread.js'), 'utf8');

async function scenario(label, response, expected) {
  const badge = {
    hidden: true, textContent: '', dataset: {}, title: '',
    removeAttribute(name) {
      if (name === 'data-state') delete this.dataset.state;
      if (name === 'title') this.title = '';
    },
  };
  const attributes = {};
  const link = {
    querySelector: () => badge,
    getAttribute: key => key === 'data-mail-unread-url' ? '/admin/mail-ongelezen.php' : null,
    setAttribute: (key, val) => { attributes[key] = val; },
  };
  let calls = 0;
  let intervalCallback;
  const document = {
    visibilityState: 'visible',
    querySelector: selector => selector === 'a[data-mail-unread-url]' ? link : null,
    addEventListener: () => {},
  };
  const window = {
    setTimeout: () => 1,
    clearTimeout: () => {},
    setInterval: fn => { intervalCallback = fn; },
  };
  const fetch = async (url, options) => {
    calls++;
    assert.equal(url, '/admin/mail-ongelezen.php');
    assert.equal(options.method, 'GET');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    return response;
  };
  vm.runInNewContext(client, { document, window, fetch, AbortController, Error, Number }, { filename: 'mail-unread.js', timeout: 1500 });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(badge.hidden, expected.hidden, label + ': badge hidden');
  assert.equal(badge.textContent, expected.text, label + ': badge text');
  if (expected.state) assert.equal(badge.dataset.state, expected.state, label + ': badge state');
  assert.equal(calls, 1, label + ': initial request count');
  intervalCallback();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls, expected.retry ? 2 : 1, label + ': retry policy');
}

(async () => {
  const ok = data => ({ ok: true, json: async () => data });
  await scenario('not configured', ok({ available: false, reason: 'not_configured' }),
    { hidden: false, text: 'Niet gekoppeld', state: 'disconnected', retry: false });
  await scenario('zero unread', ok({ available: true, unread: 0 }),
    { hidden: true, text: '', retry: true });
  await scenario('three unread', ok({ available: true, unread: 3 }),
    { hidden: false, text: '3', state: 'count', retry: true });
  await scenario('over 99 unread', ok({ available: true, unread: 120 }),
    { hidden: false, text: '99+', state: 'count', retry: true });
  await scenario('unavailable', { ok: false },
    { hidden: false, text: '?', state: 'unavailable', retry: true });
  console.log('PASS mail-unread client: disconnected, zero, count, cap, failure and retry policy');
})().catch(err => { console.error(err); process.exitCode = 1; });
