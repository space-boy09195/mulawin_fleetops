document.addEventListener('DOMContentLoaded', () => {
  const base = window.APP_BASE ?? '';
  const routeUrl = `${base}/ajax/route_request_handler.php`;
  const dispatchUrl = `${base}/ajax/review_dispatch.php`;
  const alertEl = document.getElementById('requestsAlert');

  function showMessage(message, type = 'danger') {
    if (!alertEl) return;
    alertEl.className = `alert alert-${type}`;
    alertEl.textContent = message;
    alertEl.classList.remove('d-none');
  }

  async function submit(url, values) {
    const body = new URLSearchParams({
      ...values,
      [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN,
    });
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body,
    });
    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch {
      throw new Error(`Server returned HTTP ${response.status} instead of JSON.`);
    }
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
      review(button, dispatchUrl, {
        dispatch_id: button.dataset.id,
        status: 'Rejected',
        remarks: remarks.trim(),
      });
    });
  });
});
