/* Officer Information: live search, role filter and a photo viewer with keyboard and swipe support. */
(() => {
  'use strict';
  const people = JSON.parse(document.getElementById('od-data')?.textContent || '[]');
  const cards = [...document.querySelectorAll('[data-od-card]')];
  const search = document.querySelector('[data-od-search]');
  const tiers = [...document.querySelectorAll('[data-od-tier]')];
  const count = document.querySelector('[data-od-count]');
  const empty = document.querySelector('[data-od-empty]');

  // Search keeps the hierarchy: tiers with no matching people are hidden.
  const apply = () => {
    const query = (search?.value || '').trim().toLowerCase();
    let shown = 0;
    cards.forEach(card => {
      card.hidden = Boolean(query) && !card.dataset.search.includes(query);
      if (!card.hidden) shown++;
    });
    tiers.forEach(tier => { tier.hidden = !tier.querySelector('[data-od-card]:not([hidden])'); });
    count.textContent = query ? `Showing ${shown} of ${cards.length}` : '';
    empty.hidden = shown > 0;
  };
  search?.addEventListener('input', apply);
  document.querySelector('[data-od-reset]')?.addEventListener('click', () => { search.value = ''; apply(); search.focus(); });

  // Viewer: steps through the cards that are currently visible.
  const viewer = document.querySelector('[data-od-viewer]');
  if (!viewer || !people.length) return;
  const image = viewer.querySelector('[data-od-image]');
  const initials = viewer.querySelector('[data-od-initials]');
  let order = [];
  let position = 0;
  let lastFocus = null;

  const show = () => {
    const person = people[order[position]];
    viewer.classList.remove('is-ready');
    if (person.photo) {
      image.hidden = false;
      initials.hidden = true;
      image.alt = person.name;
      image.src = person.photo;
    } else {
      image.hidden = true;
      initials.hidden = false;
      initials.textContent = person.initials;
    }
    viewer.querySelector('[data-od-role]').textContent = { adviser: 'Adviser', president: 'President' }[person.group] || 'Officer';
    viewer.querySelector('[data-od-name]').textContent = person.name;
    viewer.querySelector('[data-od-position]').textContent = person.position;
    viewer.querySelector('[data-od-counter]').textContent = `${position + 1} of ${order.length}`;
    viewer.querySelectorAll('[data-od-prev], [data-od-next]').forEach(button => { button.hidden = order.length < 2; });
    requestAnimationFrame(() => viewer.classList.add('is-ready'));
  };
  const step = delta => { position = (position + delta + order.length) % order.length; show(); };
  const open = index => {
    order = cards.filter(card => !card.hidden).map(card => Number(card.querySelector('[data-od-open]').dataset.odOpen));
    position = Math.max(0, order.indexOf(index));
    lastFocus = document.activeElement;
    viewer.hidden = false;
    document.body.classList.add('od-locked');
    show();
    requestAnimationFrame(() => viewer.classList.add('is-open'));
    viewer.querySelector('.od-viewer__close').focus();
  };
  const close = () => {
    viewer.classList.remove('is-open');
    document.body.classList.remove('od-locked');
    setTimeout(() => { viewer.hidden = true; }, 220);
    lastFocus?.focus();
  };

  document.querySelectorAll('[data-od-open]').forEach(button => button.addEventListener('click', () => open(Number(button.dataset.odOpen))));
  viewer.querySelectorAll('[data-od-close]').forEach(button => button.addEventListener('click', close));
  viewer.querySelector('[data-od-prev]').addEventListener('click', () => step(-1));
  viewer.querySelector('[data-od-next]').addEventListener('click', () => step(1));
  document.addEventListener('keydown', event => {
    if (viewer.hidden) return;
    if (event.key === 'Escape') close();
    else if (event.key === 'ArrowLeft') step(-1);
    else if (event.key === 'ArrowRight') step(1);
    else if (event.key === 'Tab') {
      const focusable = [...viewer.querySelectorAll('button:not([hidden])')];
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  // Swipe left/right on touch screens.
  let startX = null;
  viewer.addEventListener('touchstart', event => { startX = event.touches[0].clientX; }, { passive: true });
  viewer.addEventListener('touchend', event => {
    if (startX === null) return;
    const delta = event.changedTouches[0].clientX - startX;
    if (Math.abs(delta) > 45) step(delta < 0 ? 1 : -1);
    startX = null;
  });
})();
