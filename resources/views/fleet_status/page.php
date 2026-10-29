<?php
// ============================================================
// resources/views/fleet_status/page.php
// Fleet Status markup, included by pages/fleet_status.php after its data
// layer and layoutHead(). Uses the page variables $trucks and $isHead.
// Holds no queries or permission checks: those stay in
// pages/fleet_status.php. Not reachable by URL (.htaccess denies
// resources/); opened without the application it returns 404.
// ============================================================
if (!defined('APP_BASE')) {
    http_response_code(404);
    exit;
}
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Fleet Status</h1>
    <p class="page-subtitle">Current truck records, stored statuses, and active assignments.</p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($isHead): ?>
    <button class="btn btn-success btn-sm d-flex align-items-center gap-2"
            data-bs-toggle="modal" data-bs-target="#addTruckModal">
      <i class="bi bi-plus-lg"></i> Add Truck
    </button>
    <?php endif; ?>
    <?php if (isAdmin() || currentUserHasAnyPermission(['dispatch.instructions.encode'])): ?>
    <a href="<?= APP_BASE ?>/pages/dispatch.php"
       class="btn btn-primary btn-sm d-flex align-items-center gap-2">
      <i class="bi bi-send"></i> New Dispatch
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-4">
  <div class="card-body-custom">
    <div class="d-flex flex-wrap gap-2 align-items-center" data-persist-filter="fleet_status">
      <span class="text-muted" style="font-size:.8rem;">Filter:</span>
      <button class="filter-btn active" data-filter="all">All</button>
      <button class="filter-btn" data-filter="Available">Available</button>
      <button class="filter-btn" data-filter="Assigned">Assigned</button>
      <button class="filter-btn" data-filter="On Trip">On Trip</button>
      <button class="filter-btn" data-filter="Under Maintenance">Under Maintenance</button>
      <button class="filter-btn" data-filter="Unavailable">Unavailable</button>
      <div class="ms-auto">
        <input type="text" id="truckSearch" class="form-control form-control-sm" data-persist-state
               placeholder="Search plate, brand, model…" style="width:220px;">
      </div>
    </div>
  </div>
</div>

<!-- Truck table -->
<div class="card">
  <div class="card-header-custom">
    <h2 class="card-title-custom">Truck Registry</h2>
    <span class="text-muted" style="font-size:.8rem;" id="rowCount"></span>
  </div>

  <div class="table-responsive">
    <table class="table-custom" id="fleetTable">
      <thead>
        <tr>
          <th>Plate No.</th>
          <th>Truck</th>
          <th>Body Type</th>
          <th>Fuel</th>
          <th>Capacity</th>
          <th>Status</th>
          <th>Current Assignment</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="fleetBody">
        <?php if (empty($trucks)): ?>
        <tr>
          <td colspan="8" class="text-center text-muted py-4">
            No trucks found.
            <?php if ($isHead): ?>
            <a href="#" data-bs-toggle="modal" data-bs-target="#addTruckModal">Add the first truck.</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($trucks as $truck):
          // Expiry date − current date (includes/metrics.php).
          $warrantyDaysRemaining = daysUntilExpiry($truck['warranty_expiry'] ?? null);
          $registrationDays = daysUntilExpiry($truck['registration_expiry'] ?? null);
          $insuranceDays = daysUntilExpiry($truck['insurance_expiry'] ?? null);
          $badgeClass = match($truck['status']) {
            'Available'         => 'available',
            'Assigned', 'On Trip' => 'deployed',
            'Under Maintenance' => 'maintenance',
            default             => 'inactive',
          };
          $dot = match($truck['status']) {
            'Available'         => '🟢',
            'Assigned', 'On Trip' => '🔵',
            'Under Maintenance' => '🟡',
            default             => '⚫',
          };
        ?>
        <tr data-status="<?= htmlspecialchars($truck['status']) ?>"
            data-search="<?= strtolower(htmlspecialchars($truck['plate_number'] . ' ' . $truck['brand'] . ' ' . $truck['model'])) ?>">
          <td>
            <span class="fw-600" style="font-family:monospace;letter-spacing:.03em;">
              <?= htmlspecialchars($truck['plate_number']) ?>
            </span>
            <?php if ($truck['unit_number']): ?><br><span class="text-muted small">Unit <?= htmlspecialchars($truck['unit_number']) ?></span><?php endif; ?>
            <?php if ($registrationDays !== null): ?><br><span class="small <?= $registrationDays < 0 ? 'text-danger fw-semibold' : ($registrationDays <= 30 ? 'text-warning fw-semibold' : 'text-muted') ?>">OR/CR due <?= htmlspecialchars($truck['registration_expiry']) ?> (<?= htmlspecialchars(expiryDaysText($registrationDays)) ?>)</span><?php endif; ?>
            <?php if ($insuranceDays !== null): ?><br><span class="small <?= $insuranceDays < 0 ? 'text-danger fw-semibold' : ($insuranceDays <= 30 ? 'text-warning fw-semibold' : 'text-muted') ?>">Insurance due <?= htmlspecialchars($truck['insurance_expiry']) ?> (<?= htmlspecialchars(expiryDaysText($insuranceDays)) ?>)</span><?php endif; ?>
            <?php if ($warrantyDaysRemaining !== null): ?><br><span class="small <?= $warrantyDaysRemaining < 0 ? 'text-danger fw-semibold' : ($warrantyDaysRemaining <= 30 ? 'text-warning fw-semibold' : 'text-muted') ?>">Warranty <?= $warrantyDaysRemaining < 0 ? 'expired' : ($warrantyDaysRemaining <= 30 ? 'due soon' : 'expires') ?> <?= htmlspecialchars($truck['warranty_expiry']) ?></span><?php endif; ?>
          </td>
          <td>
            <img
              src="<?= !empty($truck['image_path']) ? APP_BASE . '/' . htmlspecialchars($truck['image_path']) : APP_BASE . '/assets/images/inspection/demo-truck-side.svg' ?>"
              alt="<?= !empty($truck['image_path']) ? 'Photo of ' : 'Sample illustration of ' ?><?= htmlspecialchars($truck['brand'] . ' ' . $truck['model']) ?>"
              loading="lazy" decoding="async" class="fleet-truck-thumb me-2">
            <div style="font-weight:600;"><?= htmlspecialchars($truck['brand'] . ' ' . $truck['model']) ?></div>
            <?php if ($truck['truck_type']): ?><div class="text-muted small"><?= htmlspecialchars($truck['truck_type']) ?></div><?php endif; ?>
            <div class="text-muted" style="font-size:.78rem;"><?= htmlspecialchars($truck['year_model']) ?></div>
          </td>
          <td><?= htmlspecialchars($truck['body_type'] ?? '—') ?></td>
          <td><?= htmlspecialchars($truck['fuel_type']) ?></td>
          <td><?= $truck['capacity_tons'] ? htmlspecialchars($truck['capacity_tons']) . ' t' : '—' ?></td>
          <td>
            <span class="status-badge <?= $badgeClass ?>">
              <?= $dot ?> <?= htmlspecialchars($truck['status']) ?>
            </span>
          </td>
          <td>
            <?php if (in_array($truck['status'], ['Assigned', 'On Trip'], true) && $truck['trip_number']): ?>
            <div style="font-size:.82rem;">
              <span class="fw-600"><?= htmlspecialchars($truck['trip_number']) ?></span><br>
              <span class="text-muted">Driver: <?= htmlspecialchars($truck['driver_name'] ?? '—') ?></span><br>
              <span class="text-muted">
                <?= htmlspecialchars($truck['origin'] ?? '') ?>
                <i class="bi bi-arrow-right"></i>
                <?= htmlspecialchars($truck['destination'] ?? '') ?>
              </span>
            </div>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-1">
              <!-- Status update (both roles) -->
              <button class="btn btn-sm btn-outline-secondary"
                      onclick="openStatusModal(<?= $truck['truck_id'] ?>, '<?= htmlspecialchars($truck['plate_number'], ENT_QUOTES) ?>', '<?= htmlspecialchars($truck['status'], ENT_QUOTES) ?>')"
                      title="Update status">
                <i class="bi bi-arrow-repeat"></i>
              </button>
              <button class="btn btn-sm btn-outline-info truck-history-btn"
                      data-truck-id="<?= (int)$truck['truck_id'] ?>"
                      data-truck-plate="<?= htmlspecialchars($truck['plate_number'], ENT_QUOTES) ?>"
                      title="View availability history">
                <i class="bi bi-clock-history"></i>
              </button>
              <!-- Edit (Head Management only) -->
              <?php if ($isHead): ?>
              <button class="btn btn-sm btn-outline-primary btn-edit-truck"
                      title="Edit truck"
                      data-id="<?= $truck['truck_id'] ?>"
                      data-plate="<?= htmlspecialchars($truck['plate_number'], ENT_QUOTES) ?>"
                      data-unit-number="<?= htmlspecialchars($truck['unit_number'] ?? '', ENT_QUOTES) ?>"
                      data-truck-type="<?= htmlspecialchars($truck['truck_type'] ?? '', ENT_QUOTES) ?>"
                      data-chassis="<?= htmlspecialchars($truck['chassis_number'] ?? '', ENT_QUOTES) ?>"
                      data-engine="<?= htmlspecialchars($truck['engine_number'] ?? '', ENT_QUOTES) ?>"
                      data-mv-file="<?= htmlspecialchars($truck['mv_file_number'] ?? '', ENT_QUOTES) ?>"
                      data-registration-expiry="<?= htmlspecialchars($truck['registration_expiry'] ?? '', ENT_QUOTES) ?>"
                      data-insurance-provider="<?= htmlspecialchars($truck['insurance_provider'] ?? '', ENT_QUOTES) ?>"
                      data-insurance-policy="<?= htmlspecialchars($truck['insurance_policy_number'] ?? '', ENT_QUOTES) ?>"
                      data-insurance-expiry="<?= htmlspecialchars($truck['insurance_expiry'] ?? '', ENT_QUOTES) ?>"
                      data-warranty-expiry="<?= htmlspecialchars($truck['warranty_expiry'] ?? '', ENT_QUOTES) ?>"
                      data-brand="<?= htmlspecialchars($truck['brand'], ENT_QUOTES) ?>"
                      data-model="<?= htmlspecialchars($truck['model'], ENT_QUOTES) ?>"
                      data-year="<?= htmlspecialchars($truck['year_model'], ENT_QUOTES) ?>"
                      data-body="<?= htmlspecialchars($truck['body_type'] ?? '', ENT_QUOTES) ?>"
                      data-fuel="<?= htmlspecialchars($truck['fuel_type'], ENT_QUOTES) ?>"
                      data-capacity="<?= htmlspecialchars($truck['capacity_tons'] ?? '', ENT_QUOTES) ?>"
                      data-status="<?= htmlspecialchars($truck['status'], ENT_QUOTES) ?>"
                      data-image-front="<?= htmlspecialchars($truck['image_front_path'] ? APP_BASE . '/' . ltrim($truck['image_front_path'], '/') : '', ENT_QUOTES) ?>"
                      data-image-side="<?= htmlspecialchars($truck['image_side_path'] ? APP_BASE . '/' . ltrim($truck['image_side_path'], '/') : (!empty($truck['image_path']) ? APP_BASE . '/' . ltrim($truck['image_path'], '/') : ''), ENT_QUOTES) ?>"
                      data-image-rear="<?= htmlspecialchars($truck['image_rear_path'] ? APP_BASE . '/' . ltrim($truck['image_rear_path'], '/') : '', ENT_QUOTES) ?>"
                      data-image-top="<?= htmlspecialchars($truck['image_top_path'] ? APP_BASE . '/' . ltrim($truck['image_top_path'], '/') : '', ENT_QUOTES) ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <tr id="noFleetResults" class="d-none">
          <td colspan="8">
            <div class="no-results">
              <i class="bi bi-search"></i>
              <span>No trucks match your filters.</span>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ Add Truck Modal ════════════════════════════════════════════════════ -->
<?php if ($isHead): ?>
<div class="modal fade" id="addTruckModal" tabindex="-1" aria-labelledby="addTruckLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content fleet-modal">
      <div class="modal-header fleet-modal-header-add">
        <h5 class="modal-title" id="addTruckLabel">
          <i class="bi bi-truck me-2"></i>Add New Truck
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body fleet-modal-body">
        <div id="addTruckAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="fleet-label">Plate Number <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="at_plate"
                   placeholder="e.g. ABC 1234" required>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Brand <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="at_brand"
                   placeholder="e.g. Isuzu" required>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Model <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="at_model"
                   placeholder="e.g. Giga Forward" required>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Year Model <span class="text-danger">*</span></label>
            <input type="number" class="form-control fleet-input" id="at_year"
                   min="1990" max="<?= date('Y') + 1 ?>"
                   placeholder="<?= date('Y') ?>" required>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Body Type</label>
            <input type="text" class="form-control fleet-input" id="at_body"
                   placeholder="e.g. Closed Van, Flatbed"
                   list="bodyTypeList">
            <datalist id="bodyTypeList">
              <option value="Closed Van">
              <option value="Flatbed">
              <option value="Reefer">
              <option value="Tanker">
              <option value="Dump Truck">
              <option value="Wing Van">
            </datalist>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Fuel Type <span class="text-danger">*</span></label>
            <select class="form-select fleet-input" id="at_fuel" required>
              <option value="Diesel" selected>Diesel</option>
              <option value="Gasoline">Gasoline</option>
              <option value="LPG">LPG</option>
              <option value="Electric">Electric</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Capacity (tons)</label>
            <input type="number" class="form-control fleet-input" id="at_capacity"
                   min="0" step="0.01" placeholder="e.g. 5.00">
          </div>
          <div class="col-md-6">
            <label class="fleet-label">Chassis Number</label>
            <input type="text" class="form-control fleet-input" id="at_chassis"
                   placeholder="Optional">
          </div>
          <div class="col-md-6">
            <label class="fleet-label">Engine Number</label>
            <input type="text" class="form-control fleet-input" id="at_engine"
                   placeholder="Optional">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Internal Unit Number</label>
            <input type="text" class="form-control fleet-input" id="at_unit_number" maxlength="30">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Truck Type</label>
            <select class="form-select fleet-input" id="at_truck_type" required>
              <option value="">Select truck category</option>
              <?php foreach (TRUCK_CATEGORIES as $truckCategory): ?>
              <option value="<?= htmlspecialchars($truckCategory) ?>"><?= htmlspecialchars($truckCategory) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">MV File Number</label>
            <input type="text" class="form-control fleet-input" id="at_mv_file" maxlength="50">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Registration Expiry</label>
            <input type="date" class="form-control fleet-input" id="at_registration_expiry">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Insurance Provider</label>
            <input type="text" class="form-control fleet-input" id="at_insurance_provider" maxlength="150">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Insurance Policy Number</label>
            <input type="text" class="form-control fleet-input" id="at_insurance_policy" maxlength="80">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Insurance Expiry</label>
            <input type="date" class="form-control fleet-input" id="at_insurance_expiry">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Warranty Expiry</label>
            <input type="date" class="form-control fleet-input" id="at_warranty_expiry">
          </div>
          <div class="col-12">
             <label class="fleet-label">Inspection View Photos (JPG, PNG, or WebP; max 10 MB each)</label>
             <p class="small text-muted mb-2">Sample illustrations are shown until you choose your truck photos.</p>
             <div class="row g-2">
               <?php foreach (['front' => 'Front View', 'side' => 'Side View', 'rear' => 'Rear View', 'top' => 'Top View'] as $key => $label): ?>
               <div class="col-sm-6">
                 <img class="fleet-image-preview" id="at_image_preview_<?= $key ?>"
                      src="<?= APP_BASE ?>/assets/images/inspection/demo-truck-<?= $key ?>.svg"
                      alt="Sample truck <?= strtolower($label) ?> illustration">
                 <label class="small text-muted" for="at_image_<?= $key ?>"><?= $label ?></label>
                 <input type="file" class="form-control fleet-input" id="at_image_<?= $key ?>" accept=".jpg,.jpeg,.png,.webp">
               </div>
               <?php endforeach; ?>
             </div>
          </div>
        </div>
      </div>
      <div class="modal-footer fleet-modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm" id="submitAddTruckBtn">
          <span id="atBtnText"><i class="bi bi-plus-lg me-1"></i>Add Truck</span>
          <span id="atBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Edit Truck Modal ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="editTruckModal" tabindex="-1" aria-labelledby="editTruckLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content fleet-modal">
      <div class="modal-header fleet-modal-header-edit">
        <h5 class="modal-title" id="editTruckLabel">
          <i class="bi bi-pencil me-2"></i>Edit Truck
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body fleet-modal-body">
        <div id="editTruckAlert" class="alert d-none" role="alert"></div>
        <input type="hidden" id="et_id">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="fleet-label">Plate Number <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="et_plate" required>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Brand <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="et_brand" required>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Model <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="et_model" required>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Year Model <span class="text-danger">*</span></label>
            <input type="number" class="form-control fleet-input" id="et_year"
                   min="1990" max="<?= date('Y') + 1 ?>" required>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Body Type</label>
            <input type="text" class="form-control fleet-input" id="et_body" list="bodyTypeList">
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Fuel Type <span class="text-danger">*</span></label>
            <select class="form-select fleet-input" id="et_fuel" required>
              <option value="Diesel">Diesel</option>
              <option value="Gasoline">Gasoline</option>
              <option value="LPG">LPG</option>
              <option value="Electric">Electric</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Capacity (tons)</label>
            <input type="number" class="form-control fleet-input" id="et_capacity"
                   min="0" step="0.01">
          </div>
          <div class="col-md-6">
            <label class="fleet-label">Chassis Number</label>
            <input type="text" class="form-control fleet-input" id="et_chassis">
          </div>
          <div class="col-md-6">
            <label class="fleet-label">Engine Number</label>
            <input type="text" class="form-control fleet-input" id="et_engine">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Internal Unit Number</label>
            <input type="text" class="form-control fleet-input" id="et_unit_number" maxlength="30">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Truck Type</label>
            <select class="form-select fleet-input" id="et_truck_type" required>
              <option value="">Select truck category</option>
              <?php foreach (TRUCK_CATEGORIES as $truckCategory): ?>
              <option value="<?= htmlspecialchars($truckCategory) ?>"><?= htmlspecialchars($truckCategory) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">MV File Number</label>
            <input type="text" class="form-control fleet-input" id="et_mv_file" maxlength="50">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Registration Expiry</label>
            <input type="date" class="form-control fleet-input" id="et_registration_expiry">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Insurance Provider</label>
            <input type="text" class="form-control fleet-input" id="et_insurance_provider" maxlength="150">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Insurance Policy Number</label>
            <input type="text" class="form-control fleet-input" id="et_insurance_policy" maxlength="80">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Insurance Expiry</label>
            <input type="date" class="form-control fleet-input" id="et_insurance_expiry">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Warranty Expiry</label>
            <input type="date" class="form-control fleet-input" id="et_warranty_expiry">
          </div>
          <div class="col-12">
            <label class="fleet-label">Replace Inspection View Photos (optional)</label>
            <p class="small text-muted mb-2">Current photos or sample illustrations are shown below.</p>
            <div class="row g-2">
              <?php foreach (['front' => 'Front View', 'side' => 'Side View', 'rear' => 'Rear View', 'top' => 'Top View'] as $key => $label): ?>
              <div class="col-sm-6">
                <img class="fleet-image-preview" id="et_image_preview_<?= $key ?>"
                     src="<?= APP_BASE ?>/assets/images/inspection/demo-truck-<?= $key ?>.svg"
                     alt="Sample truck <?= strtolower($label) ?> illustration">
                <label class="small text-muted" for="et_image_<?= $key ?>"><?= $label ?></label>
                <input type="file" class="form-control fleet-input" id="et_image_<?= $key ?>" accept=".jpg,.jpeg,.png,.webp">
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer fleet-modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="submitEditTruckBtn">
          <span id="etBtnText"><i class="bi bi-check-lg me-1"></i>Save Changes</span>
          <span id="etBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ Status Update Modal (existing, preserved) ══════════════════════════ -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fleet-modal">
      <div class="modal-header fleet-modal-header-status">
        <h5 class="modal-title" id="statusModalLabel">
          <i class="bi bi-arrow-repeat me-2"></i>Update Truck Status
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body fleet-modal-body">
        <p class="text-muted mb-3">
          Truck: <strong id="modalPlate"></strong>
        </p>
        <input type="hidden" id="modalTruckId">
        <label class="fleet-label">New Status</label>
        <select class="form-select fleet-input" id="modalStatus">
          <?php /* Assigned / On Trip are set by the trip workflow only. */ ?>
          <?php foreach (TRUCK_MANUAL_STATUSES as $manualStatus): ?>
          <option value="<?= htmlspecialchars($manualStatus) ?>"><?= htmlspecialchars($manualStatus) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="fleet-label mt-3" for="modalStatusReason">Reason for change</label>
        <textarea class="form-control fleet-input" id="modalStatusReason" rows="2" maxlength="500"
                  placeholder="Briefly explain the status change" required></textarea>
      </div>
      <div class="modal-footer fleet-modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="confirmStatusBtn">
          <span id="statusBtnText"><i class="bi bi-check-lg me-1"></i>Update Status</span>
          <span id="statusBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="truckHistoryModal" tabindex="-1" aria-labelledby="truckHistoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content fleet-modal">
      <div class="modal-header fleet-modal-header-status">
        <h5 class="modal-title" id="truckHistoryModalLabel">
          <i class="bi bi-clock-history me-2"></i>Availability History — <span id="truckHistoryPlate"></span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body fleet-modal-body">
        <div id="truckHistoryAlert" class="alert d-none" role="alert"></div>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>Date and Time</th><th>Change</th><th>Reason</th><th>Changed By</th></tr></thead>
            <tbody id="truckHistoryBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

