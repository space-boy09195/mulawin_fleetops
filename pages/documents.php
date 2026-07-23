<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE, ROLE_ACCOUNTING]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/documents.js';

layoutHead('Documents', APP_BASE . '/assets/css/documents.css');

$pdo = getDBConnection();

// ── All documents ─────────────────────────────────────────────────────────────
$docsSql = "
    SELECT
        d.document_id,
        d.doc_type,
        d.file_name,
        d.stored_name,
        d.file_path,
        d.file_size,
        d.mime_type,
        d.description,
        d.uploaded_at,
        u.full_name  AS uploaded_by_name,
        t.trip_number
    FROM documents d
    JOIN users u       ON d.uploaded_by = u.user_id
    LEFT JOIN trips t  ON d.trip_id     = t.trip_id
    ORDER BY d.uploaded_at DESC
";
$documents = $pdo->query($docsSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Trips for optional trip link dropdown ─────────────────────────────────────
$trips = $pdo->query("
    SELECT trip_id, trip_number FROM trips
    ORDER BY trip_number DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$docTypes = [
    'OR/CR', 'Delivery Receipt', 'Waybill',
    'Maintenance Record', 'Billing Record',
    'Company Document', 'Other',
];

// ── Helper: format file size ──────────────────────────────────────────────────
function fmtSize(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}

// ── MIME → icon mapping ───────────────────────────────────────────────────────
function mimeIcon(?string $mime): string {
    if (!$mime) return 'bi-file-earmark';
    if (str_contains($mime, 'pdf'))   return 'bi-file-earmark-pdf';
    if (str_contains($mime, 'image')) return 'bi-file-earmark-image';
    if (str_contains($mime, 'word') || str_contains($mime, 'document'))
                                       return 'bi-file-earmark-word';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet'))
                                       return 'bi-file-earmark-excel';
    return 'bi-file-earmark';
}
?>

<div class="doc-page">

  <!-- Header -->
  <div class="doc-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="doc-title mb-0">Documents</h1>
      <p class="doc-subtitle mb-0">Upload and manage trip and company documents</p>
    </div>
    <button class="btn btn-doc-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
      <i class="bi bi-upload me-1"></i> Upload Document
    </button>
  </div>

  <!-- Filters -->
  <div class="doc-filters d-flex flex-wrap gap-2 mb-4">
    <select id="filterDocType" class="form-select doc-filter-select">
      <option value="">All Types</option>
      <?php foreach ($docTypes as $dt): ?>
      <option value="<?= htmlspecialchars($dt) ?>"><?= htmlspecialchars($dt) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" id="filterDocSearch" class="form-control doc-filter-search"
           placeholder="Search filename, description, trip…">
  </div>

  <!-- Document grid -->
  <?php if (empty($documents)): ?>
  <div class="doc-empty">
    <i class="bi bi-folder2-open doc-empty-icon"></i>
    <p>No documents uploaded yet.</p>
  </div>
  <?php else: ?>
  <div class="doc-grid" id="docGrid">
    <?php foreach ($documents as $doc): ?>
    <?php
      $icon    = mimeIcon($doc['mime_type']);
      $size    = fmtSize((int)$doc['file_size']);
      $isPdf   = str_contains($doc['mime_type'] ?? '', 'pdf');
      $isImage = str_contains($doc['mime_type'] ?? '', 'image');
      $canPreview = $isPdf || $isImage;
    ?>
    <div class="doc-card"
         data-type="<?= htmlspecialchars($doc['doc_type']) ?>"
         data-search="<?= htmlspecialchars(strtolower(
           $doc['file_name'] . ' ' . ($doc['description'] ?? '') . ' ' . ($doc['trip_number'] ?? '')
         )) ?>">

      <!-- File icon / type indicator -->
      <div class="doc-card-icon">
        <i class="bi <?= $icon ?>"></i>
      </div>

      <!-- Type badge -->
      <span class="doc-type-badge doc-type-<?= strtolower(str_replace(['/', ' '], '-', $doc['doc_type'])) ?>">
        <?= htmlspecialchars($doc['doc_type']) ?>
      </span>

      <!-- File name -->
      <div class="doc-card-name" title="<?= htmlspecialchars($doc['file_name']) ?>">
        <?= htmlspecialchars($doc['file_name']) ?>
      </div>

      <!-- Description -->
      <?php if ($doc['description']): ?>
      <div class="doc-card-desc" title="<?= htmlspecialchars($doc['description']) ?>">
        <?= htmlspecialchars($doc['description']) ?>
      </div>
      <?php endif; ?>

      <!-- Meta -->
      <div class="doc-card-meta">
        <span><i class="bi bi-person"></i> <?= htmlspecialchars($doc['uploaded_by_name']) ?></span>
        <?php if ($doc['trip_number']): ?>
        <span><i class="bi bi-truck"></i> <?= htmlspecialchars($doc['trip_number']) ?></span>
        <?php endif; ?>
        <span><i class="bi bi-hdd"></i> <?= $size ?></span>
        <span><i class="bi bi-calendar3"></i> <?= date('M d, Y', strtotime($doc['uploaded_at'])) ?></span>
      </div>

      <!-- Actions -->
      <div class="doc-card-actions">
        <a href="<?= APP_BASE . '/uploads/' . htmlspecialchars($doc['stored_name']) ?>"
           class="btn btn-doc-action" download="<?= htmlspecialchars($doc['file_name']) ?>"
           title="Download">
          <i class="bi bi-download"></i>
        </a>
        <?php if ($canPreview): ?>
        <a href="<?= APP_BASE . '/uploads/' . htmlspecialchars($doc['stored_name']) ?>"
           class="btn btn-doc-action" target="_blank" rel="noopener" title="Preview">
          <i class="bi bi-eye"></i>
        </a>
        <?php endif; ?>
        <?php if (in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING])
               || $doc['uploaded_by_name'] === ''): ?>
        <button class="btn btn-doc-action btn-doc-delete"
                data-id="<?= $doc['document_id'] ?>"
                data-name="<?= htmlspecialchars($doc['file_name']) ?>"
                title="Delete">
          <i class="bi bi-trash3"></i>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div id="noDocResults" class="no-results d-none">
    <i class="bi bi-search"></i>
    <span>No documents match your filters.</span>
  </div>
  <?php endif; ?>
</div>

<!-- ══ Upload Modal ════════════════════════════════════════════════════════ -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content doc-modal-content">
      <div class="modal-header doc-modal-header">
        <h5 class="modal-title" id="uploadModalLabel">
          <i class="bi bi-upload me-2"></i>Upload Document
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body doc-modal-body">
        <div id="uploadAlert" class="alert d-none" role="alert"></div>

        <!-- Drop zone -->
        <div id="dropZone" class="doc-dropzone mb-3">
          <i class="bi bi-cloud-upload doc-dropzone-icon"></i>
          <p class="doc-dropzone-text">Drag &amp; drop a file here, or <span class="doc-dropzone-link">browse</span></p>
          <p class="doc-dropzone-hint">PDF, JPG, PNG, DOCX, XLSX — max 10 MB</p>
          <input type="file" id="fileInput" class="doc-file-input"
                 accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.doc,.xls">
        </div>

        <!-- File preview -->
        <div id="filePreview" class="doc-file-preview d-none mb-3">
          <i class="bi bi-file-earmark doc-preview-icon" id="previewIcon"></i>
          <div class="doc-preview-info">
            <span id="previewName" class="doc-preview-name"></span>
            <span id="previewSize" class="doc-preview-size"></span>
          </div>
          <button type="button" class="btn-close doc-preview-clear" id="clearFile" aria-label="Remove file"></button>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label doc-label" for="uploadDocType">Document Type</label>
            <select class="form-select doc-input" id="uploadDocType" required>
              <option value="">— Select type —</option>
              <?php foreach ($docTypes as $dt): ?>
              <option value="<?= htmlspecialchars($dt) ?>"><?= htmlspecialchars($dt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label doc-label" for="uploadTripId">Link to Trip (optional)</label>
            <select class="form-select doc-input" id="uploadTripId">
              <option value="">— None —</option>
              <?php foreach ($trips as $trip): ?>
              <option value="<?= $trip['trip_id'] ?>"><?= htmlspecialchars($trip['trip_number']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label doc-label" for="uploadDescription">Description (optional)</label>
            <input type="text" class="form-control doc-input" id="uploadDescription"
                   placeholder="Short note about this document…" maxlength="255">
          </div>
        </div>

        <!-- Upload progress -->
        <div id="uploadProgress" class="doc-progress mt-3 d-none">
          <div class="doc-progress-bar" id="uploadProgressBar"></div>
        </div>
      </div>
      <div class="modal-footer doc-modal-footer">
        <button type="button" class="btn btn-doc-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-doc-primary" id="submitUploadBtn" disabled>
          <span id="uploadBtnText">Upload</span>
          <span id="uploadBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Delete Confirm Modal ════════════════════════════════════════════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content doc-modal-content">
      <div class="modal-header doc-modal-header-danger">
        <h5 class="modal-title" id="deleteModalLabel">
          <i class="bi bi-trash3 me-2"></i>Delete Document
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body doc-modal-body">
        <div id="deleteAlert" class="alert d-none" role="alert"></div>
        <p class="mb-0">
          Permanently delete <strong id="deleteFileName"></strong>?
          This cannot be undone.
        </p>
      </div>
      <div class="modal-footer doc-modal-footer">
        <button type="button" class="btn btn-doc-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-doc-danger" id="confirmDeleteBtn">
          <span id="deleteBtnText">Delete</span>
          <span id="deleteBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>