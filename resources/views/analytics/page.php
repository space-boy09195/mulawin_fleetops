<?php
// ============================================================
// resources/views/analytics/page.php
// Analytics page markup, included by pages/analytics.php after its data
// layer and layoutHead(). Uses the page variables $analyticsTitle,
// $periodLabel, $granularities, $granularity, $periods, $period, $sections,
// $charts and $chartJson. Holds no queries or permission checks: those stay
// in pages/analytics.php. Not reachable by URL (.htaccess denies resources/);
// opened without the application it returns 404.
// ============================================================
if (!defined('APP_BASE')) {
    http_response_code(404);
    exit;
}

function anHasData(array $chart): bool {
    foreach ($chart['datasets'] as $dataset) {
        foreach ($dataset['data'] as $value) {
            if ($value !== null && (float)$value > 0) {
                return true;
            }
        }
    }
    return false;
}

function anKpi(array $kpi): string {
    $tone = $kpi['tone'] !== '' ? ' an-kpi-' . $kpi['tone'] : '';
    $hero = !empty($kpi['hero']) ? ' an-kpi-hero' : '';
    return '<div class="an-kpi' . $tone . $hero . '">'
        . '<div class="an-kpi-label">' . htmlspecialchars($kpi['label']) . '</div>'
        . '<div class="an-kpi-value">' . htmlspecialchars($kpi['value']) . '</div>'
        . '<div class="an-kpi-sub">' . htmlspecialchars($kpi['sub']) . '</div></div>';
}

function anChartCard(string $id, array $chart): void { ?>
  <article class="an-card an-span-<?= (int)$chart['span'] ?>">
    <header class="an-card-head">
      <h3><?= htmlspecialchars($chart['title']) ?></h3>
      <p><?= htmlspecialchars($chart['note']) ?></p>
    </header>
    <?php if (anHasData($chart)): ?>
      <div class="an-chart an-chart-<?= htmlspecialchars($chart['height']) ?>">
        <canvas id="<?= htmlspecialchars($id) ?>" role="img" aria-label="<?= htmlspecialchars($chart['title'] . ' — values are listed in the detailed records below') ?>"></canvas>
      </div>
    <?php else: ?>
      <div class="an-chart-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i> No recorded data for this period.</div>
    <?php endif; ?>
    <details class="an-method">
      <summary>How this is calculated</summary>
      <dl>
        <?php foreach ($chart['method'] as $term => $text): ?>
          <dt><?= htmlspecialchars($term) ?></dt><dd><?= htmlspecialchars($text) ?></dd>
        <?php endforeach; ?>
      </dl>
    </details>
  </article>
<?php }

function anTable(array $table): void { ?>
  <div class="an-table-card">
    <h4><?= htmlspecialchars($table['title']) ?></h4>
    <?php if (!$table['rows']): ?>
      <p class="an-muted">No records in this period.</p>
    <?php else: ?>
    <div class="an-table-wrap">
      <table class="table align-middle mb-0">
        <thead><tr><?php foreach ($table['headers'] as $i => $header): ?>
          <th class="<?= $i > 0 ? 'text-end' : '' ?>"><?= htmlspecialchars($header) ?></th>
        <?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($table['rows'] as $row): ?>
          <tr><?php foreach ($row as $i => $cell): ?>
            <td class="<?= $i > 0 ? 'text-end' : '' ?>"><?= htmlspecialchars(is_int($cell) ? number_format($cell) : (string)$cell) ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
<?php }
?>
<div class="an-page">
  <header class="an-header">
    <div>
      <h1 class="an-title"><?= htmlspecialchars($analyticsTitle) ?></h1>
      <p class="an-subtitle">Recorded business data · <?= htmlspecialchars($periodLabel) ?> · grouped <?= htmlspecialchars(strtolower($granularities[$granularity])) ?></p>
    </div>
    <form method="get" class="an-filters">
      <div>
        <label for="period">Reporting period</label>
        <select id="period" name="period" class="form-select" onchange="this.form.submit()">
          <?php foreach ($periods as $key => $option): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= htmlspecialchars($option['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="granularity">Group trends</label>
        <select id="granularity" name="granularity" class="form-select" onchange="this.form.submit()">
          <?php foreach ($granularities as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $granularity === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </header>

  <?php if (!$sections): ?>
    <p class="an-muted">No analytics sections are available for your role.</p>
  <?php else: ?>
  <?php if (count($sections) > 1): ?>
  <nav class="an-tabs" aria-label="Analytics departments">
    <?php foreach ($sections as $key => $section): ?>
      <a href="#an-<?= htmlspecialchars($key) ?>"><i class="bi <?= htmlspecialchars($section['icon']) ?>" aria-hidden="true"></i> <?= htmlspecialchars($section['title']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php foreach ($sections as $key => $section): ?>
  <section class="an-dept an-dept-<?= htmlspecialchars($key) ?>" id="an-<?= htmlspecialchars($key) ?>" aria-labelledby="an-<?= htmlspecialchars($key) ?>-h">
    <header class="an-dept-head">
      <span class="an-dept-icon"><i class="bi <?= htmlspecialchars($section['icon']) ?>" aria-hidden="true"></i></span>
      <div>
        <h2 id="an-<?= htmlspecialchars($key) ?>-h"><?= htmlspecialchars($section['title']) ?></h2>
        <p><?= htmlspecialchars($section['blurb']) ?></p>
      </div>
    </header>

    <div class="an-kpis">
      <?php foreach ($section['kpis'] as $kpi) { echo anKpi($kpi); } ?>
    </div>

    <div class="an-grid">
      <?php foreach ($section['charts'] as $chartId) { anChartCard($chartId, $charts[$chartId]); } ?>
    </div>

    <details class="an-detail">
      <summary><i class="bi bi-table" aria-hidden="true"></i> Detailed records</summary>
      <div class="an-table-grid">
        <?php foreach ($section['tables'] as $table) { anTable($table); } ?>
      </div>
    </details>

    <p class="an-unavailable"><i class="bi bi-info-circle" aria-hidden="true"></i> <strong>Not shown:</strong> <?= htmlspecialchars($section['unavailable']) ?></p>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<script>
window.ANALYTICS_DATA = <?= json_encode($chartJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
</script>
