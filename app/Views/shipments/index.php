<?php

use App\Domain\Logistics\ShipmentStatus;

/** @var list<array<string, mixed>> $rows @var string $status @var array<string, int> $kpis */

$options = ['' => t('filters.all'), 'open' => t('shipments.filter_open'), 'late' => t('shipments.filter_late'), 'docs_missing' => t('shipments.filter_docs_missing'), 'pod_pending' => t('shipments.filter_pod_pending')];
foreach (ShipmentStatus::cases() as $case) {
    $options[$case->value] = t($case->labelKey());
}
?>
<?= partial('partials/page-header', [
    'icon' => 'truck',
    'title' => t('shipments.title'),
    'meta' => e(t('shipments.meta', ['open' => $kpis['open'], 'late' => $kpis['late'], 'border' => $kpis['at_border']])),
    'actions' => export_button() . (can('shipments.manage') ? '<a class="btn btn--primary" href="' . e(url('/shipments/new')) . '">' . icon('plus') . '<span>' . e(t('shipments.new')) . '</span></a>' : ''),
]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/shipments')) ?>" data-autosubmit>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('filters.view')) ?></span>
        <select name="status">
          <?php foreach ($options as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $status === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
  </form>
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('truck') ?><p><?= e(t('shipments.empty')) ?></p></div>
  <?php else: ?>
    <?= partial('shipments/_table', ['rows' => $rows]) ?>
  <?php endif; ?>
</section>
