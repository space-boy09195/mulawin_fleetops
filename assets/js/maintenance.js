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
  const recInspectionId = document.getElementById('recInspectionId');
  const submitRecordBtn = document.getElementById('submitRecordBtn');
  const recBtnSpinner = document.getElementById('recBtnSpinner');
  const recordFormAlert = document.getElementById('recordFormAlert');

  recordModal?.addEventListener('hidden.bs.modal', () => {
    [recTruckId, recType, recTruckStatus, recDescription, recNextDue, recIncidentId, recInspectionId].forEach(el => {
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
    const inspectionId = recInspectionId?.value     ?? '';

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
      inspection_id: inspectionId,
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

  // ── Description expand (Records tab) ─────────────────────────────────────────
  const descModalEl = document.getElementById('descModal');
  const descModal    = descModalEl ? new bootstrap.Modal(descModalEl) : null;

  document.querySelectorAll('.mnt-desc-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('descModalTruck').textContent = btn.dataset.truck || '';
      document.getElementById('descModalType').textContent  = btn.dataset.type  || '';
      document.getElementById('descModalText').textContent  = btn.dataset.description || '';
      descModal?.show();
    });
  });

  recTruckId?.addEventListener('change', () => {
    recInspectionId?.querySelectorAll('option[data-truck-id]').forEach(option => {
      option.hidden = option.dataset.truckId !== recTruckId.value;
      if (option.hidden && option.selected) recInspectionId.value = '';
    });
  });

  document.querySelectorAll('.inspection-result-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const result = JSON.parse(btn.dataset.inspection || '{}');
      document.getElementById('descModalTruck').textContent = 'Vehicle Inspection';
      document.getElementById('descModalType').textContent = result.date || '';
      document.getElementById('descModalText').textContent =
        `${result.notes ? `Overall notes: ${result.notes}\n` : ''}` +
        (result.findings || []).map(f => `${f.view_name} — ${f.part_name}: ${f.condition}${f.notes ? ` (${f.notes})` : ''}`).join('\n');
      descModal?.show();
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // INTERACTIVE VEHICLE INSPECTION
  // ══════════════════════════════════════════════════════════════════════════
  const inspectionTruck = document.getElementById('inspectionTruck');
  const inspectionDate = document.getElementById('inspectionDate');
  const inspectionNotes = document.getElementById('inspectionNotes');
  const inspectionDiagram = document.getElementById('inspectionDiagram');
  const inspectionParts = document.getElementById('inspectionParts');
  const inspectionAlert = document.getElementById('inspectionFormAlert');
  const inspectionBodyLabel = document.getElementById('inspectionBodyLabel');
  const inspectionImageMap = JSON.parse(inspectionDiagram?.dataset.imageMap || '{}');
  const inspectionState = new Map();
  const inspectionConditions = ['Not Checked', 'Good', 'Needs Attention', 'Damaged', 'Missing', 'Leaking', 'Worn'];
  const inspectionPartsByView = {
    Front: ['Windshield', 'Left Headlight', 'Right Headlight', 'Front Bumper', 'Left Front Tire', 'Right Front Tire'],
    Side: ['Left Mirror', 'Driver Door', 'Cargo Body', 'Fuel Tank', 'Left Rear Tire', 'Exhaust'],
    Rear: ['Rear Doors', 'Left Tail Light', 'Right Tail Light', 'Rear Bumper', 'Left Rear Tire', 'Right Rear Tire'],
    Top: ['Cab Roof', 'Cargo Roof', 'Left Side Panel', 'Right Side Panel', 'Fuel Tank', 'Rear Undercarriage'],
  };
  const bodyParts = {
    'Closed Van': { Front: 'cab-front', Side: 'van-side', Rear: 'van-rear', Top: 'van-top' },
    'Flatbed': { Front: 'cab-front', Side: 'flatbed-side', Rear: 'flatbed-rear', Top: 'flatbed-top' },
    'Reefer': { Front: 'cab-front reefer-front', Side: 'reefer-side', Rear: 'reefer-rear', Top: 'reefer-top' },
    'Wing Van': { Front: 'cab-front', Side: 'van-side wing-van-side', Rear: 'van-rear', Top: 'van-top' },
    'Carrier': { Front: 'cab-front', Side: 'flatbed-side carrier-side', Rear: 'flatbed-rear', Top: 'flatbed-top' },
  };

  function inspectionKey(view, part) { return `${view}:${part}`; }
  function vehicleIllustration(view, body) {
    const cargo = body === 'Flatbed'
      ? '<rect x="360" y="145" width="300" height="82" rx="8" class="vector-deck"/><path d="M370 145h280" class="vector-rail"/>'
      : `<rect x="360" y="90" width="300" height="137" rx="12" class="vector-cargo ${body === 'Reefer' ? 'vector-reefer' : ''}"/>`;
    if (view === 'Front') {
      return `<svg viewBox="0 0 900 300" role="img" aria-label="${body} front view">
        <rect width="900" height="300" class="vector-ground"/>
        <rect x="330" y="50" width="240" height="190" rx="28" class="vector-cab"/>
        <rect x="375" y="75" width="150" height="58" rx="10" class="vector-glass"/>
        <circle cx="365" cy="180" r="22" class="vector-lamp"/><circle cx="535" cy="180" r="22" class="vector-lamp"/>
        <rect x="355" y="215" width="190" height="20" rx="8" class="vector-bumper"/>
      </svg>`;
    }
    if (view === 'Rear') {
      return `<svg viewBox="0 0 900 300" role="img" aria-label="${body} rear view">
        <rect width="900" height="300" class="vector-ground"/>
        <rect x="260" y="55" width="380" height="175" rx="16" class="vector-cargo ${body === 'Reefer' ? 'vector-reefer' : ''}"/>
        <rect x="295" y="82" width="145" height="120" class="vector-door"/><rect x="460" y="82" width="145" height="120" class="vector-door"/>
        <circle cx="290" cy="190" r="18" class="vector-lamp"/><circle cx="610" cy="190" r="18" class="vector-lamp"/>
        <rect x="285" y="215" width="330" height="18" rx="8" class="vector-bumper"/>
      </svg>`;
    }
    if (view === 'Top') {
      return `<svg viewBox="0 0 900 300" role="img" aria-label="${body} top view">
        <rect width="900" height="300" class="vector-ground"/>
        <path d="M235 45h130l35 35h280c18 0 30 14 30 32v76c0 18-12 32-30 32H400l-35 35H235c-16 0-28-12-28-28V73c0-16 12-28 28-28z" class="vector-top-body"/>
        <path d="M255 70h95v160h-95z" class="vector-top-cab"/>
        <path d="M390 90h265M390 125h265M390 160h265M390 195h265" class="vector-top-lines"/>
        <circle cx="230" cy="85" r="9" class="vector-top-detail"/><circle cx="230" cy="215" r="9" class="vector-top-detail"/>
      </svg>`;
    }
    return `<svg viewBox="0 0 900 300" role="img" aria-label="${body} side view">
      <rect width="900" height="300" class="vector-ground"/>
      <path d="M150 220h600" class="vector-road"/>
      <path d="M190 220V145l55-50h130v125z" class="vector-cab"/>
      <rect x="255" y="116" width="86" height="45" rx="8" class="vector-glass"/>
      ${cargo}
      <circle cx="270" cy="220" r="30" class="vector-wheel"/><circle cx="270" cy="220" r="12" class="vector-hub"/>
      <circle cx="565" cy="220" r="30" class="vector-wheel"/><circle cx="565" cy="220" r="12" class="vector-hub"/>
      <circle cx="640" cy="220" r="30" class="vector-wheel"/><circle cx="640" cy="220" r="12" class="vector-hub"/>
    </svg>`;
  }
  function renderInspection() {
    if (!inspectionDiagram || !inspectionParts) return;
    const view = document.querySelector('.inspection-view.active')?.dataset.view || 'Front';
    const body = inspectionTruck?.selectedOptions[0]?.dataset.body || 'Closed Van';
    const configuredBody = bodyParts[body] ? body : 'Closed Van';
    const shape = bodyParts[configuredBody];
    inspectionBodyLabel.textContent = body;
    inspectionDiagram.className = `inspection-diagram ${shape[view] || ''}`;
    const imageUrl = inspectionImageMap[body]?.[view] || '';
    inspectionDiagram.innerHTML = imageUrl
      ? `<img class="inspection-custom-image" src="${imageUrl}" alt="${body} ${view} view">`
      : vehicleIllustration(view, body);
    inspectionParts.innerHTML = inspectionPartsByView[view].map(part => {
      const state = inspectionState.get(inspectionKey(view, part)) || { condition: 'Not Checked', notes: '' };
      return `<div class="inspection-part-card" data-part="${part}">
        <button type="button" class="inspection-part-btn"><i class="bi bi-geo-alt me-1"></i>${part}</button>
        <select class="form-select form-select-sm inspection-condition">
          ${inspectionConditions.map(value => `<option ${value === state.condition ? 'selected' : ''}>${value}</option>`).join('')}
        </select>
        <input class="form-control form-control-sm inspection-part-notes" value="${state.notes.replace(/"/g, '&quot;')}" placeholder="Optional note">
      </div>`;
    }).join('');
    inspectionParts.querySelectorAll('.inspection-part-card').forEach(card => {
      const part = card.dataset.part;
      const key = inspectionKey(view, part);
      const save = () => inspectionState.set(key, {
        condition: card.querySelector('.inspection-condition').value,
        notes: card.querySelector('.inspection-part-notes').value.trim(),
      });
      card.querySelector('.inspection-part-btn').addEventListener('click', () => card.classList.toggle('selected'));
      card.querySelector('.inspection-condition').addEventListener('change', save);
      card.querySelector('.inspection-part-notes').addEventListener('input', save);
    });
  }
  inspectionTruck?.addEventListener('change', renderInspection);
  document.querySelectorAll('.inspection-view').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('.inspection-view').forEach(item => item.classList.remove('active'));
    button.classList.add('active');
    renderInspection();
  }));
  document.getElementById('inspectionModal')?.addEventListener('show.bs.modal', renderInspection);
  document.getElementById('submitInspectionBtn')?.addEventListener('click', () => {
    hideAlert(inspectionAlert);
    if (!inspectionTruck?.value) {
      showAlert(inspectionAlert, 'Please select a vehicle.');
      return;
    }
    const findings = [];
    inspectionState.forEach((finding, key) => {
      const separator = key.indexOf(':');
      findings.push({ view: key.slice(0, separator), part: key.slice(separator + 1), ...finding });
    });
    postAjax({
      action: 'save_inspection',
      truck_id: inspectionTruck.value,
      inspection_date: inspectionDate.value,
      notes: inspectionNotes.value.trim(),
      findings: JSON.stringify(findings),
    }).then(res => {
      if (res.success) window.location.reload();
      else showAlert(inspectionAlert, res.message || 'Could not save inspection.');
    }).catch(() => showAlert(inspectionAlert, 'Network error. Please try again.'));
  });
  renderInspection();

})();