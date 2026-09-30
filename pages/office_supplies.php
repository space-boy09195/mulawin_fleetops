<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
requireAnyPermission(['admin.supplies.view', 'admin.supplies.manage']);
$pdo = getDBConnection();
$canManage = currentUserHasAnyPermission(['admin.supplies.manage']);
$items = $pdo->query('SELECT item_id,item_name,unit,quantity,reorder_level FROM office_supply_items WHERE is_active=1 ORDER BY item_name')->fetchAll(PDO::FETCH_ASSOC);
$movements = $pdo->query(
    'SELECT m.movement_type,m.quantity,m.quantity_change,m.reference_number,m.notes,m.created_at,
            i.item_name,i.unit,u.full_name AS recorded_by
     FROM office_supply_movements m JOIN office_supply_items i ON i.item_id=m.item_id
     JOIN users u ON u.user_id=m.recorded_by ORDER BY m.created_at DESC,m.movement_id DESC LIMIT 100'
)->fetchAll(PDO::FETCH_ASSOC);
layoutHead('Office Supplies');
?>
<div class="page-header"><h1 class="page-title">Office Supplies</h1><p class="page-subtitle">Track office consumables separately from vehicle parts inventory.</p></div>
<div id="supplyFeedback" class="alert d-none" role="alert"></div>
<?php if ($canManage): ?>
<div class="row g-4 mb-4"><div class="col-lg-5"><div class="card h-100"><div class="card-header-custom"><h2 class="card-title-custom">Add supply item</h2></div><div class="card-body-custom">
<form id="supplyItemForm" class="vstack gap-3"><div><label class="form-label">Item name</label><input id="supplyName" class="form-control" maxlength="150" required></div><div><label class="form-label">Unit</label><input id="supplyUnit" class="form-control" maxlength="50" value="piece" required></div><div><label class="form-label">Reorder level</label><input id="supplyReorder" class="form-control" type="number" min="0" step="0.01" value="0"></div><button class="btn btn-primary">Add item</button></form>
</div></div></div><div class="col-lg-7"><div class="card h-100"><div class="card-header-custom"><h2 class="card-title-custom">Record stock movement</h2></div><div class="card-body-custom">
<form id="supplyMovementForm" class="row g-3"><div class="col-md-6"><label class="form-label">Item</label><select id="movementItem" class="form-select" required><option value="">Select item</option><?php foreach($items as $item): ?><option value="<?= (int)$item['item_id'] ?>"><?= htmlspecialchars($item['item_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Movement</label><select id="movementType" class="form-select" required><option>Stock In</option><option>Issue</option><option>Adjustment</option></select></div><div class="col-md-4 d-none" id="adjustmentDirectionWrap"><label class="form-label">Adjustment direction</label><select id="adjustmentDirection" class="form-select"><option>Increase</option><option>Decrease</option></select></div><div class="col-md-4"><label class="form-label">Quantity</label><input id="movementQuantity" class="form-control" type="number" min="0.01" step="0.01" required></div><div class="col-md-4"><label class="form-label">Reference</label><input id="movementReference" class="form-control" maxlength="100"></div><div class="col-md-4"><label class="form-label">Notes</label><input id="movementNotes" class="form-control" maxlength="500"></div><div class="col-12"><button class="btn btn-outline-primary">Save movement</button></div></form>
</div></div></div></div>
<?php endif; ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Current stock</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Item</th><th>Unit</th><th>On hand</th><th>Reorder level</th><th>Status</th></tr></thead><tbody><?php foreach($items as $item): $low=(float)$item['quantity'] <= (float)$item['reorder_level']; ?><tr><td><?= htmlspecialchars($item['item_name']) ?></td><td><?= htmlspecialchars($item['unit']) ?></td><td><?= number_format((float)$item['quantity'],2) ?></td><td><?= number_format((float)$item['reorder_level'],2) ?></td><td><span class="badge text-bg-<?= $low ? 'warning' : 'success' ?>"><?= $low ? 'Reorder' : 'In Stock' ?></span></td></tr><?php endforeach; ?><?php if(!$items): ?><tr><td colspan="5" class="text-center text-muted py-4">No office supplies recorded.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><div class="card-header-custom"><h2 class="card-title-custom">Recent stock movements</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>When</th><th>Item</th><th>Movement</th><th>Quantity change</th><th>Reference</th><th>Recorded by</th></tr></thead><tbody><?php foreach($movements as $movement): ?><tr><td><?= htmlspecialchars($movement['created_at']) ?></td><td><?= htmlspecialchars($movement['item_name']) ?></td><td><?= htmlspecialchars($movement['movement_type']) ?></td><td><?= number_format((float)$movement['quantity_change'],2) ?> <?= htmlspecialchars($movement['unit']) ?></td><td><?= htmlspecialchars($movement['reference_number'] ?? '—') ?></td><td><?= htmlspecialchars($movement['recorded_by']) ?></td></tr><?php endforeach; ?><?php if(!$movements): ?><tr><td colspan="6" class="text-center text-muted py-4">No stock movements recorded.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if ($canManage): ?><script>
const supplySend = async data => { const response=await fetch('<?= APP_BASE ?>/ajax/office_supplies_handler.php',{method:'POST',body:data}); const result=await response.json(); const box=document.getElementById('supplyFeedback'); box.textContent=result.message||'Request completed.'; box.className=`alert alert-${result.success?'success':'danger'}`; if(result.success)setTimeout(()=>window.location.reload(),500); };
const movementType=document.getElementById('movementType');
const directionWrap=document.getElementById('adjustmentDirectionWrap');
movementType.addEventListener('change',()=>{directionWrap.classList.toggle('d-none',movementType.value!=='Adjustment');});
document.getElementById('supplyItemForm').addEventListener('submit',event=>{event.preventDefault();supplySend(new URLSearchParams({action:'create_item',item_name:document.getElementById('supplyName').value,unit:document.getElementById('supplyUnit').value,reorder_level:document.getElementById('supplyReorder').value,[window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN}));});
document.getElementById('supplyMovementForm').addEventListener('submit',event=>{event.preventDefault();supplySend(new URLSearchParams({action:'record_movement',item_id:document.getElementById('movementItem').value,movement_type:movementType.value,adjustment_direction:document.getElementById('adjustmentDirection').value,quantity:document.getElementById('movementQuantity').value,reference_number:document.getElementById('movementReference').value,notes:document.getElementById('movementNotes').value,[window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN}));});
</script><?php endif; ?>
<?php layoutFoot(); ?>
