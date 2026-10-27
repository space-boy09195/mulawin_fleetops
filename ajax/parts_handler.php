<?php
// ============================================================
// ajax/parts_handler.php
// Parts inventory on the target schema:
//   parts                   catalog (part_number NOT NULL UNIQUE, unit_cost,
//                           reorder_level, warranty_period_days, supplier_id)
//   inventory               one row per part: quantity_on_hand (>= 0)
//   inventory_transactions  every movement; quantity is always positive
//                           (CHECK > 0). Direction: Stock In / Return add,
//                           Stock Out subtracts, Adjustment adds or
//                           subtracts as recorded in reference_type
//                           ('count_increase' / 'count_decrease').
//   maintenance_parts       parts issued to a maintenance job; the job's
//                           parts_cost / total_cost are updated with them.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';
require_once __DIR__ . '/../app/modules/inventory/purchasing.php';

header('Content-Type: application/json');

// parts.manage (Purchasing Officer; Admin) covers every action. Maintenance
// staff (maintenance.manage without parts.manage) may only issue parts to a
// maintenance job or return them from one (record_movement, Stock Out /
// Return with a linked job, at the catalog unit cost); see below.
requireAnyPermission(['parts.manage', 'maintenance.manage']);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';
$partsIssueOnly = !currentUserHasAnyPermission(['parts.manage']);
if ($partsIssueOnly && $action !== 'record_movement') {
    jsonFail('You do not have permission to perform this action.', 403);
}

// ── Add new part ──────────────────────────────────────────────────────────────
if ($action === 'add_part') {

    // Catalog changes need parts.catalog.manage (Admin holds every
    // permission; the reviewed seed grants it to the Purchasing Officer).
    if (!currentUserHasAnyPermission(['parts.catalog.manage'])) {
        jsonFail('You are not allowed to add catalog parts.', 403);
    }

    $name          = requiredString('part_name', 'Part name', 150);
    $partNumber    = optionalString('part_number', null, 80);
    $category      = requiredString('category', 'Category', 80);
    $unit          = optionalString('unit', null, 30) ?? 'pcs';
    $reorderLevel  = filter_input(INPUT_POST, 'reorder_level', FILTER_VALIDATE_INT);
    $reorderLevel  = ($reorderLevel === false || $reorderLevel === null) ? 5 : $reorderLevel;
    $unitCost      = optionalFloat('unit_cost');
    $rawInitialQty = $_POST['initial_qty'] ?? '';
    $supplier      = optionalString('supplier', null, 180);
    $warrantyDaysRaw = trim((string)($_POST['warranty_days'] ?? ''));
    $warrantyDays = null;
    if ($warrantyDaysRaw !== '') {
        if (!preg_match('/^\d{1,5}$/', $warrantyDaysRaw) || (int)$warrantyDaysRaw < 1) {
            jsonFail('Warranty period must be a whole number of days from 1 to 99,999.');
        }
        $warrantyDays = (int)$warrantyDaysRaw;
    }

    if ($rawInitialQty === '' || !preg_match('/^\d{1,9}$/', (string)$rawInitialQty)) {
        jsonFail('Initial quantity is required and must be a whole number.');
    }
    $initialQty = (int)$rawInitialQty;

    if ($reorderLevel < 0) {
        jsonFail('Reorder level cannot be negative.');
    }
    if ($unitCost !== null && $unitCost < 0) {
        jsonFail('Unit cost cannot be negative.');
    }

    // Check for duplicate part number
    if ($partNumber && existsWhere($pdo, 'parts', 'part_number', $partNumber)) {
        jsonFail('A part with that part number already exists.');
    }

    try {
        $pdo->beginTransaction();
        $supplierId = supplierIdForName($pdo, $supplier);

        // part_number is required in the target: a blank one gets a unique
        // placeholder first and then PART-<id>.
        $stmt = $pdo->prepare("
            INSERT INTO parts
                (part_number, part_name, category, unit, reorder_level, unit_cost,
                 warranty_period_days, supplier_id, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $partNumber ?? ('TMP-' . bin2hex(random_bytes(12))),
            $name, $category, $unit, $reorderLevel, $unitCost ?? 0,
            $warrantyDays, $supplierId,
        ]);
        $newId = (int)$pdo->lastInsertId();
        if ($partNumber === null) {
            $partNumber = sprintf('PART-%06d', $newId);
            $pdo->prepare('UPDATE parts SET part_number = ? WHERE part_id = ?')->execute([$partNumber, $newId]);
        }
        $pdo->prepare('INSERT INTO inventory (part_id, quantity_on_hand) VALUES (?, ?)')
            ->execute([$newId, $initialQty]);

        // Record initial stock-in movement if qty > 0
        if ($initialQty > 0) {
            $pdo->prepare("
                INSERT INTO inventory_transactions
                    (part_id, transaction_type, quantity, unit_cost, performed_by, remarks)
                VALUES (?, 'Stock In', ?, ?, ?, 'Initial stock entry')
            ")->execute([$newId, $initialQty, $unitCost, currentUserId()]);
        }

        auditLog('ADD_PART', 'parts', $newId, null, [
            'part_name'    => $name,
            'part_number'  => $partNumber,
            'category'     => $category,
            'initial_qty'  => $initialQty,
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'Part added successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('parts_handler/add_part: ' . $e->getMessage());
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
            jsonFail('A part with that part number already exists.', 409);
        }
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Edit / deactivate a catalog part ──────────────────────────────────────────
// Name, category, unit, reorder level, warranty period, supplier and the
// active flag. The part number, stock and history are not changed; an
// inactive part keeps every past movement, issue and purchase line and is
// only left out of new movements and purchase orders.
if ($action === 'edit_part') {
    if (!currentUserHasAnyPermission(['parts.catalog.manage'])) {
        jsonFail('You are not allowed to change catalog parts.', 403);
    }
    $partId       = requiredInt('part_id', 'Part', 1);
    $name         = requiredString('part_name', 'Part name', 150);
    $category     = requiredString('category', 'Category', 80);
    $unit         = optionalString('unit', null, 30) ?? 'pcs';
    $reorderLevel = requiredInt('reorder_level', 'Reorder level', 0, 999999999);
    $supplier     = optionalString('supplier', null, 180);
    $isActive     = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;
    $warrantyDaysRaw = trim((string)($_POST['warranty_days'] ?? ''));
    $warrantyDays = null;
    if ($warrantyDaysRaw !== '') {
        if (!preg_match('/^\d{1,5}$/', $warrantyDaysRaw) || (int)$warrantyDaysRaw < 1) {
            jsonFail('Warranty period must be a whole number of days from 1 to 99,999.');
        }
        $warrantyDays = (int)$warrantyDaysRaw;
    }

    try {
        $pdo->beginTransaction();
        $old = $pdo->prepare(
            'SELECT part_name, category, unit, reorder_level, warranty_period_days, supplier_id, is_active
             FROM parts WHERE part_id = ? FOR UPDATE'
        );
        $old->execute([$partId]);
        $oldData = $old->fetch(PDO::FETCH_ASSOC);
        if (!$oldData) {
            $pdo->rollBack();
            jsonFail('Part not found.', 404);
        }
        $supplierId = supplierIdForName($pdo, $supplier);
        $pdo->prepare(
            'UPDATE parts SET part_name = ?, category = ?, unit = ?, reorder_level = ?,
                    warranty_period_days = ?, supplier_id = ?, is_active = ?
             WHERE part_id = ?'
        )->execute([$name, $category, $unit, $reorderLevel, $warrantyDays, $supplierId, $isActive, $partId]);
        auditLog('EDIT_PART', 'parts', $partId, $oldData, [
            'part_name' => $name, 'category' => $category, 'unit' => $unit,
            'reorder_level' => $reorderLevel, 'warranty_period_days' => $warrantyDays,
            'supplier_id' => $supplierId, 'is_active' => $isActive,
        ]);
        $pdo->commit();
        jsonOk([], $isActive ? 'Part updated.' : 'Part updated and deactivated.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('parts_handler/edit_part: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Record movement ───────────────────────────────────────────────────────────
if ($action === 'record_movement') {

    $partId        = requiredInt('part_id', 'Part', 1);
    $movType       = requiredEnum('movement_type', PARTS_MOVEMENT_TYPES, 'Movement type');
    $unitCost      = optionalFloat('unit_cost');
    $maintenanceId = filter_input(INPUT_POST, 'maintenance_id', FILTER_VALIDATE_INT) ?: null;
    $reference     = optionalString('reference_number', null, 100);
    $notes         = optionalString('notes', null, 2000);
    if ($unitCost !== null && $unitCost < 0) {
        jsonFail('Unit cost cannot be negative.');
    }
    if ($maintenanceId && !in_array($movType, ['Stock Out', 'Return'], true)) {
        jsonFail('Only a stock-out or a return can be linked to a maintenance job.');
    }
    if ($partsIssueOnly) {
        if (!$maintenanceId || !in_array($movType, ['Stock Out', 'Return'], true)) {
            jsonFail('Maintenance staff can only issue parts to, or return parts from, a linked maintenance job.', 403);
        }
        $unitCost = null; // the issue is valued at the catalog unit cost
    }

    try {
        $pdo->beginTransaction();
        $partQuery = $pdo->prepare(
            'SELECT p.part_id, p.part_name, p.unit, p.unit_cost, COALESCE(i.quantity_on_hand, 0) AS quantity,
                    i.inventory_id
             FROM parts p
             LEFT JOIN inventory i ON i.part_id = p.part_id
             WHERE p.part_id = ? AND p.is_active = 1
             FOR UPDATE'
        );
        $partQuery->execute([$partId]);
        $part = $partQuery->fetch(PDO::FETCH_ASSOC);
        if (!$part) {
            $pdo->rollBack();
            jsonFail('Part not found.', 404);
        }
        if ($part['inventory_id'] === null) {
            $pdo->prepare('INSERT INTO inventory (part_id, quantity_on_hand) VALUES (?, 0)')->execute([$partId]);
        }
        $currentQty = (float)$part['quantity'];

        if ($maintenanceId) {
            $jobCheck = $pdo->prepare(
                "SELECT maintenance_id FROM maintenance_records
                 WHERE maintenance_id = ? AND status <> 'Cancelled' FOR UPDATE"
            );
            $jobCheck->execute([$maintenanceId]);
            if (!$jobCheck->fetch()) {
                $pdo->rollBack();
                jsonFail('Linked job not found.');
            }
        }

        $referenceType = $maintenanceId ? 'maintenance' : null;
        if ($movType === 'Adjustment') {
            $rawCount = $_POST['quantity'] ?? '';
            if (!is_string($rawCount) || !preg_match('/^\d{1,9}$/', $rawCount)) {
                $pdo->rollBack();
                jsonFail('Enter the physical stock count as a whole number from 0 to 999,999,999.');
            }
            $actualCount = (int)$rawCount;
            $delta = $actualCount - $currentQty;
            if ($delta == 0) {
                $pdo->rollBack();
                jsonFail('The counted stock matches the recorded quantity; no adjustment is needed.', 409);
            }
            $quantity = abs($delta);
            $referenceType = $delta > 0 ? 'count_increase' : 'count_decrease';
            $notes = 'Physical count ' . $actualCount . ' (' . ($delta > 0 ? '+' : '-') . $quantity . '). ' . ($notes ?? '');
            $newQty = (float)$actualCount;
            $signedQty = $delta;
        } else {
            $quantity = requiredInt('quantity', 'Quantity', 1);
            if ($movType === 'Stock Out' && $currentQty < $quantity) {
                $pdo->rollBack();
                jsonFail("Insufficient stock. Current stock: {$part['quantity']} {$part['unit']}.");
            }
            $signedQty = $movType === 'Stock Out' ? -$quantity : $quantity;
            $newQty = $currentQty + $signedQty;
        }

        // A return from a job cannot exceed what was issued to that job and
        // takes its value off the job's parts cost (average issue cost).
        $returnValue = 0.0;
        if ($maintenanceId && $movType === 'Return') {
            $issued = $pdo->prepare(
                'SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(total_cost), 0) AS cost
                 FROM maintenance_parts WHERE maintenance_id = ? AND part_id = ?'
            );
            $issued->execute([$maintenanceId, $partId]);
            $issuedRow = $issued->fetch(PDO::FETCH_ASSOC);
            $returned = $pdo->prepare(
                "SELECT COALESCE(SUM(quantity), 0) FROM inventory_transactions
                 WHERE transaction_type = 'Return' AND reference_type = 'maintenance'
                   AND reference_id = ? AND part_id = ?"
            );
            $returned->execute([$maintenanceId, $partId]);
            $returnable = (float)$issuedRow['qty'] - (float)$returned->fetchColumn();
            if ($quantity > $returnable) {
                $pdo->rollBack();
                jsonFail('The return exceeds the quantity issued to this job (returnable: ' . rtrim(rtrim(number_format(max(0, $returnable), 2, '.', ''), '0'), '.') . ').', 409);
            }
            $returnValue = round((float)$issuedRow['cost'] / (float)$issuedRow['qty'] * $quantity, 2);
        }
        // The target has no reference-number column; it is kept in remarks.
        $remarks = trim(($reference !== null ? 'Ref: ' . $reference . '. ' : '') . ($notes ?? ''));

        $pdo->prepare("
            INSERT INTO inventory_transactions
                (part_id, transaction_type, quantity, unit_cost, reference_type, reference_id,
                 performed_by, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $partId, $movType, $quantity, $unitCost, $referenceType, $maintenanceId,
            currentUserId(), $remarks === '' ? null : $remarks,
        ]);
        $movId = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE inventory SET quantity_on_hand = ? WHERE part_id = ?')
            ->execute([$newQty, $partId]);
        if ($movType === 'Stock In' && $unitCost !== null) {
            $pdo->prepare('UPDATE parts SET unit_cost = ? WHERE part_id = ?')->execute([$unitCost, $partId]);
        }

        // Parts used on a job are part of its cost (maintenance_parts).
        if ($maintenanceId && $movType === 'Stock Out') {
            $issueCost = $unitCost ?? (float)$part['unit_cost'];
            $lineTotal = round($issueCost * $quantity, 2);
            $pdo->prepare(
                'INSERT INTO maintenance_parts (maintenance_id, part_id, quantity, unit_cost, total_cost, issued_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$maintenanceId, $partId, $quantity, $issueCost, $lineTotal, currentUserId()]);
            $pdo->prepare(
                'UPDATE maintenance_records
                 SET parts_cost = parts_cost + ?, total_cost = labor_cost + parts_cost + other_cost
                 WHERE maintenance_id = ?'
            )->execute([$lineTotal, $maintenanceId]);
        }
        if ($maintenanceId && $movType === 'Return' && $returnValue > 0) {
            $pdo->prepare(
                'UPDATE maintenance_records
                 SET parts_cost = GREATEST(parts_cost - ?, 0), total_cost = labor_cost + parts_cost + other_cost
                 WHERE maintenance_id = ?'
            )->execute([$returnValue, $maintenanceId]);
        }

        auditLog('PARTS_MOVEMENT', 'inventory_transactions', $movId,
            ['quantity' => $currentQty],
            ['quantity' => $newQty, 'movement_type' => $movType, 'change' => $signedQty, 'maintenance_id' => $maintenanceId]
        );
        $pdo->commit();

        $display = rtrim(rtrim(number_format($newQty, 2, '.', ''), '0'), '.');
        jsonOk(
            ['new_stock' => $newQty],
            "Movement recorded. New stock: <strong>" . htmlspecialchars($display . ' ' . $part['unit']) . "</strong>."
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
