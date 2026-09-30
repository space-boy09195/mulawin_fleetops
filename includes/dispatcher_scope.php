<?php

require_once __DIR__ . '/session.php';

function dispatcherScope(): array
{
    $roleName = (string)($_SESSION['role_name'] ?? '');
    $scopes = [
        'Car Carrier Dispatcher - Day' => [
            'shift' => 'Day',
            'truck_types' => ['Car Carrier'],
        ],
        'Car Carrier Dispatcher - Night' => [
            'shift' => 'Night',
            'truck_types' => ['Car Carrier'],
        ],
        'Container & Wing Van Dispatcher - Day' => [
            'shift' => 'Day',
            'truck_types' => ['Container', 'Wing Van'],
        ],
        'Container & Wing Van Dispatcher - Night' => [
            'shift' => 'Night',
            'truck_types' => ['Container', 'Wing Van'],
        ],
    ];

    return $scopes[$roleName] ?? [];
}

function enforceDispatcherScope(string $shift, string $truckType): void
{
    $scope = dispatcherScope();
    if (!$scope) {
        return;
    }

    if ($shift !== $scope['shift']) {
        jsonFail('This dispatcher account is assigned to the ' . $scope['shift'] . ' shift.', 403);
    }
    if (!in_array($truckType, $scope['truck_types'], true)) {
        jsonFail('This dispatcher account cannot encode the selected truck category.', 403);
    }
}
