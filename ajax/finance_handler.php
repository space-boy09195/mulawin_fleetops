<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/approval_workflow.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';

header('Content-Type: application/json');
requireAnyPermission(['finance.funds.view', 'finance.disbursements.view', 'finance.ap.view']);
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'create_fund_request') {
    requirePermission('finance.funds.manage');
    $type = optionalString('request_type', '');
    $purpose = requiredString('purpose', 'Purpose', 255);
    $amount = (float)($_POST['amount'] ?? 0);
    $tripId = ($_POST['trip_id'] ?? '') !== '' ? requiredInt('trip_id', 'Trip', 1) : null;
    $notes = optionalString('notes');
    if (!in_array($type, ['Trip Allowance', 'Cash Advance', 'Operating Fund', 'Other'], true) || $amount <= 0) {
        jsonFail('Request type and a positive amount are required.');
    }
    if ($tripId !== null) {
        $trip = $pdo->prepare('SELECT trip_id FROM trips WHERE trip_id = ?');
        $trip->execute([$tripId]);
        if (!$trip->fetchColumn()) jsonFail('Trip not found.');
    }
    try {
        $pdo->beginTransaction();
        $number = 'FR-' . date('YmdHis') . '-' . random_int(100, 999);
        $insert = $pdo->prepare(
            'INSERT INTO fund_requests
             (request_number, requested_by, request_type, trip_id, purpose, amount, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$number, currentUserId(), $type, $tripId, $purpose, $amount, $notes]);
        $requestId = (int)$pdo->lastInsertId();
        $approvalId = createApprovalRequest($pdo, 'fund_request', 'fund_request', $requestId, currentUserId());
        $pdo->prepare('UPDATE fund_requests SET approval_id = ? WHERE fund_request_id = ?')
            ->execute([$approvalId, $requestId]);
        $pdo->commit();
        auditLog('CREATE', 'fund_requests', $requestId, null, ['request_number' => $number, 'amount' => $amount]);
        jsonOk(['fund_request_id' => $requestId, 'request_number' => $number], 'Fund request submitted for approval.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('finance_handler/create_fund_request: ' . $e->getMessage());
        jsonFail('Could not create fund request.', 500);
    }
}

if ($action === 'create_disbursement') {
    requirePermission('finance.disbursements.manage');
    $requestId = ($_POST['fund_request_id'] ?? '') !== '' ? requiredInt('fund_request_id', 'Fund request', 1) : null;
    $voucherId = ($_POST['payment_voucher_id'] ?? '') !== '' ? requiredInt('payment_voucher_id', 'Payment voucher', 1) : null;
    $payee = requiredString('payee_name', 'Payee', 150);
    $amount = (float)($_POST['amount'] ?? 0);
    $mode = requiredString('payment_mode', 'Payment mode', 50);
    $date = requiredDate('disbursed_at', 'Disbursement date', true);
    $reference = optionalString('reference_no', null, 100);
    $notes = optionalString('notes');
    if ($amount <= 0) jsonFail('Disbursement amount must be positive.');
    try {
        $pdo->beginTransaction();
        if ($requestId !== null && $voucherId !== null) {
            throw new DomainException('Link a disbursement to a fund request or payment voucher, not both.');
        }
        if ($voucherId !== null) {
            $voucher = $pdo->prepare(
                "SELECT voucher_id, payable_id, amount, status FROM payment_vouchers WHERE voucher_id = ? FOR UPDATE"
            );
            $voucher->execute([$voucherId]);
            $voucherRow = $voucher->fetch(PDO::FETCH_ASSOC);
            if (!$voucherRow || $voucherRow['status'] !== 'Approved') {
                throw new DomainException('Only approved payment vouchers can be disbursed.');
            }
            $paid = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM finance_disbursements WHERE payment_voucher_id = ?');
            $paid->execute([$voucherId]);
            $remaining = round((float)$voucherRow['amount'] - (float)$paid->fetchColumn(), 2);
            if ($amount > $remaining) {
                throw new DomainException('Disbursement exceeds the remaining approved voucher amount.');
            }
        } elseif ($requestId !== null) {
            $request = $pdo->prepare(
                "SELECT fund_request_id, amount, status FROM fund_requests WHERE fund_request_id = ? FOR UPDATE"
            );
            $request->execute([$requestId]);
            $fund = $request->fetch(PDO::FETCH_ASSOC);
            if (!$fund || $fund['status'] !== 'Approved') {
                throw new DomainException('Only approved fund requests can be disbursed.');
            }
            $paid = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM finance_disbursements WHERE fund_request_id = ?');
            $paid->execute([$requestId]);
            $remaining = round((float)$fund['amount'] - (float)$paid->fetchColumn(), 2);
            if ($amount > $remaining) {
                throw new DomainException('Disbursement exceeds the remaining approved fund amount.');
            }
        }
        $number = 'DISB-' . date('YmdHis') . '-' . random_int(100, 999);
        $pdo->prepare(
            'INSERT INTO finance_disbursements
             (disbursement_number, fund_request_id, payment_voucher_id, payee_name, amount, payment_mode, reference_no, disbursed_by, disbursed_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$number, $requestId, $voucherId, $payee, $amount, $mode, $reference, currentUserId(), $date, $notes]);
        if ($requestId !== null) {
            $paid->execute([$requestId]);
            if ((float)$paid->fetchColumn() >= (float)$fund['amount']) {
                $pdo->prepare("UPDATE fund_requests SET status = 'Disbursed' WHERE fund_request_id = ?")
                    ->execute([$requestId]);
            }
        }
        if ($voucherId !== null) {
            $paid->execute([$voucherId]);
            if ((float)$paid->fetchColumn() >= (float)$voucherRow['amount']) {
                $pdo->prepare("UPDATE payment_vouchers SET status = 'Paid' WHERE voucher_id = ?")
                    ->execute([$voucherId]);
            }
            $pdo->prepare(
                "UPDATE accounts_payable ap
                 SET ap.status = CASE
                   WHEN ap.status = 'Cancelled' THEN 'Cancelled'
                   WHEN (
                     SELECT COALESCE(SUM(fd.amount), 0)
                     FROM finance_disbursements fd
                     JOIN payment_vouchers pv ON pv.voucher_id = fd.payment_voucher_id
                     WHERE pv.payable_id = ap.payable_id
                   ) >= ap.amount THEN 'Paid'
                   ELSE 'Partially Paid'
                 END
                 WHERE ap.payable_id = ?"
            )->execute([$voucherRow['payable_id']]);
        }
        $pdo->commit();
        auditLog('CREATE', 'finance_disbursements', (int)$pdo->lastInsertId(), null, ['amount' => $amount]);
        jsonOk(['disbursement_number' => $number], 'Disbursement recorded.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) jsonFail($e->getMessage(), 409);
        error_log('finance_handler/create_disbursement: ' . $e->getMessage());
        jsonFail('Could not record disbursement.', 500);
    }
}

if ($action === 'create_payable') {
    requirePermission('finance.ap.manage');
    $supplier = requiredString('supplier_name', 'Supplier', 150);
    $invoice = optionalString('invoice_number', null, 100);
    $description = requiredString('description', 'Description', 255);
    $amount = (float)($_POST['amount'] ?? 0);
    $dueDate = optionalString('due_date', null, 10);
    if ($amount <= 0 || ($dueDate !== null && !isValidDate($dueDate))) jsonFail('Supplier, description, amount, and a valid due date are required.');
    try {
        $number = 'AP-' . date('YmdHis') . '-' . random_int(100, 999);
        $pdo->prepare(
            'INSERT INTO accounts_payable
             (payable_number, supplier_name, invoice_number, description, amount, due_date, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$number, $supplier, $invoice, $description, $amount, $dueDate, currentUserId()]);
        auditLog('CREATE', 'accounts_payable', (int)$pdo->lastInsertId(), null, ['amount' => $amount]);
        jsonOk(['payable_number' => $number], 'Accounts payable record created.');
    } catch (Throwable $e) {
        error_log('finance_handler/create_payable: ' . $e->getMessage());
        jsonFail('Could not create accounts payable record.', 500);
    }
}

if ($action === 'create_voucher') {
    requirePermission('finance.ap.manage');
    $payableId = requiredInt('payable_id', 'Accounts payable', 1);
    $amount = (float)($_POST['amount'] ?? 0);
    $notes = optionalString('notes');
    if ($amount <= 0) jsonFail('Voucher amount must be positive.');
    try {
        $pdo->beginTransaction();
        $payable = $pdo->prepare(
            "SELECT payable_id, amount, status FROM accounts_payable WHERE payable_id = ? FOR UPDATE"
        );
        $payable->execute([$payableId]);
        $payableRow = $payable->fetch(PDO::FETCH_ASSOC);
        if (!$payableRow || !in_array($payableRow['status'], ['Open', 'Partially Paid'], true)) {
            throw new DomainException('Voucher must reference an open payable.');
        }
        $reserved = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_vouchers
             WHERE payable_id = ? AND status NOT IN ('Rejected', 'Cancelled')"
        );
        $reserved->execute([$payableId]);
        $remaining = round((float)$payableRow['amount'] - (float)$reserved->fetchColumn(), 2);
        if ($amount > $remaining) {
            throw new DomainException('Voucher amount exceeds the remaining payable balance.');
        }
        $number = 'PV-' . date('YmdHis') . '-' . random_int(100, 999);
        $pdo->prepare(
            'INSERT INTO payment_vouchers (voucher_number, payable_id, amount, prepared_by, notes)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$number, $payableId, $amount, currentUserId(), $notes]);
        $voucherId = (int)$pdo->lastInsertId();
        $approvalId = createApprovalRequest($pdo, 'fund_request', 'payment_voucher', $voucherId, currentUserId());
        $pdo->prepare('UPDATE payment_vouchers SET approval_id = ? WHERE voucher_id = ?')
            ->execute([$approvalId, $voucherId]);
        $pdo->commit();
        auditLog('CREATE', 'payment_vouchers', $voucherId, null, ['voucher_number' => $number, 'amount' => $amount]);
        jsonOk(['voucher_id' => $voucherId, 'voucher_number' => $number], 'Payment voucher submitted for approval.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) jsonFail($e->getMessage(), 409);
        error_log('finance_handler/create_voucher: ' . $e->getMessage());
        jsonFail('Could not create payment voucher.', 500);
    }
}

if ($action === 'list') {
    $funds = $pdo->query(
        "SELECT fr.request_number, fr.request_type, fr.purpose, fr.amount, fr.status, fr.requested_at,
                u.full_name AS requester
         FROM fund_requests fr JOIN users u ON u.user_id = fr.requested_by
         ORDER BY fr.requested_at DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    $disbursements = $pdo->query(
        "SELECT disbursement_number, payee_name, amount, payment_mode, disbursed_at
         FROM finance_disbursements ORDER BY disbursed_at DESC, disbursement_id DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    $payables = $pdo->query(
        "SELECT payable_id, payable_number, supplier_name, invoice_number, description, amount, due_date, status
         FROM accounts_payable ORDER BY due_date IS NULL, due_date ASC, payable_id DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    $vouchers = $pdo->query(
        "SELECT pv.voucher_id, pv.voucher_number, ap.payable_number, pv.amount, pv.status
         FROM payment_vouchers pv JOIN accounts_payable ap ON ap.payable_id = pv.payable_id
         ORDER BY pv.voucher_id DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    jsonOk(['fund_requests' => $funds, 'disbursements' => $disbursements, 'payables' => $payables, 'vouchers' => $vouchers]);
}

jsonFail('Unknown action.');
