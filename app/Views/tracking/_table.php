<?php

/**
 * Une ligne par RFQ : réponse, devis en cours, PO client.
 *
 * @var list<array<string, mixed>> $rows @var bool $withClient @var string $mode
 */
$withClient ??= true;
$columnFilters ??= true; // la page Suivi commercial filtre côté serveur (client, période, étape)
$filter = static fn (string $type): string => $columnFilters ? ' data-filter="' . $type . '"' : '';
$unit = t('tracking.days_short_' . $mode);
$tones = ['to_answer' => 'warn', 'awaiting_client' => 'progress', 'awaiting_po' => 'progress', 'won' => 'ok', 'lost' => 'neutral', 'cancelled' => 'neutral'];
?>
<div class="table-scroll">
<table class="table" data-page-length="25">
  <thead><tr>
    <th scope="col"><?= e(t('tracking.col.rfq')) ?></th>
    <?php if ($withClient): ?><th scope="col"<?= $filter('select') ?>><?= e(t('rfqs.col.client')) ?></th><?php endif; ?>
    <th scope="col" data-x="date"<?= $filter('date') ?> data-hidden data-x-always="1"><?= e(t('export.col.received_on')) ?></th>
    <th scope="col" data-x="date"><?= e(t('tracking.col.due')) ?></th>
    <th scope="col" class="text-left" data-x="text"><?= e(t('tracking.col.answer')) ?></th>
    <th scope="col" data-x="text"><?= e(t('tracking.col.quotation')) ?></th>
    <th scope="col" class="num" data-x="money" data-priority="low"><?= e(t('tracking.col.amount')) ?></th>
    <th scope="col"<?= $filter('select') ?>><?= e(t('tracking.col.stage')) ?></th>
    <th scope="col" data-x="text"><?= e(t('tracking.col.po')) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('/rfqs/' . $r['id'])) ?>"><strong><?= e($r['reference']) ?></strong></a></td>
      <?php if ($withClient): ?><td><span class="clip"><?= e($r['client_name']) ?></span></td><?php endif; ?>
      <td data-order="<?= e($r['received_on']) ?>"><?= e(format_date($r['received_on'])) ?></td>
      <td data-order="<?= e((string) $r['due_at']) ?>"<?= $r['due_at'] ? ' title="' . e(format_datetime($r['due_at'])) . '"' : '' ?>><?= $r['due_at'] ? e(format_date($r['due_at'])) : '<span class="muted">—</span>' ?></td>
      <td class="text-left" data-order="<?= $r['response_days'] ?? 9999 ?>">
        <?php if ($r['first_sent_at'] !== null): ?>
          <?= $r['on_time'] === false ? '<span class="chip chip--bad">' . e(t('tracking.late_by', ['n' => $r['late_days'], 'unit' => $unit])) . '</span>' : '<span class="chip chip--ok">' . e(t('tracking.on_time')) . '</span>' ?>
          <span class="sub"><?= e($r['response_days'] === 0 ? t('tracking.same_day') : t('tracking.in_days', ['n' => $r['response_days'], 'unit' => $unit])) ?></span>
        <?php elseif ($r['stage'] === 'to_answer'): ?>
          <?= $r['on_time'] === false ? '<span class="chip chip--bad">' . e(t('tracking.overdue_by', ['n' => $r['late_days'], 'unit' => $unit])) . '</span>' : '<span class="muted">' . e(t('tracking.not_answered')) . '</span>' ?>
        <?php else: ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td><?= $r['quotation_id'] !== null ? '<a href="' . e(url('/quotations/' . $r['quotation_id'])) . '">' . e($r['quotation_reference'] . ' v' . $r['version']) . '</a>' : '<span class="muted">—</span>' ?></td>
      <td class="num" data-order="<?= $r['quotation_id'] !== null ? (int) $r['quotation_amount'] : '' ?>"><?= $r['quotation_id'] !== null ? e(format_money((int) $r['quotation_amount'], 'USD')) : '<span class="muted">—</span>' ?></td>
      <td data-filter="<?= e(t('tracking.stage.' . $r['stage'])) ?>"><?= badge(\App\Domain\Shared\Tone::from($tones[$r['stage']]), t('tracking.stage.' . $r['stage'])) ?></td>
      <td><?= $r['order_id'] !== null ? '<a href="' . e(url('/client-orders/' . $r['order_id'])) . '">' . e($r['order_reference']) . '</a>' : '<span class="muted">—</span>' ?></td>
      <?= row_detail([
          t('export.col.received_on') => e(format_date($r['received_on'])),
          t('tracking.col.due') => e(format_datetime($r['due_at'])),
          t('detail.client_reference') => e((string) $r['client_reference']),
          t('detail.owner') => e((string) $r['owner_name']),
          t('tracking.col.answered_on') => e(format_datetime($r['first_sent_at'])),
          t('detail.quotation_status') => $r['quotation_status'] !== null ? e(t('quotation_status.' . $r['quotation_status'])) : null,
          t('detail.valid_until') => e(format_date($r['valid_until'])),
          t('detail.followups') => $r['followups'] > 0 ? e(t('tracking.followups', ['n' => $r['followups'], 'date' => format_date($r['last_followup_on'])])) : null,
          t('detail.lost_reason') => $r['stage'] === 'lost' ? e(implode(' — ', array_filter([$r['lost_reason'] ? t('lost_reason.' . $r['lost_reason']) : null, $r['lost_comment'] ?? $r['closed_reason']]))) : null,
          t('detail.client_po') => $r['order_id'] !== null ? e($r['client_po_number'] . ' · ' . format_date($r['order_received_on'])) : null,
      ]) ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
