<?php
http_response_code(404);
exit;

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/modules/people/recruitment.php';

requireAnyPermission(['hr.recruitment.view', 'hr.recruitment.manage']);
$pdo = getDBConnection();
$canManage = currentUserHasAnyPermission(['hr.recruitment.manage']);
$rows = $pdo->query(
    'SELECT recruitment_id, applicant_name AS full_name, position AS desired_position,
            contact_number AS phone, application_date AS applied_on, status, notes
     FROM recruitment_records ORDER BY application_date DESC, recruitment_id DESC LIMIT 300'
)->fetchAll(PDO::FETCH_ASSOC);
// Candidate number, email and source in the legacy shape (includes/recruitment.php).
$candidates = [];
foreach ($rows as $row) {
    $parts = recruitmentSplitNotes($row['notes']);
    $row['candidate_number'] = recruitmentCandidateNumber((int)$row['recruitment_id']);
    $row['email'] = $parts['email'];
    $row['source'] = $parts['source'];
    $row['notes'] = $parts['notes'];
    $candidates[] = $row;
}
$statuses = RECRUITMENT_STATUSES;
layoutHead('Recruitment');
?>
<div class="page-header"><h1 class="page-title">Recruitment</h1><p class="page-subtitle">Track applicants through screening, interviews, and acceptance.</p></div>
<div id="recruitmentFeedback" class="alert d-none" role="alert"></div>
<?php if ($canManage): ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Add candidate</h2></div><div class="card-body-custom">
<form id="candidateForm" class="row g-3">
<div class="col-md-4"><label class="form-label" for="candidateName">Full name</label><input class="form-control" id="candidateName" maxlength="180" required></div>
<div class="col-md-4"><label class="form-label" for="candidatePosition">Desired position</label><input class="form-control" id="candidatePosition" maxlength="100" required></div>
<div class="col-md-4"><label class="form-label" for="candidateDate">Application date</label><input class="form-control" id="candidateDate" type="date" value="<?= date('Y-m-d') ?>" required></div>
<div class="col-md-4"><label class="form-label" for="candidatePhone">Phone</label><input class="form-control" id="candidatePhone" maxlength="50"></div>
<div class="col-md-4"><label class="form-label" for="candidateEmail">Email</label><input class="form-control" id="candidateEmail" type="email" maxlength="150"></div>
<div class="col-md-4"><label class="form-label" for="candidateSource">Source</label><input class="form-control" id="candidateSource" maxlength="100" placeholder="Referral, job board, etc."></div>
<div class="col-12"><label class="form-label" for="candidateNotes">Notes</label><textarea class="form-control" id="candidateNotes" maxlength="5000" rows="2"></textarea></div>
<div class="col-12"><button class="btn btn-primary">Add candidate</button></div>
</form></div></div>
<?php endif; ?>
<div class="card"><div class="card-header-custom"><h2 class="card-title-custom">Candidates</h2></div><div class="table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>Candidate</th><th>Position</th><th>Applied</th><th>Contact</th><th>Source</th><th>Status</th><?php if ($canManage): ?><th>Update</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($candidates as $candidate): ?><tr data-candidate="<?= htmlspecialchars($candidate['candidate_number']) ?>">
<td><?= htmlspecialchars($candidate['full_name']) ?><br><span class="text-muted small"><?= htmlspecialchars($candidate['candidate_number']) ?></span></td>
<td><?= htmlspecialchars($candidate['desired_position']) ?></td><td><?= htmlspecialchars($candidate['applied_on']) ?></td>
<td><?= htmlspecialchars($candidate['phone'] ?? '') ?><br><?= htmlspecialchars($candidate['email'] ?? '') ?></td>
<td><?= htmlspecialchars($candidate['source'] ?? '—') ?></td>
<?php if ($canManage): ?><td><select class="form-select form-select-sm candidate-status"><?php foreach ($statuses as $status): ?><option <?= $candidate['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></td><td><button type="button" class="btn btn-sm btn-outline-primary save-candidate-status">Save</button></td>
<?php else: ?><td><?= htmlspecialchars($candidate['status']) ?></td><?php endif; ?>
</tr><?php endforeach; ?>
<?php if (!$candidates): ?><tr><td colspan="<?= $canManage ? 7 : 6 ?>" class="text-center text-muted py-4">No candidates recorded.</td></tr><?php endif; ?>
</tbody></table></div></div>
<script>
// window.sendAction (assets/js/layout.js): busy button, server-confirmed
// result, readable errors, entries kept on failure.
const recruitmentRequest = (data, options) => window.sendAction('<?= APP_BASE ?>/ajax/recruitment_handler.php', data, {
  feedback: document.getElementById('recruitmentFeedback'), ...options
});
document.getElementById('candidateForm')?.addEventListener('submit', event => {
  event.preventDefault();
  recruitmentRequest(new URLSearchParams({
    action:'create',
    full_name:document.getElementById('candidateName').value,
    desired_position:document.getElementById('candidatePosition').value,
    applied_on:document.getElementById('candidateDate').value,
    phone:document.getElementById('candidatePhone').value,
    email:document.getElementById('candidateEmail').value,
    source:document.getElementById('candidateSource').value,
    notes:document.getElementById('candidateNotes').value,
    [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN
  }), {label: 'add the candidate', form: event.target, button: event.submitter || event.target.querySelector('button')});
});
document.querySelectorAll('.save-candidate-status').forEach(button => button.addEventListener('click', () => {
  const row = button.closest('tr');
  recruitmentRequest(new URLSearchParams({action:'update_status', candidate_number:row.dataset.candidate, status:row.querySelector('.candidate-status').value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN}),
    {label: 'save the candidate status', button});
}));
</script>
<?php layoutFoot(); ?>
