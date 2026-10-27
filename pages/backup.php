<?php
// ============================================================
// pages/backup.php
// Back up all system data and uploaded documents — Admin only.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/modules/system/backup.php';

requireLogin();
if (!isAdmin()) {
    if (isJsonRequest()) {
        sendJsonAccessResponse(403, 'You do not have permission to perform this action.');
    }
    http_response_code(403);
    include __DIR__ . '/403.php';
    exit;
}

$GLOBALS['page_js'] = APP_BASE . '/assets/js/backup.js';
layoutHead('Backup');

$backups = listSystemBackups();
?>
  <div class="page-header d-flex justify-content-between align-items-center mb-4">
    <h1 class="page-title mb-0">Backup</h1>
    <button type="button" class="btn btn-primary" id="createBackupBtn">
      <i class="bi bi-hdd-network"></i> Back Up Now
    </button>
</div>

  <p class="text-muted">
    Saves a full copy of all system data and uploaded documents on the server
    (<code>backups/</code> folder). Download the SQL file to keep a copy elsewhere.
  </p>

  <div class="card">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead>
          <tr><th>Created</th><th>Database size</th><th class="text-end">Download</th></tr>
        </thead>
        <tbody>
        <?php if (!$backups): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">No backups yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td><?= htmlspecialchars($b['created']) ?></td>
            <td><?= number_format($b['db_bytes'] / 1024, 1) ?> KB</td>
            <td class="text-end">
              <form method="post" action="<?= APP_BASE ?>/ajax/backup_handler.php" class="d-inline">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="download">
                <input type="hidden" name="name" value="<?= htmlspecialchars($b['name']) ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-download"></i> SQL
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php layoutFoot(); ?>
