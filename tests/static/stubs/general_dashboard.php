<?php
// Capture-only stubs for pages/dashboard.php.
function dispatcherScopeResult(?string $d = null): array
{
    return in_array($GLOBALS['__role'], ['car_carrier_dispatcher', 'container_wing_van_dispatcher'], true)
        ? ['status' => 'scoped', 'shift' => 'Day', 'truck_types' => ['Car Carrier']]
        : ['status' => 'none'];
}
function dispatcherScope(): array { return ['shift' => 'Day', 'truck_types' => ['Car Carrier']]; }
function employeeForCurrentUser(PDO $pdo): ?array { return ['employee_id' => 1, 'position' => 'Driver', 'employment_type' => 'Employee']; }
