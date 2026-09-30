<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('purchasing.view');
$pdo = getDBConnection();
$suppliers = $pdo->query('SELECT supplier_id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name')->fetchAll(PDO::FETCH_ASSOC);
$parts = $pdo->query('SELECT part_id, part_number, part_name, unit, unit_cost FROM parts_inventory ORDER BY part_name')->fetchAll(PDO::FETCH_ASSOC);
$orders = $pdo->query(
    "SELECT po.po_number, po.status, po.total_amount, po.expected_at, s.supplier_name, u.full_name AS requester
     FROM purchase_order_headers po JOIN suppliers s ON s.supplier_id = po.supplier_id
     JOIN users u ON u.user_id = po.requested_by ORDER BY po.created_at DESC LIMIT 100"
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
  <div class="card-header-custom"><h2 class="card-title-custom">Add supplier</h2></div>
  <div class="card-body-custom">
    <form id="supplierForm" class="row g-3">
      <div class="col-md-4"><label class="form-label" for="supplierName">Supplier name</label><input id="supplierName" class="form-control" maxlength="150" required></div>
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
      <div class="col-md-4"><label class="form-label">Supplier</label><select id="purchaseSupplier" class="form-select" required><option value="">Select supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int)$supplier['supplier_id'] ?>"><?= htmlspecialchars($supplier['supplier_name']) ?></option><?php endforeach; ?></select></div>
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
  document.getElementById('supplierForm').addEventListener('submit', async event => {
    event.preventDefault();
    const body = new URLSearchParams({
      action: 'create_supplier',
      supplier_name: document.getElementById('supplierName').value,
      contact_person: document.getElementById('supplierContact').value,
      phone: document.getElementById('supplierPhone').value,
      email: document.getElementById('supplierEmail').value,
      [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN
    });
    const response = await fetch('<?= APP_BASE ?>/ajax/purchasing_handler.php', {method: 'POST', body});
    const result = await response.json();
    show(result.message || 'Request completed.', result.success);
    if (result.success) setTimeout(() => window.location.reload(), 500);
  });
  document.getElementById('addPurchaseItem').addEventListener('click', () => {
    const part = document.getElementById('purchasePart');
    const quantity = Number(document.getElementById('purchaseQuantity').value);
    const unitCost = Number(document.getElementById('purchaseUnitCost').value);
    if (!part.value || quantity <= 0 || unitCost < 0) { show('Choose a part and valid quantity/cost.', false); return; }
    items.push({part_id: Number(part.value), label: part.options[part.selectedIndex].text, quantity, unit_cost: unitCost});
    list.innerHTML = items.map((item, index) => `<li class="list-group-item d-flex justify-content-between"><span>${item.label} — ${item.quantity} × ₱${item.unit_cost.toFixed(2)}</span><button type="button" class="btn btn-sm btn-outline-danger" data-remove="${index}">Remove</button></li>`).join('');
    list.querySelectorAll('[data-remove]').forEach(button => button.addEventListener('click', () => { items.splice(Number(button.dataset.remove), 1); button.closest('li').remove(); }));
  });
  document.getElementById('purchaseOrderForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!items.length) { show('Add at least one item.', false); return; }
    const body = new URLSearchParams({action: 'create_order', supplier_id: document.getElementById('purchaseSupplier').value, expected_at: document.getElementById('purchaseExpected').value, notes: document.getElementById('purchaseNotes').value, items: JSON.stringify(items), [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN});
    const response = await fetch('<?= APP_BASE ?>/ajax/purchasing_handler.php', {method: 'POST', body});
    const result = await response.json(); show(result.message || 'Request completed.', result.success);
    if (result.success) setTimeout(() => window.location.reload(), 700);
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
