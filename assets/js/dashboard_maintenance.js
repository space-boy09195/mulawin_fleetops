'use strict';
(function () {
  const D = window.DASH_DATA;
  if (!D) return;

  const isDark    = document.documentElement.getAttribute('data-theme') === 'dark';
  const gridColor = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
  const textColor = isDark ? '#7F9487' : '#5F6D64';
  const tooltipBg = isDark ? '#0D2112' : '#FFFFFF';
  const tooltipBrd = isDark ? 'rgba(0,255,133,0.18)' : '#D6DED7';

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
          borderColor: isDark ? '#0A1B0E' : '#FFFFFF',
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
            titleColor: isDark ? '#F1FFF6' : '#172019', bodyColor: textColor, padding: 10,
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
          borderColor: (isDark ? '#FFB800' : '#A87916'),
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
            titleColor: isDark ? '#F1FFF6' : '#172019', bodyColor: textColor, padding: 10,
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