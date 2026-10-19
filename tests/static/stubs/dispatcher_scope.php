<?php
// Capture-only stubs: a scoped dispatcher (Day shift, Car Carrier).
function dispatcherScope(): array { return ['shift' => 'Day', 'truck_types' => ['Car Carrier']]; }
function dispatcherScopeResult(?string $scheduledDate = null): array {
    return in_array($GLOBALS['__role'], ['car_carrier_dispatcher', 'container_wing_van_dispatcher'], true)
        ? ['status' => 'scoped', 'shift' => 'Day', 'truck_types' => ['Car Carrier']]
        : ['status' => 'none'];
}
function refuseHelpers(): void {}
