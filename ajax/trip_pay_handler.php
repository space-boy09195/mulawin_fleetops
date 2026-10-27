<?php
// ============================================================
// ajax/trip_pay_handler.php
// Per-trip wages for the crew actually assigned to the trip (driver, second
// driver (T-T1) and helper), stored in the target trip_pay_records table
// (+ T-P1: recorded_by, notes, UNIQUE (trip_id, employee_id, pay_type)).
// This is distinct from:
//   - Driver Allowance: an expense_records row (reimbursement, not wages)
//   - payroll_records: periodic salary for fixed-salary staff
// The T-P1 unique key keeps one wage row per trip and person. A save never
// changes a recorded (non-cancelled) wage: it is refused, and a correction
// goes through the Admin cancel action below and a new save. A cancelled
// row is re-activated (audited with its old values) instead of duplicated.
// Cancelled trips cannot receive Trip Pay.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../app/modules/finance/finance_records.php';

header('Content-Type: application/json');
requirePermission('payroll.manage');
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'log') {
    $tripId = requiredInt('trip_id', 'Trip', 1);
    $date   = requiredDate('paid_date', 'Paid date');
    $notes  = optionalString('notes', null, 200);

    // entries: [{employee_id, crew_role, amount}, ...] submitted together per trip.
    $rawEntries = $_POST['entries'] ?? null;
    $entries = [];
    if (is_array($rawEntries)) {
        foreach ($rawEntries as $entry) {
            if (!is_array($entry)) continue;
            $rawAmount = trim((string)($entry['amount'] ?? ''));
            if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $rawAmount)) {
                jsonFail('Each pay amount must be a positive number with at most two decimal places.');
            }
            $entries[] = [
                'employee_id' => (int)($entry['employee_id'] ?? 0),
                'crew_role'   => trim((string)($entry['crew_role'] ?? '')),
                'amount'      => round((float)$rawAmount, 2),
            ];
        }
    }

    if ($entries === []) {
        jsonFail('At least one crew member is required.');
    }

    try {
        $pdo->beginTransaction();
        // Who was ACTUALLY assigned to this trip (locked), so pay can only be
        // logged for crew who really worked it.
        $assignStmt = $pdo->prepare(
            'SELECT trip_id, driver_id, second_driver_id, helper_id, status
             FROM trips WHERE trip_id = ? FOR UPDATE'
        );
        $assignStmt->execute([$tripId]);
        $assignment = $assignStmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            $pdo->rollBack();
            jsonFail('Trip not found.', 404);
        }
        // Legacy trips existed only after approval.
        if ($assignment['status'] === 'Pending Approval') {
            $pdo->rollBack();
            jsonFail('Crew pay can be logged only for an approved trip.', 409);
        }
        if ($assignment['status'] === 'Cancelled') {
            $pdo->rollBack();
            jsonFail('Crew pay cannot be logged for a cancelled trip.', 409);
        }

        $validAssignments = [];
        foreach (['driver_id' => 'Driver', 'second_driver_id' => 'Driver', 'helper_id' => 'Helper'] as $column => $role) {
            if (!empty($assignment[$column])) {
                $validAssignments[(int)$assignment[$column]] = $role;
            }
        }

        $seen = [];
        foreach ($entries as $entry) {
            if ($entry['amount'] <= 0) {
                $pdo->rollBack();
                jsonFail('Each pay amount must be greater than zero.');
            }
            $expectedRole = $validAssignments[$entry['employee_id']] ?? null;
            if ($expectedRole === null) {
                $pdo->rollBack();
                jsonFail('That employee was not assigned to this trip.');
            }
            if ($expectedRole !== $entry['crew_role']) {
                $pdo->rollBack();
                jsonFail('Crew role does not match this trip\'s assignment.');
            }
            if (isset($seen[$entry['employee_id']])) {
                $pdo->rollBack();
                jsonFail('Each crew member can appear only once.');
            }
            $seen[$entry['employee_id']] = true;
        }

        $storedNotes = tripPayComposeNotes($date, $notes);
        // Existing rows for this trip's crew, locked. A recorded wage is never
        // overwritten; only a cancelled row may be re-activated.
        $existingStmt = $pdo->prepare(
            'SELECT trip_pay_id, amount, status, notes FROM trip_pay_records
             WHERE trip_id = ? AND employee_id = ? AND pay_type = ? FOR UPDATE'
        );
        $insert = $pdo->prepare("
            INSERT INTO trip_pay_records (trip_id, employee_id, pay_type, amount, status, recorded_by, notes)
            VALUES (?, ?, ?, ?, 'Paid', ?, ?)
        ");
        $reactivate = $pdo->prepare("
            UPDATE trip_pay_records SET amount = ?, status = 'Paid', recorded_by = ?, notes = ?
            WHERE trip_pay_id = ? AND status = 'Cancelled'
        ");
        foreach ($entries as $entry) {
            $payType = TRIP_PAY_TYPES[$entry['crew_role']];
            $existingStmt->execute([$tripId, $entry['employee_id'], $payType]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            $oldValues = null;
            if ($existing && $existing['status'] !== 'Cancelled'
                && round((float)$existing['amount'], 2) === $entry['amount']) {
                continue; // resubmitted unchanged (the form pre-fills logged amounts)
            }
            if ($existing && $existing['status'] !== 'Cancelled') {
                $pdo->rollBack();
                jsonFail('Crew pay is already recorded for this crew member on this trip. Ask an Admin to cancel the entry before recording a corrected amount.', 409);
            }
            if ($existing) {
                $reactivate->execute([$entry['amount'], currentUserId(), $storedNotes, (int)$existing['trip_pay_id']]);
                if ($reactivate->rowCount() !== 1) {
                    $pdo->rollBack();
                    jsonFail('This crew pay entry changed while saving. Refresh the page and try again.', 409);
                }
                $tripPayId = (int)$existing['trip_pay_id'];
                $oldValues = ['amount' => $existing['amount'], 'status' => $existing['status'], 'notes' => $existing['notes']];
            } else {
                $insert->execute([
                    $tripId, $entry['employee_id'], $payType, $entry['amount'], currentUserId(), $storedNotes,
                ]);
                $tripPayId = (int)$pdo->lastInsertId();
            }
            auditLog('LOG_TRIP_PAY', 'trip_pay_records', $tripPayId, $oldValues, [
                'trip_id'     => $tripId,
                'employee_id' => $entry['employee_id'],
                'pay_type'    => $payType,
                'amount'      => $entry['amount'],
                'paid_date'   => $date,
            ]);
        }
        $pdo->commit();
        jsonOk([], 'Crew pay logged.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('trip_pay_handler/log: ' . $e->getMessage());
        // 1062: a concurrent save recorded the same crew pay first.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            jsonFail('Crew pay was recorded for this trip at the same time. Refresh the page to see it.', 409);
        }
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// Cancel (not delete) a Trip Pay entry: the row keeps its unique slot and
// its history; a later save re-activates it.
if ($action === 'delete') {
    if (!isAdmin()) {
        jsonFail('Only an Admin can delete crew pay entries.', 403);
    }
    $tripPayId = requiredInt('trip_pay_id', 'Entry', 1);

    try {
        $pdo->beginTransaction();
        $old = $pdo->prepare('SELECT trip_id, employee_id, pay_type, amount, status FROM trip_pay_records WHERE trip_pay_id = ? FOR UPDATE');
        $old->execute([$tripPayId]);
        $row = $old->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] === 'Cancelled') {
            $pdo->rollBack();
            jsonFail('Crew pay entry not found.', 404);
        }
        if ($row['status'] === 'Included in Payroll') {
            $pdo->rollBack();
            jsonFail('This entry is already included in payroll and cannot be cancelled here.', 409);
        }
        $pdo->prepare("UPDATE trip_pay_records SET status = 'Cancelled' WHERE trip_pay_id = ?")->execute([$tripPayId]);
        auditLog('DELETE_TRIP_PAY', 'trip_pay_records', $tripPayId, $row, ['status' => 'Cancelled']);
        $pdo->commit();
        jsonOk([], 'Crew pay entry deleted.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('trip_pay_handler/delete: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
