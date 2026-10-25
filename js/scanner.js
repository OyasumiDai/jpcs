/* Event attendance scanner: camera, background check-in, result popup, live feed. */
(() => {
  'use strict';
  const popup = document.getElementById('scan-popup');
  const app = document.querySelector('[data-scanner]');
  const store = {
    get: key => { try { return localStorage.getItem(key); } catch (error) { return null; } },
    set: (key, value) => { try { localStorage.setItem(key, value); } catch (error) { /* private mode */ } },
  };

  /* ---------- Feedback: tones and vibration ---------- */
  let audio = null;
  let soundOn = store.get('jpcs-scanner-sound') !== 'off';
  const tone = (notes) => {
    if (!soundOn) return;
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      let at = audio.currentTime;
      notes.forEach(([frequency, length]) => {
        const osc = audio.createOscillator();
        const gain = audio.createGain();
        osc.type = 'sine';
        osc.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(0.25, at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        osc.connect(gain).connect(audio.destination);
        osc.start(at);
        osc.stop(at + length + 0.02);
        at += length * 0.9;
      });
    } catch (error) { /* audio unavailable */ }
  };
  const feedback = {
    ok: () => { tone([[880, 0.12], [1320, 0.18]]); navigator.vibrate?.(80); },
    duplicate: () => { tone([[520, 0.16], [520, 0.16]]); navigator.vibrate?.([120, 80, 120]); },
    invalid: () => { tone([[300, 0.32]]); navigator.vibrate?.([260]); },
  };

  /* ---------- Popup ---------- */
  const eyebrows = { ok: 'Checked in', duplicate: 'Duplicate scan', invalid: 'Not accepted', closed: 'Scanning closed', error: 'Something went wrong' };
  const kinds = { ok: 'ok', duplicate: 'duplicate' };
  const field = name => popup.querySelector(`[data-sc-p="${name}"]`);
  const initials = name => {
    const parts = name.split(/[\s,]+/).filter(Boolean);
    return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
  };
  let onPopupClose = null;

  const showPopup = (result, done) => {
    const kind = kinds[result.status] || 'invalid';
    const student = result.student || {};
    popup.className = `sc-popup is-${kind}`;
    field('eyebrow').textContent = eyebrows[result.status] || eyebrows.error;
    field('title').textContent = result.title || 'Scan result';
    field('message').textContent = result.message || '';
    field('person').hidden = !student.name;
    field('name').textContent = student.name || '';
    field('course').textContent = student.course || '';
    field('initials').textContent = student.name ? initials(student.name) : '';
    ['student_id', 'control', 'time'].forEach(key => {
      field(key).textContent = student[key] || '';
      popup.querySelector(`[data-sc-row="${key}"]`).hidden = !student[key];
    });
    field('time_label').textContent = student.time_label || 'Time in';
    popup.querySelector('[data-sc-close]').textContent = app && app.dataset.closed !== '1' ? 'Scan next' : 'Close';

    // Restart the countdown bar; success closes faster than warnings.
    const timer = popup.querySelector('[data-sc-timer]');
    timer.style.animation = 'none';
    void timer.offsetWidth;
    timer.style.animation = '';
    timer.style.animationDuration = kind === 'ok' ? '3.2s' : '6s';

    onPopupClose = done || null;
    if (!popup.open) popup.showModal();
    popup.querySelector('[data-sc-close]').focus();
    (feedback[kind] || feedback.invalid)();
  };

  const closePopup = () => { if (popup.open) popup.close(); };
  popup.querySelector('[data-sc-close]').addEventListener('click', closePopup);
  popup.querySelector('[data-sc-timer]').addEventListener('animationend', closePopup);
  popup.addEventListener('click', event => { if (event.target === popup) closePopup(); });
  popup.addEventListener('close', () => {
    const callback = onPopupClose;
    onPopupClose = null;
    callback?.();
  });

  if (popup.dataset.initial) {
    try { showPopup(JSON.parse(popup.dataset.initial)); } catch (error) { /* ignore malformed state */ }
  }
  if (!app) return;

  /* ---------- Stats and feed ---------- */
  const feedList = app.querySelector('[data-sc-feed]');
  const filter = app.querySelector('[data-sc-filter]');
  const updateStats = stats => {
    if (!stats) return;
    const remaining = Math.max(0, stats.registered - stats.attended);
    const percent = stats.registered ? Math.min(100, Math.round(stats.attended / stats.registered * 100)) : 0;
    const values = { registered: stats.registered, attended: stats.attended, remaining, percent: `${percent}%` };
    app.querySelectorAll('[data-sc-stat]').forEach(node => {
      const next = String(values[node.dataset.scStat]);
      if (node.textContent !== next) {
        node.textContent = next;
        node.classList.remove('is-bump');
        void node.offsetWidth;
        node.classList.add('is-bump');
      }
    });
    app.querySelector('[data-sc-bar]').style.width = `${percent}%`;
  };
  const applyFilter = () => {
    const query = (filter?.value || '').trim().toLowerCase();
    let shown = 0;
    feedList.querySelectorAll('.sc-feed__item').forEach(item => {
      const match = !query || item.dataset.scSearch.includes(query);
      item.hidden = !match;
      if (match) shown += 1;
    });
    const total = feedList.children.length;
    app.querySelector('[data-sc-empty]').hidden = total > 0;
    app.querySelector('[data-sc-nomatch]').hidden = !(total > 0 && shown === 0);
  };
  filter?.addEventListener('input', applyFilter);
  const addToFeed = html => {
    if (!html) return;
    const holder = document.createElement('template');
    holder.innerHTML = html.trim();
    const item = holder.content.firstElementChild;
    item.classList.add('is-new');
    feedList.prepend(item);
    applyFilter();
  };

  if (app.dataset.closed === '1') return;

  /* ---------- Check-in requests ---------- */
  const form = app.querySelector('[data-sc-form]');
  const input = form.querySelector('#qrdata');
  const submitButton = form.querySelector('button[type="submit"]');
  let busy = false;
  let last = { code: '', at: 0 };

  const checkIn = async (code, source) => {
    code = code.trim();
    if (!code || busy) return;
    busy = true;
    submitButton.disabled = true;
    camera.pause();
    let result;
    try {
      const body = new FormData(form);
      body.set('qrdata', code);
      body.set('ajax', '1');
      const response = await fetch(form.action || location.href, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      result = await response.json();
    } catch (error) {
      result = { status: 'error', title: 'Connection problem', message: 'The scan could not reach the server. Check the connection and try again.' };
    }
    updateStats(result.stats);
    if (result.status === 'ok') addToFeed(result.row);
    showPopup(result, () => {
      busy = false;
      submitButton.disabled = false;
      last = { code, at: Date.now() };
      if (source === 'manual') { input.value = ''; input.focus(); }
      camera.resume();
    });
  };

  form.addEventListener('submit', event => {
    event.preventDefault();
    checkIn(input.value, 'manual');
  });

  /* ---------- Camera ---------- */
  const viewport = app.querySelector('[data-sc-viewport]');
  const idle = app.querySelector('[data-sc-idle]');
  const idleText = app.querySelector('[data-sc-idle-text]');
  const startButton = app.querySelector('[data-sc-start]');
  const stopButton = app.querySelector('[data-sc-stop]');
  const cameraWrap = app.querySelector('[data-sc-camera-wrap]');
  const cameraSelect = app.querySelector('[data-sc-camera]');
  const statusChip = app.querySelector('[data-sc-status]');
  const soundButton = app.querySelector('[data-sc-sound]');

  const setStatus = (text, state) => {
    statusChip.textContent = text;
    statusChip.className = `sc-chip${state ? ` is-${state}` : ''}`;
  };
  const setSound = on => {
    soundOn = on;
    soundButton.setAttribute('aria-pressed', String(on));
    soundButton.querySelector('span').textContent = on ? 'Sound on' : 'Sound off';
    store.set('jpcs-scanner-sound', on ? 'on' : 'off');
  };
  setSound(soundOn);
  soundButton.addEventListener('click', () => setSound(!soundOn));

  const camera = {
    reader: null,
    running: false,
    paused: false,
    pause() {
      if (!this.running || this.paused) return;
      try { this.reader.pause(true); this.paused = true; viewport.classList.add('is-paused'); } catch (error) { /* not scanning */ }
    },
    resume() {
      if (!this.running || !this.paused) return;
      try { this.reader.resume(); } catch (error) { /* already running */ }
      this.paused = false;
      viewport.classList.remove('is-paused');
    },
  };

  const onDecode = text => {
    if (busy || popup.open) return;
    // The camera reads the same pass many times a second; ignore repeats right after a popup closes.
    if (text === last.code && Date.now() - last.at < 4000) return;
    checkIn(text, 'camera');
  };

  const cameraError = error => {
    const name = error?.name || '';
    const text = String(error?.message || error || '');
    if (!window.isSecureContext) return 'The camera needs a secure (https) connection. Use manual entry, or open this page over https.';
    if (name === 'NotAllowedError' || /permission|denied/i.test(text)) return 'Camera permission was blocked. Allow camera access in the browser settings, then try again.';
    if (name === 'NotFoundError' || /no camera|not found|Requested device/i.test(text)) return 'No camera was found on this device. Use manual entry below.';
    if (name === 'NotReadableError' || /in use|could not start/i.test(text)) return 'The camera is being used by another app. Close it and try again.';
    return 'The camera could not start. Use manual entry below.';
  };

  const listCameras = async () => {
    try {
      const cameras = await Html5Qrcode.getCameras();
      if (cameras.length < 2) { cameraWrap.hidden = true; return; }
      const saved = store.get('jpcs-scanner-camera');
      cameraSelect.innerHTML = '';
      cameras.forEach((device, index) => cameraSelect.add(new Option(device.label || `Camera ${index + 1}`, device.id, false, device.id === saved)));
      cameraWrap.hidden = false;
    } catch (error) { cameraWrap.hidden = true; }
  };

  const startCamera = async () => {
    if (typeof Html5Qrcode === 'undefined') {
      idleText.textContent = 'The scanner library did not load. Refresh the page or use manual entry.';
      return;
    }
    startButton.disabled = true;
    setStatus('Starting…', 'wait');
    idleText.textContent = 'Starting the camera…';
    try {
      camera.reader = camera.reader || new Html5Qrcode('reader', { verbose: false, experimentalFeatures: { useBarCodeDetectorIfSupported: true } });
      const saved = store.get('jpcs-scanner-camera');
      const source = cameraSelect.value || saved || { facingMode: 'environment' };
      const config = { fps: 12, aspectRatio: 1, disableFlip: false };
      try {
        await camera.reader.start(source, config, onDecode, () => {});
      } catch (error) {
        if (typeof source !== 'string') throw error;
        await camera.reader.start({ facingMode: 'environment' }, config, onDecode, () => {});
      }
      camera.running = true;
      camera.paused = false;
      viewport.classList.add('is-live');
      idle.hidden = true;
      stopButton.hidden = false;
      setStatus('Scanning', 'live');
      store.set('jpcs-scanner-auto', '1');
      listCameras();
    } catch (error) {
      console.error(error);
      idleText.textContent = cameraError(error);
      setStatus('Camera off', 'error');
      startButton.textContent = 'Try again';
    } finally {
      startButton.disabled = false;
    }
  };

  const stopCamera = async () => {
    if (!camera.running) return;
    try { await camera.reader.stop(); } catch (error) { /* already stopped */ }
    camera.running = false;
    camera.paused = false;
    viewport.classList.remove('is-live', 'is-paused');
    idle.hidden = false;
    idleText.textContent = 'Camera stopped. Start it again to keep scanning.';
    startButton.textContent = 'Start camera';
    stopButton.hidden = true;
    setStatus('Camera off');
    store.set('jpcs-scanner-auto', '0');
  };

  startButton.addEventListener('click', () => {
    tone([]); // unlocks audio on iOS
    startCamera();
  });
  stopButton.addEventListener('click', stopCamera);
  cameraSelect.addEventListener('change', async () => {
    store.set('jpcs-scanner-camera', cameraSelect.value);
    if (camera.running) { await stopCamera(); startCamera(); }
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) camera.pause(); else if (!busy && !popup.open) camera.resume();
  });

  // Reopen the camera automatically for staff who already allowed it.
  if (store.get('jpcs-scanner-auto') === '1' && window.isSecureContext) startCamera();
})();
