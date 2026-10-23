/* Registrations & Attendance: event search, tabs without reloads, list filters, CSV export and print. */
(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  // Event picker.
  const eventSearch = $('[data-rx-event-search]');
  if (eventSearch) {
    let status = 'all';
    const cards = $$('[data-rx-event]');
    const apply = () => {
      const query = eventSearch.value.trim().toLowerCase();
      let shown = 0;
      cards.forEach(card => {
        card.hidden = (status !== 'all' && card.dataset.status !== status) || (query && !card.dataset.search.includes(query));
        if (!card.hidden) shown++;
      });
      $('[data-rx-events-empty]').hidden = shown > 0 || !cards.length;
    };
    eventSearch.addEventListener('input', apply);
    $$('[data-rx-event-filter]').forEach(button => button.addEventListener('click', () => {
      status = button.dataset.rxEventFilter;
      $$('[data-rx-event-filter]').forEach(other => other.setAttribute('aria-pressed', String(other === button)));
      apply();
    }));
  }

  // Tabs: switch panels in place and keep the URL shareable.
  const tabs = $$('[data-rx-tab]');
  const show = name => {
    tabs.forEach(tab => tab.setAttribute('aria-selected', String(tab.dataset.rxTab === name)));
    $$('[data-rx-panel]').forEach(panel => { panel.hidden = panel.dataset.rxPanel !== name; });
    const url = new URL(location.href);
    url.searchParams.set('tab', name);
    history.replaceState(null, '', url);
  };
  tabs.forEach(tab => tab.addEventListener('click', event => { event.preventDefault(); show(tab.dataset.rxTab); }));
  tabs.forEach((tab, index) => tab.addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    const next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
    next.focus();
    show(next.dataset.rxTab);
  }));

  // Registered list filters.
  const table = $('[data-rx-table]');
  if (table) {
    const rows = $$('[data-rx-row]', table);
    const search = $('[data-rx-search]');
    const sectionSelect = $('[data-rx-section-select]');
    const state = { year: '', section: '', att: '' };
    const apply = () => {
      const query = search.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach(row => {
        const visible = (!state.year || row.dataset.year === state.year) && (!state.section || row.dataset.section === state.section)
          && (!state.att || row.dataset.att === state.att) && (!query || row.dataset.search.includes(query));
        row.hidden = !visible;
        if (visible) row.querySelector('.rx-num').textContent = ++shown;
      });
      const attended = rows.filter(row => !row.hidden && row.dataset.att === '1').length;
      $('[data-rx-count]').textContent = `${shown} of ${rows.length} students · ${attended} attended`;
      $('[data-rx-none]').hidden = shown > 0;
      $('[data-rx-print-filter]').textContent = [state.section || state.year, state.att === '1' ? 'attended' : state.att === '0' ? 'not attended' : ''].filter(Boolean).join(' · ');
    };
    const press = (buttons, active) => buttons.forEach(button => button.setAttribute('aria-pressed', String(button === active)));
    search.addEventListener('input', apply);
    $$('[data-rx-year]').forEach(button => button.addEventListener('click', () => {
      state.year = button.dataset.rxYear;
      state.section = '';
      sectionSelect.value = '';
      [...sectionSelect.options].forEach(option => { option.hidden = Boolean(option.value) && Boolean(state.year) && !option.value.includes(state.year); });
      press($$('[data-rx-year]'), button);
      apply();
    }));
    sectionSelect.addEventListener('change', () => { state.section = sectionSelect.value; apply(); });
    $$('[data-rx-att]').forEach(button => button.addEventListener('click', () => { state.att = button.dataset.rxAtt; press($$('[data-rx-att]'), button); apply(); }));
    const openSection = section => {
      state.section = section;
      state.year = '';
      press($$('[data-rx-year]'), $('[data-rx-year=""]'));
      [...sectionSelect.options].forEach(option => { option.hidden = false; });
      sectionSelect.value = section;
      apply();
    };
    $$('[data-rx-open-section]').forEach(button => button.addEventListener('click', () => { openSection(button.dataset.rxOpenSection); show('registered'); table.closest('section').scrollIntoView({ behavior: 'smooth', block: 'start' }); }));
    const initial = $('[data-rx-panel="registered"]').dataset.rxSection;
    if (initial) openSection(initial); else apply();

    // CSV of the rows currently shown.
    $('[data-rx-csv]').addEventListener('click', () => {
      const headers = ['Student', 'Student ID', 'Section', 'Email', 'Control no.', 'Attendance'];
      const cell = value => `"${String(value).replace(/"/g, '""').trim()}"`;
      const lines = rows.filter(row => !row.hidden).map(row => [...row.cells].slice(1, 7).map(td => cell(td.innerText.replace(/\s+/g, ' '))).join(','));
      const blob = new Blob(['﻿' + [headers.map(cell).join(','), ...lines].join('\r\n')], { type: 'text/csv;charset=utf-8' });
      const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `${document.querySelector('.rx-event-head h2').textContent.trim()} - registered.csv` });
      link.click();
      setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    });
  }

  // Print the registered list (with the current filters).
  $('[data-rx-print]')?.addEventListener('click', () => {
    if ($('[data-rx-panel="registered"]')) show('registered');
    window.print();
  });
})();
