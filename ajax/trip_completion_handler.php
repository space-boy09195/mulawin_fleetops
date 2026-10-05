<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/trip_completion.php';

header('Content-Type: application/json');
requireLogin();
requirePostMethod();
enforceCsrf();

$tripId = requiredInt('trip_id', 'Trip', 1);
if (isset($_POST['driver_note']) && !is_string($_POST['driver_note'])) {
    jsonFail('The trip note must be submitted as text.');
}
$driverNote = optionalString('driver_note', null, 500);
$pdo = getDBConnection();

$employeeQuery = $pdo->prepare(
    'SELECT employee_id, position
     FROM employees
     WHERE user_id = ? AND is_active = 1
     ORDER BY employee_id'
);
$employeeQuery->execute([currentUserId()]);
$employees = $employeeQuery->fetchAll(PDO::FETCH_ASSOC);
if (count($employees) !== 1) {
    error_log(sprintf(
        'Trip completion report rejected for user_id %d: expected one active linked employee, found %d.',
        currentUserId(),
        count($employees)
    ));
    jsonFail('A unique active employee record must be linked to your account before reporting trip completion.', 409);
}
$employee = $employees[0];
if (stripos((string)$employee['position'], 'driver') === false) {
    jsonFail('Only an assigned driver can report trip completion.', 403);
}

try {
    $pdo->beginTransaction();
    $employeeLock = $pdo->prepare(
        'SELECT employee_id
         FROM employees
         WHERE employee_id = ? AND user_id = ? AND is_active = 1
         FOR UPDATE'
    );
    $employeeLock->execute([(int)$employee['employee_id'], currentUserId()]);
    if (!$employeeLock->fetchColumn()) {
        $pdo->rollBack();
        jsonFail('Your active employee link changed. Refresh the page and try again.', 409);
    }

    $tripQuery = $pdo->prepare(
        'SELECT t.trip_id, t.trip_number, t.status, dr.dispatch_id, dr.requested_by,
                dr.driver_id, dr.second_driver_id
         FROM trips t
         JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
         WHERE t.trip_id = ?
         FOR UPDATE'
    );
    $tripQuery->execute([$tripId]);
    $trip = $tripQuery->fetch(PDO::FETCH_ASSOC);
    if (!$trip) {
        $pdo->rollBack();
        jsonFail('Trip not found.', 404);
    }
    if ((int)$trip['driver_id'] !== (int)$employee['employee_id']
        && (int)($trip['second_driver_id'] ?? 0) !== (int)$employee['employee_id']) {
        $pdo->rollBack();
        jsonFail('You are not assigned as a driver on this trip.', 403);
    }
    if (in_array($trip['status'], ['Completed', 'Cancelled'], true)) {
        $pdo->rollBack();
        jsonFail('This trip is no longer active and cannot receive a completion report.', 409);
    }

    if (findActiveTripCompletionReport($pdo, $tripId, true)) {
        $pdo->rollBack();
        jsonFail('A completion report has already been submitted for this trip.', 409);
    }

    $insert = $pdo->prepare(
        'INSERT INTO trip_completion_reports
            (trip_id, dispatch_id, reported_by, driver_note)
         VALUES (?, ?, ?, ?)'
    );
    $insert->execute([
        $tripId,
        (int)$trip['dispatch_id'],
        (int)$employee['employee_id'],
        $driverNote,
    ]);
    $reportId = (int)$pdo->lastInsertId();

    $recipientQuery = $pdo->prepare(
        "SELECT u.user_id
         FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1
           AND p.permission_key IN ('trips.update', 'trips.view')
           AND u.user_id <> ?
           AND (u.role_id = ? OR u.user_id = ?)
         GROUP BY u.user_id
         HAVING COUNT(DISTINCT p.permission_key) = 2"
    );
    $recipientQuery->execute([currentUserId(), ROLE_ADMIN, (int)$trip['requested_by']]);
    $recipients = array_map('intval', $recipientQuery->fetchAll(PDO::FETCH_COLUMN));
    if (!$recipients) {
        $pdo->rollBack();
        jsonFail('The report was not saved because no authorized trip recorder can receive it.', 409);
    }
    $message = $trip['trip_number']
        . ' has been reported complete by the assigned driver. Official trip completion is still required.';
    foreach ($recipients as $recipientId) {
        createNotification(
            $pdo,
            $recipientId,
            'Driver reported trip completion',
            $message,
            APP_BASE . '/pages/trip_workflow.php?trip_id=' . $tripId
        );
    }

    auditLog('REPORT_TRIP_COMPLETED', 'trip_completion_reports', $reportId, null, [
        'trip_id' => $tripId,
        'dispatch_id' => (int)$trip['dispatch_id'],
        'status' => 'Pending',
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('trip_completion_handler: ' . $e->getMessage());
    jsonFail('Could not submit the trip completion report. Please try again.', 500);
}

jsonOk([
    'report_id' => $reportId,
    'reported_at' => date('Y-m-d H:i:s'),
], 'Trip completion reported. Operations has been notified; the authorized trip recorder must still complete the trip.');
