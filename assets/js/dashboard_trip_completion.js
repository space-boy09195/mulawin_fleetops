'use strict';

document.querySelectorAll('[data-report-trip-completed]').forEach((button) => {
  button.addEventListener('click', async () => {
    const tripNumber = button.dataset.tripNumber || 'this trip';
    if (!window.confirm(`Report ${tripNumber} as completed? This will notify operations but will not change the official trip status.`)) {
      return;
    }

    const feedback = document.getElementById('tripCompletionFeedback');
    const body = new URLSearchParams({
      trip_id: button.dataset.tripId,
      [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN
    });
    button.disabled = true;

    try {
      const response = await fetch(`${window.APP_BASE}/ajax/trip_completion_handler.php`, {
        method: 'POST',
        body,
        headers: { Accept: 'application/json' }
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'The completion report could not be submitted.');
      }

      feedback.textContent = result.message;
      feedback.classList.remove('is-error');
      feedback.classList.add('is-success');
      const reported = document.createElement('div');
      reported.className = 'fleet-dash-completion-reported';
      const icon = document.createElement('i');
      icon.className = 'bi bi-check2-circle';
      icon.setAttribute('aria-hidden', 'true');
      const copy = document.createElement('div');
      const title = document.createElement('strong');
      title.textContent = 'Completion Reported';
      const detail = document.createElement('span');
      detail.textContent = 'Operations has been notified. The trip still needs its official post-trip completion.';
      copy.append(title, detail);
      reported.append(icon, copy);
      button.replaceWith(reported);
    } catch (error) {
      console.error('Trip completion report failed:', error);
      feedback.textContent = error.message || 'The completion report could not be submitted. Please try again.';
      feedback.classList.remove('is-success');
      feedback.classList.add('is-error');
      button.disabled = false;
    }
  });
});
