<?php
// ============================================================
// ajax/trip_pay_handler.php
// Logs per-trip pay for the Driver and/or Helper who were
// actually assigned to that trip (via dispatch_requests), paid
// at trip completion. This is distinct from:
//   - trip_expenses (Driver Allowance): expense reimbursement,
//     not wages.
//   - payroll_records: periodic salary for fixed-salary staff.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');
requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'log') {
    $tripId = requiredInt('trip_id', 'Trip', 1);
    $date   = requiredDate('paid_date', 'Paid date');
    $notes  = optionalString('notes');

    // entries: [{employee_id, crew_role, amount}, ...] — one or two rows
    // (driver, and optionally helper) submitted together per trip.
    $rawEntries = $_POST['entries'] ?? null;
    $entries = [];
    if (is_array($rawEntries)) {
        foreach ($rawEntries as $entry) {
            if (!is_array($entry)) continue;
            $entries[] = [
                'employee_id' => (int)($entry['employee_id'] ?? 0),
                'crew_role'   => trim((string)($entry['crew_role'] ?? '')),
                'amount'      => (float)($entry['amount'] ?? 0),
            ];
        }
    }

    if ($entries === []) {
        jsonFail('At least one crew member is required.');
    }

    // Look up who was ACTUALLY assigned to this trip, so pay can only be
    // logged for the driver/helper who really worked it — not an arbitrary
    // employee picked from a dropdown.
    $assignStmt = $pdo->prepare("
        SELECT dr.driver_id, dr.helper_id
        FROM trips t
        JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
        WHERE t.trip_id = ?
    ");
    $assignStmt->execute([$tripId]);
    $assignment = $assignStmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        jsonFail('Trip not found.', 404);
    }

    $validAssignments = [
        (int)$assignment['driver_id'] => 'Driver',
    ];
    if (!empty($assignment['helper_id'])) {
        $validAssignments[(int)$assignment['helper_id']] = 'Helper';
    }

    foreach ($entries as $entry) {
        if ($entry['amount'] <= 0) {
            jsonFail('Each pay amount must be greater than zero.');
        }
        $expectedRole = $validAssignments[$entry['employee_id']] ?? null;
        if ($expectedRole === null) {
            jsonFail('That employee was not assigned to this trip.');
        }
        if ($expectedRole !== $entry['crew_role']) {
            jsonFail('Crew role does not match this trip\'s assignment.');
        }
    }

    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO trip_pay (trip_id, employee_id, crew_role, amount, paid_date, recorded_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), paid_date = VALUES(paid_date), notes = VALUES(notes)
        ");
        foreach ($entries as $entry) {
            $insertStmt->execute([
                $tripId, $entry['employee_id'], $entry['crew_role'], $entry['amount'], $date, currentUserId(), $notes,
            ]);
            auditLog('LOG_TRIP_PAY', 'trip_pay', (int)$pdo->lastInsertId(), null, [
                'trip_id'     => $tripId,
                'employee_id' => $entry['employee_id'],
                'crew_role'   => $entry['crew_role'],
                'amount'      => $entry['amount'],
            ]);
        }
        jsonOk([], 'Crew pay logged.');
    } catch (PDOException $e) {
        error_log('trip_pay_handler/log: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

if ($action === 'delete') {
    if (currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
        jsonFail('Only Head Management can delete crew pay entries.', 403);
    }
    $tripPayId = requiredInt('trip_pay_id', 'Entry', 1);

    try {
        $stmt = $pdo->prepare("DELETE FROM trip_pay WHERE trip_pay_id = ?");
        $stmt->execute([$tripPayId]);
        auditLog('DELETE_TRIP_PAY', 'trip_pay', $tripPayId, null, null);
        jsonOk([], 'Crew pay entry deleted.');
    } catch (PDOException $e) {
        error_log('trip_pay_handler/delete: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
