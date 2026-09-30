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
    $clientType = requiredEnum('client_type', ['Direct', 'Forwarder', 'End Client'], 'Client type');
    $parentClientRaw = trim((string)($_POST['parent_client_id'] ?? ''));
    $parentClientId = null;
    if ($parentClientRaw !== '') {
        $parsedParentClientId = filter_var($parentClientRaw, FILTER_VALIDATE_INT);
        if ($parsedParentClientId === false || $parsedParentClientId < 1) {
            jsonFail('Parent client selection is invalid.');
        }
        $parentClientId = (int)$parsedParentClientId;
    }
    if ($parentClientId !== null) {
        if ($parentClientId === $clientId) {
            jsonFail('A client cannot be its own parent.');
        }
        $parent = $pdo->prepare('SELECT is_active FROM clients WHERE client_id = ?');
        $parent->execute([$parentClientId]);
        $parentActive = $parent->fetchColumn();
        if ($parentActive === false || (int)$parentActive !== 1) {
            jsonFail('Selected parent client does not exist or is inactive.');
        }
    }
    if ($clientType === 'Forwarder' && $parentClientId !== null) {
        jsonFail('A forwarder cannot be assigned another parent client.');
    }

    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Enter a valid client email address.');
    }
    $duplicate = $pdo->prepare('SELECT client_id FROM clients WHERE LOWER(client_name) = LOWER(?) AND client_id <> COALESCE(?, 0)');
    $duplicate->execute([$name, $clientId]);
    if ($duplicate->fetchColumn()) {
        jsonFail('A client with that name already exists.');
    }

    if ($clientId) {
        $stmt = $pdo->prepare('UPDATE clients SET client_name=?, contact_person=?, phone=?, email=?, address=?, notes=?, client_type=?, parent_client_id=? WHERE client_id=?');
        $stmt->execute([$name, $contact, $phone, $email, $address, $notes, $clientType, $parentClientId, $clientId]);
        auditLog('UPDATE_CLIENT', 'clients', $clientId, null, ['client_name' => $name]);
        jsonOk([], 'Client updated successfully.');
    }

    $stmt = $pdo->prepare('INSERT INTO clients (client_name, contact_person, phone, email, address, notes, client_type, parent_client_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $contact, $phone, $email, $address, $notes, $clientType, $parentClientId, currentUserId()]);
    $newId = (int)$pdo->lastInsertId();
    auditLog('CREATE_CLIENT', 'clients', $newId, null, ['client_name' => $name]);
    jsonOk(['id' => $newId], 'Client added successfully.');
}

if ($action === 'toggle') {
    $clientId = requiredInt('client_id', 'Client', 1);
    $client = findOrFail($pdo, 'clients', 'client_id', $clientId, 'Client not found.');
    $newState = (int)!((int)$client['is_active']);
    if ($newState === 0) {
        $children = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE parent_client_id = ? AND is_active = 1');
        $children->execute([$clientId]);
        if ((int)$children->fetchColumn() > 0) {
            jsonFail('Reassign or deactivate active child clients before deactivating this client.', 409);
        }
    }
    $pdo->prepare('UPDATE clients SET is_active=? WHERE client_id=?')->execute([$newState, $clientId]);
    auditLog('TOGGLE_CLIENT', 'clients', $clientId, ['is_active' => $client['is_active']], ['is_active' => $newState]);
    jsonOk(['is_active' => $newState], 'Client status updated.');
}

jsonFail('Unknown action.');
