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
            borderColor: '#0d6efd',
            borderWidth: 1.5,
            borderRadius: 5,
            borderSkipped: false,
          },
          {
            label: 'Collected',
            data: D.monthly.collected.map(v => +(v / 1000).toFixed(2)),
            backgroundColor: 'rgba(25,135,84,0.75)',
            borderColor: '#198754',
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
            titleColor: isDark ? '#e2e8f0' : '#212529', bodyColor: textColor, padding: 10,
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
          borderColor: '#198754',
          backgroundColor: 'rgba(25,135,84,0.08)',
          borderWidth: 2.5,
          pointBackgroundColor: '#198754',
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
            titleColor: isDark ? '#e2e8f0' : '#212529', bodyColor: textColor, padding: 10,
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