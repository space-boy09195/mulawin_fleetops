<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('audit.view');

$pdo = getDBConnection();
$auditLogs = $pdo->query(
    "SELECT a.audit_id, a.action, a.entity_type, a.entity_id, a.old_values, a.new_values, a.created_at,
            COALESCE(e.full_name, u.username, 'System') AS actor_name
     FROM audit_logs a
     LEFT JOIN users u ON u.user_id = a.user_id
     LEFT JOIN employees e ON e.employee_id = u.employee_id
     ORDER BY a.created_at DESC, a.audit_id DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$approvalHistory = $pdo->query(
    "SELECT ah.approval_history_id, w.workflow_name, ar.entity_type, ar.entity_id,
            ah.action, ah.acted_at, ah.remarks,
            COALESCE(e.full_name, u.username, 'System') AS actor_name
     FROM approval_history ah
     JOIN approval_requests ar ON ar.approval_request_id = ah.approval_request_id
     JOIN approval_workflows w ON w.workflow_id = ar.workflow_id
     LEFT JOIN users u ON u.user_id = ah.acted_by
     LEFT JOIN employees e ON e.employee_id = u.employee_id
     ORDER BY ah.acted_at DESC, ah.approval_history_id DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$workflowEvents = $pdo->query(
    "SELECT we.workflow_event_id, we.entity_type, we.entity_id, we.event_type,
            we.old_status, we.new_status, we.created_at,
            COALESCE(e.full_name, u.username, 'System') AS actor_name
     FROM workflow_events we
     LEFT JOIN users u ON u.user_id = we.performed_by
     LEFT JOIN employees e ON e.employee_id = u.employee_id
     ORDER BY we.created_at DESC, we.workflow_event_id DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

function sanitizedAuditJson(?string $raw): ?string {
    if ($raw === null || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    $redact = static function (array $values) use (&$redact): array {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|secret|token|credential|hash|ip_address|user_agent/i', $key)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $redact($value);
            }
        }
        return $values;
    };
    return json_encode($redact($data), JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
}

layoutHead('Audit History');
?>

<div class="page-header">
  <h1 class="page-title">Audit History</h1>
  <p class="page-subtitle">Read-only record changes, approval decisions, and workflow events. The latest 200 entries from each source are shown.</p>
</div>
<div class="alert alert-info">
  Deleted-record archives, restore and purge actions are not available here. Screen views are not logged by the system. Sensitive credential-like values in recorded change details are redacted.
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#audit-record-changes" type="button" role="tab">Record changes <span class="badge text-bg-secondary"><?= count($auditLogs) ?></span></button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#audit-approvals" type="button" role="tab">Approval history <span class="badge text-bg-secondary"><?= count($approvalHistory) ?></span></button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#audit-workflows" type="button" role="tab">Workflow events <span class="badge text-bg-secondary"><?= count($workflowEvents) ?></span></button></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="audit-record-changes" role="tabpanel">
    <div class="table-responsive"><table class="table table-hover">
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Recorded changes</th></tr></thead>
      <tbody>
      <?php if (!$auditLogs): ?><tr><td colspan="5" class="text-muted">No audit entries recorded.</td></tr><?php endif; ?>
      <?php foreach ($auditLogs as $row): ?>
      <?php $details = sanitizedAuditJson($row['new_values']) ?? sanitizedAuditJson($row['old_values']); ?>
      <tr>
        <td><?= date('M d, Y H:i:s', strtotime($row['created_at'])) ?></td>
        <td><?= htmlspecialchars($row['actor_name']) ?></td>
        <td><?= htmlspecialchars($row['action']) ?></td>
        <td><?= htmlspecialchars(($row['entity_type'] ?? 'Record') . ' #' . ($row['entity_id'] ?? '—')) ?></td>
        <td>
          <?php if ($details !== null): ?>
          <details><summary>View changes</summary><pre class="small mb-0"><?= htmlspecialchars($details) ?></pre></details>
          <?php else: ?><span class="text-muted">No structured details</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="tab-pane fade" id="audit-approvals" role="tabpanel">
    <div class="table-responsive"><table class="table table-hover">
      <thead><tr><th>When</th><th>Workflow</th><th>Record</th><th>Decision</th><th>By</th><th>Remarks</th></tr></thead>
      <tbody>
      <?php if (!$approvalHistory): ?><tr><td colspan="6" class="text-muted">No approval history recorded.</td></tr><?php endif; ?>
      <?php foreach ($approvalHistory as $row): ?>
      <tr>
        <td><?= date('M d, Y H:i:s', strtotime($row['acted_at'])) ?></td>
        <td><?= htmlspecialchars($row['workflow_name']) ?></td>
        <td><?= htmlspecialchars($row['entity_type'] . ' #' . $row['entity_id']) ?></td>
        <td><?= htmlspecialchars($row['action']) ?></td>
        <td><?= htmlspecialchars($row['actor_name']) ?></td>
        <td><?= $row['remarks'] ? nl2br(htmlspecialchars($row['remarks'])) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="tab-pane fade" id="audit-workflows" role="tabpanel">
    <div class="table-responsive"><table class="table table-hover">
      <thead><tr><th>When</th><th>Record</th><th>Event</th><th>Status change</th><th>By</th></tr></thead>
      <tbody>
      <?php if (!$workflowEvents): ?><tr><td colspan="5" class="text-muted">No workflow events recorded.</td></tr><?php endif; ?>
      <?php foreach ($workflowEvents as $row): ?>
      <tr>
        <td><?= date('M d, Y H:i:s', strtotime($row['created_at'])) ?></td>
        <td><?= htmlspecialchars($row['entity_type'] . ' #' . $row['entity_id']) ?></td>
        <td><?= htmlspecialchars($row['event_type']) ?></td>
        <td><?= htmlspecialchars(($row['old_status'] ?? '—') . ' → ' . ($row['new_status'] ?? '—')) ?></td>
        <td><?= htmlspecialchars($row['actor_name']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php layoutFoot(); ?>
