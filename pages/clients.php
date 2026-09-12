<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);
$GLOBALS['page_js'] = APP_BASE . '/assets/js/clients.js';
$pdo = getDBConnection();
$clients = $pdo->query('SELECT c.*, u.full_name AS created_by_name FROM clients c JOIN users u ON u.user_id=c.created_by ORDER BY c.is_active DESC, c.client_name')->fetchAll(PDO::FETCH_ASSOC);
layoutHead('Clients', APP_BASE . '/assets/css/billing.css');
?>
<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div><h1 class="page-title">Clients</h1><p class="page-subtitle">Manage the client registry used by Accounting and Dispatch.</p></div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clientModal" onclick="document.getElementById('clientForm').reset();document.getElementById('clientId').value=''"><i class="bi bi-person-plus me-1"></i>Add Client</button>
</div>
<div id="clientFormAlert" class="alert d-none"></div>
<div class="card">
  <div class="table-responsive"><table class="table-custom">
    <thead><tr><th>Client</th><th>Contact</th><th>Phone</th><th>Email</th><th>Address</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$clients): ?><tr><td colspan="7" class="text-center text-muted py-4">No clients have been added.</td></tr>
    <?php else: foreach ($clients as $client): ?>
      <tr>
        <td><strong><?= htmlspecialchars($client['client_name']) ?></strong><br><span class="text-muted small">Added by <?= htmlspecialchars($client['created_by_name']) ?></span></td>
        <td><?= htmlspecialchars($client['contact_person'] ?? '—') ?></td>
        <td><?= htmlspecialchars($client['phone'] ?? '—') ?></td>
        <td><?= htmlspecialchars($client['email'] ?? '—') ?></td>
        <td><?= htmlspecialchars($client['address'] ?? '—') ?></td>
        <td><span class="status-badge <?= $client['is_active'] ? 'available' : 'inactive' ?>"><?= $client['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td><button class="btn btn-sm btn-outline-<?= $client['is_active'] ? 'warning' : 'success' ?> js-toggle-client" data-id="<?= (int)$client['client_id'] ?>" data-active="<?= (int)$client['is_active'] ?>"><?= $client['is_active'] ? 'Deactivate' : 'Activate' ?></button></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<div class="modal fade" id="clientModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form id="clientForm">
      <div class="modal-header"><h5 class="modal-title">Add Client</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><input type="hidden" name="client_id" id="clientId">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Client Name *</label><input class="form-control" name="client_name" maxlength="150" required></div>
          <div class="col-md-6"><label class="form-label">Contact Person</label><input class="form-control" name="contact_person" maxlength="150"></div>
          <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="50"></div>
          <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" maxlength="150"></div>
          <div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255"></div>
          <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" maxlength="5000" rows="3"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Client</button></div>
    </form>
  </div></div>
</div>
<?php layoutFoot(); ?>
