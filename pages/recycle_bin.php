<?php
// ============================================================
// pages/recycle_bin.php
// View deleted records and audit history — Head Management only.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/soft_delete.php';

requireRole([ROLE_HEAD_MANAGEMENT]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/recycle_bin.js';
layoutHead('Recycle Bin', APP_BASE . '/assets/css/recycle_bin.css');

$pdo = getDBConnection();

$archived = $pdo->query("
    SELECT archive_id, original_table, original_id, record_data, deleted_by_name, deleted_at, restored_at
    FROM deleted_records
    ORDER BY deleted_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$auditLogs = $pdo->query("
    SELECT a.log_id, a.action, a.table_name, a.record_id,
           a.old_value, a.new_value, a.ip_address, a.logged_at,
           COALESCE(u.full_name, 'System') AS user_name
    FROM audit_logs a
    LEFT JOIN users u ON u.user_id = a.user_id
    ORDER BY a.logged_at DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$tableLabels = [
    'announcements' => ['label' => 'Announcement', 'icon' => 'bi-megaphone'],
    'documents'     => ['label' => 'Document',     'icon' => 'bi-file-earmark'],
    'payroll_records' => ['label' => 'Payroll Record', 'icon' => 'bi-cash-stack'],
];

function auditActionLabel(string $action): string {
    return ucwords(strtolower(str_replace(['_', '-'], ' ', $action)));
}

function auditDetails(array $log): string {
    $new = json_decode((string)($log['new_value'] ?? ''), true);
    $old = json_decode((string)($log['old_value'] ?? ''), true);
    $data = is_array($new) && $new ? $new : (is_array($old) ? $old : []);
    $labels = [
        'client_name' => 'Client', 'route_name' => 'Route', 'file_name' => 'File',
        'title' => 'Title', 'status' => 'Status', 'approval_status' => 'Approval',
        'is_active' => 'Active', 'amount' => 'Amount', 'trip_number' => 'Trip',
    ];
    $parts = [];
    foreach ($data as $key => $value) {
        if (!array_key_exists($key, $labels) || is_array($value) || is_object($value)) continue;
        $display = is_bool($value) ? ($value ? 'Yes' : 'No') : (string)$value;
        $parts[] = ($labels[$key] ?? ucwords(str_replace('_', ' ', $key))) . ': ' . $display;
    }
    return implode(' | ', $parts);
}
?>

<div class="rb-page">

  <div class="page-header">
    <h1 class="page-title">Recycle Bin</h1>
    <p class="page-subtitle">Review deleted records, restore them, and inspect the system audit history.</p>
  </div>

  <ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#rbDeleted" type="button">
      <i class="bi bi-trash3 me-1"></i>Deleted Records <span class="badge text-bg-secondary"><?= count(array_filter($archived, fn($a) => $a['restored_at'] === null)) ?></span>
    </button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rbAudit" type="button">
      <i class="bi bi-journal-text me-1"></i>Audit Logs <span class="badge text-bg-secondary"><?= count($auditLogs) ?></span>
    </button></li>
  </ul>

  <div class="tab-content">
  <div class="tab-pane fade show active" id="rbDeleted">
  <?php if (empty($archived)): ?>
  <div class="rb-empty">
    <i class="bi bi-trash3 rb-empty-icon"></i>
    <p>Nothing in the recycle bin.</p>
  </div>
  <?php else: ?>
  <div class="rb-table-wrap">
    <div class="rb-filter-bar">
      <div class="rb-filter-search">
        <label class="visually-hidden" for="deletedSearch">Search deleted records</label>
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" class="form-control" id="deletedSearch" placeholder="Search deleted records, people, or IDs...">
      </div>
      <div class="rb-filter-select">
        <label class="visually-hidden" for="deletedTypeFilter">Filter deleted record type</label>
        <select class="form-select" id="deletedTypeFilter">
          <option value="">All record types</option>
          <?php
            $archivedTypes = array_unique(array_map(fn($a) => $a['original_table'], $archived));
            sort($archivedTypes);
            foreach ($archivedTypes as $type):
              $typeLabel = $tableLabels[$type]['label'] ?? ucwords(str_replace('_', ' ', $type));
          ?>
          <option value="<?= htmlspecialchars(strtolower($type)) ?>"><?= htmlspecialchars($typeLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rb-filter-select">
        <label class="visually-hidden" for="deletedStatusFilter">Filter deleted record status</label>
        <select class="form-select" id="deletedStatusFilter">
          <option value="">All statuses</option>
          <option value="deleted">Deleted</option>
          <option value="restored">Restored</option>
        </select>
      </div>
    </div>
    <table class="table-custom rb-table">
      <thead>
        <tr data-deleted-search="<?= htmlspecialchars(strtolower($summary . ' ' . $a['original_table'] . ' ' . $a['deleted_by_name'] . ' ' . $a['original_id']), ENT_QUOTES) ?>"
            data-deleted-type="<?= htmlspecialchars(strtolower($a['original_table'])) ?>"
            data-deleted-status="<?= $a['restored_at'] ? 'restored' : 'deleted' ?>">
          <th>Type</th>
          <th>Item</th>
          <th>Deleted By</th>
          <th>Deleted At</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($archived as $a):
          $data  = json_decode($a['record_data'], true) ?: [];
          $meta  = $tableLabels[$a['original_table']] ?? ['label' => ucfirst($a['original_table']), 'icon' => 'bi-box'];
          $summary = summarizeArchivedRecord($a['original_table'], $data);
        ?>
        <tr>
          <td>
            <span class="rb-type-badge"><i class="bi <?= $meta['icon'] ?>"></i> <?= htmlspecialchars($meta['label']) ?></span>
          </td>
          <td class="rb-summary" title="<?= htmlspecialchars($summary) ?>"><?= htmlspecialchars($summary) ?></td>
          <td><?= htmlspecialchars($a['deleted_by_name']) ?></td>
          <td class="rb-date"><?= date('M d, Y g:i A', strtotime($a['deleted_at'])) ?></td>
          <td>
            <?php if ($a['restored_at']): ?>
            <span class="badge text-bg-success">Restored</span>
            <?php else: ?>
            <span class="badge text-bg-warning">Deleted</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="rb-actions">
              <?php if (!$a['restored_at']): ?>
              <button class="rb-btn rb-btn-restore" data-archive-id="<?= $a['archive_id'] ?>" title="Restore">
                <i class="bi bi-arrow-counterclockwise"></i> Restore
              </button>
              <button class="rb-btn rb-btn-purge" data-archive-id="<?= $a['archive_id'] ?>"
                      data-summary="<?= htmlspecialchars($summary) ?>" title="Permanently delete">
                <i class="bi bi-trash3"></i>
              </button>
              <?php else: ?>
              <span class="text-muted small">No actions</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr class="rb-no-results d-none"><td colspan="6" class="text-center text-muted py-4">No deleted records match these filters.</td></tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  </div>

  <div class="tab-pane fade" id="rbAudit">
    <div class="rb-table-wrap">
      <div class="p-3 border-bottom">
        <div class="rb-filter-bar rb-filter-bar-audit">
          <div class="rb-filter-search">
            <label class="visually-hidden" for="auditSearch">Search audit logs</label>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control" id="auditSearch" placeholder="Search users, actions, records, or details...">
          </div>
          <div class="rb-filter-select">
            <label class="visually-hidden" for="auditActionFilter">Filter audit action</label>
            <select class="form-select" id="auditActionFilter">
              <option value="">All actions</option>
              <?php
                $auditActions = array_unique(array_map(fn($log) => strtolower($log['action']), $auditLogs));
                sort($auditActions);
                foreach ($auditActions as $action):
              ?>
              <option value="<?= htmlspecialchars($action) ?>"><?= htmlspecialchars(auditActionLabel($action)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rb-filter-select">
            <label class="visually-hidden" for="auditTableFilter">Filter audit table</label>
            <select class="form-select" id="auditTableFilter">
              <option value="">All areas</option>
              <?php
                $auditTables = array_unique(array_map(fn($log) => strtolower($log['table_name']), $auditLogs));
                sort($auditTables);
                foreach ($auditTables as $table):
                  $auditTableLabel = $tableLabels[$table]['label'] ?? ucwords(str_replace('_', ' ', $table));
              ?>
              <option value="<?= htmlspecialchars($table) ?>"><?= htmlspecialchars($auditTableLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table-custom rb-table" id="auditTable">
          <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>Details</th><th>IP Address</th></tr></thead>
          <tbody>
          <?php foreach ($auditLogs as $log):
            $details = auditDetails($log);
            $actionLabel = auditActionLabel($log['action']);
            $tableLabel = $tableLabels[$log['table_name']]['label'] ?? ucwords(str_replace('_', ' ', $log['table_name']));
            $searchText = strtolower($log['user_name'] . ' ' . $actionLabel . ' ' . $tableLabel . ' ' . $log['record_id'] . ' ' . $details);
          ?>
            <tr data-audit-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                data-audit-action="<?= htmlspecialchars(strtolower($log['action'])) ?>"
                data-audit-table="<?= htmlspecialchars(strtolower($log['table_name'])) ?>">
              <td class="rb-date"><?= date('M d, Y g:i A', strtotime($log['logged_at'])) ?></td>
              <td><?= htmlspecialchars($log['user_name']) ?></td>
              <td><span class="rb-type-badge"><?= htmlspecialchars($actionLabel) ?></span></td>
              <td><?= htmlspecialchars($tableLabel) ?></td>
              <td><?= $log['record_id'] !== null ? (int)$log['record_id'] : '—' ?></td>
              <td class="rb-summary" title="<?= htmlspecialchars($details) ?>"><?= htmlspecialchars($details ?: '—') ?></td>
              <td><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          <tr id="auditNoResults" class="d-none"><td colspan="7" class="text-center text-muted py-4">No audit logs match these filters.</td></tr>
          <?php if (!$auditLogs): ?><tr><td colspan="7" class="text-center text-muted py-4">No audit logs found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  </div>

</div>

<!-- ══ Permanently Delete Confirm Modal ═══════════════════════════════════ -->
<div class="modal fade" id="purgeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content rb-modal-content">
      <div class="modal-header rb-modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Permanently Delete</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="purgeAlert" class="alert alert-danger d-none"></div>
        <p class="mb-0">
          Permanently delete <strong id="purgeSummary"></strong>?
          <br><span class="text-danger">This cannot be undone — there is no further recovery after this.</span>
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger btn-sm" id="confirmPurgeBtn">
          <span id="purgeBtnText">Permanently Delete</span>
          <span id="purgeBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>