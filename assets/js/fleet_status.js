// ============================================================
// assets/js/fleet_status.js
// Handles: filter buttons, live search, status modal,
//          add truck modal, edit truck modal
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  const BASE     = window.APP_BASE ?? '';
  const AJAX_URL = BASE + '/ajax/trucks_handler.php';
  const STATUS_URL = BASE + '/ajax/update_truck_status.php';

  // ── Filter + search ─────────────────────────────────────────────────────────
  const tbody       = document.getElementById('fleetBody');
  const rowCountEl  = document.getElementById('rowCount');
  const searchInput = document.getElementById('truckSearch');
  const filterBtns  = document.querySelectorAll('.filter-btn');
  const noResults   = document.getElementById('noFleetResults');

  let activeFilter = 'all';
  let searchTerm   = '';

  function updateRowCount() {
    if (!tbody || !rowCountEl) return;
    const visible = tbody.querySelectorAll('tr[data-status]:not(.hidden-row)').length;
    const total   = tbody.querySelectorAll('tr[data-status]').length;
    rowCountEl.textContent = visible === total
      ? `${total} truck${total !== 1 ? 's' : ''}`
      : `${visible} of ${total} trucks`;
    if (noResults) noResults.classList.toggle('d-none', visible > 0 || total === 0);
  }

  function applyFilters() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-status]').forEach(row => {
      const matchesFilter = activeFilter === 'all' || row.dataset.status === activeFilter;
      const matchesSearch = searchTerm === '' || (row.dataset.search ?? '').includes(searchTerm);
      row.classList.toggle('hidden-row', !(matchesFilter && matchesSearch));
    });
    updateRowCount();
  }

  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      filterBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      activeFilter = btn.dataset.filter || 'all';
      applyFilters();
    });
  });

  searchInput?.addEventListener('input', () => {
    searchTerm = searchInput.value.trim().toLowerCase();
    applyFilters();
  });

  updateRowCount();

  // ── Shared helpers ───────────────────────────────────────────────────────────
  function postAjax(data, url = AJAX_URL) {
    return fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    new URLSearchParams({ ...data, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
    }).then(r => r.json());
  }

  function showAlert(el, msg, type = 'danger') {
    if (!el) return;
    el.className = `alert alert-${type}`;
    el.textContent = msg;
    el.classList.remove('d-none');
  }

  function hideAlert(el) {
    if (!el) return;
    el.classList.add('d-none');
    el.textContent = '';
  }

  function setBusy(textEl, spinnerEl, busy) {
    if (!textEl || !spinnerEl) return;
    textEl.closest('button').disabled = busy;
    textEl.classList.toggle('d-none', busy);
    spinnerEl.classList.toggle('d-none', !busy);
  }

  // ── Status update modal (existing behavior preserved) ────────────────────────
  const statusModal      = document.getElementById('statusModal');
  const modalPlateEl     = document.getElementById('modalPlate');
  const modalTruckIdEl   = document.getElementById('modalTruckId');
  const modalStatusEl    = document.getElementById('modalStatus');
  const modalStatusReason = document.getElementById('modalStatusReason');
  const confirmBtn       = document.getElementById('confirmStatusBtn');
  const statusBtnText    = document.getElementById('statusBtnText');
  const statusBtnSpinner = document.getElementById('statusBtnSpinner');

  let bsStatusModal = statusModal ? new bootstrap.Modal(statusModal) : null;

  window.openStatusModal = function(truckId, plate, currentStatus) {
    if (!bsStatusModal) return;
    modalPlateEl.textContent  = plate;
    modalTruckIdEl.value      = truckId;
    modalStatusEl.value       = currentStatus;
    modalStatusEl.dataset.currentStatus = currentStatus;
    modalStatusReason.value   = '';
    bsStatusModal.show();
  };

  confirmBtn?.addEventListener('click', async () => {
    const truckId   = modalTruckIdEl.value;
    const newStatus = modalStatusEl.value;
    if (!truckId || !newStatus) return;
    const reason = modalStatusReason?.value.trim() ?? '';
    if (newStatus !== modalStatusEl.dataset.currentStatus && !reason) {
      alert('Enter a reason for the status change.');
      return;
    }

    setBusy(statusBtnText, statusBtnSpinner, true);
    try {
      const formData = new FormData();
      formData.append('truck_id', truckId);
      formData.append('status',   newStatus);
      formData.append('reason', reason);
      formData.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
      const res  = await fetch(BASE + '/ajax/update_truck_status.php', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        bsStatusModal.hide();
        window.location.reload();
      } else {
        alert('Error: ' + (data.message || 'Could not update status.'));
      }
    } catch {
      alert('Network error. Please try again.');
    } finally {
      setBusy(statusBtnText, statusBtnSpinner, false);
    }
  });

  const historyModalEl = document.getElementById('truckHistoryModal');
  const historyModal = historyModalEl ? new bootstrap.Modal(historyModalEl) : null;
  const historyBody = document.getElementById('truckHistoryBody');
  const historyPlate = document.getElementById('truckHistoryPlate');
  const historyAlert = document.getElementById('truckHistoryAlert');

  document.querySelectorAll('.truck-history-btn').forEach(button => {
    button.addEventListener('click', async () => {
      historyPlate.textContent = button.dataset.truckPlate || '';
      historyBody.replaceChildren();
      hideAlert(historyAlert);
      const loadingRow = document.createElement('tr');
      const loadingCell = document.createElement('td');
      loadingCell.colSpan = 4;
      loadingCell.textContent = 'Loading availability history…';
      loadingRow.append(loadingCell);
      historyBody.append(loadingRow);
      historyModal?.show();

      try {
        const response = await postAjax({
          action: 'history',
          truck_id: button.dataset.truckId || '',
        }, STATUS_URL);
        if (!response.success) {
          historyBody.replaceChildren();
          showAlert(historyAlert, response.message || 'Could not load truck history.');
          return;
        }
        historyBody.replaceChildren();
        if (!response.history?.length) {
          const row = document.createElement('tr');
          const cell = document.createElement('td');
          cell.colSpan = 4;
          cell.className = 'text-muted text-center';
          cell.textContent = 'No availability history is recorded.';
          row.append(cell);
          historyBody.append(row);
          return;
        }
        response.history.forEach(entry => {
          const row = document.createElement('tr');
          const values = [
            new Date(entry.changed_at.replace(' ', 'T')).toLocaleString(),
            entry.previous_status
              ? `${entry.previous_status} → ${entry.new_status}`
              : entry.new_status,
            entry.reason,
            entry.changed_by_name || 'System / migration',
          ];
          values.forEach(value => {
            const cell = document.createElement('td');
            cell.textContent = value || '—';
            row.append(cell);
          });
          historyBody.append(row);
        });
      } catch {
        historyBody.replaceChildren();
        showAlert(historyAlert, 'Network error. Please try again.');
      }
    });
  });

  // ── Add Truck modal ──────────────────────────────────────────────────────────
  const addModal      = document.getElementById('addTruckModal');
  const at_plate      = document.getElementById('at_plate');
  const at_brand      = document.getElementById('at_brand');
  const at_model      = document.getElementById('at_model');
  const at_year       = document.getElementById('at_year');
  const at_body       = document.getElementById('at_body');
  const at_fuel       = document.getElementById('at_fuel');
  const at_capacity   = document.getElementById('at_capacity');
  const at_chassis    = document.getElementById('at_chassis');
  const at_engine     = document.getElementById('at_engine');
  const at_unit_number = document.getElementById('at_unit_number');
  const at_truck_type = document.getElementById('at_truck_type');
  const at_mv_file = document.getElementById('at_mv_file');
  const at_registration_expiry = document.getElementById('at_registration_expiry');
  const at_insurance_provider = document.getElementById('at_insurance_provider');
  const at_insurance_policy = document.getElementById('at_insurance_policy');
  const at_insurance_expiry = document.getElementById('at_insurance_expiry');
  const at_warranty_expiry = document.getElementById('at_warranty_expiry');
  const at_images     = Object.fromEntries(['front', 'side', 'rear', 'top'].map(view => [view, document.getElementById(`at_image_${view}`)]));
  const submitAddBtn  = document.getElementById('submitAddTruckBtn');
  const atBtnText     = document.getElementById('atBtnText');
  const atBtnSpinner  = document.getElementById('atBtnSpinner');
  const addAlert      = document.getElementById('addTruckAlert');

  function setImagePreview(prefix, view, source = '') {
    const preview = document.getElementById(`${prefix}_image_preview_${view}`);
    if (!preview) return;
    if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
    preview.dataset.objectUrl = '';
    preview.src = source || `${BASE}/assets/images/inspection/demo-truck-${view}.svg`;
  }

  function bindImagePreviews(prefix, inputs) {
    Object.entries(inputs).forEach(([view, input]) => {
      input?.addEventListener('change', () => {
        const file = input.files?.[0];
        if (file) {
          const objectUrl = URL.createObjectURL(file);
          setImagePreview(prefix, view, objectUrl);
          const preview = document.getElementById(`${prefix}_image_preview_${view}`);
          if (preview) preview.dataset.objectUrl = objectUrl;
        }
      });
    });
  }

  function resetImageInputs(prefix, inputs) {
    Object.entries(inputs).forEach(([view, input]) => {
      if (input) input.value = '';
      setImagePreview(prefix, view);
    });
  }

  bindImagePreviews('at', at_images);

  addModal?.addEventListener('hidden.bs.modal', () => {
    [at_plate, at_brand, at_model, at_year, at_body, at_capacity, at_chassis, at_engine,
      at_unit_number, at_truck_type, at_mv_file, at_registration_expiry,
      at_insurance_provider, at_insurance_policy, at_insurance_expiry, at_warranty_expiry].forEach(el => {
      if (el) el.value = '';
    });
    resetImageInputs('at', at_images);
    if (at_fuel) at_fuel.value = 'Diesel';
    hideAlert(addAlert);
    setBusy(atBtnText, atBtnSpinner, false);
  });

  submitAddBtn?.addEventListener('click', () => {
    hideAlert(addAlert);

    const plate = at_plate?.value.trim() ?? '';
    const brand = at_brand?.value.trim() ?? '';
    const model = at_model?.value.trim() ?? '';
    const year  = at_year?.value         ?? '';

    if (!plate || !brand || !model || !year) {
      showAlert(addAlert, 'Plate number, brand, model, and year are required.');
      return;
    }

    setBusy(atBtnText, atBtnSpinner, true);

    const formData = new FormData();
    Object.entries({
      action: 'add', plate_number: plate, brand, model, year_model: year,
      body_type: at_body?.value.trim() ?? '', fuel_type: at_fuel?.value ?? 'Diesel',
      capacity_tons: at_capacity?.value ?? '', chassis_number: at_chassis?.value.trim() ?? '',
      engine_number: at_engine?.value.trim() ?? '',
      unit_number: at_unit_number?.value.trim() ?? '', truck_type: at_truck_type?.value.trim() ?? '',
      mv_file_number: at_mv_file?.value.trim() ?? '',
      registration_expiry: at_registration_expiry?.value ?? '',
      insurance_provider: at_insurance_provider?.value.trim() ?? '',
      insurance_policy_number: at_insurance_policy?.value.trim() ?? '',
      insurance_expiry: at_insurance_expiry?.value ?? '',
      warranty_expiry: at_warranty_expiry?.value ?? ''
    }).forEach(([key, value]) => formData.append(key, value));
    Object.entries(at_images).forEach(([view, input]) => {
      if (input?.files?.[0]) formData.append(`truck_images[${view}]`, input.files[0]);
    });
    formData.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
    fetch(AJAX_URL, { method: 'POST', body: formData }).then(r => r.json())
      .then(res => {
        setBusy(atBtnText, atBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(addModal)?.hide();
          window.location.reload();
        } else {
          showAlert(addAlert, res.message ?? 'Failed to add truck.');
        }
      })
      .catch(() => {
        setBusy(atBtnText, atBtnSpinner, false);
        showAlert(addAlert, 'Network error. Please try again.');
      });
  });

  // ── Edit Truck modal ─────────────────────────────────────────────────────────
  const editModal     = document.getElementById('editTruckModal');
  const et_id         = document.getElementById('et_id');
  const et_plate      = document.getElementById('et_plate');
  const et_brand      = document.getElementById('et_brand');
  const et_model      = document.getElementById('et_model');
  const et_year       = document.getElementById('et_year');
  const et_body       = document.getElementById('et_body');
  const et_fuel       = document.getElementById('et_fuel');
  const et_capacity   = document.getElementById('et_capacity');
  const et_chassis    = document.getElementById('et_chassis');
  const et_engine     = document.getElementById('et_engine');
  const et_unit_number = document.getElementById('et_unit_number');
  const et_truck_type = document.getElementById('et_truck_type');
  const et_mv_file = document.getElementById('et_mv_file');
  const et_registration_expiry = document.getElementById('et_registration_expiry');
  const et_insurance_provider = document.getElementById('et_insurance_provider');
  const et_insurance_policy = document.getElementById('et_insurance_policy');
  const et_insurance_expiry = document.getElementById('et_insurance_expiry');
  const et_warranty_expiry = document.getElementById('et_warranty_expiry');
  const et_images     = Object.fromEntries(['front', 'side', 'rear', 'top'].map(view => [view, document.getElementById(`et_image_${view}`)]));
  const et_status     = document.getElementById('et_status');
  const et_status_reason = document.getElementById('et_status_reason');
  const submitEditBtn = document.getElementById('submitEditTruckBtn');
  const etBtnText     = document.getElementById('etBtnText');
  const etBtnSpinner  = document.getElementById('etBtnSpinner');
  const editAlert     = document.getElementById('editTruckAlert');

  bindImagePreviews('et', et_images);

  // Populate edit modal from data attributes
  document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-edit-truck');
    if (!btn) return;

    if (et_id)       et_id.value       = btn.dataset.id       ?? '';
    if (et_plate)    et_plate.value    = btn.dataset.plate    ?? '';
    if (et_brand)    et_brand.value    = btn.dataset.brand    ?? '';
    if (et_model)    et_model.value    = btn.dataset.model    ?? '';
    if (et_year)     et_year.value     = btn.dataset.year     ?? '';
    if (et_body)     et_body.value     = btn.dataset.body     ?? '';
    if (et_fuel)     et_fuel.value     = btn.dataset.fuel     ?? 'Diesel';
    if (et_capacity) et_capacity.value = btn.dataset.capacity ?? '';
    if (et_chassis)  et_chassis.value  = btn.dataset.chassis  ?? '';
    if (et_engine)   et_engine.value   = btn.dataset.engine   ?? '';
    if (et_unit_number) et_unit_number.value = btn.dataset.unitNumber ?? '';
    if (et_truck_type) et_truck_type.value = btn.dataset.truckType ?? '';
    if (et_mv_file) et_mv_file.value = btn.dataset.mvFile ?? '';
    if (et_registration_expiry) et_registration_expiry.value = btn.dataset.registrationExpiry ?? '';
    if (et_insurance_provider) et_insurance_provider.value = btn.dataset.insuranceProvider ?? '';
    if (et_insurance_policy) et_insurance_policy.value = btn.dataset.insurancePolicy ?? '';
    if (et_insurance_expiry) et_insurance_expiry.value = btn.dataset.insuranceExpiry ?? '';
    if (et_warranty_expiry) et_warranty_expiry.value = btn.dataset.warrantyExpiry ?? '';
    if (et_status)   et_status.value   = btn.dataset.status   ?? 'Available';
    if (et_status_reason) et_status_reason.value = '';
    Object.entries(et_images).forEach(([view, input]) => {
      if (input) input.value = '';
      const viewName = view[0].toUpperCase() + view.slice(1);
      setImagePreview('et', view, btn.dataset[`image${viewName}`] ?? '');
    });

    hideAlert(editAlert);
    setBusy(etBtnText, etBtnSpinner, false);

    new bootstrap.Modal(editModal).show();
  });

  editModal?.addEventListener('hidden.bs.modal', () => {
    hideAlert(editAlert);
    setBusy(etBtnText, etBtnSpinner, false);
    if (et_status_reason) et_status_reason.value = '';
  });

  submitEditBtn?.addEventListener('click', () => {
    hideAlert(editAlert);

    const plate = et_plate?.value.trim() ?? '';
    const brand = et_brand?.value.trim() ?? '';
    const model = et_model?.value.trim() ?? '';
    const year  = et_year?.value         ?? '';

    if (!plate || !brand || !model || !year) {
      showAlert(editAlert, 'Plate number, brand, model, and year are required.');
      return;
    }

    setBusy(etBtnText, etBtnSpinner, true);

    const formData = new FormData();
    Object.entries({
      action: 'edit', truck_id: et_id?.value ?? '', plate_number: plate, brand, model,
      year_model: year, body_type: et_body?.value.trim() ?? '',
      fuel_type: et_fuel?.value ?? 'Diesel', capacity_tons: et_capacity?.value ?? '',
      chassis_number: et_chassis?.value.trim() ?? '', engine_number: et_engine?.value.trim() ?? '',
      unit_number: et_unit_number?.value.trim() ?? '', truck_type: et_truck_type?.value.trim() ?? '',
      mv_file_number: et_mv_file?.value.trim() ?? '',
      registration_expiry: et_registration_expiry?.value ?? '',
      insurance_provider: et_insurance_provider?.value.trim() ?? '',
      insurance_policy_number: et_insurance_policy?.value.trim() ?? '',
      insurance_expiry: et_insurance_expiry?.value ?? '',
      warranty_expiry: et_warranty_expiry?.value ?? '',
      status: et_status?.value ?? 'Available',
      status_reason: et_status_reason?.value.trim() ?? ''
    }).forEach(([key, value]) => formData.append(key, value));
    Object.entries(et_images).forEach(([view, input]) => {
      if (input?.files?.[0]) formData.append(`truck_images[${view}]`, input.files[0]);
    });
    formData.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
    fetch(AJAX_URL, { method: 'POST', body: formData }).then(r => r.json())
      .then(res => {
        setBusy(etBtnText, etBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(editModal)?.hide();
          window.location.reload();
        } else {
          showAlert(editAlert, res.message ?? 'Failed to update truck.');
        }
      })
      .catch(() => {
        setBusy(etBtnText, etBtnSpinner, false);
        showAlert(editAlert, 'Network error. Please try again.');
      });
  });

});