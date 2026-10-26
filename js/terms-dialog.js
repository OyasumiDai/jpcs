/* Sign-up terms: the Terms and Agreement opens before the box can be ticked or the account created.
   Agreeing needs a scroll to the end; the server still checks the box. */
(() => {
  'use strict';
  const dialog = document.getElementById('termsDialog');
  const checkbox = document.getElementById('terms');
  const form = checkbox?.form;
  if (!dialog || !checkbox || !form || typeof dialog.showModal !== 'function') return;

  const body = dialog.querySelector('[data-terms-body]');
  const agree = dialog.querySelector('[data-terms-agree]');
  const hint = dialog.querySelector('[data-terms-hint]');
  const bar = dialog.querySelector('[data-terms-progress]');
  let readToEnd = false;
  let submitAfter = false;

  // The dialog replaces the browser's "please tick this box" bubble.
  checkbox.required = false;

  const update = () => {
    const max = body.scrollHeight - body.clientHeight;
    const ratio = max <= 0 ? 1 : body.scrollTop / max;
    bar.style.width = `${Math.round(ratio * 100)}%`;
    if (!readToEnd && ratio >= 0.98) readToEnd = true;
    agree.disabled = !readToEnd;
    hint.classList.toggle('is-ready', readToEnd);
    hint.textContent = readToEnd ? 'Thanks for reading. You can now agree.' : 'Scroll to the end to agree.';
  };
  body.addEventListener('scroll', update, { passive: true });

  const open = afterAgree => {
    submitAfter = afterAgree;
    agree.textContent = afterAgree ? 'I agree & create account' : 'I agree';
    if (!dialog.open) dialog.showModal();
    update();
    (readToEnd ? agree : body).focus();
  };

  checkbox.addEventListener('click', event => {
    if (checkbox.checked) { event.preventDefault(); open(false); }
  });
  document.querySelectorAll('[data-terms-open]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    open(false);
  }));
  form.addEventListener('submit', event => {
    if (checkbox.checked) return;
    event.preventDefault();
    open(true);
  });

  agree.addEventListener('click', () => {
    checkbox.checked = true;
    dialog.close();
    if (submitAfter) form.requestSubmit();
    else checkbox.focus();
  });
  dialog.querySelectorAll('[data-terms-close]').forEach(button => button.addEventListener('click', () => {
    dialog.close();
    checkbox.focus();
  }));
})();
