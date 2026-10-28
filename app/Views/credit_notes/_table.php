<?php

/** @var list<array<string, mixed>> $rows @var bool $compact @var bool $withClient */
$withClient ??= true;
?>
<table class="table"<?= !empty($compact) ? ' data-dt="compact"' : '' ?>>
  <thead><tr>
    <th scope="col"><?= e(t('credit_notes.col.reference')) ?></th>
    <?php if ($withClient): ?><th scope="col" data-filter="select"><?= e(t('rfqs.col.client')) ?></th><?php endif; ?>
    <th scope="col" data-x="text"><?= e(t('detail.invoice')) ?></th>
    <th scope="col" data-filter="date"><?= e(t('invoices.col.issue_date')) ?></th>
    <th scope="col" data-priority="low"><?= e(t('credit_notes.reason')) ?></th>
    <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('credit_notes.col.total_usd')) ?>" data-x="money"><?= e(t('invoices.col.total')) ?></th>
    <th scope="col" class="num" data-x="money"><?= e(t('credit_notes.col.unallocated')) ?></th>
    <th scope="col" class="no-sort"><span class="visually-hidden"><?= e(t('common.actions')) ?></span></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $n): ?>
    <tr class="row-link" tabindex="0" data-href="<?= e(url('/credit-notes/' . $n['id'])) ?>">
      <td><strong><?= e($n['reference']) ?></strong></td>
      <?php if ($withClient): ?><td><span class="clip"><?= e($n['client_name']) ?></span></td><?php endif; ?>
      <td><?= e($n['invoice_reference']) ?></td>
      <td data-order="<?= e($n['issue_date']) ?>"><?= e(format_date($n['issue_date'])) ?></td>
      <td><span class="clip"><?= e($n['reason']) ?></span><?php if ($n['is_return']): ?> <span class="chip"><?= e(t('credit_notes.return_chip')) ?></span><?php endif; ?></td>
      <td class="num" data-order="<?= (int) $n['total_minor'] ?>"><?= e(format_money((int) $n['total_minor'], 'USD')) ?></td>
      <td class="num<?= $n['unallocated'] > 0 ? ' warn-text' : '' ?>" data-order="<?= (int) $n['unallocated'] ?>"><?= $n['unallocated'] > 0 ? e(format_money((int) $n['unallocated'], 'USD')) : '<span class="muted">—</span>' ?></td>
      <td class="row-actions"><?= partial('partials/preview-button', [
          'href' => url('/credit-notes/' . $n['id'] . '/print'),
          'kind' => 'document',
          'title' => t('credit_notes.preview_title', ['ref' => $n['reference']]),
          'meta' => t('preview.meta_client_document', ['client' => $n['client_name']]),
      ]) ?></td>
      <?= row_detail([
          t('detail.reason') => e($n['reason']) . ($n['is_return'] ? ' · ' . e(t('credit_notes.return_chip')) : ''),
          t('detail.client_order') => e($n['order_reference'] . ' · ' . t('client_orders.their_po', ['ref' => $n['client_po_number']])),
          t('detail.subtotal') => e(format_money((int) $n['subtotal_minor'], 'USD')),
          t('detail.vat') => e(format_money((int) $n['vat_minor'], 'USD')),
          t('detail.allocated') => e(format_money((int) $n['allocated_minor'], 'USD')),
          t('detail.created_by') => e((string) $n['created_by_name']),
      ], $n['notes'] ? '<p class="row-detail__note">' . e($n['notes']) . '</p>' : '') ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
