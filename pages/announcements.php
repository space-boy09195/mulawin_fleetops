<?php
// ============================================================
// pages/announcements.php
// All announcements — all roles can view
// Head Management can add and delete
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();

$GLOBALS['page_js'] = APP_BASE . '/assets/js/announcements.js';

$pdo = getDBConnection();

// ── Period filter (pinned announcements always show regardless of period) ────
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

$annStmt = $pdo->prepare(
    "SELECT a.announcement_id, a.title, a.body, a.is_pinned, a.priority, a.created_at,
            u.full_name AS author
       FROM announcements a
       JOIN users u ON a.created_by = u.user_id
      WHERE a.is_pinned = 1 " . ($rangeStartSql ? "OR (a.is_pinned = 0 AND a.created_at >= :rangeStart)" : "OR a.is_pinned = 0") . "
      ORDER BY a.is_pinned DESC, FIELD(a.priority, 'high', 'medium', 'low'), a.created_at DESC"
);
if ($rangeStartSql) $annStmt->bindValue(':rangeStart', $rangeStartSql);
$annStmt->execute();
$announcements = $annStmt->fetchAll();

$isHead = currentRoleId() === ROLE_HEAD_MANAGEMENT;

layoutHead('Announcements', APP_BASE . '/assets/css/announcements.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Announcements</h1>
    <p class="page-subtitle"><?= count($announcements) ?> total announcement<?= count($announcements) !== 1 ? 's' : '' ?></p>
  </div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <form method="get" class="d-flex">
      <select name="period" class="form-select form-select-sm ann-sort-select" onchange="this.form.submit()">
        <?php foreach ($periods as $key => $label): ?>
        <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <select id="annSortFilter" class="form-select form-select-sm ann-sort-select">
      <option value="default">Default (Pinned + Severity)</option>
      <option value="severity_desc">Severity: High to Low</option>
      <option value="severity_asc">Severity: Low to High</option>
      <option value="newest">Newest First</option>
      <option value="oldest">Oldest First</option>
    </select>
    <?php if ($isHead): ?>
    <button class="btn btn-primary btn-sm d-flex align-items-center gap-2"
            data-bs-toggle="modal" data-bs-target="#addAnnModal">
      <i class="bi bi-plus-lg"></i> New Announcement
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($announcements)): ?>
<div class="card p-5 text-center text-muted">
  <i class="bi bi-megaphone" style="font-size:2.5rem;opacity:.3;"></i>
  <p class="mt-3 mb-0">No announcements yet.</p>
</div>
<?php else: ?>
<div class="ann-full-list">
  <?php
  $severityRank = ['high' => 3, 'medium' => 2, 'low' => 1];
  $severityMeta = [
    'high'   => ['label' => 'High Priority',   'icon' => 'bi-exclamation-triangle-fill'],
    'medium' => ['label' => 'Medium Priority', 'icon' => 'bi-exclamation-circle-fill'],
    'low'    => ['label' => 'Low Priority',    'icon' => 'bi-info-circle-fill'],
  ];
  ?>
  <?php foreach ($announcements as $a): ?>
  <?php $sev = $a['priority'] ?? 'medium'; ?>
  <div class="card ann-full-item mb-3" id="ann-full-<?= $a['announcement_id'] ?>"
       data-pinned="<?= (int)$a['is_pinned'] ?>"
       data-severity="<?= htmlspecialchars($sev) ?>"
       data-severity-rank="<?= $severityRank[$sev] ?? 2 ?>"
       data-created="<?= strtotime($a['created_at']) ?>">
    <div class="card-body-custom">
      <div class="d-flex align-items-start justify-content-between gap-3">
        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <span class="ann-severity ann-severity-<?= htmlspecialchars($sev) ?>">
              <i class="bi <?= $severityMeta[$sev]['icon'] ?>"></i> <?= $severityMeta[$sev]['label'] ?>
            </span>
            <?php if ($a['is_pinned']): ?>
            <span class="ann-pin">
              <i class="bi bi-pin-fill"></i> Pinned
            </span>
            <?php endif; ?>
          </div>
          <h3 class="ann-full-title"><?= htmlspecialchars($a['title']) ?></h3>
          <p class="ann-full-body"><?= nl2br(htmlspecialchars($a['body'])) ?></p>
          <div class="ann-meta">
            <i class="bi bi-person-fill"></i> <?= htmlspecialchars($a['author']) ?>
            &nbsp;&middot;&nbsp;
            <i class="bi bi-clock"></i>
            <?= date('F j, Y \a\t g:i A', strtotime($a['created_at'])) ?>
          </div>
        </div>
        <?php if ($isHead): ?>
        <div class="d-flex gap-2 flex-shrink-0">
          <button class="ann-edit-btn"
                  onclick="openEditAnnouncement(<?= $a['announcement_id'] ?>, <?= htmlspecialchars(json_encode($a['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($a['body']), ENT_QUOTES) ?>, <?= (int)$a['is_pinned'] ?>, <?= htmlspecialchars(json_encode($sev), ENT_QUOTES) ?>)"
                  title="Edit">
            <i class="bi bi-pencil"></i>
          </button>
          <button class="ann-delete-btn"
                  onclick="deleteAnnouncementFull(<?= $a['announcement_id'] ?>)"
                  title="Delete">
            <i class="bi bi-trash"></i>
          </button>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ---- Add Modal (Head Management only) ------------------ -->
<?php if ($isHead): ?>
<div class="modal fade" id="addAnnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--card-bg);color:var(--text-primary);border:1px solid var(--card-border);">
      <div class="modal-header" style="border-color:var(--card-border);">
        <h5 class="modal-title"><i class="bi bi-megaphone me-2"></i>New Announcement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-600">Title <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="fullAnnTitle" maxlength="200" placeholder="Announcement title">
        </div>
        <div class="mb-3">
          <label class="form-label fw-600">Message <span class="text-danger">*</span></label>
          <textarea class="form-control" id="fullAnnBody" rows="4" placeholder="Write your announcement…"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label fw-600">Priority Level</label>
          <div class="d-flex gap-3 ann-severity-picker">
            <label class="ann-severity-option ann-severity-option-high">
              <input type="radio" name="fullAnnPriority" value="high">
              <span><i class="bi bi-exclamation-triangle-fill"></i> High</span>
            </label>
            <label class="ann-severity-option ann-severity-option-medium">
              <input type="radio" name="fullAnnPriority" value="medium" checked>
              <span><i class="bi bi-exclamation-circle-fill"></i> Medium</span>
            </label>
            <label class="ann-severity-option ann-severity-option-low">
              <input type="radio" name="fullAnnPriority" value="low">
              <span><i class="bi bi-info-circle-fill"></i> Low</span>
            </label>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <input type="checkbox" class="form-check-input" id="fullAnnPinned">
          <label class="form-check-label" for="fullAnnPinned" style="font-size:.875rem;">
            Pin this announcement (appears at the top)
          </label>
        </div>
        <div id="fullAnnError" class="alert alert-danger mt-3 d-none"></div>
      </div>
      <div class="modal-footer" style="border-color:var(--card-border);">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" id="submitFullAnnBtn">
          <i class="bi bi-megaphone me-1"></i>Post Announcement
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ---- Edit Modal (Head Management only) ------------------ -->
<div class="modal fade" id="editAnnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--card-bg);color:var(--text-primary);border:1px solid var(--card-border);">
      <div class="modal-header" style="border-color:var(--card-border);">
        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Announcement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editAnnId">
        <div class="mb-3">
          <label class="form-label fw-600">Title <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="editAnnTitle" maxlength="200" placeholder="Announcement title">
        </div>
        <div class="mb-3">
          <label class="form-label fw-600">Message <span class="text-danger">*</span></label>
          <textarea class="form-control" id="editAnnBody" rows="4" placeholder="Write your announcement…"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label fw-600">Priority Level</label>
          <div class="d-flex gap-3 ann-severity-picker">
            <label class="ann-severity-option ann-severity-option-high">
              <input type="radio" name="editAnnPriority" value="high">
              <span><i class="bi bi-exclamation-triangle-fill"></i> High</span>
            </label>
            <label class="ann-severity-option ann-severity-option-medium">
              <input type="radio" name="editAnnPriority" value="medium">
              <span><i class="bi bi-exclamation-circle-fill"></i> Medium</span>
            </label>
            <label class="ann-severity-option ann-severity-option-low">
              <input type="radio" name="editAnnPriority" value="low">
              <span><i class="bi bi-info-circle-fill"></i> Low</span>
            </label>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <input type="checkbox" class="form-check-input" id="editAnnPinned">
          <label class="form-check-label" for="editAnnPinned" style="font-size:.875rem;">
            Pin this announcement (appears at the top)
          </label>
        </div>
        <div id="editAnnError" class="alert alert-danger mt-3 d-none"></div>
      </div>
      <div class="modal-footer" style="border-color:var(--card-border);">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" id="submitEditAnnBtn">
          <i class="bi bi-check-lg me-1"></i>Save Changes
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>