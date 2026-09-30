<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';

header('Content-Type: application/json');
requireAnyPermission(['hr.recruitment.view', 'hr.recruitment.manage']);
requirePostMethod();
enforceCsrf();

$action = $_POST['action'] ?? '';
if ($action === 'list') {
    requirePermission('hr.recruitment.view');
    $rows = getDBConnection()->query(
        'SELECT candidate_number, full_name, desired_position, phone, email, applied_on, source, status, notes
         FROM recruitment_candidates ORDER BY applied_on DESC, candidate_id DESC LIMIT 300'
    )->fetchAll(PDO::FETCH_ASSOC);
    jsonOk(['candidates' => $rows]);
}
requirePermission('hr.recruitment.manage');
$pdo = getDBConnection();

if ($action === 'create') {
    $name = requiredString('full_name', 'Candidate name', 150);
    $position = requiredString('desired_position', 'Desired position', 100);
    $phone = optionalString('phone', null, 50);
    $email = optionalString('email', null, 150);
    $date = requiredDate('applied_on', 'Application date');
    $source = optionalString('source', null, 100);
    $notes = optionalString('notes', null, 5000);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonFail('Enter a valid email address.');
    try {
        $number = 'APP-' . date('YmdHis') . '-' . random_int(100, 999);
        $pdo->prepare(
            'INSERT INTO recruitment_candidates
             (candidate_number, full_name, desired_position, phone, email, applied_on, source, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$number, $name, $position, $phone, $email, $date, $source, $notes, currentUserId()]);
        $id = (int)$pdo->lastInsertId();
        auditLog('CREATE', 'recruitment_candidates', $id, null, ['candidate_number' => $number, 'position' => $position]);
        jsonOk(['candidate_number' => $number], 'Candidate added to recruitment pipeline.');
    } catch (PDOException $e) {
        error_log('recruitment_handler/create: ' . $e->getMessage());
        jsonFail('Could not add candidate.', 500);
    }
}

if ($action === 'update_status') {
    $candidateNumber = requiredString('candidate_number', 'Candidate', 40);
    $status = requiredEnum('status', ['New','Screening','Interview','Reference Check','Offer','Hired','Rejected','Withdrawn'], 'Status');
    $notes = optionalString('notes', null, 5000);
    $stmt = $pdo->prepare('UPDATE recruitment_candidates SET status = ?, notes = COALESCE(?, notes) WHERE candidate_number = ?');
    $stmt->execute([$status, $notes, $candidateNumber]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM recruitment_candidates WHERE candidate_number = ? AND status = ?');
        $check->execute([$candidateNumber, $status]);
        if (!$check->fetchColumn()) jsonFail('Candidate not found.', 404);
    }
    auditLog('UPDATE_RECRUITMENT_STATUS', 'recruitment_candidates', null, null, [
        'candidate_number' => $candidateNumber, 'status' => $status,
    ]);
    jsonOk([], 'Candidate status updated.');
}

jsonFail('Unknown action.');
