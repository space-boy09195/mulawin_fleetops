'use strict';
(function () {
  const D = window.DASH_DATA;
  if (!D) return;

  const isDark    = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  const gridColor = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
  const textColor = isDark ? '#a0aec0' : '#6c757d';
  const tooltipBg = isDark ? '#1e2d3d' : '#fff';
  const tooltipBrd = isDark ? '#2d4058' : '#dee2e6';

  Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";

  // ── Trip Status bar chart ───────────────────────────────────────────────────
  const ctx = document.getElementById('tripStatusChart');
  if (ctx) {
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: D.statusChart.labels,
        datasets: [{
          label: 'Trips',
          data: D.statusChart.data,
          backgroundColor: D.statusChart.colors,
          borderRadius: 6,
          borderSkipped: false,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: tooltipBg,
            borderColor: tooltipBrd,
            borderWidth: 1,
            titleColor: isDark ? '#e2e8f0' : '#212529',
            bodyColor: textColor,
            padding: 10,
            callbacks: {
              label: ctx => ` ${ctx.parsed.y} trip${ctx.parsed.y !== 1 ? 's' : ''}`,
            }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor } },
          y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, precision: 0 } }
        }
      }
    });
  }

  setTimeout(() => window.location.reload(), 120000);

  // ── Quick status update (Live Trips widget) ─────────────────────────────────
  const BASE = window.APP_BASE ?? '';

  // Same request as the Trip Monitoring update: idempotency key per trip,
  // cancellation reason required, Admin override reason asked once on 422.
  async function sendStatus(tripId, values) {
    const scope = `trip.status:${tripId}`;
    const response = await fetch(BASE + '/ajax/update_trip_status.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        ...window.fleetOpsIdempotencyHeaders(scope),
      },
      body: new URLSearchParams({ trip_id: tripId, ...values, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error(`Server returned HTTP ${response.status} instead of JSON.`);
    }
    if (response.status === 422 && !('override_reason' in values)) {
      const reason = (window.prompt(`${result.message || 'An Admin override reason is required.'}\n\nAdmin override reason (1-500 characters):`) || '').trim();
      if (!reason) return { success: false, message: 'Cancelled: no Admin override reason was given. Nothing was saved.' };
      return sendStatus(tripId, { ...values, override_reason: reason });
    }
    if (result.success || response.status === 409) {
      window.fleetOpsCompleteIdempotency(scope);
    }
    return result;
  }

  document.querySelectorAll('.dd-quick-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const tripId = btn.dataset.tripId;
      const select = document.querySelector(`.dd-quick-select[data-trip-id="${tripId}"]`);
      const status = select?.value;
      if (!tripId || !status) return;

      const values = { status };
      if (status === 'Cancelled') {
        const reason = (window.prompt('Reason for cancelling this trip:') || '').trim();
        if (!reason) return;
        values.notes = reason;
      } else if (!window.confirm(`Set this trip to ${status}?`)) {
        return;
      }

      btn.disabled = true;
      const icon = btn.querySelector('i');
      const originalClass = icon.className;
      icon.className = 'spinner-border spinner-border-sm';
      try {
        const res = await sendStatus(tripId, values);
        if (res.success) {
          window.location.reload();
          return;
        }
        alert(res.message || 'Failed to update trip status.');
      } catch (error) {
        alert(error.message || 'Network error. Please try again.');
      }
      icon.className = originalClass;
      btn.disabled = false;
    });
  });
})();
