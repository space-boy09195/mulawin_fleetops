<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAnyPermission(['clients.manage']);

$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
if (!$clientId || $clientId < 1) {
    http_response_code(400);
    exit('Invalid client.');
}

$pdo = getDBConnection();
$clientQuery = $pdo->prepare('SELECT client_id, client_name, client_type FROM clients WHERE client_id = ?');
$clientQuery->execute([$clientId]);
$client = $clientQuery->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    http_response_code(404);
    exit('Client not found.');
}

$locationQuery = $pdo->prepare(
    'SELECT location_id, location_name, location_type, address, contact_person,
            contact_number, notes, is_active
     FROM client_locations
     WHERE client_id = ?
     ORDER BY is_active DESC, location_name'
);
$locationQuery->execute([$clientId]);
$locations = $locationQuery->fetchAll(PDO::FETCH_ASSOC);

$rateQuery = $pdo->prepare(
    'SELECT cr.*, origin.location_name AS origin_name, destination.location_name AS destination_name
     FROM client_rates cr
     JOIN client_locations origin ON origin.location_id = cr.origin_location_id
     JOIN client_locations destination ON destination.location_id = cr.destination_location_id
     WHERE cr.client_id = ?
     ORDER BY cr.is_active DESC, cr.effective_from DESC, cr.rate_id DESC'
);
$rateQuery->execute([$clientId]);
$rates = $rateQuery->fetchAll(PDO::FETCH_ASSOC);
$activeLocations = array_values(array_filter($locations, static fn(array $location): bool => (bool)$location['is_active']));
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$GLOBALS['page_js'] = APP_BASE . '/assets/js/client_master_data.js';

layoutHead('Client Locations & Rates', APP_BASE . '/assets/css/billing.css');
?>
<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Locations &amp; Rates</h1>
    <p class="page-subtitle"><?= $escape($client['client_name']) ?> · <?= $escape($client['client_type']) ?></p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= APP_BASE ?>/pages/clients.php">Back to Clients</a>
</div>

<div id="masterDataAlert" class="alert d-none" role="alert"></div>

<section class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Client locations</h2></div>
  <div class="card-body">
    <form id="locationForm" class="mb-4">
      <input type="hidden" name="action" value="save_location">
      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
      <input type="hidden" name="location_id" id="locationId">
      <div class="row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label" for="locationName">Location name</label><input class="form-control" name="location_name" id="locationName" maxlength="150" required></div>
        <div class="col-md-2"><label class="form-label" for="locationType">Use</label><select class="form-select" name="location_type" id="locationType"><option>Both</option><option>Pickup</option><option>Delivery</option></select></div>
        <div class="col-md-4"><label class="form-label" for="locationAddress">Address</label><input class="form-control" name="address" id="locationAddress" maxlength="500" required></div>
        <div class="col-md-3"><label class="form-label" for="locationContact">Contact person</label><input class="form-control" name="contact_person" id="locationContact" maxlength="150"></div>
        <div class="col-md-3"><label class="form-label" for="locationPhone">Contact number</label><input class="form-control" name="contact_number" id="locationPhone" maxlength="50"></div>
        <div class="col-md-6"><label class="form-label" for="locationNotes">Notes</label><input class="form-control" name="notes" id="locationNotes" maxlength="1000"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit" id="saveLocationButton">Add location</button><button class="btn btn-outline-secondary d-none" type="button" id="cancelLocationEdit">Cancel edit</button></div>
      </div>
    </form>
    <div class="table-responsive">
      <table class="table-custom">
        <thead><tr><th>Location</th><th>Type</th><th>Address</th><th>Contact</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$locations): ?><tr><td colspan="6" class="text-center text-muted py-4">No client locations yet.</td></tr>
        <?php else: foreach ($locations as $location): ?>
          <tr>
            <td><strong><?= $escape($location['location_name']) ?></strong><?php if ($location['notes']): ?><br><span class="small text-muted"><?= $escape($location['notes']) ?></span><?php endif; ?></td>
            <td><?= $escape($location['location_type']) ?></td><td><?= $escape($location['address']) ?></td>
            <td><?= $escape($location['contact_person'] ?? '—') ?><br><?= $escape($location['contact_number'] ?? '') ?></td>
            <td><?= $location['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-primary edit-location"
                data-id="<?= (int)$location['location_id'] ?>" data-name="<?= $escape($location['location_name']) ?>"
                data-type="<?= $escape($location['location_type']) ?>" data-address="<?= $escape($location['address']) ?>"
                data-contact="<?= $escape($location['contact_person'] ?? '') ?>" data-phone="<?= $escape($location['contact_number'] ?? '') ?>"
                data-notes="<?= $escape($location['notes'] ?? '') ?>">Edit</button>
              <button type="button" class="btn btn-sm btn-outline-secondary toggle-location"
                data-id="<?= (int)$location['location_id'] ?>" data-active="<?= (int)$location['is_active'] ?>">
                <?= $location['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card">
  <div class="card-header-custom"><h2 class="card-title-custom">Effective-dated rate cards</h2></div>
  <div class="card-body">
    <?php if (count($activeLocations) < 2): ?>
      <p class="alert alert-info">Add at least two active locations before defining a route rate.</p>
    <?php else: ?>
    <form id="rateForm" class="mb-4">
      <input type="hidden" name="action" value="save_rate">
      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
      <input type="hidden" name="rate_id" id="rateId">
      <div class="row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label" for="originLocation">Pickup location</label><select class="form-select" name="origin_location_id" id="originLocation" required><option value="">Select</option><?php foreach ($activeLocations as $location): ?><option value="<?= (int)$location['location_id'] ?>"><?= $escape($location['location_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="destinationLocation">Delivery location</label><select class="form-select" name="destination_location_id" id="destinationLocation" required><option value="">Select</option><?php foreach ($activeLocations as $location): ?><option value="<?= (int)$location['location_id'] ?>"><?= $escape($location['location_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="serviceName">Service</label><input class="form-control" name="service_name" id="serviceName" maxlength="100" value="Standard" required></div>
        <div class="col-md-3"><label class="form-label" for="rateBasis">Rate basis</label><select class="form-select" name="rate_basis" id="rateBasis"><option>Per Trip</option><option>Per Ton</option><option>Per Kilometer</option><option>Per Unit</option></select></div>
        <div class="col-md-3"><label class="form-label" for="rateAmount">Amount</label><input class="form-control" type="number" name="rate_amount" id="rateAmount" min="0.01" step="0.01" required></div>
        <div class="col-md-2"><label class="form-label" for="rateCurrency">Currency</label><input class="form-control" name="currency" id="rateCurrency" maxlength="3" value="PHP" required></div>
        <div class="col-md-3"><label class="form-label" for="effectiveFrom">Effective from</label><input class="form-control" type="date" name="effective_from" id="effectiveFrom" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-3"><label class="form-label" for="effectiveTo">Effective to</label><input class="form-control" type="date" name="effective_to" id="effectiveTo"></div>
        <div class="col-md-8"><label class="form-label" for="rateNotes">Notes</label><input class="form-control" name="notes" id="rateNotes" maxlength="1000"></div>
        <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary" type="submit" id="saveRateButton">Add rate</button><button class="btn btn-outline-secondary d-none" type="button" id="cancelRateEdit">Cancel edit</button></div>
      </div>
    </form>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table-custom">
        <thead><tr><th>Pickup → Delivery</th><th>Service</th><th>Rate</th><th>Effective</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$rates): ?><tr><td colspan="6" class="text-center text-muted py-4">No client rates yet.</td></tr>
        <?php else: foreach ($rates as $rate): ?>
          <tr>
            <td><?= $escape($rate['origin_name']) ?> → <?= $escape($rate['destination_name']) ?></td>
            <td><?= $escape($rate['service_name']) ?><br><span class="small text-muted"><?= $escape($rate['rate_basis']) ?></span></td>
            <td><?= $escape($rate['currency']) ?> <?= number_format((float)$rate['rate_amount'], 2) ?></td>
            <td><?= $escape($rate['effective_from']) ?> → <?= $escape($rate['effective_to'] ?? 'No end date') ?></td>
            <td><?= $rate['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-primary edit-rate"
                data-id="<?= (int)$rate['rate_id'] ?>" data-origin="<?= (int)$rate['origin_location_id'] ?>"
                data-destination="<?= (int)$rate['destination_location_id'] ?>" data-service="<?= $escape($rate['service_name']) ?>"
                data-basis="<?= $escape($rate['rate_basis']) ?>" data-amount="<?= $escape($rate['rate_amount']) ?>"
                data-currency="<?= $escape($rate['currency']) ?>" data-from="<?= $escape($rate['effective_from']) ?>"
                data-to="<?= $escape($rate['effective_to'] ?? '') ?>" data-notes="<?= $escape($rate['notes'] ?? '') ?>">Edit</button>
              <button type="button" class="btn btn-sm btn-outline-secondary toggle-rate"
                data-id="<?= (int)$rate['rate_id'] ?>" data-active="<?= (int)$rate['is_active'] ?>">
                <?= $rate['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php layoutFoot(); ?>
