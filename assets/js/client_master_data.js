document.addEventListener('DOMContentLoaded', () => {
  const base = window.APP_BASE ?? '';
  const endpoint = `${base}/ajax/client_master_data_handler.php`;
  const alertEl = document.getElementById('masterDataAlert');

  function showMessage(text, type = 'danger') {
    alertEl.className = `alert alert-${type}`;
    alertEl.textContent = text;
    alertEl.classList.remove('d-none');
    alertEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  async function post(values) {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ ...values, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
    });
    const text = await response.text();
    let result;
    try {
      result = JSON.parse(text);
    } catch {
      throw new Error(`Server returned HTTP ${response.status} instead of JSON.`);
    }
    if (!response.ok && result.success) {
      throw new Error(`Request failed with HTTP ${response.status}.`);
    }
    return result;
  }

  async function submitForm(form) {
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    alertEl.classList.add('d-none');
    try {
      const response = await post(Object.fromEntries(new FormData(form).entries()));
      if (!response.success) {
        showMessage(response.message || 'Could not save the record.');
        return;
      }
      window.location.reload();
    } catch (error) {
      showMessage(error.message || 'Network error.');
    } finally {
      button.disabled = false;
    }
  }

  const locationForm = document.getElementById('locationForm');
  const locationId = document.getElementById('locationId');
  const locationButton = document.getElementById('saveLocationButton');
  const cancelLocationEdit = document.getElementById('cancelLocationEdit');
  const locationFields = ['locationName', 'locationType', 'locationAddress', 'locationContact', 'locationPhone', 'locationNotes'];

  function resetLocationForm() {
    locationForm?.reset();
    if (locationId) locationId.value = '';
    if (locationButton) locationButton.textContent = 'Add location';
    cancelLocationEdit?.classList.add('d-none');
  }

  locationForm?.addEventListener('submit', event => {
    event.preventDefault();
    submitForm(locationForm);
  });
  cancelLocationEdit?.addEventListener('click', resetLocationForm);
  document.querySelectorAll('.edit-location').forEach(button => {
    button.addEventListener('click', () => {
      if (locationId) locationId.value = button.dataset.id ?? '';
      const keys = ['name', 'type', 'address', 'contact', 'phone', 'notes'];
      locationFields.forEach((id, index) => {
        const input = document.getElementById(id);
        if (input) input.value = button.dataset[keys[index]] ?? '';
      });
      if (locationButton) locationButton.textContent = 'Save location';
      cancelLocationEdit?.classList.remove('d-none');
      locationForm?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  document.querySelectorAll('.toggle-location').forEach(button => {
    button.addEventListener('click', async () => {
      const active = button.dataset.active === '1';
      if (!window.confirm(`${active ? 'Deactivate' : 'Activate'} this location?`)) return;
      button.disabled = true;
      try {
        const result = await post({ action: 'toggle_location', location_id: button.dataset.id });
        if (result.success) window.location.reload();
        else showMessage(result.message || 'Could not update location status.');
      } catch (error) {
        showMessage(error.message || 'Network error.');
      } finally {
        button.disabled = false;
      }
    });
  });

  const rateForm = document.getElementById('rateForm');
  const rateId = document.getElementById('rateId');
  const rateButton = document.getElementById('saveRateButton');
  const cancelRateEdit = document.getElementById('cancelRateEdit');

  function resetRateForm() {
    rateForm?.reset();
    if (rateId) rateId.value = '';
    const currency = document.getElementById('rateCurrency');
    const service = document.getElementById('serviceName');
    const effectiveFrom = document.getElementById('effectiveFrom');
    if (currency) currency.value = 'PHP';
    if (service) service.value = 'Standard';
    if (effectiveFrom) {
      const today = new Date();
      effectiveFrom.value = new Date(today.getTime() - today.getTimezoneOffset() * 60000)
        .toISOString().slice(0, 10);
    }
    if (rateButton) rateButton.textContent = 'Add rate';
    cancelRateEdit?.classList.add('d-none');
  }

  rateForm?.addEventListener('submit', event => {
    event.preventDefault();
    submitForm(rateForm);
  });
  cancelRateEdit?.addEventListener('click', resetRateForm);
  document.querySelectorAll('.edit-rate').forEach(button => {
    button.addEventListener('click', () => {
      if (rateId) rateId.value = button.dataset.id ?? '';
      const values = {
        originLocation: button.dataset.origin,
        destinationLocation: button.dataset.destination,
        serviceName: button.dataset.service,
        rateBasis: button.dataset.basis,
        rateAmount: button.dataset.amount,
        rateCurrency: button.dataset.currency,
        effectiveFrom: button.dataset.from,
        effectiveTo: button.dataset.to,
        rateNotes: button.dataset.notes,
      };
      Object.entries(values).forEach(([id, value]) => {
        const input = document.getElementById(id);
        if (input) input.value = value ?? '';
      });
      if (rateButton) rateButton.textContent = 'Save rate';
      cancelRateEdit?.classList.remove('d-none');
      rateForm?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  document.querySelectorAll('.toggle-rate').forEach(button => {
    button.addEventListener('click', async () => {
      const active = button.dataset.active === '1';
      if (!window.confirm(`${active ? 'Deactivate' : 'Activate'} this rate?`)) return;
      button.disabled = true;
      try {
        const result = await post({ action: 'toggle_rate', rate_id: button.dataset.id });
        if (result.success) window.location.reload();
        else showMessage(result.message || 'Could not update rate status.');
      } catch (error) {
        showMessage(error.message || 'Network error.');
      } finally {
        button.disabled = false;
      }
    });
  });
});
