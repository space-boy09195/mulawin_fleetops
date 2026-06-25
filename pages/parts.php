<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/parts.js';

layoutHead('Parts Inventory', APP_BASE . '/assets/css/parts.css');

$pdo = getDBConnection();

// ── Parts inventory ───────────────────────────────────────────────────────────
$partsSql = "
    SELECT
        p.part_id,
        p.part_number,
        p.part_name,
        p.category,
        p.unit,
        p.quantity,
        p.reorder_level,
        p.unit_cost,
        p.supplier,
        p.updated_at,
        (p.quantity <= p.reorder_level) AS is_low_stock
    FROM parts_inventory p
    ORDER BY p.category ASC, p.part_name ASC
";
$parts = $pdo->query($partsSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Recent movements ──────────────────────────────────────────────────────────
$movementsSql = "
    SELECT
        pm.movement_id,
        pm.movement_type,
        pm.quantity,
        pm.unit_cost,
        pm.reference_number,
        pm.notes,
        pm.moved_at,
        p.part_name,
        p.part_id,
        p.unit,
        u.full_name AS recorded_by_name,
        mr.record_id,
        mr.maintenance_type
    FROM parts_movements pm
    JOIN parts_inventory p ON pm.part_id      = p.part_id
    JOIN users u           ON pm.recorded_by  = u.user_id
    LEFT JOIN maintenance_records mr ON pm.maintenance_id = mr.record_id
    ORDER BY pm.moved_at DESC
    LIMIT 100
";
$movements = $pdo->query($movementsSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Categories for filter ─────────────────────────────────────────────────────
$categories = array_unique(array_column($parts, 'category'));
sort($categories);

// ── Low stock count for badge ─────────────────────────────────────────────────
$lowStockCount = count(array_filter($parts, fn($p) => $p['is_low_stock']));

$movementTypes = ['Stock In', 'Stock Out', 'Adjustment'];
?>

<div class="pts-page">

  <!-- Header -->
  <div class="pts-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="pts-title mb-0">Parts Inventory</h1>
      <p class="pts-subtitle mb-0">Stock levels, movements, and low-stock alerts</p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-pts-secondary" data-bs-toggle="modal" data-bs-target="#movementModal">
        <i class="bi bi-arrow-left-right me-1"></i> Record Movement
      </button>
      <?php if (currentRoleId() === ROLE_HEAD_MANAGEMENT): ?>
      <button class="btn btn-pts-primary" data-bs-toggle="modal" data-bs-target="#addPartModal">
        <i class="bi bi-plus-lg me-1"></i> Add Part
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="pts-cards mb-4">
    <div class="pts-card">
      <div class="pts-card-value"><?= count($parts) ?></div>
      <div class="pts-card-label">Total Parts</div>
    </div>
    <div class="pts-card <?= $lowStockCount > 0 ? 'pts-card-alert' : '' ?>">
      <div class="pts-card-value"><?= $lowStockCount ?></div>
      <div class="pts-card-label">Low / Out of Stock</div>
    </div>
    <div class="pts-card">
      <div class="pts-card-value"><?= count($categories) ?></div>
      <div class="pts-card-label">Categories</div>
    </div>
    <div class="pts-card">
      <div class="pts-card-value"><?= count($movements) ?></div>
      <div class="pts-card-label">Recent Movements</div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav pts-tabs mb-4" id="ptsTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="pts-tab active" id="tab-stock" data-bs-toggle="tab"
              data-bs-target="#pane-stock" type="button" role="tab">
        <i class="bi bi-boxes me-1"></i> Stock Levels
        <span class="pts-tab-count"><?= count($parts) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="pts-tab" id="tab-movements" data-bs-toggle="tab"
              data-bs-target="#pane-movements" type="button" role="tab">
        <i class="bi bi-arrow-left-right me-1"></i> Movements
        <span class="pts-tab-count"><?= count($movements) ?></span>
      </button>
    </li>
    <?php if ($lowStockCount > 0): ?>
    <li class="nav-item" role="presentation">
      <button class="pts-tab" id="tab-alerts" data-bs-toggle="tab"
              data-bs-target="#pane-alerts" type="button" role="tab">
        <i class="bi bi-exclamation-triangle me-1"></i> Low Stock
        <span class="pts-tab-count pts-tab-count-alert"><?= $lowStockCount ?></span>
      </button>
    </li>
    <?php endif; ?>
  </ul>

  <div class="tab-content">

    <!-- ── Stock Levels pane ─────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="pane-stock" role="tabpanel">
      <div class="pts-filters d-flex flex-wrap gap-2 mb-3">
        <select id="filterCategory" class="form-select pts-filter-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="filterStock" class="form-select pts-filter-select">
          <option value="">All Stock Levels</option>
          <option value="low">Low / Out of Stock</option>
          <option value="ok">Adequate Stock</option>
        </select>
        <input type="search" id="filterPartSearch" class="form-control pts-filter-search"
               placeholder="Search part name, number, supplier…">
      </div>

      <div class="pts-table-wrap">
        <?php if (empty($parts)): ?>
        <div class="pts-empty">
          <i class="bi bi-boxes pts-empty-icon"></i>
          <p>No parts in inventory yet.</p>
        </div>
        <?php else: ?>
        <table class="table pts-table" id="stockTable">
          <thead>
            <tr>
              <th>Part</th>
              <th>Category</th>
              <th>Part No.</th>
              <th>Supplier</th>
              <th>Unit Cost</th>
              <th>Stock</th>
              <th>Reorder At</th>
              <th>Status</th>
              <th>Last Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($parts as $part): ?>
            <?php
              $stockPct    = $part['reorder_level'] > 0
                ? min(100, round(($part['quantity'] / ($part['reorder_level'] * 2)) * 100))
                : 100;
              $stockCls    = $part['quantity'] === 0 ? 'pts-stock-zero'
                : ($part['is_low_stock'] ? 'pts-stock-low' : 'pts-stock-ok');
              $statusLabel = $part['quantity'] === 0 ? 'Out of Stock'
                : ($part['is_low_stock'] ? 'Low Stock' : 'In Stock');
              $statusCls   = $part['quantity'] === 0 ? 'pts-badge-out'
                : ($part['is_low_stock'] ? 'pts-badge-low' : 'pts-badge-ok');
            ?>
            <tr
              data-category="<?= htmlspecialchars($part['category']) ?>"
              data-low="<?= $part['is_low_stock'] ? 'low' : 'ok' ?>"
              data-search="<?= htmlspecialchars(strtolower(
                $part['part_name'] . ' ' . ($part['part_number'] ?? '') . ' ' . ($part['supplier'] ?? '')
              )) ?>"
            >
              <td>
                <div class="pts-part-label">
                  <span class="pts-part-name"><?= htmlspecialchars($part['part_name']) ?></span>
                </div>
              </td>
              <td><span class="pts-category-chip"><?= htmlspecialchars($part['category']) ?></span></td>
              <td class="pts-part-number">
                <?= $part['part_number'] ? htmlspecialchars($part['part_number']) : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="pts-supplier">
                <?= $part['supplier'] ? htmlspecialchars($part['supplier']) : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="pts-cost">
                <?= $part['unit_cost'] !== null ? '₱' . number_format($part['unit_cost'], 2) : '<span class="text-muted">—</span>' ?>
              </td>
              <td>
                <div class="pts-stock-cell">
                  <span class="pts-qty <?= $stockCls ?>">
                    <?= number_format($part['quantity']) ?> <?= htmlspecialchars($part['unit']) ?>
                  </span>
                  <div class="pts-stock-bar">
                    <div class="pts-stock-fill <?= $stockCls ?>" style="width:<?= $stockPct ?>%"></div>
                  </div>
                </div>
              </td>
              <td class="pts-reorder">
                <?= number_format($part['reorder_level']) ?> <?= htmlspecialchars($part['unit']) ?>
              </td>
              <td><span class="pts-status-badge <?= $statusCls ?>"><?= $statusLabel ?></span></td>
              <td class="pts-date"><?= date('M d, Y', strtotime($part['updated_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Movements pane ───────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="pane-movements" role="tabpanel">
      <div class="pts-filters d-flex flex-wrap gap-2 mb-3">
        <select id="filterMovType" class="form-select pts-filter-select">
          <option value="">All Types</option>
          <?php foreach ($movementTypes as $mt): ?>
          <option value="<?= $mt ?>"><?= $mt ?></option>
          <?php endforeach; ?>
        </select>
        <input type="search" id="filterMovSearch" class="form-control pts-filter-search"
               placeholder="Search part, reference…">
      </div>

      <div class="pts-table-wrap">
        <?php if (empty($movements)): ?>
        <div class="pts-empty">
          <i class="bi bi-arrow-left-right pts-empty-icon"></i>
          <p>No movements recorded yet.</p>
        </div>
        <?php else: ?>
        <table class="table pts-table" id="movementsTable">
          <thead>
            <tr>
              <th>Part</th>
              <th>Type</th>
              <th>Quantity</th>
              <th>Unit Cost</th>
              <th>Reference No.</th>
              <th>Linked Job</th>
              <th>Notes</th>
              <th>Recorded By</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($movements as $mov): ?>
            <tr
              data-movtype="<?= htmlspecialchars($mov['movement_type']) ?>"
              data-search="<?= htmlspecialchars(strtolower(
                $mov['part_name'] . ' ' . ($mov['reference_number'] ?? '')
              )) ?>"
            >
              <td class="pts-part-name"><?= htmlspecialchars($mov['part_name']) ?></td>
              <td>
                <span class="pts-mov-badge pts-mov-<?= strtolower(str_replace(' ', '-', $mov['movement_type'])) ?>">
                  <?= $mov['quantity'] > 0 ? '+' : '' ?><?= htmlspecialchars($mov['movement_type']) ?>
                </span>
              </td>
              <td class="pts-mov-qty <?= $mov['quantity'] > 0 ? 'pts-qty-in' : 'pts-qty-out' ?>">
                <?= ($mov['quantity'] > 0 ? '+' : '') . number_format($mov['quantity']) ?>
                <?= htmlspecialchars($mov['unit']) ?>
              </td>
              <td class="pts-cost">
                <?= $mov['unit_cost'] !== null ? '₱' . number_format($mov['unit_cost'], 2) : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="pts-ref">
                <?= $mov['reference_number'] ? htmlspecialchars($mov['reference_number']) : '<span class="text-muted">—</span>' ?>
              </td>
              <td>
                <?php if ($mov['record_id']): ?>
                <span class="pts-job-link">
                  <i class="bi bi-wrench"></i>
                  <?= htmlspecialchars($mov['maintenance_type']) ?> #<?= $mov['record_id'] ?>
                </span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="pts-desc-cell">
                <span class="pts-desc-text" title="<?= htmlspecialchars($mov['notes'] ?? '') ?>">
                  <?= $mov['notes'] ? htmlspecialchars($mov['notes']) : '<span class="text-muted">—</span>' ?>
                </span>
              </td>
              <td><?= htmlspecialchars($mov['recorded_by_name']) ?></td>
              <td class="pts-date"><?= date('M d, Y H:i', strtotime($mov['moved_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Low Stock pane ───────────────────────────────────────────────── -->
    <?php if ($lowStockCount > 0): ?>
    <div class="tab-pane fade" id="pane-alerts" role="tabpanel">
      <div class="pts-table-wrap">
        <table class="table pts-table">
          <thead>
            <tr>
              <th>Part</th>
              <th>Category</th>
              <th>Supplier</th>
              <th>Current Stock</th>
              <th>Reorder Level</th>
              <th>Shortage</th>
              <th>Unit Cost</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($parts as $part):
              if (!$part['is_low_stock']) continue;
              $shortage = $part['reorder_level'] - $part['quantity'];
            ?>
            <tr>
              <td class="pts-part-name"><?= htmlspecialchars($part['part_name']) ?></td>
              <td><span class="pts-category-chip"><?= htmlspecialchars($part['category']) ?></span></td>
              <td><?= $part['supplier'] ? htmlspecialchars($part['supplier']) : '<span class="text-muted">—</span>' ?></td>
              <td>
                <span class="pts-qty <?= $part['quantity'] === 0 ? 'pts-stock-zero' : 'pts-stock-low' ?>">
                  <?= number_format($part['quantity']) ?> <?= htmlspecialchars($part['unit']) ?>
                </span>
              </td>
              <td class="pts-reorder">
                <?= number_format($part['reorder_level']) ?> <?= htmlspecialchars($part['unit']) ?>
              </td>
              <td class="pts-shortage">
                <?= number_format($shortage) ?> <?= htmlspecialchars($part['unit']) ?>
              </td>
              <td class="pts-cost">
                <?= $part['unit_cost'] !== null ? '₱' . number_format($part['unit_cost'], 2) : '<span class="text-muted">—</span>' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /tab-content -->
</div>

<!-- ══ Add Part Modal ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="addPartModal" tabindex="-1" aria-labelledby="addPartLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content pts-modal-content">
      <div class="modal-header pts-modal-header-primary">
        <h5 class="modal-title" id="addPartLabel">
          <i class="bi bi-plus-lg me-2"></i>Add Part
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pts-modal-body">
        <div id="addPartAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label pts-label" for="apName">Part Name</label>
            <input type="text" class="form-control pts-input" id="apName"
                   placeholder="e.g. Oil Filter" required>
          </div>
          <div class="col-md-4">
            <label class="form-label pts-label" for="apPartNumber">Part Number</label>
            <input type="text" class="form-control pts-input" id="apPartNumber"
                   placeholder="Optional">
          </div>
          <div class="col-md-6">
            <label class="form-label pts-label" for="apCategory">Category</label>
            <input type="text" class="form-control pts-input" id="apCategory"
                   placeholder="e.g. Engine, Tires, Brakes" list="categoryList" required>
            <datalist id="categoryList">
              <?php foreach ($categories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>">
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="col-md-3">
            <label class="form-label pts-label" for="apUnit">Unit</label>
            <input type="text" class="form-control pts-input" id="apUnit"
                   placeholder="pcs, liters…" value="pcs" required>
          </div>
          <div class="col-md-3">
            <label class="form-label pts-label" for="apReorderLevel">Reorder Level</label>
            <input type="number" class="form-control pts-input" id="apReorderLevel"
                   min="0" value="5" required>
          </div>
          <div class="col-md-4">
            <label class="form-label pts-label" for="apUnitCost">Unit Cost (₱)</label>
            <input type="number" class="form-control pts-input" id="apUnitCost"
                   min="0" step="0.01" placeholder="Optional">
          </div>
          <div class="col-md-4">
            <label class="form-label pts-label" for="apInitialQty">Initial Quantity</label>
            <input type="number" class="form-control pts-input" id="apInitialQty"
                   min="0" value="0" required>
          </div>
          <div class="col-md-4">
            <label class="form-label pts-label" for="apSupplier">Supplier</label>
            <input type="text" class="form-control pts-input" id="apSupplier"
                   placeholder="Optional">
          </div>
        </div>
      </div>
      <div class="modal-footer pts-modal-footer">
        <button type="button" class="btn btn-pts-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-pts-primary" id="submitAddPartBtn">
          <span id="apBtnText">Add Part</span>
          <span id="apBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Record Movement Modal ══════════════════════════════════════════════ -->
<div class="modal fade" id="movementModal" tabindex="-1" aria-labelledby="movementLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content pts-modal-content">
      <div class="modal-header pts-modal-header-secondary">
        <h5 class="modal-title" id="movementLabel">
          <i class="bi bi-arrow-left-right me-2"></i>Record Movement
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pts-modal-body">
        <div id="movementAlert" class="alert d-none" role="alert"></div>

        <div class="mb-3">
          <label class="form-label pts-label" for="movPartId">Part</label>
          <select class="form-select pts-input" id="movPartId" required>
            <option value="">— Select part —</option>
            <?php foreach ($parts as $part): ?>
            <option value="<?= $part['part_id'] ?>"
                    data-unit="<?= htmlspecialchars($part['unit']) ?>"
                    data-stock="<?= $part['quantity'] ?>">
              <?= htmlspecialchars($part['part_name']) ?>
              (<?= number_format($part['quantity']) ?> <?= htmlspecialchars($part['unit']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label pts-label" for="movType">Movement Type</label>
          <select class="form-select pts-input" id="movType" required>
            <option value="">— Select type —</option>
            <?php foreach ($movementTypes as $mt): ?>
            <option value="<?= $mt ?>"><?= $mt ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-6">
            <label class="form-label pts-label" for="movQty">
              Quantity <span id="movUnitLabel" class="pts-unit-hint"></span>
            </label>
            <input type="number" class="form-control pts-input" id="movQty"
                   min="1" placeholder="0" required>
          </div>
          <div class="col-6">
            <label class="form-label pts-label" for="movUnitCost">Unit Cost (₱)</label>
            <input type="number" class="form-control pts-input" id="movUnitCost"
                   min="0" step="0.01" placeholder="Stock In only">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label pts-label" for="movReference">Reference No.</label>
          <input type="text" class="form-control pts-input" id="movReference"
                 placeholder="PO no., receipt no., etc.">
        </div>

        <div class="mb-1">
          <label class="form-label pts-label" for="movNotes">Notes</label>
          <textarea class="form-control pts-input" id="movNotes" rows="2"
                    placeholder="Optional remarks…"></textarea>
        </div>
      </div>
      <div class="modal-footer pts-modal-footer">
        <button type="button" class="btn btn-pts-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-pts-secondary" id="submitMovementBtn">
          <span id="movBtnText">Record Movement</span>
          <span id="movBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>