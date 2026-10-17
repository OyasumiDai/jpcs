/* Progressive UI enhancements; existing forms and action handlers remain untouched. */
document.addEventListener('DOMContentLoaded', function () {
  const route = location.pathname.split('/').pop() || 'index.php';
  document.querySelectorAll('.sidebar a').forEach(function (link) {
    if (new URL(link.href, location.href).pathname.split('/').pop() === route) {
      link.setAttribute('aria-current', 'page');
    }
  });
  document.querySelectorAll('.app-page table').forEach(function (table) {
    if (table.closest('.table-wrapper, .table-container, .ui-table-scroll')) return;
    const region = document.createElement('div');
    region.className = 'ui-table-scroll';
    region.tabIndex = 0;
    region.setAttribute('role', 'region');
    region.setAttribute('aria-label', 'Scrollable data table');
    table.before(region);
    region.append(table);
  });
  document.addEventListener('keydown', function (event) {
    const nav = document.getElementById('jpcsNavbar');
    if (event.key === 'Escape' && nav && nav.classList.contains('open')) {
      nav.classList.remove('open');
      const toggle = nav.querySelector('.jpcs-menu-toggle');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    }
  });
});
