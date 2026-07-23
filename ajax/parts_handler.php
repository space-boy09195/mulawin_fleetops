<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]);

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Add new part ──────────────────────────────────────────────────────────────
if ($action === 'add_part') {

    if (currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
        echo json_encode(['success' => false, 'message' => 'Only Head Management can add parts.']);
        exit;
    }

    $name         = trim($_POST['part_name']     ?? '');
    $partNumber   = trim($_POST['part_number']   ?? '') ?: null;
    $category     = trim($_POST['category']      ?? '');
    $unit         = trim($_POST['unit']          ?? 'pcs');
    $reorderLevel = (int)($_POST['reorder_level'] ?? 5);
    $unitCost     = $_POST['unit_cost'] !== '' ? (float)$_POST['unit_cost'] : null;
    $rawInitialQty = $_POST['initial_qty'] ?? '';
    $supplier     = trim($_POST['supplier']      ?? '') ?: null;

    if (!$name || !$category || !$unit) {
        echo json_encode(['success' => false, 'message' => 'Part name, category, and unit are required.']);
        exit;
    }

    if ($rawInitialQty === '') {
        echo json_encode(['success' => false, 'message' => 'Initial quantity is required.']);
        exit;
    }

    $initialQty = (int)$rawInitialQty;

    if ($reorderLevel < 0 || $initialQty < 0) {
        echo json_encode(['success' => false, 'message' => 'Quantity and reorder level cannot be negative.']);
        exit;
    }

    // Check for duplicate part number
    if ($partNumber) {
        $dupCheck = $pdo->prepare("SELECT part_id FROM parts_inventory WHERE part_number = ?");
        $dupCheck->execute([$partNumber]);
        if ($dupCheck->fetch()) {
            echo json_encode(['success' => false, 'message' => 'A part with that part number already exists.']);
            exit;
        }
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

        echo json_encode(['success' => true, 'message' => 'Part added successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('parts_handler/add_part: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Record movement ───────────────────────────────────────────────────────────
if ($action === 'record_movement') {

    $partId      = (int)($_POST['part_id']       ?? 0);
    $movType     = trim($_POST['movement_type']  ?? '');
    $qty         = (int)($_POST['quantity']      ?? 0);
    $unitCost    = $_POST['unit_cost'] !== '' ? (float)$_POST['unit_cost'] : null;
    $reference   = trim($_POST['reference_number'] ?? '') ?: null;
    $notes       = trim($_POST['notes']          ?? '') ?: null;

    $allowedTypes = ['Stock In', 'Stock Out', 'Adjustment'];

    if (!$partId || !$movType || $qty <= 0) {
        echo json_encode(['success' => false, 'message' => 'Part, movement type, and a positive quantity are required.']);
        exit;
    }

    if (!in_array($movType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Invalid movement type.']);
        exit;
    }

    // Fetch current stock
    $partStmt = $pdo->prepare("SELECT part_id, part_name, quantity, unit FROM parts_inventory WHERE part_id = ?");
    $partStmt->execute([$partId]);
    $part = $partStmt->fetch(PDO::FETCH_ASSOC);

    if (!$part) {
        echo json_encode(['success' => false, 'message' => 'Part not found.']);
        exit;
    }

    // For Stock Out, ensure enough stock
    if ($movType === 'Stock Out' && $part['quantity'] < $qty) {
        echo json_encode([
            'success' => false,
            'message' => "Insufficient stock. Current stock: {$part['quantity']} {$part['unit']}.",
        ]);
        exit;
    }

    // Signed quantity: negative for Stock Out
    $signedQty  = ($movType === 'Stock Out') ? -$qty : $qty;
    $newQty     = $part['quantity'] + $signedQty;

    if ($newQty < 0) {
        echo json_encode(['success' => false, 'message' => 'Movement would result in negative stock.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO parts_movements
                (part_id, recorded_by, movement_type, quantity, unit_cost, reference_number, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$partId, currentUserId(), $movType, $signedQty, $unitCost, $reference, $notes]);

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

        echo json_encode([
            'success'   => true,
            'message'   => "Movement recorded. New stock: <strong>$newQty {$part['unit']}</strong>.",
            'new_stock' => $newQty,
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('parts_handler/record_movement: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);