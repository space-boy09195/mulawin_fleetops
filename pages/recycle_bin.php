<?php
// ============================================================
// pages/recycle_bin.php
// View and restore soft-deleted records — Head Management only.
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
    SELECT archive_id, original_table, original_id, record_data, deleted_by_name, deleted_at
    FROM deleted_records
    WHERE restored_at IS NULL
    ORDER BY deleted_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$tableLabels = [
    'announcements' => ['label' => 'Announcement', 'icon' => 'bi-megaphone'],
    'documents'     => ['label' => 'Document',     'icon' => 'bi-file-earmark'],
];
?>

<div class="rb-page">

  <div class="rb-header">
    <div>
      <h1 class="rb-title">Recycle Bin</h1>
      <p class="rb-subtitle">Deleted items are kept here until restored or permanently removed</p>
    </div>
  </div>

  <?php if (empty($archived)): ?>
  <div class="rb-empty">
    <i class="bi bi-trash3 rb-empty-icon"></i>
    <p>Nothing in the recycle bin.</p>
  </div>
  <?php else: ?>
  <div class="rb-table-wrap">
    <table class="table rb-table">
      <thead>
        <tr>
          <th>Type</th>
          <th>Item</th>
          <th>Deleted By</th>
          <th>Deleted At</th>
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
            <div class="rb-actions">
              <button class="rb-btn rb-btn-restore" data-archive-id="<?= $a['archive_id'] ?>" title="Restore">
                <i class="bi bi-arrow-counterclockwise"></i> Restore
              </button>
              <button class="rb-btn rb-btn-purge" data-archive-id="<?= $a['archive_id'] ?>"
                      data-summary="<?= htmlspecialchars($summary) ?>" title="Permanently delete">
                <i class="bi bi-trash3"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

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