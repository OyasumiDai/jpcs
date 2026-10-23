/* Pointer effects for glass-theme.css. Purely visual: never touches forms, links or data. */
(() => {
  'use strict';
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const finePointer = matchMedia('(hover: hover) and (pointer: fine)');
  const surfaces = [
    '.gt-glass', '.admin-sidebar', '.admin-topbar', '.auth-layout', '.jpcs-navbar',
    '.content .card', '.content .panel', '.content .panel-card', '.content .stat-card', '.content .summary-card',
    '.content .table-panel', '.content .list-panel', '.content .scanner-panel', '.content .profile-card',
    '.content .table-wrapper', '.content .table-container', '.content .dash-table-wrap',
    '.event-card', '.officer-card', '.highlight-card', '.mv-card', '.glass-panel', '.glass-card',
    '.student-page .content > .section > form'
  ].join(',');
  const tiltable = '.event-card, .officer-card, .highlight-card, .mv-card, .content .card';
  const pressable = 'button, .btn, .btn-back, .back-btn, .print-btn, .auth-submit, .jpcs-nav-button';

  // Spotlight: the glass under the pointer picks up a soft warm highlight.
  let lit = null;
  document.addEventListener('pointermove', event => {
    if (!finePointer.matches) return;
    const surface = event.target.closest?.(surfaces);
    if (lit && lit !== surface) { lit.style.removeProperty('--mx'); lit.style.removeProperty('--my'); }
    lit = surface;
    if (!surface) return;
    const box = surface.getBoundingClientRect();
    surface.style.setProperty('--mx', `${event.clientX - box.left}px`);
    surface.style.setProperty('--my', `${event.clientY - box.top}px`);
  }, { passive: true });

  // Tilt: cards lean slightly toward the pointer.
  let tilted = null;
  document.addEventListener('pointermove', event => {
    if (reduced.matches || !finePointer.matches) return;
    const card = event.target.closest?.(tiltable);
    if (tilted && tilted !== card) tilted.style.transform = '';
    tilted = card;
    if (!card) return;
    card.classList.add('gt-tilt');
    const box = card.getBoundingClientRect();
    const x = (event.clientX - box.left) / box.width - .5;
    const y = (event.clientY - box.top) / box.height - .5;
    card.style.transform = `perspective(900px) rotateX(${(-y * 4).toFixed(2)}deg) rotateY(${(x * 5).toFixed(2)}deg) translateY(-4px)`;
  }, { passive: true });
  document.addEventListener('pointerleave', () => { if (tilted) tilted.style.transform = ''; tilted = null; }, true);

  // Ripple: a short wave from the click point on buttons.
  document.addEventListener('pointerdown', event => {
    if (reduced.matches) return;
    const target = event.target.closest?.(pressable);
    if (!target || target.disabled) return;
    const box = target.getBoundingClientRect();
    const size = Math.max(box.width, box.height);
    const wave = document.createElement('span');
    wave.className = 'gt-ripple';
    wave.style.cssText = `width:${size}px;height:${size}px;left:${event.clientX - box.left - size / 2}px;top:${event.clientY - box.top - size / 2}px`;
    if (getComputedStyle(target).position === 'static') target.style.position = 'relative';
    target.style.overflow = 'hidden';
    target.append(wave);
    wave.addEventListener('animationend', () => wave.remove(), { once: true });
  });

  // Theme switch: the old theme frosts over and dissolves while the new one comes into focus.
  // The page's own toggle handlers still do the switching; this only wraps them in a
  // view transition. Browsers without view transitions get the colour cross-fade below.
  const toggles = '[data-theme-toggle], [data-admin-theme]';
  let replaying = false, revealing = false;
  document.addEventListener('click', event => {
    const toggle = event.target.closest?.(toggles);
    if (!toggle || replaying) return;
    // A second press while the circle is still opening is ignored, so the theme never flips mid-reveal.
    if (revealing) { event.stopImmediatePropagation(); event.preventDefault(); return; }
    if (reduced.matches || !document.startViewTransition) return;
    event.stopImmediatePropagation();
    event.preventDefault();
    revealing = true;
    const transition = document.startViewTransition(() => { replaying = true; toggle.click(); replaying = false; });
    transition.finished.finally(() => { revealing = false; });
  }, true);

  let shiftTimer;
  new MutationObserver(() => {
    if (reduced.matches || revealing) return;
    document.documentElement.classList.add('gt-theme-shift');
    clearTimeout(shiftTimer);
    shiftTimer = setTimeout(() => document.documentElement.classList.remove('gt-theme-shift'), 450);
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
