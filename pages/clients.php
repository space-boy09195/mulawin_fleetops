<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('clients.view');
$GLOBALS['page_js'] = APP_BASE . '/assets/js/clients.js';
$pdo = getDBConnection();
$clients = $pdo->query(
    'SELECT c.*, u.full_name AS created_by_name, parent.client_name AS parent_client_name
     FROM clients c
     JOIN users u ON u.user_id = c.created_by
     LEFT JOIN clients parent ON parent.client_id = c.parent_client_id
     ORDER BY c.is_active DESC, c.client_name'
)->fetchAll(PDO::FETCH_ASSOC);
$parentClients = $pdo->query(
    "SELECT client_id, client_name FROM clients
     WHERE is_active = 1 AND client_type IN ('Direct', 'Forwarder')
     ORDER BY client_name"
)->fetchAll(PDO::FETCH_ASSOC);
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
layoutHead('Clients', APP_BASE . '/assets/css/billing.css');
?>
<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div><h1 class="page-title">Clients</h1><p class="page-subtitle">Manage the client registry used by Accounting and Dispatch.</p></div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clientModal" id="addClientButton"><i class="bi bi-person-plus me-1"></i>Add Client</button>
</div>
<div id="clientFormAlert" class="alert d-none"></div>
<div class="card">
  <div class="table-responsive"><table class="table-custom">
    <thead><tr><th>Client</th><th>Type / Parent</th><th>Contact</th><th>Phone</th><th>Email</th><th>Address</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$clients): ?><tr><td colspan="8" class="text-center text-muted py-4">No clients have been added.</td></tr>
    <?php else: foreach ($clients as $client): ?>
      <tr>
        <td><strong><?= $escape($client['client_name']) ?></strong><br><span class="text-muted small">Added by <?= $escape($client['created_by_name']) ?></span></td>
        <td><?= $escape($client['client_type']) ?><?php if ($client['parent_client_name']): ?><br><span class="text-muted small">via <?= $escape($client['parent_client_name']) ?></span><?php endif; ?></td>
        <td><?= $escape($client['contact_person'] ?? '—') ?></td>
        <td><?= $escape($client['phone'] ?? '—') ?></td>
        <td><?= $escape($client['email'] ?? '—') ?></td>
        <td><?= $escape($client['address'] ?? '—') ?></td>
        <td><span class="status-badge <?= $client['is_active'] ? 'available' : 'inactive' ?>"><?= $client['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-primary js-edit-client" data-bs-toggle="modal" data-bs-target="#clientModal"
            data-id="<?= (int)$client['client_id'] ?>" data-name="<?= $escape($client['client_name']) ?>"
            data-contact="<?= $escape($client['contact_person'] ?? '') ?>" data-phone="<?= $escape($client['phone'] ?? '') ?>"
            data-email="<?= $escape($client['email'] ?? '') ?>" data-address="<?= $escape($client['address'] ?? '') ?>"
            data-notes="<?= $escape($client['notes'] ?? '') ?>" data-type="<?= $escape($client['client_type']) ?>"
            data-parent="<?= (int)($client['parent_client_id'] ?? 0) ?>">Edit</button>
          <a class="btn btn-sm btn-outline-info" href="<?= APP_BASE ?>/pages/client_master_data.php?client_id=<?= (int)$client['client_id'] ?>">Locations &amp; Rates</a>
          <button class="btn btn-sm btn-outline-<?= $client['is_active'] ? 'warning' : 'success' ?> js-toggle-client" data-id="<?= (int)$client['client_id'] ?>" data-active="<?= (int)$client['is_active'] ?>"><?= $client['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<div class="modal fade" id="clientModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form id="clientForm">
      <div class="modal-header"><h5 class="modal-title" id="clientModalTitle">Add Client</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><input type="hidden" name="client_id" id="clientId">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Client Name *</label><input class="form-control" name="client_name" maxlength="150" required></div>
          <div class="col-md-6"><label class="form-label">Client Type *</label><select class="form-select" name="client_type" id="clientType" required><option value="Direct">Direct</option><option value="Forwarder">Forwarder</option><option value="End Client">End Client</option></select></div>
          <div class="col-md-6" id="parentClientWrap"><label class="form-label">Parent Forwarder / Client</label><select class="form-select" name="parent_client_id" id="parentClient"><option value="">None</option><?php foreach ($parentClients as $parent): ?><option value="<?= (int)$parent['client_id'] ?>"><?= $escape($parent['client_name']) ?></option><?php endforeach; ?></select></div>
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
