/* Account Management: search and access filter, photo preview, password tools and feature-access helpers. */
(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  // List filters.
  const search = $('[data-ac-search]');
  if (search) {
    let access = 'all';
    const cards = $$('[data-ac-card]');
    const apply = () => {
      const query = search.value.trim().toLowerCase();
      let shown = 0;
      cards.forEach(card => {
        card.hidden = (access !== 'all' && card.dataset.access !== access) || (query && !card.dataset.search.includes(query));
        if (!card.hidden) shown++;
      });
      const empty = $('[data-ac-empty]');
      if (empty) empty.hidden = shown > 0 || !cards.length;
    };
    search.addEventListener('input', apply);
    $$('[data-ac-filter]').forEach(button => button.addEventListener('click', () => {
      access = button.dataset.acFilter;
      $$('[data-ac-filter]').forEach(other => other.setAttribute('aria-pressed', String(other === button)));
      apply();
    }));
  }

  const form = $('[data-ac-form]');
  if (!form) return;

  // Photo preview.
  const photo = $('#ac_photo', form);
  const image = $('[data-ac-photo-img]', form);
  photo?.addEventListener('change', () => {
    const file = photo.files[0];
    if (!file) return;
    if (image.dataset.url) URL.revokeObjectURL(image.dataset.url);
    image.dataset.url = URL.createObjectURL(file);
    image.src = image.dataset.url;
    image.hidden = false;
    $('.ac-photo__empty', form).hidden = true;
  });

  // Password: show/hide, strength, match, generator.
  const password = $('[data-ac-password]', form);
  const confirm = $('[data-ac-confirm]', form);
  const meter = $('[data-ac-strength]', form);
  const note = $('[data-ac-password-note]', form);
  const defaultNote = note.textContent;
  $$('[data-ac-eye]', form).forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.classList.toggle('is-on', show);
    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  }));
  const score = value => {
    if (!value) return 0;
    if (value.length < 8) return 1;
    let points = 1 + (value.length >= 12 ? 1 : 0) + (value.length >= 16 ? 1 : 0);
    if ([/[a-z]/, /[A-Z]/, /\d/, /[^\w\s]/].filter(r => r.test(value)).length >= 3) points++;
    return Math.min(4, points);
  };
  const check = () => {
    meter.dataset.level = String(score(password.value));
    confirm.required = password.required || password.value !== '';
    const mismatch = confirm.value !== '' && confirm.value !== password.value;
    confirm.setCustomValidity(mismatch ? 'Passwords do not match.' : '');
    note.textContent = mismatch ? 'Passwords do not match yet.' : (password.value && password.value.length < 8 ? 'At least 8 characters.' : defaultNote);
    note.classList.toggle('is-bad', mismatch || (password.value !== '' && password.value.length < 8));
  };
  password.addEventListener('input', check);
  confirm.addEventListener('input', check);
  $('[data-ac-generate]', form).addEventListener('click', () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%*?';
    const bytes = crypto.getRandomValues(new Uint32Array(14));
    const value = [...bytes].map(n => chars[n % chars.length]).join('');
    password.value = confirm.value = value;
    $$('[data-ac-eye]', form).forEach(button => { if (!button.classList.contains('is-on')) button.click(); });
    check();
    note.textContent = 'Generated — copy it now and share it with the officer securely.';
  });
  if (new URLSearchParams(location.search).get('focus') === 'password') setTimeout(() => password.focus(), 150);

  // Feature access helpers.
  const boxes = $$('[data-perm-fieldset] input[type=checkbox]', form);
  const count = $('[data-perm-count]', form);
  const updateCount = () => { count.textContent = `${boxes.filter(b => b.checked).length} of ${boxes.length} selected`; };
  boxes.forEach(box => box.addEventListener('change', updateCount));
  $('[data-perm-all]', form).addEventListener('click', () => { boxes.forEach(b => { b.checked = true; }); updateCount(); });
  $('[data-perm-none]', form).addEventListener('click', () => { boxes.forEach(b => { b.checked = false; }); updateCount(); });
  updateCount();
})();
