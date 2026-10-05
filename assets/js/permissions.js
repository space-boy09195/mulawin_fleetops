(function () {
  'use strict';

  const endpoint = `${window.APP_BASE}/ajax/permissions_handler.php`;
  const feedback = document.getElementById('permissionsFeedback');
  const permissionsForm = document.getElementById('permissionsForm');

  function showFeedback(message, success) {
    feedback.textContent = message;
    feedback.className = `alert alert-${success ? 'success' : 'danger'}`;
    feedback.classList.remove('d-none');
  }

  const permissionInputs = permissionsForm
    ? Array.from(permissionsForm.querySelectorAll('input[name="permission_ids[]"]'))
    : [];
  let savedPermissions = new Set(permissionInputs.filter(input => input.checked).map(input => input.value));
  let savedRoleId = document.getElementById('roleId')?.value || '';

  function currentPermissions() {
    return new Set(permissionInputs.filter(input => input.checked).map(input => input.value));
  }

  function moduleAccessLabel(enabled, total) {
    if (enabled === 0) return 'No access';
    return enabled === total ? 'All permissions' : 'Some access';
  }

  function syncPermissionControls() {
    if (!permissionsForm) return;
    const selected = currentPermissions();
    const added = [...selected].filter(id => !savedPermissions.has(id)).length;
    const removed = [...savedPermissions].filter(id => !selected.has(id)).length;
    const count = document.getElementById('permissionCount');
    const changes = document.getElementById('permissionChanges');
    const saveButton = document.getElementById('savePermissionsButton');
    const resetButton = document.getElementById('resetPermissionsButton');
    const total = permissionInputs.length;

    count.textContent = `${selected.size} of ${total} permissions enabled`;
    changes.textContent = added || removed
      ? `${added} permission${added === 1 ? '' : 's'} to add · ${removed} to remove`
      : 'No unsaved changes.';
    saveButton.disabled = added === 0 && removed === 0;
    resetButton.disabled = added === 0 && removed === 0;

    permissionsForm.querySelectorAll('[data-permission-group]').forEach(group => {
      const checkboxes = Array.from(group.querySelectorAll('input[name="permission_ids[]"]'));
      const enabled = checkboxes.filter(input => input.checked).length;
      group.querySelector('[data-group-count]').textContent = `${enabled} of ${checkboxes.length} enabled`;
      const summary = document.getElementById(group.dataset.summaryId);
      const summaryStatus = summary?.querySelector('[data-summary-status]');
      if (summaryStatus) {
        summaryStatus.textContent = moduleAccessLabel(enabled, checkboxes.length);
      }
    });
  }

  function filterPermissions(query) {
    const normalized = query.trim().toLocaleLowerCase();
    let matchingEntries = 0;
    permissionsForm?.querySelectorAll('[data-permission-group]').forEach(group => {
      let visible = 0;
      group.querySelectorAll('[data-permission-entry]').forEach(entry => {
        const searchText = entry.dataset.searchText || entry.textContent;
        const matches = !normalized || searchText.toLocaleLowerCase().includes(normalized);
        entry.hidden = !matches;
        if (matches) {
          visible += 1;
          matchingEntries += 1;
        }
      });
      group.hidden = visible === 0;
    });
    document.getElementById('permissionNoResults')
      ?.classList.toggle('d-none', !normalized || matchingEntries > 0);
  }

  permissionInputs.forEach(input => input.addEventListener('change', syncPermissionControls));
  permissionsForm?.querySelectorAll('[data-group-select-all]').forEach(button => {
    button.addEventListener('click', () => {
      button.closest('[data-permission-group]')
        .querySelectorAll('input[name="permission_ids[]"]')
        .forEach(input => { input.checked = true; });
      syncPermissionControls();
    });
  });
  permissionsForm?.querySelectorAll('[data-group-clear]').forEach(button => {
    button.addEventListener('click', () => {
      button.closest('[data-permission-group]')
        .querySelectorAll('input[name="permission_ids[]"]')
        .forEach(input => { input.checked = false; });
      syncPermissionControls();
    });
  });
  document.getElementById('permissionSearch')?.addEventListener('input', event => {
    filterPermissions(event.currentTarget.value);
  });
  document.getElementById('resetPermissionsButton')?.addEventListener('click', () => {
    permissionInputs.forEach(input => { input.checked = savedPermissions.has(input.value); });
    syncPermissionControls();
  });

  document.getElementById('roleId')?.addEventListener('change', event => {
    const roleSelect = event.currentTarget;
    const selected = currentPermissions();
    const hasChanges = [...selected].some(id => !savedPermissions.has(id))
      || [...savedPermissions].some(id => !selected.has(id));
    if (hasChanges && !window.confirm('Discard unsaved permission changes and switch roles?')) {
      roleSelect.value = savedRoleId;
      return;
    }
    roleSelect.form.submit();
  });
  syncPermissionControls();

  async function submit(form, action) {
    const data = new FormData(form);
    data.set('action', action);
    data.set(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
    const response = await fetch(endpoint, {
      method: 'POST',
      body: data,
      headers: { Accept: 'application/json' }
    });
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'The request could not be completed.');
    }
    return result;
  }

  permissionsForm?.addEventListener('submit', async event => {
    event.preventDefault();
    const selected = currentPermissions();
    const added = [...selected].filter(id => !savedPermissions.has(id)).length;
    const removed = [...savedPermissions].filter(id => !selected.has(id)).length;
    const roleName = document.getElementById('roleId')?.selectedOptions[0]?.textContent.trim() || 'this role';
    if (!window.confirm(`Save changes for ${roleName}?\n\n${added} permission${added === 1 ? '' : 's'} will be added and ${removed} removed.`)) {
      return;
    }

    const saveButton = document.getElementById('savePermissionsButton');
    saveButton.disabled = true;
    try {
      const result = await submit(permissionsForm, 'save_role_permissions');
      savedPermissions = currentPermissions();
      syncPermissionControls();
      showFeedback(`${result.message} New grants are used on the next request; refresh to update navigation.`, true);
    } catch (error) {
      showFeedback(error.message, false);
      syncPermissionControls();
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
