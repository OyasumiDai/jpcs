/* Officer Information interactions. Server-rendered data and permissions remain authoritative. */
(() => {
  'use strict';
  const root = document.querySelector('.officers-page');
  if (!root) return;
  const cards = root.querySelector('[data-officer-cards]');
  const table = root.querySelector('[data-officer-table]');
  const search = root.querySelector('[data-officer-search]');
  const position = root.querySelector('[data-officer-position]');
  const role = root.querySelector('[data-officer-role]');
  const sort = root.querySelector('[data-officer-sort]');
  const count = root.querySelector('[data-officer-count]');
  const empty = root.querySelector('[data-officer-empty]');
  const drawer = document.querySelector('[data-officer-drawer]');
  const backdrop = document.querySelector('[data-officer-backdrop]');
  const drawerContent = document.querySelector('[data-officer-drawer-content]');
  let returnFocus = null;
  const addButton = root.querySelector('[data-officer-add]');
  const addModal = document.querySelector('[data-officer-modal]');
  const addModalBackdrop = document.querySelector('[data-officer-modal-backdrop]');
  const addForm = document.querySelector('[data-officer-add-form]');
  const addPhoto = addForm?.querySelector('[data-officer-photo]');
  const addDrop = addForm?.querySelector('[data-officer-drop]');
  const addPreview = addForm?.querySelector('[data-officer-preview]');
  const addStatus = addForm?.querySelector('[data-officer-form-status]');
  const editModal = document.querySelector('[data-officer-edit-modal]');
  const editBackdrop = document.querySelector('[data-officer-edit-backdrop]');
  const editForm = document.querySelector('[data-officer-edit-form]');
  const editPhoto = editForm?.querySelector('[data-officer-edit-photo]');
  const editDrop = editForm?.querySelector('[data-officer-edit-drop]');
  const editPreview = editForm?.querySelector('[data-officer-edit-preview]');
  const editStatus = editForm?.querySelector('[data-officer-edit-status]');
  let editReturnFocus = null;
  let addReturnFocus = null;
  const hierarchy = ['advisor', 'president', 'vice president', 'secretary', 'treasurer', 'auditor', 'public information', 'committee'];
  const items = () => [...root.querySelectorAll('[data-officer-item]')];
  const rows = () => [...root.querySelectorAll('[data-officer-row]')];
  const hierarchyIndex = value => { const index = hierarchy.findIndex(label => value.includes(label)); return index < 0 ? hierarchy.length : index; };
  const compare = (a, b) => sort.value === 'name' ? a.dataset.name.localeCompare(b.dataset.name) : sort.value === 'newest' ? (b.dataset.created || '').localeCompare(a.dataset.created || '') : hierarchyIndex(a.dataset.position) - hierarchyIndex(b.dataset.position) || a.dataset.name.localeCompare(b.dataset.name);
  function update() {
    const query = search.value.trim().toLowerCase(); const selectedPosition = position.value; const selectedRole = role.value;
    const visible = item => (!query || item.dataset.search.includes(query)) && (!selectedPosition || item.dataset.position === selectedPosition) && (!selectedRole || item.dataset.role === selectedRole);
    items().sort(compare).forEach(item => cards.append(item)); rows().sort(compare).forEach(row => table.querySelector('tbody').append(row));
    let total = 0; items().forEach(item => { const show = visible(item); item.hidden = !show; total += show ? 1 : 0; }); rows().forEach(row => { row.hidden = !visible(row); });
    count.textContent = `${total} officer${total === 1 ? '' : 's'}`; empty.hidden = total !== 0;
  }
  function setView(view) {
    const cardsView = view !== 'table'; cards.hidden = !cardsView; table.hidden = cardsView;
    root.querySelectorAll('[data-officer-view]').forEach(button => { const active = button.dataset.officerView === (cardsView ? 'cards' : 'table'); button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active)); });
    try { localStorage.setItem('jpcs-officer-view', cardsView ? 'cards' : 'table'); } catch (_) {}
  }
  function closeDrawer() { if (!drawer) return; drawer.hidden = true; backdrop.hidden = true; document.body.classList.remove('admin-drawer-open'); if (returnFocus) returnFocus.focus(); }
  function openDrawer(template, trigger) { if (!drawer || !template) return; drawerContent.replaceChildren(template.content.cloneNode(true)); drawer.hidden = false; backdrop.hidden = false; document.body.classList.add('admin-drawer-open'); returnFocus = trigger; drawer.querySelector('[data-officer-close]').focus(); }
  function closeAddModal() {
    if (!addModal) return;
    addModal.hidden = true;
    addModalBackdrop.hidden = true;
    document.body.classList.remove('admin-drawer-open');
    if (addReturnFocus) addReturnFocus.focus();
  }
  function clearFieldErrors() {
    addForm?.querySelectorAll('[data-error-for]').forEach(error => { error.textContent = ''; });
  }
  function updateCharacterCounts(form, selector) {
    if (!form) return;
    form.querySelectorAll(selector).forEach(counter => {
      const field = form.querySelector(`[name="${counter.dataset.characterCount || counter.dataset.editCharacterCount}"]`);
      if (field) counter.textContent = `${field.value.length}/${field.maxLength || 100}`;
    });
  }
  function setPhotoPreview(file) {
    if (!addPreview || !file) return;
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type) || file.size > 5 * 1024 * 1024) {
      if (addPhoto) addPhoto.setCustomValidity('Choose a JPG, PNG, or WEBP image under 5 MB.');
      addPreview.textContent = '×';
      if (addStatus) addStatus.textContent = 'Please choose a supported image under 5 MB.';
      return;
    }
    if (addPhoto) addPhoto.setCustomValidity('');
    const image = document.createElement('img');
    image.alt = 'Selected profile preview';
    image.src = URL.createObjectURL(file);
    image.onload = () => URL.revokeObjectURL(image.src);
    addPreview.replaceChildren(image);
    if (addStatus) addStatus.textContent = 'Photo ready. You can submit when the required fields are complete.';
  }
  function openAddModal() {
    if (!addModal || !addForm) return;
    addReturnFocus = addButton;
    addForm.reset();
    clearFieldErrors();
    if (addPhoto) addPhoto.setCustomValidity('');
    if (addPreview) addPreview.innerHTML = '<span aria-hidden="true">+</span>';
    if (addStatus) addStatus.textContent = 'Your profile will be visible to guests and students.';
    addModal.hidden = false;
    addModalBackdrop.hidden = false;
    document.body.classList.add('admin-drawer-open');
    addForm.querySelector('[name="name"]')?.focus();
  }
  function closeEditModal() {
    if (!editModal) return;
    editModal.hidden = true;
    editBackdrop.hidden = true;
    document.body.classList.remove('admin-drawer-open');
    if (editReturnFocus) editReturnFocus.focus();
  }
  function setEditPreview(file) {
    if (!editPreview || !file) return;
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type) || file.size > 5 * 1024 * 1024) {
      editPhoto?.setCustomValidity('Choose a JPG, PNG, or WEBP image under 5 MB.');
      editPreview.textContent = '×';
      if (editStatus) editStatus.textContent = 'Please choose a supported image under 5 MB.';
      return;
    }
    editPhoto?.setCustomValidity('');
    const image = document.createElement('img');
    image.alt = 'Selected profile preview';
    image.src = URL.createObjectURL(file);
    image.onload = () => URL.revokeObjectURL(image.src);
    editPreview.replaceChildren(image);
    if (editStatus) editStatus.textContent = 'Photo ready. Save when you are finished.';
  }
  function openEditModal(trigger) {
    if (!editModal || !editForm) return;
    const item = trigger.closest('[data-officer-item], [data-officer-row]');
    if (!item) return;
    const source = item.dataset.officerSource || 'account';
    const id = item.dataset.officerId || '';
    const isProfile = source === 'profile';
    editReturnFocus = trigger;
    editForm.action = `officers_admin.php?${isProfile ? 'profile' : 'edit'}=${encodeURIComponent(id)}`;
    editForm.querySelector('[name="id"]').value = isProfile ? '' : id;
    editForm.querySelector('[name="profile_id"]').value = isProfile ? id : '';
    editForm.querySelector('[name="name"]').value = item.dataset.name || '';
    editForm.querySelector('[name="position"]').value = item.dataset.officerPositionValue || '';
    editForm.querySelector('[name="remove_photo"]').checked = false;
    editForm.querySelectorAll('[data-edit-error-for]').forEach(error => { error.textContent = ''; });
    editPhoto?.setCustomValidity('');
    if (editPreview) editPreview.innerHTML = item.dataset.officerPhoto ? `<img src="${item.dataset.officerPhoto}" alt="Current profile photo">` : '<span aria-hidden="true">+</span>';
    const description = editModal.querySelector('[data-officer-edit-description]');
    if (description) description.textContent = isProfile ? 'Update the public profile shown on the JPCS landing page.' : 'Update this officer account profile. Login details remain managed in Account Management.';
    const note = editModal.querySelector('[data-officer-edit-account-note]');
    if (note) note.textContent = isProfile ? 'This profile is visible to guests and students.' : `Username / contact: ${item.dataset.officerUsername || 'Managed in Account Management'}`;
    if (editStatus) editStatus.textContent = 'Changes are saved securely.';
    updateCharacterCounts(editForm, '[data-edit-character-count]');
    editModal.hidden = false;
    editBackdrop.hidden = false;
    document.body.classList.add('admin-drawer-open');
    editForm.querySelector('[name="name"]')?.focus();
  }
  root.querySelectorAll('[data-officer-details]').forEach(button => button.addEventListener('click', event => { event.stopPropagation(); openDrawer(button.closest('[data-officer-item]')?.querySelector('[data-officer-detail]'), button); }));
  root.querySelectorAll('[data-officer-row-details]').forEach(button => button.addEventListener('click', () => { const row = button.closest('[data-officer-row]'); const card = items().find(item => item.dataset.search === row.dataset.search); openDrawer(card?.querySelector('[data-officer-detail]'), button); }));
  root.querySelectorAll('[data-officer-edit]').forEach(button => button.addEventListener('click', event => { event.stopPropagation(); openEditModal(button); }));
  [search, position, role, sort].forEach(control => control.addEventListener('input', update));
  root.querySelector('[data-officer-clear]').addEventListener('click', () => { search.value = ''; position.value = ''; role.value = ''; sort.value = 'hierarchy'; update(); search.focus(); });
  root.querySelectorAll('[data-officer-view]').forEach(button => button.addEventListener('click', () => setView(button.dataset.officerView)));
  document.querySelector('[data-officer-close]').addEventListener('click', closeDrawer); backdrop.addEventListener('click', closeDrawer);
  addButton?.addEventListener('click', openAddModal);
  addModal?.querySelectorAll('[data-officer-modal-close]').forEach(button => button.addEventListener('click', closeAddModal));
  addModalBackdrop?.addEventListener('click', closeAddModal);
  addPhoto?.addEventListener('change', () => setPhotoPreview(addPhoto.files?.[0]));
  addDrop?.addEventListener('dragover', event => { event.preventDefault(); addDrop.classList.add('is-dragging'); });
  addDrop?.addEventListener('dragleave', () => addDrop.classList.remove('is-dragging'));
  addDrop?.addEventListener('drop', event => {
    event.preventDefault();
    addDrop.classList.remove('is-dragging');
    const file = event.dataTransfer?.files?.[0];
    if (!file || !addPhoto) return;
    try { const transfer = new DataTransfer(); transfer.items.add(file); addPhoto.files = transfer.files; } catch (_) {}
    setPhotoPreview(file);
  });
  addForm?.addEventListener('submit', event => {
    clearFieldErrors();
    const name = addForm.querySelector('[name="name"]');
    const positionField = addForm.querySelector('[name="position"]');
    let valid = true;
    [[name, 'Name is required.'], [positionField, 'Position is required.']].forEach(([field, message]) => {
      if (field && !field.value.trim()) { field.nextElementSibling.textContent = message; valid = false; }
    });
    if (!addForm.checkValidity() || !valid) { event.preventDefault(); if (addStatus) addStatus.textContent = 'Complete the required fields before saving.'; addForm.querySelector(':invalid')?.focus(); return; }
    const save = addForm.querySelector('[data-officer-save]');
    if (save) { save.disabled = true; save.textContent = 'Saving…'; }
  });
  addForm?.querySelectorAll('input[name="name"], input[name="position"]').forEach(field => field.addEventListener('input', () => updateCharacterCounts(addForm, '[data-character-count]')));
  editModal?.querySelectorAll('[data-officer-edit-close]').forEach(button => button.addEventListener('click', closeEditModal));
  editBackdrop?.addEventListener('click', closeEditModal);
  editPhoto?.addEventListener('change', () => setEditPreview(editPhoto.files?.[0]));
  editDrop?.addEventListener('dragover', event => { event.preventDefault(); editDrop.classList.add('is-dragging'); });
  editDrop?.addEventListener('dragleave', () => editDrop.classList.remove('is-dragging'));
  editDrop?.addEventListener('drop', event => {
    event.preventDefault();
    editDrop.classList.remove('is-dragging');
    const file = event.dataTransfer?.files?.[0];
    if (!file || !editPhoto) return;
    try { const transfer = new DataTransfer(); transfer.items.add(file); editPhoto.files = transfer.files; } catch (_) {}
    setEditPreview(file);
  });
  editForm?.addEventListener('submit', event => {
    const name = editForm.querySelector('[name="name"]');
    const error = editForm.querySelector('[data-edit-error-for="name"]');
    if (!name.value.trim()) { event.preventDefault(); error.textContent = 'Name is required.'; if (editStatus) editStatus.textContent = 'Complete the required fields before saving.'; name.focus(); return; }
    error.textContent = '';
    const save = editForm.querySelector('[data-officer-edit-save]');
    if (save) { save.disabled = true; save.textContent = 'Saving…'; }
  });
  editForm?.querySelectorAll('input[name="name"], input[name="position"]').forEach(field => field.addEventListener('input', () => updateCharacterCounts(editForm, '[data-edit-character-count]')));
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (addModal && !addModal.hidden) closeAddModal();
    else if (editModal && !editModal.hidden) closeEditModal();
    else if (drawer && !drawer.hidden) closeDrawer();
  });
  let initialView = 'cards'; try { initialView = localStorage.getItem('jpcs-officer-view') || 'cards'; } catch (_) {}
  setView(initialView); update();
  updateCharacterCounts(addForm, '[data-character-count]');
  updateCharacterCounts(editForm, '[data-edit-character-count]');
})();
