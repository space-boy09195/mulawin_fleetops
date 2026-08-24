/**
 * analytics.js — Mulawin FleetOps
 * Renders the cross-functional Analytics page charts and handles CSV export
 * of the leaderboard tables.
 */
'use strict';
(function () {
  const D = window.ANALYTICS_DATA;
  if (!D) return;

  const isDark    = document.documentElement.getAttribute('data-theme') === 'dark';
  const gridColor = isDark ? 'rgba(225,245,225,0.24)' : 'rgba(23,35,27,0.18)';
  const textColor = isDark ? '#e4f0df' : '#24382a';
  const tooltipBg = isDark ? '#123b26' : '#fffdf7';
  const tooltipBrd = isDark ? '#e0b83f' : '#9a6800';
  const legendTextColor = isDark ? '#f3f5e9' : '#17231b';

  function refreshChartTheme() {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    const grid = dark ? 'rgba(225,245,225,0.24)' : 'rgba(23,35,27,0.18)';
    const text = dark ? '#e4f0df' : '#24382a';
    const tooltip = dark ? '#123b26' : '#fffdf7';
    const border = dark ? '#39734f' : '#9a6800';
    const legend = dark ? '#f3f5e9' : '#17231b';
    Chart.defaults.color = text;
    Chart.defaults.borderColor = grid;

    Object.values(Chart.instances).forEach((chart) => {
      const scales = chart.options.scales || {};
      Object.values(scales).forEach((scale) => {
        if (scale.ticks) {
          scale.ticks.color = text;
          scale.ticks.font = { weight: '600' };
        }
        if (scale.grid) scale.grid.color = grid;
      });
      if (chart.options.plugins?.legend?.labels) {
        chart.options.plugins.legend.labels.color = legend;
      }
      if (chart.options.plugins?.tooltip) {
        chart.options.plugins.tooltip.backgroundColor = tooltip;
        chart.options.plugins.tooltip.borderColor = border;
        chart.options.plugins.tooltip.titleColor = legend;
        chart.options.plugins.tooltip.bodyColor = text;
      }
      if (chart.config.type === 'doughnut') {
        chart.data.datasets[0].borderColor = dark ? '#0d2a1b' : '#fffdf7';
      }
      chart.update('none');
    });
  }

  Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
  Chart.defaults.font.size   = 13;
  Chart.defaults.color       = textColor;
  Chart.defaults.borderColor = gridColor;

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
            backgroundColor: isDark ? 'rgba(86,210,126,0.82)' : 'rgba(25,135,84,0.78)',
            borderColor: isDark ? '#8af0a4' : '#126b3d',
            borderWidth: 1,
            borderRadius: 4,
            order: 2,
          },
          {
            type: 'line',
            label: 'Maintenance Cost',
            data: D.revCostTrend.cost,
            borderColor: isDark ? '#ff7b7b' : '#a3262a',
            backgroundColor: 'rgba(163,38,42,0.12)',
            borderWidth: 3,
            pointBackgroundColor: isDark ? '#ff9b9b' : '#a3262a',
            pointBorderColor: isDark ? '#fff1e6' : '#fffdf7',
            pointBorderWidth: 2,
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
          x: { grid: { display: false }, ticks: { color: textColor, font: { weight: '600' } } },
          y: {
            beginAtZero: true,
            grid: { color: gridColor },
            ticks: { color: textColor, font: { weight: '600' }, callback: v => '₱' + Number(v).toLocaleString() },
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
          borderColor: isDark ? '#0d2a1b' : '#fffdf7',
          borderWidth: 4,
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
          borderColor: isDark ? '#0d2a1b' : '#fffdf7',
          borderWidth: 4,
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
          backgroundColor: isDark ? 'rgba(255,196,76,0.86)' : 'rgba(180,122,0,0.82)',
          borderColor: isDark ? '#ffe39a' : '#825700',
          borderWidth: 1,
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

  // ── Dispatcher: Completed Trips volume chart ──────────────────────────────────
  const tripVolumeCtx = document.getElementById('tripVolumeChart');
  if (tripVolumeCtx && D.tripVolumeTrend) {
    new Chart(tripVolumeCtx, {
      type: 'bar',
      data: {
        labels: D.tripVolumeTrend.labels,
        datasets: [{
          label: 'Completed Trips',
          data: D.tripVolumeTrend.data,
          backgroundColor: isDark ? 'rgba(116,170,255,0.86)' : 'rgba(32,91,157,0.82)',
          borderColor: isDark ? '#b7d5ff' : '#173f70',
          borderWidth: 1,
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
            callbacks: { label: ctx => ` ${ctx.parsed.y} trip${ctx.parsed.y !== 1 ? 's' : ''}` },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor } },
          y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, stepSize: 1, precision: 0 } },
        },
      },
    });
  }

  // ── Maintenance: Cost-only trend chart ────────────────────────────────────────
  const maintCostTrendCtx = document.getElementById('maintCostTrendChart');
  if (maintCostTrendCtx && D.maintCostTrend) {
    new Chart(maintCostTrendCtx, {
      type: 'bar',
      data: {
        labels: D.maintCostTrend.labels,
        datasets: [{
          label: 'Maintenance Cost',
          data: D.maintCostTrend.data,
          backgroundColor: isDark ? 'rgba(255,123,123,0.86)' : 'rgba(163,38,42,0.82)',
          borderColor: isDark ? '#ffbaba' : '#7e1d21',
          borderWidth: 1,
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
            callbacks: { label: ctx => ` ${peso(ctx.parsed.y)}` },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor } },
          y: {
            beginAtZero: true, grid: { color: gridColor },
            ticks: { color: textColor, callback: v => '₱' + Number(v).toLocaleString() },
          },
        },
      },
    });
  }

  // ── Accounting: Billed vs Collected combo chart ───────────────────────────────
  const billedCollectedCtx = document.getElementById('billedCollectedChart');
  if (billedCollectedCtx && D.billedCollected) {
    new Chart(billedCollectedCtx, {
      type: 'bar',
      data: {
        labels: D.billedCollected.labels,
        datasets: [
          {
            label: 'Billed',
            data: D.billedCollected.revenue,
            backgroundColor: isDark ? 'rgba(116,170,255,0.86)' : 'rgba(32,91,157,0.82)',
            borderColor: isDark ? '#b7d5ff' : '#173f70',
            borderWidth: 1,
            borderRadius: 4,
            order: 2,
          },
          {
            type: 'line',
            label: 'Collected',
            data: D.billedCollected.collected,
            borderColor: isDark ? '#8af0a4' : '#126b3d',
            backgroundColor: 'rgba(25,135,84,0.12)',
            borderWidth: 3,
            pointBackgroundColor: isDark ? '#8af0a4' : '#126b3d',
            pointBorderColor: isDark ? '#fff1e6' : '#fffdf7',
            pointBorderWidth: 2,
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
          x: { grid: { display: false }, ticks: { color: textColor, font: { weight: '600' } } },
          y: {
            beginAtZero: true, grid: { color: gridColor },
            ticks: { color: textColor, font: { weight: '600' }, callback: v => '₱' + Number(v).toLocaleString() },
          },
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

  window.addEventListener('mulawin:themechange', refreshChartTheme);

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

  // ── Alert popover behavior (Analytics page) ──────────────────────────────
  const popover = document.createElement('div');
  popover.className = 'an-alert-popover';
  popover.style.display = 'none';
  document.body.appendChild(popover);

  function closePopover() {
    popover.style.display = 'none';
    popover.innerHTML = '';
  }

  document.addEventListener('click', (ev) => {
    // close when clicking outside
    if (!popover.contains(ev.target) && !ev.target.closest('.an-alert-toggle')) {
      closePopover();
    }
  });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closePopover(); });

  document.querySelectorAll('.an-alert-toggle').forEach(btn => {
    btn.addEventListener('click', (ev) => {
      ev.preventDefault();
      const detail = btn.dataset.detail || '';
      const presJson = btn.dataset.prescription || '[]';
      let prescriptions = [];
      try { prescriptions = JSON.parse(presJson); } catch (e) { prescriptions = []; }
      const actionUrl = btn.dataset.action || '';
      const title = btn.dataset.title || 'Detail';

      popover.innerHTML = '';
      const closeBtn = document.createElement('button');
      closeBtn.className = 'close-pop';
      closeBtn.textContent = '×';
      closeBtn.addEventListener('click', closePopover);
      popover.appendChild(closeBtn);

      const h = document.createElement('h4');
      h.textContent = title;
      popover.appendChild(h);

      const p = document.createElement('div');
      p.style.color = 'var(--bs-secondary-color)';
      p.style.fontSize = '0.95rem';
      p.textContent = detail;
      popover.appendChild(p);

      if (prescriptions.length) {
        const pres = document.createElement('div');
        pres.className = 'an-alert-prescriptions';
        const presTitle = document.createElement('div');
        presTitle.style.fontWeight = '700';
        presTitle.style.marginTop = '8px';
        presTitle.textContent = 'Suggested actions';
        pres.appendChild(presTitle);
        const ul = document.createElement('ul');
        prescriptions.forEach(it => {
          const li = document.createElement('li');
          li.textContent = it;
          ul.appendChild(li);
        });
        pres.appendChild(ul);
        popover.appendChild(pres);
      }

      if (actionUrl) {
        const a = document.createElement('a');
        a.href = actionUrl;
        a.className = 'dh-rec-action';
        a.style.display = 'inline-block';
        a.style.marginTop = '10px';
        a.textContent = 'Take action';
        popover.appendChild(a);
      }

      // position popover near the button, but keep inside viewport
      const rect = btn.getBoundingClientRect();
      const docLeft = window.pageXOffset || document.documentElement.scrollLeft;
      const docTop = window.pageYOffset || document.documentElement.scrollTop;
      const left = Math.min(Math.max(docLeft + rect.left, 12), window.innerWidth - 340);
      const top = docTop + rect.bottom + 8;
      popover.style.left = left + 'px';
      popover.style.top = top + 'px';
      popover.style.display = 'block';
    });
  });
})();