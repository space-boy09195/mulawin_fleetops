// ============================================================
// assets/js/announcements.js
// Full announcements page — submit, edit, delete, sort/filter
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  // ── Add Announcement ────────────────────────────────────────────────────
  const submitBtn = document.getElementById('submitFullAnnBtn');

  submitBtn?.addEventListener('click', async () => {
    const title    = document.getElementById('fullAnnTitle').value.trim();
    const body     = document.getElementById('fullAnnBody').value.trim();
    const priority = document.querySelector('input[name="fullAnnPriority"]:checked')?.value ?? 'medium';
    const pinned   = document.getElementById('fullAnnPinned').checked ? '1' : '0';
    const errEl    = document.getElementById('fullAnnError');

    errEl.classList.add('d-none');

    if (!title || !body) {
      errEl.textContent = 'Title and message are required.';
      errEl.classList.remove('d-none');
      return;
    }

    submitBtn.disabled = true;

    const fd = new FormData();
    fd.append('title',     title);
    fd.append('body',      body);
    fd.append('priority',  priority);
    fd.append('is_pinned', pinned);
    fd.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);

    try {
      const res    = await fetch(window.APP_BASE + '/ajax/announcement_handler.php?action=add', { method: 'POST', body: fd });
      const result = await res.json();
      if (result.success) {
        window.location.reload();
      } else {
        errEl.textContent = result.message || 'Could not post.';
        errEl.classList.remove('d-none');
      }
    } catch (e) {
      errEl.textContent = 'Network error.';
      errEl.classList.remove('d-none');
    } finally {
      submitBtn.disabled = false;
    }
  });

  // ── Edit Announcement ────────────────────────────────────────────────────
  const editModalEl   = document.getElementById('editAnnModal');
  const editModal     = editModalEl ? new bootstrap.Modal(editModalEl) : null;
  const submitEditBtn = document.getElementById('submitEditAnnBtn');

  submitEditBtn?.addEventListener('click', async () => {
    const id       = document.getElementById('editAnnId').value;
    const title    = document.getElementById('editAnnTitle').value.trim();
    const body     = document.getElementById('editAnnBody').value.trim();
    const priority = document.querySelector('input[name="editAnnPriority"]:checked')?.value ?? 'medium';
    const pinned   = document.getElementById('editAnnPinned').checked ? '1' : '0';
    const errEl    = document.getElementById('editAnnError');

    errEl.classList.add('d-none');

    if (!title || !body) {
      errEl.textContent = 'Title and message are required.';
      errEl.classList.remove('d-none');
      return;
    }

    submitEditBtn.disabled = true;

    const fd = new FormData();
    fd.append('announcement_id', id);
    fd.append('title',           title);
    fd.append('body',            body);
    fd.append('priority',        priority);
    fd.append('is_pinned',       pinned);
    fd.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);

    try {
      const res    = await fetch(window.APP_BASE + '/ajax/announcement_handler.php?action=edit', { method: 'POST', body: fd });
      const result = await res.json();
      if (result.success) {
        window.location.reload();
      } else {
        errEl.textContent = result.message || 'Could not save changes.';
        errEl.classList.remove('d-none');
      }
    } catch (e) {
      errEl.textContent = 'Network error.';
      errEl.classList.remove('d-none');
    } finally {
      submitEditBtn.disabled = false;
    }
  });

  window.openEditAnnouncement = function (id, title, body, isPinned, priority) {
    document.getElementById('editAnnId').value      = id;
    document.getElementById('editAnnTitle').value    = title;
    document.getElementById('editAnnBody').value     = body;
    document.getElementById('editAnnPinned').checked = !!isPinned;

    const priorityVal = priority || 'medium';
    const radio = document.querySelector(`input[name="editAnnPriority"][value="${priorityVal}"]`);
    if (radio) {
      radio.checked = true;
      radio.dispatchEvent(new Event('change', { bubbles: true }));
    }

    document.getElementById('editAnnError')?.classList.add('d-none');
    editModal?.show();
  };

  // Highlights the selected severity option (fallback for browsers without :has()).
  document.querySelectorAll('.ann-severity-picker').forEach(picker => {
    picker.querySelectorAll('input[type="radio"]').forEach(radio => {
      radio.addEventListener('change', () => {
        picker.querySelectorAll('.ann-severity-option').forEach(opt =>
          opt.classList.remove('ann-severity-option-selected'));
        radio.closest('.ann-severity-option')?.classList.add('ann-severity-option-selected');
      });
    });
    // Apply initial state on load (e.g. Medium checked by default in Add modal).
    picker.querySelector('input[type="radio"]:checked')
      ?.closest('.ann-severity-option')
      ?.classList.add('ann-severity-option-selected');
  });

  // ── Sort / priority filter ───────────────────────────────────────────────
  const sortSelect    = document.getElementById('annSortFilter');
  const listContainer = document.querySelector('.ann-full-list');

  function applySort() {
    if (!listContainer) return;
    const items = Array.from(listContainer.querySelectorAll('.ann-full-item'));
    const mode  = sortSelect?.value ?? 'default';

    items.sort((a, b) => {
      const aPinned = parseInt(a.dataset.pinned, 10) || 0;
      const bPinned = parseInt(b.dataset.pinned, 10) || 0;
      const aSev    = parseInt(a.dataset.severityRank, 10) || 2;
      const bSev    = parseInt(b.dataset.severityRank, 10) || 2;
      const aTime   = parseInt(a.dataset.created, 10) || 0;
      const bTime   = parseInt(b.dataset.created, 10) || 0;

      switch (mode) {
        case 'oldest':
          return aTime - bTime;
        case 'newest':
          return bTime - aTime;
        case 'severity_asc':
          if (aSev !== bSev) return aSev - bSev;
          return bTime - aTime;
        case 'severity_desc':
          if (bSev !== aSev) return bSev - aSev;
          return bTime - aTime;
        case 'default':
        default:
          if (bPinned !== aPinned) return bPinned - aPinned;
          if (bSev !== aSev) return bSev - aSev;
          return bTime - aTime;
      }
    });

    items.forEach(item => listContainer.appendChild(item));
  }

  sortSelect?.addEventListener('change', applySort);

});

// Called inline from delete buttons on the page
async function deleteAnnouncementFull(id) {
  if (!confirm('Delete this announcement? This cannot be undone.')) return;

  const fd = new FormData();
  fd.append('announcement_id', id);
  fd.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);

  try {
    const res    = await fetch(window.APP_BASE + '/ajax/announcement_handler.php?action=delete', { method: 'POST', body: fd });
    const result = await res.json();
    if (result.success) {
      const el = document.getElementById('ann-full-' + id);
      if (el) {
        el.style.transition = 'opacity 0.3s';
        el.style.opacity    = '0';
        setTimeout(() => el.remove(), 300);
      }
    } else {
      alert(result.message || 'Could not delete.');
    }
  } catch (e) {
    alert('Network error.');
  }
}