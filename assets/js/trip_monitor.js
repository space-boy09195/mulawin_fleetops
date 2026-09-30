// ============================================================
// assets/js/trip_monitor.js
// Filter, search, trip status update modal + AJAX
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  const tbody      = document.getElementById('tripBody');
  const rowCountEl = document.getElementById('rowCount');
  const searchInput= document.getElementById('tripSearch');
  const filterBtns = document.querySelectorAll('.filter-btn');
  const noResults  = document.getElementById('noTripResults');

  let activeFilter = 'all';
  let searchTerm   = '';

  // ---- Row count -----------------------------------------
  function updateRowCount() {
    if (!tbody || !rowCountEl) return;
    const visible = tbody.querySelectorAll('tr:not(.hidden-row)[data-status]').length;
    const total   = tbody.querySelectorAll('tr[data-status]').length;
    rowCountEl.textContent = visible === total
      ? `${total} trip${total !== 1 ? 's' : ''}`
      : `${visible} of ${total} trips`;
    if (noResults) noResults.classList.toggle('d-none', visible > 0 || total === 0);
  }

  // ---- Apply filters + search ----------------------------
  function applyFilters() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-status]').forEach((row) => {
      const status     = row.dataset.status || '';
      const shift      = row.dataset.shift || '';
      const isLate     = row.dataset.late   === '1';
      const hasProblem = row.dataset.problem === '1';
      const searchData = row.dataset.search || '';

      let matchesFilter = false;
      if (activeFilter === 'all')        matchesFilter = true;
      else if (activeFilter === 'late')  matchesFilter = isLate;
      else if (activeFilter === 'problem') matchesFilter = hasProblem;
      else if (activeFilter === 'okay') matchesFilter = !hasProblem;
      else if (activeFilter === 'shift:Day') matchesFilter = shift === 'Day';
      else if (activeFilter === 'shift:Night') matchesFilter = shift === 'Night';
      else                               matchesFilter = status === activeFilter;

      const matchesSearch = searchTerm === '' || searchData.includes(searchTerm);

      row.classList.toggle('hidden-row', !(matchesFilter && matchesSearch));
    });
    updateRowCount();
  }

  filterBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      filterBtns.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      activeFilter = btn.dataset.filter || 'all';
      applyFilters();
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      searchTerm = searchInput.value.trim().toLowerCase();
      applyFilters();
    });
  }

  updateRowCount();

  // ====================================================
  // Update Trip Modal + AJAX
  // ====================================================
  const modal          = document.getElementById('updateModal');
  const modalTripNum   = document.getElementById('modalTripNumber');
  const modalTripId    = document.getElementById('modalTripId');
  const modalStatus    = document.getElementById('modalStatus');
  const modalNotes     = document.getElementById('modalNotes');
  const modalNotesLabel = document.getElementById('modalNotesLabel');
  const modalLocation  = document.getElementById('modalLocation');
  const confirmBtn     = document.getElementById('confirmUpdateBtn');
  const btnText        = document.getElementById('updateBtnText');
  const btnSpinner     = document.getElementById('updateBtnSpinner');
  const attachments    = document.getElementById('completedReportAttachments');
  const deliveryReceipt = document.getElementById('modalDeliveryReceipt');
  const waybill         = document.getElementById('modalWaybill');

  let bsModal = null;
  if (modal) bsModal = new bootstrap.Modal(modal);

  function syncUpdateRequirements() {
    const cancelled = modalStatus?.value === 'Cancelled';
    if (modalNotes) modalNotes.required = cancelled;
    if (modalNotesLabel) {
      modalNotesLabel.innerHTML = cancelled
        ? 'Cancellation Reason <span class="text-danger">(required)</span>'
        : 'Notes <span class="text-muted fw-400">(optional)</span>';
    }
    attachments?.classList.toggle('d-none', modalStatus?.value !== 'Completed');
  }

  modalStatus?.addEventListener('change', syncUpdateRequirements);

  window.openUpdateModal = function(tripId, tripNumber, currentStatus) {
    if (!bsModal) return;
    modalTripNum.textContent  = tripNumber;
    modalTripId.value         = tripId;
    const nextStatuses = {
      'Loading': ['In Transit', 'Cancelled'],
      'In Transit': ['Unloading', 'Cancelled'],
      'Unloading': ['Completed', 'Cancelled'],
    }[currentStatus] || [];
    modalStatus.replaceChildren(...nextStatuses.map(status => {
      const option = document.createElement('option');
      option.value = status;
      option.textContent = status;
      return option;
    }));
    modalStatus.value = nextStatuses[0] || '';
    if (modalNotes)    modalNotes.value    = '';
    if (modalLocation) modalLocation.value = '';
    syncUpdateRequirements();
    if (deliveryReceipt) deliveryReceipt.value = '';
    if (waybill) waybill.value = '';
    bsModal.show();
  };

  if (confirmBtn) {
    confirmBtn.addEventListener('click', async () => {
      const tripId   = modalTripId.value;
      const status   = modalStatus.value;
      const notes    = modalNotes    ? modalNotes.value.trim()    : '';
      const location = modalLocation ? modalLocation.value.trim() : '';

      if (!tripId || !status) return;
      if (status === 'Cancelled' && !notes) {
        alert('Enter a cancellation reason before cancelling this trip.');
        return;
      }

      btnText.classList.add('d-none');
      btnSpinner.classList.remove('d-none');
      confirmBtn.disabled = true;

      try {
        const fd = new FormData();
        fd.append('trip_id',       tripId);
        fd.append('status',        status);
        fd.append('notes',         notes);
        fd.append('location_note', location);
        if (status === 'Completed') {
          if (deliveryReceipt?.files?.[0]) fd.append('delivery_receipt', deliveryReceipt.files[0]);
          if (waybill?.files?.[0]) fd.append('waybill', waybill.files[0]);
        }
        fd.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
        const scope = `trip.status:${tripId}`;
        const headers = window.fleetOpsIdempotencyHeaders(scope);
        const res = await fetch(window.APP_BASE + '/ajax/update_trip_status.php', {
          method: 'POST',
          headers,
          body: fd,
        });
        const result = await res.json();

        if (result.success) {
          window.fleetOpsCompleteIdempotency(scope);
          bsModal.hide();
          window.location.reload();
        } else {
          if (res.status === 409) window.fleetOpsCompleteIdempotency(scope);
          alert('Error: ' + (result.message || 'Could not update trip.'));
        }
      } catch (err) {
        alert('Network error. Please try again.');
        console.error(err);
      } finally {
        btnText.classList.remove('d-none');
        btnSpinner.classList.add('d-none');
        confirmBtn.disabled = false;
      }
    });
  }

});