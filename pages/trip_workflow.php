<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/modules/trips/crew_trip_access.php';
require_once __DIR__ . '/../app/modules/trips/trip_queries.php';
require_once __DIR__ . '/../app/modules/trips/trip_workflow.php';
require_once __DIR__ . '/../app/modules/documents/document_upload.php';

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
requireTripInDispatcherScope($trip['shift'], $trip['truck_type']);

// The workflow is tracked for every trip with a dispatch request (target
// trips are always created through one, D2).
$workflow = $trip['dispatch_id'] !== null;
$events = [];
$eventSteps = [];
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
                $event['details'] = tripPreparationEventText($decoded);
            }
        }
    }
    unset($event);

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

// "What should I do next?" guide: the same conditions the step cards and
// handlers use. The Maintenance page hides its entry buttons from Admin and
// Operations Head, so they are not told they can inspect.
$nextAction = $workflow ? tripNextAction((string)$trip['status'], $currentStep, [
    'departure'         => $departureSummary,
    'arrival'           => $arrivalSummary,
    'pending_allowance' => ($activeTripAllowanceRequest['status'] ?? '') === 'Pending Approval'
        ? $activeTripAllowanceRequest['request_number'] : null,
    'allowance_departure' => tripAllowanceDepartureMessage($tripAllowanceRequests),
    'is_owner'          => (int)$trip['requested_by'] === currentUserId(),
    'is_admin'          => isAdmin(),
    'can_review'        => currentUserHasAnyPermission(['approvals.review']),
    'can_assign'        => currentUserHasAnyPermission(['trips.assign']),
    'can_update'        => currentUserHasAnyPermission(['trips.update']),
    'can_clear'         => $canClear,
    'can_inspect'       => currentUserHasAnyPermission(['maintenance.manage'])
        && !isAdmin() && currentRoleKey() !== 'operations_head',
]) : null;
// Guide links, shown only when the viewer may open the page.
$nextActionLinks = [
    'approvals'    => ['Approvals', '/pages/requests.php', 'approvals.review'],
    'pre_departure'     => ['Pre-Departure Inspection', '/pages/maintenance.php?view=pre_departure&trip_id=' . (int)$tripId, 'maintenance.view'],
    'return_inspection' => ['Return Inspection', '/pages/maintenance.php?view=return&trip_id=' . (int)$tripId . '&inspection_stage=Return#inspectionModal', 'maintenance.view'],
    'trip_monitor' => ['Trip Monitoring', '/pages/trip_monitor.php', 'trips.view'],
    'billing'      => ['Billing', '/pages/billing.php', 'billing.view'],
    'trip_pay'     => ['Trip Pay', '/pages/trip_pay.php', 'payroll.manage'],
];

// Step 6 attachments: documents linked to this trip that count for
// "Prepared" (visibility all / operations) and that this user may see.
$showDeparturePreparation = $canDispatchWorkflow && $currentStep === 5 && $trip['status'] === 'Assigned';
$tripAttachments = [];
if ($showDeparturePreparation) {
    $attachmentParams = [$tripId];
    $attachmentVisibility = documentVisibilitySql(documentVisibilityScopesForCurrentUser(), 'd', $attachmentParams);
    $attachmentQuery = $pdo->prepare(
        "SELECT d.document_id, d.document_category AS doc_type, f.original_name AS file_name, f.uploaded_at
         FROM document_links dl
         JOIN documents d ON d.document_id = dl.document_id
         JOIN files f ON f.file_id = d.file_id
         WHERE dl.entity_type = 'trip' AND dl.entity_id = ?
           AND COALESCE(d.visibility_scope, 'all') IN ('all', 'operations')
           $attachmentVisibility
         ORDER BY f.uploaded_at, d.document_id"
    );
    $attachmentQuery->execute($attachmentParams);
    $tripAttachments = $attachmentQuery->fetchAll(PDO::FETCH_ASSOC);
}
$canUploadAttachment = $showDeparturePreparation && currentUserHasAnyPermission(['documents.upload']);
$canDownloadAttachment = currentUserHasAnyPermission(['documents.download']);

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
    6 => 'Trip allowance decision is recorded; optional trip attachments are added.',
    7 => 'Pre-Departure Inspection is completed (pre-trip checklist and departure inspection).',
    8 => 'Operations Head provides dispatch clearance.',
    9 => 'Truck is dispatched.',
    10 => 'Dispatcher records trip progress and relevant updates.',
    11 => 'Delivery and return details are entered.',
    12 => 'Return inspection is completed.',
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
<details class="card p-3 mb-4" id="nextActionGuide" open>
  <summary class="h5 mb-0">What should I do next?</summary>
  <div class="mt-3">
    <p class="mb-2">
      <strong>Progress:</strong>
      <?= $currentStep > 0 ? 'Step ' . (int)$currentStep . ' of 13 complete.' : 'No steps recorded yet.' ?>
      <?php if ($nextAction['role'] !== null): ?>
      <strong class="ms-2">Next:</strong> <?= htmlspecialchars($nextAction['role']) ?>
      <?php endif; ?>
    </p>
    <?php if ($nextAction['role'] !== null): ?>
    <p class="mb-2"><strong>Where:</strong> <?= htmlspecialchars($nextAction['where']) ?></p>
    <?php if ($nextAction['can_act'] === 'yes'): ?>
    <p class="mb-2"><span class="badge text-bg-success">You can do this now.</span></p>
    <?php elseif ($nextAction['can_act'] === 'override'): ?>
    <p class="mb-2"><span class="badge text-bg-warning">You can act only as an Admin override (a reason will be requested).</span></p>
    <?php else: ?>
    <p class="mb-2"><span class="badge text-bg-secondary">Waiting for: <?= htmlspecialchars($nextAction['role']) ?></span></p>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($nextAction['note'] !== null): ?>
    <p class="mb-2"><?= htmlspecialchars($nextAction['note']) ?></p>
    <?php endif; ?>
    <?php if ($nextAction['blockers']): ?>
    <ul class="mb-2 text-danger small">
      <?php foreach ($nextAction['blockers'] as $blocker): ?>
      <li><?= htmlspecialchars($blocker) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php foreach ($nextAction['links'] as $linkKey): ?>
    <?php [$linkLabel, $linkPath, $linkPermission] = $nextActionLinks[$linkKey]; ?>
    <?php if (currentUserHasAnyPermission([$linkPermission])): ?>
    <a class="btn btn-sm btn-outline-primary me-2" href="<?= APP_BASE . htmlspecialchars($linkPath) ?>">Open <?= htmlspecialchars($linkLabel) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
</details>

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

    <?php if ($showDeparturePreparation): ?>
    <div class="card p-3 mb-4">
      <h2 class="h5">Step 6 · Prepare documents and allowance</h2>
      <p class="text-muted">Trip attachments are optional; no document type is currently required before departure. Step 6 records how many attachments are linked and the allowance decision.</p>
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
      <div class="border rounded p-3 mb-3" id="tripAttachments">
        <h3 class="h6">Attachments (<?= count($tripAttachments) ?>)</h3>
        <?php if (!$tripAttachments): ?>
        <p class="small text-muted mb-2">No attachments are linked to this trip yet.</p>
        <?php else: ?>
        <ul class="list-unstyled small mb-2">
          <?php foreach ($tripAttachments as $attachment): ?>
          <li class="mb-1">
            <span class="badge text-bg-success me-1">Uploaded</span>
            <?php if ($canDownloadAttachment): ?>
            <a href="<?= APP_BASE ?>/ajax/document_download.php?id=<?= (int)$attachment['document_id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($attachment['file_name']) ?></a>
            <?php else: ?>
            <?= htmlspecialchars($attachment['file_name']) ?>
            <?php endif; ?>
            <span class="text-muted">· <?= htmlspecialchars($attachment['doc_type']) ?> · <?= date('M j, Y g:i A', strtotime($attachment['uploaded_at'])) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <?php if ($canUploadAttachment): ?>
          <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#tripAttachmentModal">
            <i class="bi bi-paperclip me-1"></i>Upload Attachment
          </button>
          <?php endif; ?>
          <?php if (currentUserHasAnyPermission(['documents.view'])): ?>
          <a class="small" href="<?= APP_BASE ?>/pages/documents.php?trip_id=<?= $tripId ?>">Open in Documents</a>
          <?php endif; ?>
        </div>
        <p class="small text-muted mt-2 mb-0">Uploading does not complete Step 6. Choose the trip allowance below and click “Record departure preparation”.</p>
      </div>
      <form class="trip-workflow-form" id="departurePreparationForm">
        <input type="hidden" name="action" value="prepare_departure">
        <input type="hidden" name="trip_id" value="<?= $tripId ?>">
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
          <small class="d-block text-muted mt-1">Finance must record the full disbursement before the truck can depart.</small>
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
      <h2 class="h5">Step 7 · Pre-Departure Inspection</h2>
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
        <a href="<?= APP_BASE ?>/pages/maintenance.php?view=pre_departure&amp;trip_id=<?= $tripId ?>">Open Pre-Departure Inspection</a>
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
      <h2 class="h5">Step 12 · Return inspection</h2>
      <?php if ($arrivalSummary['required'] === 0): ?>
      <p class="mb-1 text-danger">No active arrival inspection template is configured. Completion stays blocked until an inspection template is set up.</p>
      <?php else: ?>
      <p class="mb-1">Arrival inspection: <strong><?= (int)$arrivalSummary['covered'] ?>/<?= (int)$arrivalSummary['required'] ?> required items recorded</strong>
        <?= $arrivalSummary['flagged'] > 0 ? '· ' . (int)$arrivalSummary['flagged'] . ' flagged finding(s)' : '' ?></p>
      <?php endif; ?>
      <?php if (currentUserHasAnyPermission(['maintenance.manage'])): ?>
      <div class="mt-2"><a href="<?= APP_BASE ?>/pages/maintenance.php?view=return&amp;trip_id=<?= $tripId ?>&amp;inspection_stage=Return#inspectionModal">Open Return Inspection</a></div>
      <?php else: ?>
      <div class="small text-muted mt-2">Maintenance personnel complete the return inspection.</div>
      <?php endif; ?>
      <?php if (in_array($trip['status'], ['Delivered', 'Returned'], true)): ?>
      <p class="text-muted small mt-2 mb-0">After the return inspection passes its completeness checks, submit the trip as Completed from Trip Monitoring to finish Step 13.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($canUploadAttachment): ?>
<!-- Step 6 attachment upload: posts to ajax/document_handler.php (action
     upload), the same handler, checks and records as the Documents page. -->
<div class="modal fade" id="tripAttachmentModal" tabindex="-1" aria-labelledby="tripAttachmentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tripAttachmentModalLabel"><i class="bi bi-paperclip me-2"></i>Upload Attachment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="tripAttachmentForm">
        <div class="modal-body">
          <div id="tripAttachmentAlert" class="alert alert-danger d-none" role="alert"></div>
          <p class="small text-muted">This file will be linked to <?= htmlspecialchars($trip['trip_number']) ?>.</p>
          <input type="hidden" name="trip_id" value="<?= $tripId ?>">
          <div class="mb-3">
            <label class="form-label" for="tripAttachmentFile">File</label>
            <input class="form-control" type="file" id="tripAttachmentFile" name="file" required
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
            <div class="form-text">PDF, JPG, PNG, DOC, DOCX, XLS or XLSX, up to 10 MB.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="tripAttachmentType">Document type</label>
            <select class="form-select" id="tripAttachmentType" name="doc_type" required>
              <option value="">— Choose a document type —</option>
              <?php foreach (DOCUMENT_TYPES as $documentType): ?>
              <option value="<?= htmlspecialchars($documentType) ?>"><?= htmlspecialchars($documentType) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-0">
            <label class="form-label" for="tripAttachmentDescription">Description <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="tripAttachmentDescription" name="description" maxlength="255">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="tripAttachmentSubmit">Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php layoutFoot(); ?>
