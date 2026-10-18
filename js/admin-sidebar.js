/* Desktop rail and mobile drawer have independent state. */
(function () {
  function init() {
    const sidebar = document.getElementById('adminSidebar');
    if (!sidebar || sidebar.dataset.initialized) return;
    sidebar.dataset.initialized = 'true';
    const root = document.documentElement, desktop = window.matchMedia('(min-width: 861px)');
    const toggle = document.querySelector('.admin-sidebar-toggle'), overlay = document.querySelector('[data-sidebar-overlay]');
    const collapse = sidebar.querySelector('.admin-sidebar__collapse'), expand = sidebar.querySelector('.admin-sidebar__expand');
    const storageKey = 'jpcs-admin-sidebar-collapsed';
    let preferred = root.getAttribute('data-admin-sidebar-collapsed') === 'true';
    try { preferred = localStorage.getItem(storageKey) === 'true'; } catch (_) {}
    const tooltip = document.createElement('div');
    tooltip.className = 'admin-rail-tooltip'; tooltip.id = 'admin-rail-tooltip';
    tooltip.setAttribute('role', 'tooltip'); tooltip.hidden = true; document.body.append(tooltip);
    function hideTooltip() { tooltip.hidden = true; }
    function setCollapsed(value, persist) {
      const active = value && desktop.matches;
      // Explicit values must match CSS [="true"]; toggleAttribute writes an empty string.
      root.setAttribute('data-admin-sidebar-collapsed', active ? 'true' : 'false');
      document.body.classList.toggle('admin-sidebar-collapsed', active);
      sidebar.classList.toggle('is-collapsed', active);
      collapse.setAttribute('aria-expanded', String(!active)); expand.setAttribute('aria-expanded', String(!active));
      hideTooltip();
      if (persist) { preferred = value; try { localStorage.setItem(storageKey, String(value)); } catch (_) {} }
      document.dispatchEvent(new CustomEvent('jpcs:sidebar', { detail: { collapsed: active } }));
    }
    function setOpen(open) {
      const active = open && !desktop.matches;
      sidebar.classList.toggle('is-open', active); overlay.hidden = !active;
      toggle.setAttribute('aria-expanded', String(active));
      toggle.setAttribute('aria-label', active ? 'Close navigation' : 'Open navigation');
      sidebar.inert = !desktop.matches && !active;
      document.body.classList.toggle('admin-drawer-open', active);
      const content = document.querySelector('.content');
      if (content) content.inert = active;
      if (active) sidebar.querySelector('a').focus();
    }
    collapse.addEventListener('click', () => { setCollapsed(true, true); expand.focus(); });
    expand.addEventListener('click', () => { setCollapsed(false, true); collapse.focus(); });
    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
    overlay.addEventListener('click', () => { setOpen(false); toggle.focus(); });
    sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setOpen(false)));
    sidebar.querySelectorAll('[data-tooltip]').forEach(item => {
      if (!item.getAttribute('aria-label')) item.setAttribute('aria-label', item.dataset.tooltip);
      function show() {
        if (!desktop.matches || root.getAttribute('data-admin-sidebar-collapsed') !== 'true') return;
        tooltip.textContent = item.classList.contains('admin-sidebar__profile') ? item.title : item.dataset.tooltip;
        tooltip.hidden = false;
        const rect = item.getBoundingClientRect();
        tooltip.style.left = (sidebar.getBoundingClientRect().right + 10) + 'px';
        tooltip.style.top = Math.max(8,Math.min(innerHeight-tooltip.offsetHeight-8,rect.top+(rect.height-tooltip.offsetHeight)/2)) + 'px';
        item.setAttribute('aria-describedby', tooltip.id);
      }
      item.addEventListener('mouseenter', show); item.addEventListener('focus', show);
      item.addEventListener('mouseleave', hideTooltip); item.addEventListener('blur', hideTooltip);
    });
    sidebar.querySelector('nav').addEventListener('scroll', hideTooltip);
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') { hideTooltip(); if (sidebar.classList.contains('is-open')) { setOpen(false); toggle.focus(); } }
      if (event.key === 'Tab' && !desktop.matches && sidebar.classList.contains('is-open')) {
        const items = [...sidebar.querySelectorAll('a,button')].filter(el => el.offsetParent !== null);
        const first = items[0], last = items[items.length-1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
    desktop.addEventListener('change', () => { setCollapsed(preferred, false); setOpen(false); });
    setCollapsed(preferred, false); setOpen(false);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
}());
