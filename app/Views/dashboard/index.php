<?php

use App\Domain\Invoicing\Receivables;

/**
 * @var array<string, list<array{label: string, value: string, hint: string, panel: string, tone: string, icon: string}>> $kpis par domaine
 * @var array<string, mixed> $charts activity, pipeline, shipments, aging (selon les droits)
 * @var list<array<string, mixed>> $alerts notifications (panel : détail en fenêtre, sinon lien)
 */

$month = static fn (string $ym): string => t('finance.month.' . substr($ym, 5, 2)) . ' ' . substr($ym, 2, 2);
$pipelineStages = ['to_answer', 'awaiting_client', 'awaiting_po', 'won', 'lost'];
$shipmentStages = ['planned', 'picked_up', 'in_transit', 'at_border', 'cleared', 'delivered'];
$data = [];
if (isset($charts['activity'])) {
    $data['activity'] = [
        'labels' => array_map(static fn (array $m): string => $month($m['month']), $charts['activity']),
        'months' => array_column($charts['activity'], 'month'),
        'keys' => ['rfqs', 'quotes', 'orders'],
        'series' => [
            ['name' => t('dashboard.series.rfqs'), 'values' => array_column($charts['activity'], 'rfqs')],
            ['name' => t('dashboard.series.quotes'), 'values' => array_column($charts['activity'], 'quotes')],
            ['name' => t('dashboard.series.orders'), 'values' => array_column($charts['activity'], 'orders')],
        ],
    ];
}
if (isset($charts['pipeline'])) {
    $data['pipeline'] = [
        'labels' => array_map(static fn (string $s): string => t('tracking.stage.' . $s), $pipelineStages),
        'keys' => $pipelineStages,
        'values' => array_map(static fn (string $s): int => $charts['pipeline'][$s]['n'], $pipelineStages),
        'amounts' => array_map(static fn (string $s): int => $charts['pipeline'][$s]['amount'], $pipelineStages),
        'name' => t('dashboard.series.rfqs'),
    ];
}
if (isset($charts['shipments'])) {
    $data['shipments'] = [
        'labels' => array_map(static fn (string $s): string => t('shipment_status.' . $s), $shipmentStages),
        'keys' => $shipmentStages,
        'values' => array_map(static fn (string $s): int => $charts['shipments'][$s], $shipmentStages),
        'name' => t('dashboard.series.shipments'),
    ];
}
if (isset($charts['aging'])) {
    $data['aging'] = [
        'labels' => array_map(static fn (string $b): string => t('aging.' . $b), Receivables::BUCKETS),
        'keys' => Receivables::BUCKETS,
        'values' => array_values($charts['aging']),
        'name' => t('dashboard.series.receivables'),
        'money' => true,
    ];
}
// Graphique sans aucune valeur : message plutôt qu'un axe vide.
$empty = static fn (string $id): bool => !isset($data[$id]) || array_sum($id === 'activity' ? array_merge(...array_column($data[$id]['series'], 'values')) : $data[$id]['values']) === 0;
$groups = ['sales' => ['dashboard.group.sales', 'handshake'], 'ops' => ['dashboard.group.ops', 'truck'], 'finance' => ['dashboard.group.finance', 'landmark']];
$chartTable = static function (array $labels, array $columns): string {
    $html = '<details class="chart-table"><summary>' . e(t('finance.show_table')) . '</summary><div class="table-scroll"><table class="table" data-no-dt><thead><tr><th scope="col"></th>';
    foreach (array_keys($columns) as $name) {
        $html .= '<th scope="col" class="num">' . e($name) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($labels as $i => $label) {
        $html .= '<tr><td>' . e($label) . '</td>';
        foreach ($columns as $values) {
            $html .= '<td class="num">' . e($values[$i]) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div></details>';
};
?>
<?= partial('partials/page-header', [
    'icon' => 'layout-dashboard',
    'title' => t('dashboard.title'),
    'meta' => e(t('dashboard.subtitle', ['date' => date('d/m/Y')])),
    'actions' => can('rfqs.manage') ? '<a class="btn btn--primary" href="' . e(url('/rfqs/new')) . '">' . icon('plus') . '<span>' . e(t('rfqs.new')) . '</span></a>' : '',
]) ?>

<div class="dash-kpis">
  <?php foreach ($groups as $group => [$label, $groupIcon]): if (empty($kpis[$group])) { continue; } ?>
    <section class="dash-kpis__group" aria-label="<?= e(t($label)) ?>">
      <h2 class="dash-kpis__title"><?= icon($groupIcon) ?><?= e(t($label)) ?></h2>
      <div class="dash-kpis__tiles">
        <?php foreach ($kpis[$group] as $k): ?>
          <button type="button" class="dkpi<?= $k['tone'] !== '' ? ' dkpi--' . e($k['tone']) : '' ?>" data-panel="<?= e($k['panel']) ?>" aria-haspopup="dialog">
            <span class="dkpi__label"><?= icon($k['icon']) ?><?= e($k['label']) ?></span>
            <span class="dkpi__value"><?= e($k['value']) ?></span>
            <span class="dkpi__hint"><?= e($k['hint']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<div class="dash-grid">
  <div class="dash-grid__main">
    <?php if (isset($data['activity'])): ?>
    <section class="card">
      <div class="card__title"><h2><?= icon('chart-column', 'title-icon') ?><?= e(t('dashboard.chart.activity')) ?></h2></div>
      <?php if ($empty('activity')): ?><div class="empty-state empty-state--ok"><?= icon('circle-check') ?><p><?= e(t('dashboard.panel_empty')) ?></p></div><?php else: ?>
      <div class="chart-box chart-box--sm"><canvas id="chart-activity" role="img" aria-label="<?= e(t('dashboard.chart.activity')) ?>"></canvas></div>
      <?= $chartTable($data['activity']['labels'], array_combine(array_column($data['activity']['series'], 'name'), array_column($data['activity']['series'], 'values'))) ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <div class="grid grid--two section-gap">
      <?php if (isset($data['pipeline'])): ?>
      <section class="card">
        <div class="card__title"><h2><?= icon('route', 'title-icon') ?><?= e(t('dashboard.chart.pipeline')) ?></h2><a class="link-btn" href="<?= e(url('/tracking')) ?>"><?= e(t('common.see_all')) ?></a></div>
        <?php if ($empty('pipeline')): ?><div class="empty-state empty-state--ok"><?= icon('circle-check') ?><p><?= e(t('dashboard.panel_empty')) ?></p></div><?php else: ?>
      <div class="chart-box chart-box--sm"><canvas id="chart-pipeline" role="img" aria-label="<?= e(t('dashboard.chart.pipeline')) ?>"></canvas></div>
        <?= $chartTable($data['pipeline']['labels'], [t('dashboard.series.rfqs') => $data['pipeline']['values'], t('tracking.col.amount') => array_map(static fn (int $a): string => format_money($a, 'USD'), $data['pipeline']['amounts'])]) ?>
      <?php endif; ?>
      </section>
      <?php endif; ?>
      <?php if (isset($data['shipments'])): ?>
      <section class="card">
        <div class="card__title"><h2><?= icon('truck', 'title-icon') ?><?= e(t('dashboard.chart.shipments')) ?></h2><a class="link-btn" href="<?= e(url('/shipments')) ?>"><?= e(t('common.see_all')) ?></a></div>
        <?php if ($empty('shipments')): ?><div class="empty-state empty-state--ok"><?= icon('circle-check') ?><p><?= e(t('dashboard.panel_empty')) ?></p></div><?php else: ?>
      <div class="chart-box chart-box--sm"><canvas id="chart-shipments" role="img" aria-label="<?= e(t('dashboard.chart.shipments')) ?>"></canvas></div>
        <?= $chartTable($data['shipments']['labels'], [t('dashboard.series.shipments') => $data['shipments']['values']]) ?>
      <?php endif; ?>
      </section>
      <?php endif; ?>
    </div>
  </div>

  <aside class="dash-grid__side">
    <section class="card">
      <div class="card__title"><h2><?= icon('bell-ring', 'title-icon') ?><?= e(t('alerts.title')) ?></h2><?php if ($alerts !== []): ?><span class="chip"><?= count($alerts) ?></span><?php endif; ?></div>
      <?php if ($alerts === []): ?>
        <div class="empty-state empty-state--ok"><?= icon('circle-check') ?><p><?= e(t('alerts.none')) ?></p></div>
      <?php else: ?>
        <ul class="dnotif-list">
          <?php foreach ($alerts as $a): ?>
            <li class="dnotif dnotif--<?= e($a['level']) ?>">
              <?php if ($a['panel'] !== null): ?>
                <button type="button" data-panel="<?= e($a['panel']) ?>" aria-haspopup="dialog"><?= icon($a['icon']) ?><span class="dnotif__text"><?= e($a['label']) ?></span><?= icon('chevron-right', 'dnotif__go') ?></button>
              <?php else: ?>
                <a href="<?= e($a['url']) ?>"><?= icon($a['icon']) ?><span class="dnotif__text"><?= e($a['label']) ?></span><?= icon('chevron-right', 'dnotif__go') ?></a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php if (isset($data['aging'])): ?>
    <section class="card section-gap">
      <div class="card__title"><h2><?= icon('hourglass', 'title-icon') ?><?= e(t('dashboard.chart.aging')) ?></h2><a class="link-btn" href="<?= e(url('/statements')) ?>"><?= e(t('common.see_all')) ?></a></div>
      <?php if ($empty('aging')): ?><div class="empty-state empty-state--ok"><?= icon('circle-check') ?><p><?= e(t('dashboard.panel_empty')) ?></p></div><?php else: ?>
      <div class="chart-box chart-box--sm"><canvas id="chart-aging" role="img" aria-label="<?= e(t('dashboard.chart.aging')) ?>"></canvas></div>
      <?= $chartTable($data['aging']['labels'], [t('dashboard.series.receivables') => array_map(static fn (int $v): string => format_money($v, 'USD'), $data['aging']['values'])]) ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </aside>
</div>

<dialog class="panel-modal" id="panel-modal" aria-labelledby="panel-modal-title">
  <div class="panel-modal__head">
    <h2 id="panel-modal-title" class="panel-modal__title"></h2>
    <span class="chip panel-modal__count" hidden></span>
    <a class="btn btn--ghost btn--sm panel-modal__page" href="#"><?= icon('external-link') ?><span><?= e(t('dashboard.open_page')) ?></span></a>
    <button type="button" class="icon-btn panel-modal__close" data-panel-close aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <div class="panel-modal__body" aria-live="polite"></div>
</dialog>

<?= json_script('dashboard-data', $data) ?>
<p class="hint dash-hint"><?= icon('mouse-pointer-click') ?><?= e(t('dashboard.click_hint')) ?></p>
<?php if ($data !== []): ?><script src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"></script><?php endif; ?>
