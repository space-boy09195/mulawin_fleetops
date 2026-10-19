document.addEventListener('DOMContentLoaded', () => {
  const base = window.APP_BASE ?? '';
  const routeUrl = `${base}/ajax/route_request_handler.php`;
  const dispatchUrl = `${base}/ajax/review_dispatch.php`;
  const decisionUrl = `${base}/ajax/approval_decision_handler.php`;
  const alertEl = document.getElementById('requestsAlert');

  function showMessage(message, type = 'danger') {
    if (!alertEl) return;
    alertEl.className = `alert alert-${type}`;
    alertEl.textContent = message;
    alertEl.classList.remove('d-none');
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
      showMessage('An Admin override reason of 1 to 500 characters is required. Nothing was saved.');
      return null;
    }
    return reason;
  }

  async function submit(url, values) {
    const idempotencyScope = values.dispatch_id
      ? `dispatch.review:${values.dispatch_id}`
      : (values.approval_request_id ? `approval.decision:${values.approval_request_id}` : null);
    const body = new URLSearchParams({
      ...values,
      [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN,
    });
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        ...(idempotencyScope ? window.fleetOpsIdempotencyHeaders(idempotencyScope) : {}),
      },
      body,
    });
    const text = await response.text();
    let result;
    try {
      result = JSON.parse(text);
    } catch {
      throw new Error(`Server returned HTTP ${response.status} instead of JSON.`);
    }
    // HTTP 422: Admin override reason required. Ask once and retry once with
    // the same idempotency scope (its key is kept until success or 409).
    if (response.status === 422 && !('override_reason' in values)) {
      const reason = askOverrideReason(result.message);
      if (reason === null) {
        return { success: false, message: 'Cancelled: no Admin override reason was given. Nothing was saved.' };
      }
      return submit(url, { ...values, override_reason: reason });
    }
    if (idempotencyScope && (result.success || response.status === 409)) {
      window.fleetOpsCompleteIdempotency(idempotencyScope);
    }
    return result;
  }

  async function review(button, url, values) {
    button.disabled = true;
    try {
      const result = await submit(url, values);
      if (!result.success) {
        showMessage(result.message || 'The request could not be reviewed.');
        button.disabled = false;
        return;
      }
      window.location.reload();
    } catch (error) {
      showMessage(error.message || 'Network error. Please try again.');
      button.disabled = false;
    }
  }

  document.querySelectorAll('.js-review-route').forEach(button => {
    button.addEventListener('click', () => {
      const status = button.dataset.status;
      if (!window.confirm(`${status} this route request?`)) return;
      review(button, routeUrl, {
        action: 'review',
        route_id: button.dataset.id,
        status,
      });
    });
  });

  document.querySelectorAll('.js-approve-dispatch').forEach(button => {
    button.addEventListener('click', () => {
      if (!window.confirm('Approve this dispatch request?')) return;
      review(button, dispatchUrl, {
        dispatch_id: button.dataset.id,
        status: 'Approved',
        remarks: '',
      });
    });
  });

  document.querySelectorAll('.js-reject-dispatch').forEach(button => {
    button.addEventListener('click', () => {
      const remarks = window.prompt('Reason for rejection:');
      if (remarks === null) return;
      if (!remarks.trim()) {
        showMessage('A rejection reason is required.');
        return;
      }
      if (remarks.trim().length > 255) {
        showMessage('The rejection reason must be 255 characters or fewer.');
        return;
      }
      if (!window.confirm('Reject this dispatch request with the provided reason?')) return;
      review(button, dispatchUrl, {
        dispatch_id: button.dataset.id,
        status: 'Rejected',
        remarks: remarks.trim(),
      });
    });
  });

  // Purchasing, fund, voucher and payroll approvals (generic decision
  // endpoint). A rejection needs a reason.
  document.querySelectorAll('.js-decide-approval').forEach(button => {
    button.addEventListener('click', () => {
      const decision = button.dataset.decision;
      let remarks = '';
      if (decision === 'Rejected') {
        const value = window.prompt('Reason for rejection:');
        if (value === null) return;
        remarks = value.trim();
        if (!remarks || remarks.length > 1000) {
          showMessage('A rejection reason of 1 to 1000 characters is required.');
          return;
        }
      } else if (!window.confirm('Approve this request?')) {
        return;
      }
      review(button, decisionUrl, {
        approval_request_id: button.dataset.id,
        decision,
        remarks,
      });
    });
  });
});
