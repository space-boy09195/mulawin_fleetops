<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';

requirePermission('dispatch.instructions.manage');

$pdo = getDBConnection();
$clients = $pdo->query(
    "SELECT client_id, client_name FROM clients WHERE status = 'Active' ORDER BY client_name"
)->fetchAll(PDO::FETCH_ASSOC);
// Requested routes stay inactive until approved: active = approved.
$routes = $pdo->query(
    "SELECT route_id, route_name, origin, destination
     FROM routes WHERE is_active = 1
     ORDER BY route_name"
)->fetchAll(PDO::FETCH_ASSOC);
// Instructions are client_bookings rows (T-C1). Status shown with the
// legacy labels: Pending = Open, Confirmed/Completed = Encoded.
$instructions = $pdo->query(
    "SELECT i.booking_id AS instruction_id, NULL AS batch_id, i.booking_reference,
            DATE(i.scheduled_departure) AS shift_date, i.shift, i.truck_type,
            i.scheduled_departure AS scheduled_at, i.quantity AS unit_count,
            i.notes AS instruction_notes,
            CASE i.status WHEN 'Pending' THEN 'Open'
                          WHEN 'Confirmed' THEN 'Encoded'
                          WHEN 'Completed' THEN 'Encoded'
                          ELSE i.status END AS status,
            c.client_name, r.route_name,
            COALESCE(ce.full_name, cu.username) AS created_by_name,
            COALESCE(de.full_name, du.username) AS encoded_by_name,
            dr.dispatch_request_id AS dispatch_id
     FROM client_bookings i
     JOIN clients c ON c.client_id = i.client_id
     JOIN routes r ON r.route_id = i.route_id
     LEFT JOIN users cu ON cu.user_id = i.created_by
     LEFT JOIN employees ce ON ce.employee_id = cu.employee_id
     LEFT JOIN trips t ON t.trip_id = (
         SELECT MIN(t2.trip_id) FROM trips t2 WHERE t2.booking_id = i.booking_id
     )
     LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
     LEFT JOIN users du ON du.user_id = dr.submitted_by
     LEFT JOIN employees de ON de.employee_id = du.employee_id
     WHERE i.scheduled_departure IS NOT NULL
     ORDER BY i.booking_id DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dispatch_planning.js';
layoutHead('Dispatch Planning');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Dispatch Planning</h1>
    <p class="page-subtitle">Send Day Shift or Night Shift dispatch instructions to the Dispatcher queue for encoding.</p>
  </div>
  <?php if (currentUserHasAnyPermission(['dispatch.instructions.encode'])): ?>
    <a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/dispatch_inbox.php">
      <i class="bi bi-inbox me-1"></i>Dispatcher Queue
    </a>
  <?php endif; ?>
</div>

<div id="instructionAlert" class="alert d-none" role="alert"></div>

<div class="card mb-4">
  <div class="card-header-custom">
    <h2 class="card-title-custom mb-0">Send a dispatch instruction</h2>
  </div>
  <div class="card-body">
    <form id="dispatchInstructionForm" class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="instructionClient">Client</label>
        <select class="form-select" id="instructionClient" required>
          <option value="">Select an active client</option>
          <?php foreach ($clients as $client): ?>
          <option value="<?= (int)$client['client_id'] ?>"><?= htmlspecialchars($client['client_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="instructionRoute">Approved Route</label>
        <select class="form-select" id="instructionRoute" required>
          <option value="">Select an approved route</option>
          <?php foreach ($routes as $route): ?>
          <option value="<?= (int)$route['route_id'] ?>">
            <?= htmlspecialchars($route['route_name'] . ' — ' . $route['origin'] . ' to ' . $route['destination']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="instructionScheduledAt">Scheduled Departure</label>
        <input class="form-control" type="datetime-local" id="instructionScheduledAt"
               min="<?= date('Y-m-d\TH:i') ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="instructionShift">Shift</label>
        <select class="form-select" id="instructionShift" required>
          <option value="Day">Day Shift</option>
          <option value="Night">Night Shift</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="instructionTruckType">Truck Type</label>
        <select class="form-select" id="instructionTruckType" required>
          <option value="">Select a truck type</option>
          <?php foreach (TRUCK_CATEGORIES as $truckType): ?>
          <option value="<?= htmlspecialchars($truckType) ?>"><?= htmlspecialchars($truckType) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="instructionUnits">Unit Count (optional)</label>
        <input class="form-control" type="number" min="1" max="999999" step="1" id="instructionUnits">
      </div>
      <div class="col-md-8">
        <label class="form-label" for="instructionBookingReference">Client Booking / Nomination Reference (optional, container trips)</label>
        <input class="form-control" type="text" maxlength="100" id="instructionBookingReference"
               placeholder="Leave blank to generate an instruction number">
      </div>
      <div class="col-12">
        <label class="form-label" for="instructionNotes">Instructions for Dispatcher (optional)</label>
        <textarea class="form-control" id="instructionNotes" rows="3" maxlength="1000"
                  placeholder="Additional dispatch details to encode"></textarea>
      </div>
      <div class="col-12">
        <button class="btn btn-primary" type="submit" id="sendInstructionButton">
          <i class="bi bi-send me-1"></i>Send to Dispatchers
        </button>
      </div>
    </form>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header-custom">
    <h2 class="card-title-custom mb-0">Send a dispatch batch</h2>
    <span class="text-muted small">Add multiple trip instructions and send them together.</span>
  </div>
  <div class="card-body">
    <div id="dispatchBatchEntries" class="d-grid gap-3"></div>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <button class="btn btn-outline-primary" type="button" id="addDispatchBatchEntry">
        <i class="bi bi-plus-lg me-1"></i>Add trip to batch
      </button>
      <button class="btn btn-primary" type="button" id="sendDispatchBatch" disabled>
        <i class="bi bi-send me-1"></i>Send batch to Dispatchers
      </button>
    </div>
    <div class="form-text mt-2">Each trip can have its own client, route, departure, shift, units, and notes. Batches can contain 2–25 trips; all items are sent together.</div>
  </div>
</div>

<template id="dispatchBatchEntryTemplate">
  <div class="dispatch-batch-entry border rounded p-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="h6 mb-0">Trip <span class="dispatch-batch-entry-number"></span></h3>
      <button class="btn btn-sm btn-outline-danger" type="button" data-remove-batch-entry>
        Remove
      </button>
    </div>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Client</label>
        <select class="form-select batch-client" required>
          <option value="">Select an active client</option>
          <?php foreach ($clients as $client): ?>
          <option value="<?= (int)$client['client_id'] ?>"><?= htmlspecialchars($client['client_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Approved Route</label>
        <select class="form-select batch-route" required>
          <option value="">Select an approved route</option>
          <?php foreach ($routes as $route): ?>
          <option value="<?= (int)$route['route_id'] ?>">
            <?= htmlspecialchars($route['route_name'] . ' — ' . $route['origin'] . ' to ' . $route['destination']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Scheduled Departure</label>
        <input class="form-control batch-scheduled-at" type="datetime-local"
               min="<?= date('Y-m-d\TH:i') ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Shift</label>
        <select class="form-select batch-shift" required>
          <option value="Day">Day Shift</option>
          <option value="Night">Night Shift</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Truck Type</label>
        <select class="form-select batch-truck-type" required>
          <option value="">Select a truck type</option>
          <?php foreach (TRUCK_CATEGORIES as $truckType): ?>
          <option value="<?= htmlspecialchars($truckType) ?>"><?= htmlspecialchars($truckType) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Unit Count (optional)</label>
        <input class="form-control batch-unit-count" type="number" min="1" max="999999" step="1">
      </div>
      <div class="col-md-8">
        <label class="form-label">Client Booking / Nomination Reference (optional, container trips)</label>
        <input class="form-control batch-booking-reference" type="text" maxlength="100"
               placeholder="Leave blank to generate an instruction number">
      </div>
      <div class="col-12">
        <label class="form-label">Instructions for Dispatcher (optional)</label>
        <textarea class="form-control batch-instruction-notes" rows="2" maxlength="1000"
                  placeholder="Additional dispatch details to encode"></textarea>
      </div>
    </div>
  </div>
</template>

<div class="card">
  <div class="card-header-custom">
    <h2 class="card-title-custom mb-0">Sent Dispatch List</h2>
    <span class="text-muted small"><?= count($instructions) ?> recent instructions</span>
  </div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead>
        <tr><th>Truck Type</th><th>Ref</th><th>Shift</th><th>Client</th><th>Route</th><th>Departure</th><th>Units</th><th>Status</th><th>Notes</th><th>Action</th></tr>
      </thead>
      <tbody>
      <?php if (!$instructions): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">No dispatch instructions sent yet.</td></tr>
      <?php else: foreach ($instructions as $instruction): ?>
        <tr>
          <td><?= htmlspecialchars((string)$instruction['truck_type']) ?></td>
          <td><?= htmlspecialchars((string)$instruction['booking_reference']) ?></td>
          <td><span class="badge text-bg-<?= $instruction['shift'] === 'Day' ? 'info' : 'dark' ?>"><?= htmlspecialchars($instruction['shift']) ?></span></td>
          <td><?= htmlspecialchars($instruction['client_name']) ?></td>
          <td><?= htmlspecialchars($instruction['route_name']) ?></td>
          <td><?= date('M j, Y g:i A', strtotime($instruction['scheduled_at'])) ?></td>
          <td><?= $instruction['unit_count'] !== null ? number_format((int)$instruction['unit_count']) : '—' ?></td>
          <td><?= htmlspecialchars($instruction['status']) ?><?= $instruction['encoded_by_name'] ? '<br><small>' . htmlspecialchars($instruction['encoded_by_name']) . '</small>' : '' ?></td>
          <td><?= $instruction['instruction_notes'] ? htmlspecialchars($instruction['instruction_notes']) : '—' ?></td>
          <td>
            <?php if ($instruction['status'] === 'Open'): ?>
            <button class="btn btn-sm btn-outline-danger js-cancel-instruction"
                    data-id="<?= (int)$instruction['instruction_id'] ?>">Cancel</button>
            <?php elseif ($instruction['dispatch_id']): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= APP_BASE ?>/pages/dispatch.php">Dispatch #<?= (int)$instruction['dispatch_id'] ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layoutFoot(); ?>
