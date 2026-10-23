/* Notifications panel for advisers and officers. Opened from the sidebar item or the top-bar bell;
   without JavaScript both lead to notifications.php. */
(() => {
  'use strict';
  const triggers = [...document.querySelectorAll('[data-notify-open]')];
  if (!triggers.length) return;
  const badge = document.querySelector('[data-notify-count]');
  const token = document.querySelector('[data-notify-token]')?.dataset.notifyToken || '';
  const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const setBadge = count => {
    if (!badge) return;
    badge.hidden = count < 1;
    badge.textContent = count > 9 ? '9+' : String(count);
  };

  const overlay = document.createElement('div');
  overlay.className = 'notify-overlay';
  overlay.hidden = true;
  const panel = document.createElement('aside');
  panel.className = 'notify-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'true');
  panel.setAttribute('aria-labelledby', 'notify-title');
  panel.hidden = true;
  panel.innerHTML = `
    <header class="notify-panel__head">
      <span class="notify-panel__bell" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg></span>
      <div class="notify-panel__title"><h2 id="notify-title">Notifications</h2><p data-notify-summary>Loading…</p></div>
      <button type="button" class="notify-close" aria-label="Close notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg></button>
    </header>
    <a class="notify-attention" href="pending.php" data-notify-attention hidden>
      <span class="notify-attention__count" data-notify-pending>0</span>
      <span><strong>Needs your attention</strong><small data-notify-pending-text>registrations waiting for review</small></span>
      <span class="notify-attention__go">Review <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"></path></svg></span>
    </a>
    <div class="notify-tabs" role="tablist" aria-label="Filter notifications">
      <button type="button" role="tab" aria-selected="true" data-notify-filter="all">All <span data-notify-total>0</span></button>
      <button type="button" role="tab" aria-selected="false" data-notify-filter="unread">Unread <span data-notify-new>0</span></button>
    </div>
    <div class="notify-scroll" data-notify-list aria-live="polite"></div>
    <footer class="notify-panel__foot"><a href="notifications.php">View all notifications</a></footer>`;
  document.body.append(overlay, panel);
  const listBox = panel.querySelector('[data-notify-list]');
  const summary = panel.querySelector('[data-notify-summary]');
  const closeButton = panel.querySelector('.notify-close');
  let feed = { items: [], pending: 0 };
  let filter = 'all';

  const dayLabel = epoch => {
    const date = new Date(epoch * 1000);
    const start = new Date(); start.setHours(0, 0, 0, 0);
    if (date >= start) return 'Today';
    start.setDate(start.getDate() - 1);
    if (date >= start) return 'Yesterday';
    start.setDate(start.getDate() - 5);
    return date >= start ? 'This week' : 'Earlier';
  };
  const itemHtml = item => `
    <li class="notify-item is-${escapeHtml(item.kind)}${item.unread ? ' is-unread' : ''}">
      <a href="${escapeHtml(item.url)}">
        <span class="notify-icon" aria-hidden="true">${item.icon || ''}</span>
        <span class="notify-body"><strong>${escapeHtml(item.title)}</strong>${item.detail ? `<small>${escapeHtml(item.detail)}</small>` : ''}</span>
        <time>${escapeHtml(item.ago)}</time>
      </a>
    </li>`;
  const render = () => {
    const unread = feed.items.filter(item => item.unread);
    const shown = (filter === 'unread' ? unread : feed.items).slice(0, 30);
    summary.textContent = unread.length ? `${unread.length} new since you last checked` : 'You are all caught up';
    panel.querySelector('[data-notify-total]').textContent = feed.items.length;
    panel.querySelector('[data-notify-new]').textContent = unread.length;
    const attention = panel.querySelector('[data-notify-attention]');
    attention.hidden = !feed.pending;
    panel.querySelector('[data-notify-pending]').textContent = feed.pending > 99 ? '99+' : feed.pending;
    panel.querySelector('[data-notify-pending-text]').textContent = feed.pending === 1 ? 'registration waiting for review' : 'registrations waiting for review';
    if (!shown.length) {
      listBox.innerHTML = `<div class="notify-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg></span>
        <strong>${filter === 'unread' ? 'No unread notifications' : 'Nothing yet'}</strong>
        <small>New registrations, check-ins, masterlist uploads and event changes will appear here.</small></div>`;
      return;
    }
    const groups = new Map();
    shown.forEach(item => { const label = dayLabel(item.at); if (!groups.has(label)) groups.set(label, []); groups.get(label).push(item); });
    listBox.innerHTML = [...groups].map(([label, items]) => `<section class="notify-group"><h3>${label}</h3><ul class="notify-list">${items.map(itemHtml).join('')}</ul></section>`).join('');
  };

  panel.querySelectorAll('[data-notify-filter]').forEach(tab => tab.addEventListener('click', () => {
    filter = tab.dataset.notifyFilter;
    panel.querySelectorAll('[data-notify-filter]').forEach(other => other.setAttribute('aria-selected', String(other === tab)));
    render();
  }));

  const fetchFeed = () => fetch('notifications.php?format=json', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(response => {
    if (!response.ok) throw new Error(String(response.status));
    return response.json();
  });
  // Opening the panel marks everything as read; items stay highlighted until the panel is reopened.
  const markRead = () => {
    const body = new FormData();
    body.append('csrf', token);
    body.append('format', 'json');
    return fetch('notifications.php', { method: 'POST', body, credentials: 'same-origin' }).then(response => { if (response.ok) setBadge(0); });
  };

  let lastFocus = null;
  const open = () => {
    lastFocus = document.activeElement;
    overlay.hidden = false;
    panel.hidden = false;
    requestAnimationFrame(() => { overlay.classList.add('is-open'); panel.classList.add('is-open'); });
    triggers.forEach(trigger => trigger.setAttribute('aria-expanded', 'true'));
    closeButton.focus();
    listBox.innerHTML = '<div class="notify-loading" aria-label="Loading"><span></span><span></span><span></span></div>';
    fetchFeed().then(data => { feed = data; render(); if (data.unread > 0) markRead(); })
      .catch(() => { summary.textContent = 'Could not load notifications.'; listBox.innerHTML = '<div class="notify-empty"><a href="notifications.php">Open the notifications page</a></div>'; });
  };
  const close = () => {
    overlay.classList.remove('is-open');
    panel.classList.remove('is-open');
    triggers.forEach(trigger => trigger.setAttribute('aria-expanded', 'false'));
    setTimeout(() => { overlay.hidden = true; panel.hidden = true; }, 250);
    lastFocus?.focus();
  };

  triggers.forEach(trigger => {
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.addEventListener('click', event => {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return; // keep "open in new tab"
      event.preventDefault();
      panel.hidden ? open() : close();
    });
  });
  overlay.addEventListener('click', close);
  closeButton.addEventListener('click', close);
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) close(); });

  // Keep the sidebar badge current while the page stays open.
  setInterval(() => {
    if (document.hidden || !panel.hidden) return;
    fetchFeed().then(data => setBadge(data.unread)).catch(() => {});
  }, 60000);
})();
