'use strict';
(function () {
  const D = window.DASH_DATA;
  if (!D) return;

  const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  const gridColor  = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
  const textColor  = isDark ? '#a0aec0' : '#6c757d';
  const tooltipBg  = isDark ? '#1e2d3d' : '#fff';
  const tooltipBrd = isDark ? '#2d4058' : '#dee2e6';

  Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
  Chart.defaults.font.size   = 12;

  // ── Trip Trends line chart ──────────────────────────────────────────────────
  const trendCtx = document.getElementById('tripTrendChart');
  if (trendCtx) {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: D.trend.labels,
        datasets: [{
          label: 'Trips Created',
          data: D.trend.data,
          borderColor: '#0d6efd',
          backgroundColor: 'rgba(13,110,253,0.08)',
          borderWidth: 2.5,
          pointBackgroundColor: '#0d6efd',
          pointRadius: 4,
          pointHoverRadius: 6,
          tension: 0.3,
          fill: true,
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
          x: {
            grid: { color: gridColor },
            ticks: { color: textColor, maxRotation: 0 },
          },
          y: {
            beginAtZero: true,
            grid: { color: gridColor },
            ticks: { color: textColor, stepSize: 1, precision: 0 },
          }
        }
      }
    });
  }

  // ── Fleet Status donut ──────────────────────────────────────────────────────
  const donutCtx = document.getElementById('fleetDonutChart');
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
        cutout: '70%',
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
              label: ctx => ` ${ctx.label}: ${ctx.parsed} truck${ctx.parsed !== 1 ? 's' : ''}`,
            }
          }
        }
      }
    });
  }

  // ── Auto-refresh every 2 min ──────────────────────────────────────────────
  setTimeout(() => window.location.reload(), 120000);
})();