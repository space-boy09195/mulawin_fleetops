<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/crew_trip_access.php';
require_once __DIR__ . '/../includes/trip_queries.php';
require_once __DIR__ . '/../includes/trip_workflow.php';

// trips.view, or a Helper limited to trips assigned to them.
$helperEmployeeId = tripPageAccess(['trips.view']);

$tripId = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
if (!$tripId) {
    http_response_code(400);
    exit('Invalid trip.');
}
if ($helperEmployeeId !== null) {
    requireHelperAssignedToTrip((int)$tripId, $helperEmployeeId);
}

$pdo = getDBConnection();
$tripQuery = $pdo->prepare(tripSelectSql() . ' WHERE t.trip_id = ?');
$tripQuery->execute([$tripId]);
$trip = $tripQuery->fetch(PDO::FETCH_ASSOC);
if (!$trip) {
    http_response_code(404);
    exit('Trip not found.');
}

// The workflow is tracked for every trip with a dispatch request (target
// trips are always created through one, D2).
$workflow = $trip['dispatch_id'] !== null;
$events = [];
$eventSteps = [];
$documents = 0;
$checklistResult = null;
$departureSummary = ['required' => 0, 'covered' => 0, 'flagged' => 0, 'completed_inspections' => 0];
$arrivalSummary = $departureSummary;
$approvedAllowances = [];
$tripAllowanceRequests = [];
$activeTripAllowanceRequest = null;
$deliveryDetails = null;
$packetDetails = null;

if ($workflow) {
    $eventQuery = $pdo->prepare(
        "SELECT e.workflow_event_id, e.event_type AS event_key, e.remarks AS details,
                e.created_at AS event_at,
                COALESCE(ae.full_name, u.username, 'System') AS actor_name
         FROM workflow_events e
         LEFT JOIN users u ON u.user_id = e.performed_by
         LEFT JOIN employees ae ON ae.employee_id = u.employee_id
         WHERE e.entity_type = 'trip' AND e.entity_id = ?
         ORDER BY e.created_at, e.workflow_event_id"
    );
    $eventQuery->execute([$tripId]);
    $events = $eventQuery->fetchAll(PDO::FETCH_ASSOC);
    foreach ($events as &$event) {
        $step = tripWorkflowStepForKey((string)$event['event_key']);
        $event['step_number'] = $step;
        if ($step !== null && $step > 0) {
            $eventSteps[$step] = true;
        } elseif ($step === 0) {
            $eventSteps[0] = true;
        }
        if ($event['event_key'] === 'departure-packet-prepared') {
            $decoded = json_decode((string)$event['details'], true);
            if (is_array($decoded)) {
                $packetDetails = $decoded;
                $event['details'] = 'Documents: ' . ($decoded['documents_status'] ?? '—')
                    . (!empty($decoded['documents_notes']) ? ' — ' . $decoded['documents_notes'] : '')
                    . '. Allowance: ' . ($decoded['allowance_status'] ?? '—')
                    . (!empty($decoded['allowance_notes']) ? ' — ' . $decoded['allowance_notes'] : '')
                    . '.';
            }
        }
    }
    unset($event);

    $documentQuery = $pdo->prepare(
        "SELECT COUNT(*)
         FROM document_links dl
         JOIN documents d ON d.document_id = dl.document_id
         WHERE dl.entity_type = 'trip' AND dl.entity_id = ?
           AND COALESCE(d.visibility_scope, 'all') IN ('all', 'operations')"
    );
    $documentQuery->execute([$tripId]);
    $documents = (int)$documentQuery->fetchColumn();

    // The target merges the legacy pre-trip checklist and the departure
    // inspection into departure vehicle inspections (inspection templates).
    $departureSummary = tripInspectionSummary($pdo, $tripId, 'Departure');
    $arrivalSummary = tripInspectionSummary($pdo, $tripId, 'Arrival');
    if ($departureSummary['completed_inspections'] > 0) {
        $checklistResult = tripDepartureChecksPassed($pdo, $tripId) ? 'Passed' : 'Incomplete or flagged';
    }

    $allowanceQuery = $pdo->prepare(
        "SELECT fund_request_id, request_number, purpose, amount, status
         FROM fund_requests
         WHERE trip_id = ? AND request_type = 'Trip Allowance'
         ORDER BY created_at DESC"
    );
    $allowanceQuery->execute([$tripId]);
    $tripAllowanceRequests = $allowanceQuery->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tripAllowanceRequests as $allowanceRequest) {
        if (in_array($allowanceRequest['status'], ['Approved', 'Processing', 'Disbursed', 'Settled'], true)) {
            $approvedAllowances[] = $allowanceRequest;
        }
        if ($activeTripAllowanceRequest === null
            && in_array($allowanceRequest['status'], ['Pending Approval', 'Approved', 'Processing', 'Disbursed', 'Settled'], true)) {
            $activeTripAllowanceRequest = $allowanceRequest;
        }
    }

    $deliveryQuery = $pdo->prepare('SELECT * FROM trip_deliveries WHERE trip_id = ?');
    $deliveryQuery->execute([$tripId]);
    $returnQuery = $pdo->prepare('SELECT * FROM trip_returns WHERE trip_id = ?');
    $returnQuery->execute([$tripId]);
    $deliveryDetails = tripDeliveryFormValues(
        $deliveryQuery->fetch(PDO::FETCH_ASSOC) ?: null,
        $returnQuery->fetch(PDO::FETCH_ASSOC) ?: null
    );
}

$isDispatcher = isAdmin()
    || (int)$trip['requested_by'] === currentUserId();
$canDispatchWorkflow = $isDispatcher && currentUserHasAnyPermission(['trips.assign']);
$canRequestTripAllowance = $canDispatchWorkflow
    && currentUserHasAnyPermission(['finance.trip_allowance.request']);
$canUpdateWorkflow = $isDispatcher && currentUserHasAnyPermission(['trips.update']);
$canClear = currentUserHasAnyPermission(['dispatch.clear']);
$currentStep = $workflow ? tripWorkflowCurrentStep($pdo, $tripId) : 0;

$canReassign = $canDispatchWorkflow && $currentStep === 4 && $trip['status'] === 'Approved';
$reassignTrucks = [];
$reassignDrivers = [];
$reassignHelpers = [];
if ($canReassign) {
    // Include the currently assigned truck even though it is 'Assigned' to
    // this trip, so the current selection still shows up in the dropdown.
    // Replacement trucks must be the same type as the trip.
    $reassignTrucks = $pdo->prepare(
        "SELECT truck_id, plate_number, brand, model
         FROM trucks
         WHERE (status = 'Available' AND truck_type = ?) OR truck_id = ?
         ORDER BY plate_number"
    );
    $reassignTrucks->execute([(string)$trip['trip_type'], (int)$trip['truck_id']]);
    $reassignTrucks = $reassignTrucks->fetchAll(PDO::FETCH_ASSOC);

    $reassignDrivers = $pdo->query(
        "SELECT employee_id, full_name FROM employees
         WHERE employment_status = 'Active' AND license_number IS NOT NULL
         ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $reassignHelpers = $pdo->query(
        "SELECT employee_id, full_name FROM employees
         WHERE employment_status = 'Active' ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

$steps = [
    1 => 'Dispatcher receives or prepares a trip request.',
    2 => 'Dispatcher checks whether a truck and driver are available.',
    3 => 'Dispatcher enters the trip details.',
    4 => 'Trip request is submitted to the Operations Head for approval.',
    5 => 'Once approved, dispatcher confirms the truck and driver assignment.',
    6 => 'Required trip documents and allowances are prepared.',
    7 => 'Pre-departure checklist is completed.',
    8 => 'Operations Head provides dispatch clearance.',
    9 => 'Truck is dispatched.',
    10 => 'Dispatcher records trip progress and relevant updates.',
    11 => 'Delivery and return details are entered.',
    12 => 'Arrival checklist is completed.',
    13 => 'Dispatcher submits the completed trip record.',
];

$GLOBALS['page_js'] = APP_BASE . '/assets/js/trip_workflow.js';
layoutHead('Trip Workflow', APP_BASE . '/assets/css/trip_monitor.css');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title">13-Step Trip Workflow</h1>
    <p class="page-subtitle">
      <?= htmlspecialchars($trip['trip_number']) ?> · <?= htmlspecialchars($trip['client_name'] ?? 'Client not recorded') ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/trip_pre_advice.php?trip_id=<?= $tripId ?>" target="_blank" rel="noopener">
      <i class="bi bi-printer me-1"></i>Print Pre-Advice
    </a>
    <a class="btn btn-outline-secondary" href="<?= APP_BASE ?>/pages/trip_monitor.php">
      <i class="bi bi-arrow-left me-1"></i>Trip Monitoring
    </a>
  </div>
</div>

<div class="card p-3 mb-4">
  <div class="row g-3">
    <div class="col-md-3"><strong>Status:</strong> <?= htmlspecialchars($trip['status']) ?></div>
    <div class="col-md-3"><strong>Truck:</strong> <?= htmlspecialchars($trip['plate_number']) ?></div>
    <div class="col-md-3"><strong>Driver:</strong> <?= htmlspecialchars($trip['driver_name']) ?></div>
    <div class="col-md-3"><strong>Route:</strong> <?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></div>
  </div>
</div>

<?php if (!$workflow): ?>
<div class="alert alert-info">
  This trip was created before the 13-step workflow was enabled. Its previous history is not being reconstructed; existing trip status and inspection safeguards remain in effect.
</div>
<?php else: ?>
<div class="row g-4">
  <div class="col-xl-7">
    <div class="card p-3">
      <h2 class="h5">Workflow progress</h2>
      <ol class="list-group list-group-numbered">
        <?php foreach ($steps as $number => $label): ?>
        <?php $complete = isset($eventSteps[$number]); ?>
        <li class="list-group-item d-flex justify-content-between align-items-start gap-3">
          <span><?= htmlspecialchars($label) ?></span>
          <span class="badge <?= $complete ? 'text-bg-success' : 'text-bg-secondary' ?>">
            <?= $complete ? 'Complete' : ($number === $currentStep + 1 ? 'Next' : 'Pending') ?>
          </span>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php if (isset($eventSteps[0])): ?>
      <div class="alert alert-warning mt-3 mb-0">This trip was cancelled.</div>
      <?php endif; ?>
    </div>

    <div class="card p-3 mt-4">
      <h2 class="h5">Recorded events</h2>
      <?php if (!$events): ?>
      <p class="text-muted mb-0">No workflow events have been recorded.</p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Step</th><th>Event</th><th>Recorded by</th><th>Time</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($events) as $event): ?>
          <tr>
            <td><?= $event['step_number'] === null ? '—' : ((int)$event['step_number'] === 0 ? 'Cancelled' : (int)$event['step_number']) ?></td>
            <td>
              <?= htmlspecialchars($event['details'] ?? $event['event_key']) ?>
              <?php if ($event['step_number'] === 10): ?>
              <div class="small text-muted"><?= htmlspecialchars($event['event_key']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($event['actor_name']) ?></td>
            <td><?= date('M j, Y g:i A', strtotime($event['event_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-5">
    <?php if ($canReassign): ?>
    <div class="card p-3 mb-4">
      <h2 class="h5">Correction · Reassign truck, crew, or schedule</h2>
      <p class="text-muted">Use this before confirming the assignment if the truck, driver, or schedule needs to change. The new selections are re-checked for availability and conflicts.</p>
      <form class="trip-workflow-form" id="reassignForm">
        <input type="hidden" name="action" value="reassign_resources">
        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
        <div class="mb-3">
          <label class="form-label" for="reassignTruck">Truck</label>
          <select class="form-select" id="reassignTruck" name="truck_id" required>
            <?php foreach ($reassignTrucks as $t): ?>
            <option value="<?= $t['truck_id'] ?>" <?= (int)$t['truck_id'] === (int)$trip['truck_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($t['plate_number']) ?> — <?= htmlspecialchars($t['brand'] . ' ' . $t['model']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="reassignDriver">Driver</label>
          <select class="form-select" id="reassignDriver" name="driver_id" required>
            <?php foreach ($reassignDrivers as $d): ?>
            <option value="<?= $d['employee_id'] ?>" <?= (int)$d['employee_id'] === (int)$trip['driver_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($d['full_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="reassignSecondDriver">Second driver <span class="text-muted">(optional)</span></label>
          <select class="form-select" id="reassignSecondDriver" name="second_driver_id">
            <option value="">— None —</option>
            <?php foreach ($reassignDrivers as $d): ?>
            <option value="<?= $d['employee_id'] ?>" <?= (int)$d['employee_id'] === (int)($trip['second_driver_id'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars($d['full_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="reassignHelper">Helper <span class="text-muted">(optional)</span></label>
          <select class="form-select" id="reassignHelper" name="helper_id">
            <option value="">— None —</option>
            <?php foreach ($reassignHelpers as $h): ?>
            <option value="<?= $h['employee_id'] ?>" <?= (int)$h['employee_id'] === (int)($trip['helper_id'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars($h['full_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="reassignScheduledAt">Scheduled departure</label>
          <input type="datetime-local" class="form-control" id="reassignScheduledAt" name="scheduled_at"
                 value="<?= $trip['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($trip['scheduled_at'])) : '' ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="reassignExpectedArrival">Expected arrival <span class="text-muted">(optional)</span></label>
          <input type="datetime-local" class="form-control" id="reassignExpectedArrival" name="expected_arrival"
                 value="<?= $trip['expected_arrival'] ? date('Y-m-d\TH:i', strtotime($trip['expected_arrival'])) : '' ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="reassignReason">Reason for this change</label>
          <textarea class="form-control" id="reassignReason" name="reassign_reason" rows="2" required
                    placeholder="e.g. Original truck went unavailable for maintenance"></textarea>
        </div>
        <button class="btn btn-outline-primary" type="submit">Save changes</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($canDispatchWorkflow && $currentStep === 4 && $trip['status'] === 'Approved'): ?>
    <div class="card p-3 mb-4">
      <h2 class="h5">Step 5 · Confirm assignment</h2>
      <p class="text-muted">The approved truck and driver are reserved for this trip.</p>
      <form class="trip-workflow-form">
        <input type="hidden" name="action" value="confirm_assignment">
        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
        <button class="btn btn-primary" type="submit">Confirm truck and driver</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($canDispatchWorkflow && $currentStep === 5 && $trip['status'] === 'Assigned'): ?>
    <div class="card p-3 mb-4">
      <h2 class="h5">Step 6 · Prepare documents and allowance</h2>
      <p class="text-muted">No document categories are mandatory. “Prepared” requires at least one uploaded trip document.</p>
      <?php if ($canRequestTripAllowance): ?>
      <div class="border rounded p-3 mb-3">
        <h3 class="h6">Trip Allowance Request</h3>
        <p class="small text-muted">Submit a Trip Allowance request for this trip. It remains subject to the existing approval process; this does not grant access to cash advances, operating funds, or disbursement actions.</p>
        <?php if ($tripAllowanceRequests): ?>
        <ul class="small mb-3">
          <?php foreach ($tripAllowanceRequests as $allowanceRequest): ?>
          <li>
            <?= htmlspecialchars($allowanceRequest['request_number']) ?> ·
            <?= htmlspecialchars($allowanceRequest['status']) ?> ·
            ₱<?= number_format((float)$allowanceRequest['amount'], 2) ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($activeTripAllowanceRequest): ?>
        <div class="alert alert-info py-2 mb-0">
          An active allowance request already exists. Record departure preparation after its approval or rejection.
        </div>
        <?php else: ?>
        <form id="tripAllowanceRequestForm">
          <input type="hidden" name="action" value="create_fund_request">
          <input type="hidden" name="request_type" value="Trip Allowance">
          <input type="hidden" name="trip_id" value="<?= $tripId ?>">
          <div class="mb-3">
            <label class="form-label" for="tripAllowancePurpose">Purpose</label>
            <input class="form-control" id="tripAllowancePurpose" name="purpose" maxlength="255" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="tripAllowanceAmount">Amount (₱)</label>
            <input class="form-control" id="tripAllowanceAmount" name="amount" type="number" min="0.01" step="0.01" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="tripAllowanceNotes">Notes <span class="text-muted">(optional)</span></label>
            <textarea class="form-control" id="tripAllowanceNotes" name="notes" rows="2" maxlength="2000"></textarea>
          </div>
          <button class="btn btn-outline-primary" type="submit">Submit allowance request</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <p><a href="<?= APP_BASE ?>/pages/documents.php?trip_id=<?= $tripId ?>">Upload or review trip documents</a> (<?= $documents ?> linked)</p>
      <form class="trip-workflow-form" id="departurePreparationForm">
        <input type="hidden" name="action" value="prepare_departure">
        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
        <div class="mb-3">
          <label class="form-label" for="documentsStatus">Trip-document packet</label>
          <select class="form-select" id="documentsStatus" name="documents_status" required>
            <option value="Prepared">Prepared — at least one trip document uploaded</option>
            <option value="Not Required">No additional documents required</option>
          </select>
        </div>
        <div class="mb-3 d-none" id="documentsNotesGroup">
          <label class="form-label" for="documentsNotes">Why are no additional documents required?</label>
          <textarea class="form-control" id="documentsNotes" name="documents_notes" rows="2" maxlength="2000"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label" for="allowanceStatus">Trip allowance</label>
          <select class="form-select" id="allowanceStatus" name="allowance_status" required>
            <?php if ($activeTripAllowanceRequest): ?>
            <option value="" selected disabled>Awaiting allowance decision</option>
            <?php endif; ?>
            <option value="Not Required" <?= $activeTripAllowanceRequest ? 'disabled' : '' ?>>Not required</option>
            <option value="Approved">Approved or disbursed fund request</option>
          </select>
        </div>
        <div class="mb-3 d-none" id="fundRequestGroup">
          <label class="form-label" for="fundRequestId">Approved Trip Allowance request</label>
          <select class="form-select" id="fundRequestId" name="fund_request_id">
            <option value="">Select an approved request</option>
            <?php foreach ($approvedAllowances as $fundRequest): ?>
            <option value="<?= (int)$fundRequest['fund_request_id'] ?>">
              <?= htmlspecialchars($fundRequest['request_number'] . ' · ' . $fundRequest['status'] . ' · ' . number_format((float)$fundRequest['amount'], 2)) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$approvedAllowances): ?>
          <small class="text-muted">No approved or disbursed Trip Allowance request is linked to this trip.</small>
          <?php endif; ?>
        </div>
        <div class="mb-3 d-none" id="allowanceNotesGroup">
          <label class="form-label" for="allowanceNotes">Why is an allowance not required?</label>
          <textarea class="form-control" id="allowanceNotes" name="allowance_notes" rows="2" maxlength="2000"></textarea>
        </div>
        <button class="btn btn-primary" type="submit">Record departure preparation</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card p-3 mb-4">
      <h2 class="h5">Step 7 · Pre-departure checks</h2>
      <p class="mb-1">Departure checks: <strong><?= htmlspecialchars($checklistResult ?? 'Not submitted') ?></strong></p>
      <?php if ($departureSummary['required'] === 0): ?>
      <p class="mb-1 text-danger">No active departure inspection template is configured. Departure stays blocked until an inspection template is set up.</p>
      <?php else: ?>
      <p class="mb-1">Departure inspection: <strong><?= (int)$departureSummary['covered'] ?>/<?= (int)$departureSummary['required'] ?> required items passed</strong>
        <?= $departureSummary['covered'] >= $departureSummary['required'] ? '· All good' : '· Incomplete or needs attention' ?>
      </p>
      <?php endif; ?>
      <?php if (currentUserHasAnyPermission(['maintenance.manage'])): ?>
      <div class="mt-2">
        <a href="<?= APP_BASE ?>/pages/maintenance.php?trip_id=<?= $tripId ?>&amp;inspection_stage=Departure#inspectionModal">Open departure inspection</a>
      </div>
      <?php else: ?>
      <div class="small text-muted mt-2">Maintenance personnel complete these checks.</div>
      <?php endif; ?>
    </div>

    <?php if ($canClear && $currentStep === 7 && $trip['status'] === 'Ready for Dispatch'): ?>
    <div class="card p-3 mb-4">
      <h2 class="h5">Step 8 · Operations Head dispatch clearance</h2>
      <form class="trip-workflow-form">
        <input type="hidden" name="action" value="departure_clearance">
        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
        <label class="form-label" for="clearanceNotes">Clearance notes <span class="text-muted">(optional)</span></label>
        <textarea class="form-control mb-3" id="clearanceNotes" name="clearance_notes" rows="2" maxlength="2000"></textarea>
        <button class="btn btn-success" type="submit">Provide dispatch clearance</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($canUpdateWorkflow && $trip['status'] === 'In Progress' && $currentStep >= 9): ?>
    <div class="card p-3 mb-4">
      <h2 class="h5">Step 10 · Record trip progress</h2>
      <form class="trip-workflow-form">
        <input type="hidden" name="action" value="record_progress">
        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
        <label class="form-label" for="progressLocation">Current location</label>
        <input class="form-control mb-3" id="progressLocation" name="location_note" maxlength="255">
        <label class="form-label" for="progressNotes">Progress or incident update</label>
        <textarea class="form-control mb-3" id="progressNotes" name="notes" rows="3" maxlength="4000"></textarea>
        <button class="btn btn-primary" type="submit">Record progress update</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($canUpdateWorkflow && $trip['status'] === 'In Progress' && $currentStep >= 10): ?>
    <div class="card p-3 mb-4">
      <h2 class="h5">Step 11 · Delivery and return details</h2>
      <?php if ($deliveryDetails): ?>
      <div class="alert alert-success py-2">Details are recorded and may be updated until trip completion.</div>
      <?php endif; ?>
      <form class="trip-workflow-form">
        <input type="hidden" name="action" value="save_delivery_return">
        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
        <label class="form-label" for="deliveredUnitCount">Delivered unit count <span class="text-muted">(optional)</span></label>
        <input class="form-control mb-3" type="number" min="0" max="1000000" step="0.01"
               id="deliveredUnitCount" name="delivered_unit_count"
               value="<?= htmlspecialchars((string)($deliveryDetails['delivered_unit_count'] ?? '')) ?>">
        <label class="form-label" for="deliveryReceiptNumber">Delivery receipt number <span class="text-muted">(optional)</span></label>
        <input class="form-control mb-3" id="deliveryReceiptNumber" name="delivery_receipt_number" maxlength="100"
               value="<?= htmlspecialchars($deliveryDetails['delivery_receipt_number'] ?? '') ?>">
        <label class="form-label" for="sharedWaybill">Shared waybill reference <span class="text-muted">(optional; may be shared)</span></label>
        <input class="form-control mb-3" id="sharedWaybill" name="shared_waybill_reference" maxlength="100"
               value="<?= htmlspecialchars($deliveryDetails['shared_waybill_reference'] ?? '') ?>">
        <label class="form-label" for="coLoadReference">Co-load reference <span class="text-muted">(optional)</span></label>
        <input class="form-control mb-3" id="coLoadReference" name="co_load_reference" maxlength="100"
               value="<?= htmlspecialchars($deliveryDetails['co_load_reference'] ?? '') ?>">
        <label class="form-label" for="deliveredAt">Delivered at <span class="text-muted">(optional; defaults to now)</span></label>
        <input class="form-control mb-3" type="datetime-local" id="deliveredAt" name="delivered_at"
               max="<?= date('Y-m-d\TH:i') ?>">
        <label class="form-label" for="receivedBy">Received by <span class="text-muted">(optional)</span></label>
        <input class="form-control mb-3" id="receivedBy" name="received_by" maxlength="150"
               value="<?= htmlspecialchars($deliveryDetails['received_by'] ?? '') ?>">
        <label class="form-label" for="deliveryNotes">Delivery details</label>
        <textarea class="form-control mb-3" id="deliveryNotes" name="delivery_notes" rows="3" maxlength="4000" required><?= htmlspecialchars($deliveryDetails['delivery_notes'] ?? '') ?></textarea>
        <label class="form-label" for="returnLocation">Return location <span class="text-muted">(optional)</span></label>
        <input class="form-control mb-3" id="returnLocation" name="return_location" maxlength="255"
               value="<?= htmlspecialchars($deliveryDetails['return_location'] ?? '') ?>">
        <label class="form-label" for="returnNotes">Return details</label>
        <textarea class="form-control mb-3" id="returnNotes" name="return_notes" rows="3" maxlength="4000" required><?= htmlspecialchars($deliveryDetails['return_notes'] ?? '') ?></textarea>
        <button class="btn btn-primary" type="submit">Save delivery and return details</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card p-3">
      <h2 class="h5">Step 12 · Arrival checklist</h2>
      <?php if ($arrivalSummary['required'] === 0): ?>
      <p class="mb-1 text-danger">No active arrival inspection template is configured. Completion stays blocked until an inspection template is set up.</p>
      <?php else: ?>
      <p class="mb-1">Arrival inspection: <strong><?= (int)$arrivalSummary['covered'] ?>/<?= (int)$arrivalSummary['required'] ?> required items recorded</strong>
        <?= $arrivalSummary['flagged'] > 0 ? '· ' . (int)$arrivalSummary['flagged'] . ' flagged finding(s)' : '' ?></p>
      <?php endif; ?>
      <?php if (currentUserHasAnyPermission(['maintenance.manage'])): ?>
      <div class="mt-2"><a href="<?= APP_BASE ?>/pages/maintenance.php?trip_id=<?= $tripId ?>&amp;inspection_stage=Arrival#inspectionModal">Open arrival vehicle inspection</a></div>
      <?php else: ?>
      <div class="small text-muted mt-2">Maintenance personnel complete the return inspection.</div>
      <?php endif; ?>
      <?php if (in_array($trip['status'], ['Delivered', 'Returned'], true)): ?>
      <p class="text-muted small mt-2 mb-0">After the return inspection passes its completeness checks, submit the trip as Completed from Trip Monitoring to finish Step 13.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>
