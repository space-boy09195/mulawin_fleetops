'use strict';

function attendanceTime(value) {
  const [hours, minutes] = String(value).split(':').map(Number);
  return new Date(2000, 0, 1, hours, minutes).toLocaleTimeString([], {
    hour: 'numeric',
    minute: '2-digit'
  });
}

function workedDuration(minutes) {
  const safeMinutes = Math.max(0, Number(minutes) || 0);
  return `${Math.floor(safeMinutes / 60)}h ${safeMinutes % 60}m`;
}

function renderAttendance(panel, attendance) {
  const display = panel.querySelector('[data-attendance-display]');
  if (!display) return;

  const previousState = display.querySelector('[data-attendance-state]');
  const previousNote = display.querySelector('[data-attendance-note]');
  const state = document.createElement('p');
  state.className = previousState?.className
    || (panel.id.startsWith('admin') ? '' : 'fleet-dash-attendance-state');
  state.dataset.attendanceState = '';
  const note = document.createElement('p');
  note.className = previousNote?.className
    || (panel.id.startsWith('admin') ? 'dh-muted' : 'fleet-dash-attendance-note');
  note.dataset.attendanceNote = '';
  display.replaceChildren(state, note);

  const timeIn = document.createElement('strong');
  timeIn.textContent = attendanceTime(attendance.time_in);
  state.append('Timed in at ', timeIn);

  const elapsedId = panel.dataset.elapsed;
  if (!attendance.time_out) {
    const elapsed = document.createElement('span');
    elapsed.id = elapsedId;
    note.append('Your shift is in progress ', elapsed, '.');
    panel.dataset.start = String(
      attendance.started_at_ms ?? Date.parse(`${attendance.attendance_date}T${attendance.time_in}`)
    );

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-outline-primary';
    button.dataset.attendanceAction = 'clock_out';
    button.textContent = 'Time Out';
    display.append(button);
    return;
  }

  const timeOut = document.createElement('strong');
  timeOut.textContent = attendanceTime(attendance.time_out);
  state.append(' · timed out at ', timeOut);
  note.textContent = `Worked ${workedDuration(attendance.worked_minutes)}.`;
  panel.dataset.start = '';
}

document.querySelectorAll('[data-dashboard-attendance]').forEach((panel) => {
  const feedback = document.getElementById(panel.dataset.feedback);
  if (!feedback) return;

  const updateElapsed = () => {
    const elapsed = panel.dataset.elapsed
      ? document.getElementById(panel.dataset.elapsed)
      : null;
    const start = Number(panel.dataset.start);
    if (!elapsed || !Number.isFinite(start) || start <= 0) return;
    const minutes = Math.max(0, Math.floor((Date.now() - start) / 60000));
    elapsed.textContent = `· ${Math.floor(minutes / 60)}h ${minutes % 60}m elapsed`;
  };
  if (panel.dataset.elapsed) {
    updateElapsed();
    window.setInterval(updateElapsed, 60000);
  }

  panel.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-attendance-action]');
    if (!button || !panel.contains(button)) return;

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
        body,
        headers: { Accept: 'application/json' }
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Attendance could not be updated.');
      }

      feedback.textContent = result.message;
      feedback.classList.remove('is-error');
      feedback.classList.add('is-success');
      renderAttendance(panel, result.attendance);
      const days = panel.querySelector('[data-attendance-days]');
      if (days && Number.isInteger(result.attendance.days_this_month)) {
        const count = result.attendance.days_this_month;
        days.textContent = `${count} recorded work day${count === 1 ? '' : 's'} this month`;
      }
      updateElapsed();
    } catch (error) {
      console.error('Dashboard attendance request failed:', error);
      feedback.textContent = error.message || 'Attendance could not be updated. Please try again.';
      feedback.classList.remove('is-success');
      feedback.classList.add('is-error');
      button.disabled = false;
    }
  });
});
