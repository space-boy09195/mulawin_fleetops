/**
 * users.js — Mulawin FleetOps
 * Handles: add/edit user, reset password, add/edit employee, filters, pw toggle.
 */

'use strict';

(function () {

  const BASE     = window.APP_BASE ?? '';
  const AJAX_URL = BASE + '/ajax/users_handler.php';

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

  function clearInputs(...els) {
    els.forEach(el => { if (el) el.value = ''; });
  }

  // ── Password visibility toggles ───────────────────────────────────────────
  document.querySelectorAll('.usr-pw-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      if (!input) return;
      const isText = input.type === 'text';
      input.type = isText ? 'password' : 'text';
      btn.querySelector('i').className = isText ? 'bi bi-eye' : 'bi bi-eye-slash';
    });
  });

  // ── Users filter ──────────────────────────────────────────────────────────
  const filterUserRole   = document.getElementById('filterUserRole');
  const filterUserSearch = document.getElementById('filterUserSearch');
  const usersTbody       = document.querySelector('#usersTable tbody');
  const noUserResults    = document.getElementById('noUserResults');

  function applyUserFilters() {
    if (!usersTbody) return;
    const roleVal   = filterUserRole?.value   ?? '';
    const searchVal = (filterUserSearch?.value ?? '').toLowerCase();
    let visibleCount = 0;
    usersTbody.querySelectorAll('tr').forEach(row => {
      const matchRole   = !roleVal   || row.dataset.role   === roleVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      const isMatch = matchRole && matchSearch;
      row.classList.toggle('usr-row-hidden', !isMatch);
      if (isMatch) visibleCount++;
    });
    noUserResults?.classList.toggle('d-none', visibleCount > 0);
  }

  filterUserRole?.addEventListener('change', applyUserFilters);
  filterUserSearch?.addEventListener('input', applyUserFilters);

  // ── Employees filter ──────────────────────────────────────────────────────
  const filterEmpStatus = document.getElementById('filterEmpStatus');
  const filterEmpSearch = document.getElementById('filterEmpSearch');
  const empTbody        = document.querySelector('#employeesTable tbody');
  const noEmployeeResults = document.getElementById('noEmployeeResults');

  function applyEmpFilters() {
    if (!empTbody) return;
    const statusVal = filterEmpStatus?.value ?? '';
    const searchVal = (filterEmpSearch?.value ?? '').toLowerCase();
    let visibleCount = 0;
    empTbody.querySelectorAll('tr').forEach(row => {
      const matchStatus = !statusVal || row.dataset.active === statusVal;
      const matchSearch = !searchVal || (row.dataset.search ?? '').includes(searchVal);
      const isMatch = matchStatus && matchSearch;
      row.classList.toggle('usr-row-hidden', !isMatch);
      if (isMatch) visibleCount++;
    });
    noEmployeeResults?.classList.toggle('d-none', visibleCount > 0);
  }

  filterEmpStatus?.addEventListener('change', applyEmpFilters);
  filterEmpSearch?.addEventListener('input', applyEmpFilters);

  // ══════════════════════════════════════════════════════════════════════════
  // ADD USER
  // ══════════════════════════════════════════════════════════════════════════
  const addUserModal  = document.getElementById('addUserModal');
  const auFullName    = document.getElementById('auFullName');
  const auUsername    = document.getElementById('auUsername');
  const auEmail       = document.getElementById('auEmail');
  const auRole        = document.getElementById('auRole');
  const auPassword    = document.getElementById('auPassword');
  const auConfirm     = document.getElementById('auConfirmPassword');
  const submitAddUser = document.getElementById('submitAddUserBtn');
  const auSpinner     = document.getElementById('auBtnSpinner');
  const addUserAlert  = document.getElementById('addUserAlert');

  addUserModal?.addEventListener('hidden.bs.modal', () => {
    clearInputs(auFullName, auUsername, auEmail, auPassword, auConfirm);
    if (auRole) auRole.value = '';
    hideAlert(addUserAlert);
    setBusy(submitAddUser, auSpinner, false);
  });

  submitAddUser?.addEventListener('click', () => {
    hideAlert(addUserAlert);

    const pw  = auPassword?.value  ?? '';
    const con = auConfirm?.value   ?? '';

    if (!auFullName?.value.trim() || !auUsername?.value.trim() || !auEmail?.value.trim() || !auRole?.value || !pw) {
      showAlert(addUserAlert, 'All fields are required.');
      return;
    }
    if (pw.length < 8) {
      showAlert(addUserAlert, 'Password must be at least 8 characters.');
      return;
    }
    if (pw !== con) {
      showAlert(addUserAlert, 'Passwords do not match.');
      return;
    }

    setBusy(submitAddUser, auSpinner, true);

    postAjax({
      action:    'add_user',
      full_name: auFullName.value.trim(),
      username:  auUsername.value.trim(),
      email:     auEmail.value.trim(),
      role_id:   auRole.value,
      password:  pw,
      confirm:   con,
    }).then(res => {
      setBusy(submitAddUser, auSpinner, false);
      if (res.success) { bootstrap.Modal.getInstance(addUserModal)?.hide(); window.location.reload(); }
      else showAlert(addUserAlert, res.message ?? 'Failed to add user.');
    }).catch(() => {
      setBusy(submitAddUser, auSpinner, false);
      showAlert(addUserAlert, 'Network error. Please try again.');
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // EDIT USER
  // ══════════════════════════════════════════════════════════════════════════
  const editUserModal  = document.getElementById('editUserModal');
  const euId           = document.getElementById('euId');
  const euFullName     = document.getElementById('euFullName');
  const euUsername     = document.getElementById('euUsername');
  const euEmail        = document.getElementById('euEmail');
  const euRole         = document.getElementById('euRole');
  const euActive       = document.getElementById('euActive');
  const submitEditUser = document.getElementById('submitEditUserBtn');
  const euSpinner      = document.getElementById('euBtnSpinner');
  const editUserAlert  = document.getElementById('editUserAlert');

  usersTbody?.addEventListener('click', e => {
    const btn = e.target.closest('.btn-usr-edit-user');
    if (!btn) return;

    if (euId)       euId.value       = btn.dataset.id       ?? '';
    if (euFullName) euFullName.value = btn.dataset.name     ?? '';
    if (euUsername) euUsername.value = btn.dataset.username ?? '';
    if (euEmail)    euEmail.value    = btn.dataset.email    ?? '';
    if (euRole)     euRole.value     = btn.dataset.role     ?? '';
    if (euActive)   euActive.checked = btn.dataset.active   === '1';
    if (euActive)   euActive.dataset.original = btn.dataset.active === '1' ? '1' : '0';

    hideAlert(editUserAlert);
    setBusy(submitEditUser, euSpinner, false);
    new bootstrap.Modal(editUserModal).show();
  });

  editUserModal?.addEventListener('hidden.bs.modal', () => {
    hideAlert(editUserAlert);
    setBusy(submitEditUser, euSpinner, false);
  });

  submitEditUser?.addEventListener('click', () => {
    hideAlert(editUserAlert);

    if (!euFullName?.value.trim() || !euUsername?.value.trim() || !euEmail?.value.trim() || !euRole?.value) {
      showAlert(editUserAlert, 'All fields are required.');
      return;
    }

    const wasActive = euActive?.dataset.original === '1';
    const isActive  = euActive?.checked ?? true;
    if (wasActive && !isActive) {
      if (!confirm(`Deactivate ${euFullName.value.trim()}? They will no longer be able to log in.`)) {
        return;
      }
    }

    setBusy(submitEditUser, euSpinner, true);

    postAjax({
      action:    'edit_user',
      user_id:   euId?.value   ?? '',
      full_name: euFullName.value.trim(),
      username:  euUsername.value.trim(),
      email:     euEmail.value.trim(),
      role_id:   euRole.value,
      is_active: euActive?.checked ? '1' : '0',
    }).then(res => {
      setBusy(submitEditUser, euSpinner, false);
      if (res.success) { bootstrap.Modal.getInstance(editUserModal)?.hide(); window.location.reload(); }
      else showAlert(editUserAlert, res.message ?? 'Failed to update user.');
    }).catch(() => {
      setBusy(submitEditUser, euSpinner, false);
      showAlert(editUserAlert, 'Network error. Please try again.');
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // RESET PASSWORD
  // ══════════════════════════════════════════════════════════════════════════
  const resetPwModal   = document.getElementById('resetPwModal');
  const rpUserId       = document.getElementById('rpUserId');
  const rpUserName     = document.getElementById('rpUserName');
  const rpPassword     = document.getElementById('rpPassword');
  const rpConfirm      = document.getElementById('rpConfirm');
  const submitResetPw  = document.getElementById('submitResetPwBtn');
  const rpSpinner      = document.getElementById('rpBtnSpinner');
  const resetPwAlert   = document.getElementById('resetPwAlert');

  usersTbody?.addEventListener('click', e => {
    const btn = e.target.closest('.btn-usr-reset-pw');
    if (!btn) return;

    if (rpUserId)   rpUserId.value        = btn.dataset.id   ?? '';
    if (rpUserName) rpUserName.textContent = btn.dataset.name ?? '';
    clearInputs(rpPassword, rpConfirm);
    hideAlert(resetPwAlert);
    setBusy(submitResetPw, rpSpinner, false);
    new bootstrap.Modal(resetPwModal).show();
  });

  resetPwModal?.addEventListener('hidden.bs.modal', () => {
    clearInputs(rpPassword, rpConfirm);
    hideAlert(resetPwAlert);
    setBusy(submitResetPw, rpSpinner, false);
  });

  submitResetPw?.addEventListener('click', () => {
    hideAlert(resetPwAlert);

    const pw  = rpPassword?.value ?? '';
    const con = rpConfirm?.value  ?? '';

    if (!pw) { showAlert(resetPwAlert, 'New password is required.'); return; }
    if (pw.length < 8) { showAlert(resetPwAlert, 'Password must be at least 8 characters.'); return; }
    if (pw !== con)    { showAlert(resetPwAlert, 'Passwords do not match.'); return; }

    const targetName = document.getElementById('rpUserName')?.textContent || 'this user';
    if (!confirm(`Reset the password for ${targetName}? Their current password will stop working immediately.`)) {
      return;
    }

    setBusy(submitResetPw, rpSpinner, true);

    postAjax({
      action:   'reset_password',
      user_id:  rpUserId?.value ?? '',
      password: pw,
      confirm:  con,
    }).then(res => {
      setBusy(submitResetPw, rpSpinner, false);
      if (res.success) {
        bootstrap.Modal.getInstance(resetPwModal)?.hide();
        showAlert(document.getElementById('editUserAlert'), res.message, 'success');
      } else showAlert(resetPwAlert, res.message ?? 'Failed to reset password.');
    }).catch(() => {
      setBusy(submitResetPw, rpSpinner, false);
      showAlert(resetPwAlert, 'Network error. Please try again.');
    });

    document.querySelectorAll('.js-review-reset').forEach(button => {
      button.addEventListener('click', async () => {
        const status = button.dataset.status;
        const notes = status === 'Rejected' ? (prompt('Reason for rejection:') || '').trim() : '';
        if (status === 'Rejected' && !notes) return;
        if (!confirm(`${status} this password request?`)) return;
        button.disabled = true;
        try {
          const result = await postAjax({
            action: 'review_password_reset',
            request_id: button.dataset.id,
            status,
            review_notes: notes,
          });
          if (result.success) window.location.reload();
          else { alert(result.message || 'Could not review request.'); button.disabled = false; }
        } catch {
          alert('Network error. Please try again.');
          button.disabled = false;
        }
      });
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // ADD EMPLOYEE
  // ══════════════════════════════════════════════════════════════════════════
  const addEmpModal   = document.getElementById('addEmpModal');
  const aeCode        = document.getElementById('aeCode');
  const aeName        = document.getElementById('aeName');
  const aePosition    = document.getElementById('aePosition');
  const aeContact     = document.getElementById('aeContact');
  const aeAddress     = document.getElementById('aeAddress');
  const aeLicense     = document.getElementById('aeLicense');
  const aeLicExpiry   = document.getElementById('aeLicenseExpiry');
  const aeLicType     = document.getElementById('aeLicenseType');
  const aeDateHired   = document.getElementById('aeDateHired');
  const aeEmploymentType = document.getElementById('aeEmploymentType');
  const aeContractorCompany = document.getElementById('aeContractorCompany');
  const aeDateResigned = document.getElementById('aeDateResigned');
  const aeResignationReason = document.getElementById('aeResignationReason');
  const aeContractorCompanyWrap = document.getElementById('aeContractorCompanyWrap');
  const submitAddEmp  = document.getElementById('submitAddEmpBtn');
  const aeSpinner     = document.getElementById('aeBtnSpinner');
  const addEmpAlert   = document.getElementById('addEmpAlert');

  addEmpModal?.addEventListener('hidden.bs.modal', () => {
    clearInputs(aeCode, aeName, aePosition, aeContact, aeAddress, aeLicense, aeLicExpiry, aeLicType, aeDateHired,
      aeContractorCompany, aeDateResigned, aeResignationReason);
    if (aeEmploymentType) aeEmploymentType.value = 'Employee';
    aeContractorCompanyWrap?.classList.add('d-none');
    if (aeAddress) aeAddress.value = '';
    hideAlert(addEmpAlert);
    setBusy(submitAddEmp, aeSpinner, false);
  });

  aeEmploymentType?.addEventListener('change', () => {
    aeContractorCompanyWrap?.classList.toggle('d-none', aeEmploymentType.value !== 'Contractor');
  });

  submitAddEmp?.addEventListener('click', () => {
    hideAlert(addEmpAlert);

    if (!aeCode?.value.trim() || !aeName?.value.trim() || !aePosition?.value.trim()) {
      showAlert(addEmpAlert, 'Employee code, name, and position are required.');
      return;
    }

    setBusy(submitAddEmp, aeSpinner, true);

    postAjax({
      action:          'add_employee',
      employee_code:   aeCode.value.trim(),
      full_name:       aeName.value.trim(),
      position:        aePosition.value.trim(),
      contact_number:  aeContact?.value.trim()  ?? '',
      address:         aeAddress?.value.trim()  ?? '',
      license_number:  aeLicense?.value.trim()  ?? '',
      license_expiry:  aeLicExpiry?.value        ?? '',
      license_type:    aeLicType?.value.trim()  ?? '',
      date_hired:      aeDateHired?.value        ?? '',
      employment_type: aeEmploymentType?.value ?? 'Employee',
      contractor_company: aeContractorCompany?.value.trim() ?? '',
      date_resigned: aeDateResigned?.value ?? '',
      resignation_reason: aeResignationReason?.value.trim() ?? '',
    }).then(res => {
      setBusy(submitAddEmp, aeSpinner, false);
      if (res.success) { bootstrap.Modal.getInstance(addEmpModal)?.hide(); window.location.reload(); }
      else showAlert(addEmpAlert, res.message ?? 'Failed to add employee.');
    }).catch(() => {
      setBusy(submitAddEmp, aeSpinner, false);
      showAlert(addEmpAlert, 'Network error. Please try again.');
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // EDIT EMPLOYEE
  // ══════════════════════════════════════════════════════════════════════════
  const editEmpModal  = document.getElementById('editEmpModal');
  const eeId          = document.getElementById('eeId');
  const eeCode        = document.getElementById('eeCode');
  const eeName        = document.getElementById('eeName');
  const eePosition    = document.getElementById('eePosition');
  const eeContact     = document.getElementById('eeContact');
  const eeAddress     = document.getElementById('eeAddress');
  const eeLicense     = document.getElementById('eeLicense');
  const eeLicExpiry   = document.getElementById('eeLicenseExpiry');
  const eeLicType     = document.getElementById('eeLicenseType');
  const eeDateHired   = document.getElementById('eeDateHired');
  const eeEmploymentType = document.getElementById('eeEmploymentType');
  const eeContractorCompany = document.getElementById('eeContractorCompany');
  const eeDateResigned = document.getElementById('eeDateResigned');
  const eeResignationReason = document.getElementById('eeResignationReason');
  const eeContractorCompanyWrap = document.getElementById('eeContractorCompanyWrap');
  const eeActive      = document.getElementById('eeActive');
  const submitEditEmp = document.getElementById('submitEditEmpBtn');
  const eeSpinner     = document.getElementById('eeBtnSpinner');
  const editEmpAlert  = document.getElementById('editEmpAlert');

  empTbody?.addEventListener('click', e => {
    const btn = e.target.closest('.btn-usr-edit-emp');
    if (!btn) return;

    if (eeId)        eeId.value        = btn.dataset.id            ?? '';
    if (eeCode)      eeCode.value      = btn.dataset.code          ?? '';
    if (eeName)      eeName.value      = btn.dataset.name          ?? '';
    if (eePosition)  eePosition.value  = btn.dataset.position      ?? '';
    if (eeContact)   eeContact.value   = btn.dataset.contact       ?? '';
    if (eeAddress)   eeAddress.value   = btn.dataset.address       ?? '';
    if (eeLicense)   eeLicense.value   = btn.dataset.license       ?? '';
    if (eeLicExpiry) eeLicExpiry.value = btn.dataset.licenseExpiry ?? '';
    if (eeLicType)   eeLicType.value   = btn.dataset.licenseType   ?? '';
    if (eeDateHired) eeDateHired.value = btn.dataset.hired         ?? '';
    if (eeEmploymentType) eeEmploymentType.value = btn.dataset.employmentType ?? 'Employee';
    if (eeContractorCompany) eeContractorCompany.value = btn.dataset.contractorCompany ?? '';
    if (eeDateResigned) eeDateResigned.value = btn.dataset.dateResigned ?? '';
    if (eeResignationReason) eeResignationReason.value = btn.dataset.resignationReason ?? '';
    eeContractorCompanyWrap?.classList.toggle('d-none', eeEmploymentType?.value !== 'Contractor');
    if (eeActive)    eeActive.checked  = btn.dataset.active        === '1';
    if (eeActive)    eeActive.dataset.original = btn.dataset.active === '1' ? '1' : '0';

    hideAlert(editEmpAlert);
    setBusy(submitEditEmp, eeSpinner, false);
    new bootstrap.Modal(editEmpModal).show();
  });
  eeEmploymentType?.addEventListener('change', () => {
    eeContractorCompanyWrap?.classList.toggle('d-none', eeEmploymentType.value !== 'Contractor');
  });

  editEmpModal?.addEventListener('hidden.bs.modal', () => {
    hideAlert(editEmpAlert);
    setBusy(submitEditEmp, eeSpinner, false);
  });

  submitEditEmp?.addEventListener('click', () => {
    hideAlert(editEmpAlert);

    if (!eeCode?.value.trim() || !eeName?.value.trim() || !eePosition?.value.trim()) {
      showAlert(editEmpAlert, 'Employee code, name, and position are required.');
      return;
    }

    const empWasActive = eeActive?.dataset.original === '1';
    const empIsActive  = eeActive?.checked ?? true;
    if (empWasActive && !empIsActive) {
      if (!confirm(`Deactivate ${eeName.value.trim()}? They will no longer be assignable to dispatches.`)) {
        return;
      }
    }

    setBusy(submitEditEmp, eeSpinner, true);

    postAjax({
      action:          'edit_employee',
      employee_id:     eeId?.value          ?? '',
      employee_code:   eeCode.value.trim(),
      full_name:       eeName.value.trim(),
      position:        eePosition.value.trim(),
      contact_number:  eeContact?.value.trim()  ?? '',
      address:         eeAddress?.value.trim()  ?? '',
      license_number:  eeLicense?.value.trim()  ?? '',
      license_expiry:  eeLicExpiry?.value        ?? '',
      license_type:    eeLicType?.value.trim()  ?? '',
      date_hired:      eeDateHired?.value        ?? '',
      employment_type: eeEmploymentType?.value ?? 'Employee',
      contractor_company: eeContractorCompany?.value.trim() ?? '',
      date_resigned: eeDateResigned?.value ?? '',
      resignation_reason: eeResignationReason?.value.trim() ?? '',
      is_active:       eeDateResigned?.value ? '0' : (eeActive?.checked ? '1' : '0'),
    }).then(res => {
      setBusy(submitEditEmp, eeSpinner, false);
      if (res.success) { bootstrap.Modal.getInstance(editEmpModal)?.hide(); window.location.reload(); }
      else showAlert(editEmpAlert, res.message ?? 'Failed to update employee.');
    }).catch(() => {
      setBusy(submitEditEmp, eeSpinner, false);
      showAlert(editEmpAlert, 'Network error. Please try again.');
    });
  });

})();