<?php
// ============================================================
// includes/purchasing.php
// Purchasing on the target schema:
//   purchase_requests (+ items)  what is requested; approved through the
//                                'purchase_request' workflow
//   purchase_orders (+ items)    the order to the supplier, created with the
//                                request as 'Draft' and issued on approval
//   purchase_receipts            each delivery received
//   inventory_transactions       stock-in per order line (reference_type
//                                'purchase_order_item', reference_id = line)
// Received quantity of a line = sum of its stock-in transactions.
// ============================================================

/**
 * Apply the final approval decision to a purchase request and its order.
 * Called inside the caller's transaction after recordApprovalDecision()
 * returned 'Approved' or 'Rejected'.
 */
function applyPurchaseRequestDecision(PDO $pdo, int $purchaseRequestId, string $decision, int $actorUserId): void {
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Invalid purchase decision.');
    }
    $update = $pdo->prepare(
        "UPDATE purchase_requests
         SET status = ?, approved_at = IF(? = 'Approved', NOW(), approved_at),
             approved_by = IF(? = 'Approved', ?, approved_by)
         WHERE purchase_request_id = ? AND status = 'Pending Approval'"
    );
    $update->execute([$decision, $decision, $decision, $actorUserId, $purchaseRequestId]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('The purchase request is no longer pending.');
    }
    $pdo->prepare(
        "UPDATE purchase_orders SET status = ?
         WHERE purchase_request_id = ? AND status = 'Draft'"
    )->execute([$decision === 'Approved' ? 'Issued' : 'Cancelled', $purchaseRequestId]);
    if ($decision === 'Approved') {
        $pdo->prepare("UPDATE purchase_requests SET status = 'Ordered' WHERE purchase_request_id = ?")
            ->execute([$purchaseRequestId]);
    }
}

/**
 * Lines of an order with ordered and received quantities, locked.
 */
function purchaseOrderLines(PDO $pdo, int $purchaseOrderId): array {
    $stmt = $pdo->prepare(
        "SELECT poi.purchase_order_item_id, poi.part_id, poi.quantity, poi.unit_cost,
                COALESCE((
                    SELECT SUM(it.quantity) FROM inventory_transactions it
                    WHERE it.reference_type = 'purchase_order_item'
                      AND it.reference_id = poi.purchase_order_item_id
                      AND it.transaction_type = 'Stock In'
                ), 0) AS received_quantity
         FROM purchase_order_items poi
         WHERE poi.purchase_order_id = ?
         FOR UPDATE"
    );
    $stmt->execute([$purchaseOrderId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $lines[(int)$line['purchase_order_item_id']] = $line;
    }
    return $lines;
}
/**
 * supplier_id for a supplier name typed on the form: the existing supplier
 * with that name, otherwise a new active supplier. Null for an empty name.
 */
function supplierIdForName(PDO $pdo, ?string $name, string $source = 'parts_form'): ?int {
    if ($name === null || trim($name) === '') {
        return null;
    }
    $name = mb_substr(trim($name), 0, 180, 'UTF-8');
    $find = $pdo->prepare('SELECT supplier_id FROM suppliers WHERE supplier_name = ? ORDER BY supplier_id LIMIT 1');
    $find->execute([$name]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    $pdo->prepare("INSERT INTO suppliers (supplier_name, status) VALUES (?, 'Active')")->execute([$name]);
    $newId = (int)$pdo->lastInsertId();
    auditLog('ADD_SUPPLIER', 'suppliers', $newId, null, ['supplier_name' => $name, 'source' => $source]);
    return $newId;
}

