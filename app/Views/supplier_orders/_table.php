<?php

use App\Domain\Orders\SupplierOrderStatus;
use App\Domain\Shared\Fx;

/** @var list<array<string, mixed>> $rows @var bool $withClient */
$today = date('Y-m-d');
?>
<table class="table"<?= !empty($compact) ? ' data-dt="compact"' : '' ?>>
  <thead><tr>
    <th scope="col"><?= e(t('supplier_orders.col.reference')) ?></th>
    <th scope="col" data-filter="select"><?= e(t('sourcing.supplier')) ?></th>
    <?php if ($withClient ?? true): ?><th scope="col" data-filter="select" data-filter-label="<?= e(t('rfqs.col.client')) ?>" data-priority="low"><?= e(t('supplier_orders.col.for')) ?></th><?php endif; ?>
    <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    <th scope="col" data-filter="select" data-hidden data-x-always="1"><?= e(t('suppliers.col.currency')) ?></th>
    <th scope="col" class="num" data-x="money" data-x-total="0" data-x-label="<?= e(t('detail.amount_currency')) ?>"><?= e(t('detail.amount_currency')) ?></th>
    <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('supplier_orders.col.amount_usd')) ?>" data-x="money"><?= e(t('supplier_orders.col.amount_usd')) ?></th>
    <th scope="col" data-filter="date"><?= e(t('supplier_orders.col.ready')) ?></th>
    <th scope="col" class="no-sort"><span class="visually-hidden"><?= e(t('common.actions')) ?></span></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $row):
      $status = SupplierOrderStatus::from($row['status']);
      $usd = Fx::toUsd((int) $row['total'], (int) $row['fx_units_per_usd_micro']);
      $late = $row['expected_ready_on'] !== null && $row['expected_ready_on'] < $today && in_array($status, [SupplierOrderStatus::Sent, SupplierOrderStatus::Confirmed], true); ?>
    <tr class="row-link" tabindex="0" data-href="<?= e(url('/supplier-orders/' . $row['id'])) ?>">
      <td><strong><?= e($row['reference']) ?></strong></td>
      <td><span class="clip"><?= e($row['supplier_name']) ?></span></td>
      <?php if ($withClient ?? true): ?><td data-filter="<?= e($row['client_name']) ?>"><span class="clip"><?= e($row['client_name']) ?></span></td><?php endif; ?>
      <td data-filter="<?= e(t($status->labelKey())) ?>"><?= badge($status->tone(), t($status->labelKey())) ?></td>
      <td><span class="tag"><?= e($row['currency_code']) ?></span></td>
      <td class="num" data-order="<?= (int) $row['total'] ?>"><?= e(format_money((int) $row['total'], $row['currency_code'])) ?></td>
      <td class="num" data-order="<?= $usd ?>"><?= e(format_money($usd, 'USD')) ?></td>
      <td class="<?= $late ? 'overdue' : '' ?>" data-order="<?= e($row['expected_ready_on'] ?? '') ?>"><?= e(format_date($row['expected_ready_on'])) ?: '<span class="muted">—</span>' ?></td>
      <td class="row-actions"><?= partial('partials/preview-button', [
          'href' => url('/supplier-orders/' . $row['id'] . '/print'),
          'kind' => 'document',
          'title' => t('preview.title_purchase_order', ['ref' => $row['reference']]),
          'meta' => t('preview.meta_supplier_document', ['supplier' => $row['supplier_name']]),
      ]) ?></td>
      <?= row_detail([
          t('detail.client_order') => '<a href="' . e(url('/client-orders/' . $row['client_order_id'])) . '">' . e($row['client_order_reference']) . '</a> · ' . e(t('client_orders.their_po', ['ref' => $row['client_po_number']])),
          t('detail.client') => e($row['client_name']),
          t('detail.supplier_reference') => e((string) $row['supplier_reference']),
          t('detail.supplier_contact') => e(trim($row['supplier_contact'] . ' ' . ($row['supplier_email'] ? '<' . $row['supplier_email'] . '>' : ''))),
          t('detail.rate') => $row['currency_code'] !== 'USD' ? e(format_rate((int) $row['fx_units_per_usd_micro']) . ' ' . $row['currency_code'] . ' / USD') : null,
          t('detail.terms') => e(trim(($row['incoterm'] ?? '') . ' · ' . t('common.days', ['n' => $row['payment_terms_days']]), ' ·')),
          t('detail.issued_on') => e(format_date($row['issued_on'])),
          t('detail.sent_at') => e(format_datetime($row['sent_at'])),
          t('detail.confirmed_at') => e(format_datetime($row['confirmed_at'])),
          t('detail.delivery_address') => e((string) $row['delivery_address']),
      ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
