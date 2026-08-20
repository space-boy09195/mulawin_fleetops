'use strict';
(function () {
  const D = window.DASH_DATA;
  if (!D) return;

  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const gridColor  = isDark ? 'rgba(225,245,225,0.24)' : 'rgba(23,35,27,0.18)';
  const textColor  = isDark ? '#e4f0df' : '#24382a';
  const tooltipBg  = isDark ? '#123b26' : '#fffdf7';
  const tooltipBrd = isDark ? '#39734f' : '#b47a00';

  function refreshChartTheme() {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    const grid = dark ? 'rgba(225,245,225,0.24)' : 'rgba(23,35,27,0.18)';
    const text = dark ? '#e4f0df' : '#24382a';
    const tooltip = dark ? '#123b26' : '#fffdf7';
    const border = dark ? '#39734f' : '#9a6800';
    Chart.defaults.color = text;
    Chart.defaults.borderColor = grid;

    Object.values(Chart.instances).forEach((chart) => {
      chart.options.scales?.x && (chart.options.scales.x.ticks.color = text);
      chart.options.scales?.y && (chart.options.scales.y.ticks.color = text);
      chart.options.scales?.x && (chart.options.scales.x.grid.color = grid);
      chart.options.scales?.y && (chart.options.scales.y.grid.color = grid);
      if (chart.options.plugins?.tooltip) {
        chart.options.plugins.tooltip.backgroundColor = tooltip;
        chart.options.plugins.tooltip.borderColor = border;
        chart.options.plugins.tooltip.titleColor = dark ? '#f3f5e9' : '#17231b';
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
          borderColor: '#b47a00',
          backgroundColor: 'rgba(180,122,0,0.14)',
          borderWidth: 3,
          pointBackgroundColor: '#b47a00',
          pointBorderColor: isDark ? '#fff1e6' : '#fffdf7',
          pointBorderWidth: 2,
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
            titleColor: isDark ? '#f3f5e9' : '#17231b',
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
            ticks: { color: textColor, font: { weight: '600' }, maxRotation: 0 },
          },
          y: {
            beginAtZero: true,
            grid: { color: gridColor },
            ticks: { color: textColor, font: { weight: '600' }, stepSize: 1, precision: 0 },
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
          borderColor: isDark ? '#0d2a1b' : '#fffdf7',
          borderWidth: 4,
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
            titleColor: isDark ? '#f3f5e9' : '#17231b',
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
  window.addEventListener('mulawin:themechange', refreshChartTheme);
})();