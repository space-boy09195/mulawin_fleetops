<?php
// ============================================================
// ajax/finance_handler.php
// Fund requests, accounts payable, payment vouchers and disbursements on
// the target schema:
//   fund_requests      request_type, purpose, amount, status (Pending
//                      Approval -> Approved -> Disbursed), workflow
//                      'fund_request'
//   accounts_payable   supplier_id, reference_number (supplier invoice),
//                      description, amount, due_date, status
//   payment_vouchers   payee, purpose, amount, reference_type
//                      'accounts_payable' / reference_id, workflow
//                      'payment_voucher' (legacy submitted vouchers under the
//                      fund_request workflow; fixed here)
//   disbursements      reference_type / reference_id (required in the
//                      target), payment_method, payment_date, check_number,
//                      transaction_reference, processed_by
// Display numbers without a target column: AP-<ap_id>, DISB-<id>.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/approval_workflow.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/purchasing.php';

header('Content-Type: application/json');
requirePostMethod();
enforceCsrf();
requireLogin();

$pdo = getDBConnection();
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$isDispatcherRole = in_array(currentRoleKey(), ['car_carrier_dispatcher', 'container_wing_van_dispatcher'], true);
$tripAllowanceRequestOnly = $action === 'create_fund_request'
    && $isDispatcherRole
    && !currentUserHasAnyPermission(['finance.funds.manage'])
    && currentUserHasAnyPermission(['finance.trip_allowance.request']);
if (!$tripAllowanceRequestOnly) {
    requireAnyPermission(['finance.funds.view', 'finance.disbursements.view', 'finance.ap.view']);
}

function financeAmount(string $key, string $label): float {
    $raw = trim((string)($_POST[$key] ?? ''));
    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $raw) || (float)$raw <= 0) {
        jsonFail($label . ' must be a positive amount with at most two decimal places.');
    }
    return round((float)$raw, 2);
}

if ($action === 'create_fund_request') {
    $canManageFunds = currentUserHasAnyPermission(['finance.funds.manage']);
    if (!$canManageFunds && !$tripAllowanceRequestOnly) {
        requirePermission('finance.funds.manage');
    }
    $type = optionalString('request_type', '');
    $purpose = requiredString('purpose', 'Purpose', 255);
    $amount = financeAmount('amount', 'Amount');
    $tripId = ($_POST['trip_id'] ?? '') !== '' ? requiredInt('trip_id', 'Trip', 1) : null;
    $notes = optionalString('notes', null, 2000);
    if (!in_array($type, ['Trip Allowance', 'Cash Advance', 'Operating Fund', 'Other'], true)) {
        jsonFail('Request type and a positive amount are required.');
    }
    if ($tripAllowanceRequestOnly && $type !== 'Trip Allowance') {
        jsonFail('This permission only allows Trip Allowance requests.', 403);
    }
    if ($type === 'Trip Allowance' && $tripId === null) {
        jsonFail('A Trip Allowance request must be linked to a trip.');
    }
    $requestKey = null;
    if ($tripAllowanceRequestOnly) {
        if ($tripId === null) {
            jsonFail('A Trip Allowance request must be linked to your assigned trip.');
        }
        $requestKey = requestIdempotencyKey();
        if ($requestKey === null) {
            jsonFail('Missing or invalid request idempotency key.', 400);
        }
    } elseif ($tripId !== null) {
        $trip = $pdo->prepare("SELECT trip_id FROM trips WHERE trip_id = ? AND status <> 'Cancelled'");
        $trip->execute([$tripId]);
        if (!$trip->fetchColumn()) jsonFail('Trip not found.');
    }
    try {
        $pdo->beginTransaction();
        if ($requestKey !== null && !claimIdempotencyKey($pdo, 'finance.trip_allowance.create.' . $tripId, $requestKey)) {
            $pdo->rollBack();
            jsonFail('This Trip Allowance request has already been submitted.', 409);
        }
        if ($tripAllowanceRequestOnly) {
            $trip = $pdo->prepare(
                "SELECT t.trip_id, t.status, t.shift, t.trip_type, t.scheduled_departure,
                        dr.submitted_by
                 FROM trips t
                 JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
                 WHERE t.trip_id = ?
                 FOR UPDATE"
            );
            $trip->execute([$tripId]);
            $tripRow = $trip->fetch(PDO::FETCH_ASSOC);
            if (!$tripRow || (int)$tripRow['submitted_by'] !== currentUserId()) {
                $pdo->rollBack();
                jsonFail('You may request an allowance only for a trip you submitted.', 403);
            }
            if ($tripRow['status'] !== 'Assigned') {
                throw new DomainException('The trip must have an approved and confirmed assignment before requesting an allowance.');
            }
            $scopeViolation = dispatcherScopeViolation(
                (string)($tripRow['shift'] ?? ''),
                (string)$tripRow['trip_type'],
                date('Y-m-d', strtotime((string)$tripRow['scheduled_departure']))
            );
            if ($scopeViolation !== null) {
                $pdo->rollBack();
                jsonFail($scopeViolation, 403);
            }
            $existingRequest = $pdo->prepare(
                "SELECT fund_request_id
                 FROM fund_requests
                 WHERE trip_id = ? AND request_type = 'Trip Allowance'
                   AND status IN ('Pending Approval', 'Approved', 'Processing', 'Disbursed', 'Settled')
                 LIMIT 1
                 FOR UPDATE"
            );
            $existingRequest->execute([$tripId]);
            if ($existingRequest->fetchColumn()) {
                throw new DomainException('An active Trip Allowance request already exists for this trip.');
            }
        }
        $number = 'FR-' . date('YmdHis') . '-' . random_int(100, 999);
        // The target has no notes column; notes follow the purpose.
        $fullPurpose = $purpose . ($notes !== null ? "\n" . $notes : '');
        $pdo->prepare(
            "INSERT INTO fund_requests
             (request_number, trip_id, requested_by, request_type, purpose, amount, status, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending Approval', NOW())"
        )->execute([$number, $tripId, currentUserId(), $type, $fullPurpose, $amount]);
        $requestId = (int)$pdo->lastInsertId();
        $approvalId = createApprovalRequest($pdo, 'fund_request', 'fund_request', $requestId, currentUserId(), $notes);
        auditLog('CREATE', 'fund_requests', $requestId, null, [
            'request_number' => $number, 'request_type' => $type, 'amount' => $amount, 'approval_request_id' => $approvalId,
        ]);
        $pdo->commit();
        jsonOk(['fund_request_id' => $requestId, 'request_number' => $number], 'Fund request submitted for approval.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) jsonFail($e->getMessage(), 409);
        error_log('finance_handler/create_fund_request: ' . $e->getMessage());
        jsonFail('Could not create fund request.', 500);
    }
}

if ($action === 'create_disbursement') {
    requirePermission('finance.disbursements.manage');
    $requestId = ($_POST['fund_request_id'] ?? '') !== '' ? requiredInt('fund_request_id', 'Fund request', 1) : null;
    $voucherId = ($_POST['payment_voucher_id'] ?? '') !== '' ? requiredInt('payment_voucher_id', 'Payment voucher', 1) : null;
    $payee = requiredString('payee_name', 'Payee', 150);
    $amount = financeAmount('amount', 'Disbursement amount');
    $rawMode = (string)($_POST['payment_mode'] ?? '');
    if (!in_array($rawMode, PAYMENT_MODES, true)) {
        // Legacy free-text modes (e.g. GCash) are recorded as 'Other'.
        $walletNote = $rawMode !== '' ? $rawMode : null;
        $_POST['payment_mode'] = 'Other';
    }
    $mode = requiredEnum('payment_mode', PAYMENT_MODES, 'Payment mode');
    $date = requiredDate('disbursed_at', 'Disbursement date', true);
    $reference = optionalString('reference_no', null, 100);
    $notes = optionalString('notes', null, 200);
    // The target links every disbursement to a source record.
    if (($requestId === null) === ($voucherId === null)) {
        jsonFail('Link a disbursement to exactly one approved fund request or payment voucher.');
    }
    $transactionReference = implode(' — ', array_filter([
        'Payee: ' . $payee,
        isset($walletNote) ? 'Via ' . $walletNote : null,
        $reference,
        $notes,
    ], static fn($v) => $v !== null && $v !== ''));
    try {
        $pdo->beginTransaction();
        $paid = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE reference_type = ? AND reference_id = ?'
        );
        if ($voucherId !== null) {
            $voucher = $pdo->prepare(
                "SELECT voucher_id, reference_id AS ap_id, amount, status FROM payment_vouchers
                 WHERE voucher_id = ? AND reference_type = 'accounts_payable' FOR UPDATE"
            );
            $voucher->execute([$voucherId]);
            $voucherRow = $voucher->fetch(PDO::FETCH_ASSOC);
            if (!$voucherRow || $voucherRow['status'] !== 'Approved') {
                throw new DomainException('Only approved payment vouchers can be disbursed.');
            }
            $paid->execute(['payment_voucher', $voucherId]);
            $remaining = round((float)$voucherRow['amount'] - (float)$paid->fetchColumn(), 2);
            $referenceType = 'payment_voucher';
            $referenceId = $voucherId;
        } else {
            $request = $pdo->prepare(
                "SELECT fund_request_id, amount, status FROM fund_requests WHERE fund_request_id = ? FOR UPDATE"
            );
            $request->execute([$requestId]);
            $fund = $request->fetch(PDO::FETCH_ASSOC);
            if (!$fund || !in_array($fund['status'], ['Approved', 'Processing'], true)) {
                throw new DomainException('Only approved fund requests can be disbursed.');
            }
            $paid->execute(['fund_request', $requestId]);
            $remaining = round((float)$fund['amount'] - (float)$paid->fetchColumn(), 2);
            $referenceType = 'fund_request';
            $referenceId = $requestId;
        }
        if ($amount > $remaining) {
            throw new DomainException('Disbursement exceeds the remaining approved amount.');
        }
        $pdo->prepare(
            'INSERT INTO disbursements
             (reference_type, reference_id, payment_method, amount, payment_date, check_number,
              transaction_reference, processed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $referenceType, $referenceId, $mode, $amount, $date,
            $mode === 'Check' ? $reference : null,
            mb_substr($transactionReference, 0, 150, 'UTF-8'),
            currentUserId(),
        ]);
        $disbursementId = (int)$pdo->lastInsertId();
        $paid->execute([$referenceType, $referenceId]);
        $totalPaid = (float)$paid->fetchColumn();
        if ($referenceType === 'fund_request') {
            $newStatus = $totalPaid >= (float)$fund['amount'] ? 'Disbursed' : 'Processing';
            $pdo->prepare(
                'UPDATE fund_requests SET status = ?, processed_at = NOW(), processed_by = ? WHERE fund_request_id = ?'
            )->execute([$newStatus, currentUserId(), $requestId]);
        } else {
            if ($totalPaid >= (float)$voucherRow['amount']) {
                $pdo->prepare("UPDATE payment_vouchers SET status = 'Paid' WHERE voucher_id = ?")->execute([$voucherId]);
            }
            // Payable status from everything disbursed against its vouchers.
            $pdo->prepare(
                "UPDATE accounts_payable ap
                 SET ap.status = CASE
                   WHEN ap.status = 'Cancelled' THEN 'Cancelled'
                   WHEN (
                     SELECT COALESCE(SUM(d.amount), 0)
                     FROM disbursements d
                     JOIN payment_vouchers pv ON pv.voucher_id = d.reference_id
                     WHERE d.reference_type = 'payment_voucher'
                       AND pv.reference_type = 'accounts_payable' AND pv.reference_id = ap.ap_id
                   ) >= ap.amount THEN 'Paid'
                   ELSE 'Partially Paid'
                 END
                 WHERE ap.ap_id = ?"
            )->execute([(int)$voucherRow['ap_id']]);
        }
        $number = sprintf('DISB-%06d', $disbursementId);
        auditLog('CREATE', 'disbursements', $disbursementId, null, [
            'disbursement_number' => $number, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'amount' => $amount,
        ]);
        $pdo->commit();
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
    $supplier = requiredString('supplier_name', 'Supplier', 180);
    $invoice = optionalString('invoice_number', null, 100);
    $description = requiredString('description', 'Description', 255);
    $amount = financeAmount('amount', 'Amount');
    $dueDate = optionalString('due_date', null, 10);
    if ($dueDate !== null && !isValidDate($dueDate)) jsonFail('Supplier, description, amount, and a valid due date are required.');
    try {
        $pdo->beginTransaction();
        $supplierId = supplierIdForName($pdo, $supplier, 'accounts_payable_form');
        $pdo->prepare(
            "INSERT INTO accounts_payable (supplier_id, reference_number, description, amount, due_date, status)
             VALUES (?, ?, ?, ?, ?, 'Open')"
        )->execute([$supplierId, $invoice, $description, $amount, $dueDate]);
        $apId = (int)$pdo->lastInsertId();
        $number = sprintf('AP-%06d', $apId);
        auditLog('CREATE', 'accounts_payable', $apId, null, ['payable_number' => $number, 'amount' => $amount]);
        $pdo->commit();
        jsonOk(['payable_number' => $number], 'Accounts payable record created.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('finance_handler/create_payable: ' . $e->getMessage());
        jsonFail('Could not create accounts payable record.', 500);
    }
}

if ($action === 'create_voucher') {
    requirePermission('finance.ap.manage');
    $payableId = requiredInt('payable_id', 'Accounts payable', 1);
    $amount = financeAmount('amount', 'Voucher amount');
    $notes = optionalString('notes', null, 2000);
    try {
        $pdo->beginTransaction();
        $payable = $pdo->prepare(
            "SELECT ap.ap_id, ap.amount, ap.status, ap.description, s.supplier_name
             FROM accounts_payable ap
             LEFT JOIN suppliers s ON s.supplier_id = ap.supplier_id
             WHERE ap.ap_id = ? FOR UPDATE"
        );
        $payable->execute([$payableId]);
        $payableRow = $payable->fetch(PDO::FETCH_ASSOC);
        if (!$payableRow || !in_array($payableRow['status'], ['Open', 'Partially Paid', 'Overdue'], true)) {
            throw new DomainException('Voucher must reference an open payable.');
        }
        $reserved = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_vouchers
             WHERE reference_type = 'accounts_payable' AND reference_id = ?
               AND status NOT IN ('Rejected', 'Cancelled')"
        );
        $reserved->execute([$payableId]);
        $remaining = round((float)$payableRow['amount'] - (float)$reserved->fetchColumn(), 2);
        if ($amount > $remaining) {
            throw new DomainException('Voucher amount exceeds the remaining payable balance.');
        }
        $number = 'PV-' . date('YmdHis') . '-' . random_int(100, 999);
        $pdo->prepare(
            "INSERT INTO payment_vouchers
                (voucher_number, payee, purpose, amount, reference_type, reference_id, status, prepared_by)
             VALUES (?, ?, ?, ?, 'accounts_payable', ?, 'Pending Approval', ?)"
        )->execute([
            $number,
            mb_substr((string)($payableRow['supplier_name'] ?? 'Supplier'), 0, 180, 'UTF-8'),
            $notes ?? $payableRow['description'],
            $amount,
            $payableId,
            currentUserId(),
        ]);
        $voucherId = (int)$pdo->lastInsertId();
        $approvalId = createApprovalRequest($pdo, 'payment_voucher', 'payment_voucher', $voucherId, currentUserId(), $notes);
        auditLog('CREATE', 'payment_vouchers', $voucherId, null, [
            'voucher_number' => $number, 'amount' => $amount, 'approval_request_id' => $approvalId,
        ]);
        $pdo->commit();
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
        "SELECT fr.request_number, fr.request_type, fr.purpose, fr.amount, fr.status,
                COALESCE(fr.submitted_at, fr.created_at) AS requested_at,
                COALESCE(e.full_name, u.username) AS requester
         FROM fund_requests fr JOIN users u ON u.user_id = fr.requested_by
         LEFT JOIN employees e ON e.employee_id = u.employee_id
         ORDER BY fr.created_at DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    $disbursements = $pdo->query(
        "SELECT CONCAT('DISB-', LPAD(disbursement_id, 6, '0')) AS disbursement_number,
                transaction_reference AS payee_name, amount, payment_method AS payment_mode,
                payment_date AS disbursed_at
         FROM disbursements ORDER BY payment_date DESC, disbursement_id DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    $payables = $pdo->query(
        "SELECT ap.ap_id AS payable_id, CONCAT('AP-', LPAD(ap.ap_id, 6, '0')) AS payable_number,
                s.supplier_name, ap.reference_number AS invoice_number, ap.description, ap.amount,
                ap.due_date, ap.status
         FROM accounts_payable ap LEFT JOIN suppliers s ON s.supplier_id = ap.supplier_id
         ORDER BY ap.due_date IS NULL, ap.due_date ASC, ap.ap_id DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    $vouchers = $pdo->query(
        "SELECT pv.voucher_id, pv.voucher_number, CONCAT('AP-', LPAD(pv.reference_id, 6, '0')) AS payable_number,
                pv.amount, pv.status
         FROM payment_vouchers pv
         WHERE pv.reference_type = 'accounts_payable'
         ORDER BY pv.voucher_id DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    jsonOk(['fund_requests' => $funds, 'disbursements' => $disbursements, 'payables' => $payables, 'vouchers' => $vouchers]);
}

jsonFail('Unknown action.');
