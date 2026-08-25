<?php
// ============================================================
// pages/analytics.php
// Cross-functional analytics hub — Head Management only.
// Pulls together Operations, Maintenance, and Accounting data
// that no single role dashboard shows together.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/alerts.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE, ROLE_ACCOUNTING]);
$role   = currentRoleId();
$isHead = $role === ROLE_HEAD_MANAGEMENT;

$GLOBALS['page_js'] = APP_BASE . '/assets/js/analytics.js';
layoutHead('Analytics', APP_BASE . '/assets/css/analytics.css');

$pdo = getDBConnection();

// ── Period filter ──────────────────────────────────────────────────────────
$periods = [
    '1m'  => ['label' => 'This Month',      'months' => 1],
    '3m'  => ['label' => 'Last 3 Months',   'months' => 3],
    '6m'  => ['label' => 'Last 6 Months',   'months' => 6],
    '1y'  => ['label' => 'Last 12 Months',  'months' => 12],
    'all' => ['label' => 'All Time',        'months' => null],
];
$period = $_GET['period'] ?? '6m';
if (!isset($periods[$period])) $period = '6m';
$periodLabel = $periods[$period]['label'];
$months      = $periods[$period]['months'];

// ── Granularity filter (controls trend chart bucketing) ──────────────────────
$granularities = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'];
$requestedGranularity = $_GET['granularity'] ?? 'month';
if (!isset($granularities[$requestedGranularity])) $requestedGranularity = 'month';

// ── Resolve the actual date range (shared by KPIs, leaderboards, and trends) ──
$rangeEnd = new DateTime('today');
if ($months !== null) {
    $rangeStart = (new DateTime('today'))->modify("-$months months");
} else {
    // "All Time" — find the earliest relevant record, but clamp to 24 months
    // back so a Daily view over years of history doesn't produce an
    // unreasonably huge chart.
    $earliest = $pdo->query("
        SELECT MIN(d) FROM (
            SELECT MIN(created_at)     AS d FROM billings
            UNION ALL SELECT MIN(date_performed) FROM maintenance_records
            UNION ALL SELECT MIN(reported_at)    FROM incidents
        ) x
    ")->fetchColumn();
    $rangeStart = $earliest ? new DateTime($earliest) : (new DateTime('today'))->modify('-6 months');
    $clamp = (new DateTime('today'))->modify('-24 months');
    if ($rangeStart < $clamp) $rangeStart = $clamp;
}
$rangeStartSql = $rangeStart->format('Y-m-d 00:00:00');
$rangeDays     = (int)$rangeStart->diff($rangeEnd)->days;

// ── Guardrail: which granularities are practical for this date range? ────────
// Daily over a long range produces hundreds of dense, unreadable points.
// Monthly over a very short range produces only 1 bucket, which is degenerate.
$granularityAllowed = [
    'day'   => $rangeDays <= 92,   // ~3 months — beyond this, daily gets too dense
    'week'  => true,               // reasonable at any range, always available
    'month' => $rangeDays >= 32,   // needs at least ~1 month to be meaningful
];

// If the requested granularity isn't practical for this range, fall back to
// Weekly (always allowed above) rather than silently ignoring the request.
$granularity = $granularityAllowed[$requestedGranularity] ? $requestedGranularity : 'week';
$granularityLabel = $granularities[$granularity];
$granularityWasAdjusted = $granularity !== $requestedGranularity;

// Date filters as bindable clauses — $months === null (All Time) still uses
// the clamped $rangeStart so trend buckets stay bounded; KPIs/leaderboards
// use the same start for consistency across the page.
$tripDateFilter  = "AND t.created_at >= :rangeStart";
$billDateFilter  = "AND b.created_at >= :rangeStart";
$collDateFilter  = "AND c.payment_date >= :rangeStart";
$maintDateFilter = "AND date_performed >= :rangeStart";

// ── Bucket-expression helper for the selected granularity ────────────────────
function bucketExpr(string $col, string $granularity): string {
    return match ($granularity) {
        'day'   => "DATE_FORMAT($col, '%Y-%m-%d')",
        'week'  => "DATE_FORMAT(DATE_SUB($col, INTERVAL WEEKDAY($col) DAY), '%Y-%m-%d')",
        default => "DATE_FORMAT($col, '%Y-%m')", // month
    };
}

// Generates the full list of bucket keys + display labels between two dates,
// so months/weeks/days with zero activity still show as 0 instead of a gap.
function buildBuckets(DateTime $start, DateTime $end, string $granularity): array {
    $keys = [];
    $labels = [];
    $cursor = clone $start;

    if ($granularity === 'day') {
        $cursor->setTime(0, 0, 0);
        while ($cursor <= $end) {
            $keys[]   = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('M d');
            $cursor->modify('+1 day');
        }
    } elseif ($granularity === 'week') {
        $cursor->modify('monday this week');
        while ($cursor <= $end) {
            $keys[]   = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('M d');
            $cursor->modify('+7 days');
        }
    } else {
        $cursor->modify('first day of this month');
        while ($cursor <= $end) {
            $keys[]   = $cursor->format('Y-m');
            $labels[] = $cursor->format('M Y');
            $cursor->modify('first day of next month');
        }
    }
    return ['keys' => $keys, 'labels' => $labels];
}

$buckets = buildBuckets($rangeStart, $rangeEnd, $granularity);

// Runs a query that references :rangeStart, binding it once so every call
// site doesn't have to repeat the bind boilerplate.
function qRange(PDO $pdo, string $sql, string $rangeStartSql): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':rangeStart' => $rangeStartSql]);
    return $stmt;
}

// ══════════════════════════════════════════════════════════════════════════
// PERIOD-OVER-PERIOD COMPARISON
// Resolves an immediately-preceding window of the same length as the
// selected period, so KPI cards can show "+12.4% vs last period" alongside
// the raw number. "All Time" has no natural prior window, so comparison is
// simply turned off for that period rather than showing a misleading figure.
// ══════════════════════════════════════════════════════════════════════════
$hasComparison = $months !== null;
if ($hasComparison) {
    $prevRangeEnd      = clone $rangeStart;                 // exclusive upper bound
    $prevRangeStart     = (clone $rangeStart)->modify("-$months months");
    $prevRangeStartSql = $prevRangeStart->format('Y-m-d 00:00:00');
    $prevRangeEndSql   = $prevRangeEnd->format('Y-m-d 00:00:00');
} else {
    $prevRangeStartSql = null;
    $prevRangeEndSql   = null;
}

// Runs a query bound between the previous period's start (inclusive) and the
// current period's start (exclusive) — i.e. the window immediately before
// the one currently on screen.
function qPrev(PDO $pdo, string $sql, ?string $prevStart, ?string $prevEnd) {
    if ($prevStart === null) return null;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':prevStart' => $prevStart, ':prevEnd' => $prevEnd]);
    return $stmt;
}

// Percent change from $previous to $current. Returns null (rather than a
// divide-by-zero or a misleading 0%) when there's no previous-period
// baseline to compare against.
function pctChange(float $current, float $previous): ?float {
    if ($previous == 0.0) {
        return $current == 0.0 ? 0.0 : null;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

// Renders a small "▲ +12.4% vs last period" badge for a KPI card, comparing
// raw amounts/counts. $higherIsBetter flips which direction is shown as
// good (green) vs bad (red) — e.g. rising revenue is good, rising cost isn't.
function trendBadge($current, $previous, bool $higherIsBetter = true, bool $hasComparison = true): string {
    if (!$hasComparison || $previous === null) return '';
    $pct = pctChange((float)$current, (float)$previous);
    if ($pct === null) {
        return '<span class="an-trend an-trend-flat"><i class="bi bi-dash"></i> new vs last period</span>';
    }
    if (abs($pct) < 0.05) {
        return '<span class="an-trend an-trend-flat"><i class="bi bi-dash"></i> flat vs last period</span>';
    }
    $up   = $pct > 0;
    $good = $higherIsBetter ? $up : !$up;
    $cls  = $good ? 'an-trend-up' : 'an-trend-down';
    $icon = $up ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    $sign = $up ? '+' : '';
    return '<span class="an-trend ' . $cls . '"><i class="bi ' . $icon . '"></i> ' . $sign . number_format($pct, 1) . '% vs last period</span>';
}

// Same idea, but for metrics that are ALREADY percentages (on-time rate,
// utilization rate). Comparing those with percent-of-a-percent math is
// confusing ("90%→95%" reads as "+5.6%"), so this shows the plain
// percentage-point difference instead ("+5.0 pts").
function trendBadgePts($current, $previous, bool $higherIsBetter = true, bool $hasComparison = true): string {
    if (!$hasComparison || $previous === null) return '';
    $diff = round((float)$current - (float)$previous, 1);
    if (abs($diff) < 0.05) {
        return '<span class="an-trend an-trend-flat"><i class="bi bi-dash"></i> flat vs last period</span>';
    }
    $up   = $diff > 0;
    $good = $higherIsBetter ? $up : !$up;
    $cls  = $good ? 'an-trend-up' : 'an-trend-down';
    $icon = $up ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    $sign = $up ? '+' : '';
    return '<span class="an-trend ' . $cls . '"><i class="bi ' . $icon . '"></i> ' . $sign . number_format($diff, 1) . ' pts vs last period</span>';
}

// ── KPI: Revenue billed vs collected ──────────────────────────────────────────
$revenue = (float)qRange($pdo, "
    SELECT COALESCE(SUM(b.amount), 0) FROM billings b WHERE 1=1 $billDateFilter
", $rangeStartSql)->fetchColumn();

$collected = (float)qRange($pdo, "
    SELECT COALESCE(SUM(c.amount_paid), 0) FROM collections c WHERE 1=1 $collDateFilter
", $rangeStartSql)->fetchColumn();

$collectionRate = $revenue > 0 ? round(($collected / $revenue) * 100, 1) : 0;

// ── KPI: Maintenance cost ─────────────────────────────────────────────────────
$maintCost = (float)qRange($pdo, "
    SELECT COALESCE(SUM(cost), 0) FROM maintenance_records WHERE 1=1 $maintDateFilter
", $rangeStartSql)->fetchColumn();

// ── KPI: Completed trips + on-time rate ───────────────────────────────────────
$tripStats = qRange($pdo, "
    SELECT
        COUNT(*)                                            AS completed,
        SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END)         AS late_count
    FROM trips t
    WHERE t.status = 'Completed' $tripDateFilter
", $rangeStartSql)->fetch(PDO::FETCH_ASSOC);
$completedTrips = (int)($tripStats['completed'] ?? 0);
$lateCount      = (int)($tripStats['late_count'] ?? 0);
$onTimeRate     = $completedTrips > 0 ? round((($completedTrips - $lateCount) / $completedTrips) * 100, 1) : 0;

// ── KPI: Fleet utilization (distinct trucks dispatched / total trucks) ───────
$totalTrucks = (int)$pdo->query("SELECT COUNT(*) FROM trucks")->fetchColumn();
$avgCostPerTruck = $totalTrucks > 0 ? $maintCost / $totalTrucks : 0;
$utilizedTrucks = (int)qRange($pdo, "
    SELECT COUNT(DISTINCT dr.truck_id)
    FROM dispatch_requests dr
    JOIN trips t ON t.dispatch_id = dr.dispatch_id
    WHERE 1=1 $tripDateFilter
", $rangeStartSql)->fetchColumn();
$utilizationRate = $totalTrucks > 0 ? round(($utilizedTrucks / $totalTrucks) * 100, 1) : 0;

// ── KPI: Avg revenue per completed trip ───────────────────────────────────────
$avgRevenuePerTrip = $completedTrips > 0 ? $revenue / $completedTrips : 0;

// ── Previous-period equivalents of the above, for the trend badges ───────────
$revenuePrev = $hasComparison ? (float)qPrev($pdo, "
    SELECT COALESCE(SUM(b.amount), 0) FROM billings b
    WHERE b.created_at >= :prevStart AND b.created_at < :prevEnd
", $prevRangeStartSql, $prevRangeEndSql)->fetchColumn() : null;

$collectedPrev = $hasComparison ? (float)qPrev($pdo, "
    SELECT COALESCE(SUM(c.amount_paid), 0) FROM collections c
    WHERE c.payment_date >= :prevStart AND c.payment_date < :prevEnd
", $prevRangeStartSql, $prevRangeEndSql)->fetchColumn() : null;

$maintCostPrev = $hasComparison ? (float)qPrev($pdo, "
    SELECT COALESCE(SUM(cost), 0) FROM maintenance_records
    WHERE date_performed >= :prevStart AND date_performed < :prevEnd
", $prevRangeStartSql, $prevRangeEndSql)->fetchColumn() : null;

$tripStatsPrev = $hasComparison ? qPrev($pdo, "
    SELECT
        COUNT(*)                                      AS completed,
        SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END)   AS late_count
    FROM trips t
    WHERE t.status = 'Completed' AND t.created_at >= :prevStart AND t.created_at < :prevEnd
", $prevRangeStartSql, $prevRangeEndSql)->fetch(PDO::FETCH_ASSOC) : null;
$completedTripsPrev = $hasComparison ? (int)($tripStatsPrev['completed'] ?? 0) : null;
$lateCountPrev      = $hasComparison ? (int)($tripStatsPrev['late_count'] ?? 0) : null;
$onTimeRatePrev     = ($hasComparison && $completedTripsPrev > 0)
    ? round((($completedTripsPrev - $lateCountPrev) / $completedTripsPrev) * 100, 1)
    : null;

$utilizedTrucksPrev = $hasComparison ? (int)qPrev($pdo, "
    SELECT COUNT(DISTINCT dr.truck_id)
    FROM dispatch_requests dr
    JOIN trips t ON t.dispatch_id = dr.dispatch_id
    WHERE t.created_at >= :prevStart AND t.created_at < :prevEnd
", $prevRangeStartSql, $prevRangeEndSql)->fetchColumn() : null;
// Uses today's total fleet size as the denominator for both periods — the
// system doesn't track historical fleet size at each past date, and at
// capstone scale the truck count doesn't meaningfully shift month to month.
$utilizationRatePrev = ($hasComparison && $totalTrucks > 0)
    ? round(($utilizedTrucksPrev / $totalTrucks) * 100, 1)
    : null;

$avgRevenuePerTripPrev = ($hasComparison && $completedTripsPrev > 0)
    ? $revenuePrev / $completedTripsPrev
    : null;

$avgCostPerTruckPrev = ($hasComparison && $totalTrucks > 0)
    ? $maintCostPrev / $totalTrucks
    : null;

// ── Revenue vs Maintenance Cost trend (bucketed by selected granularity) ─────
$revBucketExpr  = bucketExpr('created_at', $granularity);
$costBucketExpr = bucketExpr('date_performed', $granularity);

$revByBucket = qRange($pdo, "
    SELECT $revBucketExpr AS bucket, SUM(amount) AS total
    FROM billings
    WHERE created_at >= :rangeStart
    GROUP BY bucket
", $rangeStartSql)->fetchAll(PDO::FETCH_KEY_PAIR);

$costByBucket = qRange($pdo, "
    SELECT $costBucketExpr AS bucket, SUM(cost) AS total
    FROM maintenance_records
    WHERE date_performed >= :rangeStart
    GROUP BY bucket
", $rangeStartSql)->fetchAll(PDO::FETCH_KEY_PAIR);

$trendLabels = $buckets['labels'];
$revTrend    = array_map(fn($k) => round((float)($revByBucket[$k]  ?? 0), 2), $buckets['keys']);
$costTrend   = array_map(fn($k) => round((float)($costByBucket[$k] ?? 0), 2), $buckets['keys']);

// ── Trip status breakdown (period-aware) ──────────────────────────────────────
$statusRows = qRange($pdo, "
    SELECT status, COUNT(*) AS cnt FROM trips t WHERE 1=1 $tripDateFilter GROUP BY status
", $rangeStartSql)->fetchAll(PDO::FETCH_KEY_PAIR);
$statusLabels = ['Loading', 'In Transit', 'Unloading', 'Completed', 'Cancelled'];
$statusData   = array_map(fn($s) => (int)($statusRows[$s] ?? 0), $statusLabels);
$statusColors = ['#6c757d', '#0d6efd', '#0dcaf0', '#198754', '#dc3545'];

// ── Maintenance cost by type (period-aware) ───────────────────────────────────
$maintTypeRows = qRange($pdo, "
    SELECT maintenance_type, COALESCE(SUM(cost), 0) AS total_cost
    FROM maintenance_records
    WHERE 1=1 $maintDateFilter
    GROUP BY maintenance_type
", $rangeStartSql)->fetchAll(PDO::FETCH_KEY_PAIR);
$maintTypeLabels = ['Preventive', 'Corrective', 'Inspection'];
$maintTypeData   = array_map(fn($t) => round((float)($maintTypeRows[$t] ?? 0), 2), $maintTypeLabels);
$maintTypeColors = ['#198754', '#dc3545', '#0d6efd'];

// ── Top 5 trucks by revenue ────────────────────────────────────────────────────
$topTrucks = qRange($pdo, "
    SELECT tr.plate_number, tr.brand, tr.model,
           COUNT(DISTINCT t.trip_id) AS trip_count,
           COALESCE(SUM(b.amount), 0) AS revenue
    FROM trucks tr
    JOIN dispatch_requests dr ON dr.truck_id = tr.truck_id
    JOIN trips t ON t.dispatch_id = dr.dispatch_id AND t.status = 'Completed'
    LEFT JOIN billings b ON b.trip_id = t.trip_id
    WHERE 1=1 $tripDateFilter
    GROUP BY tr.truck_id
    ORDER BY revenue DESC
    LIMIT 5
", $rangeStartSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Top 5 drivers by completed trips ──────────────────────────────────────────
$topDrivers = qRange($pdo, "
    SELECT e.full_name,
           COUNT(*) AS trip_count,
           SUM(CASE WHEN t.is_late = 1 THEN 1 ELSE 0 END) AS late_count
    FROM dispatch_requests dr
    JOIN trips t ON t.dispatch_id = dr.dispatch_id AND t.status = 'Completed'
    JOIN employees e ON e.employee_id = dr.driver_id
    WHERE 1=1 $tripDateFilter
    GROUP BY e.employee_id
    ORDER BY trip_count DESC
    LIMIT 5
", $rangeStartSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Incident trend (bucketed by selected granularity) ─────────────────────────
$incBucketExpr = bucketExpr('reported_at', $granularity);
$incByBucket = qRange($pdo, "
    SELECT $incBucketExpr AS bucket, COUNT(*) AS cnt
    FROM incidents
    WHERE reported_at >= :rangeStart
    GROUP BY bucket
", $rangeStartSql)->fetchAll(PDO::FETCH_KEY_PAIR);
$incTrend = array_map(fn($k) => (int)($incByBucket[$k] ?? 0), $buckets['keys']);

// ══════════════════════════════════════════════════════════════════════════
// ROLE-SPECIFIC DATA — each role gets its own relevant slice, not a
// relabeled copy of the Head Management view.
// ══════════════════════════════════════════════════════════════════════════

// ── Dispatcher: trip volume trend + trucks by trip count ─────────────────────
if ($role === ROLE_DISPATCHER) {
    $tripVolBucketExpr = bucketExpr('t.created_at', $granularity);
    $tripVolByBucket = qRange($pdo, "
        SELECT $tripVolBucketExpr AS bucket, COUNT(*) AS cnt
        FROM trips t
        WHERE t.status = 'Completed' AND t.created_at >= :rangeStart
        GROUP BY bucket
    ", $rangeStartSql)->fetchAll(PDO::FETCH_KEY_PAIR);
    $tripVolumeTrend = array_map(fn($k) => (int)($tripVolByBucket[$k] ?? 0), $buckets['keys']);

    $topTrucksByTrips = qRange($pdo, "
        SELECT tr.plate_number, tr.brand, tr.model,
               COUNT(*) AS trip_count,
               SUM(CASE WHEN t.is_late = 1 THEN 1 ELSE 0 END) AS late_count
        FROM trucks tr
        JOIN dispatch_requests dr ON dr.truck_id = tr.truck_id
        JOIN trips t ON t.dispatch_id = dr.dispatch_id AND t.status = 'Completed'
        WHERE t.created_at >= :rangeStart
        GROUP BY tr.truck_id
        ORDER BY trip_count DESC
        LIMIT 5
    ", $rangeStartSql)->fetchAll(PDO::FETCH_ASSOC);
}

// ── Maintenance: cost-only trend, open incidents, records logged, top trucks ─
if ($role === ROLE_MAINTENANCE) {
    $openIncidentsCount = (int)$pdo->query("
        SELECT COUNT(*) FROM incidents WHERE resolved_at IS NULL
    ")->fetchColumn();

    $maintRecordsLoggedCount = (int)qRange($pdo, "
        SELECT COUNT(*) FROM maintenance_records WHERE date_performed >= :rangeStart
    ", $rangeStartSql)->fetchColumn();

    $maintRecordsLoggedCountPrev = $hasComparison ? (int)qPrev($pdo, "
        SELECT COUNT(*) FROM maintenance_records
        WHERE date_performed >= :prevStart AND date_performed < :prevEnd
    ", $prevRangeStartSql, $prevRangeEndSql)->fetchColumn() : null;

    $topTrucksByMaintCost = qRange($pdo, "
        SELECT tr.plate_number, tr.brand, tr.model,
               COUNT(*) AS record_count,
               COALESCE(SUM(mr.cost), 0) AS total_cost
        FROM trucks tr
        JOIN maintenance_records mr ON mr.truck_id = tr.truck_id
        WHERE mr.date_performed >= :rangeStart
        GROUP BY tr.truck_id
        ORDER BY total_cost DESC
        LIMIT 5
    ", $rangeStartSql)->fetchAll(PDO::FETCH_ASSOC);
}

// ── Accounting: billed vs collected trend, top clients ────────────────────────
if ($role === ROLE_ACCOUNTING) {
    $collBucketExpr = bucketExpr('c.payment_date', $granularity);
    $collByBucket = qRange($pdo, "
        SELECT $collBucketExpr AS bucket, SUM(c.amount_paid) AS total
        FROM collections c
        WHERE c.payment_date >= :rangeStart
        GROUP BY bucket
    ", $rangeStartSql)->fetchAll(PDO::FETCH_KEY_PAIR);
    $collTrend = array_map(fn($k) => round((float)($collByBucket[$k] ?? 0), 2), $buckets['keys']);

    $topClientsByRevenue = qRange($pdo, "
        SELECT COALESCE(NULLIF(b.client_name, ''), CONCAT('Trip ', t.trip_number)) AS client_label,
               COUNT(*) AS invoice_count,
               COALESCE(SUM(b.amount), 0) AS revenue
        FROM billings b
        JOIN trips t ON b.trip_id = t.trip_id
        WHERE b.created_at >= :rangeStart
        GROUP BY client_label
        ORDER BY revenue DESC
        LIMIT 5
    ", $rangeStartSql)->fetchAll(PDO::FETCH_ASSOC);
}

// Pass chart data to JS
$GLOBALS['analytics_data'] = json_encode([
    'revCostTrend'     => ['labels' => $trendLabels, 'revenue' => $revTrend, 'cost' => $costTrend],
    'tripStatus'       => ['labels' => $statusLabels, 'data' => $statusData, 'colors' => $statusColors],
    'maintType'        => ['labels' => $maintTypeLabels, 'data' => $maintTypeData, 'colors' => $maintTypeColors],
    'incTrend'         => ['labels' => $trendLabels, 'data' => $incTrend],
    'tripVolumeTrend'  => $role === ROLE_DISPATCHER  ? ['labels' => $trendLabels, 'data' => $tripVolumeTrend] : null,
    'maintCostTrend'   => $role === ROLE_MAINTENANCE ? ['labels' => $trendLabels, 'data' => $costTrend]       : null,
    'billedCollected'  => $role === ROLE_ACCOUNTING  ? ['labels' => $trendLabels, 'revenue' => $revTrend, 'collected' => $collTrend] : null,
]);
?>
<div class="an-page">

  <!-- Header -->
  <div class="an-header">
    <div>
      <h1 class="an-title">Analytics</h1>
      <p class="an-subtitle">
        <?php if ($isHead): ?>
        Cross-functional performance across Operations, Maintenance, and Accounting
        <?php elseif ($role === ROLE_DISPATCHER): ?>
        Your operations performance — trips, fleet utilization, and on-time delivery
        <?php elseif ($role === ROLE_MAINTENANCE): ?>
        Your maintenance performance — costs, incidents, and fleet health
        <?php elseif ($role === ROLE_ACCOUNTING): ?>
        Your billing performance — revenue, collections, and client activity
        <?php endif; ?>
      </p>
    </div>
    <form method="get" class="an-period-form">
      <select name="period" class="form-select an-period-select" onchange="this.form.submit()">
        <?php foreach ($periods as $key => $p): ?>
        <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($p['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="granularity" class="form-select an-period-select" onchange="this.form.submit()">
        <?php foreach ($granularities as $key => $label):
          $isAllowed = $granularityAllowed[$key];
          $reason = $key === 'day'
            ? 'Daily view needs a range of ~3 months or less'
            : ($key === 'month' ? 'Monthly view needs a range of at least ~1 month' : '');
        ?>
        <option value="<?= $key ?>"
                <?= $key === $granularity ? 'selected' : '' ?>
                <?= $isAllowed ? '' : 'disabled' ?>
                <?= $isAllowed ? '' : 'title="' . htmlspecialchars($reason) . '"' ?>>
          <?= htmlspecialchars($label) ?><?= $isAllowed ? '' : ' (unavailable for this range)' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php if ($granularityWasAdjusted): ?>
  <div class="an-adjust-note">
    <i class="bi bi-info-circle"></i>
    "<?= htmlspecialchars($granularities[$requestedGranularity]) ?>" isn't practical for "<?= htmlspecialchars($periodLabel) ?>", so this is showing <?= htmlspecialchars($granularityLabel) ?> instead.
  </div>
  <?php endif; ?>

  <!-- KPI cards -->
  <div class="an-kpis">
    <?php if ($isHead): ?>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-cash-coin"></i> Revenue Billed</div>
      <div class="an-kpi-value">₱<?= number_format($revenue, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($revenue, $revenuePrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-wallet2"></i> Collected</div>
      <div class="an-kpi-value an-value-green">₱<?= number_format($collected, 2) ?></div>
      <div class="an-kpi-sub"><?= $collectionRate ?>% collection rate</div>
      <?= trendBadge($collected, $collectedPrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-tools"></i> Maintenance Cost</div>
      <div class="an-kpi-value an-value-orange">₱<?= number_format($maintCost, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($maintCost, $maintCostPrev, false, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-clock-history"></i> On-Time Delivery</div>
      <div class="an-kpi-value <?= $onTimeRate >= 85 ? 'an-value-green' : ($onTimeRate >= 60 ? 'an-value-orange' : 'an-value-red') ?>">
        <?= $onTimeRate ?>%
      </div>
      <div class="an-kpi-sub"><?= $completedTrips ?> completed trip<?= $completedTrips !== 1 ? 's' : '' ?></div>
      <?= trendBadgePts($onTimeRate, $onTimeRatePrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-speedometer2"></i> Fleet Utilization</div>
      <div class="an-kpi-value an-value-blue"><?= $utilizationRate ?>%</div>
      <div class="an-kpi-sub"><?= $utilizedTrucks ?> of <?= $totalTrucks ?> trucks active</div>
      <?= trendBadgePts($utilizationRate, $utilizationRatePrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-graph-up-arrow"></i> Avg Revenue / Trip</div>
      <div class="an-kpi-value">₱<?= number_format($avgRevenuePerTrip, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($avgRevenuePerTrip, $avgRevenuePerTripPrev, true, $hasComparison) ?>
    </div>

    <?php elseif ($role === ROLE_DISPATCHER): ?>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-clock-history"></i> On-Time Delivery</div>
      <div class="an-kpi-value <?= $onTimeRate >= 85 ? 'an-value-green' : ($onTimeRate >= 60 ? 'an-value-orange' : 'an-value-red') ?>">
        <?= $onTimeRate ?>%
      </div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadgePts($onTimeRate, $onTimeRatePrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-speedometer2"></i> Fleet Utilization</div>
      <div class="an-kpi-value an-value-blue"><?= $utilizationRate ?>%</div>
      <div class="an-kpi-sub"><?= $utilizedTrucks ?> of <?= $totalTrucks ?> trucks active</div>
      <?= trendBadgePts($utilizationRate, $utilizationRatePrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-check-circle"></i> Completed Trips</div>
      <div class="an-kpi-value an-value-green"><?= $completedTrips ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($completedTrips, $completedTripsPrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-exclamation-triangle"></i> Late Trips</div>
      <div class="an-kpi-value <?= $lateCount === 0 ? 'an-value-green' : 'an-value-red' ?>"><?= $lateCount ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($lateCount, $lateCountPrev, false, $hasComparison) ?>
    </div>

    <?php elseif ($role === ROLE_MAINTENANCE): ?>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-tools"></i> Maintenance Cost</div>
      <div class="an-kpi-value an-value-orange">₱<?= number_format($maintCost, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($maintCost, $maintCostPrev, false, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-clipboard-check"></i> Records Logged</div>
      <div class="an-kpi-value"><?= $maintRecordsLoggedCount ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($maintRecordsLoggedCount, $maintRecordsLoggedCountPrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-exclamation-octagon"></i> Open Incidents</div>
      <div class="an-kpi-value <?= $openIncidentsCount === 0 ? 'an-value-green' : 'an-value-red' ?>"><?= $openIncidentsCount ?></div>
      <div class="an-kpi-sub">Currently unresolved</div>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-truck"></i> Avg Cost / Truck</div>
      <div class="an-kpi-value">₱<?= number_format($avgCostPerTruck, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($avgCostPerTruck, $avgCostPerTruckPrev, false, $hasComparison) ?>
    </div>

    <?php elseif ($role === ROLE_ACCOUNTING): ?>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-cash-coin"></i> Revenue Billed</div>
      <div class="an-kpi-value">₱<?= number_format($revenue, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($revenue, $revenuePrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-wallet2"></i> Collected</div>
      <div class="an-kpi-value an-value-green">₱<?= number_format($collected, 2) ?></div>
      <div class="an-kpi-sub"><?= $collectionRate ?>% collection rate</div>
      <?= trendBadge($collected, $collectedPrev, true, $hasComparison) ?>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-graph-up-arrow"></i> Avg Revenue / Trip</div>
      <div class="an-kpi-value">₱<?= number_format($avgRevenuePerTrip, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
      <?= trendBadge($avgRevenuePerTrip, $avgRevenuePerTripPrev, true, $hasComparison) ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Alerts (rule-based) -->
  <?php
  // Build metrics bag for alerts helper
  $metricsForAlerts = [
      'utilizationRate' => $utilizationRate ?? null,
      'collectionRate'  => $collectionRate ?? null,
      'onTimeRate'      => $onTimeRate ?? null,
      'maintCost'       => $maintCost ?? null,
      'revTrend'        => $revTrend ?? [],
      'costTrend'       => $costTrend ?? [],
  ];
  $alerts = getAnalyticsAlerts($pdo, $metricsForAlerts, $periodLabel);
  ?>

  <?php if (!empty($alerts)): ?>
  <?php
    $critCount = count(array_filter($alerts, fn($x) => $x['severity'] === 'Critical'));
    $warnCount = count(array_filter($alerts, fn($x) => $x['severity'] === 'Warning'));
    $infoCount = count(array_filter($alerts, fn($x) => $x['severity'] === 'Info'));
    $topAlert  = $alerts[0] ?? null;
  ?>

  <div class="an-alerts-summary">
    <div class="an-alert-summary-item"><span class="an-alert-count"><?= $critCount ?></span> Critical</div>
    <div class="an-alert-summary-item"><span class="an-alert-count" style="background:rgba(255,193,7,0.12);color:#856404"><?= $warnCount ?></span> Warnings</div>
    <div class="an-alert-summary-item"><span class="an-alert-count" style="background:rgba(13,110,253,0.06);color:#0d6efd"><?= $infoCount ?></span> Info</div>
    <?php if ($topAlert): ?>
    <div class="an-alert-summary-item" style="margin-left:auto;font-weight:700;">Top: <?= htmlspecialchars($topAlert['alert']) ?></div>
    <?php endif; ?>
  </div>

  <div class="an-alert-cards">
    <?php foreach ($alerts as $i => $a):
      $priorityClass = $a['priority'] ?? ($a['severity'] === 'Critical' ? 'high' : 'medium');
      $prioLabel = strtoupper($a['priority'] ?? ($a['severity'] === 'Critical' ? 'HIGH' : 'MED'));
    ?>
    <div class="dh-rec-card an-alert-card dh-rec-<?= htmlspecialchars($priorityClass) ?>">
      <div class="dh-rec-priority dh-rec-priority-<?= htmlspecialchars($priorityClass) ?> an-alert-priority"><?= htmlspecialchars($prioLabel) ?></div>
      <div class="dh-rec-body an-alert-body">
        <div class="an-alert-title"><?= htmlspecialchars($a['alert']) ?> <span style="font-size:0.78rem;color:var(--bs-secondary-color);">&mdash; <?= htmlspecialchars($a['source']) ?></span></div>
        <div class="an-alert-summary-text" style="color:var(--bs-secondary-color);font-size:0.9rem;"><?= htmlspecialchars(substr($a['detail'],0,120)) ?><?= strlen($a['detail'])>120 ? '...' : '' ?></div>
      </div>
      <div class="an-alert-actions">
        <?php if (!empty($a['action_url'])): ?>
          <a class="dh-rec-action" href="<?= APP_BASE . $a['action_url'] ?>">Take action</a>
        <?php endif; ?>
        <button class="an-alert-toggle" data-title="<?= htmlspecialchars($a['alert']) ?>" data-detail="<?= htmlspecialchars($a['detail']) ?>" data-prescription="<?= htmlspecialchars(json_encode($a['prescription'] ?? []), ENT_QUOTES) ?>" data-action="<?= !empty($a['action_url']) ? (APP_BASE . $a['action_url']) : '' ?>">Show details</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>

  <!-- Chart grid -->
  <div class="an-grid">

    <?php if ($isHead): ?>
    <div class="an-widget an-widget-wide">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-bar-chart-line me-2"></i>Revenue vs Maintenance Cost (<?= htmlspecialchars($periodLabel) ?>, <?= htmlspecialchars($granularityLabel) ?>)</span>
      </div>
      <div class="an-chart-wrap an-chart-tall">
        <canvas id="revCostChart"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($role === ROLE_DISPATCHER): ?>
    <div class="an-widget an-widget-wide">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-bar-chart-line me-2"></i>Completed Trips (<?= htmlspecialchars($periodLabel) ?>, <?= htmlspecialchars($granularityLabel) ?>)</span>
      </div>
      <div class="an-chart-wrap an-chart-tall">
        <canvas id="tripVolumeChart"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($role === ROLE_MAINTENANCE): ?>
    <div class="an-widget an-widget-wide">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-bar-chart-line me-2"></i>Maintenance Cost (<?= htmlspecialchars($periodLabel) ?>, <?= htmlspecialchars($granularityLabel) ?>)</span>
      </div>
      <div class="an-chart-wrap an-chart-tall">
        <canvas id="maintCostTrendChart"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($role === ROLE_ACCOUNTING): ?>
    <div class="an-widget an-widget-wide">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-bar-chart-line me-2"></i>Billed vs Collected (<?= htmlspecialchars($periodLabel) ?>, <?= htmlspecialchars($granularityLabel) ?>)</span>
      </div>
      <div class="an-chart-wrap an-chart-tall">
        <canvas id="billedCollectedChart"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isHead || $role === ROLE_DISPATCHER): ?>
    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-pie-chart-fill me-2"></i>Trip Status</span>
      </div>
      <div class="an-donut-wrap">
        <div class="an-donut-canvas-wrap">
          <canvas id="tripStatusChart"></canvas>
        </div>
        <div class="an-donut-legend">
          <?php foreach ($statusLabels as $i => $lbl): ?>
          <div class="an-legend-item">
            <span class="an-legend-dot" style="background:<?= $statusColors[$i] ?>"></span>
            <span class="an-legend-label"><?= $lbl ?></span>
            <span class="an-legend-val"><?= $statusData[$i] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isHead || $role === ROLE_MAINTENANCE): ?>
    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-wrench-adjustable me-2"></i>Maintenance Cost by Type</span>
      </div>
      <div class="an-donut-wrap">
        <div class="an-donut-canvas-wrap">
          <canvas id="maintTypeChart"></canvas>
        </div>
        <div class="an-donut-legend">
          <?php foreach ($maintTypeLabels as $i => $lbl): ?>
          <div class="an-legend-item">
            <span class="an-legend-dot" style="background:<?= $maintTypeColors[$i] ?>"></span>
            <span class="an-legend-label"><?= $lbl ?></span>
            <span class="an-legend-val">₱<?= number_format($maintTypeData[$i], 0) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isHead || $role === ROLE_DISPATCHER || $role === ROLE_MAINTENANCE): ?>
    <div class="an-widget an-widget-wide">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-exclamation-triangle me-2"></i>Incident Trend (<?= htmlspecialchars($periodLabel) ?>, <?= htmlspecialchars($granularityLabel) ?>)</span>
      </div>
      <div class="an-chart-wrap">
        <canvas id="incTrendChart"></canvas>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Leaderboards -->
  <div class="an-tables-grid">

    <?php if ($isHead || $role === ROLE_ACCOUNTING): ?>
    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-trophy me-2"></i>Top 5 Trucks by Revenue</span>
        <button class="an-export-btn" data-export="topTrucksTable" data-filename="top-trucks">
          <i class="bi bi-download"></i> CSV
        </button>
      </div>
      <?php if (empty($topTrucks)): ?>
      <div class="an-empty"><i class="bi bi-truck"></i><span>No completed trips in this period</span></div>
      <?php else: ?>
      <table class="table an-table" id="topTrucksTable">
        <thead><tr><th>Plate No.</th><th>Truck</th><th>Trips</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($topTrucks as $tt): ?>
          <tr>
            <td class="an-mono"><?= htmlspecialchars($tt['plate_number']) ?></td>
            <td><?= htmlspecialchars($tt['brand'] . ' ' . $tt['model']) ?></td>
            <td><?= (int)$tt['trip_count'] ?></td>
            <td class="an-money">₱<?= number_format((float)$tt['revenue'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isHead || $role === ROLE_DISPATCHER): ?>
    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-person-badge me-2"></i>Top 5 Drivers by Trips</span>
        <button class="an-export-btn" data-export="topDriversTable" data-filename="top-drivers">
          <i class="bi bi-download"></i> CSV
        </button>
      </div>
      <?php if (empty($topDrivers)): ?>
      <div class="an-empty"><i class="bi bi-person"></i><span>No completed trips in this period</span></div>
      <?php else: ?>
      <table class="table an-table" id="topDriversTable">
        <thead><tr><th>Driver</th><th>Completed Trips</th><th>Late Trips</th><th>On-Time %</th></tr></thead>
        <tbody>
          <?php foreach ($topDrivers as $td):
            $tCount = (int)$td['trip_count'];
            $lCount = (int)$td['late_count'];
            $otPct  = $tCount > 0 ? round((($tCount - $lCount) / $tCount) * 100, 1) : 0;
          ?>
          <tr>
            <td><?= htmlspecialchars($td['full_name']) ?></td>
            <td><?= $tCount ?></td>
            <td><?= $lCount ?></td>
            <td class="<?= $otPct >= 85 ? 'an-text-green' : ($otPct >= 60 ? 'an-text-orange' : 'an-text-red') ?>">
              <?= $otPct ?>%
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($role === ROLE_DISPATCHER): ?>
    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-truck me-2"></i>Top 5 Trucks by Trip Count</span>
        <button class="an-export-btn" data-export="topTrucksByTripsTable" data-filename="top-trucks-by-trips">
          <i class="bi bi-download"></i> CSV
        </button>
      </div>
      <?php if (empty($topTrucksByTrips)): ?>
      <div class="an-empty"><i class="bi bi-truck"></i><span>No completed trips in this period</span></div>
      <?php else: ?>
      <table class="table an-table" id="topTrucksByTripsTable">
        <thead><tr><th>Plate No.</th><th>Truck</th><th>Trips</th><th>Late</th></tr></thead>
        <tbody>
          <?php foreach ($topTrucksByTrips as $tt): ?>
          <tr>
            <td class="an-mono"><?= htmlspecialchars($tt['plate_number']) ?></td>
            <td><?= htmlspecialchars($tt['brand'] . ' ' . $tt['model']) ?></td>
            <td><?= (int)$tt['trip_count'] ?></td>
            <td><?= (int)$tt['late_count'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($role === ROLE_MAINTENANCE): ?>
    <div class="an-widget an-widget-wide">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-truck me-2"></i>Top 5 Trucks by Maintenance Cost</span>
        <button class="an-export-btn" data-export="topTrucksByMaintCostTable" data-filename="top-trucks-by-maintenance-cost">
          <i class="bi bi-download"></i> CSV
        </button>
      </div>
      <?php if (empty($topTrucksByMaintCost)): ?>
      <div class="an-empty"><i class="bi bi-tools"></i><span>No maintenance records in this period</span></div>
      <?php else: ?>
      <table class="table an-table" id="topTrucksByMaintCostTable">
        <thead><tr><th>Plate No.</th><th>Truck</th><th>Records</th><th>Total Cost</th></tr></thead>
        <tbody>
          <?php foreach ($topTrucksByMaintCost as $tm): ?>
          <tr>
            <td class="an-mono"><?= htmlspecialchars($tm['plate_number']) ?></td>
            <td><?= htmlspecialchars($tm['brand'] . ' ' . $tm['model']) ?></td>
            <td><?= (int)$tm['record_count'] ?></td>
            <td class="an-money">₱<?= number_format((float)$tm['total_cost'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($role === ROLE_ACCOUNTING): ?>
    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-people me-2"></i>Top 5 Clients by Revenue</span>
        <button class="an-export-btn" data-export="topClientsTable" data-filename="top-clients">
          <i class="bi bi-download"></i> CSV
        </button>
      </div>
      <?php if (empty($topClientsByRevenue)): ?>
      <div class="an-empty"><i class="bi bi-receipt"></i><span>No billings in this period</span></div>
      <?php else: ?>
      <table class="table an-table" id="topClientsTable">
        <thead><tr><th>Client</th><th>Invoices</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($topClientsByRevenue as $cl): ?>
          <tr>
            <td><?= htmlspecialchars($cl['client_label']) ?></td>
            <td><?= (int)$cl['invoice_count'] ?></td>
            <td class="an-money">₱<?= number_format((float)$cl['revenue'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
window.ANALYTICS_DATA = <?= $GLOBALS['analytics_data'] ?>;
</script>
<?php layoutFoot(); ?>