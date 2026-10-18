/* Progressive enhancements: existing forms, permissions and routes remain authoritative. */
(() => {
  'use strict';
  function init() {
    if (!document.body.classList.contains('jpcs-admin')) return;
    const root = document.documentElement;
    // Use only the label so pending-count badges never become part of the page title.
    const active = document.querySelector('.admin-sidebar__link[aria-current="page"] .admin-sidebar__label');
    const title = document.querySelector('[data-admin-page-title]');
    if (title) title.textContent = active?.textContent.trim() || document.title.replace(/^JPCS\s*[-–:]?\s*/i, '');
    const themeButton = document.querySelector('[data-admin-theme]');
    function themeLabel() {
      if (!themeButton) return;
      const dark = root.dataset.theme === 'dark';
      themeButton.setAttribute('aria-label', `Switch to ${dark ? 'light' : 'dark'} theme`);
      themeButton.title = themeButton.getAttribute('aria-label');
      themeButton.setAttribute('aria-pressed', String(dark));
      const icon = themeButton.querySelector('.theme-icon');
      if (icon) icon.innerHTML = dark
        ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.4A8 8 0 0 1 8.6 4 8.1 8.1 0 1 0 20 15.4Z"></path></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>';
    }
    themeButton?.addEventListener('click', () => {
      root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('jpcs-theme', root.dataset.theme); } catch (_) { /* Storage can be unavailable in private mode. */ }
      themeLabel();
    });
    themeLabel();
    // Server-rendered editors share keyboard containment without changing their actions.
    const modal = document.querySelector('.modal [role="dialog"]');
    if (modal) {
      const focusable = () => [...modal.querySelectorAll('a[href],button,input:not([type="hidden"]),select,textarea,[tabindex="0"]')].filter(el => !el.disabled && el.getClientRects().length);
      let branch = modal.closest('.modal');
      while (branch && branch.parentElement) {
        [...branch.parentElement.children].forEach(sibling => { if (sibling !== branch && !['SCRIPT','LINK','STYLE'].includes(sibling.tagName)) sibling.inert = true; });
        if (branch.parentElement === document.body) break;
        branch = branch.parentElement;
      }
      (focusable()[0] || modal).focus();
      modal.addEventListener('keydown', event => {
        const elements = focusable(), first = elements[0], last = elements[elements.length - 1];
        if (event.key === 'Tab' && elements.length) {
          if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
          else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
        if (event.key === 'Escape') modal.querySelector('a.cancel-btn')?.click();
      });
    }
    // Mobile labels do not alter table data, sorting, or form submissions.
    document.querySelectorAll('.content table').forEach(table => {
      const headers = [...(table.tHead?.rows[0]?.cells || [])].map(cell => cell.textContent.trim());
      if (!headers.length) return;
      table.classList.add('admin-mobile-table');
      [...(table.tBodies[0]?.rows || [])].forEach(row => [...row.cells].forEach((cell, i) => { cell.dataset.label = headers[i] || ''; }));
    });
    const toastRegion = document.createElement('div');
    toastRegion.className = 'admin-toast-region'; toastRegion.setAttribute('role', 'status'); toastRegion.setAttribute('aria-live', 'polite'); document.body.append(toastRegion);
    window.JPCSAdmin = Object.assign(window.JPCSAdmin || {}, { toast(message) {
      const node = document.createElement('div'); node.className = 'admin-toast'; node.textContent = message; toastRegion.append(node); setTimeout(() => node.remove(), 6000);
    } });
    // Build navigation from the rendered sidebar, never from an unrestricted route list.
    const dialog = document.createElement('dialog'); dialog.className = 'admin-command'; dialog.setAttribute('aria-label', 'Search admin pages');
    dialog.innerHTML = '<header><strong>Go to a page</strong><button type="button" aria-label="Close search">Close</button></header><input type="search" aria-label="Search pages" placeholder="Search pages"><nav aria-label="Search results"></nav>';
    document.body.append(dialog);
    const search = dialog.querySelector('input'), results = dialog.querySelector('nav');
    document.querySelectorAll('.admin-sidebar__link,.admin-sidebar__profile').forEach(link => {
      const item = document.createElement('a'); item.href = link.getAttribute('href'); item.textContent = link.dataset.tooltip || link.textContent.trim(); results.append(item);
    });
    function openSearch() { if (!dialog.open) dialog.showModal(); search.focus(); }
    document.querySelector('[data-command-open]')?.addEventListener('click', openSearch);
    dialog.querySelector('button').addEventListener('click', () => dialog.close());
    search.addEventListener('input', () => results.querySelectorAll('a').forEach(link => { link.hidden = !link.textContent.toLowerCase().includes(search.value.toLowerCase()); }));
    document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openSearch(); } });
    document.querySelectorAll('.admin-menu').forEach(menu => menu.addEventListener('toggle', () => { if (menu.open) document.querySelectorAll('.admin-menu').forEach(other => { if (other !== menu) other.open = false; }); }));
    document.addEventListener('click', event => document.querySelectorAll('.admin-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; }));
    // Enhance only ordinary full-data tables; server-paginated tables can opt out.
    document.querySelectorAll('.content table:not([data-no-enhance])').forEach(table => {
      const body = table.tBodies[0], headings = [...(table.tHead?.rows[0]?.cells || [])];
      if (!body || !headings.length || table.dataset.adminEnhanced) return;
      const rows = [...body.rows];
      if (!rows.length || rows.some(row => row.cells.length !== headings.length)) return;
      table.dataset.adminEnhanced = 'true';
      let page = 0, sortColumn = -1, direction = 1;
      const toolbar = document.createElement('div'); toolbar.className = 'admin-table-tools';
      toolbar.innerHTML = '<input type="search" aria-label="Search table" placeholder="Search this table"><button type="button">Export filtered CSV</button>';
      const footer = document.createElement('div'); footer.className = 'admin-table-pagination';
      footer.innerHTML = '<span aria-live="polite"></span><button type="button">Previous</button><button type="button">Next</button>';
      table.before(toolbar); table.after(footer);
      const input = toolbar.querySelector('input'), [previous, next] = footer.querySelectorAll('button');
      function filtered() { return rows.filter(row => row.textContent.toLowerCase().includes(input.value.toLowerCase())); }
      function render() {
        const visible = filtered(); page = Math.max(0, Math.min(page, Math.ceil(visible.length / 10) - 1));
        rows.forEach(row => { row.hidden = true; }); visible.slice(page * 10, page * 10 + 10).forEach(row => { row.hidden = false; });
        footer.querySelector('span').textContent = `${visible.length ? page * 10 + 1 : 0}–${Math.min((page + 1) * 10, visible.length)} of ${visible.length} records`;
        previous.disabled = page === 0; next.disabled = (page + 1) * 10 >= visible.length;
      }
      input.addEventListener('input', () => { page = 0; render(); });
      previous.addEventListener('click', () => { page--; render(); }); next.addEventListener('click', () => { page++; render(); });
      headings.forEach((heading, index) => {
        if (/action/i.test(heading.textContent) || heading.querySelector('input,button') || !heading.textContent.trim()) return;
        const button = document.createElement('button'); button.type = 'button'; button.className = 'admin-sort'; button.textContent = heading.textContent + ' ↕'; heading.textContent = ''; heading.append(button);
        button.addEventListener('click', () => {
          direction = sortColumn === index ? -direction : 1; sortColumn = index;
          rows.sort((a, b) => direction * a.cells[index].textContent.trim().localeCompare(b.cells[index].textContent.trim(), undefined, { numeric: true }));
          rows.forEach(row => body.append(row)); headings.forEach(h => h.removeAttribute('aria-sort')); heading.setAttribute('aria-sort', direction === 1 ? 'ascending' : 'descending'); render();
        });
      });
      toolbar.querySelector('button').addEventListener('click', () => {
        const columns = headings.map((heading, index) => /action/i.test(heading.textContent) ? -1 : index).filter(index => index >= 0);
        // Neutralize spreadsheet formulas in all user-controlled cells.
        const quote = value => '"' + (/^[\s]*[=+@-]/.test(value) ? "'" : '') + value.replace(/"/g, '""') + '"';
        const data = [columns.map(i => headings[i].textContent.replace(' ↕', '')), ...filtered().map(row => columns.map(i => row.cells[i].textContent.trim()))];
        const url = URL.createObjectURL(new Blob(['\ufeff' + data.map(row => row.map(quote).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a'); link.href = url; link.download = 'jpcs-report.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
      });
      render();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
})();
