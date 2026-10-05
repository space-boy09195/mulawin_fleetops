<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/app_settings.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('permissions.manage');

$pdo = getDBConnection();
$roles = $pdo->query('SELECT role_id, role_name FROM roles ORDER BY role_name')->fetchAll();
$permissions = $pdo->query(
    'SELECT permission_id, permission_key, module_name, action_name, description
     FROM permissions
     ORDER BY module_name, action_name, permission_key'
)->fetchAll();
$approvalTypes = $pdo->query(
    'SELECT request_type, display_name
     FROM approval_request_types
     WHERE is_active = 1
     ORDER BY display_name'
)->fetchAll();
$approvalRoleRows = $pdo->query(
    'SELECT request_type, step_order, approver_role_id
     FROM approval_role_steps
     ORDER BY request_type, step_order'
)->fetchAll(PDO::FETCH_ASSOC);
$approvalRoles = [];
foreach ($approvalRoleRows as $approvalRoleRow) {
    $approvalRoles[$approvalRoleRow['request_type']][(int)$approvalRoleRow['step_order']] =
        (int)$approvalRoleRow['approver_role_id'];
}
$selectedRoleId = filter_input(INPUT_GET, 'role_id', FILTER_VALIDATE_INT);
if (!$selectedRoleId || !array_filter($roles, static fn(array $role): bool => (int)$role['role_id'] === $selectedRoleId)) {
    $selectedRoleId = (int)($roles[0]['role_id'] ?? 0);
}
$selectedRoleName = '';
foreach ($roles as $role) {
    if ((int)$role['role_id'] === $selectedRoleId) {
        $selectedRoleName = (string)$role['role_name'];
        break;
    }
}
$grantedIds = [];
if ($selectedRoleId > 0) {
    $grantQuery = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
    $grantQuery->execute([$selectedRoleId]);
    $grantedIds = array_map('intval', $grantQuery->fetchAll(PDO::FETCH_COLUMN));
}
$emailDomain = getAppSetting($pdo, 'company_email_domain', COMPANY_EMAIL_DOMAIN) ?? COMPANY_EMAIL_DOMAIN;
$reminderThresholds = getAppSetting($pdo, 'reminder_thresholds_days', '60,30,15,7') ?? '60,30,15,7';
$crewLabel = getAppSetting($pdo, 'crew_module_label', 'Drivers & Helpers') ?? 'Drivers & Helpers';

$permissionLabels = [
    'legacy.role.1' => 'Legacy Admin Page Access',
    'legacy.role.2' => 'Legacy Dispatcher Page Access',
    'legacy.role.3' => 'Legacy Maintenance Page Access',
    'legacy.role.4' => 'Legacy Accounting Page Access',
    'roles.manage' => 'Create System Roles',
    'permissions.manage' => 'Manage Role Permissions',
    'approvals.manage' => 'Configure Approval Chains',
    'approvals.review' => 'Review Approval Requests',
    'users.view' => 'View User Accounts',
    'users.manage' => 'Manage User Accounts',
    'fleet.view' => 'View Fleet',
    'fleet.manage' => 'Manage Fleet',
    'fleet.status.update' => 'Update Vehicle Status',
    'employees.view' => 'View Employee & Crew Records',
    'employees.manage' => 'Manage Employee & Crew Records',
    'clients.view' => 'View Clients',
    'clients.manage' => 'Manage Clients',
    'routes.view' => 'View Routes & Locations',
    'routes.manage' => 'Manage Routes & Locations',
    'routes.request' => 'Submit Route Requests',
    'routes.approve' => 'Approve Route Requests',
    'trips.view' => 'View Trips',
    'trips.create' => 'Create Trips',
    'trips.update' => 'Update Trip Information',
    'trips.assign' => 'Assign Trucks & Crew to Trips',
    'trips.approve' => 'Approve Trip Requests',
    'trips.complete' => 'Submit Trip Completion Records',
    'dispatch.clear' => 'Grant Dispatch Clearance',
    'dispatch.instructions.manage' => 'Manage Shift Dispatch Instructions',
    'dispatch.instructions.encode' => 'Encode Dispatch Instructions',
    'dispatch.shift.day' => 'Encode Day-Shift Dispatches',
    'dispatch.shift.night' => 'Encode Night-Shift Dispatches',
    'dispatch.vehicle.car_carrier' => 'Encode Car Carrier Dispatches',
    'dispatch.vehicle.container' => 'Encode Container Dispatches',
    'dispatch.vehicle.wing_van' => 'Encode Wing Van Dispatches',
    'maintenance.view' => 'View Maintenance Records',
    'maintenance.manage' => 'Manage Maintenance Records',
    'maintenance.reports.view' => 'View Maintenance Reports',
    'parts.view' => 'View Parts Inventory',
    'parts.manage' => 'Manage Parts Inventory',
    'billing.view' => 'View Billing & Collections',
    'billing.manage' => 'Manage Billing & Collections',
    'finance.view' => 'View Finance Records',
    'finance.manage' => 'Manage Finance Records',
    'finance.funds.view' => 'View Fund Requests & Cash Advances',
    'finance.funds.manage' => 'Manage Fund Requests & Cash Advances',
    'finance.disbursements.view' => 'View Disbursement Records',
    'finance.disbursements.manage' => 'Record Approved Disbursements',
    'finance.ap.view' => 'View Accounts Payable & Vouchers',
    'finance.ap.manage' => 'Manage Accounts Payable & Vouchers',
    'payroll.view' => 'View Payroll Records',
    'payroll.manage' => 'Prepare & Manage Payroll',
    'documents.view' => 'View Authorized Documents',
    'documents.upload' => 'Upload Documents',
    'documents.download' => 'Download Authorized Documents',
    'documents.manage' => 'Manage Document Records',
    'reports.view' => 'View Reports',
    'reports.export' => 'Export Report Data',
    'operations.dashboard.view' => 'View Operations Head Dashboard',
    'company.dashboard.view' => 'View Company Dashboard',
    'operations.reports.view' => 'View Operations Performance Reports',
    'audit.view' => 'View Audit History',
    'messages.view' => 'View Authorized Conversations',
    'messages.send' => 'Send Authorized Messages',
    'hr.attendance.view' => 'View Attendance & Timekeeping',
    'hr.attendance.manage' => 'Record & Update Attendance',
    'hr.attendance.report' => 'View & Export Attendance Reports',
    'hr.recruitment.view' => 'View Recruitment Candidates',
    'hr.recruitment.manage' => 'Manage Recruitment Candidates',
    'admin.supplies.view' => 'View Office Supplies Inventory',
    'admin.supplies.manage' => 'Manage Office Supplies Inventory',
    'purchasing.view' => 'View Suppliers & Purchase Orders',
    'purchasing.manage' => 'Create & Receive Purchase Orders',
    'incidents.manage' => 'Report & Resolve Trip Incidents',
];

$moduleLabels = [
    'legacy' => 'Legacy Page Access',
    'admin' => 'Administration',
    'approvals' => 'Approval Workflows',
    'users' => 'User Management',
    'fleet' => 'Fleet Management',
    'employees' => 'Employee & Crew Management',
    'clients' => 'Client Management',
    'routes' => 'Route Management',
    'trips' => 'Trip Management',
    'dispatch' => 'Dispatch',
    'maintenance' => 'Maintenance',
    'inventory' => 'Inventory & Parts',
    'billing' => 'Accounting & Billing',
    'finance' => 'Accounting & Billing',
    'payroll' => 'Payroll',
    'documents' => 'Documents',
    'reports' => 'Analytics & Reports',
    'operations' => 'Operations',
    'company' => 'Dashboard',
    'audit' => 'Audit & History',
    'messages' => 'Messages',
    'hr' => 'Human Resources',
    'purchasing' => 'Purchasing',
    'incidents' => 'Trip Incidents',
];

$specialCategories = [
    'roles.manage' => 'Role & Permission Management',
    'permissions.manage' => 'Role & Permission Management',
    'admin.supplies.view' => 'Office Supplies',
    'admin.supplies.manage' => 'Office Supplies',
    'company.dashboard.view' => 'Dashboard',
    'operations.dashboard.view' => 'Dashboard',
    'operations.reports.view' => 'Analytics & Reports',
    'maintenance.reports.view' => 'Analytics & Reports',
    'hr.attendance.view' => 'Attendance',
    'hr.attendance.manage' => 'Attendance',
    'hr.attendance.report' => 'Attendance',
    'hr.recruitment.view' => 'Recruitment',
    'hr.recruitment.manage' => 'Recruitment',
];

$permissionWarnings = [
    'roles.manage' => 'High privilege — allows creating system roles.',
    'permissions.manage' => 'High privilege — changes role access and company-wide settings on this page.',
    'users.manage' => 'High privilege — manages accounts, assigns roles, and can reset passwords.',
    'approvals.manage' => 'High privilege — changes approver roles and approval order.',
    'billing.manage' => 'Sensitive financial access — can change billing and collection records.',
    'finance.manage' => 'Sensitive financial access — can change finance records.',
    'finance.funds.manage' => 'Sensitive financial access — can create and manage fund requests and cash advances.',
    'finance.disbursements.manage' => 'Sensitive financial access — can record approved disbursements.',
    'finance.ap.manage' => 'Sensitive financial access — can create accounts payable and payment vouchers.',
    'payroll.manage' => 'Sensitive payroll access — can prepare and manage payroll.',
];

$permissionsByModule = [];
foreach ($permissions as $permission) {
    $permissionKey = (string)$permission['permission_key'];
    $category = $specialCategories[$permissionKey]
        ?? $moduleLabels[$permission['module_name']]
        ?? ucwords(str_replace('_', ' ', (string)$permission['module_name']));
    $description = trim((string)$permission['description']);

    if (preg_match('/^legacy\\.role\\.(\\d+)$/', $permissionKey, $legacyMatch)) {
        $legacyRoleNames = [1 => 'Admin', 2 => 'Dispatcher', 3 => 'Maintenance', 4 => 'Accounting'];
        $legacyRoleName = $legacyRoleNames[(int)$legacyMatch[1]] ?? 'system role';
        $description = "Allows access to older pages that still check the {$legacyRoleName} role.";
    } elseif ($permissionKey === 'permissions.manage') {
        $description = "Allows changing role permission grants and this page's company email domain, reminder thresholds, and crew label settings.";
    } elseif ($permissionKey === 'users.manage') {
        $description = 'Allows creating, editing, and deactivating accounts, assigning user roles, and reviewing or resetting passwords.';
    } elseif ($permissionKey === 'roles.manage') {
        $description = 'Allows creating system roles. The permissions assigned to each role are managed separately.';
    }

    if ($description !== '' && !preg_match('/[.!?]$/u', $description)) {
        $description .= '.';
    }
    $permission['display_name'] = $permissionLabels[$permissionKey]
        ?? rtrim($description, '.!?');
    $permission['display_description'] = $description;
    $permission['category'] = $category;
    $permission['warning'] = $permissionWarnings[$permissionKey] ?? '';
    $permissionsByModule[$category][] = $permission;
}
ksort($permissionsByModule, SORT_NATURAL | SORT_FLAG_CASE);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/permissions.js';
layoutHead('Role Permissions');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title">Role &amp; Permission Management</h1>
    <p class="page-subtitle">Choose what each role can do. User Management assigns a role to each account; this page controls the access that role grants.</p>
  </div>
</div>

<div id="permissionsFeedback" class="alert d-none" role="status"></div>

<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Select role</h2></div>
  <div class="card-body-custom">
    <form method="get" class="d-flex flex-wrap align-items-end gap-3">
      <div>
        <label for="roleId" class="form-label">Role</label>
        <select id="roleId" name="role_id" class="form-select">
          <?php foreach ($roles as $role): ?>
          <option value="<?= (int)$role['role_id'] ?>" <?= (int)$role['role_id'] === $selectedRoleId ? 'selected' : '' ?>>
            <?= htmlspecialchars($role['role_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <span class="text-muted small">Legacy page access remains available through the corresponding legacy role permission.</span>
    </form>
  </div>
</div>

<?php if (currentUserHasAnyPermission(['roles.manage'])): ?>
<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Add role</h2></div>
  <div class="card-body-custom">
    <form id="createRoleForm" class="d-flex flex-wrap gap-2 align-items-end">
      <div>
        <label for="newRoleName" class="form-label">Role name</label>
        <input id="newRoleName" name="role_name" class="form-control" maxlength="50" required>
      </div>
      <button type="submit" class="btn btn-outline-primary">Create role</button>
      <span class="small text-muted">New roles start with no permissions.</span>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Role &amp; Permission Management</h2></div>
  <div class="card-body-custom">
    <?php if ($selectedRoleId > 0): ?>
    <form id="permissionsForm">
      <input type="hidden" name="role_id" value="<?= $selectedRoleId ?>">
      <section class="border rounded bg-light p-3 mb-4" aria-label="Selected role access summary">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
          <div>
            <div class="small text-uppercase fw-semibold text-muted">Selected role</div>
            <strong><?= htmlspecialchars($selectedRoleName, ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
          <div class="text-sm-end">
            <strong id="permissionCount" role="status" aria-live="polite">
              <?= count($grantedIds) ?> of <?= count($permissions) ?> permissions enabled
            </strong>
            <div id="permissionChanges" class="small text-muted" aria-live="polite">No unsaved changes.</div>
          </div>
        </div>
        <div class="small text-uppercase fw-semibold text-muted mb-2">Module access</div>
        <div class="row g-2">
          <?php foreach ($permissionsByModule as $module => $modulePermissions): ?>
          <?php
            $enabledInModule = count(array_filter(
                $modulePermissions,
                static fn(array $permission): bool => in_array((int)$permission['permission_id'], $grantedIds, true)
            ));
            $summaryId = 'permission-summary-' . preg_replace('/[^A-Za-z0-9_-]/', '-', strtolower((string)$module));
            $moduleStatus = $enabledInModule === 0
                ? 'No access'
                : ($enabledInModule === count($modulePermissions) ? 'All permissions' : 'Some access');
          ?>
          <div class="col-6 col-md-4 col-xl-3">
            <div id="<?= htmlspecialchars($summaryId, ENT_QUOTES, 'UTF-8') ?>"
                 class="border rounded bg-white px-2 py-2 h-100"
                 data-summary-module>
              <span class="d-block small fw-semibold"><?= htmlspecialchars($module, ENT_QUOTES, 'UTF-8') ?></span>
              <span class="small text-muted" data-summary-status><?= $moduleStatus ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
        <div>
          <label for="permissionSearch" class="form-label">Search permissions</label>
          <input id="permissionSearch" class="form-control" type="search" autocomplete="off"
                 placeholder="Search name, description, module, or key" aria-controls="permissionGroups">
        </div>
      </div>
      <div id="permissionGroups">
      <?php foreach ($permissionsByModule as $module => $modulePermissions): ?>
      <?php $moduleId = 'permission-module-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$module); ?>
      <?php $summaryId = 'permission-summary-' . preg_replace('/[^A-Za-z0-9_-]/', '-', strtolower((string)$module)); ?>
      <section class="mb-4 permission-group" data-permission-group data-summary-id="<?= htmlspecialchars($summaryId, ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="<?= htmlspecialchars($moduleId, ENT_QUOTES, 'UTF-8') ?>">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <h3 class="h6 text-uppercase fw-semibold mb-0" id="<?= htmlspecialchars($moduleId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($module, ENT_QUOTES, 'UTF-8') ?></h3>
          <div class="d-flex align-items-center gap-2">
            <span class="small text-muted" data-group-count></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-group-select-all
                    aria-label="Select all permissions in <?= htmlspecialchars($module, ENT_QUOTES, 'UTF-8') ?>">Select all</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-group-clear
                    aria-label="Clear permissions in <?= htmlspecialchars($module, ENT_QUOTES, 'UTF-8') ?>">Clear</button>
          </div>
        </div>
        <div class="row g-2">
          <?php foreach ($modulePermissions as $permission): ?>
          <?php
            $searchText = implode(' ', [
                $permission['display_name'],
                $permission['display_description'],
                $permission['category'],
                $permission['permission_key'],
            ]);
          ?>
          <div class="col-12 col-md-6 col-xl-4 permission-entry" data-permission-entry
               data-search-text="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
            <label class="form-check border rounded p-3 h-100<?= $permission['warning'] !== '' ? ' border-warning' : '' ?>">
              <input class="form-check-input" type="checkbox" name="permission_ids[]"
                     value="<?= (int)$permission['permission_id'] ?>"
                     <?= in_array((int)$permission['permission_id'], $grantedIds, true) ? 'checked' : '' ?>>
              <span class="form-check-label">
                <strong><?= htmlspecialchars($permission['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="d-block small text-muted mt-1"><?= htmlspecialchars($permission['display_description'], ENT_QUOTES, 'UTF-8') ?></span>
                <code class="d-block small text-muted mt-1">Key: <?= htmlspecialchars($permission['permission_key'], ENT_QUOTES, 'UTF-8') ?></code>
                <?php if ($permission['warning'] !== ''): ?>
                <span class="d-block small text-danger fw-semibold mt-2">
                  <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><?= htmlspecialchars($permission['warning'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
              </span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
      </div>
      <p id="permissionNoResults" class="text-muted d-none" role="status">No permissions match your search.</p>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <button id="savePermissionsButton" type="submit" class="btn btn-primary" disabled>Save Changes</button>
        <button id="resetPermissionsButton" type="button" class="btn btn-outline-secondary" disabled>Reset Changes</button>
      </div>
    </form>
    <?php else: ?>
    <p class="text-muted mb-0">No roles are available.</p>
    <?php endif; ?>
  </div>
</div>

<?php if (currentUserHasAnyPermission(['approvals.manage'])): ?>
<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Approval chains</h2></div>
  <div class="card-body-custom">
    <p class="small text-muted">Selected roles approve sequentially in the order shown. At least one role is required per request type.</p>
    <form id="approvalChainsForm">
      <?php foreach ($approvalTypes as $type): ?>
      <div class="row g-3 align-items-start border-bottom py-3">
        <div class="col-12 col-md-4">
          <label class="form-label" for="approvers-<?= htmlspecialchars($type['request_type']) ?>-1">
            <?= htmlspecialchars($type['display_name']) ?>
          </label>
        </div>
        <div class="col-12 col-md-8">
          <?php for ($stepOrder = 1; $stepOrder <= 6; $stepOrder++): ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="small text-muted" style="min-width:48px;">Step <?= $stepOrder ?></span>
            <select class="form-select" id="approvers-<?= htmlspecialchars($type['request_type']) ?>-<?= $stepOrder ?>"
                    name="approver_roles[<?= htmlspecialchars($type['request_type']) ?>][<?= $stepOrder ?>]">
              <option value="">No approver</option>
              <?php foreach ($roles as $role): ?>
              <option value="<?= (int)$role['role_id'] ?>"
                <?= (int)($approvalRoles[$type['request_type']][$stepOrder] ?? 0) === (int)$role['role_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($role['role_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endfor; ?>
          <div class="form-text">Choose roles in approval order; leave unused steps empty.</div>
        </div>
      </div>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary mt-3">Save approval chains</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header-custom"><h2 class="card-title-custom">Company email domain</h2></div>
  <div class="card-body-custom">
    <form id="emailDomainForm" class="d-flex flex-wrap gap-2 align-items-end">
      <div>
        <label for="emailDomain" class="form-label">Allowed domain</label>
        <div class="input-group">
          <span class="input-group-text">@</span>
          <input id="emailDomain" name="domain" class="form-control" maxlength="253"
                 value="<?= htmlspecialchars($emailDomain) ?>" required>
        </div>
      </div>
      <button type="submit" class="btn btn-outline-primary">Save domain</button>
      <span class="small text-muted">Applies to newly created or edited user accounts.</span>
    </form>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Expiry reminder thresholds</h2></div>
  <div class="card-body-custom">
    <form id="reminderThresholdsForm" class="d-flex flex-wrap gap-2 align-items-end">
      <div>
        <label for="reminderThresholds" class="form-label">Days before expiry</label>
        <input id="reminderThresholds" name="thresholds" class="form-control"
               value="<?= htmlspecialchars($reminderThresholds) ?>" maxlength="100" required>
      </div>
      <button type="submit" class="btn btn-outline-primary">Save thresholds</button>
      <span class="small text-muted">Comma-separated, unique values from 1 to 365; default 60,30,15,7.</span>
    </form>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Crew section label</h2></div>
  <div class="card-body-custom">
    <form id="crewLabelForm" class="d-flex flex-wrap gap-2 align-items-end">
      <div>
        <label for="crewLabel" class="form-label">Label for employee/crew records</label>
        <input id="crewLabel" name="label" class="form-control" value="<?= htmlspecialchars($crewLabel) ?>" maxlength="50" required>
      </div>
      <button type="submit" class="btn btn-outline-primary">Save label</button>
      <span class="small text-muted">Changes the crew tab name in User &amp; Employee Management.</span>
    </form>
  </div>
</div>

<?php layoutFoot(); ?>
