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
    // Nav groups: remember which ones the user closed (initial state is applied inline in the sidebar).
    // On the icon rail a group opens as a flyout beside the sidebar instead.
    const groupKey = 'jpcs-admin-sidebar-groups';
    const isRail = () => desktop.matches && root.getAttribute('data-admin-sidebar-collapsed') === 'true';
    const flyout = document.createElement('div');
    flyout.className = 'admin-menu__panel admin-flyout'; flyout.hidden = true; flyout.setAttribute('role', 'menu');
    document.body.append(flyout);
    let flyoutButton = null, flyoutTimer = 0;
    function closeFlyout(returnFocus) {
      clearTimeout(flyoutTimer);
      if (!flyoutButton) return;
      flyoutButton.classList.remove('is-flyout-open'); flyoutButton.setAttribute('aria-expanded', String(!isRail() && !flyoutButton.closest('[data-sidebar-group]').classList.contains('is-closed')));
      if (returnFocus) flyoutButton.focus();
      flyout.hidden = true; flyoutButton = null;
    }
    function openFlyout(group, focusFirst) {
      clearTimeout(flyoutTimer);
      const button = group.querySelector('.admin-sidebar__group-toggle');
      if (flyoutButton === button) { if (focusFirst) flyout.querySelector('a')?.focus(); return; }
      closeFlyout(false); hideTooltip();
      const title = document.createElement('p');
      title.className = 'admin-flyout__title'; title.textContent = button.getAttribute('aria-label');
      flyout.replaceChildren(title, ...[...group.querySelectorAll('.admin-sidebar__link')].map(link => {
        const item = document.createElement('a');
        item.className = 'admin-menu__link admin-flyout__link' + (link.classList.contains('is-active') ? ' is-active' : '');
        item.href = link.getAttribute('href'); item.setAttribute('role', 'menuitem');
        if (link.hasAttribute('aria-current')) item.setAttribute('aria-current', 'page');
        item.innerHTML = link.querySelector('.admin-sidebar__icon').innerHTML;
        item.append(link.querySelector('.admin-sidebar__label').textContent);
        const badge = link.querySelector('.admin-sidebar__badge');
        if (badge) item.append(badge.cloneNode(true));
        return item;
      }));
      flyout.hidden = false; flyoutButton = button;
      button.classList.add('is-flyout-open'); button.setAttribute('aria-expanded', 'true');
      const rect = button.getBoundingClientRect();
      flyout.style.left = (sidebar.getBoundingClientRect().right + 10) + 'px';
      flyout.style.top = Math.max(8, Math.min(innerHeight - flyout.offsetHeight - 8, rect.top - 8)) + 'px';
      if (focusFirst) flyout.querySelector('a')?.focus();
    }
    const scheduleClose = () => { clearTimeout(flyoutTimer); flyoutTimer = setTimeout(() => closeFlyout(false), 220); };
    flyout.addEventListener('mouseenter', () => clearTimeout(flyoutTimer));
    flyout.addEventListener('mouseleave', scheduleClose);
    flyout.addEventListener('keydown', event => {
      const items = [...flyout.querySelectorAll('a')], index = items.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus(); }
      if (event.key === 'Escape') { event.stopPropagation(); closeFlyout(true); }
      if (event.key === 'Tab') closeFlyout(false);
    });
    document.addEventListener('click', event => { if (flyoutButton && !flyout.contains(event.target) && !flyoutButton.contains(event.target)) closeFlyout(false); });
    document.addEventListener('jpcs:sidebar', () => closeFlyout(false));
    window.addEventListener('resize', () => closeFlyout(false));
    sidebar.querySelectorAll('[data-sidebar-group]').forEach(group => {
      const button = group.querySelector('.admin-sidebar__group-toggle');
      button.addEventListener('mouseenter', () => { if (isRail()) openFlyout(group, false); });
      button.addEventListener('mouseleave', () => { if (isRail()) scheduleClose(); });
      button.addEventListener('keydown', event => { if (isRail() && event.key === 'ArrowRight') { event.preventDefault(); openFlyout(group, true); } });
      button.addEventListener('click', event => {
        if (isRail()) {
          // Mouse hover already opened it; keyboard (detail 0) toggles it and moves focus inside.
          if (flyoutButton === button && event.detail === 0) closeFlyout(true);
          else openFlyout(group, event.detail === 0);
          return;
        }
        const closed = !group.classList.contains('is-closed');
        group.classList.toggle('is-closed', closed);
        button.setAttribute('aria-expanded', String(!closed));
        let saved = {};
        try { saved = JSON.parse(localStorage.getItem(groupKey) || '{}'); } catch (_) {}
        saved[group.dataset.sidebarGroup] = closed;
        try { localStorage.setItem(groupKey, JSON.stringify(saved)); } catch (_) {}
      });
    });
    sidebar.querySelector('nav').addEventListener('scroll', () => { hideTooltip(); closeFlyout(false); });
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
