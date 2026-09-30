<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/approval_workflow.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');
requireAnyPermission(['purchasing.view', 'purchasing.manage']);
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'create_supplier') {
    requirePermission('purchasing.manage');
    $name = requiredString('supplier_name', 'Supplier name', 150);
    $contact = optionalString('contact_person', null, 150);
    $phone = optionalString('phone', null, 50);
    $email = optionalString('email', null, 150);
    $address = optionalString('address', null, 255);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Enter a valid supplier email.');
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $contact, $phone, $email, $address, currentUserId()]);
        $id = (int)$pdo->lastInsertId();
        auditLog('CREATE', 'suppliers', $id, null, ['supplier_name' => $name]);
        jsonOk(['supplier_id' => $id], 'Supplier created.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonFail('A supplier with that name already exists.', 409);
        }
        error_log('purchasing_handler/create_supplier: ' . $e->getMessage());
        jsonFail('Could not create supplier.', 500);
    }
}

if ($action === 'create_order') {
    requirePermission('purchasing.manage');
    $supplierId = requiredInt('supplier_id', 'Supplier', 1);
    $expectedAt = optionalString('expected_at', null, 10);
    $notes = optionalString('notes');
    $items = json_decode((string)($_POST['items'] ?? ''), true);
    if (!is_array($items) || count($items) < 1 || count($items) > 100) {
        jsonFail('Add at least one purchase-order item.');
    }
    if ($expectedAt !== null && !isValidDate($expectedAt)) {
        jsonFail('Expected delivery date is invalid.');
    }

    $supplier = $pdo->prepare('SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND is_active = 1');
    $supplier->execute([$supplierId]);
    if (!$supplier->fetchColumn()) {
        jsonFail('Supplier not found or inactive.');
    }

    $validated = [];
    $total = 0;
    $partQuery = $pdo->prepare('SELECT part_id, unit_cost FROM parts_inventory WHERE part_id = ?');
    foreach ($items as $index => $item) {
        $partId = filter_var($item['part_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = $item['quantity'] ?? null;
        $unitCost = $item['unit_cost'] ?? null;
        if ($partId === false || !is_numeric($quantity) || (float)$quantity <= 0
            || !is_numeric($unitCost) || (float)$unitCost < 0) {
            jsonFail('Purchase item ' . ((int)$index + 1) . ' is invalid.');
        }
        $partQuery->execute([$partId]);
        if (!$partQuery->fetch(PDO::FETCH_ASSOC)) {
            jsonFail('Purchase item ' . ((int)$index + 1) . ' references an invalid part.');
        }
        $quantity = (float)$quantity;
        $unitCost = (float)$unitCost;
        $total += $quantity * $unitCost;
        $validated[] = [$partId, $quantity, $unitCost];
    }

    $pdo->beginTransaction();
    try {
        $poNumber = 'PO-' . date('YmdHis') . '-' . random_int(100, 999);
        $insert = $pdo->prepare(
            "INSERT INTO purchase_order_headers
                (po_number, supplier_id, requested_by, expected_at, notes, total_amount)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([$poNumber, $supplierId, currentUserId(), $expectedAt, $notes, $total]);
        $orderId = (int)$pdo->lastInsertId();
        $itemInsert = $pdo->prepare(
            'INSERT INTO purchase_order_lines (purchase_order_id, part_id, quantity, unit_cost)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($validated as [$partId, $quantity, $unitCost]) {
            $itemInsert->execute([$orderId, $partId, $quantity, $unitCost]);
        }
        $approvalId = createApprovalRequest($pdo, 'purchase_request', 'purchase_order', $orderId, currentUserId());
        $pdo->prepare('UPDATE purchase_order_headers SET approval_id = ? WHERE purchase_order_id = ?')
            ->execute([$approvalId, $orderId]);
        $pdo->commit();
        auditLog('CREATE', 'purchase_order_headers', $orderId, null, ['po_number' => $poNumber, 'total_amount' => $total]);
        jsonOk(['purchase_order_id' => $orderId, 'po_number' => $poNumber], 'Purchase order submitted for approval.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('purchasing_handler/create_order: ' . $e->getMessage());
        jsonFail('Could not create purchase order.', 500);
    }
}

if ($action === 'receive_order') {
    requirePermission('purchasing.manage');
    $orderId = requiredInt('purchase_order_id', 'Purchase order', 1);
    $received = json_decode((string)($_POST['received'] ?? ''), true);
    if (!is_array($received) || !$received) {
        jsonFail('Enter received quantities.');
    }
    $pdo->beginTransaction();
    try {
        $orderQuery = $pdo->prepare(
            "SELECT purchase_order_id, status FROM purchase_order_headers
             WHERE purchase_order_id = ? FOR UPDATE"
        );
        $orderQuery->execute([$orderId]);
        $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!$order || !in_array($order['status'], ['Approved', 'Ordered', 'Partially Received'], true)) {
            throw new DomainException('Only approved purchase orders can be received.');
        }
        $itemQuery = $pdo->prepare(
            'SELECT purchase_order_item_id, part_id, quantity, received_quantity, unit_cost
             FROM purchase_order_lines WHERE purchase_order_item_id = ? AND purchase_order_id = ? FOR UPDATE'
        );
        foreach ($received as $itemId => $quantity) {
            if (!is_numeric($quantity) || (float)$quantity < 0) {
                throw new DomainException('Received quantity is invalid.');
            }
            $itemQuery->execute([(int)$itemId, $orderId]);
            $item = $itemQuery->fetch(PDO::FETCH_ASSOC);
            if (!$item || (float)$quantity > (float)$item['quantity'] - (float)$item['received_quantity']) {
                throw new DomainException('Received quantity exceeds the remaining order quantity.');
            }
            $quantity = (float)$quantity;
            if ($quantity <= 0) continue;
            $pdo->prepare(
                "INSERT INTO parts_movements
                    (part_id, recorded_by, movement_type, quantity, unit_cost, reference_number, notes)
                 VALUES (?, ?, 'Stock In', ?, ?, ?, ?)"
            )->execute([
                $item['part_id'], currentUserId(), $quantity, $item['unit_cost'],
                'PO-' . $orderId, 'Purchase order receipt',
            ]);
            $pdo->prepare('UPDATE parts_inventory SET quantity = quantity + ?, unit_cost = ? WHERE part_id = ?')
                ->execute([$quantity, $item['unit_cost'], $item['part_id']]);
            $pdo->prepare(
                'UPDATE purchase_order_lines SET received_quantity = received_quantity + ?
                 WHERE purchase_order_item_id = ?'
            )->execute([$quantity, $item['purchase_order_item_id']]);
        }
        $remaining = $pdo->prepare(
            'SELECT COUNT(*) FROM purchase_order_lines WHERE purchase_order_id = ? AND received_quantity < quantity'
        );
        $remaining->execute([$orderId]);
        $status = (int)$remaining->fetchColumn() === 0 ? 'Received' : 'Partially Received';
        $pdo->prepare('UPDATE purchase_order_headers SET status = ? WHERE purchase_order_id = ?')
            ->execute([$status, $orderId]);
        $pdo->commit();
        auditLog('RECEIVE', 'purchase_order_headers', $orderId, null, ['status' => $status]);
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
        "SELECT po.purchase_order_id, po.po_number, po.status, po.total_amount, po.expected_at,
                s.supplier_name, u.full_name AS requested_by_name
         FROM purchase_order_headers po
         JOIN suppliers s ON s.supplier_id = po.supplier_id
         JOIN users u ON u.user_id = po.requested_by
         ORDER BY po.created_at DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    jsonOk(['orders' => $orders]);
}

jsonFail('Unknown action.');
