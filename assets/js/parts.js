/**
 * parts.js — Mulawin FleetOps
 * Handles: add part form, record movement form, client-side filters.
 */

'use strict';

(function () {

  const BASE     = window.APP_BASE ?? '';
  const AJAX_URL = BASE + '/ajax/parts_handler.php';

  // ── Helpers ───────────────────────────────────────────────────────────────
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

  // ── Toast feedback (persists across the page reload after a save) ───────────
  const ptsToast     = document.getElementById('ptsToast');
  const ptsToastMsg  = document.getElementById('ptsToastMsg');
  const ptsToastIcon = document.getElementById('ptsToastIcon');
  let toastTimer     = null;

  function showToast(message, type = 'success') {
    if (!ptsToast || !ptsToastMsg) return;
    ptsToast.classList.toggle('pts-toast-danger', type === 'danger');
    if (ptsToastIcon) {
      ptsToastIcon.className = type === 'danger'
        ? 'bi bi-exclamation-circle-fill me-2'
        : 'bi bi-check-circle-fill me-2';
    }
    ptsToastMsg.innerHTML = message;
    ptsToast.classList.remove('pts-toast-hidden');
    ptsToast.classList.add('pts-toast-visible');

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      ptsToast.classList.remove('pts-toast-visible');
      ptsToast.classList.add('pts-toast-hidden');
    }, 4000);
  }

  function queueToast(message, type = 'success') {
    try {
      sessionStorage.setItem('pts_toast', JSON.stringify({ message, type }));
    } catch { /* sessionStorage unavailable — skip silently */ }
  }

  // Show a queued toast left over from before the last reload, if any.
  (function showQueuedToast() {
    let raw;
    try { raw = sessionStorage.getItem('pts_toast'); } catch { return; }
    if (!raw) return;
    try { sessionStorage.removeItem('pts_toast'); } catch { /* ignore */ }
    try {
      const data = JSON.parse(raw);
      showToast(data.message, data.type);
    } catch { /* malformed — ignore */ }
  })();

  // ── Stock filter ──────────────────────────────────────────────────────────
  const filterCategory  = document.getElementById('filterCategory');
  const filterStock     = document.getElementById('filterStock');
  const filterPartSearch = document.getElementById('filterPartSearch');
  const stockTbody      = document.querySelector('#stockTable tbody');

  function applyStockFilters() {
    if (!stockTbody) return;
    const catVal    = filterCategory?.value   ?? '';
    const stockVal  = filterStock?.value      ?? '';
    const searchVal = (filterPartSearch?.value ?? '').toLowerCase();

    stockTbody.querySelectorAll('tr').forEach(row => {
      const matchCat    = !catVal    || row.dataset.category === catVal;
      const matchStock  = !stockVal  || row.dataset.low      === stockVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      row.classList.toggle('pts-row-hidden', !(matchCat && matchStock && matchSearch));
    });
  }

  filterCategory?.addEventListener('change', applyStockFilters);
  filterStock?.addEventListener('change', applyStockFilters);
  filterPartSearch?.addEventListener('input', applyStockFilters);

  // ── Movements filter ──────────────────────────────────────────────────────
  const filterMovType   = document.getElementById('filterMovType');
  const filterMovSearch = document.getElementById('filterMovSearch');
  const movTbody        = document.querySelector('#movementsTable tbody');

  function applyMovFilters() {
    if (!movTbody) return;
    const typeVal   = filterMovType?.value   ?? '';
    const searchVal = (filterMovSearch?.value ?? '').toLowerCase();

    movTbody.querySelectorAll('tr').forEach(row => {
      const matchType   = !typeVal   || row.dataset.movtype === typeVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      row.classList.toggle('pts-row-hidden', !(matchType && matchSearch));
    });
  }

  filterMovType?.addEventListener('change', applyMovFilters);
  filterMovSearch?.addEventListener('input', applyMovFilters);

  // ══════════════════════════════════════════════════════════════════════════
  // ADD PART FORM
  // ══════════════════════════════════════════════════════════════════════════
  const addPartModal    = document.getElementById('addPartModal');
  const apName          = document.getElementById('apName');
  const apPartNumber    = document.getElementById('apPartNumber');
  const apCategory      = document.getElementById('apCategory');
  const apUnit          = document.getElementById('apUnit');
  const apReorderLevel  = document.getElementById('apReorderLevel');
  const apUnitCost      = document.getElementById('apUnitCost');
  const apInitialQty    = document.getElementById('apInitialQty');
  const apSupplier      = document.getElementById('apSupplier');
  const submitAddPartBtn = document.getElementById('submitAddPartBtn');
  const apBtnSpinner    = document.getElementById('apBtnSpinner');
  const addPartAlert    = document.getElementById('addPartAlert');

  addPartModal?.addEventListener('hidden.bs.modal', () => {
    [apName, apPartNumber, apCategory, apUnit, apUnitCost, apSupplier].forEach(el => {
      if (el) el.value = el.id === 'apUnit' ? 'pcs' : '';
    });
    if (apReorderLevel) apReorderLevel.value = '5';
    if (apInitialQty)   apInitialQty.value   = '';
    hideAlert(addPartAlert);
    setBusy(submitAddPartBtn, apBtnSpinner, false);
  });

  submitAddPartBtn?.addEventListener('click', () => {
    hideAlert(addPartAlert);

    const name        = apName?.value.trim()        ?? '';
    const partNumber  = apPartNumber?.value.trim()  ?? '';
    const category    = apCategory?.value.trim()    ?? '';
    const unit        = apUnit?.value.trim()        ?? 'pcs';
    const reorderLevel = apReorderLevel?.value      ?? '5';
    const unitCost    = apUnitCost?.value           ?? '';
    const initialQty  = apInitialQty?.value         ?? '';
    const supplier    = apSupplier?.value.trim()    ?? '';

    if (!name || !category || !unit) {
      showAlert(addPartAlert, 'Part name, category, and unit are required.');
      return;
    }

    if (initialQty === '') {
      showAlert(addPartAlert, 'Initial quantity is required.');
      apInitialQty?.focus();
      return;
    }

    if (parseInt(initialQty, 10) < 0) {
      showAlert(addPartAlert, 'Initial quantity cannot be negative.');
      return;
    }

    setBusy(submitAddPartBtn, apBtnSpinner, true);

    postAjax({
      action:        'add_part',
      part_name:     name,
      part_number:   partNumber,
      category,
      unit,
      reorder_level: reorderLevel,
      unit_cost:     unitCost,
      initial_qty:   initialQty,
      supplier,
    })
      .then(res => {
        setBusy(submitAddPartBtn, apBtnSpinner, false);
        if (res.success) {
          queueToast(res.message || 'Part added successfully.', 'success');
          bootstrap.Modal.getInstance(addPartModal)?.hide();
          window.location.reload();
        } else {
          showAlert(addPartAlert, res.message ?? 'Failed to add part.');
        }
      })
      .catch(() => {
        setBusy(submitAddPartBtn, apBtnSpinner, false);
        showAlert(addPartAlert, 'Network error. Please try again.');
      });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // RECORD MOVEMENT FORM
  // ══════════════════════════════════════════════════════════════════════════
  const movementModal    = document.getElementById('movementModal');
  const movPartId        = document.getElementById('movPartId');
  const movType          = document.getElementById('movType');
  const movQty           = document.getElementById('movQty');
  const movUnitCost      = document.getElementById('movUnitCost');
  const movReference     = document.getElementById('movReference');
  const movNotes         = document.getElementById('movNotes');
  const movUnitLabel     = document.getElementById('movUnitLabel');
  const submitMovementBtn = document.getElementById('submitMovementBtn');
  const movBtnSpinner    = document.getElementById('movBtnSpinner');
  const movementAlert    = document.getElementById('movementAlert');

  // Show unit in qty label when part selected
  movPartId?.addEventListener('change', () => {
    const opt = movPartId.selectedOptions[0];
    if (movUnitLabel) {
      movUnitLabel.textContent = opt?.dataset.unit ? `(${opt.dataset.unit})` : '';
    }
  });

  // Hide unit cost field for non-Stock In
  movType?.addEventListener('change', () => {
    const costField = movUnitCost?.closest('.col-6');
    if (costField) {
      costField.style.opacity = movType.value === 'Stock In' ? '1' : '0.4';
    }
  });

  movementModal?.addEventListener('hidden.bs.modal', () => {
    [movPartId, movType, movQty, movUnitCost, movReference, movNotes].forEach(el => {
      if (el) el.value = '';
    });
    if (movUnitLabel) movUnitLabel.textContent = '';
    hideAlert(movementAlert);
    setBusy(submitMovementBtn, movBtnSpinner, false);
  });

  submitMovementBtn?.addEventListener('click', () => {
    hideAlert(movementAlert);

    const partId    = movPartId?.value  ?? '';
    const type      = movType?.value    ?? '';
    const qty       = movQty?.value     ?? '';
    const unitCost  = movUnitCost?.value ?? '';
    const reference = movReference?.value.trim() ?? '';
    const notes     = movNotes?.value.trim()     ?? '';

    if (!partId || !type || !qty || parseInt(qty) <= 0) {
      showAlert(movementAlert, 'Please select a part, type, and enter a positive quantity.');
      return;
    }

    setBusy(submitMovementBtn, movBtnSpinner, true);

    postAjax({
      action:           'record_movement',
      part_id:          partId,
      movement_type:    type,
      quantity:         qty,
      unit_cost:        unitCost,
      reference_number: reference,
      notes,
    })
      .then(res => {
        setBusy(submitMovementBtn, movBtnSpinner, false);
        if (res.success) {
          queueToast(res.message || 'Movement recorded successfully.', 'success');
          bootstrap.Modal.getInstance(movementModal)?.hide();
          window.location.reload();
        } else {
          showAlert(movementAlert, res.message ?? 'Failed to record movement.');
        }
      })
      .catch(() => {
        setBusy(submitMovementBtn, movBtnSpinner, false);
        showAlert(movementAlert, 'Network error. Please try again.');
      });
  });

})();