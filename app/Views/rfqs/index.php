<?php

use App\Domain\Sales\RfqStatus;

/**
 * @var list<array<string, mixed>> $rows
 * @var array{status: string, client_id: ?int} $filters
 * @var list<array{id: int, label: string}> $clients
 * @var array<string, int> $kpis
 */

$now = date('Y-m-d H:i:s');
$chips = ['' => t('filters.all'), 'open' => t('rfqs.filter_open'), 'late' => t('rfqs.filter_late')];
foreach (RfqStatus::cases() as $case) {
    $chips[$case->value] = t($case->labelKey());
}
?>
<?= partial('partials/page-header', [
    'icon' => 'inbox',
    'title' => t('rfqs.title'),
    'meta' => e(t('rfqs.meta', ['open' => $kpis['open'], 'late' => $kpis['late']])),
    'actions' => export_button() . (can('rfqs.manage') ? '<a class="btn btn--primary" href="' . e(url('/rfqs/new')) . '">' . icon('plus') . '<span>' . e(t('rfqs.new')) . '</span></a>' : ''),
]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/rfqs')) ?>" data-autosubmit>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('filters.view')) ?></span>
        <select name="status">
          <?php foreach ($chips as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $filters['status'] === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <?php if ($filters['status'] !== '' || $filters['client_id'] !== null): ?>
      <a class="btn btn--ghost btn--sm" href="<?= e(url('/rfqs')) ?>"><?= icon('rotate-ccw') ?><span><?= e(t('filters.reset')) ?></span></a>
    <?php endif; ?>
  </form>
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('inbox') ?><p><?= e(t('rfqs.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('rfqs.col.reference')) ?></th>
      <th scope="col"><?= e(t('rfqs.client_reference')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('rfqs.col.client')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('rfqs.col.status')) ?></th>
      <th scope="col" data-filter="date"><?= e(t('rfqs.col.received')) ?></th>
      <th scope="col" data-filter="date" data-x="date"><?= e(t('rfqs.col.due')) ?></th>
      <th scope="col" class="num" data-x="text"><?= e(t('rfqs.col.offers')) ?></th>
      <th scope="col" data-filter="select" data-priority="low"><?= e(t('rfqs.col.owner')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row):
        $status = RfqStatus::from($row['status']);
        $late = $status->isOpen() && $row['due_at'] !== null && $row['due_at'] < $now; ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/rfqs/' . $row['id'])) ?>">
        <td><strong><?= e($row['reference']) ?></strong></td>
        <td><?= $row['client_reference'] ? '<span class="client-ref">' . e($row['client_reference']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td><span class="clip"><?= e($row['client_name']) ?></span></td>
        <td data-filter="<?= e(t($status->labelKey())) ?>"><?= badge($status->tone(), t($status->labelKey())) ?></td>
        <td data-order="<?= e($row['received_on']) ?>"><?= e(format_date($row['received_on'])) ?></td>
        <td class="<?= $late ? 'overdue' : '' ?>" data-order="<?= e($row['due_at'] ?? '') ?>"><?= $row['due_at'] ? e(format_datetime($row['due_at'])) : '<span class="muted">—</span>' ?></td>
        <td class="num" data-order="<?= (int) $row['offers'] ?>"><?= (int) $row['offers'] ?> / <?= (int) $row['requests'] ?></td>
        <td><?= e($row['owner_name'] ?? '—') ?></td>
        <?= row_detail([
            t('detail.buyer') => e(trim($row['buyer_name'] . ' ' . ($row['buyer_email'] ? '<' . $row['buyer_email'] . '>' : ''))),
            t('detail.site') => e((string) $row['delivery_site']),
            t('detail.lines') => e(t('rfqs.lines_count', ['n' => $row['line_count']])),
            t('detail.sourcing') => e(t('detail.offers_of', ['offers' => $row['offers'], 'requests' => $row['requests']])),
            t('detail.owner') => e((string) $row['owner_name']),
            t('detail.closed_reason') => e((string) $row['closed_reason']),
        ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
