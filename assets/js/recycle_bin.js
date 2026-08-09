/**
 * recycle_bin.js — Mulawin FleetOps
 * Handles: restore and permanently-delete actions.
 */

'use strict';

(function () {

  const BASE     = window.APP_BASE ?? '';
  const AJAX_URL = BASE + '/ajax/recycle_bin_handler.php';

  function postAjax(data) {
    return fetch(AJAX_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    new URLSearchParams(data),
    }).then(r => r.json());
  }

  // ── Restore ───────────────────────────────────────────────────────────────
  document.querySelectorAll('.rb-btn-restore').forEach(btn => {
    btn.addEventListener('click', () => {
      const archiveId = btn.dataset.archiveId;
      if (!archiveId) return;
      if (!confirm('Restore this item back to where it was?')) return;

      btn.disabled = true;
      postAjax({ action: 'restore', archive_id: archiveId })
        .then(res => {
          if (res.success) {
            window.location.reload();
          } else {
            alert(res.message || 'Could not restore this item.');
            btn.disabled = false;
          }
        })
        .catch(() => {
          alert('Network error. Please try again.');
          btn.disabled = false;
        });
    });
  });

  // ── Permanently delete ────────────────────────────────────────────────────
  const purgeModalEl  = document.getElementById('purgeModal');
  const purgeModal    = purgeModalEl ? new bootstrap.Modal(purgeModalEl) : null;
  const purgeSummary  = document.getElementById('purgeSummary');
  const purgeAlert    = document.getElementById('purgeAlert');
  const confirmPurgeBtn = document.getElementById('confirmPurgeBtn');
  const purgeBtnText   = document.getElementById('purgeBtnText');
  const purgeBtnSpinner = document.getElementById('purgeBtnSpinner');
  let pendingPurgeId = null;

  document.querySelectorAll('.rb-btn-purge').forEach(btn => {
    btn.addEventListener('click', () => {
      pendingPurgeId = btn.dataset.archiveId;
      if (purgeSummary) purgeSummary.textContent = btn.dataset.summary || 'this item';
      purgeAlert?.classList.add('d-none');
      purgeModal?.show();
    });
  });

  purgeModalEl?.addEventListener('hidden.bs.modal', () => {
    pendingPurgeId = null;
    purgeAlert?.classList.add('d-none');
  });

  confirmPurgeBtn?.addEventListener('click', () => {
    if (!pendingPurgeId) return;

    purgeAlert?.classList.add('d-none');
    confirmPurgeBtn.disabled = true;
    purgeBtnText?.classList.add('d-none');
    purgeBtnSpinner?.classList.remove('d-none');

    postAjax({ action: 'purge', archive_id: pendingPurgeId })
      .then(res => {
        if (res.success) {
          window.location.reload();
        } else {
          if (purgeAlert) {
            purgeAlert.textContent = res.message || 'Could not permanently delete this item.';
            purgeAlert.classList.remove('d-none');
          }
          confirmPurgeBtn.disabled = false;
          purgeBtnText?.classList.remove('d-none');
          purgeBtnSpinner?.classList.add('d-none');
        }
      })
      .catch(() => {
        if (purgeAlert) {
          purgeAlert.textContent = 'Network error. Please try again.';
          purgeAlert.classList.remove('d-none');
        }
        confirmPurgeBtn.disabled = false;
        purgeBtnText?.classList.remove('d-none');
        purgeBtnSpinner?.classList.add('d-none');
      });
  });

})();