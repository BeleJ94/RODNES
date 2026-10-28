<?php

use App\Domain\Orders\ClientOrderStatus;

/** @var list<array<string, mixed>> $rows @var string $status @var array<string, int> $kpis */

$options = ['' => t('filters.all'), 'open' => t('client_orders.filter_open'), 'to_order' => t('client_orders.filter_to_order'), 'to_invoice' => t('client_orders.filter_to_invoice')];
foreach (ClientOrderStatus::cases() as $case) {
    $options[$case->value] = t($case->labelKey());
}
?>
<?= partial('partials/page-header', [
    'icon' => 'file-check',
    'title' => t('client_orders.title'),
    'meta' => e(t('client_orders.meta', ['open' => $kpis['open'], 'amount' => format_money($kpis['open_amount'], 'USD'), 'to_order' => $kpis['to_order']])),
    'actions' => export_button(),
]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/client-orders')) ?>" data-autosubmit>
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
    <div class="empty-state"><?= icon('file-check') ?><p><?= e(t('client_orders.empty')) ?></p></div>
  <?php else: ?>
    <?= partial('client_orders/_table', ['rows' => $rows]) ?>
  <?php endif; ?>
</section>
