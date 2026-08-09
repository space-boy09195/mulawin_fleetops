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
      const isLate     = row.dataset.late   === '1';
      const searchData = row.dataset.search || '';

      let matchesFilter = false;
      if (activeFilter === 'all')        matchesFilter = true;
      else if (activeFilter === 'late')  matchesFilter = isLate;
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
  const modalLocation  = document.getElementById('modalLocation');
  const modalNotes     = document.getElementById('modalNotes');
  const confirmBtn     = document.getElementById('confirmUpdateBtn');
  const btnText        = document.getElementById('updateBtnText');
  const btnSpinner     = document.getElementById('updateBtnSpinner');

  let bsModal = null;
  if (modal) bsModal = new bootstrap.Modal(modal);

  window.openUpdateModal = function(tripId, tripNumber, currentStatus) {
    if (!bsModal) return;
    modalTripNum.textContent  = tripNumber;
    modalTripId.value         = tripId;
    modalStatus.value         = currentStatus;
    if (modalLocation) modalLocation.value = '';
    if (modalNotes)    modalNotes.value    = '';
    bsModal.show();
  };

  if (confirmBtn) {
    confirmBtn.addEventListener('click', async () => {
      const tripId   = modalTripId.value;
      const status   = modalStatus.value;
      const location = modalLocation ? modalLocation.value.trim() : '';
      const notes    = modalNotes    ? modalNotes.value.trim()    : '';

      if (!tripId || !status) return;

      if (status === 'Cancelled' && !confirm('Cancel this trip? This affects truck availability and any linked billing.')) {
        return;
      }

      btnText.classList.add('d-none');
      btnSpinner.classList.remove('d-none');
      confirmBtn.disabled = true;

      try {
        const fd = new FormData();
        fd.append('trip_id',       tripId);
        fd.append('status',        status);
        fd.append('location_note', location);
        fd.append('notes',         notes);

        const res    = await fetch(window.APP_BASE + '/ajax/update_trip_status.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.success) {
          bsModal.hide();
          window.location.reload();
        } else {
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