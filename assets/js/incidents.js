/**
 * incidents.js — Mulawin FleetOps
 * Handles: log incident, client-side filtering, resolve with resolution notes.
 */

'use strict';

(function () {

  const BASE     = window.APP_BASE ?? '';
  const AJAX_URL = BASE + '/ajax/log_incident.php';

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const filterType    = document.getElementById('filterType');
  const filterStatus  = document.getElementById('filterStatus');
  const filterSearch  = document.getElementById('filterSearch');
  const tableBody     = document.querySelector('#incidentsTable tbody');
  const noResults     = document.getElementById('noIncidentResults');

  // Log modal
  const logModal      = document.getElementById('logIncidentModal');
  const tripSelect    = document.getElementById('tripSelect');
  const incidentType  = document.getElementById('incidentType');
  const incidentDesc  = document.getElementById('incidentDesc');
  const submitBtn     = document.getElementById('submitIncidentBtn');
  const submitText    = document.getElementById('submitBtnText');
  const submitSpinner = document.getElementById('submitBtnSpinner');
  const incFormAlert  = document.getElementById('incFormAlert');

  // Resolve modal
  const resolveModal    = document.getElementById('resolveModal');
  const resolveTripRef  = document.getElementById('resolveTripRef');
  const resolutionNotes = document.getElementById('resolutionNotes');
  const confirmResolve  = document.getElementById('confirmResolveBtn');
  const resolveText     = document.getElementById('resolveBtnText');
  const resolveSpinner  = document.getElementById('resolveBtnSpinner');
  const resolveAlert    = document.getElementById('resolveAlert');

  let pendingResolveId = null;

  // ── Filtering ─────────────────────────────────────────────────────────────
  function applyFilters() {
    if (!tableBody) return;
    const typeVal   = filterType?.value   ?? '';
    const statusVal = filterStatus?.value ?? '';
    const searchVal = (filterSearch?.value ?? '').toLowerCase();
    let visibleCount = 0;

    tableBody.querySelectorAll('tr[data-id]').forEach(row => {
      const matchType   = !typeVal   || row.dataset.type   === typeVal;
      const matchStatus = !statusVal || row.dataset.status === statusVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      const isMatch = matchType && matchStatus && matchSearch;
      row.classList.toggle('inc-row-hidden', !isMatch);
      if (isMatch) visibleCount++;
    });

    if (noResults) noResults.classList.toggle('d-none', visibleCount > 0);
  }

  filterType?.addEventListener('change', applyFilters);
  filterStatus?.addEventListener('change', applyFilters);
  filterSearch?.addEventListener('input', applyFilters);

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

  function setBusy(btn, spinner, busy) {
    if (!btn || !spinner) return;
    btn.disabled = busy;
    spinner.classList.toggle('d-none', !busy);
  }

  function postAjax(data) {
    return fetch(AJAX_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    new URLSearchParams(data),
    }).then(r => r.json());
  }

  // ── Reset log modal on close ──────────────────────────────────────────────
  logModal?.addEventListener('hidden.bs.modal', () => {
    if (tripSelect)   tripSelect.value   = '';
    if (incidentType) incidentType.value = '';
    if (incidentDesc) incidentDesc.value = '';
    hideAlert(incFormAlert);
    setBusy(submitBtn, submitSpinner, false);
  });

  // ── Submit: log incident ──────────────────────────────────────────────────
  submitBtn?.addEventListener('click', () => {
    hideAlert(incFormAlert);

    const tripId = tripSelect?.value          ?? '';
    const type   = incidentType?.value        ?? '';
    const desc   = incidentDesc?.value.trim() ?? '';

    if (!tripId || !type || !desc) {
      showAlert(incFormAlert, 'Please fill in all fields.');
      return;
    }

    setBusy(submitBtn, submitSpinner, true);

    postAjax({ action: 'log', trip_id: tripId, type, description: desc })
      .then(res => {
        setBusy(submitBtn, submitSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(logModal)?.hide();
          window.location.reload();
        } else {
          showAlert(incFormAlert, res.message ?? 'Failed to log incident.');
        }
      })
      .catch(() => {
        setBusy(submitBtn, submitSpinner, false);
        showAlert(incFormAlert, 'Network error. Please try again.');
      });
  });

  // ── Open resolve modal ────────────────────────────────────────────────────
  tableBody?.addEventListener('click', e => {
    const btn = e.target.closest('.btn-resolve');
    if (!btn) return;

    pendingResolveId = btn.dataset.id ?? null;
    if (resolveTripRef)  resolveTripRef.textContent = btn.dataset.trip ?? '';
    if (resolutionNotes) resolutionNotes.value = '';
    hideAlert(resolveAlert);
    setBusy(confirmResolve, resolveSpinner, false);

    new bootstrap.Modal(resolveModal).show();
  });

  // ── Reset resolve modal on close ──────────────────────────────────────────
  resolveModal?.addEventListener('hidden.bs.modal', () => {
    pendingResolveId = null;
    if (resolutionNotes) resolutionNotes.value = '';
    hideAlert(resolveAlert);
    setBusy(confirmResolve, resolveSpinner, false);
  });

  // ── Confirm resolve ───────────────────────────────────────────────────────
  confirmResolve?.addEventListener('click', () => {
    if (!pendingResolveId) return;

    hideAlert(resolveAlert);
    setBusy(confirmResolve, resolveSpinner, true);

    const notes = resolutionNotes?.value.trim() ?? '';

    postAjax({
      action:           'resolve',
      incident_id:      pendingResolveId,
      resolution_notes: notes,
    })
      .then(res => {
        setBusy(confirmResolve, resolveSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(resolveModal)?.hide();
          updateRowResolved(pendingResolveId, notes);
        } else {
          showAlert(resolveAlert, res.message ?? 'Failed to resolve incident.');
        }
      })
      .catch(() => {
        setBusy(confirmResolve, resolveSpinner, false);
        showAlert(resolveAlert, 'Network error. Please try again.');
      });
  });

  // ── Update row in DOM after resolve ──────────────────────────────────────
  function updateRowResolved(id, notes) {
    const row = tableBody?.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;

    row.dataset.status = 'resolved';

    // Status badge
    const badge = row.querySelector('.inc-status-badge');
    if (badge) {
      badge.className   = 'inc-status-badge inc-status-resolved';
      badge.textContent = 'Resolved';
    }

    // Append resolution note under description if provided
    if (notes) {
      const descCell = row.querySelector('.inc-desc-cell');
      if (descCell && !descCell.querySelector('.inc-resolution-note')) {
        const noteEl = document.createElement('span');
        noteEl.className = 'inc-resolution-note';
        noteEl.title     = 'Resolution: ' + notes;
        noteEl.innerHTML = `<i class="bi bi-check2-circle"></i> ${escapeHtml(notes)}`;
        descCell.appendChild(noteEl);
      }
    }

    // Swap action button → resolved icon
    const actionCell = row.querySelector('td:last-child');
    if (actionCell) {
      const today = new Date().toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
      actionCell.innerHTML = `
        <span class="inc-resolved-check" title="Resolved ${today}">
          <i class="bi bi-check-circle-fill text-success"></i>
        </span>`;
    }

    // Re-apply filters so row hides if "Open" is selected
    applyFilters();
  }

  function escapeHtml(str) {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

})();