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

  document.querySelectorAll('.dd-quick-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const tripId = btn.dataset.tripId;
      const select = document.querySelector(`.dd-quick-select[data-trip-id="${tripId}"]`);
      const status = select?.value;
      if (!tripId || !status) return;

      if (status === 'Cancelled' && !confirm('Cancel this trip?')) return;

      btn.disabled = true;
      const icon = btn.querySelector('i');
      const originalClass = icon.className;
      icon.className = 'spinner-border spinner-border-sm';

      fetch(BASE + '/ajax/update_trip_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ trip_id: tripId, status, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
      })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            window.location.reload();
          } else {
            icon.className = originalClass;
            btn.disabled = false;
            alert(res.message || 'Failed to update trip status.');
          }
        })
        .catch(() => {
          icon.className = originalClass;
          btn.disabled = false;
          alert('Network error. Please try again.');
        });
    });
  });
})();