<?php
// ============================================================
// pages/automation.php
// Safe control center for future system automations.
// No automation jobs are registered yet.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

requireRole([ROLE_HEAD_MANAGEMENT]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/automation.js';
layoutHead('Automation', APP_BASE . '/assets/css/automation.css');

$pdo = getDBConnection();
$engineEnabled = false;
$migrationReady = true;

try {
    $stmt = $pdo->prepare("SELECT setting_value FROM automation_settings WHERE setting_key = 'automation_engine_enabled'");
    $stmt->execute();
    $engineEnabled = $stmt->fetchColumn() === '1';
} catch (PDOException $e) {
    if ($e->getCode() === '42S02' || str_contains($e->getMessage(), "doesn't exist")) {
        $migrationReady = false;
    } else {
        throw $e;
    }
}
?>
<div class="automation-page">
  <div class="page-header">
    <h1 class="page-title">Automation</h1>
    <p class="page-subtitle">Control future background tasks without changing normal FleetOps workflows.</p>
  </div>

  <?php if (!$migrationReady): ?>
  <div class="alert alert-warning" role="alert">
    <strong>Automation controls are not installed yet.</strong>
    Run <code>db/automation_settings_migration.sql</code> before using this page.
  </div>
  <?php endif; ?>

  <div class="automation-card">
    <div>
      <div class="automation-card-title"><i class="bi bi-power me-2"></i>Automation engine</div>
      <p class="automation-card-text">
        There are currently no automated jobs configured. This switch is intentionally inactive until a reviewed
        automation is added. Turning it on now will not run anything.
      </p>
    </div>
    <div class="automation-status <?= $engineEnabled ? 'is-on' : 'is-off' ?>">
      <?= $engineEnabled ? 'On' : 'Off' ?>
    </div>
  </div>

  <div class="automation-card automation-card-muted">
    <div>
      <div class="automation-card-title"><i class="bi bi-shield-check me-2"></i>Safe-by-default controls</div>
      <p class="automation-card-text">
        Future automations will be listed here individually. Each one can have its own schedule, last-run status,
        error message, and separate on/off switch. No automation will be allowed to silently change records.
      </p>
    </div>
    <span class="badge text-bg-secondary">No jobs configured</span>
  </div>

  <?php if ($migrationReady): ?>
  <form method="post" action="<?= APP_BASE ?>/ajax/automation_handler.php" class="automation-control-form" id="automationControlForm">
    <?= csrfInput() ?>
    <input type="hidden" name="action" value="toggle_engine">
    <input type="hidden" name="enabled" value="<?= $engineEnabled ? '0' : '1' ?>">
    <button type="submit" class="btn btn-outline-secondary" disabled title="No automation jobs are configured yet">
      <i class="bi bi-toggle2-off me-1"></i>
      <?= $engineEnabled ? 'Turn automation engine off' : 'Turn automation engine on' ?>
    </button>
    <small class="text-muted">The control will become available after the first reviewed automation is installed.</small>
  </form>
  <?php endif; ?>
</div>
