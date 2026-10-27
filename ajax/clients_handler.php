<?php
// ============================================================
// ajax/clients_handler.php
// Client registry on the target clients table (client_name,
// contact_person, contact_number, email, address, billing_address, status).
// The legacy client type / parent client / notes have no target column.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';
require_once __DIR__ . '/../app/modules/trips/route_options.php';

header('Content-Type: application/json');
// Client records: clients.manage (Admin passes every permission check).
requirePermission('clients.manage');
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $clientId = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT) ?: null;
    $name = requiredString('client_name', 'Client name', 180);
    $contact = optionalString('contact_person', null, 150);
    $phone = optionalString('phone', null, 50);
    $email = optionalString('email', null, 150);
    $address = optionalString('address', null, 1000);
    $billingAddress = optionalString('billing_address', null, 5000);
    $routeIds = isset($_POST['route_ids']) ? postedIdList('route_ids') : null;

    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Enter a valid client email address.');
    }
    $duplicate = $pdo->prepare('SELECT client_id FROM clients WHERE LOWER(client_name) = LOWER(?) AND client_id <> COALESCE(?, 0)');
    $duplicate->execute([$name, $clientId]);
    if ($duplicate->fetchColumn()) {
        jsonFail('A client with that name already exists.');
    }

    try {
        $pdo->beginTransaction();
        if ($clientId) {
            $old = $pdo->prepare('SELECT client_name, contact_person, contact_number, email, address, billing_address FROM clients WHERE client_id = ? FOR UPDATE');
            $old->execute([$clientId]);
            $oldData = $old->fetch(PDO::FETCH_ASSOC);
            if (!$oldData) {
                $pdo->rollBack();
                jsonFail('Client not found.', 404);
            }
            $pdo->prepare(
                'UPDATE clients SET client_name = ?, contact_person = ?, contact_number = ?, email = ?,
                        address = ?, billing_address = ?
                 WHERE client_id = ?'
            )->execute([$name, $contact, $phone, $email, $address, $billingAddress, $clientId]);
            if ($routeIds !== null) syncClientRoutes($pdo, $clientId, $routeIds);
            auditLog('UPDATE_CLIENT', 'clients', $clientId, $oldData, ['client_name' => $name]);
            $pdo->commit();
            jsonOk([], 'Client updated successfully.');
        }

        $pdo->prepare(
            "INSERT INTO clients (client_name, contact_person, contact_number, email, address, billing_address, status)
             VALUES (?, ?, ?, ?, ?, ?, 'Active')"
        )->execute([$name, $contact, $phone, $email, $address, $billingAddress]);
        $newId = (int)$pdo->lastInsertId();
        if ($routeIds !== null) syncClientRoutes($pdo, $newId, $routeIds);
        auditLog('CREATE_CLIENT', 'clients', $newId, null, ['client_name' => $name]);
        $pdo->commit();
        jsonOk(['id' => $newId], 'Client added successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('clients_handler/save: ' . $e->getMessage());
        jsonFail('Could not save the client.', 500);
    }
}

if ($action === 'toggle') {
    $clientId = requiredInt('client_id', 'Client', 1);
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT status FROM clients WHERE client_id = ? FOR UPDATE');
        $lock->execute([$clientId]);
        $status = $lock->fetchColumn();
        if ($status === false) {
            $pdo->rollBack();
            jsonFail('Client not found.', 404);
        }
        $newStatus = $status === 'Active' ? 'Inactive' : 'Active';
        if ($newStatus === 'Inactive') {
            // A client with trips still under way stays active.
            $open = $pdo->prepare(
                "SELECT COUNT(*) FROM trips WHERE client_id = ? AND status NOT IN ('Completed', 'Cancelled')"
            );
            $open->execute([$clientId]);
            if ((int)$open->fetchColumn() > 0) {
                $pdo->rollBack();
                jsonFail('Complete or cancel this client\'s open trips before deactivating it.', 409);
            }
        }
        $pdo->prepare('UPDATE clients SET status = ? WHERE client_id = ?')->execute([$newStatus, $clientId]);
        auditLog('TOGGLE_CLIENT', 'clients', $clientId, ['status' => $status], ['status' => $newStatus]);
        $pdo->commit();
        jsonOk(['is_active' => $newStatus === 'Active' ? 1 : 0], 'Client status updated.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('clients_handler/toggle: ' . $e->getMessage());
        jsonFail('Could not update the client.', 500);
    }
}

jsonFail('Unknown action.');
