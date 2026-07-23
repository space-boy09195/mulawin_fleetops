<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/users.js';

layoutHead('User & Employee Management', APP_BASE . '/assets/css/users.css');

$pdo = getDBConnection();

// ── Users ─────────────────────────────────────────────────────────────────────
$usersSql = "
    SELECT
        u.user_id,
        u.username,
        u.full_name,
        u.email,
        u.is_active,
        u.created_at,
        r.role_name,
        r.role_id
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    ORDER BY u.is_active DESC, r.role_id ASC, u.full_name ASC
";
$users = $pdo->query($usersSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Roles for dropdown ────────────────────────────────────────────────────────
$roles = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id")->fetchAll(PDO::FETCH_ASSOC);

// ── Employees ─────────────────────────────────────────────────────────────────
$empSql = "
    SELECT
        e.employee_id,
        e.employee_code,
        e.full_name,
        e.position,
        e.contact_number,
        e.license_number,
        e.license_expiry,
        e.license_type,
        e.is_active,
        e.date_hired,
        u.username AS linked_username
    FROM employees e
    LEFT JOIN users u ON e.user_id = u.user_id
    ORDER BY e.is_active DESC, e.full_name ASC
";
$employees = $pdo->query($empSql)->fetchAll(PDO::FETCH_ASSOC);

// ── License expiry alerts ─────────────────────────────────────────────────────
$alerts = $pdo->query("
    SELECT full_name, license_expiry, days_until_expiry
    FROM v_license_expiry_alerts
    ORDER BY days_until_expiry ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Unique positions for datalist ─────────────────────────────────────────────
$positions = array_unique(array_column($employees, 'position'));
sort($positions);

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
      <p class="usr-subtitle mb-0">System accounts and field staff management</p>
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
        <i class="bi bi-person-lines-fill me-1"></i> Employees
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
        <button class="btn btn-usr-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
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
        <div class="d-flex gap-2">
          <select id="filterEmpStatus" class="form-select usr-filter-select">
            <option value="">All Statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
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
              data-search="<?= htmlspecialchars(strtolower(
                $emp['full_name'] . ' ' . $emp['employee_code'] . ' ' . $emp['position']
              )) ?>"
              data-id="<?= $emp['employee_id'] ?>"
            >
              <td><span class="usr-emp-code"><?= htmlspecialchars($emp['employee_code']) ?></span></td>
              <td class="usr-name"><?= htmlspecialchars($emp['full_name']) ?></td>
              <td><span class="usr-position-chip"><?= htmlspecialchars($emp['position']) ?></span></td>
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
                        data-position="<?= htmlspecialchars($emp['position']) ?>"
                        data-contact="<?= htmlspecialchars($emp['contact_number'] ?? '') ?>"
                        data-address="<?= htmlspecialchars($emp['address'] ?? '') ?>"
                        data-license="<?= htmlspecialchars($emp['license_number'] ?? '') ?>"
                        data-license-expiry="<?= htmlspecialchars($emp['license_expiry'] ?? '') ?>"
                        data-license-type="<?= htmlspecialchars($emp['license_type'] ?? '') ?>"
                        data-hired="<?= htmlspecialchars($emp['date_hired'] ?? '') ?>"
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
            <label class="form-label usr-label" for="auFullName">Full Name</label>
            <input type="text" class="form-control usr-input" id="auFullName" required>
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
          <div class="col-12">
            <label class="form-label usr-label" for="euFullName">Full Name</label>
            <input type="text" class="form-control usr-input" id="euFullName" required>
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
                   placeholder="e.g. EMP-0001" required>
          </div>
          <div class="col-md-8">
            <label class="form-label usr-label" for="aeName">Full Name</label>
            <input type="text" class="form-control usr-input" id="aeName" required>
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
            <input type="date" class="form-control usr-input" id="aeLicenseExpiry">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeLicenseType">
              License Type <span class="text-danger d-none" id="aeLicTypeReq">*</span>
            </label>
            <input type="text" class="form-control usr-input" id="aeLicenseType"
                   placeholder="e.g. Professional">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="aeDateHired">
              Date Hired <span class="text-danger d-none" id="aeDateHiredReq">*</span>
            </label>
            <input type="date" class="form-control usr-input" id="aeDateHired">
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
            <input type="text" class="form-control usr-input" id="eeCode" required>
          </div>
          <div class="col-md-8">
            <label class="form-label usr-label" for="eeName">Full Name</label>
            <input type="text" class="form-control usr-input" id="eeName" required>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="eePosition">Position</label>
            <input type="text" class="form-control usr-input" id="eePosition" list="positionList" required>
          </div>
          <div class="col-md-6">
            <label class="form-label usr-label" for="eeContact">Contact Number</label>
            <input type="text" class="form-control usr-input" id="eeContact">
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
            <input type="text" class="form-control usr-input" id="eeLicenseType">
          </div>
          <div class="col-md-4">
            <label class="form-label usr-label" for="eeDateHired">
              Date Hired <span class="text-danger d-none" id="eeDateHiredReq">*</span>
            </label>
            <input type="date" class="form-control usr-input" id="eeDateHired">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch usr-active-toggle">
              <input class="form-check-input" type="checkbox" id="eeActive">
              <label class="form-check-label usr-label mb-0" for="eeActive">Active</label>
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