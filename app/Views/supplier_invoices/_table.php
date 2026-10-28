<?php

use App\Domain\Purchasing\SupplierInvoiceStatus;

/** @var list<array<string, mixed>> $rows @var bool $compact @var bool $withSupplier */
$today = date('Y-m-d');
$withSupplier ??= true;
?>
<table class="table"<?= !empty($compact) ? ' data-dt="compact"' : '' ?>>
  <thead><tr>
    <th scope="col"><?= e(t('supplier_invoices.col.reference')) ?></th>
    <?php if ($withSupplier): ?><th scope="col" data-filter="select"><?= e(t('sourcing.supplier')) ?></th><?php endif; ?>
    <th scope="col" data-x="text" data-priority="low"><?= e(t('detail.their_number')) ?></th>
    <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    <th scope="col" data-filter="date"><?= e(t('supplier_invoices.col.invoice_date')) ?></th>
    <th scope="col" data-filter="date"><?= e(t('invoices.col.due_date')) ?></th>
    <th scope="col" data-filter="select" data-hidden data-x-always="1"><?= e(t('suppliers.col.currency')) ?></th>
    <th scope="col" class="num" data-x="money" data-x-total="0"><?= e(t('detail.amount_currency')) ?></th>
    <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('supplier_invoices.col.balance_usd')) ?>" data-x="money"><?= e(t('supplier_invoices.col.balance_usd')) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $row):
      $status = SupplierInvoiceStatus::from($row['status']);
      $overdue = $status->isOpen() && $row['due_date'] < $today;
      $variances = $row['variance_list'] !== [] && $status !== SupplierInvoiceStatus::Cancelled; ?>
    <tr class="row-link" tabindex="0" data-href="<?= e(url('/supplier-invoices/' . $row['id'])) ?>">
      <td><strong><?= e($row['reference']) ?></strong><?php if ($row['kind'] === 'logistics'): ?> <span class="chip"><?= e(t('logistics.kind')) ?></span><?php endif; ?></td>
      <?php if ($withSupplier): ?><td><span class="clip"><?= e($row['supplier_name']) ?></span></td><?php endif; ?>
      <td><?= e($row['supplier_invoice_number']) ?></td>
      <td data-filter="<?= e(t($status->labelKey())) ?>"><?= badge($status->tone(), t($status->labelKey())) ?><?php if ($variances): ?> <span class="chip chip--<?= $status === SupplierInvoiceStatus::OnHold ? 'bad' : 'warn' ?>"><?= e(t('supplier_invoices.variances_n', ['n' => count($row['variance_list'])])) ?></span><?php endif; ?><?php if ($overdue): ?> <span class="chip chip--bad"><?= e(t('invoices.overdue')) ?></span><?php endif; ?></td>
      <td data-order="<?= e($row['invoice_date']) ?>"><?= e(format_date($row['invoice_date'])) ?></td>
      <td class="<?= $overdue ? 'overdue' : '' ?>" data-order="<?= e($row['due_date']) ?>"><?= e(format_date($row['due_date'])) ?></td>
      <td><span class="tag"><?= e($row['currency_code']) ?></span></td>
      <td class="num" data-order="<?= (int) $row['total_minor'] ?>"><?= e(format_money((int) $row['total_minor'], $row['currency_code'])) ?></td>
      <td class="num<?= $row['balance'] > 0 ? ' warn-text' : '' ?>" data-order="<?= (int) $row['balance_usd'] ?>"<?= $status->isOpen() ? '' : ' data-export=""' ?>><?= $status->isOpen() ? e(format_money((int) $row['balance_usd'], 'USD')) : '<span class="muted">—</span>' ?></td>
      <?= row_detail([
          t('detail.kind') => e($row['kind'] === 'logistics' ? t('logistics.kind') : t('logistics.kind_goods')),
          t('detail.linked') => $row['kind'] === 'logistics' ? e((string) $row['shipment_references']) : ($row['supplier_order_id'] ? '<a href="' . e(url('/supplier-orders/' . $row['supplier_order_id'])) . '">' . e($row['order_reference']) . '</a>' . ($row['client_order_reference'] ? ' · ' . e($row['client_order_reference']) : '') : null),
          t('detail.subtotal') => e(format_money((int) $row['subtotal_minor'], $row['currency_code'])),
          t('detail.vat') => e(format_money((int) $row['vat_minor'], $row['currency_code'])),
          t('detail.paid') => (int) $row['paid'] > 0 ? e(format_money((int) $row['paid'], $row['currency_code'])) : null,
          t('detail.balance') => $status->isOpen() ? e(format_money((int) $row['balance'], $row['currency_code'])) : null,
          t('detail.rate') => $row['currency_code'] !== 'USD' ? e(format_rate((int) $row['fx_units_per_usd_micro']) . ' ' . $row['currency_code'] . ' / USD') : null,
          t('detail.total_usd') => e(format_money((int) $row['total_usd'], 'USD')),
          t('detail.variances') => $variances ? e(implode(' · ', array_map(static fn (array $v): string => '#' . $v['line_no'] . ' ' . t('supplier_invoices.variance.' . $v['type'], [
              'expected' => $v['type'] === 'price_above' ? format_money((int) $v['expected']) : format_qty((int) $v['expected']),
              'actual' => $v['type'] === 'price_above' ? format_money((int) $v['actual']) : format_qty((int) $v['actual']),
          ]), $row['variance_list']))) : null,
          t('detail.approved_by') => $row['approved_by_name'] ? e($row['approved_by_name'] . ' · ' . format_datetime($row['approved_at'])) : null,
          t('detail.cancelled_reason') => e((string) $row['cancelled_reason']),
      ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
