<?php

/** @var list<array<string, mixed>> $rows @var string $viewFilter */

$actions = can('payments.manage') ? '<a class="btn btn--primary" href="' . e(url('/payments/new')) . '">' . icon('plus') . '<span>' . e(t('payments.new')) . '</span></a>' : '';
?>
<?= partial('partials/page-header', ['icon' => 'wallet', 'title' => t('payments.title'), 'meta' => e(t('payments.meta')), 'actions' => export_button() . $actions]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/payments')) ?>" data-autosubmit>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('filters.view')) ?></span>
        <select name="view">
          <option value=""><?= e(t('filters.all')) ?></option>
          <option value="unallocated"<?= $viewFilter === 'unallocated' ? ' selected' : '' ?>><?= e(t('payments.filter_unallocated')) ?></option>
        </select>
      </label>
    </div>
  </form>
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('wallet') ?><p><?= e(t('payments.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('payments.col.reference')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('rfqs.col.client')) ?></th>
      <th scope="col" data-filter="date"><?= e(t('payments.col.received_on')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('payments.col.method')) ?></th>
      <th scope="col" data-priority="low"><?= e(t('payments.col.invoices')) ?></th>
      <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('payments.col.amount_usd')) ?>" data-x="money"><?= e(t('payments.col.amount')) ?></th>
      <th scope="col" class="num" data-x="money"><?= e(t('payments.col.unallocated')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $p):
        $rest = (int) $p['amount_minor'] - (int) $p['allocated_minor']; ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/payments/' . $p['id'])) ?>">
        <td><strong><?= e($p['reference']) ?></strong></td>
        <td><span class="clip"><?= e($p['client_name']) ?></span></td>
        <td data-order="<?= e($p['received_on']) ?>"><?= e(format_date($p['received_on'])) ?></td>
        <td><?= e(t('payment_method.' . $p['method'])) ?></td>
        <td><span class="clip"><?= $p['invoice_references'] ? e($p['invoice_references']) : '<span class="muted">—</span>' ?></span></td>
        <td class="num" data-order="<?= (int) $p['amount_minor'] ?>"><?= e(format_money((int) $p['amount_minor'], 'USD')) ?></td>
        <td class="num<?= $rest > 0 ? ' warn-text' : '' ?>" data-order="<?= $rest ?>"><?= $rest > 0 ? e(format_money($rest, 'USD')) : '<span class="muted">—</span>' ?></td>
        <?= row_detail([
            t('detail.bank_reference') => e((string) $p['bank_reference']),
            t('detail.allocated') => e(format_money((int) $p['allocated_minor'], 'USD')),
            t('detail.invoices') => e((string) $p['invoice_references']),
            t('detail.created_by') => e((string) $p['created_by_name']),
        ], $p['notes'] ? '<p class="row-detail__note">' . e($p['notes']) . '</p>' : '') ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
