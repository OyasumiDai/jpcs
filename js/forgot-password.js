/* Forgot password: code entry, resend countdown, password strength and match hints. */
(() => {
  'use strict';
  const eye = '<svg viewBox="0 0 24 24" focusable="false"><path d="M3 12s3.2-5 9-5 9 5 9 5-3.2 5-9 5-9-5-9-5Z"></path><circle cx="12" cy="12" r="2.2"></circle></svg>';
  const eyeOff = '<svg viewBox="0 0 24 24" focusable="false"><path d="M3 12s3.2-5 9-5 9 5 9 5-3.2 5-9 5-9-5-9-5Z"></path><circle cx="12" cy="12" r="2.2"></circle><path d="M4 4l16 16"></path></svg>';
  document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.passwordToggle);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    button.title = show ? 'Hide password' : 'Show password';
    button.innerHTML = show ? eyeOff : eye;
  }));

  // Prevent double submits while the email is being sent.
  document.querySelectorAll('[data-fp-form]').forEach(form => form.addEventListener('submit', () => {
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    setTimeout(() => { button.disabled = true; button.dataset.label = button.textContent; button.textContent = 'Please wait…'; }, 0);
  }));

  // Code: digits only; submit automatically once all 6 are in (typed or pasted).
  const code = document.querySelector('[data-fp-code]');
  if (code) {
    code.addEventListener('input', () => {
      code.value = code.value.replace(/\D/g, '').slice(0, 6);
      code.classList.toggle('is-full', code.value.length === 6);
      if (code.value.length === 6) code.form.requestSubmit();
    });
  }

  const format = seconds => seconds >= 60 ? `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}` : `${seconds}s`;
  const resend = document.querySelector('[data-fp-resend]');
  if (resend) {
    let wait = Number(resend.dataset.wait) || 0;
    const label = resend.textContent;
    const tick = () => {
      resend.disabled = wait > 0;
      resend.textContent = wait > 0 ? `send a new code in ${format(wait)}` : label;
      if (wait-- > 0) setTimeout(tick, 1000);
    };
    tick();
  }

  const expiry = document.querySelector('[data-fp-expiry]');
  if (expiry) {
    let left = Number(expiry.dataset.fpExpiry) || 0;
    const value = expiry.querySelector('b');
    const tick = () => {
      if (left <= 0) {
        expiry.classList.add('is-expired');
        expiry.textContent = 'This code has expired. Send a new code.';
        return;
      }
      value.textContent = format(left);
      expiry.classList.toggle('is-soon', left < 120);
      left -= 1;
      setTimeout(tick, 1000);
    };
    tick();
  }

  const password = document.querySelector('[data-fp-password]');
  const confirm = document.querySelector('[data-fp-confirm]');
  if (password && confirm) {
    const bar = document.querySelector('[data-fp-bar]');
    const hint = document.querySelector('[data-fp-hint]');
    const match = document.querySelector('[data-fp-match]');
    const levels = [
      ['Too short — use at least 8 characters.', 'is-weak'],
      ['Weak — add numbers or symbols.', 'is-weak'],
      ['Fair — a little longer would help.', 'is-fair'],
      ['Good password.', 'is-good'],
      ['Strong password.', 'is-strong'],
    ];
    const update = () => {
      const value = password.value;
      let score = 0;
      if (value.length >= 8) {
        score = 1 + [/[a-z]/.test(value) && /[A-Z]/.test(value), /\d/.test(value), /[^A-Za-z0-9]/.test(value), value.length >= 12].filter(Boolean).length;
        score = Math.min(4, score);
      }
      bar.style.width = value ? `${Math.max(12, score * 25)}%` : '0';
      bar.className = levels[score][1];
      hint.textContent = value ? levels[score][0] : 'Use 8 or more characters. Mixing letters, numbers and symbols makes it stronger.';
      const same = confirm.value && confirm.value === value;
      match.textContent = !confirm.value ? '' : same ? '✓ Passwords match' : 'Passwords do not match yet';
      match.className = `fp-hint ${!confirm.value ? '' : same ? 'is-ok' : 'is-bad'}`;
      confirm.setCustomValidity(confirm.value && !same ? 'The passwords do not match.' : '');
    };
    password.addEventListener('input', update);
    confirm.addEventListener('input', update);
  }
})();
