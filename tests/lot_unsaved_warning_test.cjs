/* Deterministic regression for false "unsaved changes" on pristine new lots. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
  path.join(__dirname, '../catalogus-public/admin/lot-unsaved-warning.js'), 'utf8');
const fields = { lot_number: '1', title: '' };
let selectedFile = null;
let nextSyntheticTimestamp = 1700000000000;
let confirms = 0;

class FakeFile {
  constructor(name, size, lastModified) {
    this.name = name;
    this.size = size;
    this.lastModified = lastModified;
  }
}
class FakeFormData {
  constructor(form) {
    assert.equal(form, lotForm);
  }
  *entries() {
    yield ['csrf', 'token-not-relevant-to-dirty-state'];
    for (const [name, value] of Object.entries(fields)) yield [name, value];
    // Browsers expose an empty file part for a picker with no selected file;
    // the File object's metadata can be newly instantiated every time.
    yield ['photos[]', selectedFile || new FakeFile('', 0, nextSyntheticTimestamp++)];
  }
}
const lotForm = {};
const links = Array.from({ length: 3 }, () => ({
  handlers: new Map(),
  addEventListener(name, callback) { this.handlers.set(name, callback); },
}));
const document = {
  getElementById(id) { return id === 'lot-editor-form' ? lotForm : null; },
  querySelectorAll() { return links; },
};
const window = { confirm() { confirms++; return false; } };
vm.runInNewContext(source, { document, window, FormData: FakeFormData, File: FakeFile });

function click(link) {
  let prevented = false;
  link.handlers.get('click')({ preventDefault() { prevented = true; } });
  return prevented;
}
assert.equal(click(links[0]), false, 'Pristine form: back link must leave without warning');
assert.equal(click(links[1]), false, 'Pristine form: top cancel must leave without warning');
assert.equal(click(links[2]), false, 'Pristine form: bottom cancel must leave without warning');
assert.equal(confirms, 0, 'No confirmation for a pristine form, even with a synthetic file');

fields.title = 'New title';
assert.equal(click(links[1]), true, 'Actual title change must warn on cancel');
assert.equal(confirms, 1);
fields.title = '';
assert.equal(click(links[1]), false, 'Reverted edit must not warn');

selectedFile = new FakeFile('photo.jpg', 456, 1700000010000);
assert.equal(click(links[1]), true, 'Selected unsaved photo must warn');
assert.equal(confirms, 2);
selectedFile = null;
assert.equal(click(links[1]), false, 'Removing unsaved photo restores pristine state');
assert.equal(confirms, 2);
console.log('PASS: pristine cancel silent; real text/photo changes warn');
