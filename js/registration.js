/* Event registration: live checks, progress, summary and a busy state while the server sends email.
   The server repeats every check; this only helps people fix mistakes before submitting. */
(() => {
  'use strict';
  const form = document.querySelector('[data-rg-form]');
  if (!form) return;
  const fields = ['surname', 'given_name', 'middle_initial', 'student_id', 'course_year_section', 'email', 'contact'].map(id => document.getElementById(id));
  const radios = [...form.querySelectorAll('input[name=event_id]')];
  const rules = {
    surname: v => v.trim().length > 0,
    given_name: v => v.trim().length > 0,
    middle_initial: v => v.trim() === '' || /^[A-Za-z]\.?$/.test(v.trim()),
    student_id: v => /^[0-9][0-9\s-]{5,}$/.test(v.trim()),
    course_year_section: v => v !== '',
    email: v => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()),
    contact: v => /^(\+?63|0)?9\d{9}$/.test(v.replace(/[\s()-]/g, '')) || /^[0-9+\s()-]{7,}$/.test(v.trim()),
  };
  const touched = new Set();

  const showField = (input, force = false) => {
    const field = input.closest('.rg-field');
    const ok = rules[input.id](input.value);
    const show = force || touched.has(input.id);
    field.classList.toggle('is-valid', ok && input.value.trim() !== '');
    field.classList.toggle('is-invalid', show && !ok);
    field.querySelector('.rg-error').hidden = !(show && !ok);
    input.setAttribute('aria-invalid', String(show && !ok));
    return ok;
  };
  const eventChosen = () => radios.some(radio => radio.checked);

  // Progress and summary.
  const sum = key => form.parentElement.querySelector(`[data-rg-sum="${key}"]`);
  const update = () => {
    const required = fields.filter(input => input.required);
    const done = required.filter(input => rules[input.id](input.value)).length + (eventChosen() ? 1 : 0);
    const percent = Math.round(done / (required.length + 1) * 100);
    form.querySelector('[data-rg-progress]').style.width = percent + '%';
    const percentLabel = document.querySelector('[data-rg-percent]');
    if (percentLabel) percentLabel.textContent = percent + '%';
    const chosen = radios.find(radio => radio.checked);
    const title = document.querySelector('[data-rg-sum-title]');
    if (title) {
      title.textContent = chosen ? chosen.dataset.title : 'No event selected';
      document.querySelector('[data-rg-sum-date]').textContent = chosen ? chosen.dataset.date : 'Choose an event to continue';
      const poster = document.querySelector('[data-rg-sum-poster]');
      const source = chosen?.closest('.rg-event').querySelector('img');
      poster.hidden = !source;
      if (source) poster.src = source.src;
      const mi = document.getElementById('middle_initial').value.trim().replace('.', '').toUpperCase();
      const surname = document.getElementById('surname').value.trim();
      const given = document.getElementById('given_name').value.trim();
      sum('name').textContent = surname || given ? `${surname}${surname && given ? ', ' : ''}${given}${mi ? ` ${mi}.` : ''}` : '—';
      sum('student_id').textContent = document.getElementById('student_id').value.trim() || '—';
      const section = document.getElementById('course_year_section');
      sum('course_year_section').textContent = section.value ? section.value : '—';
      sum('email').textContent = document.getElementById('email').value.trim() || '—';
    }
    form.querySelectorAll('[data-rg-step]').forEach(step => {
      const inputs = [...step.querySelectorAll('input:not([type=radio]), select')].filter(input => input.required);
      const complete = step.querySelector('input[type=radio]') ? eventChosen() : inputs.every(input => rules[input.id](input.value));
      step.classList.toggle('is-complete', complete);
    });
  };

  fields.forEach(input => {
    input.addEventListener('blur', () => { if (input.value.trim() !== '') touched.add(input.id); showField(input); });
    input.addEventListener('input', () => {
      if (input.id === 'middle_initial') input.value = input.value.toUpperCase();
      if (touched.has(input.id)) showField(input);
      update();
    });
    input.addEventListener('change', () => { touched.add(input.id); showField(input); update(); });
    if (input.value.trim() !== '') showField(input);
  });
  radios.forEach(radio => radio.addEventListener('change', () => {
    form.querySelector('[data-rg-error-for=event_id]').hidden = true;
    update();
    // On phones, move on to the details once an event is picked.
    if (matchMedia('(max-width: 760px)').matches) document.getElementById('surname').focus({ preventScroll: false });
  }));

  form.addEventListener('submit', event => {
    let firstBad = null;
    if (!eventChosen()) { form.querySelector('[data-rg-error-for=event_id]').hidden = false; firstBad = radios[0]; }
    fields.forEach(input => { touched.add(input.id); if (!showField(input, true) && !firstBad) firstBad = input; });
    if (firstBad) {
      event.preventDefault();
      firstBad.closest('fieldset').scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstBad.focus({ preventScroll: true });
      return;
    }
    if (form.dataset.busy) { event.preventDefault(); return; }
    // Keep the submit button's name/value in the request, then show the busy state.
    form.dataset.busy = '1';
    const button = form.querySelector('[data-rg-submit]');
    setTimeout(() => { button.classList.add('is-busy'); button.disabled = true; }, 0);
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    delete form.dataset.busy;
    const button = form.querySelector('[data-rg-submit]');
    button.classList.remove('is-busy');
    button.disabled = false;
  });
  update();
})();
