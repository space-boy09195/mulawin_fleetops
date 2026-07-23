/**
 * maintenance.js — Mulawin FleetOps
 * Handles: maintenance record form, checklist form, live result preview, filters.
 */

'use strict';

(function () {

  const BASE     = window.APP_BASE ?? '';
  const AJAX_URL = BASE + '/ajax/maintenance_handler.php';

  // ── Shared helper ─────────────────────────────────────────────────────────
  function postAjax(data) {
    return fetch(AJAX_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    new URLSearchParams(data),
    }).then(r => r.json());
  }

  function showAlert(el, html, type = 'danger') {
    if (!el) return;
    el.className = `alert alert-${type}`;
    el.innerHTML = html;
    el.classList.remove('d-none');
  }

  function hideAlert(el) {
    if (!el) return;
    el.classList.add('d-none');
    el.innerHTML = '';
  }

  function setBusy(btn, spinner, busy) {
    if (!btn || !spinner) return;
    btn.disabled = busy;
    spinner.classList.toggle('d-none', !busy);
  }

  // ── Filters: records tab ──────────────────────────────────────────────────
  const filterRecordType   = document.getElementById('filterRecordType');
  const filterRecordSearch = document.getElementById('filterRecordSearch');
  const recordsTbody       = document.querySelector('#recordsTable tbody');
  const noRecordResults    = document.getElementById('noRecordResults');

  function applyRecordFilters() {
    if (!recordsTbody) return;
    const typeVal   = filterRecordType?.value ?? '';
    const searchVal = (filterRecordSearch?.value ?? '').toLowerCase();
    let visibleCount = 0;

    recordsTbody.querySelectorAll('tr').forEach(row => {
      const matchType   = !typeVal   || row.dataset.type === typeVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      const isMatch = matchType && matchSearch;
      row.classList.toggle('mnt-row-hidden', !isMatch);
      if (isMatch) visibleCount++;
    });

    noRecordResults?.classList.toggle('d-none', visibleCount > 0);
  }

  filterRecordType?.addEventListener('change', applyRecordFilters);
  filterRecordSearch?.addEventListener('input', applyRecordFilters);

  // ── Filters: checklists tab ───────────────────────────────────────────────
  const filterCheckResult = document.getElementById('filterCheckResult');
  const filterCheckSearch = document.getElementById('filterCheckSearch');
  const checklistsTbody   = document.querySelector('#checklistsTable tbody');
  const noChecklistResults = document.getElementById('noChecklistResults');

  function applyChecklistFilters() {
    if (!checklistsTbody) return;
    const resultVal = filterCheckResult?.value ?? '';
    const searchVal = (filterCheckSearch?.value ?? '').toLowerCase();
    let visibleCount = 0;

    checklistsTbody.querySelectorAll('tr').forEach(row => {
      const matchResult = !resultVal || row.dataset.result === resultVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      const isMatch = matchResult && matchSearch;
      row.classList.toggle('mnt-row-hidden', !isMatch);
      if (isMatch) visibleCount++;
    });

    noChecklistResults?.classList.toggle('d-none', visibleCount > 0);
  }

  filterCheckResult?.addEventListener('change', applyChecklistFilters);
  filterCheckSearch?.addEventListener('input', applyChecklistFilters);

  // ══════════════════════════════════════════════════════════════════════════
  // MAINTENANCE RECORD FORM
  // ══════════════════════════════════════════════════════════════════════════
  const recordModal   = document.getElementById('recordModal');
  const recTruckId    = document.getElementById('recTruckId');
  const recType       = document.getElementById('recType');
  const recTruckStatus = document.getElementById('recTruckStatus');
  const recDescription = document.getElementById('recDescription');
  const recDatePerformed = document.getElementById('recDatePerformed');
  const recNextDue    = document.getElementById('recNextDue');
  const recCost       = document.getElementById('recCost');
  const recIncidentId = document.getElementById('recIncidentId');
  const submitRecordBtn = document.getElementById('submitRecordBtn');
  const recBtnSpinner = document.getElementById('recBtnSpinner');
  const recordFormAlert = document.getElementById('recordFormAlert');

  recordModal?.addEventListener('hidden.bs.modal', () => {
    [recTruckId, recType, recTruckStatus, recDescription, recNextDue, recIncidentId].forEach(el => {
      if (el) el.value = '';
    });
    if (recCost)         recCost.value         = '';
    if (recDatePerformed) recDatePerformed.value = new Date().toISOString().slice(0, 10);
    hideAlert(recordFormAlert);
    setBusy(submitRecordBtn, recBtnSpinner, false);
  });

  submitRecordBtn?.addEventListener('click', () => {
    hideAlert(recordFormAlert);

    const truckId      = recTruckId?.value          ?? '';
    const type         = recType?.value             ?? '';
    const truckStatus  = recTruckStatus?.value      ?? '';
    const description  = recDescription?.value.trim() ?? '';
    const datePerformed = recDatePerformed?.value   ?? '';
    const nextDue      = recNextDue?.value          ?? '';
    const cost         = recCost?.value             ?? '';
    const incidentId   = recIncidentId?.value       ?? '';

    if (!truckId || !type || !truckStatus || !description || !datePerformed) {
      showAlert(recordFormAlert, 'Please fill in all required fields.');
      return;
    }

    setBusy(submitRecordBtn, recBtnSpinner, true);

    postAjax({
      action:        'log_record',
      truck_id:      truckId,
      type,
      truck_status:  truckStatus,
      description,
      date_performed: datePerformed,
      next_due_date: nextDue,
      cost,
      incident_id:   incidentId,
    })
      .then(res => {
        setBusy(submitRecordBtn, recBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(recordModal)?.hide();
          window.location.reload();
        } else {
          showAlert(recordFormAlert, res.message ?? 'Failed to save record.');
        }
      })
      .catch(() => {
        setBusy(submitRecordBtn, recBtnSpinner, false);
        showAlert(recordFormAlert, 'Network error. Please try again.');
      });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // CHECKLIST FORM
  // ══════════════════════════════════════════════════════════════════════════
  const checklistModal    = document.getElementById('checklistModal');
  const clDispatchId      = document.getElementById('clDispatchId');
  const clNotes           = document.getElementById('clNotes');
  const submitChecklistBtn = document.getElementById('submitChecklistBtn');
  const clBtnSpinner      = document.getElementById('clBtnSpinner');
  const checklistFormAlert = document.getElementById('checklistFormAlert');
  const clResultBanner    = document.getElementById('clResultBanner');
  const checkboxes        = document.querySelectorAll('.mnt-check-cb');

  // Live result preview as checkboxes are toggled
  function updateResultBanner() {
    if (!clResultBanner) return;
    const total  = checkboxes.length;
    const passed = [...checkboxes].filter(cb => cb.checked).length;
    const allOk  = passed === total;

    clResultBanner.classList.remove('d-none', 'mnt-banner-pass', 'mnt-banner-fail');
    clResultBanner.classList.add(allOk ? 'mnt-banner-pass' : 'mnt-banner-fail');
    clResultBanner.innerHTML = allOk
      ? `<i class="bi bi-check-circle-fill me-2"></i>All items checked — <strong>PASSED</strong>`
      : `<i class="bi bi-x-circle-fill me-2"></i>${passed}/${total} items checked — <strong>FAILED</strong>`;
  }

  checkboxes.forEach(cb => cb.addEventListener('change', updateResultBanner));

  checklistModal?.addEventListener('show.bs.modal', updateResultBanner);

  checklistModal?.addEventListener('hidden.bs.modal', () => {
    if (clDispatchId) clDispatchId.value = '';
    if (clNotes)      clNotes.value      = '';
    checkboxes.forEach(cb => { cb.checked = false; });
    if (clResultBanner) clResultBanner.classList.add('d-none');
    hideAlert(checklistFormAlert);
    setBusy(submitChecklistBtn, clBtnSpinner, false);
  });

  submitChecklistBtn?.addEventListener('click', () => {
    hideAlert(checklistFormAlert);

    const dispatchId = clDispatchId?.value ?? '';
    if (!dispatchId) {
      showAlert(checklistFormAlert, 'Please select a dispatch.');
      return;
    }

    setBusy(submitChecklistBtn, clBtnSpinner, true);

    const payload = {
      action:      'submit_checklist',
      dispatch_id: dispatchId,
      notes:       clNotes?.value.trim() ?? '',
    };

    checkboxes.forEach(cb => {
      payload[cb.name] = cb.checked ? '1' : '0';
    });

    postAjax(payload)
      .then(res => {
        setBusy(submitChecklistBtn, clBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(checklistModal)?.hide();
          window.location.reload();
        } else {
          showAlert(checklistFormAlert, res.message ?? 'Failed to submit checklist.');
        }
      })
      .catch(() => {
        setBusy(submitChecklistBtn, clBtnSpinner, false);
        showAlert(checklistFormAlert, 'Network error. Please try again.');
      });
  });

})();