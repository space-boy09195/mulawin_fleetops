'use strict';
// Operations Head dashboard charts (fleet status donut, trips by status).
(function () {
  const D = window.DASH_DATA;
  if (!D || !window.Chart) return;

  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
  Chart.defaults.color = isDark ? '#e4f0df' : '#24382a';
  Chart.defaults.borderColor = isDark ? 'rgba(225,245,225,0.24)' : 'rgba(23,35,27,0.18)';

  const fleetCtx = document.getElementById('opsFleetChart');
  if (fleetCtx && D.fleet) {
    new Chart(fleetCtx, {
      type: 'doughnut',
      data: {
        labels: D.fleet.labels,
        datasets: [{
          data: D.fleet.data,
          backgroundColor: D.fleet.colors,
          borderColor: isDark ? '#0d2a1b' : '#fffdf7',
          borderWidth: 2,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom' } },
      },
    });
  }

  const statusCtx = document.getElementById('opsTripStatusChart');
  if (statusCtx && D.tripStatus) {
    new Chart(statusCtx, {
      type: 'bar',
      data: {
        labels: D.tripStatus.labels,
        datasets: [{
          label: 'Trips',
          data: D.tripStatus.data,
          backgroundColor: isDark ? 'rgba(243,209,106,0.75)' : 'rgba(180,122,0,0.75)',
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }
})();
