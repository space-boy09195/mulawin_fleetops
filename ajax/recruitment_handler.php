<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../app/modules/people/recruitment.php';

header('Content-Type: application/json');
requireAnyPermission(['hr.recruitment.view', 'hr.recruitment.manage']);
requirePostMethod();
enforceCsrf();

$action = $_POST['action'] ?? '';
if ($action === 'list') {
    requirePermission('hr.recruitment.view');
    $rows = getDBConnection()->query(
        'SELECT recruitment_id, applicant_name AS full_name, position AS desired_position,
                contact_number AS phone, application_date AS applied_on, status, notes
         FROM recruitment_records ORDER BY application_date DESC, recruitment_id DESC LIMIT 300'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $parts = recruitmentSplitNotes($row['notes']);
        $row['candidate_number'] = recruitmentCandidateNumber((int)$row['recruitment_id']);
        $row['email'] = $parts['email'];
        $row['source'] = $parts['source'];
        $row['notes'] = $parts['notes'];
    }
    unset($row);
    jsonOk(['candidates' => $rows]);
}
requirePermission('hr.recruitment.manage');
$pdo = getDBConnection();

if ($action === 'create') {
    $name = requiredString('full_name', 'Candidate name', 180);
    $position = requiredString('desired_position', 'Desired position', 100);
    $phone = optionalString('phone', null, 50);
    $email = optionalString('email', null, 150);
    $date = requiredDate('applied_on', 'Application date');
    $source = optionalString('source', null, 100);
    $notes = optionalString('notes', null, 5000);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonFail('Enter a valid email address.');
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO recruitment_records
             (applicant_name, position, contact_number, application_date, status, notes, created_by)
             VALUES (?, ?, ?, ?, 'Applicant', ?, ?)"
        )->execute([$name, $position, $phone, $date, recruitmentComposeNotes($email, $source, $notes), currentUserId()]);
        $id = (int)$pdo->lastInsertId();
        $number = recruitmentCandidateNumber($id);
        auditLog('CREATE', 'recruitment_records', $id, null, ['candidate_number' => $number, 'position' => $position]);
        $pdo->commit();
        jsonOk(['candidate_number' => $number], 'Candidate added to recruitment pipeline.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('recruitment_handler/create: ' . $e->getMessage());
        jsonFail('Could not add candidate.', 500);
    }
}

if ($action === 'update_status') {
    $candidateNumber = requiredString('candidate_number', 'Candidate', 40);
    $recruitmentId = recruitmentIdFromNumber($candidateNumber);
    if ($recruitmentId === null) {
        jsonFail('Candidate not found.', 404);
    }
    $status = requiredEnum('status', RECRUITMENT_STATUSES, 'Status');
    $notes = optionalString('notes', null, 5000);
    try {
        $pdo->beginTransaction();
        $current = $pdo->prepare('SELECT status, notes FROM recruitment_records WHERE recruitment_id = ? FOR UPDATE');
        $current->execute([$recruitmentId]);
        $row = $current->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            jsonFail('Candidate not found.', 404);
        }
        $newNotes = $row['notes'];
        if ($notes !== null) {
            $parts = recruitmentSplitNotes($row['notes']);
            $newNotes = recruitmentComposeNotes($parts['email'], $parts['source'], $notes);
        }
        $pdo->prepare('UPDATE recruitment_records SET status = ?, notes = ? WHERE recruitment_id = ?')
            ->execute([$status, $newNotes, $recruitmentId]);
        auditLog('UPDATE_RECRUITMENT_STATUS', 'recruitment_records', $recruitmentId, ['status' => $row['status']], [
            'candidate_number' => $candidateNumber, 'status' => $status,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('recruitment_handler/update_status: ' . $e->getMessage());
        jsonFail('Could not update the candidate.', 500);
    }
    jsonOk([], 'Candidate status updated.');
}

jsonFail('Unknown action.');
