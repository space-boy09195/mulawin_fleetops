// ============================================================
// assets/js/dispatch.js
// Handles: filter, dispatch submit, approve/reject,
//          driver→helper exclusion, add/edit/toggle routes
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  const BASE          = window.APP_BASE ?? '';
  const DISPATCH_URL  = BASE + '/ajax/submit_dispatch.php';
  const REVIEW_URL    = BASE + '/ajax/review_dispatch.php';
  const ROUTES_URL    = BASE + '/ajax/routes_handler.php';

  // ── Helpers ─────────────────────────────────────────────────────────────────
  function postAjax(url, data) {
    return fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    new URLSearchParams(data),
    }).then(r => r.json());
  }

  function postForm(url, formData) {
    return fetch(url, { method: 'POST', body: formData }).then(r => r.json());
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
    const btn = textEl.closest('button');
    if (btn) btn.disabled = busy;
    textEl.classList.toggle('d-none', busy);
    spinnerEl.classList.toggle('d-none', !busy);
  }

  // ── Filter (requests tab) ────────────────────────────────────────────────────
  const filterBtns  = document.querySelectorAll('.filter-btn');
  const tbody       = document.getElementById('dispatchBody');
  const rowCountEl  = document.getElementById('rowCount');
  const noResults   = document.getElementById('noDispatchResults');
  let activeFilter  = 'all';

  function updateCount() {
    if (!tbody || !rowCountEl) return;
    const vis   = tbody.querySelectorAll('tr:not(.hidden-row)[data-status]').length;
    const total = tbody.querySelectorAll('tr[data-status]').length;
    rowCountEl.textContent = vis === total
      ? `${total} request${total !== 1 ? 's' : ''}`
      : `${vis} of ${total} requests`;

    if (noResults) noResults.classList.toggle('d-none', vis > 0);
  }

  function applyFilter() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-status]').forEach(row => {
      const match = activeFilter === 'all' || row.dataset.status === activeFilter;
      row.classList.toggle('hidden-row', !match);
    });
    updateCount();
  }

  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      filterBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      activeFilter = btn.dataset.filter || 'all';
      applyFilter();
    });
  });
  updateCount();

  // ── Driver → Helper exclusion ────────────────────────────────────────────────
  const driverSel = document.getElementById('d_driver');
  const helperSel = document.getElementById('d_helper');

  function syncHelperOptions() {
    if (!driverSel || !helperSel) return;
    const selectedDriverId = driverSel.value;

    Array.from(helperSel.options).forEach(opt => {
      if (opt.value === '') return; // keep "— None —"
      opt.hidden   = opt.value === selectedDriverId;
      opt.disabled = opt.value === selectedDriverId;
    });

    // If current helper selection is now the driver, reset helper
    if (helperSel.value === selectedDriverId) {
      helperSel.value = '';
    }
  }

  driverSel?.addEventListener('change', syncHelperOptions);
  syncHelperOptions(); // run on load in case of pre-selection

  // ── Submit new dispatch request ──────────────────────────────────────────────
  const submitDispatchBtn = document.getElementById('submitDispatchBtn');
  const dispatchFormError = document.getElementById('dispatchFormError');
  const newDispatchModal  = document.getElementById('newDispatchModal');

  newDispatchModal?.addEventListener('hidden.bs.modal', () => {
    hideAlert(dispatchFormError);
    if (driverSel)  driverSel.value  = '';
    if (helperSel)  helperSel.value  = '';
    const route     = document.getElementById('d_route');
    const truck     = document.getElementById('d_truck');
    const scheduled = document.getElementById('d_scheduled');
    const remarks   = document.getElementById('d_remarks');
    if (route)     route.value     = '';
    if (truck)     truck.value     = '';
    if (scheduled) scheduled.value = '';
    if (remarks)   remarks.value   = '';
    syncHelperOptions();
  });

  submitDispatchBtn?.addEventListener('click', async () => {
    hideAlert(dispatchFormError);

    const truck     = document.getElementById('d_truck')?.value      ?? '';
    const route     = document.getElementById('d_route')?.value      ?? '';
    const driver    = driverSel?.value                               ?? '';
    const helper    = helperSel?.value                               ?? '';
    const scheduled = document.getElementById('d_scheduled')?.value  ?? '';
    const remarks   = document.getElementById('d_remarks')?.value.trim() ?? '';

    if (!truck || !route || !driver || !scheduled) {
      showAlert(dispatchFormError, 'Please fill in all required fields (Truck, Route, Driver, Scheduled Departure).');
      return;
    }

    const btnText    = document.getElementById('dispatchBtnText');
    const btnSpinner = document.getElementById('dispatchBtnSpinner');
    setBusy(btnText, btnSpinner, true);

    try {
      const fd = new FormData();
      fd.append('truck_id',     truck);
      fd.append('route_id',     route);
      fd.append('driver_id',    driver);
      fd.append('helper_id',    helper);
      fd.append('scheduled_at', scheduled);
      fd.append('remarks',      remarks);

      const result = await postForm(DISPATCH_URL, fd);

      if (result.success) {
        window.location.reload();
      } else {
        showAlert(dispatchFormError, result.message || 'Could not submit request.');
      }
    } catch {
      showAlert(dispatchFormError, 'Network error. Please try again.');
    } finally {
      setBusy(btnText, btnSpinner, false);
    }
  });

  // ── Approve dispatch ─────────────────────────────────────────────────────────
  window.reviewDispatch = async function(dispatchId, status) {
    if (!confirm(`${status === 'Approved' ? 'Approve' : 'Reject'} this dispatch request?`)) return;
    try {
      const fd = new FormData();
      fd.append('dispatch_id', dispatchId);
      fd.append('status',      status);
      fd.append('remarks',     '');
      const result = await postForm(REVIEW_URL, fd);
      if (result.success) window.location.reload();
      else alert('Error: ' + (result.message || 'Could not update.'));
    } catch { alert('Network error.'); }
  };

  // ── Reject dispatch ──────────────────────────────────────────────────────────
  const rejectModal   = document.getElementById('rejectModal');
  const rejectIdEl    = document.getElementById('rejectDispatchId');
  const rejectRmks    = document.getElementById('rejectRemarks');
  const confirmReject = document.getElementById('confirmRejectBtn');
  let bsRejectModal   = rejectModal ? new bootstrap.Modal(rejectModal) : null;

  window.openRejectModal = function(dispatchId) {
    if (!bsRejectModal) return;
    rejectIdEl.value = dispatchId;
    rejectRmks.value = '';
    bsRejectModal.show();
  };

  confirmReject?.addEventListener('click', async () => {
    const remarks = rejectRmks.value.trim();
    if (!remarks) { alert('Please provide a reason for rejection.'); return; }
    try {
      const fd = new FormData();
      fd.append('dispatch_id', rejectIdEl.value);
      fd.append('status',      'Rejected');
      fd.append('remarks',     remarks);
      const result = await postForm(REVIEW_URL, fd);
      if (result.success) { bsRejectModal.hide(); window.location.reload(); }
      else alert('Error: ' + (result.message || 'Could not reject.'));
    } catch { alert('Network error.'); }
  });

  // ══════════════════════════════════════════════════════════════════════════
  // ROUTE MANAGEMENT
  // ══════════════════════════════════════════════════════════════════════════

  // ── Map preview helpers (Google Maps Embed, no API key) ──────────────────────
  function mapPinSrc(place) {
    return 'https://maps.google.com/maps?q=' + encodeURIComponent(place) + '&output=embed';
  }
  function mapRouteSrc(origin, destination) {
    return 'https://maps.google.com/maps?saddr=' + encodeURIComponent(origin) +
           '&daddr=' + encodeURIComponent(destination) + '&output=embed';
  }

  // Wires up a pair of origin/destination inputs to their preview iframes.
  // prefix e.g. 'ar' or 'er' -> expects #{prefix}_origin_map, #{prefix}_destination_map,
  // #{prefix}_route_map and matching *_placeholder elements.
  function wireMapPreview(prefix, originInput, destinationInput) {
    const originFrame      = document.getElementById(prefix + '_origin_map');
    const originPlaceholder = document.getElementById(prefix + '_origin_map_placeholder');
    const destFrame         = document.getElementById(prefix + '_destination_map');
    const destPlaceholder   = document.getElementById(prefix + '_destination_map_placeholder');
    const routeFrame        = document.getElementById(prefix + '_route_map');
    const routePlaceholder  = document.getElementById(prefix + '_route_map_placeholder');

    let debounceTimer = null;

    function updateSingle(input, frame, placeholder) {
      const val = input?.value.trim() ?? '';
      if (val.length < 3) {
        frame?.classList.add('d-none');
        placeholder?.classList.remove('d-none');
        if (frame) frame.src = '';
        return;
      }
      if (frame) frame.src = mapPinSrc(val);
      frame?.classList.remove('d-none');
      placeholder?.classList.add('d-none');
    }

    function updateRoute() {
      const origin      = originInput?.value.trim() ?? '';
      const destination  = destinationInput?.value.trim() ?? '';
      if (origin.length < 3 || destination.length < 3) {
        routeFrame?.classList.add('d-none');
        routePlaceholder?.classList.remove('d-none');
        if (routeFrame) routeFrame.src = '';
        return;
      }
      if (routeFrame) routeFrame.src = mapRouteSrc(origin, destination);
      routeFrame?.classList.remove('d-none');
      routePlaceholder?.classList.add('d-none');
    }

    function refreshAll() {
      updateSingle(originInput, originFrame, originPlaceholder);
      updateSingle(destinationInput, destFrame, destPlaceholder);
      updateRoute();
    }

    function scheduleRefresh() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(refreshAll, 600);
    }

    originInput?.addEventListener('input', scheduleRefresh);
    destinationInput?.addEventListener('input', scheduleRefresh);

    return { refreshAll, reset: () => {
      clearTimeout(debounceTimer);
      [originFrame, destFrame, routeFrame].forEach(f => { if (f) { f.src = ''; f.classList.add('d-none'); } });
      [originPlaceholder, destPlaceholder, routePlaceholder].forEach(p => p?.classList.remove('d-none'));
    }};
  }

  // ── Add Route ────────────────────────────────────────────────────────────────
  const addRouteModal = document.getElementById('addRouteModal');
  const ar_name       = document.getElementById('ar_name');
  const ar_origin     = document.getElementById('ar_origin');
  const ar_destination = document.getElementById('ar_destination');
  const ar_distance   = document.getElementById('ar_distance');
  const submitAddRoute = document.getElementById('submitAddRouteBtn');
  const arBtnText     = document.getElementById('arBtnText');
  const arBtnSpinner  = document.getElementById('arBtnSpinner');
  const addRouteAlert = document.getElementById('addRouteAlert');

  const arMapPreview = wireMapPreview('ar', ar_origin, ar_destination);

  addRouteModal?.addEventListener('hidden.bs.modal', () => {
    [ar_name, ar_origin, ar_destination, ar_distance].forEach(el => { if (el) el.value = ''; });
    hideAlert(addRouteAlert);
    setBusy(arBtnText, arBtnSpinner, false);
    arMapPreview.reset();
  });

  submitAddRoute?.addEventListener('click', () => {
    hideAlert(addRouteAlert);

    const name        = ar_name?.value.trim()        ?? '';
    const origin      = ar_origin?.value.trim()      ?? '';
    const destination = ar_destination?.value.trim() ?? '';
    const distance    = ar_distance?.value           ?? '';

    if (!name || !origin || !destination) {
      showAlert(addRouteAlert, 'Route name, origin, and destination are required.');
      return;
    }

    setBusy(arBtnText, arBtnSpinner, true);

    postAjax(ROUTES_URL, {
      action:      'add',
      route_name:  name,
      origin,
      destination,
      distance_km: distance,
    })
      .then(res => {
        setBusy(arBtnText, arBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(addRouteModal)?.hide();
          window.location.reload();
        } else {
          showAlert(addRouteAlert, res.message ?? 'Failed to add route.');
        }
      })
      .catch(() => {
        setBusy(arBtnText, arBtnSpinner, false);
        showAlert(addRouteAlert, 'Network error. Please try again.');
      });
  });

  // ── Edit Route ───────────────────────────────────────────────────────────────
  const editRouteModal  = document.getElementById('editRouteModal');
  const er_id           = document.getElementById('er_id');
  const er_name         = document.getElementById('er_name');
  const er_origin       = document.getElementById('er_origin');
  const er_destination  = document.getElementById('er_destination');
  const er_distance     = document.getElementById('er_distance');
  const submitEditRoute = document.getElementById('submitEditRouteBtn');
  const erBtnText       = document.getElementById('erBtnText');
  const erBtnSpinner    = document.getElementById('erBtnSpinner');
  const editRouteAlert  = document.getElementById('editRouteAlert');

  const erMapPreview = wireMapPreview('er', er_origin, er_destination);

  document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-edit-route');
    if (!btn) return;

    if (er_id)          er_id.value          = btn.dataset.id          ?? '';
    if (er_name)        er_name.value        = btn.dataset.name        ?? '';
    if (er_origin)      er_origin.value      = btn.dataset.origin      ?? '';
    if (er_destination) er_destination.value = btn.dataset.destination ?? '';
    if (er_distance)    er_distance.value    = btn.dataset.distance    ?? '';

    hideAlert(editRouteAlert);
    setBusy(erBtnText, erBtnSpinner, false);
    erMapPreview.refreshAll();
    new bootstrap.Modal(editRouteModal).show();
  });

  editRouteModal?.addEventListener('hidden.bs.modal', () => {
    hideAlert(editRouteAlert);
    setBusy(erBtnText, erBtnSpinner, false);
    erMapPreview.reset();
  });

  submitEditRoute?.addEventListener('click', () => {
    hideAlert(editRouteAlert);

    const name        = er_name?.value.trim()        ?? '';
    const origin      = er_origin?.value.trim()      ?? '';
    const destination = er_destination?.value.trim() ?? '';
    const distance    = er_distance?.value           ?? '';

    if (!name || !origin || !destination) {
      showAlert(editRouteAlert, 'Route name, origin, and destination are required.');
      return;
    }

    setBusy(erBtnText, erBtnSpinner, true);

    postAjax(ROUTES_URL, {
      action:      'edit',
      route_id:    er_id?.value ?? '',
      route_name:  name,
      origin,
      destination,
      distance_km: distance,
    })
      .then(res => {
        setBusy(erBtnText, erBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(editRouteModal)?.hide();
          window.location.reload();
        } else {
          showAlert(editRouteAlert, res.message ?? 'Failed to update route.');
        }
      })
      .catch(() => {
        setBusy(erBtnText, erBtnSpinner, false);
        showAlert(editRouteAlert, 'Network error. Please try again.');
      });
  });

  // ── View Route (read-only, all roles) ────────────────────────────────────────
  const viewRouteModal    = document.getElementById('viewRouteModal');
  const vr_origin         = document.getElementById('vr_origin');
  const vr_destination    = document.getElementById('vr_destination');
  const vr_title          = document.getElementById('vr_title');
  const vr_origin_text    = document.getElementById('vr_origin_text');
  const vr_destination_text = document.getElementById('vr_destination_text');
  const vr_distance_text  = document.getElementById('vr_distance_text');

  const vrMapPreview = wireMapPreview('vr', vr_origin, vr_destination);

  document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-view-route');
    if (!btn) return;

    const name        = btn.dataset.name        ?? '';
    const origin      = btn.dataset.origin      ?? '';
    const destination  = btn.dataset.destination ?? '';
    const distance     = btn.dataset.distance    ?? '';

    if (vr_title)       vr_title.textContent = name || 'Route Preview';
    if (vr_origin_text) vr_origin_text.textContent = origin;
    if (vr_destination_text) vr_destination_text.textContent = destination;
    if (vr_distance_text) vr_distance_text.textContent = distance ? `${distance} km` : '—';
    if (vr_origin)      vr_origin.value      = origin;
    if (vr_destination) vr_destination.value = destination;

    vrMapPreview.refreshAll();
    new bootstrap.Modal(viewRouteModal).show();
  });

  viewRouteModal?.addEventListener('hidden.bs.modal', () => {
    vrMapPreview.reset();
  });

  // ── Toggle Route active/inactive ─────────────────────────────────────────────
  document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-toggle-route');
    if (!btn) return;

    const routeId  = btn.dataset.id;
    const isActive = btn.dataset.active === '1';
    const action   = isActive ? 'deactivate' : 'activate';

    if (!confirm(`Are you sure you want to ${action} this route?`)) return;

    postAjax(ROUTES_URL, { action: 'toggle', route_id: routeId })
      .then(res => {
        if (res.success) window.location.reload();
        else alert('Error: ' + (res.message ?? 'Could not toggle route.'));
      })
      .catch(() => alert('Network error. Please try again.'));
  });

});