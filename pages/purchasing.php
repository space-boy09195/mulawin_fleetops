<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('purchasing.view');
$pdo = getDBConnection();
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'Active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
// Every supplier (active and inactive) for the supplier maintenance form.
$allSuppliers = $pdo->query("SELECT supplier_id, supplier_name, contact_person, contact_number, email, status FROM suppliers ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$parts = $pdo->query('SELECT part_id, part_number, part_name, unit, unit_cost FROM parts WHERE is_active = 1 ORDER BY part_name')->fetchAll(PDO::FETCH_ASSOC);
// Status: the purchase request's while it awaits (or failed) approval, the
// purchase order's afterwards.
$orders = $pdo->query(
    "SELECT po.po_number,
            IF(pr.status IN ('Pending Approval', 'Rejected'), pr.status, po.status) AS status,
            po.total_amount, po.expected_delivery_date AS expected_at, s.supplier_name,
            COALESCE(e.full_name, u.username) AS requester
     FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id
     JOIN users u ON u.user_id = po.created_by
     LEFT JOIN employees e ON e.employee_id = u.employee_id
     LEFT JOIN purchase_requests pr ON pr.purchase_request_id = po.purchase_request_id
     ORDER BY po.created_at DESC LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);
layoutHead('Purchasing');
?>
<div class="page-header">
  <h1 class="page-title">Purchasing</h1>
  <p class="page-subtitle">Suppliers, purchase orders, approvals, and receiving.</p>
</div>
<div id="purchasingFeedback" class="alert d-none" role="alert"></div>
<?php if (currentUserHasAnyPermission(['purchasing.manage'])): ?>
<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Add or edit supplier</h2></div>
  <div class="card-body-custom">
    <form id="supplierForm" class="row g-3">
      <div class="col-md-6"><label class="form-label" for="supplierPick">Supplier record</label><select id="supplierPick" class="form-select" data-refresh-options><option value="">New supplier</option><?php foreach ($allSuppliers as $supplier): ?><option value="<?= (int)$supplier['supplier_id'] ?>" data-name="<?= htmlspecialchars($supplier['supplier_name'], ENT_QUOTES) ?>" data-contact="<?= htmlspecialchars((string)$supplier['contact_person'], ENT_QUOTES) ?>" data-phone="<?= htmlspecialchars((string)$supplier['contact_number'], ENT_QUOTES) ?>" data-email="<?= htmlspecialchars((string)$supplier['email'], ENT_QUOTES) ?>" data-status="<?= htmlspecialchars($supplier['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($supplier['supplier_name']) ?><?= $supplier['status'] === 'Inactive' ? ' (Inactive)' : '' ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label" for="supplierStatus">Status</label><select id="supplierStatus" class="form-select"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
      <div class="col-md-4"><label class="form-label" for="supplierName">Supplier name</label><input id="supplierName" class="form-control" maxlength="180" required></div>
      <div class="col-md-3"><label class="form-label" for="supplierContact">Contact person</label><input id="supplierContact" class="form-control" maxlength="150"></div>
      <div class="col-md-2"><label class="form-label" for="supplierPhone">Phone</label><input id="supplierPhone" class="form-control" maxlength="50"></div>
      <div class="col-md-3"><label class="form-label" for="supplierEmail">Email</label><input id="supplierEmail" class="form-control" type="email" maxlength="150"></div>
      <div class="col-12"><button class="btn btn-outline-primary" type="submit">Save supplier</button></div>
    </form>
  </div>
</div>
<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Create purchase order</h2></div>
  <div class="card-body-custom">
    <form id="purchaseOrderForm" class="row g-3">
      <div class="col-md-4"><label class="form-label">Supplier</label><select id="purchaseSupplier" class="form-select" data-refresh-options required><option value="">Select supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int)$supplier['supplier_id'] ?>"><?= htmlspecialchars($supplier['supplier_name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Expected delivery</label><input id="purchaseExpected" class="form-control" type="date"></div>
      <div class="col-md-4"><label class="form-label">Notes</label><input id="purchaseNotes" class="form-control" maxlength="1000"></div>
      <div class="col-12"><label class="form-label">Part</label><select id="purchasePart" class="form-select"><option value="">Select part</option><?php foreach ($parts as $part): ?><option value="<?= (int)$part['part_id'] ?>"><?= htmlspecialchars(($part['part_number'] ? $part['part_number'] . ' — ' : '') . $part['part_name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label">Quantity</label><input id="purchaseQuantity" class="form-control" type="number" min="0.01" step="0.01"></div>
      <div class="col-md-3"><label class="form-label">Unit cost</label><input id="purchaseUnitCost" class="form-control" type="number" min="0" step="0.01"></div>
      <div class="col-md-3 align-self-end"><button type="button" id="addPurchaseItem" class="btn btn-outline-secondary">Add item</button></div>
      <div class="col-12"><ul id="purchaseItems" class="list-group"></ul></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Submit for approval</button></div>
    </form>
  </div>
</div>
<script>
(() => {
  const items = [];
  const list = document.getElementById('purchaseItems');
  const feedback = document.getElementById('purchasingFeedback');
  const show = (message, ok) => { feedback.textContent = message; feedback.className = `alert alert-${ok ? 'success' : 'danger'}`; };
  const renderItems = () => {
    const rows = items.map((item, index) => {
      const row = document.createElement('li');
      row.className = 'list-group-item d-flex justify-content-between';
      const label = document.createElement('span');
      label.textContent = `${item.label} — ${item.quantity} × ₱${item.unit_cost.toFixed(2)}`;
      const removeButton = document.createElement('button');
      removeButton.type = 'button';
      removeButton.className = 'btn btn-sm btn-outline-danger';
      removeButton.setAttribute('data-remove', index);
      removeButton.textContent = 'Remove';
      removeButton.addEventListener('click', () => {
        items.splice(index, 1);
        renderItems();
      });
      row.append(label, removeButton);
      return row;
    });
    list.replaceChildren(...rows);
  };
  const supplierPick = document.getElementById('supplierPick');
  supplierPick.addEventListener('change', () => {
    const option = supplierPick.options[supplierPick.selectedIndex];
    const editing = supplierPick.value !== '';
    document.getElementById('supplierName').value = editing ? option.dataset.name : '';
    document.getElementById('supplierContact').value = editing ? option.dataset.contact : '';
    document.getElementById('supplierPhone').value = editing ? option.dataset.phone : '';
    document.getElementById('supplierEmail').value = editing ? option.dataset.email : '';
    document.getElementById('supplierStatus').value = editing ? option.dataset.status : 'Active';
  });
  document.getElementById('supplierForm').addEventListener('submit', async event => {
    event.preventDefault();
    const body = new URLSearchParams({
      action: supplierPick.value ? 'edit_supplier' : 'create_supplier',
      supplier_id: supplierPick.value,
      status: document.getElementById('supplierStatus').value,
      supplier_name: document.getElementById('supplierName').value,
      contact_person: document.getElementById('supplierContact').value,
      phone: document.getElementById('supplierPhone').value,
      email: document.getElementById('supplierEmail').value,
      [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN
    });
    // window.sendAction (assets/js/layout.js): busy button, server-confirmed
    // result, readable errors, entries kept on failure.
    window.sendAction('<?= APP_BASE ?>/ajax/purchasing_handler.php', body, {
      feedback, label: 'save the supplier', form: event.target,
      button: event.submitter || event.target.querySelector('[type="submit"]')
    });
  });
  document.getElementById('addPurchaseItem').addEventListener('click', () => {
    const part = document.getElementById('purchasePart');
    const quantity = Number(document.getElementById('purchaseQuantity').value);
    const unitCost = Number(document.getElementById('purchaseUnitCost').value);
    if (!part.value || quantity <= 0 || unitCost < 0) { show('Choose a part and valid quantity/cost.', false); return; }
    items.push({part_id: Number(part.value), label: part.options[part.selectedIndex].text, quantity, unit_cost: unitCost});
    renderItems();
  });
  document.getElementById('purchaseOrderForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!items.length) { show('Add at least one item.', false); return; }
    const body = new URLSearchParams({action: 'create_order', supplier_id: document.getElementById('purchaseSupplier').value, expected_at: document.getElementById('purchaseExpected').value, notes: document.getElementById('purchaseNotes').value, items: JSON.stringify(items), [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN});
    const response = await fetch('<?= APP_BASE ?>/ajax/purchasing_handler.php', {method: 'POST', body});
    const result = await response.json(); show(result.message || 'Request completed.', result.success);
    if (result.success) setTimeout(() => window.softReload(), 700);
  });
})();
</script>
<?php endif; ?>
<div class="card">
  <div class="card-header-custom"><h2 class="card-title-custom">Purchase orders</h2></div>
  <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>PO</th><th>Supplier</th><th>Status</th><th>Total</th><th>Expected</th><th>Requested by</th></tr></thead><tbody>
  <?php foreach ($orders as $order): ?><tr><td><?= htmlspecialchars($order['po_number']) ?></td><td><?= htmlspecialchars($order['supplier_name']) ?></td><td><?= htmlspecialchars($order['status']) ?></td><td>₱<?= number_format((float)$order['total_amount'], 2) ?></td><td><?= htmlspecialchars($order['expected_at'] ?? '—') ?></td><td><?= htmlspecialchars($order['requester']) ?></td></tr><?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="6" class="text-center text-muted py-4">No purchase orders found.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php layoutFoot(); ?>
