/* My Profile: live photo preview, username verification field, password visibility, strength and match. */
(() => {
  'use strict';
  const photo = document.getElementById('photo');
  const avatar = document.querySelector('.profile-avatar');
  const image = document.getElementById('profile-photo');
  const initials = avatar?.querySelector('.profile-avatar__initials');
  const fileLabel = document.querySelector('[data-profile-file]');
  const remove = document.querySelector('[data-profile-remove]');
  const original = image?.getAttribute('src');
  let preview = '';
  const showImage = src => {
    if (src) { image.src = src; image.hidden = false; initials.hidden = true; }
    else { image.hidden = true; initials.hidden = false; }
  };
  photo?.addEventListener('change', () => {
    if (preview) URL.revokeObjectURL(preview);
    const file = photo.files[0];
    preview = file ? URL.createObjectURL(file) : '';
    fileLabel.textContent = file ? `Selected: ${file.name} (save to apply)` : 'JPEG, PNG or WebP · max 5 MB';
    if (file && remove) remove.checked = false;
    showImage(preview || (avatar.classList.contains('has-photo') ? original : ''));
    avatar.classList.toggle('is-preview', Boolean(file));
  });
  remove?.addEventListener('change', () => {
    if (remove.checked && photo.files[0]) { photo.value = ''; photo.dispatchEvent(new Event('change')); }
    showImage(remove.checked ? '' : original);
    avatar.classList.toggle('is-preview', remove.checked);
  });
  const drop = document.querySelector('[data-profile-drop]');
  if (drop && photo) {
    ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => { event.preventDefault(); drop.classList.add('is-dragging'); }));
    ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, () => drop.classList.remove('is-dragging')));
    drop.addEventListener('drop', event => { event.preventDefault(); if (event.dataTransfer.files.length) { photo.files = event.dataTransfer.files; photo.dispatchEvent(new Event('change')); } });
  }

  // Name and username update the profile header as you type.
  const nameInput = document.getElementById('name');
  const usernameInput = document.getElementById('username');
  nameInput?.addEventListener('input', () => { document.querySelector('[data-profile-name]').textContent = nameInput.value.trim() || '—'; });
  // The current password is only needed (and only shown) when the username changes.
  const verify = document.querySelector('[data-profile-verify]');
  const verifyInput = verify?.querySelector('input');
  const syncVerify = () => {
    const changed = usernameInput.value.trim() !== usernameInput.dataset.original;
    verify.toggleAttribute('data-collapsed', !changed);
    verifyInput.required = changed;
    document.querySelector('[data-profile-username]').textContent = usernameInput.value.trim() || usernameInput.dataset.original;
    // Email usernames already contain an @, so the handle prefix is only shown for plain usernames.
    document.querySelector('[data-profile-at]').hidden = document.querySelector('[data-profile-username]').textContent.includes('@');
  };
  if (usernameInput && verify) { usernameInput.addEventListener('input', syncVerify); syncVerify(); }

  // Show / hide password buttons.
  document.querySelectorAll('[data-profile-eye]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    button.classList.toggle('is-on', show);
  }));

  // Strength meter and confirmation match.
  const next = document.getElementById('new_password');
  const confirm = document.getElementById('confirm_password');
  const meter = document.querySelector('[data-profile-strength]');
  const meterText = document.querySelector('[data-profile-strength-text]');
  const match = document.querySelector('[data-profile-match]');
  const labels = ['Use 8–72 characters. Mixing letters, numbers and symbols makes it stronger.', 'Weak — add more characters or variety.', 'Fair — add numbers or symbols.', 'Good password.', 'Strong password.'];
  const score = value => {
    if (value.length < 8) return value ? 1 : 0;
    let points = 1 + (value.length >= 12 ? 1 : 0);
    points += [/[a-z]/, /[A-Z]/, /\d/, /[^\w\s]/].filter(pattern => pattern.test(value)).length >= 3 ? 1 : 0;
    points += value.length >= 16 ? 1 : 0;
    return Math.min(4, points);
  };
  const check = () => {
    const level = score(next.value);
    meter.dataset.level = String(level);
    meterText.textContent = labels[level];
    if (!confirm.value) { match.textContent = ''; match.className = 'profile-match'; confirm.setCustomValidity(''); return; }
    const same = confirm.value === next.value;
    match.textContent = same ? 'Passwords match.' : 'Passwords do not match yet.';
    match.className = 'profile-match ' + (same ? 'is-ok' : 'is-bad');
    confirm.setCustomValidity(same ? '' : 'Passwords do not match.');
  };
  next?.addEventListener('input', check);
  confirm?.addEventListener('input', check);
})();
