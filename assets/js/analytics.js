(function () {
  'use strict';

  var data = window.ANALYTICS_DATA || {};
  var instances = {};

  var pesoFmt = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2 });
  var compactFmt = new Intl.NumberFormat('en-PH', { notation: 'compact', maximumFractionDigits: 1 });

  function isDark() {
    return document.documentElement.getAttribute('data-theme') === 'dark';
  }

  function fmt(format, value) {
    return format === 'peso' ? pesoFmt.format(value) : new Intl.NumberFormat('en-PH').format(value);
  }

  function plural(unit, n) {
    if (!unit) { return ''; }
    return ' ' + unit + (n === 1 ? '' : 's');
  }

  var centerTotal = {
    id: 'anCenterTotal',
    afterDraw: function (chart, args, opts) {
      if (!opts || !opts.text) { return; }
      var meta = chart.getDatasetMeta(0);
      if (!meta || !meta.data.length) { return; }
      var x = meta.data[0].x, y = meta.data[0].y, ctx = chart.ctx;
      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = opts.color;
      ctx.font = '700 20px system-ui, sans-serif';
      ctx.fillText(opts.text, x, y - 7);
      ctx.font = '12px system-ui, sans-serif';
      ctx.fillStyle = opts.sub;
      ctx.fillText('total', x, y + 13);
      ctx.restore();
    }
  };

  function render() {
    if (typeof Chart === 'undefined') { return; }
    var dark = isDark();
    var text = dark ? '#c9d1cc' : '#4a554e';
    var grid = dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)';
    var sliceBorder = dark ? '#1c2420' : '#ffffff';

    Object.keys(instances).forEach(function (id) { instances[id].destroy(); });
    instances = {};

    Object.keys(data).forEach(function (id) {
      var def = data[id];
      var canvas = document.getElementById(id);
      if (!canvas) { return; }
      var donut = def.type === 'doughnut';
      var horizontal = def.type === 'hbar';
      var multi = def.datasets.length > 1;

      var datasets = def.datasets.map(function (ds) {
        if (donut) {
          return { label: ds.label, data: ds.data, backgroundColor: ds.colors, borderColor: sliceBorder, borderWidth: 2 };
        }
        return { label: ds.label, data: ds.data, backgroundColor: ds.color, borderRadius: 4, maxBarThickness: horizontal ? 22 : 38 };
      });

      var total = donut ? def.datasets[0].data.reduce(function (a, b) { return a + Number(b); }, 0) : 0;
      var options = {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: horizontal ? 'y' : 'x',
        plugins: {
          legend: { display: donut || multi, position: donut ? 'bottom' : 'top', labels: { color: text, boxWidth: 12, usePointStyle: true } },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                var v = Number(ctx.raw);
                var out = ' ' + (donut ? ctx.label : ctx.dataset.label) + ': ' + fmt(def.format, v);
                if (def.format === 'count') { out += plural(def.unit, v); }
                if (donut && total > 0) { out += ' (' + (v / total * 100).toFixed(1) + '%)'; }
                return out;
              }
            }
          },
          anCenterTotal: donut ? { text: def.format === 'peso' ? '₱' + compactFmt.format(total) : String(total), color: text, sub: text } : false
        }
      };
      if (donut) {
        options.cutout = '62%';
      } else {
        var valueAxis = {
          beginAtZero: true,
          grid: { color: grid },
          ticks: {
            color: text,
            precision: 0,
            callback: function (v) { return def.format === 'peso' ? '₱' + compactFmt.format(v) : v; }
          }
        };
        var labelAxis = { grid: { display: false }, ticks: { color: text, maxRotation: 45, autoSkip: true } };
        options.scales = horizontal ? { x: valueAxis, y: labelAxis } : { x: labelAxis, y: valueAxis };
      }

      instances[id] = new Chart(canvas.getContext('2d'), {
        type: donut ? 'doughnut' : 'bar',
        data: { labels: def.labels, datasets: datasets },
        options: options,
        plugins: donut ? [centerTotal] : []
      });
    });
  }

  document.addEventListener('DOMContentLoaded', render);
  document.addEventListener('mulawin:themechange', render);
})();
