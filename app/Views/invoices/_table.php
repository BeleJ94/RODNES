<?php

use App\Domain\Invoicing\InvoiceStatus;

/** @var list<array<string, mixed>> $rows @var bool $compact @var bool $withClient */
$today = date('Y-m-d');
$withClient ??= true;
?>
<table class="table"<?= !empty($compact) ? ' data-dt="compact"' : '' ?>>
  <thead><tr>
    <th scope="col"><?= e(t('invoices.col.reference')) ?></th>
    <?php if ($withClient): ?><th scope="col" data-filter="select"><?= e(t('rfqs.col.client')) ?></th><?php endif; ?>
    <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    <th scope="col" data-filter="date"><?= e(t('invoices.col.issue_date')) ?></th>
    <th scope="col" data-filter="date"><?= e(t('invoices.col.due_date')) ?></th>
    <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('invoices.col.total_usd')) ?>" data-x="money"><?= e(t('invoices.col.total')) ?></th>
    <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('invoices.col.balance_usd')) ?>" data-x="money"><?= e(t('invoices.col.balance')) ?></th>
    <th scope="col" class="no-sort"><span class="visually-hidden"><?= e(t('common.actions')) ?></span></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $row):
      $status = InvoiceStatus::from($row['status']);
      $overdue = $status->isOpen() && $row['due_date'] !== null && $row['due_date'] < $today;
      $title = $row['reference'] ?? t('invoices.draft_title');
      $showBalance = $status->isOpen() || $status === InvoiceStatus::Paid; ?>
    <tr class="row-link" tabindex="0" data-href="<?= e(url('/invoices/' . $row['id'])) ?>">
      <td><strong><?= $row['reference'] !== null ? e($row['reference']) : '<span class="muted">' . e(t('invoices.draft_title')) . '</span>' ?></strong></td>
      <?php if ($withClient): ?><td><span class="clip"><?= e($row['client_name']) ?></span></td><?php endif; ?>
      <td data-filter="<?= e(t($status->labelKey())) ?>"><?= badge($status->tone(), t($status->labelKey())) ?><?php if ($overdue): ?> <span class="chip chip--bad"><?= e(t('invoices.overdue')) ?></span><?php endif; ?></td>
      <td data-order="<?= e($row['issue_date'] ?? '') ?>"><?= e(format_date($row['issue_date'])) ?: '<span class="muted">—</span>' ?></td>
      <td class="<?= $overdue ? 'overdue' : '' ?>" data-order="<?= e($row['due_date'] ?? '') ?>"><?= e(format_date($row['due_date'])) ?: '<span class="muted">—</span>' ?></td>
      <td class="num" data-order="<?= (int) $row['total'] ?>"><?= e(format_money((int) $row['total'], 'USD')) ?></td>
      <td class="num<?= $row['balance'] > 0 ? ' warn-text' : '' ?>" data-order="<?= (int) $row['balance'] ?>"<?= $showBalance ? '' : ' data-export=""' ?>><?= $showBalance ? e(format_money((int) $row['balance'], 'USD')) : '<span class="muted">—</span>' ?></td>
      <td class="row-actions"><?= partial('partials/preview-button', [
          'href' => url('/invoices/' . $row['id'] . '/print'),
          'kind' => 'document',
          'title' => t('preview.title_invoice', ['ref' => $title]),
          'meta' => t('preview.meta_client_document', ['client' => $row['client_name']]),
      ]) ?></td>
      <?= row_detail([
          t('detail.client_order') => '<a href="' . e(url('/client-orders/' . $row['client_order_id'])) . '">' . e($row['order_reference']) . '</a> · ' . e(t('client_orders.their_po', ['ref' => $row['client_po_number']])),
          t('detail.subtotal') => e(format_money((int) $row['subtotal'], 'USD')),
          t('detail.vat') => e($row['vat_applicable'] ? format_money((int) $row['vat'], 'USD') . ' (' . format_percent((int) $row['vat_rate_bp']) . ')' : t('quotations.total.vat_exempt')),
          t('detail.paid') => (int) $row['paid'] > 0 ? e(format_money((int) $row['paid'], 'USD')) : null,
          t('detail.credited') => (int) $row['credited'] > 0 ? e(format_money((int) $row['credited'], 'USD')) : null,
          t('detail.terms') => e(t('common.days', ['n' => $row['payment_terms_days']])),
          t('detail.issued_at') => e(format_datetime($row['issued_at'])),
          t('detail.cancelled_reason') => e((string) $row['cancelled_reason']),
      ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
