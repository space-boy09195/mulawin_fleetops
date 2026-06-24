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

$announcements = $pdo->query(
    "SELECT a.announcement_id, a.title, a.body, a.is_pinned, a.created_at,
            u.full_name AS author
       FROM announcements a
       JOIN users u ON a.created_by = u.user_id
      ORDER BY a.is_pinned DESC, a.created_at DESC"
)->fetchAll();

$isHead = currentRoleId() === ROLE_HEAD_MANAGEMENT;

layoutHead('Announcements', APP_BASE . '/assets/css/announcements.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Announcements</h1>
    <p class="page-subtitle"><?= count($announcements) ?> total announcement<?= count($announcements) !== 1 ? 's' : '' ?></p>
  </div>
  <?php if ($isHead): ?>
  <button class="btn btn-primary btn-sm d-flex align-items-center gap-2"
          data-bs-toggle="modal" data-bs-target="#addAnnModal">
    <i class="bi bi-plus-lg"></i> New Announcement
  </button>
  <?php endif; ?>
</div>

<?php if (empty($announcements)): ?>
<div class="card p-5 text-center text-muted">
  <i class="bi bi-megaphone" style="font-size:2.5rem;opacity:.3;"></i>
  <p class="mt-3 mb-0">No announcements yet.</p>
</div>
<?php else: ?>
<div class="ann-full-list">
  <?php foreach ($announcements as $a): ?>
  <div class="card ann-full-item mb-3" id="ann-full-<?= $a['announcement_id'] ?>">
    <div class="card-body-custom">
      <div class="d-flex align-items-start justify-content-between gap-3">
        <div class="flex-grow-1">
          <?php if ($a['is_pinned']): ?>
          <div class="ann-pin mb-1">
            <i class="bi bi-pin-fill"></i> Pinned
          </div>
          <?php endif; ?>
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
        <button class="ann-delete-btn flex-shrink-0"
                onclick="deleteAnnouncementFull(<?= $a['announcement_id'] ?>)"
                title="Delete">
          <i class="bi bi-trash"></i>
        </button>
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
<?php endif; ?>

<?php layoutFoot(); ?>