<?php
// ============================================================
// ajax/billing_handler.php
// Billing and collections on the target schema:
//   billings       one per trip (uq_billing_trip), invoice_number (unique),
//                  billing_date, due_date, subtotal / tax_amount /
//                  total_amount, status Issued -> Partially Paid -> Paid
//   billing_items  the charge lines (one freight line per trip here)
//   collections    payments: amount, payment_date, payment_method (target
//                  ENUM), reference_number, received_by
// The balance is total_amount minus the sum of collections, computed inside
// the payment transaction with the billing row locked, so two payments
// cannot together exceed the balance.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';
require_once __DIR__ . '/../app/modules/finance/client_rates.php';

header('Content-Type: application/json');

requirePermission('billing.manage');
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();

$action = $_POST['action'] ?? '';

// ── Suggested charge from the client's rate card ─────────────────────────────
if ($action === 'suggest_amount') {
    $tripId = requiredInt('trip_id', 'Trip', 1);
    $trip = $pdo->prepare(
        'SELECT t.trip_id, t.client_id, t.route_id, t.trip_type, DATE(t.scheduled_departure) AS trip_date,
                r.distance, c.client_name
         FROM trips t
         JOIN clients c ON c.client_id = t.client_id
         LEFT JOIN routes r ON r.route_id = t.route_id
         WHERE t.trip_id = ?'
    );
    $trip->execute([$tripId]);
    $row = $trip->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonFail('Trip not found.', 404);
    }
    $rate = applicableClientRate(
        $pdo,
        (int)$row['client_id'],
        $row['route_id'] === null ? null : (int)$row['route_id'],
        (string)$row['trip_type'],
        (string)$row['trip_date']
    );
    $amount = $rate ? clientRateTripAmount($rate, $row['distance'] === null ? null : (float)$row['distance']) : null;
    jsonOk([
        'client_name' => $row['client_name'],
        'amount'      => $amount,
        'rate_type'   => $rate['rate_type'] ?? null,
        'rate_id'     => $rate ? (int)$rate['rate_id'] : null,
    ], $amount === null ? 'No applicable client rate; enter the amount.' : 'Suggested from the client rate card.');
}

// ── Create billing ────────────────────────────────────────────────────────────
if ($action === 'create_billing') {

    $tripId         = requiredInt('trip_id', 'Trip', 1);
    $clientName     = requiredString('client_name', 'Client', 180);
    $amount         = requiredPositiveFloat('amount', 'Amount');
    $dueDate        = requiredDate('due_date', 'Due date', true);
    // The target keeps one invoice number per billing: the client invoice
    // number when given, otherwise the internal billing number.
    $billingNumber  = requiredString('billing_number', 'Billing number', 80);
    $invoiceNumber  = optionalString('invoice_number', null, 80) ?? $billingNumber;
    $invoiceDateRaw = trim($_POST['invoice_date'] ?? '');
    $notes          = optionalString('notes', null, 200);
    if (round($amount, 2) !== $amount) {
        jsonFail('Amount may have at most two decimal places.');
    }

    $billingDate = date('Y-m-d');
    if ($invoiceDateRaw !== '') {
        if (!isValidDate($invoiceDateRaw)) {
            jsonFail('Invoice date must be a valid date.');
        }
        $billingDate = $invoiceDateRaw;
    }
    if ($dueDate < $billingDate) {
        jsonFail('The due date cannot be before the invoice date.');
    }

    $clientStmt = $pdo->prepare("SELECT client_id FROM clients WHERE client_name = ? AND status = 'Active'");
    $clientStmt->execute([$clientName]);
    $clientId = $clientStmt->fetchColumn();
    if (!$clientId) {
        jsonFail('Select a registered active client.');
    }

    // Unique invoice number
    if (existsWhere($pdo, 'billings', 'invoice_number', $invoiceNumber)) {
        jsonFail('Invoice number already exists.');
    }

    try {
        $pdo->beginTransaction();
        // Verify trip exists and is completed (locked).
        $tripQuery = $pdo->prepare('SELECT trip_id, trip_number, status, client_id FROM trips WHERE trip_id = ? FOR UPDATE');
        $tripQuery->execute([$tripId]);
        $trip = $tripQuery->fetch(PDO::FETCH_ASSOC);
        if (!$trip) {
            $pdo->rollBack();
            jsonFail('Trip not found.', 404);
        }
        if ($trip['status'] !== 'Completed') {
            $pdo->rollBack();
            jsonFail('Billings can only be created for completed trips.');
        }
        // The billed client must be the trip's client.
        if ((int)$trip['client_id'] !== (int)$clientId) {
            $pdo->rollBack();
            jsonFail('The selected client does not match the client of this trip.', 422);
        }

        $pdo->prepare("
            INSERT INTO billings
                (invoice_number, trip_id, client_id, billing_date, due_date,
                 subtotal, tax_amount, total_amount, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'Issued', ?)
        ")->execute([
            $invoiceNumber, $tripId, $clientId, $billingDate, $dueDate,
            $amount, $amount, currentUserId(),
        ]);
        $newId = (int)$pdo->lastInsertId();
        $description = 'Freight charge — ' . $trip['trip_number'] . ' (' . $billingNumber . ')'
            . ($notes !== null ? ' — ' . $notes : '');
        $pdo->prepare(
            'INSERT INTO billing_items (billing_id, description, quantity, unit_rate, amount)
             VALUES (?, ?, 1, ?, ?)'
        )->execute([$newId, mb_substr($description, 0, 255, 'UTF-8'), $amount, $amount]);

        auditLog('CREATE_BILLING', 'billings', $newId, null, [
            'billing_number' => $billingNumber,
            'invoice_number' => $invoiceNumber,
            'trip_id'        => $tripId,
            'client_id'      => (int)$clientId,
            'amount'         => $amount,
            'due_date'       => $dueDate,
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'Billing created successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            // uq_billing_trip / uq_invoice_number
            jsonFail('This trip is already billed, or the invoice number is already used.', 409);
        }
        error_log('billing_handler/create: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Record payment (collection) ───────────────────────────────────────────────
if ($action === 'record_payment') {

    $billingId  = requiredInt('billing_id', 'Billing', 1);
    $amountPaid = requiredPositiveFloat('amount_paid', 'Amount paid');
    $payDate    = requiredDate('payment_date', 'Payment date', true);
    $rawMode    = (string)($_POST['payment_mode'] ?? '');
    // Legacy 'GCash' has no target value: recorded as 'Other' with the
    // e-wallet named in the reference.
    $walletNote = null;
    if ($rawMode === 'GCash') {
        $_POST['payment_mode'] = 'Other';
        $walletNote = 'GCash';
    }
    $payMode    = requiredEnum('payment_mode', PAYMENT_MODES, 'Payment mode');
    $reference  = optionalString('reference_no', null, 100);
    $remarks    = optionalString('remarks', null, 100);
    if (round($amountPaid, 2) !== $amountPaid) {
        jsonFail('Amount may have at most two decimal places.');
    }
    // collections has no remarks column: wallet, reference and remarks share
    // reference_number (VARCHAR 150).
    $referenceText = implode(' — ', array_filter([$walletNote, $reference, $remarks], static fn($v) => $v !== null && $v !== ''));
    $referenceText = $referenceText === '' ? null : mb_substr($referenceText, 0, 150, 'UTF-8');

    try {
        $pdo->beginTransaction();
        $bilStmt = $pdo->prepare(
            'SELECT billing_id, client_id, total_amount, status FROM billings WHERE billing_id = ? FOR UPDATE'
        );
        $bilStmt->execute([$billingId]);
        $billing = $bilStmt->fetch(PDO::FETCH_ASSOC);
        if (!$billing) {
            $pdo->rollBack();
            jsonFail('Billing not found.', 404);
        }
        if (in_array($billing['status'], ['Paid', 'Cancelled', 'Draft'], true)) {
            $pdo->rollBack();
            jsonFail($billing['status'] === 'Paid' ? 'This billing is already fully paid.' : 'Payments can be recorded only for issued billings.');
        }
        $collectedQuery = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM collections WHERE billing_id = ?');
        $collectedQuery->execute([$billingId]);
        $balance = round((float)$billing['total_amount'] - (float)$collectedQuery->fetchColumn(), 2);
        if ($amountPaid > $balance) {
            $pdo->rollBack();
            jsonFail('Payment amount exceeds outstanding balance of ₱' . number_format($balance, 2) . '.');
        }

        $pdo->prepare("
            INSERT INTO collections
                (billing_id, client_id, amount, payment_date, payment_method, reference_number, received_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$billingId, (int)$billing['client_id'], $amountPaid, $payDate, $payMode, $referenceText, currentUserId()]);
        $collectionId = (int)$pdo->lastInsertId();

        $newBalance = round($balance - $amountPaid, 2);
        $newStatus  = $newBalance <= 0 ? 'Paid' : 'Partially Paid';
        $pdo->prepare('UPDATE billings SET status = ? WHERE billing_id = ?')->execute([$newStatus, $billingId]);

        auditLog('RECORD_PAYMENT', 'collections', $collectionId, ['status' => $billing['status'], 'balance' => $balance], [
            'billing_id'     => $billingId,
            'amount'         => $amountPaid,
            'payment_method' => $payMode,
            'new_status'     => $newStatus,
            'new_balance'    => $newBalance,
        ]);
        $pdo->commit();

        jsonOk([
            'new_status'  => $newStatus,
            'new_balance' => $newBalance,
        ], 'Payment recorded. Billing status: <strong>' . $newStatus . '</strong>.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('billing_handler/record_payment: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
