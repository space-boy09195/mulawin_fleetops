/**
 * billing.js — Mulawin FleetOps
 * Handles: create billing form, record payment form, filters.
 */

'use strict';

(function () {

  const BASE     = window.APP_BASE ?? '';
  const AJAX_URL = BASE + '/ajax/billing_handler.php';

  // ── Helpers ───────────────────────────────────────────────────────────────
  function postAjax(data) {
    return fetch(AJAX_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    new URLSearchParams({ ...data, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
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

  function fmtCurrency(n) {
    return '₱' + parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // ── Billings filter ───────────────────────────────────────────────────────
  const filterBilStatus = document.getElementById('filterBilStatus');
  const filterBilSearch = document.getElementById('filterBilSearch');
  const billingsTbody   = document.querySelector('#billingsTable tbody');
  const noBillingResults = document.getElementById('noBillingResults');

  function applyBillingFilters() {
    if (!billingsTbody) return;
    const statusVal = filterBilStatus?.value ?? '';
    const searchVal = (filterBilSearch?.value ?? '').toLowerCase();
    let visibleCount = 0;

    billingsTbody.querySelectorAll('tr').forEach(row => {
      const matchStatus = !statusVal || row.dataset.status === statusVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      const isMatch = matchStatus && matchSearch;
      row.classList.toggle('bil-row-hidden', !isMatch);
      if (isMatch) visibleCount++;
    });

    noBillingResults?.classList.toggle('d-none', visibleCount > 0);
  }

  filterBilStatus?.addEventListener('change', applyBillingFilters);
  filterBilSearch?.addEventListener('input', applyBillingFilters);

  // ── Collections filter ────────────────────────────────────────────────────
  const filterColMode   = document.getElementById('filterColMode');
  const filterColSearch = document.getElementById('filterColSearch');
  const colTbody        = document.querySelector('#collectionsTable tbody');
  const noCollectionResults = document.getElementById('noCollectionResults');

  function applyColFilters() {
    if (!colTbody) return;
    const modeVal   = filterColMode?.value   ?? '';
    const searchVal = (filterColSearch?.value ?? '').toLowerCase();
    let visibleCount = 0;

    colTbody.querySelectorAll('tr').forEach(row => {
      const matchMode   = !modeVal   || row.dataset.mode === modeVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      const isMatch = matchMode && matchSearch;
      row.classList.toggle('bil-row-hidden', !isMatch);
      if (isMatch) visibleCount++;
    });

    noCollectionResults?.classList.toggle('d-none', visibleCount > 0);
  }

  filterColMode?.addEventListener('change', applyColFilters);
  filterColSearch?.addEventListener('input', applyColFilters);

  // ══════════════════════════════════════════════════════════════════════════
  // CREATE BILLING FORM
  // ══════════════════════════════════════════════════════════════════════════
  const createBillingModal = document.getElementById('createBillingModal');
  const bilTripId          = document.getElementById('bilTripId');
  const bilClientName      = document.getElementById('bilClientName');
  const bilAmount          = document.getElementById('bilAmount');
  const bilDueDate         = document.getElementById('bilDueDate');
  const bilBillingNumber   = document.getElementById('bilBillingNumber');
  const bilNotes           = document.getElementById('bilNotes');
  const submitBillingBtn   = document.getElementById('submitBillingBtn');
  const bilBtnSpinner      = document.getElementById('bilBtnSpinner');
  const billingFormAlert   = document.getElementById('billingFormAlert');

  createBillingModal?.addEventListener('hidden.bs.modal', () => {
    [bilTripId, bilClientName, bilAmount, bilDueDate, bilBillingNumber, bilNotes].forEach(el => {
      if (el) el.value = '';
    });
    hideAlert(billingFormAlert);
    setBusy(submitBillingBtn, bilBtnSpinner, false);
  });

  submitBillingBtn?.addEventListener('click', () => {
    hideAlert(billingFormAlert);

    const tripId        = bilTripId?.value          ?? '';
    const clientName    = bilClientName?.value.trim() ?? '';
    const amount        = bilAmount?.value          ?? '';
    const dueDate       = bilDueDate?.value         ?? '';
    const billingNumber = bilBillingNumber?.value.trim() ?? '';
    const notes         = bilNotes?.value.trim()    ?? '';

    if (!tripId || !amount || !dueDate || !billingNumber) {
      showAlert(billingFormAlert, 'Please fill in all required fields.');
      return;
    }

    if (parseFloat(amount) <= 0) {
      showAlert(billingFormAlert, 'Amount must be greater than zero.');
      return;
    }

    setBusy(submitBillingBtn, bilBtnSpinner, true);

    postAjax({
      action:          'create_billing',
      trip_id:         tripId,
      client_name:     clientName,
      amount,
      due_date:        dueDate,
      billing_number:  billingNumber,
      notes,
    })
      .then(res => {
        setBusy(submitBillingBtn, bilBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(createBillingModal)?.hide();
          window.location.reload();
        } else {
          showAlert(billingFormAlert, res.message ?? 'Failed to create billing.');
        }
      })
      .catch(() => {
        setBusy(submitBillingBtn, bilBtnSpinner, false);
        showAlert(billingFormAlert, 'Network error. Please try again.');
      });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // RECORD PAYMENT FORM
  // ══════════════════════════════════════════════════════════════════════════
  const paymentModal    = document.getElementById('paymentModal');
  const payBillingNumber = document.getElementById('payBillingNumber');
  const payBalance      = document.getElementById('payBalance');
  const payAmount       = document.getElementById('payAmount');
  const payDate         = document.getElementById('payDate');
  const payMode         = document.getElementById('payMode');
  const payReference    = document.getElementById('payReference');
  const payRemarks      = document.getElementById('payRemarks');
  const submitPaymentBtn = document.getElementById('submitPaymentBtn');
  const payBtnSpinner   = document.getElementById('payBtnSpinner');
  const paymentFormAlert = document.getElementById('paymentFormAlert');

  let pendingBillingId  = null;
  let pendingBalance    = 0;

  // Open payment modal from "Record Payment" button in billings table
  billingsTbody?.addEventListener('click', e => {
    const btn = e.target.closest('.btn-bil-collect');
    if (!btn) return;

    pendingBillingId = btn.dataset.id      ?? null;
    pendingBalance   = parseFloat(btn.dataset.balance ?? 0);

    if (payBillingNumber) payBillingNumber.textContent = btn.dataset.number ?? '';
    if (payBalance)       payBalance.textContent       = fmtCurrency(pendingBalance);
    if (payAmount)        payAmount.value              = '';
    if (payMode)          payMode.value                = '';
    if (payReference)     payReference.value           = '';
    if (payRemarks)       payRemarks.value             = '';
    if (payDate)          payDate.value                = new Date().toISOString().slice(0, 10);

    hideAlert(paymentFormAlert);
    setBusy(submitPaymentBtn, payBtnSpinner, false);

    new bootstrap.Modal(paymentModal).show();
  });

  paymentModal?.addEventListener('hidden.bs.modal', () => {
    pendingBillingId = null;
    pendingBalance   = 0;
    hideAlert(paymentFormAlert);
    setBusy(submitPaymentBtn, payBtnSpinner, false);
  });

  submitPaymentBtn?.addEventListener('click', () => {
    hideAlert(paymentFormAlert);

    const amount    = payAmount?.value    ?? '';
    const date      = payDate?.value      ?? '';
    const mode      = payMode?.value      ?? '';
    const reference = payReference?.value.trim() ?? '';
    const remarks   = payRemarks?.value.trim()   ?? '';

    if (!amount || !date || !mode) {
      showAlert(paymentFormAlert, 'Amount, date, and payment mode are required.');
      return;
    }

    const amountNum = parseFloat(amount);
    if (amountNum <= 0) {
      showAlert(paymentFormAlert, 'Amount must be greater than zero.');
      return;
    }

    if (amountNum > pendingBalance) {
      showAlert(paymentFormAlert, `Payment exceeds outstanding balance of ${fmtCurrency(pendingBalance)}.`);
      return;
    }

    setBusy(submitPaymentBtn, payBtnSpinner, true);

    postAjax({
      action:       'record_payment',
      billing_id:   pendingBillingId,
      amount_paid:  amount,
      payment_date: date,
      payment_mode: mode,
      reference_no: reference,
      remarks,
    })
      .then(res => {
        setBusy(submitPaymentBtn, payBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(paymentModal)?.hide();
          updateBillingRow(pendingBillingId, res.new_status, res.new_balance);
        } else {
          showAlert(paymentFormAlert, res.message ?? 'Failed to record payment.');
        }
      })
      .catch(() => {
        setBusy(submitPaymentBtn, payBtnSpinner, false);
        showAlert(paymentFormAlert, 'Network error. Please try again.');
      });
  });

  // ── Update billing row in DOM after payment ───────────────────────────────
  function updateBillingRow(billingId, newStatus, newBalance) {
    if (!billingsTbody) return;

    const row = billingsTbody.querySelector(`tr[data-id="${billingId}"]`);
    if (!row) { window.location.reload(); return; }

    // Update data-status for filter
    row.dataset.status = newStatus;

    // Status badge
    const badge = row.querySelector('.bil-status-badge');
    if (badge) {
      badge.className   = `bil-status-badge bil-status-${newStatus.toLowerCase()}`;
      badge.textContent = newStatus;
    }

    // Balance cell
    const balanceCell = row.querySelector('.bil-balance');
    if (balanceCell) {
      balanceCell.textContent = fmtCurrency(newBalance);
      balanceCell.classList.toggle('bil-balance-due', newBalance > 0);
    }

    // Action cell
    const actionCell = row.querySelector('td:last-child');
    if (actionCell) {
      if (newStatus === 'Paid') {
        actionCell.innerHTML = `<span class="bil-paid-check" title="Fully paid"><i class="bi bi-check-circle-fill text-success"></i></span>`;
      } else {
        // Update button's balance data attribute
        const btn = actionCell.querySelector('.btn-bil-collect');
        if (btn) btn.dataset.balance = newBalance;
      }
    }

    // Re-run filters
    applyBillingFilters();
  }

  // ── Payroll: log payment ────────────────────────────────────────────────────
  const PAYROLL_AJAX_URL = BASE + '/ajax/payroll_handler.php';

  function postPayrollAjax(data) {
    return fetch(PAYROLL_AJAX_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    new URLSearchParams({ ...data, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
    }).then(r => r.json());
  }

  const logPayrollModal   = document.getElementById('logPayrollModal');
  const submitPayrollBtn  = document.getElementById('submitPayrollBtn');
  const prBtnSpinner      = document.getElementById('prBtnSpinner');
  const payrollFormAlert  = document.getElementById('payrollFormAlert');
  const prEmployee        = document.getElementById('prEmployee');
  const prPeriodStart     = document.getElementById('prPeriodStart');
  const prPeriodEnd       = document.getElementById('prPeriodEnd');
  const prAmount          = document.getElementById('prAmount');
  const prPaidDate        = document.getElementById('prPaidDate');
  const prNotes           = document.getElementById('prNotes');

  logPayrollModal?.addEventListener('show.bs.modal', () => {
    hideAlert(payrollFormAlert);
    if (prEmployee)    prEmployee.value    = '';
    if (prPeriodStart) prPeriodStart.value = '';
    if (prPeriodEnd)   prPeriodEnd.value   = '';
    if (prAmount)      prAmount.value      = '';
    if (prPaidDate)    prPaidDate.value    = new Date().toISOString().slice(0, 10);
    if (prNotes)       prNotes.value       = '';
    setBusy(submitPayrollBtn, prBtnSpinner, false);
  });

  submitPayrollBtn?.addEventListener('click', () => {
    hideAlert(payrollFormAlert);

    const employeeId   = prEmployee?.value    ?? '';
    const periodStart  = prPeriodStart?.value ?? '';
    const periodEnd    = prPeriodEnd?.value   ?? '';
    const amount       = prAmount?.value      ?? '';
    const paidDate     = prPaidDate?.value    ?? '';
    const notes        = prNotes?.value.trim() ?? '';

    if (!employeeId) {
      showAlert(payrollFormAlert, 'Select an employee.');
      return;
    }
    if (!periodStart || !periodEnd) {
      showAlert(payrollFormAlert, 'Enter the pay period.');
      return;
    }
    if (new Date(periodEnd) < new Date(periodStart)) {
      showAlert(payrollFormAlert, 'Pay period end must be on or after the start date.');
      return;
    }
    if (!amount || parseFloat(amount) <= 0) {
      showAlert(payrollFormAlert, 'Enter an amount greater than zero.');
      return;
    }
    if (!paidDate) {
      showAlert(payrollFormAlert, 'Enter the paid date.');
      return;
    }

    setBusy(submitPayrollBtn, prBtnSpinner, true);

    postPayrollAjax({
      action:           'create',
      employee_id:      employeeId,
      pay_period_start: periodStart,
      pay_period_end:   periodEnd,
      amount_paid:      amount,
      paid_date:        paidDate,
      notes,
    })
      .then(res => {
        setBusy(submitPayrollBtn, prBtnSpinner, false);
        if (res.success) {
          window.location.reload();
        } else {
          showAlert(payrollFormAlert, res.message ?? 'Failed to log payment.');
        }
      })
      .catch(() => {
        setBusy(submitPayrollBtn, prBtnSpinner, false);
        showAlert(payrollFormAlert, 'Network error. Please try again.');
      });
  });

  // ── Payroll: delete record (Head Management only) ────────────────────────
  document.querySelectorAll('.bil-delete-payroll-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const type = btn.dataset.type;
      if (!id) return;

      if (type === 'trip_pay') {
        if (!confirm('Delete this crew pay entry? This cannot be undone.')) return;
        fetch(BASE + '/ajax/trip_pay_handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ action: 'delete', trip_pay_id: id, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
        })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              window.location.reload();
            } else {
              alert(res.message ?? 'Could not delete this record.');
            }
          })
          .catch(() => alert('Network error. Please try again.'));
        return;
      }

      if (!confirm('Delete this payroll record? It can be recovered from the Recycle Bin.')) return;
      postPayrollAjax({ action: 'delete', payroll_id: id })
        .then(res => {
          if (res.success) {
            window.location.reload();
          } else {
            alert(res.message ?? 'Could not delete this record.');
          }
        })
        .catch(() => alert('Network error. Please try again.'));
    });
  });

  // ── Payroll: print report ─────────────────────────────────────────────────
  document.getElementById('printPayrollReportBtn')?.addEventListener('click', () => {
    window.print();
  });

  // ── Payroll: send report to Head (uploads via the existing Documents
  //    infrastructure, so it shows up on the Documents page Head already
  //    has access to — no new backend, no new page) ─────────────────────────
  const sendReportBtn = document.getElementById('submitSendReportBtn');
  if (sendReportBtn) {
    const DOCS_AJAX_URL   = BASE + '/ajax/document_handler.php';
    const sendReportAlert = document.getElementById('sendReportAlert');
    const srBtnSpinner    = document.getElementById('srBtnSpinner');

    sendReportBtn.addEventListener('click', () => {
      hideAlert(sendReportAlert);

      const fileInput = document.getElementById('reportFile');
      const file       = fileInput?.files?.[0];
      const description = document.getElementById('reportDescription')?.value.trim() || '';

      if (!file) {
        showAlert(sendReportAlert, 'Attach the PDF you saved from Print Report first.');
        return;
      }

      const fd = new FormData();
      fd.append('action',      'upload');
      fd.append('file',        file);
      fd.append('doc_type',    'Company Document');
      fd.append('description', description);
      fd.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);

      setBusy(sendReportBtn, srBtnSpinner, true);

      fetch(DOCS_AJAX_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          setBusy(sendReportBtn, srBtnSpinner, false);
          if (res.success) {
            showAlert(sendReportAlert, 'Sent — Head Management can now see this on the Documents page.', 'success');
            fileInput.value = '';
          } else {
            showAlert(sendReportAlert, res.message ?? 'Could not send the report.');
          }
        })
        .catch(() => {
          setBusy(sendReportBtn, srBtnSpinner, false);
          showAlert(sendReportAlert, 'Network error. Please try again.');
        });
    });
  }

})();