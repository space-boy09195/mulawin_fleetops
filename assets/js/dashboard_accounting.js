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

  // ── Billed vs Collected bar chart ───────────────────────────────────────────
  const barCtx = document.getElementById('billedCollectedChart');
  if (barCtx) {
    new Chart(barCtx, {
      type: 'bar',
      data: {
        labels: D.monthly.labels,
        datasets: [
          {
            label: 'Billed',
            data: D.monthly.billed.map(v => +(v / 1000).toFixed(2)),
            backgroundColor: 'rgba(13,110,253,0.75)',
            borderColor: (isDark ? '#00D9FF' : '#276B62'),
            borderWidth: 1.5,
            borderRadius: 5,
            borderSkipped: false,
          },
          {
            label: 'Collected',
            data: D.monthly.collected.map(v => +(v / 1000).toFixed(2)),
            backgroundColor: 'rgba(25,135,84,0.75)',
            borderColor: (isDark ? '#00FF85' : '#197A46'),
            borderWidth: 1.5,
            borderRadius: 5,
            borderSkipped: false,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: true, position: 'bottom',
            labels: { color: textColor, boxWidth: 12, padding: 14, font: { size: 11 } }
          },
          tooltip: {
            backgroundColor: tooltipBg, borderColor: tooltipBrd, borderWidth: 1,
            titleColor: isDark ? '#F1FFF6' : '#172019', bodyColor: textColor, padding: 10,
            callbacks: { label: ctx => ` ${ctx.dataset.label}: ₱${ctx.parsed.y}K` }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor } },
          y: {
            beginAtZero: true, grid: { color: gridColor },
            ticks: { color: textColor, callback: v => '₱' + v + 'K' }
          }
        }
      }
    });
  }

  // ── Collection rate line chart ──────────────────────────────────────────────
  const lineCtx = document.getElementById('collectionRateChart');
  if (lineCtx) {
    new Chart(lineCtx, {
      type: 'line',
      data: {
        labels: D.rate.labels,
        datasets: [{
          label: 'Collection Rate',
          data: D.rate.data,
          borderColor: (isDark ? '#00FF85' : '#197A46'),
          backgroundColor: 'rgba(25,135,84,0.08)',
          borderWidth: 2.5,
          pointBackgroundColor: (isDark ? '#00FF85' : '#197A46'),
          pointRadius: 5,
          pointHoverRadius: 7,
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
            backgroundColor: tooltipBg, borderColor: tooltipBrd, borderWidth: 1,
            titleColor: isDark ? '#F1FFF6' : '#172019', bodyColor: textColor, padding: 10,
            callbacks: { label: ctx => ` ${ctx.parsed.y}%` }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor } },
          y: {
            beginAtZero: false, min: 0, max: 100, grid: { color: gridColor },
            ticks: { color: textColor, callback: v => v + '%' }
          }
        }
      }
    });
  }

  setTimeout(() => window.location.reload(), 120000);
})();