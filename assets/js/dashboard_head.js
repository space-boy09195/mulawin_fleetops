'use strict';
(function () {
  const D = window.DASH_DATA;
  if (!D) return;

  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const gridColor  = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
  const textColor  = isDark ? '#7F9487' : '#5F6D64';
  const tooltipBg  = isDark ? '#0D2112' : '#FFFFFF';
  const tooltipBrd = isDark ? 'rgba(0,255,133,0.18)' : '#D6DED7';

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
          borderColor: (isDark ? '#00D9FF' : '#276B62'),
          backgroundColor: 'rgba(13,110,253,0.08)',
          borderWidth: 2.5,
          pointBackgroundColor: (isDark ? '#00D9FF' : '#276B62'),
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
            titleColor: isDark ? '#F1FFF6' : '#172019',
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
          borderColor: isDark ? '#0A1B0E' : '#FFFFFF',
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
            titleColor: isDark ? '#F1FFF6' : '#172019',
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