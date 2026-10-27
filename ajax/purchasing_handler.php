<?php
// ============================================================
// ajax/purchasing_handler.php
// Suppliers, purchase requests / orders and receiving on the target schema
// (see includes/purchasing.php).
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/approval_workflow.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../app/modules/inventory/purchasing.php';

header('Content-Type: application/json');
requireAnyPermission(['purchasing.view', 'purchasing.manage']);
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'create_supplier') {
    requirePermission('purchasing.manage');
    $name = requiredString('supplier_name', 'Supplier name', 180);
    $contact = optionalString('contact_person', null, 150);
    $phone = optionalString('phone', null, 50);
    $email = optionalString('email', null, 150);
    $address = optionalString('address', null, 255);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Enter a valid supplier email.');
    }
    // The target has no unique supplier name; duplicates are refused here.
    if (existsWhere($pdo, 'suppliers', 'supplier_name', $name)) {
        jsonFail('A supplier with that name already exists.', 409);
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO suppliers (supplier_name, contact_person, contact_number, email, address, status)
             VALUES (?, ?, ?, ?, ?, 'Active')"
        );
        $stmt->execute([$name, $contact, $phone, $email, $address]);
        $id = (int)$pdo->lastInsertId();
        auditLog('CREATE', 'suppliers', $id, null, ['supplier_name' => $name]);
        $pdo->commit();
        jsonOk(['supplier_id' => $id], 'Supplier created.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('purchasing_handler/create_supplier: ' . $e->getMessage());
        jsonFail('Could not create supplier.', 500);
    }
}

// Edit or deactivate a supplier. Suppliers are never deleted: an inactive
// supplier keeps its purchase orders and parts and is only left out of new
// purchase orders.
if ($action === 'edit_supplier') {
    requirePermission('purchasing.manage');
    $supplierId = requiredInt('supplier_id', 'Supplier', 1);
    $name = requiredString('supplier_name', 'Supplier name', 180);
    $contact = optionalString('contact_person', null, 150);
    $phone = optionalString('phone', null, 50);
    $email = optionalString('email', null, 150);
    $status = requiredEnum('status', ['Active', 'Inactive'], 'Supplier status');
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Enter a valid supplier email.');
    }
    try {
        $pdo->beginTransaction();
        $old = $pdo->prepare(
            'SELECT supplier_name, contact_person, contact_number, email, status
             FROM suppliers WHERE supplier_id = ? FOR UPDATE'
        );
        $old->execute([$supplierId]);
        $oldData = $old->fetch(PDO::FETCH_ASSOC);
        if (!$oldData) {
            $pdo->rollBack();
            jsonFail('Supplier not found.', 404);
        }
        $duplicate = $pdo->prepare('SELECT 1 FROM suppliers WHERE supplier_name = ? AND supplier_id <> ? LIMIT 1');
        $duplicate->execute([$name, $supplierId]);
        if ($duplicate->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('A supplier with that name already exists.', 409);
        }
        $pdo->prepare(
            'UPDATE suppliers SET supplier_name = ?, contact_person = ?, contact_number = ?, email = ?, status = ?
             WHERE supplier_id = ?'
        )->execute([$name, $contact, $phone, $email, $status, $supplierId]);
        auditLog('UPDATE', 'suppliers', $supplierId, $oldData, [
            'supplier_name' => $name, 'contact_person' => $contact, 'contact_number' => $phone,
            'email' => $email, 'status' => $status,
        ]);
        $pdo->commit();
        jsonOk([], $status === 'Active' ? 'Supplier updated.' : 'Supplier updated and deactivated.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('purchasing_handler/edit_supplier: ' . $e->getMessage());
        jsonFail('Could not update supplier.', 500);
    }
}

if ($action === 'create_order') {
    requirePermission('purchasing.manage');
    $supplierId = requiredInt('supplier_id', 'Supplier', 1);
    $expectedAt = optionalString('expected_at', null, 10);
    $notes = optionalString('notes', null, 1000);
    $items = json_decode((string)($_POST['items'] ?? ''), true);
    if (!is_array($items) || count($items) < 1 || count($items) > 100) {
        jsonFail('Add at least one purchase-order item.');
    }
    if ($expectedAt !== null && !isValidDate($expectedAt)) {
        jsonFail('Expected delivery date is invalid.');
    }

    $supplier = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ? AND status = 'Active'");
    $supplier->execute([$supplierId]);
    $supplierName = $supplier->fetchColumn();
    if ($supplierName === false) {
        jsonFail('Supplier not found or inactive.');
    }

    $validated = [];
    $total = 0.0;
    $partQuery = $pdo->prepare('SELECT part_id, part_number, part_name FROM parts WHERE part_id = ? AND is_active = 1');
    foreach (array_values($items) as $index => $item) {
        $partId = filter_var($item['part_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = $item['quantity'] ?? null;
        $unitCost = $item['unit_cost'] ?? null;
        if ($partId === false || !is_numeric($quantity) || (float)$quantity <= 0
            || !is_numeric($unitCost) || (float)$unitCost < 0) {
            jsonFail('Purchase item ' . ($index + 1) . ' is invalid.');
        }
        $partQuery->execute([$partId]);
        $part = $partQuery->fetch(PDO::FETCH_ASSOC);
        if (!$part) {
            jsonFail('Purchase item ' . ($index + 1) . ' references an invalid part.');
        }
        $quantity = round((float)$quantity, 2);
        $unitCost = round((float)$unitCost, 2);
        $lineTotal = round($quantity * $unitCost, 2);
        $total += $lineTotal;
        $validated[] = [
            'part_id'     => $partId,
            'description' => mb_substr($part['part_number'] . ' — ' . $part['part_name'], 0, 255, 'UTF-8'),
            'quantity'    => $quantity,
            'unit_cost'   => $unitCost,
            'total'       => $lineTotal,
        ];
    }
    $total = round($total, 2);

    $pdo->beginTransaction();
    try {
        $stamp = date('YmdHis') . '-' . random_int(100, 999);
        $requestNumber = 'PR-' . $stamp;
        $poNumber = 'PO-' . $stamp;
        $pdo->prepare(
            "INSERT INTO purchase_requests
                (request_number, requested_by, department, purpose, total_amount, status, submitted_at)
             VALUES (?, ?, 'Purchasing', ?, ?, 'Pending Approval', NOW())"
        )->execute([
            $requestNumber,
            currentUserId(),
            $notes ?? ('Purchase order to ' . $supplierName),
            $total,
        ]);
        $requestId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_orders
                (po_number, purchase_request_id, supplier_id, order_date, expected_delivery_date,
                 total_amount, status, created_by)
             VALUES (?, ?, ?, CURDATE(), ?, ?, 'Draft', ?)"
        )->execute([$poNumber, $requestId, $supplierId, $expectedAt, $total, currentUserId()]);
        $orderId = (int)$pdo->lastInsertId();
        $requestItem = $pdo->prepare(
            'INSERT INTO purchase_request_items
                (purchase_request_id, part_id, description, quantity, estimated_unit_cost, estimated_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $orderItem = $pdo->prepare(
            'INSERT INTO purchase_order_items (purchase_order_id, part_id, description, quantity, unit_cost, total_cost)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($validated as $line) {
            $requestItem->execute([$requestId, $line['part_id'], $line['description'], $line['quantity'], $line['unit_cost'], $line['total']]);
            $orderItem->execute([$orderId, $line['part_id'], $line['description'], $line['quantity'], $line['unit_cost'], $line['total']]);
        }
        $approvalId = createApprovalRequest($pdo, 'purchase_request', 'purchase_request', $requestId, currentUserId(), $notes);
        auditLog('CREATE', 'purchase_requests', $requestId, null, [
            'request_number'      => $requestNumber,
            'po_number'           => $poNumber,
            'purchase_order_id'   => $orderId,
            'total_amount'        => $total,
            'approval_request_id' => $approvalId,
        ]);
        $pdo->commit();
        jsonOk(['purchase_order_id' => $orderId, 'po_number' => $poNumber], 'Purchase order submitted for approval.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) jsonFail($e->getMessage(), 409);
        error_log('purchasing_handler/create_order: ' . $e->getMessage());
        jsonFail('Could not create purchase order.', 500);
    }
}

if ($action === 'receive_order') {
    requirePermission('purchasing.manage');
    $orderId = requiredInt('purchase_order_id', 'Purchase order', 1);
    $receiptNumber = optionalString('receipt_number', null, 100);
    $received = json_decode((string)($_POST['received'] ?? ''), true);
    if (!is_array($received) || !$received) {
        jsonFail('Enter received quantities.');
    }
    $pdo->beginTransaction();
    try {
        $orderQuery = $pdo->prepare(
            'SELECT purchase_order_id, po_number, purchase_request_id, status
             FROM purchase_orders WHERE purchase_order_id = ? FOR UPDATE'
        );
        $orderQuery->execute([$orderId]);
        $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!$order || !in_array($order['status'], ['Issued', 'Partially Received'], true)) {
            throw new DomainException('Only approved purchase orders can be received.');
        }
        $lines = purchaseOrderLines($pdo, $orderId);
        $pdo->prepare(
            'INSERT INTO purchase_receipts (purchase_order_id, receipt_number, received_date, received_by, notes)
             VALUES (?, ?, CURDATE(), ?, ?)'
        )->execute([$orderId, $receiptNumber, currentUserId(), 'Purchase order receipt']);
        $receiptId = (int)$pdo->lastInsertId();
        $anyReceived = false;
        foreach ($received as $itemId => $quantity) {
            if (!is_numeric($quantity) || (float)$quantity < 0) {
                throw new DomainException('Received quantity is invalid.');
            }
            $line = $lines[(int)$itemId] ?? null;
            $quantity = round((float)$quantity, 2);
            if (!$line || $quantity > (float)$line['quantity'] - (float)$line['received_quantity']) {
                throw new DomainException('Received quantity exceeds the remaining order quantity.');
            }
            if ($quantity <= 0) continue;
            $anyReceived = true;
            if ($line['part_id'] !== null) {
                $pdo->prepare(
                    "INSERT INTO inventory_transactions
                        (part_id, transaction_type, quantity, unit_cost, reference_type, reference_id,
                         performed_by, remarks)
                     VALUES (?, 'Stock In', ?, ?, 'purchase_order_item', ?, ?, ?)"
                )->execute([
                    $line['part_id'], $quantity, $line['unit_cost'], (int)$itemId,
                    currentUserId(), 'Ref: ' . $order['po_number'] . '. Purchase order receipt #' . $receiptId,
                ]);
                $pdo->prepare(
                    'INSERT INTO inventory (part_id, quantity_on_hand) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE quantity_on_hand = quantity_on_hand + VALUES(quantity_on_hand)'
                )->execute([$line['part_id'], $quantity]);
                $pdo->prepare('UPDATE parts SET unit_cost = ? WHERE part_id = ?')
                    ->execute([$line['unit_cost'], $line['part_id']]);
            }
            $lines[(int)$itemId]['received_quantity'] = (float)$line['received_quantity'] + $quantity;
        }
        if (!$anyReceived) {
            throw new DomainException('Enter at least one received quantity.');
        }
        $fullyReceived = true;
        foreach ($lines as $line) {
            if ((float)$line['received_quantity'] < (float)$line['quantity']) {
                $fullyReceived = false;
            }
        }
        $status = $fullyReceived ? 'Received' : 'Partially Received';
        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE purchase_order_id = ?')
            ->execute([$status, $orderId]);
        if ($fullyReceived && $order['purchase_request_id'] !== null) {
            $pdo->prepare("UPDATE purchase_requests SET status = 'Completed' WHERE purchase_request_id = ? AND status = 'Ordered'")
                ->execute([(int)$order['purchase_request_id']]);
        }
        auditLog('RECEIVE', 'purchase_orders', $orderId, ['status' => $order['status']], ['status' => $status, 'receipt_id' => $receiptId]);
        $pdo->commit();
        jsonOk([], 'Purchase receipt recorded.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) jsonFail($e->getMessage(), 409);
        error_log('purchasing_handler/receive_order: ' . $e->getMessage());
        jsonFail('Could not record the purchase receipt.', 500);
    }
}

if ($action === 'list') {
    requirePermission('purchasing.view');
    $orders = $pdo->query(
        "SELECT po.purchase_order_id, po.po_number,
                IF(pr.status IN ('Pending Approval', 'Rejected'), pr.status, po.status) AS status,
                po.total_amount, po.expected_delivery_date AS expected_at,
                s.supplier_name, COALESCE(e.full_name, u.username) AS requested_by_name
         FROM purchase_orders po
         JOIN suppliers s ON s.supplier_id = po.supplier_id
         JOIN users u ON u.user_id = po.created_by
         LEFT JOIN employees e ON e.employee_id = u.employee_id
         LEFT JOIN purchase_requests pr ON pr.purchase_request_id = po.purchase_request_id
         ORDER BY po.created_at DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    jsonOk(['orders' => $orders]);
}

jsonFail('Unknown action.');
