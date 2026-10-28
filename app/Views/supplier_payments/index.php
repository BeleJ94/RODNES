<?php

/**
 * @var list<array<string, mixed>> $rows
 * @var int|null $supplierId
 * @var list<array{id: int, label: string}> $suppliers
 */

$actions = can('supplier_payments.manage') ? '<a class="btn btn--primary" href="' . e(url('/supplier-payments/new')) . '">' . icon('plus') . '<span>' . e(t('supplier_payments.new')) . '</span></a>' : '';
?>
<?= partial('partials/page-header', ['icon' => 'send-to-back', 'title' => t('supplier_payments.title'), 'meta' => e(t('supplier_payments.meta')), 'actions' => export_button() . $actions]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/supplier-payments')) ?>" data-autosubmit>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('sourcing.supplier')) ?></span>
        <select name="supplier_id">
          <option value=""><?= e(t('filters.all')) ?></option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int) $s['id'] ?>"<?= $supplierId === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
  </form>
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('send-to-back') ?><p><?= e(t('supplier_payments.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('payments.col.reference')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('sourcing.supplier')) ?></th>
      <th scope="col" data-filter="date"><?= e(t('supplier_payments.col.paid_on')) ?></th>
      <th scope="col" data-filter="select" data-priority="low"><?= e(t('payments.col.method')) ?></th>
      <th scope="col" data-filter="select" data-hidden data-x-always="1"><?= e(t('suppliers.col.currency')) ?></th>
      <th scope="col" class="num" data-x="money" data-x-total="0"><?= e(t('detail.amount_currency')) ?></th>
      <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('supplier_payments.col.amount_usd')) ?>" data-x="money"><?= e(t('supplier_payments.col.amount_usd')) ?></th>
      <th scope="col" class="num" data-x="money" data-x-total="0"><?= e(t('supplier_payments.col.advance')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/supplier-payments/' . $p['id'])) ?>">
        <td><strong><?= e($p['reference']) ?></strong></td>
        <td><span class="clip"><?= e($p['supplier_name']) ?></span></td>
        <td data-order="<?= e($p['paid_on']) ?>"><?= e(format_date($p['paid_on'])) ?></td>
        <td><?= e(t('payment_method.' . $p['method'])) ?></td>
        <td><span class="tag"><?= e($p['currency_code']) ?></span></td>
        <td class="num" data-order="<?= (int) $p['amount_minor'] ?>"><?= e(format_money((int) $p['amount_minor'], $p['currency_code'])) ?></td>
        <td class="num" data-order="<?= (int) $p['amount_usd'] ?>"><?= e(format_money((int) $p['amount_usd'], 'USD')) ?></td>
        <td class="num<?= $p['unallocated'] > 0 ? ' warn-text' : '' ?>" data-order="<?= (int) $p['unallocated'] ?>"><?= $p['unallocated'] > 0 ? e(format_money((int) $p['unallocated'], $p['currency_code'])) : '<span class="muted">—</span>' ?></td>
        <?= row_detail([
            t('detail.bank_reference') => e((string) $p['bank_reference']),
            t('detail.rate') => $p['currency_code'] !== 'USD' ? e(format_rate((int) $p['fx_units_per_usd_micro']) . ' ' . $p['currency_code'] . ' / USD') : null,
            t('detail.allocated') => e(format_money((int) $p['allocated_minor'], $p['currency_code'])),
            t('detail.invoices') => e((string) $p['invoice_references']),
        ], $p['notes'] ? '<p class="row-detail__note">' . e($p['notes']) . '</p>' : '') ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
