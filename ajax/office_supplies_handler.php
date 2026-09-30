<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';

header('Content-Type: application/json');
requireAnyPermission(['admin.supplies.view', 'admin.supplies.manage']);
requirePostMethod();
enforceCsrf();
$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'create_item') {
    requirePermission('admin.supplies.manage');
    $name = requiredString('item_name', 'Item name', 150);
    $unit = requiredString('unit', 'Unit', 50);
    $reorder = optionalFloat('reorder_level', 0.0) ?? 0.0;
    if ($reorder < 0) jsonFail('Reorder level cannot be negative.');
    try {
        $pdo->prepare(
            'INSERT INTO office_supply_items (item_name, unit, reorder_level, created_by) VALUES (?, ?, ?, ?)'
        )->execute([$name, $unit, $reorder, currentUserId()]);
        $id = (int)$pdo->lastInsertId();
        auditLog('CREATE', 'office_supply_items', $id, null, ['item_name' => $name]);
        jsonOk(['item_id' => $id], 'Office supply item created.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') jsonFail('An item with that name already exists.', 409);
        error_log('office_supplies_handler/create_item: ' . $e->getMessage());
        jsonFail('Could not create supply item.', 500);
    }
}

if ($action === 'record_movement') {
    requirePermission('admin.supplies.manage');
    $itemId = requiredInt('item_id', 'Item', 1);
    $type = requiredEnum('movement_type', ['Stock In','Issue','Adjustment'], 'Movement type');
    $quantity = (float)($_POST['quantity'] ?? 0);
    $reference = optionalString('reference_number', null, 100);
    $notes = optionalString('notes', null, 500);
    if ($quantity <= 0 || round($quantity, 2) !== $quantity) jsonFail('Enter a positive quantity with at most two decimal places.');
    $direction = $type === 'Adjustment'
        ? requiredEnum('adjustment_direction', ['Increase', 'Decrease'], 'Adjustment direction')
        : null;
    $change = $type === 'Stock In' || $direction === 'Increase' ? $quantity : -$quantity;
    try {
        $pdo->beginTransaction();
        $item = $pdo->prepare('SELECT quantity FROM office_supply_items WHERE item_id = ? AND is_active = 1 FOR UPDATE');
        $item->execute([$itemId]);
        $current = $item->fetchColumn();
        if ($current === false) throw new DomainException('Supply item not found or inactive.');
        $newQuantity = round((float)$current + $change, 2);
        if ($newQuantity < 0) throw new DomainException('Issue/adjustment would make stock negative.');
        $pdo->prepare('UPDATE office_supply_items SET quantity = ? WHERE item_id = ?')->execute([$newQuantity, $itemId]);
        $pdo->prepare(
            'INSERT INTO office_supply_movements
             (item_id, movement_type, quantity, quantity_change, reference_number, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$itemId, $type, $quantity, $change, $reference, $notes, currentUserId()]);
        $movementId = (int)$pdo->lastInsertId();
        $pdo->commit();
        auditLog('OFFICE_SUPPLY_MOVEMENT', 'office_supply_movements', $movementId, null, [
            'item_id' => $itemId, 'type' => $type, 'quantity_change' => $change,
        ]);
        jsonOk(['quantity' => $newQuantity], 'Office supply stock updated.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) jsonFail($e->getMessage(), 409);
        error_log('office_supplies_handler/record_movement: ' . $e->getMessage());
        jsonFail('Could not update office supply stock.', 500);
    }
}

jsonFail('Unknown action.');
