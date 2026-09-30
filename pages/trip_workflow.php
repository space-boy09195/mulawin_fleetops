<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('trips.view');

$tripId = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
if (!$tripId) {
    http_response_code(400);
    exit('Invalid trip.');
}

$pdo = getDBConnection();
$tripQuery = $pdo->prepare(
    'SELECT t.trip_id, t.dispatch_id, t.trip_number, t.status,
            dr.requested_by, dr.truck_id, dr.driver_id, dr.scheduled_at,
            dr.client_name, dr.unit_count, dr.waybill_reference,
            tr.plate_number, driver.full_name AS driver_name,
            route.origin, route.destination
     FROM trips t
     JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
     JOIN trucks tr ON tr.truck_id = dr.truck_id
     JOIN employees driver ON driver.employee_id = dr.driver_id
     JOIN routes route ON route.route_id = dr.route_id
     WHERE t.trip_id = ?'
);
$tripQuery->execute([$tripId]);
$trip = $tripQuery->fetch(PDO::FETCH_ASSOC);
if (!$trip) {
    http_response_code(404);
    exit('Trip not found.');
}

$stateQuery = $pdo->prepare('SELECT * FROM trip_workflow_state WHERE dispatch_id = ?');
$stateQuery->execute([(int)$trip['dispatch_id']]);
$workflow = $stateQuery->fetch(PDO::FETCH_ASSOC) ?: null;
$events = [];
$eventSteps = [];
$documents = 0;
$checklistResult = null;
$departureFindingCount = 0;
$departureNeedsAttention = 0;
$returnFindingCount = 0;
$approvedAllowances = [];
$deliveryDetails = null;

if ($workflow) {
    $eventQuery = $pdo->prepare(
        'SELECT e.step_number, e.details, e.event_at, e.event_key,
                u.full_name AS actor_name
         FROM trip_workflow_events e
         JOIN users u ON u.user_id = e.actor_user_id
         WHERE e.dispatch_id = ?
         ORDER BY e.event_at, e.event_id'
    );
    $eventQuery->execute([(int)$trip['dispatch_id']]);
    $events = $eventQuery->fetchAll(PDO::FETCH_ASSOC);
    foreach ($events as $event) {
        if ((int)$event['step_number'] > 0) {
            $eventSteps[(int)$event['step_number']] = true;
        }
    }

    $documentQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM documents
         WHERE trip_id = ? AND visibility_scope IN ('all', 'operations')"
    );
    $documentQuery->execute([$tripId]);
    $documents = (int)$documentQuery->fetchColumn();

    $checklistQuery = $pdo->prepare(
        "SELECT result FROM maintenance_checklists
         WHERE dispatch_id = ? ORDER BY submitted_at DESC LIMIT 1"
    );
    $checklistQuery->execute([(int)$trip['dispatch_id']]);
    $checklistResult = $checklistQuery->fetchColumn() ?: null;

    $inspectionQuery = $pdo->prepare(
        "SELECT COUNT(*) AS finding_count,
                SUM(vif.`condition` <> 'Good') AS non_good_count
         FROM vehicle_inspections vi
         JOIN vehicle_inspection_findings vif ON vif.inspection_id = vi.inspection_id
         WHERE vi.trip_id = ? AND vi.inspection_stage = 'Departure'"
    );
    $inspectionQuery->execute([$tripId]);
    $departureInspection = $inspectionQuery->fetch(PDO::FETCH_ASSOC);
    $departureFindingCount = (int)($departureInspection['finding_count'] ?? 0);
    $departureNeedsAttention = (int)($departureInspection['non_good_count'] ?? 0);

    $returnQuery = $pdo->prepare(
        "SELECT COUNT(*)
         FROM vehicle_inspections vi
         JOIN vehicle_inspection_findings vif ON vif.inspection_id = vi.inspection_id
         WHERE vi.trip_id = ? AND vi.inspection_stage = 'Return'"
    );
    $returnQuery->execute([$tripId]);
    $returnFindingCount = (int)$returnQuery->fetchColumn();

    $allowanceQuery = $pdo->prepare(
        "SELECT fund_request_id, request_number, purpose, amount, status
         FROM fund_requests
         WHERE trip_id = ? AND request_type = 'Trip Allowance'
           AND status IN ('Approved', 'Disbursed')
         ORDER BY requested_at DESC"
    );
    $allowanceQuery->execute([$tripId]);
    $approvedAllowances = $allowanceQuery->fetchAll(PDO::FETCH_ASSOC);

    $deliveryQuery = $pdo->prepare(
        'SELECT * FROM trip_delivery_return_details WHERE trip_id = ?'
    );
    $deliveryQuery->execute([$tripId]);
    $deliveryDetails = $deliveryQuery->fetch(PDO::FETCH_ASSOC) ?: null;
}

$isDispatcher = currentRoleId() === ROLE_ADMIN
    || (int)$trip['requested_by'] === currentUserId();
$canDispatchWorkflow = $isDispatcher && currentUserHasAnyPermission(['trips.assign']);
$canUpdateWorkflow = $isDispatcher && currentUserHasAnyPermission(['trips.update']);
$canClear = currentUserHasAnyPermission(['dispatch.clear']);
$currentStep = $workflow ? (int)$workflow['current_step'] : 0;

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
  <a class="btn btn-outline-secondary" href="<?= APP_BASE ?>/pages/trip_monitor.php">
    <i class="bi bi-arrow-left me-1"></i>Trip Monitoring
  </a>
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
            <td><?= (int)$event['step_number'] === 0 ? 'Cancelled' : (int)$event['step_number'] ?></td>
            <td>
              <?= htmlspecialchars($event['details'] ?? $event['event_key']) ?>
              <?php if ($event['step_number'] === '10'): ?>
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
    <?php if ($canDispatchWorkflow && $currentStep === 4 && $trip['status'] === 'Loading'): ?>
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

    <?php if ($canDispatchWorkflow && $currentStep === 5 && $trip['status'] === 'Loading'): ?>
    <div class="card p-3 mb-4">
      <h2 class="h5">Step 6 · Prepare documents and allowance</h2>
      <p class="text-muted">No document categories are mandatory. “Prepared” requires at least one uploaded trip document.</p>
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
            <option value="Not Required">Not required</option>
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
      <p class="mb-1">Maintenance checklist: <strong><?= htmlspecialchars($checklistResult ?? 'Not submitted') ?></strong></p>
      <p class="mb-1">Departure inspection: <strong><?= $departureFindingCount ?>/24 findings</strong>
        <?= $departureFindingCount >= 24 && $departureNeedsAttention === 0 ? '· All good' : '· Incomplete or needs attention' ?>
      </p>
      <?php if (currentUserHasAnyPermission(['maintenance.manage'])): ?>
      <div class="mt-2">
        <a href="<?= APP_BASE ?>/pages/maintenance.php?trip_id=<?= $tripId ?>#checklistModal">Open pre-trip checklist</a>
        <span class="mx-1">·</span>
        <a href="<?= APP_BASE ?>/pages/maintenance.php?trip_id=<?= $tripId ?>&amp;inspection_stage=Departure#inspectionModal">Open departure inspection</a>
      </div>
      <?php else: ?>
      <div class="small text-muted mt-2">Maintenance personnel complete these checks.</div>
      <?php endif; ?>
    </div>

    <?php if ($canClear && $currentStep === 7 && $trip['status'] === 'Loading'): ?>
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

    <?php if ($canUpdateWorkflow && $trip['status'] === 'In Transit' && $currentStep >= 9): ?>
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

    <?php if ($canUpdateWorkflow && $trip['status'] === 'In Transit' && $currentStep >= 10): ?>
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
      <p class="mb-1">Return inspection: <strong><?= $returnFindingCount ?>/24 findings</strong></p>
      <?php if (currentUserHasAnyPermission(['maintenance.manage'])): ?>
      <div class="mt-2"><a href="<?= APP_BASE ?>/pages/maintenance.php?trip_id=<?= $tripId ?>&amp;inspection_stage=Return#inspectionModal">Open return vehicle inspection</a></div>
      <?php else: ?>
      <div class="small text-muted mt-2">Maintenance personnel complete the return inspection.</div>
      <?php endif; ?>
      <?php if ($trip['status'] === 'Unloading'): ?>
      <p class="text-muted small mt-2 mb-0">After the return inspection passes its completeness checks, submit the trip as Completed from Trip Monitoring to finish Step 13.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>
