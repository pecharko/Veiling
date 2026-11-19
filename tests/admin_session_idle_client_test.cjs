'use strict';
// Offline DOM simulation; does not contact the website or start a browser.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('catalogus-public/admin/session-idle.js', 'utf8');

const now = 1800000000000;
let currentTime = now;
const events = new Map();
const text = {textContent: ''};
const renew = {hidden: false, disabled: false, addEventListener(name, fn) {events.set(name, fn);}};
const login = {hidden: true};
const banner = {
  hidden: true,
  dataset: {expires: String((now + 540000) / 1000), renewUrl: '/admin/sessie-verlengen.php', csrf: 'test-csrf'},
  querySelector() {return text;}
};
const nodes = {'admin-session-warning': banner, 'admin-session-renew': renew, 'admin-session-login': login};
const document = {getElementById(id) {return nodes[id];}, addEventListener() {}, visibilityState: 'visible'};
let queuedTimer = null;
const window = {
  setTimeout(fn, delay) {queuedTimer = {fn, delay}; return 42;},
  clearTimeout() {queuedTimer = null;}
};
let fetchCount = 0;
const fetch = async (url, options) => {
  fetchCount++;
  assert.equal(url, '/admin/sessie-verlengen.php');
  assert.equal(options.method, 'POST');
  assert.equal(options.credentials, 'same-origin');
  assert.equal(new URLSearchParams(options.body).get('csrf'), 'test-csrf');
  return {ok: true, headers: {get() {return 'application/json';}}, async json() {return {ok: true, expires_at: (now + 3600000) / 1000};}};
};
vm.runInNewContext(source, {window, document, fetch, URLSearchParams, Date: {now: () => currentTime}});
async function run() {
  assert.equal(banner.hidden, false, 'warning is visible with nine minutes left');
  assert.equal(login.hidden, true);
  assert.ok(queuedTimer && queuedTimer.delay > 0);
  await events.get('click')();
  assert.equal(fetchCount, 1, 'renewal is explicit');
  assert.equal(banner.hidden, true, 'warning disappears after renewal');
  assert.equal(renew.disabled, false);
  currentTime = now + 3610000;
  queuedTimer.fn();
  assert.equal(banner.hidden, false, 'expiry warning displays when time elapsed');
  assert.equal(renew.hidden, true);
  assert.equal(login.hidden, false);
  assert.equal(fetchCount, 1, 'expiry does not silently extend session');
  console.log('admin_session_idle_client_test: OK');
}
run().catch(e => {console.error(e); process.exitCode = 1;});
