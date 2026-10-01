<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requirePermission('billing.manage');
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();

$action = $_POST['action'] ?? '';

// ── Create billing ────────────────────────────────────────────────────────────
if ($action === 'create_billing') {

    $tripId         = requiredInt('trip_id', 'Trip', 1);
    $clientName     = requiredString('client_name', 'Client', 150);
    $amount         = requiredPositiveFloat('amount', 'Amount');
    $dueDate        = requiredDate('due_date', 'Due date', true);
    $billingNumber  = requiredString('billing_number', 'Billing number', 100);
    $invoiceNumber  = optionalString('invoice_number', null, 50);
    $invoiceDateRaw = trim($_POST['invoice_date'] ?? '');
    $notes          = optionalString('notes');

    $invoiceDate = null;
    if ($invoiceDateRaw !== '') {
        if (!isValidDate($invoiceDateRaw)) {
            jsonFail('Invoice date must be a valid date.');
        }
        $invoiceDate = $invoiceDateRaw;
    }

    $clientStmt = $pdo->prepare('SELECT client_id FROM clients WHERE client_name = ? AND is_active = 1');
    $clientStmt->execute([$clientName]);
    $clientId = $clientStmt->fetchColumn();
    if (!$clientId) {
        jsonFail('Select a registered active client.');
    }

    // Verify trip exists and is completed
    $trip = findOrFail($pdo, 'trips', 'trip_id', $tripId, 'Trip not found.');

    if ($trip['status'] !== 'Completed') {
        jsonFail('Billings can only be created for completed trips.');
    }

    // Unique billing number
    if (existsWhere($pdo, 'billings', 'billing_number', $billingNumber)) {
        jsonFail('Billing number already exists.');
    }

    // Unique invoice number (client-facing reference), when supplied
    if ($invoiceNumber !== null && existsWhere($pdo, 'billings', 'invoice_number', $invoiceNumber)) {
        jsonFail('Invoice number already exists.');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO billings
                (trip_id, client_id, created_by, billing_number, invoice_number, invoice_date,
                 client_name, amount, due_date, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid', ?)
        ");
        $stmt->execute([
            $tripId, $clientId, currentUserId(), $billingNumber, $invoiceNumber, $invoiceDate,
            $clientName, $amount, $dueDate, $notes,
        ]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('CREATE_BILLING', 'billings', $newId, null, [
            'billing_number' => $billingNumber,
            'invoice_number' => $invoiceNumber,
            'trip_id'        => $tripId,
            'client_id'      => $clientId,
            'amount'         => $amount,
            'due_date'       => $dueDate,
        ]);

        jsonOk(['id' => $newId], 'Billing created successfully.');
    } catch (PDOException $e) {
        error_log('billing_handler/create: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Record payment (collection) ───────────────────────────────────────────────
if ($action === 'record_payment') {

    $billingId  = requiredInt('billing_id', 'Billing', 1);
    $amountPaid = requiredPositiveFloat('amount_paid', 'Amount paid');
    $payDate    = requiredDate('payment_date', 'Payment date', true);
    $payMode    = requiredEnum('payment_mode', PAYMENT_MODES, 'Payment mode');
    $reference  = optionalString('reference_no');
    $remarks    = optionalString('remarks');

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
        jsonFail('Billing not found.', 404);
    }

    if ($billing['status'] === 'Paid') {
        jsonFail('This billing is already fully paid.');
    }

    if ($amountPaid > $billing['balance']) {
        jsonFail('Payment amount exceeds outstanding balance of ₱' . number_format($billing['balance'], 2) . '.');
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

        jsonOk([
            'new_status'  => $newStatus,
            'new_balance' => $newBalance,
        ], 'Payment recorded. Billing status: <strong>' . $newStatus . '</strong>.');
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('billing_handler/record_payment: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
