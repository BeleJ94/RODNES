<?php

use App\Domain\Orders\ClientOrderStatus;

/** @var list<array<string, mixed>> $rows */
?>
<table class="table"<?= !empty($compact) ? ' data-dt="compact"' : '' ?>>
  <thead><tr>
    <th scope="col"><?= e(t('client_orders.col.reference')) ?></th>
    <th scope="col" data-x="text"><?= e(t('detail.client_po')) ?></th>
    <th scope="col" data-filter="select"><?= e(t('rfqs.col.client')) ?></th>
    <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('quotations.col.amount_usd')) ?>" data-x="money"><?= e(t('client_orders.col.amount')) ?></th>
    <th scope="col" data-filter="range" data-filter-label="<?= e(t('client_orders.col.coverage_pct')) ?>" data-x="percent" data-priority="low"><?= e(t('client_orders.col.coverage')) ?></th>
    <th scope="col" data-filter="date"><?= e(t('client_orders.col.received')) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $row):
      $status = ClientOrderStatus::from($row['status']);
      $ratio = (int) $row['ordered_milli'] > 0 ? intdiv((int) $row['covered_milli'] * 100, (int) $row['ordered_milli']) : 0; ?>
    <tr class="row-link" tabindex="0" data-href="<?= e(url('/client-orders/' . $row['id'])) ?>">
      <td><strong><?= e($row['reference']) ?></strong></td>
      <td><?= e($row['client_po_number']) ?></td>
      <td><span class="clip"><?= e($row['client_name']) ?></span></td>
      <td data-filter="<?= e(t($status->labelKey())) ?>"><?= badge($status->tone(), t($status->labelKey())) ?></td>
      <td class="num" data-order="<?= (int) $row['subtotal'] ?>"><?= e(format_money((int) $row['subtotal'], 'USD')) ?></td>
      <td data-order="<?= $ratio ?>" data-export="<?= $ratio * 100 ?>"><span class="meter" role="img" aria-label="<?= e(t('client_orders.coverage_aria', ['n' => $ratio])) ?>"><span class="meter__bar<?= $ratio >= 100 ? ' meter__bar--ok' : '' ?>" style="width: <?= min(100, $ratio) ?>%"></span></span> <span class="muted"><?= $ratio ?> %</span></td>
      <td data-order="<?= e($row['received_on']) ?>"><?= e(format_date($row['received_on'])) ?></td>
      <?= row_detail([
          t('detail.quotation') => $row['quotation_reference'] ? '<a href="' . e(url('/quotations/' . $row['quotation_id'])) . '">' . e($row['quotation_reference'] . ' v' . $row['quotation_version']) . '</a>' : null,
          t('detail.rfq') => $row['rfq_reference'] ? '<a href="' . e(url('/rfqs/' . $row['rfq_id'])) . '">' . e($row['rfq_reference']) . '</a>' : null,
          t('detail.required_by') => e(format_date($row['required_by'])),
          t('detail.site') => e((string) $row['delivery_site']),
          t('detail.terms') => e(trim(($row['incoterm'] ?? '') . ' · ' . t('common.days', ['n' => $row['payment_terms_days']]), ' ·')),
          t('detail.coverage') => e(t('detail.coverage_value', ['n' => $ratio])),
          t('detail.closed_reason') => e((string) $row['closed_reason']),
      ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
