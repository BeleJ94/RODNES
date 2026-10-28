<?php

use App\Domain\Orders\SupplierOrderStatus;

/** @var list<array<string, mixed>> $rows @var string $status @var array<string, int> $kpis */

$options = ['' => t('filters.all'), 'open' => t('supplier_orders.filter_open')];
foreach (SupplierOrderStatus::cases() as $case) {
    $options[$case->value] = t($case->labelKey());
}
?>
<?= partial('partials/page-header', [
    'icon' => 'shopping-cart',
    'title' => t('supplier_orders.title'),
    'meta' => e(t('supplier_orders.meta', ['draft' => $kpis['draft'], 'sent' => $kpis['sent'], 'confirmed' => $kpis['confirmed']])),
    'actions' => export_button(),
]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/supplier-orders')) ?>" data-autosubmit>
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
    <div class="empty-state"><?= icon('shopping-cart') ?><p><?= e(t('supplier_orders.empty')) ?></p></div>
  <?php else: ?>
    <?= partial('supplier_orders/_table', ['rows' => $rows, 'withClient' => true]) ?>
  <?php endif; ?>
</section>
