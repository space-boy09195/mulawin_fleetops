<?php
// ============================================================
// includes/analytics_period.php
// Pure helpers of the Analytics page (no database access except qRange /
// qPrev, which only bind the window): trend buckets, period-over-period
// change, trend badges and rate display. Kept here so they can be unit
// tested (tests/unit/run.php).
// ============================================================

// ── Bucket-expression helper for the selected granularity ────────────────────
// $col is a fixed column / expression from the calling page, never user input.
function bucketExpr(string $col, string $granularity): string {
    return match ($granularity) {
        'day'   => "DATE_FORMAT($col, '%Y-%m-%d')",
        'week'  => "DATE_FORMAT(DATE_SUB($col, INTERVAL WEEKDAY($col) DAY), '%Y-%m-%d')",
        default => "DATE_FORMAT($col, '%Y-%m')", // month
    };
}

// Full list of bucket keys + labels between two dates, so empty buckets show
// as 0 instead of a gap.
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

function qRange(PDO $pdo, string $sql, string $rangeStartSql, string $rangeEndSql): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':rangeStart' => $rangeStartSql, ':rangeEnd' => $rangeEndSql]);
    return $stmt;
}

function qPrev(PDO $pdo, string $sql, ?string $prevStart, ?string $prevEnd): ?PDOStatement {
    if ($prevStart === null) return null;
    return qRange($pdo, $sql, $prevStart, (string)$prevEnd);
}

// Percent change; null when there is no previous baseline.
function pctChange(float $current, float $previous): ?float {
    if ($previous == 0.0) {
        return $current == 0.0 ? 0.0 : null;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

function trendBadge($current, $previous, bool $higherIsBetter = true, bool $hasComparison = true): string {
    if (!$hasComparison || $previous === null || $current === null) return '';
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

// For metrics that are already percentages: percentage-point difference.
function trendBadgePts($current, $previous, bool $higherIsBetter = true, bool $hasComparison = true): string {
    if (!$hasComparison || $previous === null || $current === null) return '';
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

// Display helper for rates that may be unavailable (null = nothing to measure).
function rateText(?float $rate): string {
    return $rate === null ? '—' : rtrim(rtrim(number_format($rate, 1), '0'), '.') . '%';
}
function rateClass(?float $rate): string {
    if ($rate === null) return '';
    return $rate >= 85 ? 'an-value-green' : ($rate >= 60 ? 'an-value-orange' : 'an-value-red');
}
