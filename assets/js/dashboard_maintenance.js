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

  // ── Maintenance type donut ──────────────────────────────────────────────────
  const donutCtx = document.getElementById('maintTypeChart');
  if (donutCtx) {
    new Chart(donutCtx, {
      type: 'doughnut',
      data: {
        labels: D.donut.labels,
        datasets: [{
          data: D.donut.data,
          backgroundColor: D.donut.colors,
          borderColor: isDark ? '#152236' : '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: { color: textColor, boxWidth: 12, padding: 12, font: { size: 11 } }
          },
          tooltip: {
            backgroundColor: tooltipBg, borderColor: tooltipBrd, borderWidth: 1,
            titleColor: isDark ? '#e2e8f0' : '#212529', bodyColor: textColor, padding: 10,
          }
        }
      }
    });
  }

  // ── Maintenance cost bar chart ──────────────────────────────────────────────
  const costCtx = document.getElementById('maintCostChart');
  if (costCtx) {
    new Chart(costCtx, {
      type: 'bar',
      data: {
        labels: D.cost.labels,
        datasets: [{
          label: 'Cost (₱)',
          data: D.cost.data,
          backgroundColor: 'rgba(253,126,20,0.75)',
          borderColor: '#fd7e14',
          borderWidth: 1.5,
          borderRadius: 6,
          borderSkipped: false,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: tooltipBg, borderColor: tooltipBrd, borderWidth: 1,
            titleColor: isDark ? '#e2e8f0' : '#212529', bodyColor: textColor, padding: 10,
            callbacks: { label: ctx => ` ₱${ctx.parsed.y.toLocaleString()}` }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor } },
          y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, callback: v => '₱' + v.toLocaleString() } }
        }
      }
    });
  }

  setTimeout(() => window.location.reload(), 120000);
})();