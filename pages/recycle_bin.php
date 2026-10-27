<?php
// ============================================================
// pages/recycle_bin.php
// View and restore deleted records — Admin only.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/core/soft_delete.php';

// Admin only, by the validated role key (isAdmin), not a legacy role grant.
// Unauthorized responses match requireRole(): login check first, then a 403
// JSON reply for JSON/AJAX requests, otherwise the 403 page.
requireLogin();
if (!isAdmin()) {
    if (isJsonRequest()) {
        sendJsonAccessResponse(403, 'You do not have permission to perform this action.');
    }
    http_response_code(403);
    include __DIR__ . '/403.php';
    exit;
}

$GLOBALS['page_js'] = APP_BASE . '/assets/js/recycle_bin.js';
layoutHead('Recycle Bin', APP_BASE . '/assets/css/recycle_bin.css');

$pdo = getDBConnection();

$archived = $pdo->query("
    SELECT archive_id, original_table, original_id, record_data, deleted_by_name, deleted_at, restored_at
    FROM deleted_records
    ORDER BY deleted_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$tableLabels = [
    'announcements' => ['label' => 'Announcement', 'icon' => 'bi-megaphone'],
    'documents'     => ['label' => 'Document',     'icon' => 'bi-file-earmark'],
    'payroll_records' => ['label' => 'Payroll Record', 'icon' => 'bi-cash-stack'],
];

?>

<div class="rb-page">

  <div class="page-header">
    <h1 class="page-title">Recycle Bin</h1>
    <p class="page-subtitle">Review deleted records and restore them.</p>
  </div>

  <ul class="nav rb-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation"><button class="rb-tab active" data-bs-toggle="tab" data-bs-target="#rbDeleted" type="button" role="tab">
      <i class="bi bi-trash3 me-1"></i>Deleted Records <span class="rb-tab-count"><?= count(array_filter($archived, fn($a) => $a['restored_at'] === null)) ?></span>
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
        <tr>
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
        <tr data-deleted-search="<?= htmlspecialchars(strtolower($summary . ' ' . $a['original_table'] . ' ' . $a['deleted_by_name'] . ' ' . $a['original_id']), ENT_QUOTES) ?>"
            data-deleted-type="<?= htmlspecialchars(strtolower($a['original_table'])) ?>"
            data-deleted-status="<?= $a['restored_at'] ? 'restored' : 'deleted' ?>">
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
