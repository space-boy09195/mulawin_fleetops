'use strict';

document.querySelectorAll('[data-dashboard-attendance]').forEach((panel) => {
  const feedback = document.getElementById(panel.dataset.feedback);
  const elapsed = panel.dataset.elapsed
    ? document.getElementById(panel.dataset.elapsed)
    : null;
  if (!feedback) return;

  const start = Number(panel.dataset.start);
  if (elapsed && Number.isFinite(start) && start > 0) {
    const updateElapsed = () => {
      const minutes = Math.max(0, Math.floor((Date.now() - start) / 60000));
      elapsed.textContent = `· ${Math.floor(minutes / 60)}h ${minutes % 60}m elapsed`;
    };
    updateElapsed();
    window.setInterval(updateElapsed, 60000);
  }

  panel.querySelectorAll('[data-attendance-action]').forEach((button) => {
    button.addEventListener('click', async () => {
      const action = button.dataset.attendanceAction;
      const overtimeReason = action === 'clock_out'
        ? (window.prompt('If this shift is over 8 hours, enter the overtime reason. Otherwise leave blank.') || '')
        : '';
      button.disabled = true;
      const body = new URLSearchParams({
        action,
        overtime: overtimeReason !== '' ? 'true' : 'false',
        overtime_reason: overtimeReason,
        [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN
      });

      try {
        const response = await fetch(`${window.APP_BASE}/ajax/attendance_handler.php`, {
          method: 'POST',
          body
        });
        const result = await response.json();
        feedback.textContent = result.message || 'Attendance request completed.';
        feedback.classList.remove('is-success', 'is-error');
        feedback.classList.add(result.success ? 'is-success' : 'is-error');
        if (result.success) window.location.reload();
      } catch (error) {
        console.error('Dashboard attendance request failed:', error);
        feedback.textContent = 'Attendance could not be updated. Please try again.';
        feedback.classList.remove('is-success', 'is-error');
        feedback.classList.add('is-error');
      } finally {
        button.disabled = false;
      }
    });
  });
});
