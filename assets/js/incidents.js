/**
 * incidents.js — Mulawin FleetOps
 * Handles: log incident form, client-side filtering, resolve flow.
 */

'use strict';

(function () {

  const BASE      = window.APP_BASE ?? '';
  const AJAX_URL  = BASE + '/ajax/log_incident.php';

  // ── DOM refs ─────────────────────────────────────────────────────────────
  const filterType    = document.getElementById('filterType');
  const filterStatus  = document.getElementById('filterStatus');
  const filterSearch  = document.getElementById('filterSearch');
  const tableBody     = document.querySelector('#incidentsTable tbody');

  // Log incident modal
  const tripSelect      = document.getElementById('tripSelect');
  const incidentType    = document.getElementById('incidentType');
  const incidentDesc    = document.getElementById('incidentDesc');
  const submitBtn       = document.getElementById('submitIncidentBtn');
  const submitText      = document.getElementById('submitBtnText');
  const submitSpinner   = document.getElementById('submitBtnSpinner');
  const incFormAlert    = document.getElementById('incFormAlert');
  const logModal        = document.getElementById('logIncidentModal');

  // Resolve modal
  const resolveModal    = document.getElementById('resolveModal');
  const resolveTripRef  = document.getElementById('resolveTripRef');
  const confirmResolve  = document.getElementById('confirmResolveBtn');
  const resolveText     = document.getElementById('resolveBtnText');
  const resolveSpinner  = document.getElementById('resolveBtnSpinner');
  const resolveAlert    = document.getElementById('resolveAlert');

  let pendingResolveId  = null;

  // ── Filtering ─────────────────────────────────────────────────────────────
  function applyFilters() {
    if (!tableBody) return;

    const typeVal   = filterType?.value.toLowerCase()   ?? '';
    const statusVal = filterStatus?.value.toLowerCase() ?? '';
    const searchVal = filterSearch?.value.toLowerCase() ?? '';

    const rows = tableBody.querySelectorAll('tr');
    rows.forEach(row => {
      const rowType   = (row.dataset.type   ?? '').toLowerCase();
      const rowStatus = (row.dataset.status ?? '').toLowerCase();
      const rowSearch = (row.dataset.search ?? '').toLowerCase();

      const matchType   = !typeVal   || rowType   === typeVal;
      const matchStatus = !statusVal || rowStatus === statusVal;
      const matchSearch = !searchVal || rowSearch.includes(searchVal);

      row.classList.toggle('inc-row-hidden', !(matchType && matchStatus && matchSearch));
    });
  }

  if (filterType)   filterType.addEventListener('change', applyFilters);
  if (filterStatus) filterStatus.addEventListener('change', applyFilters);
  if (filterSearch) filterSearch.addEventListener('input', applyFilters);

  // ── Helpers ───────────────────────────────────────────────────────────────
  function showAlert(el, message, type = 'danger') {
    if (!el) return;
    el.className = `alert alert-${type}`;
    el.textContent = message;
    el.classList.remove('d-none');
  }

  function hideAlert(el) {
    if (!el) return;
    el.classList.add('d-none');
    el.textContent = '';
  }

  function setLoading(textEl, spinnerEl, loading) {
    if (!textEl || !spinnerEl) return;
    if (loading) {
      spinnerEl.classList.remove('d-none');
      textEl.closest('button').disabled = true;
    } else {
      spinnerEl.classList.add('d-none');
      textEl.closest('button').disabled = false;
    }
  }

  function postAjax(data) {
    const body = new URLSearchParams(data);
    return fetch(AJAX_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    }).then(r => r.json());
  }

  // ── Reset log form on modal close ─────────────────────────────────────────
  if (logModal) {
    logModal.addEventListener('hidden.bs.modal', () => {
      if (tripSelect)   tripSelect.value   = '';
      if (incidentType) incidentType.value = '';
      if (incidentDesc) incidentDesc.value = '';
      hideAlert(incFormAlert);
      if (submitBtn) submitBtn.disabled = false;
      if (submitSpinner) submitSpinner.classList.add('d-none');
    });
  }

  // ── Submit log incident ───────────────────────────────────────────────────
  if (submitBtn) {
    submitBtn.addEventListener('click', () => {
      hideAlert(incFormAlert);

      const tripId = tripSelect?.value     ?? '';
      const type   = incidentType?.value   ?? '';
      const desc   = incidentDesc?.value.trim() ?? '';

      if (!tripId || !type || !desc) {
        showAlert(incFormAlert, 'Please fill in all fields.');
        return;
      }

      setLoading(submitText, submitSpinner, true);

      postAjax({ action: 'log', trip_id: tripId, type, description: desc })
        .then(res => {
          setLoading(submitText, submitSpinner, false);
          if (res.success) {
            // Close modal and reload to reflect new row
            bootstrap.Modal.getInstance(logModal)?.hide();
            window.location.reload();
          } else {
            showAlert(incFormAlert, res.message ?? 'Failed to log incident.');
          }
        })
        .catch(() => {
          setLoading(submitText, submitSpinner, false);
          showAlert(incFormAlert, 'Network error. Please try again.');
        });
    });
  }

  // ── Open resolve modal ────────────────────────────────────────────────────
  if (tableBody) {
    tableBody.addEventListener('click', e => {
      const btn = e.target.closest('.btn-resolve');
      if (!btn) return;

      pendingResolveId = btn.dataset.id ?? null;
      const tripRef    = btn.dataset.trip ?? '';

      if (resolveTripRef) resolveTripRef.textContent = tripRef;
      hideAlert(resolveAlert);
      if (confirmResolve) confirmResolve.disabled = false;
      if (resolveSpinner) resolveSpinner.classList.add('d-none');

      const modal = new bootstrap.Modal(resolveModal);
      modal.show();
    });
  }

  // ── Confirm resolve ───────────────────────────────────────────────────────
  if (confirmResolve) {
    confirmResolve.addEventListener('click', () => {
      if (!pendingResolveId) return;

      hideAlert(resolveAlert);
      setLoading(resolveText, resolveSpinner, true);

      postAjax({ action: 'resolve', incident_id: pendingResolveId })
        .then(res => {
          setLoading(resolveText, resolveSpinner, false);
          if (res.success) {
            bootstrap.Modal.getInstance(resolveModal)?.hide();
            // Update row in-place without full reload
            updateRowResolved(pendingResolveId);
            pendingResolveId = null;
          } else {
            showAlert(resolveAlert, res.message ?? 'Failed to resolve incident.');
          }
        })
        .catch(() => {
          setLoading(resolveText, resolveSpinner, false);
          showAlert(resolveAlert, 'Network error. Please try again.');
        });
    });
  }

  // ── Update row in DOM after resolve ──────────────────────────────────────
  function updateRowResolved(id) {
    if (!tableBody) return;

    const row = tableBody.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;

    // Update data attribute for filter
    row.dataset.status = 'resolved';

    // Swap status badge
    const badge = row.querySelector('.inc-status-badge');
    if (badge) {
      badge.className = 'inc-status-badge inc-status-resolved';
      badge.textContent = 'Resolved';
    }

    // Swap action cell
    const actionCell = row.querySelector('td:last-child');
    if (actionCell) {
      actionCell.innerHTML = `
        <span class="inc-resolved-at">
          <i class="bi bi-check-circle-fill text-success"></i>
        </span>`;
    }
  }

})();