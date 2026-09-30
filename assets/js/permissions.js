(function () {
  'use strict';

  const endpoint = `${window.APP_BASE}/ajax/permissions_handler.php`;
  const feedback = document.getElementById('permissionsFeedback');

  function showFeedback(message, success) {
    feedback.textContent = message;
    feedback.className = `alert alert-${success ? 'success' : 'danger'}`;
    feedback.classList.remove('d-none');
  }

  async function submit(form, action) {
    const data = new FormData(form);
    data.set('action', action);
    data.set(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
    const response = await fetch(endpoint, { method: 'POST', body: data });
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'The request could not be completed.');
    }
    return result;
  }

  document.getElementById('permissionsForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await submit(event.currentTarget, 'save_role_permissions');
      showFeedback(result.message, true);
    } catch (error) {
      showFeedback(error.message, false);
    }
  });

  document.getElementById('approvalChainsForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await submit(event.currentTarget, 'save_approval_chains');
      showFeedback(result.message, true);
    } catch (error) {
      showFeedback(error.message, false);
    }
  });

  document.getElementById('createRoleForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await submit(event.currentTarget, 'create_role');
      showFeedback(result.message, true);
      const url = new URL(window.location.href);
      url.searchParams.set('role_id', result.role_id);
      window.location.assign(url);
    } catch (error) {
      showFeedback(error.message, false);
    }
  });

  document.getElementById('emailDomainForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await submit(event.currentTarget, 'save_email_domain');
      showFeedback(result.message, true);
    } catch (error) {
      showFeedback(error.message, false);
    }
  });

  document.getElementById('reminderThresholdsForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await submit(event.currentTarget, 'save_reminder_thresholds');
      showFeedback(result.message, true);
    } catch (error) {
      showFeedback(error.message, false);
    }
  });

  document.getElementById('crewLabelForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await submit(event.currentTarget, 'save_crew_label');
      showFeedback(result.message, true);
    } catch (error) {
      showFeedback(error.message, false);
    }
  });
})();
