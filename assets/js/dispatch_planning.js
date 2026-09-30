document.addEventListener('DOMContentLoaded', () => {
  const endpoint = `${window.APP_BASE ?? ''}/ajax/dispatch_instructions_handler.php`;
  const form = document.getElementById('dispatchInstructionForm');
  const alertElement = document.getElementById('instructionAlert');

  function showAlert(message, type = 'danger') {
    if (!alertElement) return;
    alertElement.className = `alert alert-${type}`;
    alertElement.textContent = message;
    alertElement.classList.remove('d-none');
  }

  async function post(values, headers = {}) {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        ...headers,
      },
      body: new URLSearchParams({
        ...values,
        [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN,
      }),
    });
    const text = await response.text();
    try {
      return {response, result: JSON.parse(text)};
    } catch {
      throw new Error(`Server returned HTTP ${response.status} instead of JSON.`);
    }
  }

  form?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('sendInstructionButton');
    const scope = 'dispatch-instruction:create';
    button.disabled = true;
    try {
      const {response, result} = await post({
        action: 'create',
        client_id: document.getElementById('instructionClient').value,
        route_id: document.getElementById('instructionRoute').value,
        scheduled_at: document.getElementById('instructionScheduledAt').value,
        shift: document.getElementById('instructionShift').value,
        unit_count: document.getElementById('instructionUnits').value,
        instruction_notes: document.getElementById('instructionNotes').value.trim(),
      }, window.fleetOpsIdempotencyHeaders(scope));
      if (!result.success) {
        showAlert(result.message || 'Could not send the instruction.');
        if (response.status === 409) window.fleetOpsCompleteIdempotency(scope);
        return;
      }
      window.fleetOpsCompleteIdempotency(scope);
      window.location.reload();
    } catch (error) {
      showAlert(error.message || 'Network error. Please try again.');
    } finally {
      button.disabled = false;
    }
  });

  const batchContainer = document.getElementById('dispatchBatchEntries');
  const batchTemplate = document.getElementById('dispatchBatchEntryTemplate');
  const addBatchEntryButton = document.getElementById('addDispatchBatchEntry');
  const sendBatchButton = document.getElementById('sendDispatchBatch');
  const maxBatchEntries = 25;

  function refreshBatchEntries() {
    if (!batchContainer || !addBatchEntryButton || !sendBatchButton) return;
    const rows = Array.from(batchContainer.querySelectorAll('.dispatch-batch-entry'));
    rows.forEach((row, index) => {
      row.querySelector('.dispatch-batch-entry-number').textContent = String(index + 1);
      row.querySelector('[data-remove-batch-entry]').disabled = rows.length <= 2;
    });
    addBatchEntryButton.disabled = rows.length >= maxBatchEntries;
    sendBatchButton.disabled = rows.length < 2;
    addBatchEntryButton.title = rows.length >= maxBatchEntries
      ? `A batch can contain no more than ${maxBatchEntries} trips.`
      : '';
  }

  function addBatchEntry() {
    if (!batchContainer || !batchTemplate || batchContainer.children.length >= maxBatchEntries) return;
    batchContainer.append(batchTemplate.content.firstElementChild.cloneNode(true));
    refreshBatchEntries();
  }

  addBatchEntryButton?.addEventListener('click', addBatchEntry);
  batchContainer?.addEventListener('click', event => {
    const removeButton = event.target.closest('[data-remove-batch-entry]');
    if (!removeButton || batchContainer.children.length <= 2) return;
    removeButton.closest('.dispatch-batch-entry').remove();
    refreshBatchEntries();
  });

  addBatchEntry();
  addBatchEntry();

  sendBatchButton?.addEventListener('click', async () => {
    const rows = Array.from(batchContainer.querySelectorAll('.dispatch-batch-entry'));
    if (rows.length < 2 || rows.length > maxBatchEntries) return;
    const controls = rows.flatMap(row => Array.from(row.querySelectorAll('select, input, textarea')));
    const invalidControl = controls.find(control => !control.checkValidity());
    if (invalidControl) {
      invalidControl.reportValidity();
      return;
    }

    const entries = rows.map(row => ({
      client_id: row.querySelector('.batch-client').value,
      route_id: row.querySelector('.batch-route').value,
      scheduled_at: row.querySelector('.batch-scheduled-at').value,
      shift: row.querySelector('.batch-shift').value,
      unit_count: row.querySelector('.batch-unit-count').value,
      instruction_notes: row.querySelector('.batch-instruction-notes').value.trim(),
    }));
    const scope = 'dispatch-instruction:batch-create';
    sendBatchButton.disabled = true;
    try {
      const {response, result} = await post({
        action: 'create_batch',
        entries: JSON.stringify(entries),
      }, window.fleetOpsIdempotencyHeaders(scope));
      if (!result.success) {
        showAlert(result.message || 'Could not send the dispatch batch.');
        if (response.status === 409) window.fleetOpsCompleteIdempotency(scope);
        return;
      }
      window.fleetOpsCompleteIdempotency(scope);
      window.location.reload();
    } catch (error) {
      showAlert(error.message || 'Network error. Please try again.');
    } finally {
      refreshBatchEntries();
    }
  });

  document.querySelectorAll('.js-cancel-instruction').forEach(button => {
    button.addEventListener('click', async () => {
      if (!window.confirm('Cancel this dispatch instruction?')) return;
      button.disabled = true;
      try {
        const {result} = await post({
          action: 'cancel',
          instruction_id: button.dataset.id,
        });
        if (!result.success) {
          showAlert(result.message || 'Could not cancel the instruction.');
          button.disabled = false;
          return;
        }
        window.location.reload();
      } catch (error) {
        showAlert(error.message || 'Network error. Please try again.');
        button.disabled = false;
      }
    });
  });
});
