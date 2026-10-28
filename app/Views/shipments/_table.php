<?php

use App\Domain\Logistics\ShipmentStatus;

/** @var list<array<string, mixed>> $rows */
$today = date('Y-m-d');
?>
<table class="table"<?= !empty($compact) ? ' data-dt="compact"' : '' ?>>
  <thead><tr>
    <th scope="col"><?= e(t('shipments.col.reference')) ?></th>
    <th scope="col" data-filter="select"><?= e(t('rfqs.col.client')) ?></th>
    <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    <th scope="col" data-filter="select" data-priority="low"><?= e(t('shipments.col.carrier')) ?></th>
    <th scope="col" data-priority="low"><?= e(t('shipments.col.pos')) ?></th>
    <th scope="col" data-filter="date" data-filter-label="<?= e(t('shipments.col.arrival')) ?>"><?= e(t('shipments.eta')) ?></th>
    <th scope="col" data-filter="select"><?= e(t('shipments.docs.col')) ?></th>
    <th scope="col" class="no-sort"><span class="visually-hidden"><?= e(t('shipments.pod_title')) ?></span></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $row):
      $status = ShipmentStatus::from($row['status']);
      $late = !$status->isFinal() && $row['eta'] !== null && $row['eta'] < $today; ?>
    <tr class="row-link" tabindex="0" data-href="<?= e(url('/shipments/' . $row['id'])) ?>">
      <td><strong><?= e($row['reference']) ?></strong></td>
      <td><span class="clip"><?= e($row['client_name']) ?></span></td>
      <td data-filter="<?= e(t($status->labelKey())) ?>"><?= badge($status->tone(), t($status->labelKey())) ?></td>
      <td><span class="clip"><?= e($row['carrier_name'] ?: t('shipments.carrier_type.' . $row['carrier_type'])) ?></span></td>
      <td class="muted"><span class="clip"><?= e($row['po_references']) ?></span></td>
      <td class="<?= $late ? 'overdue' : '' ?>" data-order="<?= e(($status === ShipmentStatus::Delivered ? $row['delivered_at'] : $row['eta']) ?? '') ?>"><?= $status === ShipmentStatus::Delivered ? e(t('shipments.delivered_on', ['date' => format_date($row['delivered_at'])])) : (e(format_date($row['eta'])) ?: '<span class="muted">—</span>') ?></td>
      <?php $docsLabel = (int) $row['docs_required'] === 0 ? t('shipments.docs.none_required') : ($row['docs_missing'] > 0 ? t('shipments.docs.missing_n', ['n' => (int) $row['docs_missing']]) : t('shipments.docs.complete')); ?>
      <td data-filter="<?= e($docsLabel) ?>" data-order="<?= (int) $row['docs_missing'] ?>"><?= (int) $row['docs_required'] === 0 ? '<span class="muted">—</span>' : badge($row['docs_missing'] > 0 && $status !== ShipmentStatus::Cancelled ? \App\Domain\Shared\Tone::Warn : \App\Domain\Shared\Tone::Ok, ((int) $row['docs_required'] - (int) $row['docs_missing']) . ' / ' . (int) $row['docs_required']) ?></td>
      <td class="row-actions"><?php if ($row['pod_file_id'] !== null && ($kind = preview_kind((string) $row['pod_mime'])) !== null): ?><?= partial('partials/preview-button', [
          'href' => url('/files/' . $row['pod_file_id']),
          'kind' => $kind,
          'title' => t('preview.title_pod', ['ref' => $row['reference']]),
          'meta' => (string) $row['pod_file_name'],
          'download' => url('/files/' . $row['pod_file_id'], ['download' => 1]),
      ]) ?><?php endif; ?></td>
      <?= row_detail([
          t('detail.tracking') => e((string) $row['tracking_reference']),
          t('detail.carrier') => e(t('shipments.carrier_type.' . $row['carrier_type']) . ($row['carrier_name'] ? ' · ' . $row['carrier_name'] : '')),
          t('detail.route') => e(trim(($row['origin'] ?? '') . ' → ' . ($row['destination'] ?? ''), ' →')),
          t('detail.pickup_planned') => e(format_date($row['planned_pickup_on'])),
          t('detail.picked_up') => e(format_datetime($row['picked_up_at'])),
          t('detail.eta') => e(format_date($row['eta'])),
          t('detail.delivered') => e(format_datetime($row['delivered_at'])),
          t('detail.pod') => $row['pod_received_at'] ? e(format_datetime($row['pod_received_at'])) : null,
          t('detail.supplier_orders') => e((string) $row['po_references']),
          t('detail.lines') => e((string) $row['line_count']),
      ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
