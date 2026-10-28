<?php

use App\Domain\Invoicing\Receivables;

/** @var list<array<string, mixed>> $rows @var array<string, int> $totals */
?>
<?= partial('partials/page-header', [
    'icon' => 'scroll-text',
    'title' => t('statements.title'),
    'meta' => e(t('statements.meta', ['amount' => format_money(array_sum($totals), 'USD')])),
    'actions' => export_button(),
]) ?>
<section class="kpis kpis--compact">
  <?php foreach (Receivables::BUCKETS as $bucket): ?>
    <div class="kpi<?= $bucket !== 'not_due' && $totals[$bucket] > 0 ? ($bucket === 'd90_plus' || $bucket === 'd61_90' ? ' kpi--alert' : ' kpi--warn') : '' ?>">
      <span class="kpi__label"><?= e(t('aging.' . $bucket)) ?></span>
      <span class="kpi__value"><?= e(format_money($totals[$bucket], 'USD')) ?></span>
    </div>
  <?php endforeach; ?>
</section>
<section class="card">
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('scroll-text') ?><p><?= e(t('statements.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('rfqs.col.client')) ?></th>
      <th scope="col" data-filter="date" data-priority="low"><?= e(t('statements.col.last_payment')) ?></th>
      <?php foreach (Receivables::BUCKETS as $bucket): ?><th scope="col" class="num" data-x="money"<?= $bucket === 'not_due' ? '' : ' data-priority="low"' ?>><?= e(t('aging.' . $bucket)) ?></th><?php endforeach; ?>
      <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('statements.col.balance_usd')) ?>" data-x="money"><?= e(t('statements.col.balance')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/statements/' . $r['id'])) ?>">
        <td><strong><?= e($r['name']) ?></strong> <span class="sub"><?= e($r['code']) ?></span></td>
        <td data-order="<?= e($r['last_payment_on'] ?? '') ?>"><?= $r['last_payment_on'] ? e(format_date($r['last_payment_on'])) : '<span class="muted">—</span>' ?></td>
        <?php foreach (Receivables::BUCKETS as $bucket): $v = (int) $r['aging'][$bucket]; ?>
          <td class="num<?= $v > 0 && $bucket !== 'not_due' ? ' warn-text' : '' ?>" data-order="<?= $v ?>"><?= $v > 0 ? e(format_money($v, 'USD')) : '<span class="muted">—</span>' ?></td>
        <?php endforeach; ?>
        <td class="num" data-order="<?= (int) $r['balance'] ?>"><strong><?= e(format_money((int) $r['balance'], 'USD')) ?></strong></td>
        <?= row_detail([
            t('detail.invoiced') => e(format_money((int) $r['invoiced'], 'USD')),
            t('detail.received') => e(format_money((int) $r['received'], 'USD')),
            t('detail.credited') => (int) $r['credited'] > 0 ? e(format_money((int) $r['credited'], 'USD')) : null,
            t('detail.overdue') => (int) $r['overdue'] > 0 ? e(format_money((int) $r['overdue'], 'USD')) : null,
            t('detail.terms') => e(t('common.days', ['n' => $r['payment_terms_days']])),
            t('detail.last_payment') => e(format_date($r['last_payment_on'])),
        ]) ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
