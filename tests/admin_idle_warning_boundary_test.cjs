'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('catalogus-public/admin/session-idle.js', 'utf8');
const now = 1800000000000;
function scenario(secondsRemaining) {
  const text = {textContent: ''};
  const button = {hidden: false, addEventListener() {}};
  const login = {hidden: true};
  const banner = {
    hidden: true,
    dataset: {expires: String(now / 1000 + secondsRemaining), renewUrl: '/admin/sessie-verlengen.php', csrf: 'synthetic'},
    querySelector() { return text; },
  };
  const els = {'admin-session-warning': banner, 'admin-session-renew': button, 'admin-session-login': login};
  const timers = [];
  const document = {getElementById(id) {return els[id];}, addEventListener() {}};
  const window = {setTimeout(fn, delay) {timers.push({fn, delay}); return timers.length;}, clearTimeout() {}};
  let requested = 0;
  vm.runInNewContext(source, {document, window, Date: {now: () => now}, URLSearchParams, fetch() {requested++;}});
  return {banner, text, button, login, timers, requested};
}
const outside = scenario(601);
assert.equal(outside.banner.hidden, true, 'hidden 10m+1s before expiry');
assert.ok(outside.timers[0].delay <= 1000, 'checks again at threshold');
const boundary = scenario(600);
assert.equal(boundary.banner.hidden, false, 'warning visible exactly 10m before expiry');
assert.equal(boundary.button.hidden, false, 'renewal available at warning');
assert.equal(boundary.login.hidden, true, 'login link hidden before expiry');
assert.match(boundary.text.textContent, /verloopt binnenkort/);
const near = scenario(1);
assert.equal(near.banner.hidden, false, 'warning visible before expiry');
assert.ok(near.timers[0].delay <= 1000, 'recheck at expiry');
const expired = scenario(0);
assert.equal(expired.banner.hidden, false, 'expiry notice visible at zero');
assert.equal(expired.button.hidden, true, 'renewal hidden after expiry');
assert.equal(expired.login.hidden, false, 'login shown after expiry');
assert.match(expired.text.textContent, /verlopen/);
for (const result of [outside,boundary,near,expired]) assert.equal(result.requested,0, 'no automatic renewal');
console.log('admin_idle_warning_boundary_test: OK (4 boundary scenarios)');
