<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requirePermission('parts.manage');
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
    $warrantyExpiry = optionalString('warranty_expiry');
    if ($warrantyExpiry !== null && !isValidDate($warrantyExpiry)) {
        jsonFail('Warranty expiry must be a valid date.');
    }

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
                (part_number, part_name, category, unit, quantity, reorder_level, unit_cost, warranty_expiry, supplier)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$partNumber, $name, $category, $unit, $initialQty, $reorderLevel, $unitCost, $warrantyExpiry, $supplier]);
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

    try {
        $pdo->beginTransaction();
        $partQuery = $pdo->prepare('SELECT * FROM parts_inventory WHERE part_id = ? FOR UPDATE');
        $partQuery->execute([$partId]);
        $part = $partQuery->fetch(PDO::FETCH_ASSOC);
        if (!$part) {
            $pdo->rollBack();
            jsonFail('Part not found.', 404);
        }

        if ($movType === 'Adjustment') {
            $rawCount = $_POST['quantity'] ?? '';
            if (!is_string($rawCount) || !preg_match('/^\d{1,10}$/', $rawCount)) {
                $pdo->rollBack();
                jsonFail('Enter the physical stock count as a whole number from 0 to 4,294,967,295.');
            }
            $actualCount = (int)$rawCount;
            if ($actualCount > 4294967295) {
                $pdo->rollBack();
                jsonFail('Physical stock count cannot exceed 4,294,967,295.');
            }
            $signedQty = $actualCount - (int)$part['quantity'];
            if ($signedQty === 0) {
                $pdo->rollBack();
                jsonFail('The counted stock matches the recorded quantity; no adjustment is needed.', 409);
            }
            $notes = 'Physical count ' . $actualCount . '. ' . ($notes ?? '');
            $newQty = $actualCount;
        } else {
            $qty = requiredInt('quantity', 'Quantity', 1);
            if ($movType === 'Stock Out' && (int)$part['quantity'] < $qty) {
                $pdo->rollBack();
                jsonFail("Insufficient stock. Current stock: {$part['quantity']} {$part['unit']}.");
            }
            $signedQty = $movType === 'Stock Out' ? -$qty : $qty;
            $newQty = (int)$part['quantity'] + $signedQty;
        }

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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof InvalidArgumentException) {
            jsonFail($e->getMessage());
        }
        error_log('parts_handler/record_movement: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
