/* Warn only when leaving with unsaved lot-form edits. Photo uploads are independent. */
(() => {
  'use strict';
  const form = document.getElementById('lot-editor-form');
  if (!form) return;

  const snapshot = () => {
    const entries = [];
    for (const [name, value] of new FormData(form).entries()) {
      if (name === 'csrf_token' || name === 'csrf') continue;
      // An empty file picker contributes a synthetic File to FormData.
      // Its lastModified can change between snapshots, even when the user
      // hasn't selected anything. It must not count as an unsaved change.
      if (value instanceof File && value.name === '' && value.size === 0) continue;
      const normalized = value instanceof File
        ? value.name + ':' + value.size + ':' + value.lastModified
        : String(value);
      entries.push([name, normalized]);
    }
    return JSON.stringify(entries);
  };
  const original = snapshot();

  document.querySelectorAll('.lot-editor-back, .lot-editor-toolbar-actions .lot-editor-cancel, .lot-editor-bottom a[href]').forEach(link => {
    link.addEventListener('click', (event) => {
      if (snapshot() !== original &&
          !window.confirm('Je hebt wijzigingen die nog niet zijn opgeslagen. Wil je terug naar de kavels zonder deze wijzigingen te bewaren?')) {
        event.preventDefault();
      }
    });
  });
})();
