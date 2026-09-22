<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Add new part ──────────────────────────────────────────────────────────────
if ($action === 'add_part') {

    if (currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
        jsonFail('Only Head Management can add parts.', 403);
    }

    $name          = requiredString('part_name', 'Part name', 150);
    $partNumber    = optionalString('part_number');
    $category      = requiredString('category', 'Category', 100);
    $unit          = optionalString('unit', 'pcs');
    $reorderLevel  = filter_input(INPUT_POST, 'reorder_level', FILTER_VALIDATE_INT);
    $reorderLevel  = ($reorderLevel === false || $reorderLevel === null) ? 5 : $reorderLevel;
    $unitCost      = optionalFloat('unit_cost');
    $rawInitialQty = $_POST['initial_qty'] ?? '';
    $supplier      = optionalString('supplier');

    if ($rawInitialQty === '') {
        jsonFail('Initial quantity is required.');
    }

    $initialQty = (int)$rawInitialQty;

    if ($reorderLevel < 0) {
        jsonFail('Reorder level cannot be negative.');
    }
    if ($initialQty < 0) {
        jsonFail('Initial quantity cannot be negative.');
    }

    // Check for duplicate part number
    if ($partNumber && existsWhere($pdo, 'parts_inventory', 'part_number', $partNumber)) {
        jsonFail('A part with that part number already exists.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO parts_inventory
                (part_number, part_name, category, unit, quantity, reorder_level, unit_cost, supplier)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$partNumber, $name, $category, $unit, $initialQty, $reorderLevel, $unitCost, $supplier]);
        $newId = (int)$pdo->lastInsertId();

        // Record initial stock-in movement if qty > 0
        if ($initialQty > 0) {
            $pdo->prepare("
                INSERT INTO parts_movements
                    (part_id, recorded_by, movement_type, quantity, unit_cost, notes)
                VALUES (?, ?, 'Stock In', ?, ?, 'Initial stock entry')
            ")->execute([$newId, currentUserId(), $initialQty, $unitCost]);
        }

        $pdo->commit();

        auditLog('ADD_PART', 'parts_inventory', $newId, null, [
            'part_name'    => $name,
            'category'     => $category,
            'initial_qty'  => $initialQty,
        ]);

        jsonOk(['id' => $newId], 'Part added successfully.');
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('parts_handler/add_part: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Record movement ───────────────────────────────────────────────────────────
if ($action === 'record_movement') {

    $partId        = requiredInt('part_id', 'Part', 1);
    $movType       = requiredEnum('movement_type', PARTS_MOVEMENT_TYPES, 'Movement type');
    $qty           = requiredInt('quantity', 'Quantity', 1);
    $unitCost      = optionalFloat('unit_cost');
    $maintenanceId = filter_input(INPUT_POST, 'maintenance_id', FILTER_VALIDATE_INT) ?: null;
    $reference     = optionalString('reference_number');
    $notes         = optionalString('notes');

    if ($maintenanceId) {
        $jobCheck = $pdo->prepare("SELECT record_id FROM maintenance_records WHERE record_id = ?");
        $jobCheck->execute([$maintenanceId]);
        if (!$jobCheck->fetch()) {
            jsonFail('Linked job not found.');
        }
    }

    // Fetch current stock
    $part = findOrFail($pdo, 'parts_inventory', 'part_id', $partId, 'Part not found.');

    // For Stock Out, ensure enough stock
    if ($movType === 'Stock Out' && $part['quantity'] < $qty) {
        jsonFail("Insufficient stock. Current stock: {$part['quantity']} {$part['unit']}.");
    }

    // Signed quantity: negative for Stock Out
    $signedQty  = ($movType === 'Stock Out') ? -$qty : $qty;
    $newQty     = $part['quantity'] + $signedQty;

    if ($newQty < 0) {
        jsonFail('Movement would result in negative stock.');
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO parts_movements
                (part_id, recorded_by, movement_type, quantity, unit_cost, maintenance_id, reference_number, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$partId, currentUserId(), $movType, $signedQty, $unitCost, $maintenanceId, $reference, $notes]);

        $movId = (int)$pdo->lastInsertId();

        // Update stock quantity and unit cost if Stock In
        if ($movType === 'Stock In' && $unitCost !== null) {
            $pdo->prepare("UPDATE parts_inventory SET quantity = ?, unit_cost = ? WHERE part_id = ?")
                ->execute([$newQty, $unitCost, $partId]);
        } else {
            $pdo->prepare("UPDATE parts_inventory SET quantity = ? WHERE part_id = ?")
                ->execute([$newQty, $partId]);
        }

        $pdo->commit();

        auditLog('PARTS_MOVEMENT', 'parts_movements', $movId,
            ['quantity' => $part['quantity']],
            ['quantity' => $newQty, 'movement_type' => $movType, 'change' => $signedQty]
        );

        jsonOk(
            ['new_stock' => $newQty],
            "Movement recorded. New stock: <strong>$newQty {$part['unit']}</strong>."
        );
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('parts_handler/record_movement: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Create purchase order ───────────────────────────────────────────────────
if ($action === 'create_po') {

    $partId    = requiredInt('part_id', 'Part', 1);
    $qty       = requiredInt('quantity', 'Quantity', 1);
    $unitCost  = optionalFloat('unit_cost');
    $supplier  = optionalString('supplier');
    $notes     = optionalString('notes');

    $part = findOrFail($pdo, 'parts_inventory', 'part_id', $partId, 'Part not found.');
    if (!$supplier) {
        $supplier = $part['supplier']; // default to the part's usual supplier
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders
                (po_number, part_id, quantity, unit_cost, supplier, notes, requested_by)
            VALUES ('', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$partId, $qty, $unitCost, $supplier, $notes, currentUserId()]);
        $poId = (int)$pdo->lastInsertId();

        $poNumber = 'PO-' . date('Y') . '-' . str_pad((string)$poId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE purchase_orders SET po_number = ? WHERE po_id = ?")
            ->execute([$poNumber, $poId]);

        $pdo->commit();

        auditLog('CREATE_PO', 'purchase_orders', $poId, null, [
            'po_number' => $poNumber, 'part_name' => $part['part_name'], 'quantity' => $qty,
        ]);

        jsonOk(['id' => $poId, 'po_number' => $poNumber], "Purchase order <strong>$poNumber</strong> created.");
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('parts_handler/create_po: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Mark purchase order as ordered ──────────────────────────────────────────
if ($action === 'mark_po_ordered') {

    $poId = requiredInt('po_id', 'Purchase order', 1);
    $po   = findOrFail($pdo, 'purchase_orders', 'po_id', $poId, 'Purchase order not found.');

    if ($po['status'] !== 'Pending') {
        jsonFail('Only a pending purchase order can be marked as ordered.');
    }

    $pdo->prepare("UPDATE purchase_orders SET status = 'Ordered', ordered_at = NOW() WHERE po_id = ?")
        ->execute([$poId]);

    auditLog('PO_ORDERED', 'purchase_orders', $poId, ['status' => 'Pending'], ['status' => 'Ordered']);

    jsonOk([], 'Purchase order marked as ordered.');
}

// ── Receive purchase order (creates the Stock In movement) ─────────────────
if ($action === 'receive_po') {

    $poId = requiredInt('po_id', 'Purchase order', 1);
    $po   = findOrFail($pdo, 'purchase_orders', 'po_id', $poId, 'Purchase order not found.');

    if (!in_array($po['status'], ['Pending', 'Ordered'], true)) {
        jsonFail('This purchase order has already been received or cancelled.');
    }

    $part = findOrFail($pdo, 'parts_inventory', 'part_id', $po['part_id'], 'Part not found.');
    $newQty = $part['quantity'] + $po['quantity'];

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO parts_movements
                (part_id, recorded_by, movement_type, quantity, unit_cost, reference_number, notes)
            VALUES (?, ?, 'Stock In', ?, ?, ?, ?)
        ")->execute([
            $po['part_id'], currentUserId(), $po['quantity'], $po['unit_cost'],
            $po['po_number'], 'Received against purchase order ' . $po['po_number'],
        ]);
        $movId = (int)$pdo->lastInsertId();

        if ($po['unit_cost'] !== null) {
            $pdo->prepare("UPDATE parts_inventory SET quantity = ?, unit_cost = ? WHERE part_id = ?")
                ->execute([$newQty, $po['unit_cost'], $po['part_id']]);
        } else {
            $pdo->prepare("UPDATE parts_inventory SET quantity = ? WHERE part_id = ?")
                ->execute([$newQty, $po['part_id']]);
        }

        $pdo->prepare("
            UPDATE purchase_orders
            SET status = 'Received', received_at = NOW(), received_by = ?, movement_id = ?
            WHERE po_id = ?
        ")->execute([currentUserId(), $movId, $poId]);

        $pdo->commit();

        auditLog('RECEIVE_PO', 'purchase_orders', $poId,
            ['status' => $po['status']],
            ['status' => 'Received', 'quantity' => $po['quantity'], 'new_stock' => $newQty]
        );

        jsonOk(
            ['new_stock' => $newQty],
            "Purchase order received. New stock: <strong>$newQty {$part['unit']}</strong>."
        );
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('parts_handler/receive_po: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Cancel purchase order ───────────────────────────────────────────────────
if ($action === 'cancel_po') {

    $poId = requiredInt('po_id', 'Purchase order', 1);
    $po   = findOrFail($pdo, 'purchase_orders', 'po_id', $poId, 'Purchase order not found.');

    if (!in_array($po['status'], ['Pending', 'Ordered'], true)) {
        jsonFail('Only a pending or ordered purchase order can be cancelled.');
    }

    $pdo->prepare("UPDATE purchase_orders SET status = 'Cancelled', cancelled_at = NOW() WHERE po_id = ?")
        ->execute([$poId]);

    auditLog('CANCEL_PO', 'purchase_orders', $poId, ['status' => $po['status']], ['status' => 'Cancelled']);

    jsonOk([], 'Purchase order cancelled.');
}

jsonFail('Unknown action.');
