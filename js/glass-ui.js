/* Progressive enhancement only: never intercept form submissions or access checks. */
document.addEventListener('DOMContentLoaded', function () {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const desktop = matchMedia('(hover: hover) and (pointer: fine) and (min-width: 761px)');
  const auth = document.querySelector('.auth-layout');
  const route = location.pathname.split('/').pop();

  // Keep real routes for tabs, POST validation errors, refresh and no-JS fallback.
  if (auth && document.body.classList.contains('auth-page')) {
    const card = auth.querySelector('.auth-card');
    const tabs = document.createElement('nav');
    tabs.className = 'glass-auth-tabs';
    tabs.setAttribute('aria-label', 'Account access');
    const isRegister = route === 'student_register.php';
    [['Sign In', route === 'login.php' ? 'login.php' : 'student_login.php'], ['Sign Up', 'student_register.php']].forEach(function (item, index) {
      const link = document.createElement('a');
      link.href = item[1]; link.textContent = item[0];
      if (isRegister === (index === 1)) link.setAttribute('aria-current', 'page');
      tabs.append(link);
    });
    card.prepend(tabs);
    // Present separate course/year controls while preserving the server's course_year contract.
    const combined = card.querySelector('select[name="course_year"]');
    if (combined) {
      const choices = Array.from(combined.options).filter(option => option.value).map(option => {
        const match = option.value.match(/^(.+?) (\d+(?:st|nd|rd|th) Year)$/);
        return match && { course: match[1], year: match[2], value: option.value };
      }).filter(Boolean);
      if (choices.length) {
        const current = choices.find(choice => choice.value === combined.value);
        const fields = {};
        ['course', 'year'].forEach(function (key) {
          const field = document.createElement('div'); field.className = 'auth-field';
          const label = document.createElement('label'); label.htmlFor = 'glass-' + key; label.textContent = key === 'course' ? 'Course' : 'Year level';
          const select = document.createElement('select'); select.id = label.htmlFor; select.required = true;
          select.add(new Option('Select ' + label.textContent.toLowerCase(), ''));
          Array.from(new Set(choices.map(choice => choice[key]))).forEach(value => select.add(new Option(value, value)));
          select.value = current ? current[key] : '';
          field.append(label, select); combined.closest('.auth-field').before(field); fields[key] = select;
        });
        const sync = function () { const selected = choices.find(choice => choice.course === fields.course.value && choice.year === fields.year.value); combined.value = selected ? selected.value : ''; };
        fields.course.addEventListener('change', sync); fields.year.addEventListener('change', sync);
        combined.required = false; combined.closest('.auth-field').hidden = true;
      }
    }
    card.querySelectorAll('.auth-field').forEach(function (field) {
      if (field.querySelector('input[placeholder]') && field.querySelector('label')) field.classList.add('glass-float-field');
    });
    if (typeof HTMLDialogElement !== 'undefined') {
      const dialog = document.createElement('dialog');
      dialog.className = 'glass-auth-modal';
      dialog.setAttribute('aria-label', isRegister ? 'Create a JPCS account' : 'Login to JPCS');
      document.body.append(dialog);
      // Include navbar in the dialog so theme/navigation remain keyboard accessible.
      const navbar = document.getElementById('jpcsNavbar');
      if (navbar) dialog.append(navbar);
      dialog.append(auth);
      const close = document.createElement('button');
      close.type = 'button'; close.className = 'glass-close'; close.textContent = '\u00d7';
      close.setAttribute('aria-label', 'Close and return to landing page');
      auth.prepend(close);
      const dismiss = function () { location.assign('index.php'); };
      close.addEventListener('click', dismiss);
      // Keep the form open when the user clicks outside the card.
      dialog.addEventListener('click', function (event) { if (event.target === dialog) event.stopPropagation(); });
      dialog.addEventListener('cancel', function (event) { event.preventDefault(); dismiss(); });
      dialog.showModal();
      document.body.style.overflow = 'hidden';
    }
  }

  // Feedback reflects server-rendered messages only; no simulated authentication.
  const feedback = Array.from(document.querySelectorAll('.auth-card .error, .auth-card .success')).map(function (node) { return node.textContent.trim(); }).filter(Boolean);
  if (feedback.length) {
    const toast = document.createElement('div'); toast.className = 'glass-toast';
    toast.setAttribute('role', 'status'); toast.setAttribute('aria-live', 'polite');
    const message = document.createElement('span'); message.textContent = feedback.join(' ');
    const close = document.createElement('button'); close.type = 'button'; close.textContent = '\u00d7'; close.setAttribute('aria-label', 'Dismiss notification');
    close.addEventListener('click', function () { toast.remove(); });
    toast.append(message, close);
    (document.querySelector('.glass-auth-modal') || document.body).append(toast);
  }

  const hero = document.querySelector('.hero-banner');
  if (hero) {
    const badges = document.createElement('div'); badges.className = 'hero-banner__badges';
    ['Est. 2022', 'Official CMU Chapter', '2026–2027'].forEach(function (text) {
      const badge = document.createElement('span'); badge.className = 'hero-glass-badge'; badge.textContent = text; badges.append(badge);
    });
    hero.append(badges);
  }
  // One lightweight radial layer, updated once per frame; no mouse handlers on touch.
  let cleanup = function () {};
  function setupMotion() {
    cleanup();
    if (reduced.matches || !desktop.matches) return;
    const glow = document.createElement('div'); glow.className = 'glass-cursor'; glow.setAttribute('aria-hidden', 'true'); document.body.append(glow);
    let frame = 0;
    const move = function (event) {
      if (event.pointerType && event.pointerType !== 'mouse') { leave(); return; }
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(function () {
        // Native dialogs live in the top layer; keep the halo in that layer too.
        const host = event.target.closest('dialog[open]') || document.body;
        if (glow.parentElement !== host) host.append(glow);
        const editing = event.target.closest('input,textarea,select,[contenteditable]:not([contenteditable="false"])');
        glow.style.opacity = editing ? '0' : '1';
        glow.style.transform = `translate(${event.clientX - 180}px, ${event.clientY - 180}px)`;
        if (!hero) return;
        const rect = hero.getBoundingClientRect();
        const inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
        hero.style.transform = inside ? `perspective(1400px) rotateX(${-(event.clientY - rect.top - rect.height / 2) / rect.height * 3}deg) rotateY(${(event.clientX - rect.left - rect.width / 2) / rect.width * 3}deg)` : '';
      });
    };
    const leave = function () { cancelAnimationFrame(frame); glow.style.opacity = '0'; if (hero) hero.style.transform = ''; };
    document.addEventListener('pointermove', move, { passive: true }); document.documentElement.addEventListener('pointerleave', leave);
    window.addEventListener('blur', leave);
    document.addEventListener('keydown', leave);
    cleanup = function () { cancelAnimationFrame(frame); document.removeEventListener('pointermove', move); document.documentElement.removeEventListener('pointerleave', leave); window.removeEventListener('blur', leave); document.removeEventListener('keydown', leave); glow.remove(); if (hero) hero.style.transform = ''; };
  }
  setupMotion(); reduced.addEventListener('change', setupMotion); desktop.addEventListener('change', setupMotion);

  // Only animate genuine existing numeric statistics, never invent metrics.
  if (!reduced.matches && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        const node = entry.target, original = node.textContent;
        const value = Number(original.replace(/,/g, '').trim());
        if (!Number.isFinite(value) || value < 0) return;
        node.setAttribute('aria-label', original);
        const start = performance.now();
        function tick(now) {
          const progress = reduced.matches ? 1 : Math.min((now - start) / 850, 1);
          node.textContent = progress === 1 ? original : Math.round(value * (1 - Math.pow(1 - progress, 3))).toLocaleString();
          if (progress < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
      });
    });
    document.querySelectorAll('[data-counter], .stat-number, .stat-value').forEach(function (node) { if (/^[\d,]+$/.test(node.textContent.trim())) observer.observe(node); });
  }
});
