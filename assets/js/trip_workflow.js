document.addEventListener('DOMContentLoaded', () => {
  const documentsStatus = document.getElementById('documentsStatus');
  const documentsNotesGroup = document.getElementById('documentsNotesGroup');
  const documentsNotes = document.getElementById('documentsNotes');
  const allowanceStatus = document.getElementById('allowanceStatus');
  const allowanceNotesGroup = document.getElementById('allowanceNotesGroup');
  const allowanceNotes = document.getElementById('allowanceNotes');
  const fundRequestGroup = document.getElementById('fundRequestGroup');
  const fundRequestId = document.getElementById('fundRequestId');

  function syncPreparationFields() {
    const noDocuments = documentsStatus?.value === 'Not Required';
    const noAllowance = allowanceStatus?.value === 'Not Required';
    const allowanceApproved = allowanceStatus?.value === 'Approved';
    documentsNotesGroup?.classList.toggle('d-none', !noDocuments);
    allowanceNotesGroup?.classList.toggle('d-none', !noAllowance);
    fundRequestGroup?.classList.toggle('d-none', !allowanceApproved);
    if (documentsNotes) documentsNotes.required = Boolean(noDocuments);
    if (allowanceNotes) allowanceNotes.required = Boolean(noAllowance);
    if (fundRequestId) fundRequestId.required = Boolean(allowanceApproved);
  }

  documentsStatus?.addEventListener('change', syncPreparationFields);
  allowanceStatus?.addEventListener('change', syncPreparationFields);
  syncPreparationFields();

  document.querySelectorAll('.trip-workflow-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;

      const button = form.querySelector('button[type="submit"]');
      const action = form.querySelector('[name="action"]')?.value || 'unknown';
      const tripId = form.querySelector('[name="trip_id"]')?.value || 'unknown';
      const scope = `trip.workflow.${action}:${tripId}`;
      if (button) button.disabled = true;

      try {
        const body = new FormData(form);
        body.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
        const response = await fetch(`${window.APP_BASE}/ajax/trip_workflow_handler.php`, {
          method: 'POST',
          headers: window.fleetOpsIdempotencyHeaders(scope),
          body,
        });
        const result = await response.json();
        if (result.success) {
          window.fleetOpsCompleteIdempotency(scope);
          window.location.reload();
          return;
        }
        if (response.status === 409) window.fleetOpsCompleteIdempotency(scope);
        alert(result.message || 'Could not save the trip workflow action.');
      } catch (error) {
        console.error(error);
        alert('Network error while saving the trip workflow action.');
      } finally {
        if (button) button.disabled = false;
      }
    });
  });
});
