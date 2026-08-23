<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');
requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);

$action = $_POST['action'] ?? '';

$tripId = (int)($_POST['trip_id'] ?? 0);
$type = trim($_POST['expense_type'] ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$quantity = ($_POST['quantity'] ?? '') !== '' ? (float)$_POST['quantity'] : null;
$date = trim($_POST['expense_date'] ?? '');
$otherDescription = trim($_POST['other_description'] ?? '') ?: null;
$notes = trim($_POST['notes'] ?? '') ?: null;
$allowedTypes = ['Fuel', 'Toll', 'Driver Allowance', 'Other'];

if (!in_array($action, ['create_expense', 'update_expense'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}
if ($action === 'update_expense' && currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Head Management can edit expenses.']);
    exit;
}
$expenseId = (int)($_POST['expense_id'] ?? 0);

$rawExpenseEntries = $_POST['expenses'] ?? null;
$expenseEntries = [];
if (is_array($rawExpenseEntries) && $rawExpenseEntries !== []) {
    foreach ($rawExpenseEntries as $entry) {
        if (!is_array($entry)) continue;
        $expenseEntries[] = [
            'expense_type' => trim((string)($entry['expense_type'] ?? '')),
            'amount' => (float)($entry['amount'] ?? 0),
            'quantity' => (isset($entry['quantity']) && (string)$entry['quantity'] !== '') ? (float)$entry['quantity'] : null,
            'other_description' => trim((string)($entry['other_description'] ?? '')) ?: null,
            'notes' => trim((string)($entry['notes'] ?? '')) ?: null,
        ];
    }
} elseif ($action === 'create_expense') {
    $expenseEntries[] = [
        'expense_type' => $type,
        'amount' => $amount,
        'quantity' => $quantity,
        'other_description' => $otherDescription,
        'notes' => $notes,
    ];
}

if ($action === 'update_expense') {
    if (!$tripId || !in_array($type, $allowedTypes, true) || $amount <= 0 || !$date || ($type === 'Other' && !$otherDescription)) {
        echo json_encode(['success' => false, 'message' => 'Trip, expense type, amount, and date are required.']);
        exit;
    }
    if ($type === 'Fuel' && ($quantity === null || $quantity <= 0)) {
        echo json_encode(['success' => false, 'message' => 'Fuel quantity in liters is required for fuel expenses.']);
        exit;
    }
    if (!isValidDate($date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid expense date.']);
        exit;
    }
    if (!$expenseId) {
        echo json_encode(['success' => false, 'message' => 'Expense not found.']);
        exit;
    }
} else {
    if (!$tripId || !$date || $expenseEntries === []) {
        echo json_encode(['success' => false, 'message' => 'Trip, date, and at least one expense item are required.']);
        exit;
    }
    if (!isValidDate($date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid expense date.']);
        exit;
    }
    if (isPassedDate($date)) {
        echo json_encode(['success' => false, 'message' => 'New expenses cannot use a passed date.']);
        exit;
    }
    foreach ($expenseEntries as $entry) {
        $entryType = $entry['expense_type'];
        $entryAmount = (float)$entry['amount'];
        $entryQuantity = $entry['quantity'];
        $entryOther = $entry['other_description'];
        if (!in_array($entryType, $allowedTypes, true) || $entryAmount <= 0 || ($entryType === 'Other' && !$entryOther)) {
            echo json_encode(['success' => false, 'message' => 'Each expense entry needs a valid type and amount.']);
            exit;
        }
        if ($entryType === 'Fuel' && ($entryQuantity === null || $entryQuantity <= 0)) {
            echo json_encode(['success' => false, 'message' => 'Fuel quantity in liters is required for fuel expenses.']);
            exit;
        }
    }
}

$pdo = getDBConnection();
$tripStmt = $pdo->prepare("SELECT trip_id FROM trips WHERE trip_id = ?");
$tripStmt->execute([$tripId]);
if (!$tripStmt->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'Trip not found.']);
    exit;
}

try {
    if ($action === 'create_expense') {
        $insertStmt = $pdo->prepare("
            INSERT INTO trip_expenses
                (trip_id, recorded_by, expense_type, amount, quantity, other_description, expense_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $createdIds = [];
        foreach ($expenseEntries as $entry) {
            $entryType = $entry['expense_type'];
            $entryAmount = (float)$entry['amount'];
            $entryQuantity = $entry['quantity'];
            $entryOther = $entry['other_description'];
            $entryNotes = $entry['notes'];
            $insertStmt->execute([$tripId, currentUserId(), $entryType, $entryAmount, $entryQuantity, $entryOther, $date, $entryNotes]);
            $createdIds[] = (int)$pdo->lastInsertId();
            auditLog('CREATE_TRIP_EXPENSE', 'trip_expenses', (int)$pdo->lastInsertId(), null, [
                'trip_id' => $tripId,
                'expense_type' => $entryType,
                'amount' => $entryAmount,
            ]);
        }
        $message = count($createdIds) === 1 ? 'Trip expense recorded.' : count($createdIds) . ' trip expenses recorded.';
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        $exists = $pdo->prepare("SELECT expense_id FROM trip_expenses WHERE expense_id = ?");
        $exists->execute([$expenseId]);
        if (!$exists->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Expense not found.']);
            exit;
        }
        $stmt = $pdo->prepare("
            UPDATE trip_expenses
            SET trip_id = ?, expense_type = ?, amount = ?, quantity = ?, other_description = ?, expense_date = ?, notes = ?
            WHERE expense_id = ?
        ");
        $stmt->execute([$tripId, $type, $amount, $quantity, $otherDescription, $date, $notes, $expenseId]);
        auditLog('UPDATE_TRIP_EXPENSE', 'trip_expenses', $expenseId, null, [
            'trip_id' => $tripId,
            'expense_type' => $type,
            'amount' => $amount,
        ]);
        echo json_encode(['success' => true, 'message' => 'Trip expense updated.']);
    }
} catch (PDOException $e) {
    error_log('trip_costs_handler/create: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
