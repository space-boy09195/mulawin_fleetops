<?php
// ============================================================
// includes/client_rates.php
// Client rate cards on the target client_rates table:
//   client_id, route_id (NULL = any route), truck_type, rate_type
//   ('Route' | 'Distance' | 'Fixed'), rate_amount, effective_date,
//   end_date (NULL = open-ended), is_active.
//
// Interpretation used for a suggested trip charge (shown as a suggestion;
// the billing user confirms the amount):
//   Route     rate_amount per trip on that route
//   Distance  rate_amount x routes.distance (needs a recorded distance)
//   Fixed     rate_amount per trip, whatever the distance
// A rate for the trip's own route wins over a route-less (any route) rate;
// the latest effective_date wins within each.
// ============================================================

const CLIENT_RATE_TYPES = ['Route', 'Distance', 'Fixed'];

/**
 * Active rate in force for a client, route and truck type on a date, or null.
 */
function applicableClientRate(PDO $pdo, int $clientId, ?int $routeId, string $truckType, string $date): ?array {
    $stmt = $pdo->prepare(
        'SELECT rate_id, route_id, truck_type, rate_type, rate_amount, effective_date, end_date
         FROM client_rates
         WHERE client_id = ? AND truck_type = ? AND is_active = 1
           AND effective_date <= ? AND (end_date IS NULL OR end_date >= ?)
           AND (route_id = ? OR route_id IS NULL)
         ORDER BY (route_id IS NULL), effective_date DESC, rate_id DESC
         LIMIT 1'
    );
    $stmt->execute([$clientId, $truckType, $date, $date, $routeId ?? 0]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Suggested charge for one trip from a rate, or null when it cannot be
 * computed (Distance rate without a route distance).
 */
function clientRateTripAmount(array $rate, ?float $routeDistance): ?float {
    return match ($rate['rate_type']) {
        'Route', 'Fixed' => round((float)$rate['rate_amount'], 2),
        'Distance'       => $routeDistance !== null && $routeDistance > 0
            ? round((float)$rate['rate_amount'] * $routeDistance, 2)
            : null,
        default          => null,
    };
}

/**
 * Another active rate of the same client / route / truck type whose dates
 * overlap [$from, $to] (NULL = open-ended), excluding $excludeRateId.
 */
function overlappingClientRate(PDO $pdo, int $clientId, ?int $routeId, string $truckType, string $from, ?string $to, ?int $excludeRateId): ?int {
    $stmt = $pdo->prepare(
        "SELECT rate_id FROM client_rates
         WHERE client_id = ? AND truck_type = ? AND is_active = 1
           AND (route_id <=> ?)
           AND effective_date <= COALESCE(?, '9999-12-31')
           AND COALESCE(end_date, '9999-12-31') >= ?
           AND rate_id <> COALESCE(?, 0)
         LIMIT 1"
    );
    $stmt->execute([$clientId, $truckType, $routeId, $to, $from, $excludeRateId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}
