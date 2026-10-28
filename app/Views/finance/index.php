<?php

/**
 * @var string $cashInput trésorerie disponible saisie
 * @var array<string, mixed> $forecast semaines (entrées, sorties, net, cumul), au-delà, en attente
 * @var array{months: list<array{month: string, revenue: int, margin: int}>, clients: list<array<string, mixed>>} $monthly
 * @var list<array<string, mixed>> $suppliers
 * @var array<string, mixed> $kpi
 */

$weekLabel = static function (array $w): string {
    if ($w['key'] === 'overdue') {
        return t('finance.week_overdue');
    }
    return t('finance.week_label', ['n' => date('W', strtotime($w['start'])), 'date' => date('d/m', strtotime($w['start']))]);
};
$monthLabel = static fn (string $ym): string => t('finance.month.' . substr($ym, 5, 2)) . ' ' . substr($ym, 2, 2);
$weeks = $forecast['weeks'];
$end = end($weeks);
$minCumul = min(array_column($weeks, 'cumulative'));
$chartData = [
    'cash' => [
        'labels' => array_map($weekLabel, $weeks),
        'in' => array_map(static fn (array $w): int => $w['in'], $weeks),
        'out' => array_map(static fn (array $w): int => -$w['out'], $weeks),
        'cumulative' => array_column($weeks, 'cumulative'),
        'names' => [t('finance.series.in'), t('finance.series.out'), t('finance.series.cumulative')],
    ],
    'months' => [
        'labels' => array_map(static fn (array $m): string => $monthLabel($m['month']), $monthly['months']),
        'revenue' => array_column($monthly['months'], 'revenue'),
        'margin' => array_column($monthly['months'], 'margin'),
        'names' => [t('finance.series.revenue'), t('finance.series.margin')],
    ],
];
$revenue12 = array_sum(array_column($monthly['months'], 'revenue'));
$margin12 = array_sum(array_column($monthly['months'], 'margin'));
?>
<?= partial('partials/page-header', ['icon' => 'landmark', 'title' => t('finance.title'), 'meta' => e(t('finance.meta')), 'actions' => export_button()]) ?>

<section class="kpis kpis--compact">
  <a class="kpi kpi--button" href="<?= e(url('/invoices', ['status' => 'open'])) ?>"><span class="kpi__label"><?= e(t('finance.kpi.receivables')) ?></span><span class="kpi__value"><?= e(format_money((int) $kpi['receivables'], 'USD')) ?></span><span class="kpi__hint"><?= e($kpi['dso'] !== null ? t('finance.kpi.dso', ['n' => $kpi['dso']]) : t('finance.kpi.no_sales')) ?></span></a>
  <a class="kpi kpi--button" href="<?= e(url('/supplier-statements')) ?>"><span class="kpi__label"><?= e(t('finance.kpi.payables')) ?></span><span class="kpi__value"><?= e(format_money((int) $kpi['payables'], 'USD')) ?></span><span class="kpi__hint"><?= e($kpi['dpo'] !== null ? t('finance.kpi.dpo', ['n' => $kpi['dpo']]) : t('finance.kpi.no_purchases')) ?></span></a>
  <div class="kpi<?= $minCumul < 0 ? ' kpi--alert' : '' ?>"><span class="kpi__label"><?= e(t('finance.kpi.cash_end', ['n' => count($weeks) - 1])) ?></span><span class="kpi__value"><?= e(format_money((int) $end['cumulative'], 'USD')) ?></span><span class="kpi__hint"><?= e($minCumul < 0 ? t('finance.kpi.cash_low', ['amount' => format_money($minCumul, 'USD')]) : t('finance.kpi.cash_ok')) ?></span></div>
  <a class="kpi kpi--button" href="<?= e(url('/quotations')) ?>"><span class="kpi__label"><?= e(t('finance.kpi.hit_rate')) ?></span><span class="kpi__value"><?= $kpi['hit_rate_bp'] !== null ? e(format_percent((int) $kpi['hit_rate_bp'])) : '—' ?></span><span class="kpi__hint"><?= e(t('finance.kpi.hit_rate_hint', ['won' => $kpi['quotes_won'], 'n' => $kpi['quotes_decided']])) ?></span></a>
</section>

<section class="card">
  <div class="card__title">
    <h2><?= icon('waves', 'title-icon') ?><?= e(t('finance.cash_title', ['n' => count($weeks) - 1])) ?></h2>
    <form class="inline-form" method="get" action="<?= e(url('/finance')) ?>">
      <label for="cash-input" class="muted"><?= e(t('finance.cash_on_hand')) ?></label>
      <input id="cash-input" name="cash" value="<?= e($cashInput) ?>" inputmode="decimal" class="num input--sm" placeholder="0,00">
      <button type="submit" class="btn btn--sm"><?= icon('refresh-cw') ?><span><?= e(t('finance.recompute')) ?></span></button>
    </form>
  </div>
  <div class="chart-box"><canvas id="chart-cash" role="img" aria-label="<?= e(t('finance.cash_aria')) ?>"></canvas></div>
  <details class="chart-table">
    <summary><?= e(t('finance.show_table')) ?></summary>
    <div class="table-scroll">
      <table class="table" data-no-dt>
        <thead><tr>
          <th scope="col"><?= e(t('finance.col.week')) ?></th>
          <th scope="col" class="num"><?= e(t('finance.series.in')) ?></th>
          <th scope="col" class="num"><?= e(t('finance.series.out')) ?></th>
          <th scope="col" class="num"><?= e(t('finance.col.net')) ?></th>
          <th scope="col" class="num"><?= e(t('finance.series.cumulative')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($weeks as $w): ?>
          <tr>
            <td class="nowrap"><?= e($weekLabel($w)) ?><?php if ($w['start']): ?> <span class="muted">→ <?= e(format_date($w['end'])) ?></span><?php endif; ?></td>
            <td class="num nowrap"><?= e(format_money($w['in'], 'USD')) ?></td>
            <td class="num nowrap"><?= e(format_money($w['out'], 'USD')) ?></td>
            <td class="num nowrap<?= $w['net'] < 0 ? ' overdue' : '' ?>"><?= e(format_money($w['net'], 'USD')) ?></td>
            <td class="num nowrap<?= $w['cumulative'] < 0 ? ' overdue' : '' ?>"><strong><?= e(format_money($w['cumulative'], 'USD')) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <p class="hint"><?= e(t('finance.cash_hint', [
      'opening' => format_money((int) $forecast['opening'], 'USD'),
      'later_in' => format_money((int) $forecast['later']['in'], 'USD'),
      'later_out' => format_money((int) $forecast['later']['out'], 'USD'),
      'on_hold' => format_money((int) $forecast['on_hold'], 'USD'),
  ])) ?></p>
</section>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('chart-column', 'title-icon') ?><?= e(t('finance.months_title')) ?></h2>
    <span class="muted"><?= e(t('finance.months_total', ['revenue' => format_money($revenue12, 'USD'), 'margin' => format_money($margin12, 'USD')])) ?></span></div>
  <div class="chart-box"><canvas id="chart-months" role="img" aria-label="<?= e(t('finance.months_aria')) ?>"></canvas></div>
  <details class="chart-table">
    <summary><?= e(t('finance.show_table')) ?></summary>
    <div class="table-scroll">
      <table class="table" data-no-dt>
        <thead><tr>
          <th scope="col"><?= e(t('finance.col.month')) ?></th>
          <th scope="col" class="num"><?= e(t('finance.series.revenue')) ?></th>
          <th scope="col" class="num"><?= e(t('finance.series.margin')) ?></th>
          <th scope="col" class="num"><?= e(t('reports.col.margin_pct')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($monthly['months'] as $m):
            $bp = $m['revenue'] > 0 ? \App\Domain\Sales\Pricing::marginBp($m['revenue'] - $m['margin'], $m['revenue']) : null; ?>
          <tr>
            <td><?= e($monthLabel($m['month'])) ?></td>
            <td class="num nowrap"><?= e(format_money($m['revenue'], 'USD')) ?></td>
            <td class="num nowrap<?= $m['margin'] < 0 ? ' overdue' : '' ?>"><?= e(format_money($m['margin'], 'USD')) ?></td>
            <td class="num nowrap"><?= $bp !== null ? e(format_percent($bp)) : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <p class="hint"><?= e(t('finance.months_hint')) ?></p>
</section>

<div class="grid grid--two section-gap">
  <section class="card">
    <div class="card__title"><h2><?= icon('building-2', 'title-icon') ?><?= e(t('finance.top_clients')) ?></h2></div>
    <?php if ($monthly['clients'] === []): ?>
      <p class="muted"><?= e(t('finance.none')) ?></p>
    <?php else: ?>
    <table class="table" data-no-dt>
      <thead><tr>
        <th scope="col"><?= e(t('rfqs.col.client')) ?></th>
        <th scope="col" class="num"><?= e(t('finance.series.revenue')) ?></th>
        <th scope="col" class="num"><?= e(t('finance.series.margin')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($monthly['clients'] as $c): ?>
        <tr class="row-link" tabindex="0" data-href="<?= e(url('/clients/' . $c['id'])) ?>">
          <td><?= e($c['name']) ?></td>
          <td class="num nowrap"><?= e(format_money((int) $c['revenue'], 'USD')) ?></td>
          <td class="num nowrap"><?= e(format_money((int) $c['margin'], 'USD')) ?><?php if ($c['margin_bp'] !== null): ?><br><span class="muted"><?= e(format_percent((int) $c['margin_bp'])) ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <section class="card">
    <div class="card__title"><h2><?= icon('factory', 'title-icon') ?><?= e(t('finance.top_suppliers')) ?></h2></div>
    <?php if ($suppliers === []): ?>
      <p class="muted"><?= e(t('finance.none')) ?></p>
    <?php else: ?>
    <table class="table" data-no-dt>
      <thead><tr>
        <th scope="col"><?= e(t('sourcing.supplier')) ?></th>
        <th scope="col" class="num"><?= e(t('logistics.kind_goods')) ?></th>
        <th scope="col" class="num"><?= e(t('logistics.kind')) ?></th>
        <th scope="col" class="num"><?= e(t('reports.total')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($suppliers as $s): ?>
        <tr class="row-link" tabindex="0" data-href="<?= e(url('/supplier-statements/' . $s['id'])) ?>">
          <td><?= e($s['name']) ?></td>
          <td class="num nowrap"><?= e(format_money($s['goods'], 'USD')) ?></td>
          <td class="num nowrap"><?= $s['logistics'] > 0 ? e(format_money($s['logistics'], 'USD')) : '<span class="muted">—</span>' ?></td>
          <td class="num nowrap"><strong><?= e(format_money($s['total'], 'USD')) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <p class="hint"><?= e(t('finance.suppliers_hint')) ?></p>
  </section>
</div>
<?= json_script('finance-data', $chartData) ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"></script>
