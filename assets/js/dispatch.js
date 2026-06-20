// ============================================================
// assets/js/dispatch.js
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  // ---- Filter --------------------------------------------
  const filterBtns = document.querySelectorAll('.filter-btn');
  const tbody       = document.getElementById('dispatchBody');
  const rowCountEl  = document.getElementById('rowCount');
  let activeFilter  = 'all';

  function updateCount() {
    if (!tbody || !rowCountEl) return;
    const vis   = tbody.querySelectorAll('tr:not(.hidden-row)[data-status]').length;
    const total = tbody.querySelectorAll('tr[data-status]').length;
    rowCountEl.textContent = vis === total ? `${total} requests` : `${vis} of ${total} requests`;
  }

  function applyFilter() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-status]').forEach((row) => {
      const match = activeFilter === 'all' || row.dataset.status === activeFilter;
      row.classList.toggle('hidden-row', !match);
    });
    updateCount();
  }

  filterBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      filterBtns.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      activeFilter = btn.dataset.filter || 'all';
      applyFilter();
    });
  });
  updateCount();

  // ---- Submit new dispatch request -----------------------
  const submitBtn     = document.getElementById('submitDispatchBtn');
  const dispatchError = document.getElementById('dispatchFormError');

  function showError(msg) {
    if (!dispatchError) return;
    dispatchError.textContent = msg;
    dispatchError.classList.remove('d-none');
  }
  function hideError() {
    if (dispatchError) dispatchError.classList.add('d-none');
  }

  if (submitBtn) {
    submitBtn.addEventListener('click', async () => {
      hideError();
      const truck     = document.getElementById('d_truck').value;
      const route     = document.getElementById('d_route').value;
      const driver    = document.getElementById('d_driver').value;
      const helper    = document.getElementById('d_helper').value;
      const scheduled = document.getElementById('d_scheduled').value;
      const remarks   = document.getElementById('d_remarks').value.trim();

      if (!truck || !route || !driver || !scheduled) {
        showError('Please fill in all required fields.');
        return;
      }

      const btnText    = document.getElementById('dispatchBtnText');
      const btnSpinner = document.getElementById('dispatchBtnSpinner');
      btnText.classList.add('d-none');
      btnSpinner.classList.remove('d-none');
      submitBtn.disabled = true;

      try {
        const fd = new FormData();
        fd.append('truck_id',     truck);
        fd.append('route_id',     route);
        fd.append('driver_id',    driver);
        fd.append('helper_id',    helper);
        fd.append('scheduled_at', scheduled);
        fd.append('remarks',      remarks);

        const res    = await fetch(window.APP_BASE + '/ajax/submit_dispatch.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.success) {
          window.location.reload();
        } else {
          showError(result.message || 'Could not submit request.');
        }
      } catch (err) {
        showError('Network error. Please try again.');
      } finally {
        btnText.classList.remove('d-none');
        btnSpinner.classList.add('d-none');
        submitBtn.disabled = false;
      }
    });
  }

  // ---- Approve (inline, no remarks needed) ---------------
  window.reviewDispatch = async function(dispatchId, status) {
    if (!confirm(`${status === 'Approved' ? 'Approve' : 'Reject'} this dispatch request?`)) return;

    const fd = new FormData();
    fd.append('dispatch_id', dispatchId);
    fd.append('status',      status);
    fd.append('remarks',     '');

    try {
      const res    = await fetch(window.APP_BASE + '/ajax/review_dispatch.php', { method: 'POST', body: fd });
      const result = await res.json();
      if (result.success) window.location.reload();
      else alert('Error: ' + (result.message || 'Could not update.'));
    } catch (err) {
      alert('Network error.');
    }
  };

  // ---- Reject (with remarks modal) -----------------------
  const rejectModal  = document.getElementById('rejectModal');
  const rejectIdEl   = document.getElementById('rejectDispatchId');
  const rejectRmks   = document.getElementById('rejectRemarks');
  const confirmReject= document.getElementById('confirmRejectBtn');
  let bsRejectModal  = rejectModal ? new bootstrap.Modal(rejectModal) : null;

  window.openRejectModal = function(dispatchId) {
    if (!bsRejectModal) return;
    rejectIdEl.value  = dispatchId;
    rejectRmks.value  = '';
    bsRejectModal.show();
  };

  if (confirmReject) {
    confirmReject.addEventListener('click', async () => {
      const remarks = rejectRmks.value.trim();
      if (!remarks) { alert('Please provide a reason for rejection.'); return; }

      const fd = new FormData();
      fd.append('dispatch_id', rejectIdEl.value);
      fd.append('status',      'Rejected');
      fd.append('remarks',     remarks);

      try {
        const res    = await fetch(window.APP_BASE + '/ajax/review_dispatch.php', { method: 'POST', body: fd });
        const result = await res.json();
        if (result.success) { bsRejectModal.hide(); window.location.reload(); }
        else alert('Error: ' + (result.message || 'Could not reject.'));
      } catch (err) {
        alert('Network error.');
      }
    });
  }

});