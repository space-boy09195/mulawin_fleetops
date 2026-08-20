<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');
requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);

$action = $_POST['action'] ?? '';
if ($action !== 'create_expense') {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$tripId = (int)($_POST['trip_id'] ?? 0);
$type = trim($_POST['expense_type'] ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$quantity = ($_POST['quantity'] ?? '') !== '' ? (float)$_POST['quantity'] : null;
$date = trim($_POST['expense_date'] ?? '');
$notes = trim($_POST['notes'] ?? '') ?: null;
$allowedTypes = ['Fuel', 'Toll', 'Driver Allowance', 'Other'];

if (!$tripId || !in_array($type, $allowedTypes, true) || $amount <= 0 || !$date) {
    echo json_encode(['success' => false, 'message' => 'Trip, expense type, amount, and date are required.']);
    exit;
}
if ($type === 'Fuel' && ($quantity === null || $quantity <= 0)) {
    echo json_encode(['success' => false, 'message' => 'Fuel quantity in liters is required for fuel expenses.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid expense date.']);
    exit;
}

$pdo = getDBConnection();
$tripStmt = $pdo->prepare("SELECT trip_id FROM trips WHERE trip_id = ?");
$tripStmt->execute([$tripId]);
if (!$tripStmt->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'Trip not found.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO trip_expenses
            (trip_id, recorded_by, expense_type, amount, quantity, expense_date, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$tripId, currentUserId(), $type, $amount, $quantity, $date, $notes]);
    $id = (int)$pdo->lastInsertId();
    auditLog('CREATE_TRIP_EXPENSE', 'trip_expenses', $id, null, [
        'trip_id' => $tripId, 'expense_type' => $type, 'amount' => $amount,
    ]);
    echo json_encode(['success' => true, 'message' => 'Trip expense recorded.']);
} catch (PDOException $e) {
    error_log('trip_costs_handler/create: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
