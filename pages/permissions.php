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
$grantedIds = [];
if ($selectedRoleId > 0) {
    $grantQuery = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
    $grantQuery->execute([$selectedRoleId]);
    $grantedIds = array_map('intval', $grantQuery->fetchAll(PDO::FETCH_COLUMN));
}
$emailDomain = getAppSetting($pdo, 'company_email_domain', COMPANY_EMAIL_DOMAIN) ?? COMPANY_EMAIL_DOMAIN;
$reminderThresholds = getAppSetting($pdo, 'reminder_thresholds_days', '60,30,15,7') ?? '60,30,15,7';
$crewLabel = getAppSetting($pdo, 'crew_module_label', 'Drivers & Helpers') ?? 'Drivers & Helpers';

$permissionsByModule = [];
foreach ($permissions as $permission) {
    $permissionsByModule[$permission['module_name']][] = $permission;
}

$GLOBALS['page_js'] = APP_BASE . '/assets/js/permissions.js';
layoutHead('Role Permissions');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title">Role Permissions</h1>
    <p class="page-subtitle">Manage role access using database-backed permissions.</p>
  </div>
</div>

<div id="permissionsFeedback" class="alert d-none" role="status"></div>

<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Select role</h2></div>
  <div class="card-body-custom">
    <form method="get" class="d-flex flex-wrap align-items-end gap-3">
      <div>
        <label for="roleId" class="form-label">Role</label>
        <select id="roleId" name="role_id" class="form-select" onchange="this.form.submit()">
          <?php foreach ($roles as $role): ?>
          <option value="<?= (int)$role['role_id'] ?>" <?= (int)$role['role_id'] === $selectedRoleId ? 'selected' : '' ?>>
            <?= htmlspecialchars($role['role_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <span class="text-muted small">Legacy page access is preserved through the matching `legacy.role.*` permission.</span>
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
  <div class="card-header-custom"><h2 class="card-title-custom">Permission grants</h2></div>
  <div class="card-body-custom">
    <?php if ($selectedRoleId > 0): ?>
    <form id="permissionsForm">
      <input type="hidden" name="role_id" value="<?= $selectedRoleId ?>">
      <?php foreach ($permissionsByModule as $module => $modulePermissions): ?>
      <section class="mb-4" aria-labelledby="module-<?= htmlspecialchars($module) ?>">
        <h3 class="h6 text-capitalize" id="module-<?= htmlspecialchars($module) ?>"><?= htmlspecialchars(str_replace('_', ' ', $module)) ?></h3>
        <div class="row g-2">
          <?php foreach ($modulePermissions as $permission): ?>
          <div class="col-12 col-md-6 col-xl-4">
            <label class="form-check border rounded p-3 h-100">
              <input class="form-check-input" type="checkbox" name="permission_ids[]"
                     value="<?= (int)$permission['permission_id'] ?>"
                     <?= in_array((int)$permission['permission_id'], $grantedIds, true) ? 'checked' : '' ?>>
              <span class="form-check-label">
                <strong><?= htmlspecialchars($permission['permission_key']) ?></strong><br>
                <span class="small text-muted"><?= htmlspecialchars($permission['description']) ?></span>
              </span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary">Save permissions</button>
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
