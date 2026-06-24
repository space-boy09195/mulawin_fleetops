// ============================================================
// assets/js/announcements.js
// Full announcements page — submit and delete
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  const submitBtn = document.getElementById('submitFullAnnBtn');
  if (!submitBtn) return;

  submitBtn.addEventListener('click', async () => {
    const title   = document.getElementById('fullAnnTitle').value.trim();
    const body    = document.getElementById('fullAnnBody').value.trim();
    const pinned  = document.getElementById('fullAnnPinned').checked ? 1 : 0;
    const errEl   = document.getElementById('fullAnnError');

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
    fd.append('is_pinned', pinned);

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

});

// Called inline from delete buttons on the page
async function deleteAnnouncementFull(id) {
  if (!confirm('Delete this announcement? This cannot be undone.')) return;

  const fd = new FormData();
  fd.append('announcement_id', id);

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