<?php
// ============================================================
// includes/inspections.php
// Vehicle inspections and the pre-trip checklist on the target schema.
//
//   form                      target storage
//   pre-trip checklist        vehicle_inspections (Departure) + findings on
//                             template 'Pre-trip checklist'
//   departure inspection      vehicle_inspections (Departure) + findings on
//                             template 'Standard vehicle inspection'
//   return (arrival) insp.    vehicle_inspections (Arrival) + findings on
//                             template 'Standard vehicle inspection'
//   general inspection        maintenance_records (Inspection,
//   (no trip)                 record_source 'inspection', T-M2) +
//                             maintenance_findings
// Templates and items: seed draft T-N1. A finding's condition maps to
// inspection_findings.result: Good -> Good, Not Checked -> N/A, everything
// else -> Flagged (the condition is kept in condition_description).
//
// Minimum completion time: a server-side timer kept in the PHP session
// (one-time token, 2-hour expiry, bound to the user, form and target).
// The target has no maintenance_form_timers table; the measured time is
// stored on vehicle_inspections (started_at / completed_at /
// duration_seconds).
// ============================================================

const INSPECTION_FORM_TEMPLATES = [
    'checklist'            => ['Pre-trip checklist', 'Departure'],
    'inspection_departure' => ['Standard vehicle inspection', 'Departure'],
    'inspection_return'    => ['Standard vehicle inspection', 'Arrival'],
];
const INSPECTION_FORM_TYPES = ['checklist', 'inspection_general', 'inspection_departure', 'inspection_return'];
const INSPECTION_TIMER_TTL_SECONDS = 7200;
const INSPECTION_TIMER_MAX_OPEN = 20;

// Legacy checklist field => template item name.
const CHECKLIST_ITEM_FIELDS = [
    'lights_ok'      => 'Lights',
    'tires_ok'       => 'Tires',
    'tools_ok'       => 'Tools',
    'medical_kit_ok' => 'Medical Kit',
    'license_ok'     => "Driver's License",
    'or_cr_ok'       => 'OR/CR',
    'waybill_ok'     => 'Waybill',
    'fuel_po_ok'     => 'Fuel PO',
];

function inspectionConditionResult(string $condition): string {
    return match ($condition) {
        'Good'        => 'Good',
        'Not Checked' => 'N/A',
        default       => 'Flagged',
    };
}

function inspectionConditionSeverity(string $condition): string {
    return match ($condition) {
        'Good', 'Not Checked'   => 'Low',
        'Worn', 'Needs Attention' => 'Medium',
        default                 => 'High',
    };
}

// ---- Session timer -------------------------------------------------------

function startInspectionFormTimer(string $formType, int $targetId): string {
    if (!in_array($formType, INSPECTION_FORM_TYPES, true)) {
        throw new InvalidArgumentException('Invalid form type.');
    }
    $now = time();
    $timers = is_array($_SESSION['inspection_timers'] ?? null) ? $_SESSION['inspection_timers'] : [];
    foreach ($timers as $hash => $timer) {
        if (!is_array($timer) || ($timer['expires'] ?? 0) <= $now) {
            unset($timers[$hash]);
        }
    }
    // Keep the newest timers only, so a session cannot grow without bound.
    uasort($timers, static fn(array $a, array $b): int => $a['started'] <=> $b['started']);
    while (count($timers) >= INSPECTION_TIMER_MAX_OPEN) {
        array_shift($timers);
    }
    $token = bin2hex(random_bytes(32));
    $timers[hash('sha256', $token)] = [
        'user'    => currentUserId(),
        'form'    => $formType,
        'target'  => $targetId,
        'started' => $now,
        'expires' => $now + INSPECTION_TIMER_TTL_SECONDS,
    ];
    $_SESSION['inspection_timers'] = $timers;
    return $token;
}

/**
 * Consume a timer started by startInspectionFormTimer(). Returns the start
 * time (Unix seconds). Throws DomainException with the same messages as the
 * legacy database timer.
 */
function consumeInspectionFormTimer(string $token, string $formType, int $targetId, int $minimumSeconds): int {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new DomainException('Start the form timer before submitting.');
    }
    $hash = hash('sha256', $token);
    $timer = $_SESSION['inspection_timers'][$hash] ?? null;
    if (!is_array($timer)
        || (int)$timer['user'] !== currentUserId()
        || $timer['form'] !== $formType
        || (int)$timer['target'] !== $targetId) {
        throw new DomainException('This form timer is invalid or has already been used. Start again.');
    }
    if ((int)$timer['expires'] <= time()) {
        unset($_SESSION['inspection_timers'][$hash]);
        throw new DomainException('This form timer expired. Start again before submitting.');
    }
    $elapsed = time() - (int)$timer['started'];
    if ($elapsed < $minimumSeconds) {
        $remaining = $minimumSeconds - $elapsed;
        throw new DomainException("Please wait {$remaining} more seconds before submitting.");
    }
    unset($_SESSION['inspection_timers'][$hash]);
    return (int)$timer['started'];
}

/**
 * Put a consumed timer back (the save failed after consuming it), so the
 * user can retry without waiting again.
 */
function restoreInspectionFormTimer(string $token, string $formType, int $targetId, int $startedAt): void {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return;
    }
    $_SESSION['inspection_timers'][hash('sha256', $token)] = [
        'user'    => currentUserId(),
        'form'    => $formType,
        'target'  => $targetId,
        'started' => $startedAt,
        'expires' => $startedAt + INSPECTION_TIMER_TTL_SECONDS,
    ];
}

// ---- Templates -------------------------------------------------------------

/**
 * Active template for a form type: ['template_id', 'minimum_duration_seconds']
 * or null when it is not configured (seed T-N1 not applied).
 */
function inspectionTemplateForForm(PDO $pdo, string $formType): ?array {
    if (!isset(INSPECTION_FORM_TEMPLATES[$formType])) {
        return null;
    }
    [$name, $stage] = INSPECTION_FORM_TEMPLATES[$formType];
    $stmt = $pdo->prepare(
        'SELECT template_id, minimum_duration_seconds
         FROM inspection_templates
         WHERE template_name = ? AND inspection_stage = ? AND is_active = 1'
    );
    $stmt->execute([$name, $stage]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function minimumInspectionSeconds(?array $template): int {
    return max(
        (int)MAINTENANCE_FORM_MIN_DURATION_SECONDS,
        $template ? (int)$template['minimum_duration_seconds'] : 0
    );
}

/**
 * "category:item_name" => item row for a template (active and inactive).
 */
function inspectionTemplateItems(PDO $pdo, int $templateId): array {
    $stmt = $pdo->prepare(
        'SELECT inspection_item_id, item_name, category, is_required, is_active
         FROM inspection_items WHERE template_id = ?'
    );
    $stmt->execute([$templateId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items[$item['category'] . ':' . $item['item_name']] = $item;
    }
    return $items;
}

/**
 * Item ID for a part. A part that is not on the template (a custom part
 * added on the form) becomes an inactive, optional item of the template, so
 * it can carry a finding without changing what future inspections require.
 */
function resolveInspectionItem(PDO $pdo, int $templateId, array &$items, string $category, string $name): int {
    $key = $category . ':' . $name;
    if (isset($items[$key])) {
        return (int)$items[$key]['inspection_item_id'];
    }
    $pdo->prepare(
        'INSERT INTO inspection_items (template_id, item_name, category, is_required, sort_order, is_active)
         VALUES (?, ?, ?, 0, 999, 0)'
    )->execute([$templateId, mb_substr($name, 0, 150, 'UTF-8'), mb_substr($category, 0, 80, 'UTF-8')]);
    $id = (int)$pdo->lastInsertId();
    $items[$key] = ['inspection_item_id' => $id, 'item_name' => $name, 'category' => $category, 'is_required' => 0, 'is_active' => 0];
    return $id;
}

/**
 * Completed inspections of one template for a trip (any result).
 */
function tripTemplateInspectionResults(PDO $pdo, int $tripId, int $templateId): array {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT vi.inspection_id, vi.overall_result
         FROM vehicle_inspections vi
         JOIN inspection_findings f ON f.inspection_id = vi.inspection_id
         JOIN inspection_items ii ON ii.inspection_item_id = f.inspection_item_id
         WHERE vi.trip_id = ? AND vi.completed_at IS NOT NULL AND ii.template_id = ?'
    );
    $stmt->execute([$tripId, $templateId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Insert a completed trip inspection with its findings.
 * $findings: list of ['category','name','condition','notes'].
 */
function saveTripInspection(
    PDO $pdo,
    int $tripId,
    int $truckId,
    string $stage,
    int $templateId,
    array $findings,
    string $overallResult,
    ?string $remarks,
    int $startedAt,
    int $userId
): int {
    $pdo->prepare(
        'INSERT INTO vehicle_inspections
            (trip_id, truck_id, inspection_stage, inspected_by, started_at, completed_at,
             duration_seconds, overall_result, remarks)
         VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), NOW(), GREATEST(UNIX_TIMESTAMP(NOW()) - ?, 0), ?, ?)'
    )->execute([$tripId, $truckId, $stage, $userId, $startedAt, $startedAt, $overallResult, $remarks]);
    $inspectionId = (int)$pdo->lastInsertId();
    $items = inspectionTemplateItems($pdo, $templateId);
    $insert = $pdo->prepare(
        'INSERT INTO inspection_findings
            (inspection_id, inspection_item_id, result, condition_description, damage_description)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($findings as $finding) {
        $itemId = resolveInspectionItem($pdo, $templateId, $items, $finding['category'], $finding['name']);
        $result = inspectionConditionResult($finding['condition']);
        $notes = $finding['notes'] ?? null;
        $insert->execute([
            $inspectionId,
            $itemId,
            $result,
            $finding['condition'] . ($notes !== null && $notes !== '' ? ' — ' . $notes : ''),
            $result === 'Flagged' ? $notes : null,
        ]);
    }
    return $inspectionId;
}
