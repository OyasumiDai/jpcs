/* Student masterlist: drop zone and live preview checks. The server re-validates every row on save. */
(() => {
  'use strict';
  const drop = document.querySelector('[data-ml-drop]');
  if (drop) {
    const input = drop.querySelector('input[type=file]');
    const nameOut = drop.querySelector('[data-ml-file-name]');
    const show = () => { nameOut.textContent = input.files[0] ? `Selected: ${input.files[0].name}` : ''; };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => { event.preventDefault(); drop.classList.add('is-dragging'); }));
    ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, () => drop.classList.remove('is-dragging')));
    drop.addEventListener('drop', event => {
      event.preventDefault();
      if (event.dataTransfer.files.length) { input.files = event.dataTransfer.files; show(); }
    });
  }

  // Section letters follow the chosen year level, limited to the sections this user manages.
  document.querySelectorAll('[data-ml-section-fields]').forEach(box => {
    const choices = JSON.parse(box.dataset.mlChoices || '{}');
    const course = box.querySelector('[name=course]');
    const year = box.querySelector('[data-ml-year]');
    const letter = box.querySelector('[data-ml-letter]');
    const sync = () => {
      const allowed = ((choices[course ? course.value : Object.keys(choices)[0]] || {})[year.value]) || [];
      [...letter.options].forEach(option => {
        if (!option.value) { option.textContent = year.value ? 'Choose section' : 'Choose a year first'; return; }
        option.disabled = option.hidden = !allowed.includes(option.value);
      });
      if (letter.selectedOptions[0] && letter.selectedOptions[0].disabled) letter.value = '';
    };
    year.addEventListener('change', sync);
    course?.addEventListener('change', sync);
    sync();
  });

  // Collapse / expand every section group on the Masterlist tab.
  const toggleAll = document.querySelector('[data-ml-toggle-all]');
  if (toggleAll) {
    const groups = [...document.querySelectorAll('.ml-group')];
    const label = () => { toggleAll.textContent = groups.every(group => group.open) ? 'Collapse all' : 'Expand all'; };
    toggleAll.addEventListener('click', () => { const open = !groups.every(group => group.open); groups.forEach(group => { group.open = open; }); label(); });
    groups.forEach(group => group.addEventListener('toggle', label));
  }

  const form = document.querySelector('[data-ml-preview-form]');
  if (!form) return;
  const rows = [...form.querySelectorAll('[data-ml-row]')];
  const labels = { new: 'New', update: 'Will update', duplicate: 'Duplicate ID', 'invalid-id': 'Invalid ID', 'missing-name': 'Missing name', locked: 'Other section' };
  const normalizeId = value => value.replace(/[\s-]+/g, '');
  const validName = value => { const v = value.trim(); return v.length >= 3 && /\p{L}.*\p{L}/u.test(v) && !/\d/.test(v); };
  const parts = row => { const [keep, id, name] = row.querySelectorAll('input'); return { keep, id, name }; };

  function check() {
    const firstLine = new Map();
    const counts = { new: 0, update: 0, duplicate: 0, 'invalid-id': 0, 'missing-name': 0, locked: 0 };
    rows.forEach(row => {
      const { keep, id, name } = parts(row);
      let status = row.dataset.locked ? 'locked' : null;
      let note = '';
      const value = normalizeId(id.value);
      // Rows the user removed do not count as duplicates; rows left out automatically still do.
      const considered = keep.checked || Boolean(row.dataset.autoOff);
      if (!status) {
        if (!/^\d{7,12}$/.test(value)) { status = 'invalid-id'; note = value ? 'ID must be 7–12 digits (dashes allowed).' : 'Missing Student ID No.'; }
        else if (!validName(name.value)) { status = 'missing-name'; note = name.value.trim() ? 'Name looks invalid.' : 'Missing student name.'; }
        else if (considered && firstLine.has(value)) { status = 'duplicate'; note = `Same ID as line ${firstLine.get(value)} in this file.`; }
        else status = row.dataset.existing === value ? 'update' : 'new';
        if (considered && /^\d{7,12}$/.test(value) && !firstLine.has(value)) firstLine.set(value, row.cells[1].textContent.trim());
      }
      const problem = !['new', 'update'].includes(status);
      // Problem rows are left out until fixed; a row unticked by the user stays unticked.
      if (problem && keep.checked) { keep.checked = false; row.dataset.autoOff = '1'; }
      if (!problem && row.dataset.autoOff) { keep.checked = true; delete row.dataset.autoOff; }
      if (status !== row.dataset.status) {
        row.dataset.status = status;
        const badge = row.querySelector('[data-ml-status]');
        badge.className = `ml-status is-${status}`;
        badge.textContent = labels[status];
        if (status !== 'update' && status !== 'locked') row.querySelector('[data-ml-note]').textContent = note;
      }
      row.classList.toggle('is-excluded', !keep.checked);
      counts[status]++;
    });
    form.closest('section').querySelectorAll('[data-count]').forEach(chip => { chip.querySelector('b').textContent = counts[chip.dataset.count]; });
    const saving = rows.filter(row => parts(row).keep.checked && ['new', 'update'].includes(row.dataset.status)).length;
    form.querySelector('[data-ml-summary]').textContent = `${saving} of ${rows.length} rows will be saved.`;
    form.querySelector('button[type=submit]:not([form])').disabled = saving === 0;
  }

  form.addEventListener('input', check);
  form.addEventListener('change', event => {
    const row = event.target.closest('[data-ml-row]');
    if (row && event.target.type === 'checkbox') delete row.dataset.autoOff;
    check();
  });
  document.querySelector('[data-ml-problems]')?.addEventListener('change', event => {
    rows.forEach(row => { row.hidden = event.target.checked && ['new', 'update'].includes(row.dataset.status); });
  });
  // Pack all rows into one field so long lists stay within PHP's max_input_vars.
  form.addEventListener('submit', event => {
    if (event.submitter && event.submitter.getAttribute('form')) return;
    const data = rows.map(row => { const { keep, id, name } = parts(row); return { id: id.value, name: name.value, include: keep.checked }; });
    form.elements.rows_json.value = JSON.stringify(data);
    rows.forEach(row => row.querySelectorAll('input').forEach(input => { input.disabled = true; }));
  });
  check();
})();
