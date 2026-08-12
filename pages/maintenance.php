<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/maintenance.js';

layoutHead('Maintenance', APP_BASE . '/assets/css/maintenance.css');

$pdo = getDBConnection();

// ── Period filter (scopes Maintenance Records list below) ────────────────────
$periods = [
    'today' => 'Today', '1w' => 'This Week', '1m' => 'This Month',
    '3m' => 'Last 3 Months', '6m' => 'Last 6 Months', '1y' => 'Last 12 Months', 'all' => 'All Time',
];
$period = $_GET['period'] ?? 'all';
if (!isset($periods[$period])) $period = 'all';
$rangeStart = match ($period) {
    'today' => new DateTime('today'),
    '1w'    => (new DateTime('today'))->modify('-6 days'),
    '1m'    => (new DateTime('today'))->modify('-1 months'),
    '3m'    => (new DateTime('today'))->modify('-3 months'),
    '6m'    => (new DateTime('today'))->modify('-6 months'),
    '1y'    => (new DateTime('today'))->modify('-12 months'),
    default => null,
};
$rangeStartSql = $rangeStart ? $rangeStart->format('Y-m-d 00:00:00') : null;
$recDateFilter = $rangeStartSql ? "AND mr.date_performed >= :rangeStart" : '';

// ── Maintenance records ───────────────────────────────────────────────────────
$recordsSql = "
    SELECT
        mr.record_id,
        mr.maintenance_type,
        mr.truck_status,
        mr.description,
        mr.cost,
        mr.date_performed,
        mr.next_due_date,
        mr.created_at,
        tr.plate_number,
        tr.brand,
        tr.model,
        u.full_name      AS performed_by_name,
        i.incident_type  AS linked_incident_type,
        i.incident_id
    FROM maintenance_records mr
    JOIN trucks tr   ON mr.truck_id     = tr.truck_id
    JOIN users u     ON mr.performed_by = u.user_id
    LEFT JOIN incidents i ON mr.incident_id = i.incident_id
    WHERE 1=1 $recDateFilter
    ORDER BY mr.date_performed DESC, mr.created_at DESC
";
$recordsStmt = $pdo->prepare($recordsSql);
if ($rangeStartSql) $recordsStmt->bindValue(':rangeStart', $rangeStartSql);
$recordsStmt->execute();
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Checklists ────────────────────────────────────────────────────────────────
$checklistSql = "
    SELECT
        mc.checklist_id,
        mc.result,
        mc.notes,
        mc.submitted_at,
        mc.lights_ok,
        mc.tires_ok,
        mc.tools_ok,
        mc.medical_kit_ok,
        mc.license_ok,
        mc.or_cr_ok,
        mc.waybill_ok,
        mc.fuel_po_ok,
        tr.plate_number,
        tr.brand,
        tr.model,
        dr.status        AS dispatch_status,
        t.trip_number,
        u.full_name      AS submitted_by_name
    FROM maintenance_checklists mc
    JOIN trucks tr            ON mc.truck_id    = tr.truck_id
    JOIN dispatch_requests dr ON mc.dispatch_id = dr.dispatch_id
    LEFT JOIN trips t         ON mc.trip_id     = t.trip_id
    JOIN users u              ON mc.submitted_by = u.user_id
    ORDER BY mc.submitted_at DESC
";
$checklists = $pdo->query($checklistSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Dropdowns for forms ───────────────────────────────────────────────────────
$trucks = $pdo->query("
    SELECT truck_id, plate_number, brand, model
    FROM trucks
    WHERE status != 'Inactive'
    ORDER BY plate_number
")->fetchAll(PDO::FETCH_ASSOC);

// Approved dispatches without a checklist yet
$pendingDispatchesSql = "
    SELECT
        dr.dispatch_id,
        tr.plate_number,
        tr.brand,
        tr.model,
        e.full_name  AS driver_name,
        r.origin,
        r.destination,
        dr.scheduled_at
    FROM dispatch_requests dr
    JOIN trucks tr    ON dr.truck_id  = tr.truck_id
    JOIN employees e  ON dr.driver_id = e.employee_id
    JOIN routes r     ON dr.route_id  = r.route_id
    WHERE dr.status = 'Approved'
      AND NOT EXISTS (
          SELECT 1 FROM maintenance_checklists mc
          WHERE mc.dispatch_id = dr.dispatch_id
      )
    ORDER BY dr.scheduled_at ASC
";
$pendingDispatches = $pdo->query($pendingDispatchesSql)->fetchAll(PDO::FETCH_ASSOC);

// Open incidents (for linking to a maintenance record)
$openIncidentsSql = "
    SELECT
        i.incident_id,
        i.incident_type,
        t.trip_number,
        tr.plate_number
    FROM incidents i
    JOIN trips t              ON i.trip_id     = t.trip_id
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    WHERE i.resolved_at IS NULL
    ORDER BY i.reported_at DESC
";
$openIncidents = $pdo->query($openIncidentsSql)->fetchAll(PDO::FETCH_ASSOC);

$maintenanceTypes = ['Preventive', 'Corrective', 'Inspection'];
$truckStatuses    = ['Operational', 'Scheduled Maintenance', 'Under Repair'];

$checklistItems = [
    'lights_ok'      => 'Lights',
    'tires_ok'       => 'Tires',
    'tools_ok'       => 'Tools',
    'medical_kit_ok' => 'Medical Kit',
    'license_ok'     => "Driver's License",
    'or_cr_ok'       => 'OR/CR',
    'waybill_ok'     => 'Waybill',
    'fuel_po_ok'     => 'Fuel PO',
];
?>

<div class="mnt-page">

  <!-- Header -->
  <div class="mnt-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="mnt-title mb-0">Maintenance</h1>
      <p class="mnt-subtitle mb-0">Checklists, records, and truck status</p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <form method="get" class="d-flex">
        <select name="period" class="form-select mnt-input" style="min-width:160px;font-size:.85rem;" onchange="this.form.submit()">
          <?php foreach ($periods as $key => $label): ?>
          <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <button class="btn btn-mnt-secondary" data-bs-toggle="modal" data-bs-target="#checklistModal">
        <i class="bi bi-clipboard-check me-1"></i> New Checklist
      </button>
      <button class="btn btn-mnt-primary" data-bs-toggle="modal" data-bs-target="#recordModal">
        <i class="bi bi-wrench me-1"></i> Log Record
      </button>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav mnt-tabs mb-4" id="mntTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="mnt-tab active" id="tab-records" data-bs-toggle="tab"
              data-bs-target="#pane-records" type="button" role="tab">
        <i class="bi bi-wrench me-1"></i> Maintenance Records
        <span class="mnt-tab-count"><?= count($records) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="mnt-tab" id="tab-checklists" data-bs-toggle="tab"
              data-bs-target="#pane-checklists" type="button" role="tab">
        <i class="bi bi-clipboard-check me-1"></i> Pre-Trip Checklists
        <span class="mnt-tab-count"><?= count($checklists) ?></span>
      </button>
    </li>
  </ul>

  <div class="tab-content">

    <!-- ── Records pane ─────────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="pane-records" role="tabpanel">

      <!-- Filters -->
      <div class="mnt-filters d-flex flex-wrap gap-2 mb-3">
        <select id="filterRecordType" class="form-select mnt-filter-select">
          <option value="">All Types</option>
          <?php foreach ($maintenanceTypes as $mt): ?>
          <option value="<?= $mt ?>"><?= $mt ?></option>
          <?php endforeach; ?>
        </select>
        <input type="search" id="filterRecordSearch" class="form-control mnt-filter-search"
               placeholder="Search plate, brand, description…">
      </div>

      <div class="mnt-table-wrap">
        <?php if (empty($records)): ?>
        <div class="mnt-empty">
          <i class="bi bi-wrench mnt-empty-icon"></i>
          <p>No maintenance records yet.</p>
        </div>
        <?php else: ?>
        <table class="table mnt-table" id="recordsTable">
          <thead>
            <tr>
              <th>Truck</th>
              <th>Type</th>
              <th>Description</th>
              <th>Truck Status</th>
              <th>Cost</th>
              <th>Date Performed</th>
              <th>Next Due</th>
              <th>Performed By</th>
              <th>Linked Incident</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $rec): ?>
            <tr
              data-type="<?= htmlspecialchars($rec['maintenance_type']) ?>"
              data-search="<?= htmlspecialchars(strtolower(
                $rec['plate_number'] . ' ' . $rec['brand'] . ' ' . $rec['model'] . ' ' . $rec['description']
              )) ?>"
            >
              <td>
                <div class="mnt-truck-label">
                  <span class="mnt-plate"><?= htmlspecialchars($rec['plate_number']) ?></span>
                  <span class="mnt-truck-model"><?= htmlspecialchars($rec['brand'] . ' ' . $rec['model']) ?></span>
                </div>
              </td>
              <td>
                <span class="mnt-type-badge mnt-type-<?= strtolower($rec['maintenance_type']) ?>">
                  <?= htmlspecialchars($rec['maintenance_type']) ?>
                </span>
              </td>
              <td class="mnt-desc-cell">
                <button type="button" class="mnt-desc-text mnt-desc-btn"
                        data-truck="<?= htmlspecialchars($rec['plate_number'] . ' — ' . $rec['brand'] . ' ' . $rec['model']) ?>"
                        data-type="<?= htmlspecialchars($rec['maintenance_type']) ?>"
                        data-description="<?= htmlspecialchars($rec['description']) ?>">
                  <?= htmlspecialchars($rec['description']) ?>
                </button>
              </td>
              <td>
                <span class="mnt-status-badge mnt-ts-<?= strtolower(str_replace(' ', '-', $rec['truck_status'])) ?>">
                  <?= htmlspecialchars($rec['truck_status']) ?>
                </span>
              </td>
              <td class="mnt-cost">
                <?= $rec['cost'] !== null ? '₱' . number_format($rec['cost'], 2) : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="mnt-date"><?= date('M d, Y', strtotime($rec['date_performed'])) ?></td>
              <td class="mnt-date">
                <?php if ($rec['next_due_date']): ?>
                  <?php
                    $daysLeft = (int)((strtotime($rec['next_due_date']) - time()) / 86400);
                    $dueCls   = $daysLeft <= 7 ? 'mnt-due-urgent' : ($daysLeft <= 30 ? 'mnt-due-soon' : '');
                  ?>
                  <span class="<?= $dueCls ?>"><?= date('M d, Y', strtotime($rec['next_due_date'])) ?></span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($rec['performed_by_name']) ?></td>
              <td>
                <?php if ($rec['incident_id']): ?>
                <span class="mnt-incident-link">
                  <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                  <?= htmlspecialchars($rec['linked_incident_type']) ?>
                </span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noRecordResults" class="no-results d-none">
          <i class="bi bi-search"></i>
          <span>No maintenance records match your filters.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Checklists pane ───────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="pane-checklists" role="tabpanel">

      <div class="mnt-filters d-flex flex-wrap gap-2 mb-3">
        <select id="filterCheckResult" class="form-select mnt-filter-select">
          <option value="">All Results</option>
          <option value="Passed">Passed</option>
          <option value="Failed">Failed</option>
        </select>
        <input type="search" id="filterCheckSearch" class="form-control mnt-filter-search"
               placeholder="Search plate, trip no…">
      </div>

      <div class="mnt-table-wrap">
        <?php if (empty($checklists)): ?>
        <div class="mnt-empty">
          <i class="bi bi-clipboard-check mnt-empty-icon"></i>
          <p>No checklists submitted yet.</p>
        </div>
        <?php else: ?>
        <table class="table mnt-table" id="checklistsTable">
          <thead>
            <tr>
              <th>Truck</th>
              <th>Trip No.</th>
              <th>Result</th>
              <th>Checklist Items</th>
              <th>Notes</th>
              <th>Submitted</th>
              <th>Submitted By</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($checklists as $cl): ?>
            <tr
              data-result="<?= htmlspecialchars($cl['result']) ?>"
              data-search="<?= htmlspecialchars(strtolower($cl['plate_number'] . ' ' . ($cl['trip_number'] ?? ''))) ?>"
            >
              <td>
                <div class="mnt-truck-label">
                  <span class="mnt-plate"><?= htmlspecialchars($cl['plate_number']) ?></span>
                  <span class="mnt-truck-model"><?= htmlspecialchars($cl['brand'] . ' ' . $cl['model']) ?></span>
                </div>
              </td>
              <td>
                <?php if ($cl['trip_number']): ?>
                <span class="mnt-ref"><?= htmlspecialchars($cl['trip_number']) ?></span>
                <?php else: ?>
                <span class="text-muted fst-italic" style="font-size:0.8rem;">Pending</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="mnt-result-badge mnt-result-<?= strtolower($cl['result']) ?>">
                  <?= $cl['result'] ?>
                </span>
              </td>
              <td>
                <div class="mnt-checklist-items">
                  <?php foreach ($checklistItems as $field => $label): ?>
                  <span class="mnt-check-item <?= $cl[$field] ? 'mnt-check-ok' : 'mnt-check-fail' ?>"
                        title="<?= $label ?>">
                    <i class="bi <?= $cl[$field] ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
                    <?= $label ?>
                  </span>
                  <?php endforeach; ?>
                </div>
              </td>
              <td class="mnt-desc-cell">
                <span class="mnt-desc-text" title="<?= htmlspecialchars($cl['notes'] ?? '') ?>">
                  <?= $cl['notes'] ? htmlspecialchars($cl['notes']) : '<span class="text-muted">—</span>' ?>
                </span>
              </td>
              <td class="mnt-date"><?= date('M d, Y H:i', strtotime($cl['submitted_at'])) ?></td>
              <td><?= htmlspecialchars($cl['submitted_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noChecklistResults" class="no-results d-none">
          <i class="bi bi-search"></i>
          <span>No checklists match your filters.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /tab-content -->
</div>

<!-- ══ Log Maintenance Record Modal ═══════════════════════════════════════ -->
<div class="modal fade" id="recordModal" tabindex="-1" aria-labelledby="recordModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content mnt-modal-content">
      <div class="modal-header mnt-modal-header-primary">
        <h5 class="modal-title" id="recordModalLabel">
          <i class="bi bi-wrench me-2"></i>Log Maintenance Record
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body mnt-modal-body">
        <div id="recordFormAlert" class="alert d-none" role="alert"></div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recTruckId">Truck</label>
            <select class="form-select mnt-input" id="recTruckId" required>
              <option value="">— Select truck —</option>
              <?php foreach ($trucks as $tr): ?>
              <option value="<?= $tr['truck_id'] ?>">
                <?= htmlspecialchars($tr['plate_number']) ?> — <?= htmlspecialchars($tr['brand'] . ' ' . $tr['model']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recType">Maintenance Type</label>
            <select class="form-select mnt-input" id="recType" required>
              <option value="">— Select type —</option>
              <?php foreach ($maintenanceTypes as $mt): ?>
              <option value="<?= $mt ?>"><?= $mt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recTruckStatus">Truck Status After</label>
            <select class="form-select mnt-input" id="recTruckStatus" required>
              <option value="">— Select status —</option>
              <?php foreach ($truckStatuses as $ts): ?>
              <option value="<?= $ts ?>"><?= $ts ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label mnt-label" for="recDatePerformed">Date Performed</label>
            <input type="date" class="form-control mnt-input" id="recDatePerformed"
                   value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label mnt-label" for="recNextDue">
              Next Due Date <span class="mnt-unit-hint">(sets this truck's next alert)</span>
            </label>
            <input type="date" class="form-control mnt-input" id="recNextDue">
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recCost">Cost (₱)</label>
            <input type="number" class="form-control mnt-input" id="recCost"
                   min="0" step="0.01" placeholder="Leave blank if unknown">
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recIncidentId">Link to Incident (optional)</label>
            <select class="form-select mnt-input" id="recIncidentId">
              <option value="">— None —</option>
              <?php foreach ($openIncidents as $oi): ?>
              <option value="<?= $oi['incident_id'] ?>">
                #<?= $oi['incident_id'] ?> — <?= htmlspecialchars($oi['incident_type']) ?>
                (<?= htmlspecialchars($oi['trip_number']) ?> / <?= htmlspecialchars($oi['plate_number']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label mnt-label" for="recDescription">Description</label>
            <textarea class="form-control mnt-input" id="recDescription" rows="3"
              placeholder="What was done? Parts replaced, issues found, etc." required></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer mnt-modal-footer">
        <button type="button" class="btn btn-mnt-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-mnt-primary" id="submitRecordBtn">
          <span id="recBtnText">Save Record</span>
          <span id="recBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Pre-Trip Checklist Modal ═══════════════════════════════════════════ -->
<div class="modal fade" id="checklistModal" tabindex="-1" aria-labelledby="checklistModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content mnt-modal-content">
      <div class="modal-header mnt-modal-header-secondary">
        <h5 class="modal-title" id="checklistModalLabel">
          <i class="bi bi-clipboard-check me-2"></i>Pre-Trip Checklist
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body mnt-modal-body">
        <div id="checklistFormAlert" class="alert d-none" role="alert"></div>

        <div class="mb-3">
          <label class="form-label mnt-label" for="clDispatchId">Dispatch / Trip</label>
          <select class="form-select mnt-input" id="clDispatchId" required>
            <option value="">— Select approved dispatch —</option>
            <?php foreach ($pendingDispatches as $pd): ?>
            <option value="<?= $pd['dispatch_id'] ?>"
                    data-truck-id="<?= $pd['dispatch_id'] ?>">
              <?= htmlspecialchars($pd['plate_number']) ?> —
              <?= htmlspecialchars($pd['brand'] . ' ' . $pd['model']) ?> |
              <?= htmlspecialchars($pd['driver_name']) ?> |
              <?= htmlspecialchars($pd['origin']) ?> → <?= htmlspecialchars($pd['destination']) ?>
              <?= $pd['scheduled_at'] ? '| ' . date('M d', strtotime($pd['scheduled_at'])) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($pendingDispatches)): ?>
          <div class="form-text text-warning">
            <i class="bi bi-info-circle"></i>
            No approved dispatches without a checklist at the moment.
          </div>
          <?php endif; ?>
        </div>

        <p class="mnt-label mb-2">Checklist Items</p>
        <div class="mnt-checklist-grid mb-3">
          <?php foreach ($checklistItems as $field => $label): ?>
          <label class="mnt-check-toggle">
            <input type="checkbox" class="mnt-check-cb" id="cl_<?= $field ?>" name="<?= $field ?>">
            <span class="mnt-check-toggle-inner">
              <i class="bi bi-check-lg"></i>
            </span>
            <span class="mnt-check-toggle-label"><?= $label ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <div id="clResultBanner" class="mnt-result-banner d-none"></div>

        <div class="mt-3">
          <label class="form-label mnt-label" for="clNotes">Notes (optional)</label>
          <textarea class="form-control mnt-input" id="clNotes" rows="2"
            placeholder="Any issues found or remarks…"></textarea>
        </div>
      </div>
      <div class="modal-footer mnt-modal-footer">
        <button type="button" class="btn btn-mnt-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-mnt-secondary" id="submitChecklistBtn">
          <span id="clBtnText">Submit Checklist</span>
          <span id="clBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Description Expand Modal (Records tab) ═══════════════════════════════ -->
<div class="modal fade" id="descModal" tabindex="-1" aria-labelledby="descModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content mnt-modal-content">
      <div class="modal-header mnt-modal-header-primary">
        <h5 class="modal-title" id="descModalLabel">
          <i class="bi bi-file-text me-2"></i><span id="descModalTruck"></span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <span class="mnt-type-badge" id="descModalType"></span>
        <p class="mt-3 mb-0" id="descModalText" style="white-space: pre-wrap;"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>