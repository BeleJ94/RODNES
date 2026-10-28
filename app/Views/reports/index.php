<?php

/**
 * @var list<array<string, mixed>> $rows
 * @var string|null $from @var string|null $to
 * @var array<string, mixed> $totals
 */
?>
<?= partial('partials/page-header', ['icon' => 'chart-column', 'title' => t('reports.title'), 'meta' => e(t('reports.meta')), 'actions' => export_button()]) ?>
<section class="kpis kpis--compact">
  <div class="kpi"><span class="kpi__label"><?= e(t('reports.kpi.revenue')) ?></span><span class="kpi__value"><?= e(format_money($totals['revenue'], 'USD')) ?></span><span class="kpi__hint"><?= e(t('reports.kpi.revenue_hint', ['n' => count($rows)])) ?></span></div>
  <div class="kpi"><span class="kpi__label"><?= e(t('reports.kpi.cost')) ?></span><span class="kpi__value"><?= e(format_money($totals['cost'], 'USD')) ?></span><span class="kpi__hint"><?= e(t('reports.kpi.cost_hint', ['n' => $totals['provisional'], 'landed' => format_money($totals['landed'], 'USD')])) ?></span></div>
  <div class="kpi<?= $totals['margin'] < 0 ? ' kpi--alert' : '' ?>"><span class="kpi__label"><?= e(t('reports.kpi.margin')) ?></span><span class="kpi__value"><?= e(format_money($totals['margin'], 'USD')) ?></span><span class="kpi__hint"><?= $totals['margin_bp'] !== null ? e(format_percent((int) $totals['margin_bp'])) : '—' ?></span></div>
  <div class="kpi<?= $totals['fx_effect'] > 0 ? ' kpi--warn' : '' ?>"><span class="kpi__label"><?= e(t('reports.kpi.fx')) ?></span><span class="kpi__value"><?= e(format_money(-$totals['fx_effect'], 'USD')) ?></span><span class="kpi__hint"><?= e(t('reports.kpi.fx_hint')) ?></span></div>
</section>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/reports')) ?>">
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('statements.from')) ?></span><input type="date" name="from" value="<?= e($from ?? '') ?>"></label>
      <label class="filter"><span><?= e(t('statements.to')) ?></span><input type="date" name="to" value="<?= e($to ?? '') ?>"></label>
      <button type="submit" class="btn"><?= icon('filter') ?><span><?= e(t('statements.apply')) ?></span></button>
    </div>
  </form>
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('chart-column') ?><p><?= e(t('reports.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('nav.client_orders')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('rfqs.col.client')) ?></th>
      <th scope="col" data-filter="date" data-priority="low"><?= e(t('reports.col.last_invoice')) ?></th>
      <th scope="col" class="num" data-x="money"><?= e(t('reports.col.revenue')) ?></th>
      <th scope="col" class="num" data-x="money"><?= e(t('reports.col.cost')) ?></th>
      <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('reports.col.margin_usd')) ?>" data-x="money"><?= e(t('reports.col.margin')) ?></th>
      <th scope="col" class="num" data-x="percent"><?= e(t('reports.col.margin_pct')) ?></th>
      <th scope="col" class="num" data-x="money" data-priority="low" data-x-label="<?= e(t('detail.fx_effect')) ?>"><?= e(t('reports.col.fx')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $gap = isset($r['est_margin_bp'], $r['margin_bp']) ? (int) $r['margin_bp'] - (int) $r['est_margin_bp'] : null; ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/client-orders/' . $r['id'] . '#margin')) ?>">
        <td><strong><?= e($r['reference']) ?></strong><?php if ($r['provisional']): ?> <span class="chip chip--warn"><?= e(t('reports.provisional')) ?></span><?php endif; ?></td>
        <td><span class="clip"><?= e($r['client_name']) ?></span></td>
        <td data-order="<?= e($r['last_invoice']) ?>"><?= e(format_date($r['last_invoice'])) ?></td>
        <td class="num" data-order="<?= (int) $r['revenue'] ?>"><?= e(format_money((int) $r['revenue'], 'USD')) ?></td>
        <td class="num" data-order="<?= (int) $r['cost'] ?>"><?= e(format_money((int) $r['cost'], 'USD')) ?></td>
        <td class="num<?= (int) $r['margin'] < 0 ? ' overdue' : '' ?>" data-order="<?= (int) $r['margin'] ?>"><strong><?= e(format_money((int) $r['margin'], 'USD')) ?></strong></td>
        <td class="num<?= $gap !== null && $gap < 0 ? ' warn-text' : '' ?>" data-order="<?= $r['margin_bp'] ?? '' ?>"><?= $r['margin_bp'] !== null ? e(format_percent((int) $r['margin_bp'])) : '—' ?></td>
        <td class="num<?= (int) $r['fx_effect'] > 0 ? ' overdue' : '' ?>" data-order="<?= -(int) $r['fx_effect'] ?>"><?= (int) $r['fx_effect'] === 0 ? '<span class="muted">—</span>' : e(format_money(-(int) $r['fx_effect'], 'USD')) ?></td>
        <?= row_detail([
            t('detail.client_po') => e((string) $r['client_po_number']),
            t('detail.invoicing_period') => e(format_date($r['first_invoice']) . ' → ' . format_date($r['last_invoice'])),
            t('detail.landed') => (int) ($r['landed'] ?? 0) > 0 ? e(format_money((int) $r['landed'], 'USD')) : null,
            t('detail.planned_margin') => isset($r['est_margin_bp']) ? e(format_percent((int) $r['est_margin_bp'])) : null,
            t('detail.fx_effect') => (int) $r['fx_effect'] === 0 ? null : e(t((int) $r['fx_effect'] > 0 ? 'reports.fx_loss' : 'reports.fx_gain', ['amount' => format_money(abs((int) $r['fx_effect']), 'USD')])),
            t('detail.cost_status') => $r['provisional'] ? e(t('reports.provisional')) : null,
        ]) ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint"><?= e(t('reports.hint')) ?></p>
  <?php endif; ?>
</section>
