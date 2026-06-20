// ============================================================
// assets/js/fleet_status.js
// Handles: filter buttons, live search, status update modal + AJAX
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  const table       = document.getElementById('fleetTable');
  const tbody       = document.getElementById('fleetBody');
  const rowCountEl  = document.getElementById('rowCount');
  const searchInput = document.getElementById('truckSearch');
  const filterBtns  = document.querySelectorAll('.filter-btn');

  let activeFilter = 'all';
  let searchTerm   = '';

  // ---- Row count display ---------------------------------
  function updateRowCount() {
    if (!tbody || !rowCountEl) return;
    const visible = tbody.querySelectorAll('tr:not(.hidden-row)').length;
    const total   = tbody.querySelectorAll('tr[data-status]').length;
    rowCountEl.textContent = visible === total
      ? `${total} truck${total !== 1 ? 's' : ''}`
      : `${visible} of ${total} trucks`;
  }

  // ---- Apply filter + search together --------------------
  function applyFilters() {
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr[data-status]');

    rows.forEach((row) => {
      const status     = row.dataset.status  || '';
      const searchData = row.dataset.search  || '';

      const matchesFilter = activeFilter === 'all' || status === activeFilter;
      const matchesSearch = searchTerm === ''      || searchData.includes(searchTerm);

      row.classList.toggle('hidden-row', !(matchesFilter && matchesSearch));
    });

    updateRowCount();
  }

  // ---- Filter buttons ------------------------------------
  filterBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      filterBtns.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      activeFilter = btn.dataset.filter || 'all';
      applyFilters();
    });
  });

  // ---- Live search ---------------------------------------
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      searchTerm = searchInput.value.trim().toLowerCase();
      applyFilters();
    });
  }

  // ---- Init row count ------------------------------------
  updateRowCount();

  // ====================================================
  // Status Update Modal + AJAX
  // ====================================================
  const statusModal      = document.getElementById('statusModal');
  const modalPlateEl     = document.getElementById('modalPlate');
  const modalTruckIdEl   = document.getElementById('modalTruckId');
  const modalStatusEl    = document.getElementById('modalStatus');
  const confirmBtn       = document.getElementById('confirmStatusBtn');
  const statusBtnText    = document.getElementById('statusBtnText');
  const statusBtnSpinner = document.getElementById('statusBtnSpinner');

  let bsModal = null;
  if (statusModal) {
    bsModal = new bootstrap.Modal(statusModal);
  }

  // Called by the pencil button in each table row
  window.openStatusModal = function(truckId, plate, currentStatus) {
    if (!bsModal) return;
    modalPlateEl.textContent    = plate;
    modalTruckIdEl.value        = truckId;
    modalStatusEl.value         = currentStatus;
    bsModal.show();
  };

  // Confirm button inside modal
  if (confirmBtn) {
    confirmBtn.addEventListener('click', async () => {
      const truckId   = modalTruckIdEl.value;
      const newStatus = modalStatusEl.value;

      if (!truckId || !newStatus) return;

      // Show spinner
      statusBtnText.classList.add('d-none');
      statusBtnSpinner.classList.remove('d-none');
      confirmBtn.disabled = true;

      try {
        const formData = new FormData();
        formData.append('truck_id',  truckId);
        formData.append('status',    newStatus);

        const response = await fetch(
          window.APP_BASE + '/ajax/update_truck_status.php',
          { method: 'POST', body: formData }
        );

        const result = await response.json();

        if (result.success) {
          bsModal.hide();
          // Reload the page to reflect the new status
          window.location.reload();
        } else {
          alert('Error: ' + (result.message || 'Could not update status.'));
        }
      } catch (err) {
        alert('Network error. Please try again.');
        console.error(err);
      } finally {
        statusBtnText.classList.remove('d-none');
        statusBtnSpinner.classList.add('d-none');
        confirmBtn.disabled = false;
      }
    });
  }

});