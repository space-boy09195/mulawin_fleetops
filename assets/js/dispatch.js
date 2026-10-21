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
  const ROUTE_REQUEST_URL = BASE + '/ajax/route_request_handler.php';

  // ── Helpers ─────────────────────────────────────────────────────────────────
  function postAjax(url, data) {
    const scope = `${url}:${data.dispatch_id ?? data.route_id ?? data.action ?? 'create'}`;
    const request = fetch(url, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        ...window.fleetOpsIdempotencyHeaders(scope),
      },
      body:    new URLSearchParams({ ...data, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
    }).then(async response => {
      const text = await response.text();
      try {
        const result = JSON.parse(text);
        if (result.success || response.status === 409) {
          window.fleetOpsCompleteIdempotency(scope);
        }
        return result;
      } catch {
        throw new Error(`Server returned HTTP ${response.status} instead of JSON.`);
      }
    });
    return request;
  }

  // Admin override reason for HTTP 422 replies. Returns the trimmed reason,
  // or null when cancelled or invalid (the server validates it again).
  function askOverrideReason(serverMessage) {
    const value = window.prompt(
      `${serverMessage || 'This action needs an Admin override reason.'}\n\nAdmin override reason (required, up to 500 characters):`
    );
    if (value === null) return null;
    const reason = value.trim();
    if (!reason || Array.from(reason).length > 500) {
      alert('An Admin override reason of 1 to 500 characters is required. Nothing was saved.');
      return null;
    }
    return reason;
  }

  function postForm(url, formData) {
    formData.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
    const scope = `${url}:${formData.get('dispatch_id') ?? formData.get('route_id') ?? 'create'}`;
    const send = () => fetch(url, {
      method: 'POST',
      // Same scope, so a reason retry reuses the same idempotency key.
      headers: window.fleetOpsIdempotencyHeaders(scope),
      body: formData,
    }).then(async response => {
      const result = await response.json();
      // HTTP 422: Admin override reason required. Ask once and retry once.
      if (response.status === 422 && !formData.has('override_reason')) {
        const reason = askOverrideReason(result.message);
        if (reason === null) {
          return { success: false, message: 'Cancelled: no Admin override reason was given. Nothing was saved.' };
        }
        formData.append('override_reason', reason);
        return send();
      }
      if (result.success || response.status === 409) {
        window.fleetOpsCompleteIdempotency(scope);
      }
      return result;
    });
    return send();
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
  const secondDriverSel = document.getElementById('d_second_driver');
  const helperSel = document.getElementById('d_helper');
  const routeInput = document.getElementById('d_route');
  const routeIdInput = document.getElementById('d_route_id');
  const driverIdInput = document.getElementById('d_driver_id');
  const secondDriverIdInput = document.getElementById('d_second_driver_id');
  const helperIdInput = document.getElementById('d_helper_id');
  const clientSelect = document.getElementById('d_client');
  const originLocationSelect = document.getElementById('d_origin_location');
  const destinationLocationSelect = document.getElementById('d_destination_location');

  function syncDatalistId(input, hiddenInput, listId) {
    if (!input || !hiddenInput) return;
    const value = input.value.trim();
    const option = Array.from(document.querySelectorAll(`#${listId} option`))
      .find(item => item.value === value);
    hiddenInput.value = option?.dataset.id ?? '';
  }

  // Routes: filtered by the chosen client, and the route fills in pickup/delivery.
  const routeListEl = document.getElementById('approvedRoutesList');
  const allRouteOptions = Array.from(routeListEl?.querySelectorAll('option') ?? []).map(option => ({
    value: option.value,
    id: option.dataset.id,
    clients: JSON.parse(option.dataset.clients || '[]').map(String),
    options: JSON.parse(option.dataset.options || '[]'),
  }));

  // A client with linked routes is offered only those; otherwise every route.
  function routesForClient(client) {
    const linked = allRouteOptions.filter(route => route.clients.includes(client));
    return client && linked.length ? linked : allRouteOptions;
  }

  function renderRouteList() {
    if (!routeListEl) return;
    routeListEl.replaceChildren(...routesForClient(clientSelect?.value ?? '').map(route => {
      const option = document.createElement('option');
      option.value = route.value;
      option.dataset.id = route.id;
      return option;
    }));
  }

  function fillSelect(select, values, preferred) {
    if (!select) return;
    select.replaceChildren(new Option(values.length ? '— Select location —' : '— Select route first —', ''));
    values.forEach(value => select.add(new Option(value, value)));
    select.disabled = values.length === 0;
    select.value = values.includes(preferred) ? preferred : (values[0] ?? '');
  }

  function applyRoute() {
    const route = allRouteOptions.find(item => item.id === routeIdInput?.value);
    const options = route?.options ?? [];
    const unique = key => [...new Set(options.map(option => option[key]))];
    fillSelect(originLocationSelect, unique('pickup'), options[0]?.pickup);
    fillSelect(destinationLocationSelect, unique('delivery'), options[0]?.delivery);
    if (route && clientSelect && !clientSelect.value && route.clients.length === 1) {
      clientSelect.value = route.clients[0];
      renderRouteList();
    }
  }

  routeInput?.addEventListener('input', () => {
    syncDatalistId(routeInput, routeIdInput, 'approvedRoutesList');
    applyRoute();
  });
  driverSel?.addEventListener('input', () => syncDatalistId(driverSel, driverIdInput, 'activeDriversList'));
  secondDriverSel?.addEventListener('input', () => syncDatalistId(secondDriverSel, secondDriverIdInput, 'activeDriversList'));
  helperSel?.addEventListener('input', () => syncDatalistId(helperSel, helperIdInput, 'activeHelpersList'));

  function syncHelperOptions() {
    const selectedCrew = [
      [driverIdInput, driverSel],
      [secondDriverIdInput, secondDriverSel],
    ].map(([input]) => input?.value).filter(Boolean);
    if (helperIdInput && selectedCrew.includes(helperIdInput.value)) {
      helperSel.value = '';
      helperIdInput.value = '';
    }
  }
  helperSel?.addEventListener('input', syncHelperOptions);
  secondDriverSel?.addEventListener('input', syncHelperOptions);

  clientSelect?.addEventListener('change', () => {
    const route = allRouteOptions.find(item => item.id === routeIdInput?.value);
    if (route && clientSelect.value && !routesForClient(clientSelect.value).includes(route)) {
      if (routeInput) routeInput.value = '';
      if (routeIdInput) routeIdInput.value = '';
      applyRoute();
    }
    renderRouteList();
  });
  renderRouteList();
  applyRoute();

  function clearDispatchFields() {
    if (routeInput) routeInput.value = '';
    if (routeIdInput) routeIdInput.value = '';
    if (driverSel) driverSel.value = '';
    if (driverIdInput) driverIdInput.value = '';
    if (secondDriverSel) secondDriverSel.value = '';
    if (secondDriverIdInput) secondDriverIdInput.value = '';
    if (helperSel) helperSel.value = '';
    if (helperIdInput) helperIdInput.value = '';
    if (clientSelect) clientSelect.value = '';
    const billingClient = document.getElementById('d_billing_client');
    if (billingClient) billingClient.value = '';
    renderRouteList();
    applyRoute();
    const route = document.getElementById('d_route');
    const truck = document.getElementById('d_truck');
    const scheduled = document.getElementById('d_scheduled');
    const expectedArrival = document.getElementById('d_expected_arrival');
    const bookingReference = document.getElementById('d_booking_reference');
    const waybillReference = document.getElementById('d_waybill_reference');
    const unitCount = document.getElementById('d_unit_count');
    const remarks = document.getElementById('d_remarks');
    if (route) route.value = '';
    if (truck) truck.value = '';
    if (scheduled) scheduled.value = '';
    const shift = document.getElementById('d_shift');
    if (shift && !shift.disabled) shift.value = 'Day';
    if (expectedArrival) expectedArrival.value = '';
    if (bookingReference && !bookingReference.readOnly) bookingReference.value = '';
    if (waybillReference) waybillReference.value = '';
    if (unitCount && !unitCount.readOnly) unitCount.value = '';
    if (remarks) remarks.value = '';
  }

  // ── Submit new dispatch request ──────────────────────────────────────────────
  const submitDispatchBtn = document.getElementById('submitDispatchBtn');
  const dispatchFormError = document.getElementById('dispatchFormError');
  const newDispatchModal  = document.getElementById('newDispatchModal');

  newDispatchModal?.addEventListener('hidden.bs.modal', () => {
    hideAlert(dispatchFormError);
    if (!document.getElementById('d_instruction_id')?.value) clearDispatchFields();
  });

  submitDispatchBtn?.addEventListener('click', async () => {
    hideAlert(dispatchFormError);

    const truck     = document.getElementById('d_truck')?.value      ?? '';
    const route     = routeIdInput?.value                            ?? '';
    const driver    = driverIdInput?.value                           ?? '';
    const secondDriver = secondDriverIdInput?.value                  ?? '';
    const helper    = helperIdInput?.value                           ?? '';
    const client    = clientSelect?.value                             ?? '';
    const originLocation = originLocationSelect?.value                ?? '';
    const destinationLocation = destinationLocationSelect?.value      ?? '';
    const scheduled = document.getElementById('d_scheduled')?.value  ?? '';
    const shift = document.getElementById('d_shift')?.value ?? '';
    const instructionId = document.getElementById('d_instruction_id')?.value ?? '';
    const expectedArrival = document.getElementById('d_expected_arrival')?.value ?? '';
    const remarks   = document.getElementById('d_remarks')?.value.trim() ?? '';

    if (!truck || !route || !driver || !scheduled || !shift || !client || !originLocation || !destinationLocation) {
      showAlert(dispatchFormError, 'Please choose an active client, approved route (which sets the pickup and delivery locations), active driver, truck, and scheduled departure.');
      return;
    }
    if ([driver, secondDriver, helper].filter(Boolean).length !== new Set([driver, secondDriver, helper].filter(Boolean)).size) {
      showAlert(dispatchFormError, 'Each assigned crew member must be different.');
      return;
    }
    if (expectedArrival && new Date(expectedArrival) <= new Date(scheduled)) {
      showAlert(dispatchFormError, 'Expected arrival must be later than scheduled departure.');
      return;
    }
    if (!window.confirm('Confirm and submit this dispatch request?')) return;

    const btnText    = document.getElementById('dispatchBtnText');
    const btnSpinner = document.getElementById('dispatchBtnSpinner');
    setBusy(btnText, btnSpinner, true);

    try {
      const fd = new FormData();
      fd.append('truck_id',     truck);
      fd.append('route_id',     route);
      fd.append('driver_id',    driver);
      fd.append('second_driver_id', secondDriver);
      fd.append('helper_id',    helper);
      fd.append('client_id',    client);
      fd.append('pickup_location', originLocation);
      fd.append('delivery_location', destinationLocation);
      fd.append('scheduled_at', scheduled);
      fd.append('shift', shift);
      fd.append('instruction_id', instructionId);
      fd.append('expected_arrival', expectedArrival);
      fd.append('remarks',      remarks);

      const result = await postForm(DISPATCH_URL, fd);

      if (result.success) {
        window.softReload();
      } else {
        showAlert(dispatchFormError, result.message || 'Could not submit request.');
      }
    } catch {
      showAlert(dispatchFormError, 'Network error. Please try again.');
    } finally {
      setBusy(btnText, btnSpinner, false);
    }
  });

  if (newDispatchModal && document.getElementById('d_instruction_id')?.value) {
    bootstrap.Modal.getOrCreateInstance(newDispatchModal).show();
  }

  const routeRequestModal = document.getElementById('routeRequestModal');
  const rrOrigin = document.getElementById('rr_origin');
  const rrDestination = document.getElementById('rr_destination');
  const rrMapPreview = wireMapPreview('rr', rrOrigin, rrDestination, document.getElementById('rr_pickup'));

  routeRequestModal?.addEventListener('hidden.bs.modal', () => {
    const fields = ['rr_name', 'rr_origin', 'rr_pickup', 'rr_destination', 'rr_distance', 'rr_notes'];
    fields.forEach(id => {
      const field = document.getElementById(id);
      if (field) field.value = '';
    });
    hideAlert(document.getElementById('routeRequestAlert'));
    rrMapPreview.reset();
  });

  document.getElementById('submitRouteRequestBtn')?.addEventListener('click', async () => {
    const alertEl = document.getElementById('routeRequestAlert');
    const name = document.getElementById('rr_name')?.value.trim() ?? '';
    const origin = document.getElementById('rr_origin')?.value.trim() ?? '';
    const pickup = document.getElementById('rr_pickup')?.value.trim() ?? '';
    const destination = document.getElementById('rr_destination')?.value.trim() ?? '';
    const distance = document.getElementById('rr_distance')?.value ?? '';
    const notes = document.getElementById('rr_notes')?.value.trim() ?? '';
    if (!name || !origin || !pickup || !destination) {
      showAlert(alertEl, 'Route name, origin, pickup location, and delivery location are required.');
      return;
    }
    if (name.length > 150 || origin.length > 150 || pickup.length > 150 || destination.length > 150) {
      showAlert(alertEl, 'Route name and locations must be 150 characters or fewer.');
      return;
    }
    const distanceValue = distance === '' ? null : Number(distance);
    if (distanceValue !== null && (!Number.isFinite(distanceValue) || distanceValue < 0 || distanceValue > 100000)) {
      showAlert(alertEl, 'Distance must be a valid number between 0 and 100,000 km.');
      return;
    }
    const confirmed = window.confirm(
      `Submit route request "${name}" from "${origin}" (pickup "${pickup}", delivery "${destination}") to Head Management?`
    );
    if (!confirmed) return;
    const submitButton = document.getElementById('submitRouteRequestBtn');
    if (submitButton) submitButton.disabled = true;
    try {
      const result = await postAjax(ROUTE_REQUEST_URL, {
        action: 'request', route_name: name, origin, pickup_location: pickup, delivery_location: destination,
        distance_km: distance, request_notes: notes
      });
      if (result.success) window.softReload();
      else showAlert(alertEl, result.message || 'Could not submit route request.');
    } catch (error) {
      showAlert(alertEl, error.message || 'Network error. Please try again.');
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  });

  document.querySelectorAll('.btn-review-route').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm(`${btn.dataset.status} this route request?`)) return;
      const result = await postAjax(ROUTE_REQUEST_URL, {
        action: 'review', route_id: btn.dataset.id, status: btn.dataset.status
      });
      if (result.success) window.softReload();
      else alert(result.message || 'Could not review route request.');
    });
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
      if (result.success) window.softReload();
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
      if (result.success) { bsRejectModal.hide(); window.softReload(); }
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
  function wireMapPreview(prefix, originInput, destinationInput, pickupInput = null) {
    if (pickupInput) return wireStopMapPreview(prefix, originInput, pickupInput, destinationInput);
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

  // Origin, pickup and delivery pin previews plus origin→pickup and pickup→delivery route previews.
  function wireStopMapPreview(prefix, originInput, pickupInput, destinationInput) {
    const slot = id => ({
      frame: document.getElementById(`${prefix}_${id}_map`),
      placeholder: document.getElementById(`${prefix}_${id}_map_placeholder`),
    });
    const pins = [
      [originInput, slot('origin')],
      [pickupInput, slot('pickup')],
      [destinationInput, slot('destination')],
    ];
    const routes = [
      [originInput, pickupInput, slot('route_a')],
      [pickupInput, destinationInput, slot('route_b')],
    ];
    const valueOf = input => input?.value.trim() ?? '';
    const show = ({ frame, placeholder }, src) => {
      if (frame) frame.src = src ?? '';
      frame?.classList.toggle('d-none', !src);
      placeholder?.classList.toggle('d-none', !!src);
    };

    let debounceTimer = null;
    function refreshAll() {
      pins.forEach(([input, target]) => {
        const val = valueOf(input);
        show(target, val.length >= 3 ? mapPinSrc(val) : null);
      });
      routes.forEach(([from, to, target]) => {
        const a = valueOf(from);
        const b = valueOf(to);
        show(target, a.length >= 3 && b.length >= 3 ? mapRouteSrc(a, b) : null);
      });
    }
    function scheduleRefresh() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(refreshAll, 600);
    }
    [originInput, pickupInput, destinationInput].forEach(input => input?.addEventListener('input', scheduleRefresh));

    return { refreshAll, reset: () => {
      clearTimeout(debounceTimer);
      [...pins.map(p => p[1]), ...routes.map(r => r[2])].forEach(target => show(target, null));
    }};
  }

  // ── Pickup/delivery option rows and client checklists (add/edit route) ──────
  function addOptionRow(containerId, pickup = '', delivery = '') {
    const container = document.getElementById(containerId);
    if (!container) return;
    const row = document.createElement('div');
    row.className = 'input-group input-group-sm js-option-row';
    const pickupInput = Object.assign(document.createElement('input'), {
      className: 'form-control js-option-pickup', placeholder: 'Pickup location', value: pickup,
    });
    const deliveryInput = Object.assign(document.createElement('input'), {
      className: 'form-control js-option-delivery', placeholder: 'Delivery location', value: delivery,
    });
    const remove = Object.assign(document.createElement('button'), {
      type: 'button', className: 'btn btn-outline-danger', title: 'Remove option',
    });
    remove.innerHTML = '<i class="bi bi-x-lg"></i>';
    remove.addEventListener('click', () => row.remove());
    row.append(pickupInput, deliveryInput, remove);
    container.append(row);
  }

  document.addEventListener('click', e => {
    const btn = e.target.closest('.js-add-route-option');
    if (btn) addOptionRow(btn.dataset.target);
  });

  function collectOptions(prefix) {
    const options = [{
      pickup: document.getElementById(prefix + '_pickup')?.value.trim() ?? '',
      delivery: document.getElementById(prefix + '_destination')?.value.trim() ?? '',
    }];
    document.querySelectorAll(`#${prefix}_extra_options .js-option-row`).forEach(row => {
      options.push({
        pickup: row.querySelector('.js-option-pickup').value.trim(),
        delivery: row.querySelector('.js-option-delivery').value.trim(),
      });
    });
    return options.filter(option => option.pickup || option.delivery);
  }

  function optionsComplete(options) {
    return options.length > 0 && options.every(option => option.pickup && option.delivery);
  }

  function collectClients(prefix) {
    return Array.from(document.querySelectorAll(`#${prefix}_clients input:checked`)).map(box => box.value);
  }

  function setClients(prefix, ids) {
    const selected = ids.map(String);
    document.querySelectorAll(`#${prefix}_clients input`).forEach(box => { box.checked = selected.includes(box.value); });
  }

  // ── Add Route ────────────────────────────────────────────────────────────────
  const addRouteModal = document.getElementById('addRouteModal');
  const ar_name       = document.getElementById('ar_name');
  const ar_origin     = document.getElementById('ar_origin');
  const ar_pickup     = document.getElementById('ar_pickup');
  const ar_destination = document.getElementById('ar_destination');
  const ar_distance   = document.getElementById('ar_distance');
  const submitAddRoute = document.getElementById('submitAddRouteBtn');
  const arBtnText     = document.getElementById('arBtnText');
  const arBtnSpinner  = document.getElementById('arBtnSpinner');
  const addRouteAlert = document.getElementById('addRouteAlert');

  const arMapPreview = wireMapPreview('ar', ar_origin, ar_destination, ar_pickup);

  addRouteModal?.addEventListener('hidden.bs.modal', () => {
    [ar_name, ar_origin, ar_pickup, ar_destination, ar_distance].forEach(el => { if (el) el.value = ''; });
    document.getElementById('ar_extra_options')?.replaceChildren();
    setClients('ar', []);
    hideAlert(addRouteAlert);
    setBusy(arBtnText, arBtnSpinner, false);
    arMapPreview.reset();
  });

  submitAddRoute?.addEventListener('click', () => {
    hideAlert(addRouteAlert);

    const name        = ar_name?.value.trim()        ?? '';
    const origin      = ar_origin?.value.trim()      ?? '';
    const distance    = ar_distance?.value           ?? '';
    const options     = collectOptions('ar');

    if (!name || !origin || !optionsComplete(options)) {
      showAlert(addRouteAlert, 'Route name, origin, and a pickup and delivery location are required.');
      return;
    }

    setBusy(arBtnText, arBtnSpinner, true);

    postAjax(ROUTES_URL, {
      action:      'add',
      route_name:  name,
      origin,
      options:     JSON.stringify(options),
      client_ids:  JSON.stringify(collectClients('ar')),
      distance_km: distance,
    })
      .then(res => {
        setBusy(arBtnText, arBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(addRouteModal)?.hide();
          window.softReload();
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
  const er_pickup       = document.getElementById('er_pickup');
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

    const options = JSON.parse(btn.dataset.options || '[]');
    const [first = { pickup: btn.dataset.origin ?? '', delivery: btn.dataset.destination ?? '' }, ...rest] = options;

    if (er_id)          er_id.value          = btn.dataset.id          ?? '';
    if (er_name)        er_name.value        = btn.dataset.name        ?? '';
    if (er_origin)      er_origin.value      = btn.dataset.origin      ?? '';
    if (er_pickup)      er_pickup.value      = first.pickup;
    if (er_destination) er_destination.value = first.delivery;
    if (er_distance)    er_distance.value    = btn.dataset.distance    ?? '';
    document.getElementById('er_extra_options')?.replaceChildren();
    rest.forEach(option => addOptionRow('er_extra_options', option.pickup, option.delivery));
    setClients('er', JSON.parse(btn.dataset.clients || '[]'));

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
    const distance    = er_distance?.value           ?? '';
    const options     = collectOptions('er');

    if (!name || !origin || !optionsComplete(options)) {
      showAlert(editRouteAlert, 'Route name, origin, and a pickup and delivery location are required.');
      return;
    }

    setBusy(erBtnText, erBtnSpinner, true);

    postAjax(ROUTES_URL, {
      action:      'edit',
      route_id:    er_id?.value ?? '',
      route_name:  name,
      origin,
      options:     JSON.stringify(options),
      client_ids:  JSON.stringify(collectClients('er')),
      distance_km: distance,
    })
      .then(res => {
        setBusy(erBtnText, erBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(editRouteModal)?.hide();
          window.softReload();
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
        if (res.success) window.softReload();
        else alert('Error: ' + (res.message ?? 'Could not toggle route.'));
      })
      .catch(() => alert('Network error. Please try again.'));
  });

});