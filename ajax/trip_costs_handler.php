<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');
requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);
requirePostMethod();
enforceCsrf();

$action = $_POST['action'] ?? '';

if (!in_array($action, ['create_expense', 'update_expense'], true)) {
    jsonFail('Unknown action.');
}
if ($action === 'update_expense' && currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
    jsonFail('Only Head Management can edit expenses.', 403);
}

$tripId            = requiredInt('trip_id', 'Trip', 1);
$type              = optionalString('expense_type', '');
$amount            = (float)($_POST['amount'] ?? 0);
$quantity          = ($_POST['quantity'] ?? '') !== '' ? (float)$_POST['quantity'] : null;
$date              = requiredDate('expense_date', 'Expense date', $action === 'create_expense');
$otherDescription  = optionalString('other_description');
$notes             = optionalString('notes');
$expenseId         = (int)($_POST['expense_id'] ?? 0);

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
    if (!in_array($type, TRIP_EXPENSE_TYPES, true) || $amount <= 0 || ($type === 'Other' && !$otherDescription)) {
        jsonFail('Trip, expense type, amount, and date are required.');
    }
    if ($type === 'Fuel' && ($quantity === null || $quantity <= 0)) {
        jsonFail('Fuel quantity in liters is required for fuel expenses.');
    }
    if (!$expenseId) {
        jsonFail('Expense not found.');
    }
} else {
    if ($expenseEntries === []) {
        jsonFail('Trip, date, and at least one expense item are required.');
    }
    foreach ($expenseEntries as $entry) {
        $entryType = $entry['expense_type'];
        $entryAmount = (float)$entry['amount'];
        $entryQuantity = $entry['quantity'];
        $entryOther = $entry['other_description'];
        if (!in_array($entryType, TRIP_EXPENSE_TYPES, true) || $entryAmount <= 0 || ($entryType === 'Other' && !$entryOther)) {
            jsonFail('Each expense entry needs a valid type and amount.');
        }
        if ($entryType === 'Fuel' && ($entryQuantity === null || $entryQuantity <= 0)) {
            jsonFail('Fuel quantity in liters is required for fuel expenses.');
        }
    }
}

$pdo = getDBConnection();
findOrFail($pdo, 'trips', 'trip_id', $tripId, 'Trip not found.');

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
        jsonOk([], $message);
    } else {
        findOrFail($pdo, 'trip_expenses', 'expense_id', $expenseId, 'Expense not found.');
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
        jsonOk([], 'Trip expense updated.');
    }
} catch (PDOException $e) {
    error_log('trip_costs_handler/create: ' . $e->getMessage());
    jsonFail('A database error occurred. Please try again.', 500);
}
