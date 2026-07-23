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
      body:    new URLSearchParams(data),
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

  // ── Driver-conditional employee validation helpers ──────────────────────────
  const LICENSE_FORMAT_RE = /^[A-Za-z]\d{2}-\d{2}-\d{6}$/;

  function isDriverPosition(value) {
    return (value ?? '').trim().toLowerCase() === 'driver';
  }

  // Toggles the red-asterisk "required" indicators for the license/date-hired
  // fields based on whether the entered position is "Driver".
  function updateDriverRequiredUI(prefix) {
    const positionEl = document.getElementById(prefix + 'Position');
    const hintEl      = document.getElementById(prefix + 'DriverHint');
    const isDriver    = isDriverPosition(positionEl?.value);

    hintEl?.classList.toggle('d-none', !isDriver);
    ['LicenseReq', 'LicExpiryReq', 'LicTypeReq', 'DateHiredReq'].forEach(suffix => {
      document.getElementById(prefix + suffix)?.classList.toggle('d-none', !isDriver);
    });
  }

  // Runs the same license/driver validation the backend enforces, so the
  // person gets immediate feedback instead of waiting on a round trip.
  function validateEmpFieldsClient({ position, license, licExpiry, licType, dateHired }) {
    const isDriver = isDriverPosition(position);
    const hasLic   = !!license || !!licExpiry;

    if (hasLic && !license)   return 'License number is required with expiry.';
    if (hasLic && !licExpiry) return 'License expiry is required with license number.';

    if (isDriver) {
      if (!license)   return 'License number is required for drivers.';
      if (!licExpiry) return 'License expiry is required for drivers.';
      if (!licType)   return 'License type is required for drivers.';
      if (!dateHired) return 'Date hired is required for drivers.';
    }

    if (license && !LICENSE_FORMAT_RE.test(license)) {
      return 'License number must be in the format X00-00-000000 (e.g. N01-12-123456).';
    }

    return null;
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
  const submitAddEmp  = document.getElementById('submitAddEmpBtn');
  const aeSpinner     = document.getElementById('aeBtnSpinner');
  const addEmpAlert   = document.getElementById('addEmpAlert');

  addEmpModal?.addEventListener('hidden.bs.modal', () => {
    clearInputs(aeCode, aeName, aePosition, aeContact, aeAddress, aeLicense, aeLicExpiry, aeLicType, aeDateHired);
    if (aeAddress) aeAddress.value = '';
    hideAlert(addEmpAlert);
    setBusy(submitAddEmp, aeSpinner, false);
    updateDriverRequiredUI('ae');
  });

  aePosition?.addEventListener('input', () => updateDriverRequiredUI('ae'));

  submitAddEmp?.addEventListener('click', () => {
    hideAlert(addEmpAlert);

    if (!aeCode?.value.trim() || !aeName?.value.trim() || !aePosition?.value.trim()) {
      showAlert(addEmpAlert, 'Employee code, name, and position are required.');
      return;
    }

    const license   = aeLicense?.value.trim()   ?? '';
    const licExpiry = aeLicExpiry?.value        ?? '';
    const licType   = aeLicType?.value.trim()   ?? '';
    const dateHired = aeDateHired?.value        ?? '';

    const empErr = validateEmpFieldsClient({
      position: aePosition.value, license, licExpiry, licType, dateHired,
    });
    if (empErr) {
      showAlert(addEmpAlert, empErr);
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
      license_number:  license,
      license_expiry:  licExpiry,
      license_type:    licType,
      date_hired:      dateHired,
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
    if (eeActive)    eeActive.checked  = btn.dataset.active        === '1';

    hideAlert(editEmpAlert);
    setBusy(submitEditEmp, eeSpinner, false);
    updateDriverRequiredUI('ee');
    new bootstrap.Modal(editEmpModal).show();
  });

  eePosition?.addEventListener('input', () => updateDriverRequiredUI('ee'));

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

    const license   = eeLicense?.value.trim()   ?? '';
    const licExpiry = eeLicExpiry?.value        ?? '';
    const licType   = eeLicType?.value.trim()   ?? '';
    const dateHired = eeDateHired?.value        ?? '';

    const empErr = validateEmpFieldsClient({
      position: eePosition.value, license, licExpiry, licType, dateHired,
    });
    if (empErr) {
      showAlert(editEmpAlert, empErr);
      return;
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
      license_number:  license,
      license_expiry:  licExpiry,
      license_type:    licType,
      date_hired:      dateHired,
      is_active:       eeActive?.checked ? '1' : '0',
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