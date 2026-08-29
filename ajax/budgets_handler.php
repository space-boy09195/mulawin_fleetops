<?php
// ============================================================
// ajax/budgets_handler.php
// action=list — return current budget amounts for a set of
//                calendar months, for the Set Budgets modal.
// action=save — upsert budget amounts for one or more
//                category+month combinations.
// Head Management only — budgets are a planning/control-level
// input, consistent with how truck and employee management are
// already restricted to this role elsewhere in the system.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

const ALLOWED_CATEGORIES = ['Revenue', 'Maintenance', 'Fuel', 'Toll', 'Driver Allowance', 'Other'];

// ── List current budget values for the given months ──────────────────────────
if ($action === 'list') {
    $months = json_decode($_POST['months'] ?? '[]', true);
    if (!is_array($months) || empty($months)) {
        echo json_encode(['success' => false, 'message' => 'No months provided.']);
        exit;
    }

    // Validate each month is a clean Y-m-01 date before it touches SQL.
    $cleanMonths = [];
    foreach ($months as $m) {
        if (preg_match('/^\d{4}-\d{2}-01$/', $m)) {
            $cleanMonths[] = $m;
        }
    }
    if (empty($cleanMonths)) {
        echo json_encode(['success' => false, 'message' => 'No valid months provided.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($cleanMonths), '?'));
    $stmt = $pdo->prepare("
        SELECT category, DATE_FORMAT(period_month, '%Y-%m-01') AS period_month, amount
        FROM budgets
        WHERE period_month IN ($placeholders)
    ");
    $stmt->execute($cleanMonths);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'budgets' => $rows]);
    exit;
}

// ── Save (upsert) one or more budget entries ──────────────────────────────────
if ($action === 'save') {
    $entries = json_decode($_POST['entries'] ?? '[]', true);
    if (!is_array($entries) || empty($entries)) {
        echo json_encode(['success' => false, 'message' => 'No budget entries provided.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO budgets (category, period_month, amount, set_by)
        VALUES (:category, :period_month, :amount, :set_by)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), set_by = VALUES(set_by)
    ");

    $userId = currentUserId();
    $saved  = 0;

    foreach ($entries as $entry) {
        $category = $entry['category'] ?? '';
        $month    = $entry['period_month'] ?? '';
        $amount   = $entry['amount'] ?? null;

        if (!in_array($category, ALLOWED_CATEGORIES, true)) continue;
        if (!preg_match('/^\d{4}-\d{2}-01$/', $month)) continue;
        if (!is_numeric($amount) || (float)$amount < 0) continue;

        $stmt->execute([
            ':category'     => $category,
            ':period_month' => $month,
            ':amount'       => (float)$amount,
            ':set_by'       => $userId,
        ]);
        $saved++;
    }

    if ($saved > 0) {
        auditLog('UPDATE', 'budgets', null, null, 'Updated ' . $saved . ' budget entr' . ($saved === 1 ? 'y' : 'ies'));
    }

    echo json_encode(['success' => $saved > 0, 'saved' => $saved]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);