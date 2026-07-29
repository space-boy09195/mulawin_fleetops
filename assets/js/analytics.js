/**
 * analytics.js — Mulawin FleetOps
 * Renders the cross-functional Analytics page charts and handles CSV export
 * of the leaderboard tables.
 */
'use strict';
(function () {
  const D = window.ANALYTICS_DATA;
  if (!D) return;

  const isDark    = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  const gridColor = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
  const textColor = isDark ? '#a0aec0' : '#6c757d';
  const tooltipBg = isDark ? '#1e2d3d' : '#fff';
  const tooltipBrd = isDark ? '#2d4058' : '#dee2e6';
  const legendTextColor = isDark ? '#e2e8f0' : '#212529';

  Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
  Chart.defaults.font.size   = 12;

  function peso(value) {
    return '₱' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // ── Revenue vs Maintenance Cost combo chart ───────────────────────────────
  const revCostCtx = document.getElementById('revCostChart');
  if (revCostCtx) {
    new Chart(revCostCtx, {
      type: 'bar',
      data: {
        labels: D.revCostTrend.labels,
        datasets: [
          {
            type: 'bar',
            label: 'Revenue',
            data: D.revCostTrend.revenue,
            backgroundColor: 'rgba(25,135,84,0.65)',
            borderRadius: 4,
            order: 2,
          },
          {
            type: 'line',
            label: 'Maintenance Cost',
            data: D.revCostTrend.cost,
            borderColor: '#dc3545',
            backgroundColor: 'rgba(220,53,69,0.08)',
            borderWidth: 2.5,
            pointBackgroundColor: '#dc3545',
            pointRadius: 4,
            pointHoverRadius: 6,
            tension: 0.3,
            fill: false,
            order: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top', labels: { color: legendTextColor, boxWidth: 12, padding: 14 } },
          tooltip: {
            backgroundColor: tooltipBg, borderColor: tooltipBrd, borderWidth: 1,
            titleColor: legendTextColor, bodyColor: textColor, padding: 10,
            callbacks: { label: ctx => ` ${ctx.dataset.label}: ${peso(ctx.parsed.y)}` },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor } },
          y: {
            beginAtZero: true,
            grid: { color: gridColor },
            ticks: { color: textColor, callback: v => '₱' + Number(v).toLocaleString() },
          },
        },
      },
    });
  }

  // ── Trip Status donut ──────────────────────────────────────────────────────
  const tripStatusCtx = document.getElementById('tripStatusChart');
  if (tripStatusCtx) {
    new Chart(tripStatusCtx, {
      type: 'doughnut',
      data: {
        labels: D.tripStatus.labels,
        datasets: [{
          data: D.tripStatus.data,
          backgroundColor: D.tripStatus.colors,
          borderColor: isDark ? '#152236' : '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: tooltipBg, borderColor: tooltipBrd, borderWidth: 1,
            titleColor: legendTextColor, bodyColor: textColor, padding: 10,
            callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed}` },
          },
        },
      },
    });
  }

  // ── Maintenance Cost by Type donut ──────────────────────────────────────────
  const maintTypeCtx = document.getElementById('maintTypeChart');
  if (maintTypeCtx) {
    new Chart(maintTypeCtx, {
      type: 'doughnut',
      data: {
        labels: D.maintType.labels,
        datasets: [{
          data: D.maintType.data,
          backgroundColor: D.maintType.colors,
          borderColor: isDark ? '#152236' : '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: tooltipBg, borderColor: tooltipBrd, borderWidth: 1,
            titleColor: legendTextColor, bodyColor: textColor, padding: 10,
            callbacks: { label: ctx => ` ${ctx.label}: ${peso(ctx.parsed)}` },
          },
        },
      },
    });
  }

  // ── Incident Trend bar chart ─────────────────────────────────────────────────
  const incTrendCtx = document.getElementById('incTrendChart');
  if (incTrendCtx) {
    new Chart(incTrendCtx, {
      type: 'bar',
      data: {
        labels: D.incTrend.labels,
        datasets: [{
          label: 'Incidents',
          data: D.incTrend.data,
          backgroundColor: 'rgba(255,145,0,0.65)',
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: tooltipBg, borderColor: tooltipBrd, borderWidth: 1,
            titleColor: legendTextColor, bodyColor: textColor, padding: 10,
            callbacks: { label: ctx => ` ${ctx.parsed.y} incident${ctx.parsed.y !== 1 ? 's' : ''}` },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor } },
          y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, stepSize: 1, precision: 0 } },
        },
      },
    });
  }

  // ── CSV export for leaderboard tables ────────────────────────────────────────
  function tableToCsv(table) {
    const rows = Array.from(table.querySelectorAll('tr'));
    return rows.map(row =>
      Array.from(row.querySelectorAll('th, td'))
        .map(cell => {
          const text = cell.textContent.trim().replace(/\s+/g, ' ');
          return `"${text.replace(/"/g, '""')}"`;
        })
        .join(',')
    ).join('\r\n');
  }

  document.querySelectorAll('.an-export-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const table = document.getElementById(btn.dataset.export);
      if (!table) return;
      const csv  = tableToCsv(table);
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href     = url;
      a.download = `${btn.dataset.filename || 'export'}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });
  });
})();