<?php
// ============================================================
// includes/recruitment.php
// Recruitment on the target recruitment_records table (applicant_name,
// position, contact_number, application_date, status, notes, created_by).
// The target has no candidate number, email or source column:
//   candidate number = APP-<recruitment_id> (display / lookup only)
//   email and source = labelled first lines of notes
// Status values are the target ENUM.
// ============================================================

const RECRUITMENT_STATUSES = ['Applicant', 'Screening', 'Interview', 'Accepted', 'Rejected', 'Withdrawn'];

function recruitmentCandidateNumber(int $recruitmentId): string {
    return sprintf('APP-%06d', $recruitmentId);
}

function recruitmentIdFromNumber(string $candidateNumber): ?int {
    return preg_match('/^APP-(\d{1,18})$/', $candidateNumber, $m) ? (int)$m[1] : null;
}

function recruitmentComposeNotes(?string $email, ?string $source, ?string $notes): ?string {
    $lines = [];
    if ($email !== null && $email !== '') $lines[] = 'Email: ' . $email;
    if ($source !== null && $source !== '') $lines[] = 'Source: ' . $source;
    if ($notes !== null && $notes !== '') $lines[] = $notes;
    return $lines ? implode("\n", $lines) : null;
}

/**
 * ['email' => ?, 'source' => ?, 'notes' => ?] from stored notes.
 */
function recruitmentSplitNotes(?string $stored): array {
    $result = ['email' => null, 'source' => null, 'notes' => null];
    $lines = $stored === null ? [] : preg_split('/\R/', $stored);
    while ($lines && preg_match('/^(Email|Source): (.*)$/', (string)$lines[0], $m)) {
        $result[strtolower($m[1])] = $m[2];
        array_shift($lines);
    }
    $rest = trim(implode("\n", $lines));
    $result['notes'] = $rest === '' ? null : $rest;
    return $result;
}
