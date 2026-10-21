<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('clients.view');
$hideClientActions = in_array(currentRoleKey(), ['car_carrier_dispatcher', 'container_wing_van_dispatcher'], true);
$GLOBALS['page_js'] = APP_BASE . '/assets/js/clients.js';
$pdo = getDBConnection();
// Target clients: status Active/Inactive, contact_number, billing_address.
// The legacy client type, parent client, notes and creator have no target
// column; is_active / phone are aliased for the view.
$clients = $pdo->query(
    "SELECT c.client_id, c.client_name, c.contact_person, c.contact_number AS phone, c.email,
            c.address, c.billing_address, (c.status = 'Active') AS is_active, c.created_at
     FROM clients c
     ORDER BY (c.status = 'Active') DESC, c.client_name"
)->fetchAll(PDO::FETCH_ASSOC);
$allRoutes = $pdo->query('SELECT route_id, route_name, origin FROM routes WHERE is_active = 1 ORDER BY route_name')->fetchAll(PDO::FETCH_ASSOC);
$clientRouteIds = [];
foreach ($pdo->query('SELECT client_id, route_id FROM client_routes')->fetchAll(PDO::FETCH_ASSOC) as $link) {
    $clientRouteIds[(int)$link['client_id']][] = (int)$link['route_id'];
}
$routeNames = array_column($allRoutes, 'route_name', 'route_id');
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
    <thead><tr><th>Client</th><th>Billing Address</th><th>Contact</th><th>Phone</th><th>Email</th><th>Address</th><th>Routes</th><th>Status</th><?php if (!$hideClientActions): ?><th>Actions</th><?php endif; ?></tr></thead>
    <tbody>
    <?php if (!$clients): ?><tr><td colspan="<?= $hideClientActions ? 8 : 9 ?>" class="text-center text-muted py-4">No clients have been added.</td></tr>
    <?php else: foreach ($clients as $client): ?>
      <tr>
        <td><strong><?= $escape($client['client_name']) ?></strong><br><span class="text-muted small">Added <?= $escape(date('M j, Y', strtotime($client['created_at']))) ?></span></td>
        <td><?= $escape($client['billing_address'] ?? '—') ?></td>
        <td><?= $escape($client['contact_person'] ?? '—') ?></td>
        <td><?= $escape($client['phone'] ?? '—') ?></td>
        <td><?= $escape($client['email'] ?? '—') ?></td>
        <td><?= $escape($client['address'] ?? '—') ?></td>
        <td class="small"><?php
          $linked = array_filter(array_map(fn($id) => $routeNames[$id] ?? null, $clientRouteIds[(int)$client['client_id']] ?? []));
          echo $linked ? $escape(implode(', ', $linked)) : '<span class="text-muted">—</span>';
        ?></td>
        <td><span class="status-badge <?= $client['is_active'] ? 'available' : 'inactive' ?>"><?= $client['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <?php if (!$hideClientActions): ?>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-primary js-edit-client" data-bs-toggle="modal" data-bs-target="#clientModal"
            data-id="<?= (int)$client['client_id'] ?>" data-name="<?= $escape($client['client_name']) ?>"
            data-contact="<?= $escape($client['contact_person'] ?? '') ?>" data-phone="<?= $escape($client['phone'] ?? '') ?>"
            data-email="<?= $escape($client['email'] ?? '') ?>" data-address="<?= $escape($client['address'] ?? '') ?>"
            data-billing-address="<?= $escape($client['billing_address'] ?? '') ?>"
            data-routes="<?= $escape(json_encode($clientRouteIds[(int)$client['client_id']] ?? [])) ?>">Edit</button>
          <a class="btn btn-sm btn-outline-info" href="<?= APP_BASE ?>/pages/client_master_data.php?client_id=<?= (int)$client['client_id'] ?>">Locations &amp; Rates</a>
          <button class="btn btn-sm btn-outline-<?= $client['is_active'] ? 'warning' : 'success' ?> js-toggle-client" data-id="<?= (int)$client['client_id'] ?>" data-active="<?= (int)$client['is_active'] ?>"><?= $client['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </td>
        <?php endif; ?>
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
          <div class="col-md-6"><label class="form-label">Client Name *</label><input class="form-control" name="client_name" maxlength="180" required></div>
          <div class="col-md-6"><label class="form-label">Contact Person</label><input class="form-control" name="contact_person" maxlength="150"></div>
          <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="50"></div>
          <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" maxlength="150"></div>
          <div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255"></div>
          <div class="col-12"><label class="form-label">Billing Address</label><textarea class="form-control" name="billing_address" maxlength="5000" rows="3"></textarea></div>
          <div class="col-12">
            <label class="form-label">Routes <span class="text-muted">(dispatch offers only the routes linked here)</span></label>
            <div class="border rounded p-2" style="max-height:160px;overflow:auto;">
              <?php if (!$allRoutes): ?><span class="text-muted small">No active routes yet.</span><?php endif; ?>
              <?php foreach ($allRoutes as $route): ?>
              <div class="form-check">
                <input class="form-check-input js-client-route" type="checkbox" value="<?= (int)$route['route_id'] ?>" id="clientRoute<?= (int)$route['route_id'] ?>">
                <label class="form-check-label" for="clientRoute<?= (int)$route['route_id'] ?>"><?= $escape($route['route_name']) ?> <span class="text-muted small">— from <?= $escape($route['origin']) ?></span></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Client</button></div>
    </form>
  </div></div>
</div>
<?php layoutFoot(); ?>
