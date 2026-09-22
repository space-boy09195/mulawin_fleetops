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

jsonFail('Unknown action.');
