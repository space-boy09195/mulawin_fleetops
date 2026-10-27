<?php
// ============================================================
// ajax/office_supplies_handler.php
// Office supplies on the target schema: office_supplies (quantity_on_hand,
// reorder_level, status) and office_supply_transactions (quantity > 0).
// Form 'Issue' = target 'Stock Out'. An adjustment's direction and the
// reference number have no column; they are the first part of remarks
// ("[Increase]" / "[Decrease]", "Ref: ...").
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';

header('Content-Type: application/json');
requireAnyPermission(['admin.supplies.view', 'admin.supplies.manage']);
requirePostMethod();
enforceCsrf();
$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'create_item') {
    requirePermission('admin.supplies.manage');
    $name = requiredString('item_name', 'Item name', 150);
    $unit = requiredString('unit', 'Unit', 30);
    $reorder = optionalFloat('reorder_level', 0.0) ?? 0.0;
    if ($reorder < 0) jsonFail('Reorder level cannot be negative.');
    // The target has no unique item name; duplicates are refused here.
    if (existsWhere($pdo, 'office_supplies', 'item_name', $name)) {
        jsonFail('An item with that name already exists.', 409);
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO office_supplies (item_name, unit, reorder_level, quantity_on_hand, status)
             VALUES (?, ?, ?, 0, 'Active')"
        )->execute([$name, $unit, $reorder]);
        $id = (int)$pdo->lastInsertId();
        auditLog('CREATE', 'office_supplies', $id, null, ['item_name' => $name]);
        $pdo->commit();
        jsonOk(['item_id' => $id], 'Office supply item created.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('office_supplies_handler/create_item: ' . $e->getMessage());
        jsonFail('Could not create supply item.', 500);
    }
}

if ($action === 'record_movement') {
    requirePermission('admin.supplies.manage');
    $itemId = requiredInt('item_id', 'Item', 1);
    $type = requiredEnum('movement_type', ['Stock In', 'Issue', 'Adjustment'], 'Movement type');
    $quantity = (float)($_POST['quantity'] ?? 0);
    $reference = optionalString('reference_number', null, 100);
    $notes = optionalString('notes', null, 500);
    if ($quantity <= 0 || round($quantity, 2) !== $quantity) jsonFail('Enter a positive quantity with at most two decimal places.');
    $direction = $type === 'Adjustment'
        ? requiredEnum('adjustment_direction', ['Increase', 'Decrease'], 'Adjustment direction')
        : null;
    $change = $type === 'Stock In' || $direction === 'Increase' ? $quantity : -$quantity;
    $transactionType = match ($type) {
        'Stock In'   => 'Stock In',
        'Issue'      => 'Stock Out',
        'Adjustment' => 'Adjustment',
    };
    $remarks = trim(
        ($direction !== null ? '[' . $direction . '] ' : '')
        . ($reference !== null ? 'Ref: ' . $reference . '. ' : '')
        . ($notes ?? '')
    );
    try {
        $pdo->beginTransaction();
        $item = $pdo->prepare("SELECT quantity_on_hand FROM office_supplies WHERE supply_id = ? AND status = 'Active' FOR UPDATE");
        $item->execute([$itemId]);
        $current = $item->fetchColumn();
        if ($current === false) throw new DomainException('Supply item not found or inactive.');
        $newQuantity = round((float)$current + $change, 2);
        if ($newQuantity < 0) throw new DomainException('Issue/adjustment would make stock negative.');
        $pdo->prepare('UPDATE office_supplies SET quantity_on_hand = ? WHERE supply_id = ?')->execute([$newQuantity, $itemId]);
        $pdo->prepare(
            'INSERT INTO office_supply_transactions (supply_id, transaction_type, quantity, performed_by, remarks)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$itemId, $transactionType, $quantity, currentUserId(), $remarks === '' ? null : $remarks]);
        $movementId = (int)$pdo->lastInsertId();
        auditLog('OFFICE_SUPPLY_MOVEMENT', 'office_supply_transactions', $movementId, null, [
            'supply_id' => $itemId, 'type' => $transactionType, 'quantity_change' => $change,
        ]);
        $pdo->commit();
        jsonOk(['quantity' => $newQuantity], 'Office supply stock updated.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) jsonFail($e->getMessage(), 409);
        error_log('office_supplies_handler/record_movement: ' . $e->getMessage());
        jsonFail('Could not update office supply stock.', 500);
    }
}

jsonFail('Unknown action.');
