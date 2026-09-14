<?php
// ============================================================
// pages/automation.php
// Safe control center for read-only system automations.
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
$settings = [];

try {
    $settings = $pdo->query("SELECT setting_key, setting_value FROM automation_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $engineEnabled = ($settings['automation_engine_enabled'] ?? '0') === '1';
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
    <p class="page-subtitle">    Control isolated, read-only notification jobs without changing normal FleetOps workflows.</p>
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
        Jobs run only through the separate CLI scheduler and only when both the engine and the individual job
        are enabled. They send notifications only; they do not approve, delete, or modify operational records.
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
        Each job is independent, deduplicated, and logged. A failed job is recorded without stopping other jobs.
      </p>
    </div>
    <span class="badge text-bg-secondary">Read-only notifications</span>
  </div>

  <?php if ($migrationReady): ?>
  <form method="post" action="<?= APP_BASE ?>/ajax/automation_handler.php" class="automation-control-form" id="automationControlForm">
    <?= csrfInput() ?>
    <input type="hidden" name="action" value="toggle_engine">
    <input type="hidden" name="enabled" value="<?= $engineEnabled ? '0' : '1' ?>">
    <button type="submit" class="btn btn-outline-<?= $engineEnabled ? 'danger' : 'success' ?>">
      <i class="bi bi-power me-1"></i>
      <?= $engineEnabled ? 'Turn automation engine off' : 'Turn automation engine on' ?>
    </button>
    <small class="text-muted">The engine must be on before any selected job can run.</small>
  </form>
  <div class="automation-job-grid">
    <?php
    $jobs = [
      'pending_approval_reminders' => ['Pending approval reminders', 'Notify Head Management about route or dispatch requests older than 24 hours.', 'bi-inbox'],
      'expiry_reminders' => ['License and document expiry reminders', 'Notify authorized users about employee licenses and documents expiring within 30 days.', 'bi-calendar-x'],
      'maintenance_due_reminders' => ['Maintenance due reminders', 'Notify Maintenance about trucks with maintenance due dates within 14 days.', 'bi-tools'],
      'unpaid_billing_reminders' => ['Unpaid billing reminders', 'Notify Accounting about unpaid or overdue billings without changing billing status.', 'bi-receipt'],
      'daily_analytics_summary' => ['Daily analytics summary', 'Send Head Management a read-only daily summary of trips, lateness, maintenance, and collections.', 'bi-bar-chart-line'],
      'system_health_checks' => ['System health checks', 'Check required tables and missing document files, then notify Head Management if action is needed.', 'bi-heart-pulse'],
    ];
    foreach ($jobs as $key => [$title, $description, $icon]):
      $enabled = ($settings[$key] ?? '0') === '1';
    ?>
    <div class="automation-job-card">
      <div class="automation-job-icon"><i class="bi <?= $icon ?>"></i></div>
      <div class="automation-job-content"><h2><?= htmlspecialchars($title) ?></h2><p><?= htmlspecialchars($description) ?></p></div>
      <form method="post" action="<?= APP_BASE ?>/ajax/automation_handler.php" class="automation-job-toggle">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="toggle_job">
        <input type="hidden" name="job_key" value="<?= htmlspecialchars($key) ?>">
        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
        <button type="submit" class="btn btn-sm btn-outline-<?= $enabled ? 'danger' : 'success' ?>"><?= $enabled ? 'Turn off' : 'Turn on' ?></button>
        <span class="automation-job-state <?= $enabled ? 'is-on' : 'is-off' ?>"><?= $enabled ? 'On' : 'Off' ?></span>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
