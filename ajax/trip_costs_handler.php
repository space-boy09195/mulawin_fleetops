<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/finance_records.php';

header('Content-Type: application/json');
requirePermission('finance.manage');
requirePostMethod();
enforceCsrf();

$action = $_POST['action'] ?? '';

// Driver Allowance is recorded in expense_records as a reimbursement expense,
// separate from Trip Pay wages; both may exist for the same trip and crew member.
if (!in_array($action, ['create_expense', 'update_expense'], true)) {
    jsonFail('Unknown action.');
}
// finance.expenses.edit: Admin and, per the reviewed seed, Finance.
if ($action === 'update_expense' && !currentUserHasAnyPermission(['finance.expenses.edit'])) {
    jsonFail('You are not allowed to edit recorded expenses.', 403);
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

// Target: expense_records with reference_type 'trip' (T-F1 adds the Toll and
// Driver Allowance types and the fuel quantity). The "other" description and
// notes share the NOT NULL description column.
try {
    $pdo->beginTransaction();
    if ($action === 'create_expense') {
        $insertStmt = $pdo->prepare("
            INSERT INTO expense_records
                (expense_type, reference_type, reference_id, description, amount, quantity,
                 expense_date, recorded_by)
            VALUES (?, 'trip', ?, ?, ?, ?, ?, ?)
        ");
        $createdIds = [];
        foreach ($expenseEntries as $entry) {
            $entryType = $entry['expense_type'];
            $entryAmount = round((float)$entry['amount'], 2);
            $insertStmt->execute([
                $entryType,
                $tripId,
                tripExpenseDescription($entryType, $entry['other_description'], $entry['notes']),
                $entryAmount,
                $entry['quantity'],
                $date,
                currentUserId(),
            ]);
            $newId = (int)$pdo->lastInsertId();
            $createdIds[] = $newId;
            auditLog('CREATE_TRIP_EXPENSE', 'expense_records', $newId, null, [
                'trip_id' => $tripId,
                'expense_type' => $entryType,
                'amount' => $entryAmount,
            ]);
        }
        $pdo->commit();
        $message = count($createdIds) === 1 ? 'Trip expense recorded.' : count($createdIds) . ' trip expenses recorded.';
        jsonOk([], $message);
    } else {
        $old = $pdo->prepare(
            "SELECT expense_type, reference_id, amount, quantity, description, expense_date
             FROM expense_records
             WHERE expense_id = ? AND reference_type = 'trip' FOR UPDATE"
        );
        $old->execute([$expenseId]);
        $oldData = $old->fetch(PDO::FETCH_ASSOC);
        if (!$oldData) {
            $pdo->rollBack();
            jsonFail('Expense not found.', 404);
        }
        $pdo->prepare("
            UPDATE expense_records
            SET reference_id = ?, expense_type = ?, amount = ?, quantity = ?, description = ?, expense_date = ?
            WHERE expense_id = ? AND reference_type = 'trip'
        ")->execute([
            $tripId, $type, round($amount, 2), $quantity,
            tripExpenseDescription($type, $otherDescription, $notes), $date, $expenseId,
        ]);
        auditLog('UPDATE_TRIP_EXPENSE', 'expense_records', $expenseId, $oldData, [
            'trip_id' => $tripId,
            'expense_type' => $type,
            'amount' => $amount,
        ]);
        $pdo->commit();
        jsonOk([], 'Trip expense updated.');
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('trip_costs_handler/' . $action . ': ' . $e->getMessage());
    jsonFail('A database error occurred. Please try again.', 500);
}
