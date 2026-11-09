/* Regression for the HTMLFormElement.action named-input collision. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'catalogus-public/admin/kavel.php'), 'utf8');
const client = fs.readFileSync(path.join(root, 'catalogus-public/admin/lot-photo-preview.js'), 'utf8');

assert.match(php, /id="lot-photo-upload-form"/, 'Independent existing-lot upload form must exist');
assert.match(php, /name="action" value="add-photo"/, 'Hidden action field reproduces property masking');
const expression = client.match(/\bconst uploadUrl = ([^;\n]+);/);
assert(expression, 'Upload target must be resolved explicitly');
assert.match(client, /fetch\(uploadUrl,\s*\{/);
assert.doesNotMatch(client, /fetch\(\s*form\.action\b/, 'Never use shadowed form.action');

const editor = 'http://localhost:8080/admin/kavel.php?id=42';
function resolveUrl(attribute) {
  // In real browsers, a named control masks the native form.action property.
  const actionInput = { name: 'action', value: 'add-photo' };
  const form = {
    action: actionInput,
    getAttribute(name) {
      assert.equal(name, 'action');
      return attribute;
    },
  };
  const window = { location: { href: editor } };
  return vm.runInNewContext(expression[1], { form, window });
}
assert.equal(resolveUrl(null), editor, 'Form with no action must POST to the opened editor URL');
assert.equal(resolveUrl(''), editor, 'Empty action must POST to the opened editor URL');
assert.equal(resolveUrl('/admin/kavel.php?id=42'), '/admin/kavel.php?id=42',
  'Explicit action attribute must be respected');
console.log('PASS existing-lot photo target ignores hidden action form control');
