/* Event create/edit dialog: title counter, image previews with drag and drop. */
(() => {
  'use strict';
  const form = document.querySelector('[data-ef-form]');
  if (!form) return;
  const title = form.querySelector('#title');
  const counter = form.querySelector('[data-ef-count="title"]');
  const count = () => { counter.textContent = `${title.value.length}/${title.maxLength}`; counter.classList.toggle('is-near', title.value.length > title.maxLength - 10); };
  title.addEventListener('input', count);
  count();

  form.querySelectorAll('[data-preview]').forEach(input => {
    const image = document.getElementById(input.dataset.preview);
    const drop = input.closest('[data-ef-drop]');
    const note = form.querySelector(`[data-ef-file="${input.id}"]`);
    const original = { src: image.getAttribute('src'), has: drop.classList.contains('has-image'), note: note.textContent };
    input.addEventListener('change', () => {
      if (image.dataset.objectUrl) URL.revokeObjectURL(image.dataset.objectUrl);
      const file = input.files[0];
      if (file) {
        image.dataset.objectUrl = URL.createObjectURL(file);
        image.src = image.dataset.objectUrl;
        image.hidden = false;
        drop.classList.add('has-image');
        note.textContent = `${file.name} · ${(file.size / 1048576).toFixed(1)} MB${file.size > 5 * 1048576 ? ' — too large, max 5 MB' : ''}`;
        note.classList.toggle('is-error', file.size > 5 * 1048576);
      } else {
        image.src = original.src || '';
        image.hidden = !original.has;
        drop.classList.toggle('has-image', original.has);
        note.textContent = original.note;
        note.classList.remove('is-error');
      }
    });
    ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => { event.preventDefault(); drop.classList.add('is-dragging'); }));
    ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, () => drop.classList.remove('is-dragging')));
    drop.addEventListener('drop', event => {
      event.preventDefault();
      if (event.dataTransfer.files.length) { input.files = event.dataTransfer.files; input.dispatchEvent(new Event('change')); }
    });
  });
})();
