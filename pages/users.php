<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/app_settings.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('users.manage');

$GLOBALS['page_js'] = APP_BASE . '/assets/js/users.js';

layoutHead('User & Employee Management', APP_BASE . '/assets/css/users.css');

$pdo = getDBConnection();

// ── Users ─────────────────────────────────────────────────────────────────────
$usersSql = "
    SELECT
        u.user_id,
        u.username,
        COALESCE(
            NULLIF(
                TRIM(CONCAT_WS(
                    ' ',
                    NULLIF(TRIM(e.first_name), ''),
                    NULLIF(TRIM(e.middle_name), ''),
                    NULLIF(TRIM(e.last_name), ''),
                    NULLIF(TRIM(e.suffix), '')
                )),
                ''
            ),
            u.username
        ) AS full_name,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.suffix,
        u.email,
        u.is_active,
        u.created_at,
        r.role_name,
        r.role_id
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN employees e ON u.employee_id = e.employee_id
    ORDER BY u.is_active DESC, r.role_id ASC, full_name ASC
";
$users = $pdo->query($usersSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Roles for dropdown ────────────────────────────────────────────────────────
$roles = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id")->fetchAll(PDO::FETCH_ASSOC);

// ── Employees ─────────────────────────────────────────────────────────────────
$empSql = "
    SELECT
        e.employee_id,
        e.employee_code,
        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ',
                NULLIF(TRIM(e.first_name), ''),
                NULLIF(TRIM(e.middle_name), ''),
                NULLIF(TRIM(e.last_name), ''),
                NULLIF(TRIM(e.suffix), '')
            )), ''),
            CONCAT('Employee #', e.employee_id)
        ) AS full_name,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.suffix,
        e.employment_status,
        e.position,
        e.contact_number,
        e.license_number,
        e.license_expiry,
        e.license_type,
        e.employment_type,
        e.contractor_company,
        e.date_resigned,
        e.resignation_reason,
        (e.employment_status = 'Active') AS is_active,
        e.date_hired,
        (
            SELECT linked_user.username
            FROM users linked_user
            WHERE linked_user.user_id = e.user_id
               OR linked_user.employee_id = e.employee_id
            ORDER BY linked_user.user_id
            LIMIT 1
        ) AS linked_username,
        (
            SELECT linked_user.user_id
            FROM users linked_user
            WHERE linked_user.user_id = e.user_id
               OR linked_user.employee_id = e.employee_id
            ORDER BY linked_user.user_id
            LIMIT 1
        ) AS linked_user_id
    FROM employees e
    ORDER BY (e.employment_status = 'Active') DESC, full_name ASC
";
$employees = $pdo->query($empSql)->fetchAll(PDO::FETCH_ASSOC);
$unlinkedEmployees = array_values(array_filter(
    $employees,
    static fn(array $employee): bool => $employee['employment_status'] === 'Active'
        && empty($employee['linked_user_id'])
));
// ── License expiry alerts ─────────────────────────────────────────────────────
// Same rule as the legacy v_license_expiry_alerts view (not in the target
// schema): active employees with a license expiring within 60 days.
$alerts = $pdo->query("
    SELECT
        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ',
                NULLIF(TRIM(first_name), ''),
                NULLIF(TRIM(middle_name), ''),
                NULLIF(TRIM(last_name), ''),
                NULLIF(TRIM(suffix), '')
            )), ''),
            CONCAT('Employee #', employee_id)
        ) AS full_name,
        license_expiry,
        DATEDIFF(license_expiry, CURDATE()) AS days_until_expiry
    FROM employees
    WHERE license_number IS NOT NULL
      AND license_expiry IS NOT NULL
      AND license_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
      AND employment_status = 'Active'
    ORDER BY days_until_expiry ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Unique positions for datalist ─────────────────────────────────────────────
$positions = array_unique(array_column($employees, 'position'));
sort($positions, SORT_NATURAL | SORT_FLAG_CASE);
$employmentTypes = array_values(array_unique(array_column($employees, 'employment_type')));
sort($employmentTypes, SORT_NATURAL | SORT_FLAG_CASE);
$crewLabel = isAdmin()
    ? 'Employees'
    : (getAppSetting($pdo, 'crew_module_label', 'Drivers & Helpers') ?? 'Drivers & Helpers');

// ── Stats ─────────────────────────────────────────────────────────────────────
$activeUsers = count(array_filter($users, fn($u) => $u['is_active']));
$activeEmps  = count(array_filter($employees, fn($e) => $e['is_active']));
$alertCount  = count($alerts);
?>

<div class="usr-page">

  <!-- Header -->
  <div class="usr-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="usr-title mb-0">Users &amp; Employees</h1>
      <p class="usr-subtitle mb-0">Manage user access and employee records.</p>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="usr-cards mb-4">
    <div class="usr-card">
      <div class="usr-card-value"><?= count($users) ?></div>
      <div class="usr-card-label">System Users</div>
    </div>
    <div class="usr-card usr-card-success">
      <div class="usr-card-value"><?= $activeUsers ?></div>
      <div class="usr-card-label">Active Users</div>
    </div>
    <div class="usr-card">
      <div class="usr-card-value"><?= count($employees) ?></div>
      <div class="usr-card-label">Employees</div>
    </div>
    <div class="usr-card <?= $alertCount > 0 ? 'usr-card-alert' : '' ?>">
      <div class="usr-card-value"><?= $alertCount ?></div>
      <div class="usr-card-label">License Expiry Alerts</div>
    </div>
  </div>

  <!-- License alert banner -->
  <?php if ($alertCount > 0): ?>
  <div class="usr-alert-banner mb-4">
    <i class="bi bi-exclamation-triangle-fill usr-alert-icon"></i>
    <div class="usr-alert-body">
      <strong><?= $alertCount ?> driver license<?= $alertCount > 1 ? 's' : '' ?> expiring within 60 days:</strong>
      <div class="usr-alert-list">
        <?php foreach ($alerts as $a): ?>
        <span class="usr-alert-item <?= $a['days_until_expiry'] <= 14 ? 'usr-alert-critical' : '' ?>">
          <?= htmlspecialchars($a['full_name']) ?> —
          <?= $a['days_until_expiry'] <= 0
            ? '<strong>EXPIRED</strong>'
            : 'in ' . $a['days_until_expiry'] . ' day' . ($a['days_until_expiry'] === 1 ? '' : 's') ?>
        </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tabs -->
  <ul class="nav usr-tabs mb-4" id="usrTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="usr-tab active" id="tab-users" data-bs-toggle="tab"
              data-bs-target="#pane-users" type="button" role="tab">
        <i class="bi bi-shield-lock me-1"></i> System Users
        <span class="usr-tab-count"><?= count($users) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="usr-tab" id="tab-employees" data-bs-toggle="tab"
              data-bs-target="#pane-employees" type="button" role="tab">
      <i class="bi bi-person-lines-fill me-1"></i> <?= htmlspecialchars($crewLabel) ?>
        <span class="usr-tab-count"><?= count($employees) ?></span>
      </button>
    </li>
  </ul>

  <div class="tab-content">
    <!-- ── Users pane ────────────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="pane-users" role="tabpanel">

      <div class="usr-pane-toolbar d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex gap-2">
          <select id="filterUserRole" class="form-select usr-filter-select">
            <option value="">All Roles</option>
            <?php foreach ($roles as $role): ?>
            <option value="<?= htmlspecialchars($role['role_name']) ?>"><?= htmlspecialchars($role['role_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="search" id="filterUserSearch" class="form-control usr-filter-search"
                 placeholder="Search name, username, email…">
        </div>
        <button class="btn btn-usr-primary" data-bs-toggle="modal" data-bs-target="#addUserModal" <?= $unlinkedEmployees ? '' : 'disabled title="No active employee without an account is available."' ?>>
          <i class="bi bi-plus-lg me-1"></i> Add User
        </button>
      </div>

      <div class="usr-table-wrap">
        <?php if (empty($users)): ?>
        <div class="usr-empty">
          <i class="bi bi-shield-lock usr-empty-icon"></i>
          <p>No system users found.</p>
        </div>
        <?php else: ?>
        <table class="table usr-table" id="usersTable">
          <thead>
            <tr>
              <th>Full Name</th>
              <th>Username</th>
              <th>Email</th>
              <th>Role</th>
              <th>Status</th>
              <th>Created</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $usr): ?>
            <tr
              data-role="<?= htmlspecialchars($usr['role_name']) ?>"
              data-search="<?= htmlspecialchars(strtolower($usr['full_name'] . ' ' . $usr['username'] . ' ' . $usr['email'])) ?>"
              data-id="<?= $usr['user_id'] ?>"
            >
              <td class="usr-name"><?= htmlspecialchars($usr['full_name']) ?></td>
              <td><span class="usr-username">@<?= htmlspecialchars($usr['username']) ?></span></td>
              <td class="usr-email"><?= htmlspecialchars($usr['email']) ?></td>
              <td>
                <span class="usr-role-badge usr-role-<?= $usr['role_id'] ?>">
                  <?= htmlspecialchars($usr['role_name']) ?>
                </span>
              </td>
              <td>
                <span class="usr-status-badge <?= $usr['is_active'] ? 'usr-status-active' : 'usr-status-inactive' ?>">
                  <?= $usr['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td class="usr-date"><?= date('M d, Y', strtotime($usr['created_at'])) ?></td>
              <td class="text-end">
                <div class="d-flex gap-1 justify-content-end">
                  <button class="btn btn-sm btn-usr-action btn-usr-edit-user"
                          title="Edit"
                          data-id="<?= $usr['user_id'] ?>"
                          data-name="<?= htmlspecialchars($usr['full_name']) ?>"
                          data-first-name="<?= htmlspecialchars($usr['first_name'] ?? '') ?>"
                          data-middle-name="<?= htmlspecialchars($usr['middle_name'] ?? '') ?>"
                          data-last-name="<?= htmlspecialchars($usr['last_name'] ?? '') ?>"
                          data-suffix="<?= htmlspecialchars($usr['suffix'] ?? '') ?>"
                          data-username="<?= htmlspecialchars($usr['username']) ?>"
                          data-email="<?= htmlspecialchars($usr['email']) ?>"
                          data-role="<?= $usr['role_id'] ?>"
                          data-active="<?= $usr['is_active'] ?>">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button class="btn btn-sm btn-usr-action btn-usr-reset-pw"
                          title="Reset Password"
                          data-id="<?= $usr['user_id'] ?>"
                          data-name="<?= htmlspecialchars($usr['full_name']) ?>">
                    <i class="bi bi-key"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noUserResults" class="no-results d-none">
          <i class="bi bi-search"></i>
          <span>No users match your filters.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Employees pane ────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="pane-employees" role="tabpanel">

      <div class="usr-pane-toolbar d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex gap-2 flex-wrap">
          <select id="filterEmpStatus" class="form-select usr-filter-select">
            <option value="">All Statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
          <?php if (isAdmin()): ?>
          <select id="filterEmpPosition" class="form-select usr-filter-select" aria-label="Filter by position">
            <option value="">All Positions</option>
            <?php foreach ($positions as $position): ?>
            <option value="<?= htmlspecialchars($position) ?>"><?= htmlspecialchars($position) ?></option>
            <?php endforeach; ?>
          </select>
          <select id="filterEmpEmployment" class="form-select usr-filter-select" aria-label="Filter by employment">
            <option value="">All Employment Types</option>
            <?php foreach ($employmentTypes as $employmentType): ?>
            <option value="<?= htmlspecialchars($employmentType) ?>"><?= htmlspecialchars($employmentType) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <input type="search" id="filterEmpSearch" class="form-control usr-filter-search"
                 placeholder="Search name, code, position…">
        </div>
        <button class="btn btn-usr-primary" data-bs-toggle="modal" data-bs-target="#addEmpModal">
          <i class="bi bi-plus-lg me-1"></i> Add Employee
        </button>
      </div>

      <div class="usr-table-wrap">
        <?php if (empty($employees)): ?>
        <div class="usr-empty">
          <i class="bi bi-person-lines-fill usr-empty-icon"></i>
          <p>No employees added yet.</p>
        </div>
        <?php else: ?>
        <table class="table usr-table" id="employeesTable">
          <thead>
            <tr>
              <th>Code</th>
              <th>Full Name</th>
              <th>Position</th>
              <th>Employment</th>
              <th>Contact</th>
              <th>License No.</th>
              <th>License Expiry</th>
              <th>Linked User</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $emp):
              $licExpired = $emp['license_expiry'] && strtotime($emp['license_expiry']) < time();
              $licSoon    = $emp['license_expiry'] && !$licExpired
                            && strtotime($emp['license_expiry']) <= strtotime('+60 days');
            ?>
            <tr
              data-active="<?= $emp['is_active'] ?>"
              data-position="<?= htmlspecialchars($emp['position']) ?>"
              data-employment-type="<?= htmlspecialchars($emp['employment_type']) ?>"
              data-search="<?= htmlspecialchars(strtolower(
                $emp['full_name'] . ' ' . $emp['employee_code'] . ' ' . $emp['position']
              )) ?>"
              data-id="<?= $emp['employee_id'] ?>"
            >
              <td><span class="usr-emp-code"><?= htmlspecialchars($emp['employee_code']) ?></span></td>
              <td class="usr-name"><?= htmlspecialchars($emp['full_name']) ?></td>
              <td><span class="usr-position-chip"><?= htmlspecialchars($emp['position']) ?></span></td>
              <td>
                <?= htmlspecialchars($emp['employment_type']) ?>
                <?php if ($emp['contractor_company']): ?><br><span class="usr-username"><?= htmlspecialchars($emp['contractor_company']) ?></span><?php endif; ?>
                <?php if ($emp['date_resigned']): ?><br><span class="text-danger small">Resigned <?= htmlspecialchars($emp['date_resigned']) ?></span><?php endif; ?>
              </td>
              <td class="usr-contact">
                <?= $emp['contact_number'] ? htmlspecialchars($emp['contact_number']) : '<span class="text-muted">—</span>' ?>
              </td>
              <td>
                <?php if ($emp['license_number']): ?>
                <div class="usr-license-cell">
                  <span><?= htmlspecialchars($emp['license_number']) ?></span>
                  <?php if ($emp['license_type']): ?>
                  <span class="usr-license-type"><?= htmlspecialchars($emp['license_type']) ?></span>
                  <?php endif; ?>
                </div>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($emp['license_expiry']): ?>
                <span class="usr-expiry <?= $licExpired ? 'usr-expiry-expired' : ($licSoon ? 'usr-expiry-soon' : '') ?>">
                  <?= date('M d, Y', strtotime($emp['license_expiry'])) ?>
                  <?php if ($licExpired): ?>
                  <span class="usr-expiry-badge usr-badge-expired">Expired</span>
                  <?php elseif ($licSoon): ?>
                  <span class="usr-expiry-badge usr-badge-soon">Soon</span>
                  <?php endif; ?>
                </span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?= $emp['linked_username']
                  ? '<span class="usr-username">@' . htmlspecialchars($emp['linked_username']) . '</span>'
                  : '<span class="text-muted">—</span>' ?>
              </td>
              <td>
                <span class="usr-status-badge <?= $emp['is_active'] ? 'usr-status-active' : 'usr-status-inactive' ?>">
                  <?= $emp['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-usr-action btn-usr-edit-emp"
                        title="Edit"
                        data-id="<?= $emp['employee_id'] ?>"
                        data-code="<?= htmlspecialchars($emp['employee_code']) ?>"
                        data-name="<?= htmlspecialchars($emp['full_name']) ?>"
                        data-first-name="<?= htmlspecialchars($emp['first_name'] ?? '') ?>"
                        data-middle-name="<?= htmlspecialchars($emp['middle_name'] ?? '') ?>"
                        data-last-name="<?= htmlspecialchars($emp['last_name'] ?? '') ?>"
                        data-suffix="<?= htmlspecialchars($emp['suffix'] ?? '') ?>"
                        data-position="<?= htmlspecialchars($emp['position']) ?>"
                        data-contact="<?= htmlspecialchars($emp['contact_number'] ?? '') ?>"
                        data-address="<?= htmlspecialchars($emp['address'] ?? '') ?>"
                        data-license="<?= htmlspecialchars($emp['license_number'] ?? '') ?>"
                        data-license-expiry="<?= htmlspecialchars($emp['license_expiry'] ?? '') ?>"
                        data-license-type="<?= htmlspecialchars($emp['license_type'] ?? '') ?>"
                        data-hired="<?= htmlspecialchars($emp['date_hired'] ?? '') ?>"
                        data-employment-type="<?= htmlspecialchars($emp['employment_type']) ?>"
                        data-contractor-company="<?= htmlspecialchars($emp['contractor_company'] ?? '') ?>"
                        data-date-resigned="<?= htmlspecialchars($emp['date_resigned'] ?? '') ?>"
                        data-resignation-reason="<?= htmlspecialchars($emp['resignation_reason'] ?? '') ?>"
                        data-active="<?= $emp['is_active'] ?>">
                  <i class="bi bi-pencil"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noEmployeeResults" class="no-results d-none">
          <i class="bi bi-search"></i>
          <span>No employees match your filters.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /tab-content -->
</div>

<!-- ══ Add User Modal ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content usr-modal-content">
      <div class="modal-header usr-modal-header-primary">
        <h5 class="modal-title" id="addUserLabel">
          <i class="bi bi-person-plus me-2"></i>Add System User
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body usr-modal-body">
        <div id="addUserAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label usr-label" for="auEmployee">Employee</label>
            <select class="form-select usr-input" id="auEmployee" required>
              <option value="">— Select an employee without an account —</option>
              <?php foreach ($unlinkedEmployees as $employee): ?>
              <option value="<?= (int)$employee['employee_id'] ?>"><?= htmlspecialchars($employee['full_name'] . ' · ' . $employee['employee_code']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Employees with an existing linked account are not offered here. Add an employee record first if needed.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="auUsername">Username</label>
            <input type="text" class="form-control usr-input" id="auUsername"
                   autocomplete="off" required>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="auEmail">Email</label>
            <input type="email" class="form-control usr-input" id="auEmail" required>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="auRole">Role</label>
            <select class="form-select usr-input" id="auRole" required>
              <option value="">— Select role —</option>
              <?php foreach ($roles as $role): ?>
              <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Only one active Operations Head account is allowed.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="auPassword">Password</label>
            <div class="usr-pw-wrap">
              <input type="password" class="form-control usr-input" id="auPassword"
                     autocomplete="new-password" required>
              <button type="button" class="usr-pw-toggle" data-target="auPassword">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label usr-label" for="auConfirmPassword">Confirm Password</label>
            <div class="usr-pw-wrap">
              <input type="password" class="form-control usr-input" id="auConfirmPassword"
                     autocomplete="new-password" required>
              <button type="button" class="usr-pw-toggle" data-target="auConfirmPassword">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer usr-modal-footer">
        <button type="button" class="btn btn-usr-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-usr-primary" id="submitAddUserBtn">
          <span id="auBtnText">Add User</span>
          <span id="auBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Edit User Modal ═════════════════════════════════════════════════════ -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content usr-modal-content">
      <div class="modal-header usr-modal-header-secondary">
        <h5 class="modal-title" id="editUserLabel">
          <i class="bi bi-pencil me-2"></i>Edit User
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body usr-modal-body">
        <div id="editUserAlert" class="alert d-none" role="alert"></div>
        <input type="hidden" id="euId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label usr-label" for="euFirstName">First Name</label>
            <input type="text" class="form-control usr-input" id="euFirstName" maxlength="80" required>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="euMiddleName">Middle Name</label>
            <input type="text" class="form-control usr-input" id="euMiddleName" maxlength="80">
          </div>
          <div class="col-md-8">
            <label class="form-label usr-label" for="euLastName">Last Name</label>
            <input type="text" class="form-control usr-input" id="euLastName" maxlength="80" required>
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="euSuffix">Suffix</label>
            <input type="text" class="form-control usr-input" id="euSuffix" maxlength="20" placeholder="e.g. Jr.">
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="euUsername">Username</label>
            <input type="text" class="form-control usr-input" id="euUsername" required>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="euEmail">Email</label>
            <input type="email" class="form-control usr-input" id="euEmail" required>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="euRole">Role</label>
            <select class="form-select usr-input" id="euRole" required>
              <?php foreach ($roles as $role): ?>
              <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 d-flex align-items-end">
            <div class="form-check form-switch usr-active-toggle">
              <input class="form-check-input" type="checkbox" id="euActive">
              <label class="form-check-label usr-label mb-0" for="euActive">Active</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer usr-modal-footer">
        <button type="button" class="btn btn-usr-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-usr-secondary" id="submitEditUserBtn">
          <span id="euBtnText">Save Changes</span>
          <span id="euBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Reset Password Modal ════════════════════════════════════════════════ -->
<div class="modal fade" id="resetPwModal" tabindex="-1" aria-labelledby="resetPwLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content usr-modal-content">
      <div class="modal-header usr-modal-header-warning">
        <h5 class="modal-title" id="resetPwLabel">
          <i class="bi bi-key me-2"></i>Reset Password
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body usr-modal-body">
        <div id="resetPwAlert" class="alert d-none" role="alert"></div>
        <input type="hidden" id="rpUserId">
        <p class="mb-3">
          Set a new password for <strong id="rpUserName"></strong>.
        </p>
        <div class="mb-3">
          <label class="form-label usr-label" for="rpPassword">New Password</label>
          <div class="usr-pw-wrap">
            <input type="password" class="form-control usr-input" id="rpPassword"
                   autocomplete="new-password" required>
            <button type="button" class="usr-pw-toggle" data-target="rpPassword">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
        <div class="mb-1">
          <label class="form-label usr-label" for="rpConfirm">Confirm Password</label>
          <div class="usr-pw-wrap">
            <input type="password" class="form-control usr-input" id="rpConfirm"
                   autocomplete="new-password" required>
            <button type="button" class="usr-pw-toggle" data-target="rpConfirm">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer usr-modal-footer">
        <button type="button" class="btn btn-usr-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-usr-warning" id="submitResetPwBtn">
          <span id="rpBtnText">Reset Password</span>
          <span id="rpBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Add Employee Modal ══════════════════════════════════════════════════ -->
<div class="modal fade" id="addEmpModal" tabindex="-1" aria-labelledby="addEmpLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content usr-modal-content">
      <div class="modal-header usr-modal-header-primary">
        <h5 class="modal-title" id="addEmpLabel">
          <i class="bi bi-person-plus me-2"></i>Add Employee
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body usr-modal-body">
        <div id="addEmpAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeCode">Employee Code</label>
            <input type="text" class="form-control usr-input" id="aeCode"
                   placeholder="e.g. EMP-0001" maxlength="30" required>
          </div>
          <div class="col-md-8"></div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeFirstName">First Name</label>
            <input type="text" class="form-control usr-input" id="aeFirstName" maxlength="80" required>
          </div>
          <div class="col-md-3">
            <label class="form-label usr-label" for="aeMiddleName">Middle Name</label>
            <input type="text" class="form-control usr-input" id="aeMiddleName" maxlength="80">
          </div>
          <div class="col-md-3">
            <label class="form-label usr-label" for="aeLastName">Last Name</label>
            <input type="text" class="form-control usr-input" id="aeLastName" maxlength="80" required>
          </div>
          <div class="col-md-2">
            <label class="form-label usr-label" for="aeSuffix">Suffix</label>
            <input type="text" class="form-control usr-input" id="aeSuffix" maxlength="20" placeholder="e.g. Jr.">
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="aePosition">Position</label>
            <input type="text" class="form-control usr-input" id="aePosition"
                   list="positionList" placeholder="e.g. Driver, Helper, Mechanic" required>
            <datalist id="positionList">
              <?php foreach ($positions as $pos): ?>
              <option value="<?= htmlspecialchars($pos) ?>">
              <?php endforeach; ?>
              <option value="Driver">
              <option value="Helper">
              <option value="Mechanic">
            </datalist>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="aeContact">Contact Number</label>
            <input type="text" class="form-control usr-input" id="aeContact" placeholder="Optional">
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="aeEmploymentType">Employment Type</label>
            <select class="form-select usr-input" id="aeEmploymentType">
              <option value="Employee">Employee</option>
              <option value="Contractor">Contractor</option>
            </select>
          </div>
          <div class="col-md-6 d-none" id="aeContractorCompanyWrap">
            <label class="form-label usr-label" for="aeContractorCompany">Contractor Company</label>
            <input type="text" class="form-control usr-input" id="aeContractorCompany" maxlength="150">
          </div>
          <div class="col-12">
            <label class="form-label usr-label" for="aeAddress">Address</label>
            <textarea class="form-control usr-input" id="aeAddress" rows="2" placeholder="Optional"></textarea>
          </div>
          <div class="col-12">
            <div id="aeDriverHint" class="usr-driver-hint d-none">
              <i class="bi bi-info-circle"></i>
              License Number, Expiry, Type, and Date Hired are required for Drivers.
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeLicense">
              License Number <span class="text-danger d-none" id="aeLicenseReq">*</span>
            </label>
            <input type="text" class="form-control usr-input" id="aeLicense"
                   placeholder="e.g. N01-12-123456" style="text-transform:uppercase;">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeLicenseExpiry">
              License Expiry <span class="text-danger d-none" id="aeLicExpiryReq">*</span>
            </label>
            <input type="date" class="form-control usr-input" id="aeLicenseExpiry" min="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeLicenseType">
              License Type <span class="text-danger d-none" id="aeLicTypeReq">*</span>
            </label>
            <input type="text" class="form-control usr-input" id="aeLicenseType"
                   placeholder="e.g. Professional" maxlength="20">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeDateHired">
              Date Hired <span class="text-danger d-none" id="aeDateHiredReq">*</span>
            </label>
            <input type="date" class="form-control usr-input" id="aeDateHired" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeDateResigned">Date Resigned</label>
            <input type="date" class="form-control usr-input" id="aeDateResigned" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-12">
            <label class="form-label usr-label" for="aeResignationReason">Resignation Reason</label>
            <textarea class="form-control usr-input" id="aeResignationReason" rows="2" maxlength="500"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer usr-modal-footer">
        <button type="button" class="btn btn-usr-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-usr-primary" id="submitAddEmpBtn">
          <span id="aeBtnText">Add Employee</span>
          <span id="aeBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Edit Employee Modal ═════════════════════════════════════════════════ -->
<div class="modal fade" id="editEmpModal" tabindex="-1" aria-labelledby="editEmpLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content usr-modal-content">
      <div class="modal-header usr-modal-header-secondary">
        <h5 class="modal-title" id="editEmpLabel">
          <i class="bi bi-pencil me-2"></i>Edit Employee
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body usr-modal-body">
        <div id="editEmpAlert" class="alert d-none" role="alert"></div>
        <input type="hidden" id="eeId">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label usr-label" for="eeCode">Employee Code</label>
            <input type="text" class="form-control usr-input" id="eeCode" maxlength="30" required>
          </div>
          <div class="col-md-8"></div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="eeFirstName">First Name</label>
            <input type="text" class="form-control usr-input" id="eeFirstName" maxlength="80" required>
          </div>
          <div class="col-md-3">
            <label class="form-label usr-label" for="eeMiddleName">Middle Name</label>
            <input type="text" class="form-control usr-input" id="eeMiddleName" maxlength="80">
          </div>
          <div class="col-md-3">
            <label class="form-label usr-label" for="eeLastName">Last Name</label>
            <input type="text" class="form-control usr-input" id="eeLastName" maxlength="80" required>
          </div>
          <div class="col-md-2">
            <label class="form-label usr-label" for="eeSuffix">Suffix</label>
            <input type="text" class="form-control usr-input" id="eeSuffix" maxlength="20" placeholder="e.g. Jr.">
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="eePosition">Position</label>
            <input type="text" class="form-control usr-input" id="eePosition" list="positionList" required>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="eeContact">Contact Number</label>
            <input type="text" class="form-control usr-input" id="eeContact">
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="eeEmploymentType">Employment Type</label>
            <select class="form-select usr-input" id="eeEmploymentType">
              <option value="Employee">Employee</option>
              <option value="Contractor">Contractor</option>
            </select>
          </div>
          <div class="col-md-6 d-none" id="eeContractorCompanyWrap">
            <label class="form-label usr-label" for="eeContractorCompany">Contractor Company</label>
            <input type="text" class="form-control usr-input" id="eeContractorCompany" maxlength="150">
          </div>
          <div class="col-12">
            <label class="form-label usr-label" for="eeAddress">Address</label>
            <textarea class="form-control usr-input" id="eeAddress" rows="2"></textarea>
          </div>
          <div class="col-12">
            <div id="eeDriverHint" class="usr-driver-hint d-none">
              <i class="bi bi-info-circle"></i>
              License Number, Expiry, Type, and Date Hired are required for Drivers.
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="eeLicense">
              License Number <span class="text-danger d-none" id="eeLicenseReq">*</span>
            </label>
            <input type="text" class="form-control usr-input" id="eeLicense"
                   placeholder="e.g. N01-12-123456" style="text-transform:uppercase;">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="eeLicenseExpiry">
              License Expiry <span class="text-danger d-none" id="eeLicExpiryReq">*</span>
            </label>
            <input type="date" class="form-control usr-input" id="eeLicenseExpiry">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="eeLicenseType">
              License Type <span class="text-danger d-none" id="eeLicTypeReq">*</span>
            </label>
            <input type="text" class="form-control usr-input" id="eeLicenseType" maxlength="20">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="eeDateHired">
              Date Hired <span class="text-danger d-none" id="eeDateHiredReq">*</span>
            </label>
            <input type="date" class="form-control usr-input" id="eeDateHired" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch usr-active-toggle">
              <input class="form-check-input" type="checkbox" id="eeActive">
              <label class="form-check-label usr-label mb-0" for="eeActive">Active</label>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="eeDateResigned">Date Resigned</label>
            <input type="date" class="form-control usr-input" id="eeDateResigned" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-12">
            <label class="form-label usr-label" for="eeResignationReason">Resignation Reason</label>
            <textarea class="form-control usr-input" id="eeResignationReason" rows="2" maxlength="500"></textarea>
          </div>
          <div class="col-12 d-none" id="eeClearResignationWrap">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="eeClearResignation">
              <label class="form-check-label usr-label mb-0" for="eeClearResignation">
                Clear resignation record (removes the date and reason; the old values stay in the audit log)
              </label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer usr-modal-footer">
        <button type="button" class="btn btn-usr-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-usr-secondary" id="submitEditEmpBtn">
          <span id="eeBtnText">Save Changes</span>
          <span id="eeBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>