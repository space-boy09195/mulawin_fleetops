<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');
requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $clientId = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT) ?: null;
    $name = requiredString('client_name', 'Client name', 150);
    $contact = optionalString('contact_person', null, 150);
    $phone = optionalString('phone', null, 50);
    $email = optionalString('email', null, 150);
    $address = optionalString('address', null, 255);
    $notes = optionalString('notes', null, 5000);

    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Enter a valid client email address.');
    }
    $duplicate = $pdo->prepare('SELECT client_id FROM clients WHERE LOWER(client_name) = LOWER(?) AND client_id <> COALESCE(?, 0)');
    $duplicate->execute([$name, $clientId]);
    if ($duplicate->fetchColumn()) {
        jsonFail('A client with that name already exists.');
    }

    if ($clientId) {
        $stmt = $pdo->prepare('UPDATE clients SET client_name=?, contact_person=?, phone=?, email=?, address=?, notes=? WHERE client_id=?');
        $stmt->execute([$name, $contact, $phone, $email, $address, $notes, $clientId]);
        auditLog('UPDATE_CLIENT', 'clients', $clientId, null, ['client_name' => $name]);
        jsonOk([], 'Client updated successfully.');
    }

    $stmt = $pdo->prepare('INSERT INTO clients (client_name, contact_person, phone, email, address, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $contact, $phone, $email, $address, $notes, currentUserId()]);
    $newId = (int)$pdo->lastInsertId();
    auditLog('CREATE_CLIENT', 'clients', $newId, null, ['client_name' => $name]);
    jsonOk(['id' => $newId], 'Client added successfully.');
}

if ($action === 'toggle') {
    $clientId = requiredInt('client_id', 'Client', 1);
    $client = findOrFail($pdo, 'clients', 'client_id', $clientId, 'Client not found.');
    $newState = (int)!((int)$client['is_active']);
    $pdo->prepare('UPDATE clients SET is_active=? WHERE client_id=?')->execute([$newState, $clientId]);
    auditLog('TOGGLE_CLIENT', 'clients', $clientId, ['is_active' => $client['is_active']], ['is_active' => $newState]);
    jsonOk(['is_active' => $newState], 'Client status updated.');
}

jsonFail('Unknown action.');
