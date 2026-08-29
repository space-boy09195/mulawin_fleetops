<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Create billing ────────────────────────────────────────────────────────────
if ($action === 'create_billing') {

    $tripId        = (int)($_POST['trip_id']        ?? 0);
    $clientName    = trim($_POST['client_name']     ?? '') ?: null;
    $amount        = (float)($_POST['amount']       ?? 0);
    $dueDate       = trim($_POST['due_date']        ?? '');
    $billingNumber = trim($_POST['billing_number']  ?? '');
    $notes         = trim($_POST['notes']           ?? '') ?: null;

    if (!$tripId || !$amount || !$dueDate || !$billingNumber) {
        echo json_encode(['success' => false, 'message' => 'Trip, amount, due date, and billing number are required.']);
        exit;
    }

    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero.']);
        exit;
    }

    if (!isValidDate($dueDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid due date format.']);
        exit;
    }
    if (isPassedDate($dueDate)) {
        echo json_encode(['success' => false, 'message' => 'New billings cannot use a passed due date.']);
        exit;
    }

    // Verify trip exists and is completed
    $tripCheck = $pdo->prepare("SELECT trip_id, status FROM trips WHERE trip_id = ?");
    $tripCheck->execute([$tripId]);
    $trip = $tripCheck->fetch(PDO::FETCH_ASSOC);

    if (!$trip) {
        echo json_encode(['success' => false, 'message' => 'Trip not found.']);
        exit;
    }

    if ($trip['status'] !== 'Completed') {
        echo json_encode(['success' => false, 'message' => 'Billings can only be created for completed trips.']);
        exit;
    }

    // Unique billing number
    $dupCheck = $pdo->prepare("SELECT billing_id FROM billings WHERE billing_number = ?");
    $dupCheck->execute([$billingNumber]);
    if ($dupCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Billing number already exists.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO billings
                (trip_id, created_by, billing_number, client_name, amount, due_date, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, 'Unpaid', ?)
        ");
        $stmt->execute([$tripId, currentUserId(), $billingNumber, $clientName, $amount, $dueDate, $notes]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('CREATE_BILLING', 'billings', $newId, null, [
            'billing_number' => $billingNumber,
            'trip_id'        => $tripId,
            'amount'         => $amount,
            'due_date'       => $dueDate,
        ]);

        echo json_encode(['success' => true, 'message' => 'Billing created successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        error_log('billing_handler/create: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Record payment (collection) ───────────────────────────────────────────────
if ($action === 'record_payment') {

    $billingId  = (int)($_POST['billing_id']    ?? 0);
    $amountPaid = (float)($_POST['amount_paid'] ?? 0);
    $payDate    = trim($_POST['payment_date']   ?? '');
    $payMode    = trim($_POST['payment_mode']   ?? '');
    $reference  = trim($_POST['reference_no']  ?? '') ?: null;
    $remarks    = trim($_POST['remarks']        ?? '') ?: null;

    $allowedModes = ['Cash', 'Check', 'Bank Transfer', 'GCash', 'Other'];

    if (!$billingId || !$amountPaid || !$payDate || !$payMode) {
        echo json_encode(['success' => false, 'message' => 'Billing, amount, date, and payment mode are required.']);
        exit;
    }

    if ($amountPaid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Amount paid must be greater than zero.']);
        exit;
    }

    if (!in_array($payMode, $allowedModes)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment mode.']);
        exit;
    }

    if (!isValidDate($payDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment date format.']);
        exit;
    }
    if (isPassedDate($payDate)) {
        echo json_encode(['success' => false, 'message' => 'New payments cannot use a passed date.']);
        exit;
    }

    // Fetch billing and current balance
    $bilStmt = $pdo->prepare("
        SELECT b.billing_id, b.amount, b.status, vs.balance
        FROM billings b
        JOIN v_billing_summary vs ON b.billing_id = vs.billing_id
        WHERE b.billing_id = ?
    ");
    $bilStmt->execute([$billingId]);
    $billing = $bilStmt->fetch(PDO::FETCH_ASSOC);

    if (!$billing) {
        echo json_encode(['success' => false, 'message' => 'Billing not found.']);
        exit;
    }

    if ($billing['status'] === 'Paid') {
        echo json_encode(['success' => false, 'message' => 'This billing is already fully paid.']);
        exit;
    }

    if ($amountPaid > $billing['balance']) {
        echo json_encode([
            'success' => false,
            'message' => 'Payment amount exceeds outstanding balance of ₱' . number_format($billing['balance'], 2) . '.',
        ]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Insert collection record
        $pdo->prepare("
            INSERT INTO collections
                (billing_id, recorded_by, amount_paid, payment_date, payment_mode, reference_no, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$billingId, currentUserId(), $amountPaid, $payDate, $payMode, $reference, $remarks]);

        $collectionId = (int)$pdo->lastInsertId();

        // Recalculate new balance and update billing status
        $newBalance = round($billing['balance'] - $amountPaid, 2);
        $newStatus  = $newBalance <= 0 ? 'Paid' : 'Partial';

        $pdo->prepare("UPDATE billings SET status = ? WHERE billing_id = ?")
            ->execute([$newStatus, $billingId]);

        $pdo->commit();

        auditLog('RECORD_PAYMENT', 'collections', $collectionId, null, [
            'billing_id'  => $billingId,
            'amount_paid' => $amountPaid,
            'payment_mode' => $payMode,
            'new_status'  => $newStatus,
        ]);

        echo json_encode([
            'success'     => true,
            'message'     => 'Payment recorded. Billing status: <strong>' . $newStatus . '</strong>.',
            'new_status'  => $newStatus,
            'new_balance' => $newBalance,
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('billing_handler/record_payment: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);