<?php
// ============================================================
// includes/metrics.php
// Shared metric definitions on the target schema. Dashboards, analytics,
// exports and automation summaries use these fragments so the same metric
// means the same thing everywhere.
//
// Definitions (target columns):
//   arrival moment   COALESCE(trips.arrival_time, trips.completion_time)
//   delivered trip   status IN ('Delivered','Returned','Completed')
//   late delivery    delivered, scheduled_arrival set, arrival moment set,
//                    arrival moment > scheduled_arrival
//   on-time delivery delivered, scheduled_arrival set, arrival moment set,
//                    arrival moment <= scheduled_arrival
//   trips without scheduled_arrival or an arrival moment are "not
//   measurable" and are excluded from on-time rates (never counted as
//   on time).
// Fragments take a fixed table alias from the calling query; the alias is
// validated and is never user input.
// ============================================================

const TRIP_DELIVERED_STATUSES = ['Delivered', 'Returned', 'Completed'];
const TRIP_ACTIVE_STATUSES    = ['Assigned', 'Ready for Dispatch', 'Dispatched', 'In Progress'];
const TRIP_OPEN_STATUSES      = ['Pending Approval', 'Approved', 'Assigned', 'Ready for Dispatch', 'Dispatched', 'In Progress', 'Delivered', 'For Review'];

function metricAlias(string $alias): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid table alias.');
    }
    return $alias;
}

function metricSqlList(array $values): string {
    return implode(',', array_map(static fn(string $v): string => "'" . str_replace("'", "''", $v) . "'", $values));
}

function tripArrivalSql(string $alias = 't'): string {
    $a = metricAlias($alias);
    return "COALESCE({$a}.arrival_time, {$a}.completion_time)";
}

function tripDeliveredSql(string $alias = 't'): string {
    $a = metricAlias($alias);
    return "{$a}.status IN (" . metricSqlList(TRIP_DELIVERED_STATUSES) . ')';
}

function tripMeasurableSql(string $alias = 't'): string {
    $a = metricAlias($alias);
    return '(' . tripDeliveredSql($a) . " AND {$a}.scheduled_arrival IS NOT NULL AND " . tripArrivalSql($a) . ' IS NOT NULL)';
}

function tripLateSql(string $alias = 't'): string {
    $a = metricAlias($alias);
    return '(' . tripMeasurableSql($a) . ' AND ' . tripArrivalSql($a) . " > {$a}.scheduled_arrival)";
}

function tripOnTimeSql(string $alias = 't'): string {
    $a = metricAlias($alias);
    return '(' . tripMeasurableSql($a) . ' AND ' . tripArrivalSql($a) . " <= {$a}.scheduled_arrival)";
}

/**
 * Active trip that is past its scheduled arrival and not yet delivered.
 */
function tripOverdueSql(string $alias = 't'): string {
    $a = metricAlias($alias);
    return "({$a}.status IN (" . metricSqlList(TRIP_ACTIVE_STATUSES) . ")"
        . " AND {$a}.scheduled_arrival IS NOT NULL AND {$a}.scheduled_arrival < NOW())";
}

/**
 * Percentage with a safe zero denominator: null when nothing to measure.
 */
function metricRate(float|int $numerator, float|int $denominator, int $precision = 1): ?float {
    if ($denominator <= 0) {
        return null;
    }
    return round(($numerator / $denominator) * 100, $precision);
}

/**
 * Document expiry, the client's rule "Expiry date − current date", in whole
 * calendar days: positive = days left, 0 = expires today, negative = days
 * past expiry. Null when no (valid) expiry date is recorded.
 */
function daysUntilExpiry(?string $expiryDate, ?DateTimeImmutable $today = null): ?int {
    if ($expiryDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}/', $expiryDate)) {
        return null;
    }
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', substr($expiryDate, 0, 10));
    if ($expiry === false) {
        return null;
    }
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    return (int)$today->diff($expiry)->format('%r%a');
}

/**
 * Short display text for daysUntilExpiry().
 */
function expiryDaysText(?int $days): string {
    if ($days === null) return '';
    if ($days < 0)  return 'expired ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago';
    if ($days === 0) return 'expires today';
    return $days . ' day' . ($days === 1 ? '' : 's') . ' left';
}
