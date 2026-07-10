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
})();