<?php

use App\Domain\Purchasing\SupplierInvoiceStatus;

/**
 * @var list<array<string, mixed>> $rows
 * @var string $status
 * @var int|null $supplierId
 * @var list<array{id: int, label: string}> $suppliers
 * @var array<string, int> $kpis
 * @var string $kind
 */

$options = ['' => t('filters.all'), 'open' => t('supplier_invoices.filter_open'), 'overdue' => t('invoices.filter_overdue')];
foreach (SupplierInvoiceStatus::cases() as $case) {
    $options[$case->value] = t($case->labelKey());
}
?>
<?= partial('partials/page-header', [
    'icon' => 'file-input',
    'title' => t('supplier_invoices.title'),
    'meta' => e(t('supplier_invoices.meta', ['amount' => format_money($kpis['open_usd'], 'USD')])),
    'actions' => export_button() . (can('supplier_invoices.manage') ? '<a class="btn" href="' . e(url('/supplier-invoices/logistics/new')) . '">' . icon('truck') . '<span>' . e(t('logistics.record')) . '</span></a>' : ''),
]) ?>
<section class="kpis kpis--compact">
  <a class="kpi kpi--button<?= $kpis['on_hold'] > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/supplier-invoices', ['status' => 'on_hold'])) ?>"><span class="kpi__label"><?= e(t('supplier_invoices.kpi.on_hold')) ?></span><span class="kpi__value"><?= (int) $kpis['on_hold'] ?></span><span class="kpi__hint"><?= e(t('supplier_invoices.kpi.on_hold_hint')) ?></span></a>
  <a class="kpi kpi--button" href="<?= e(url('/supplier-invoices', ['status' => 'open'])) ?>"><span class="kpi__label"><?= e(t('supplier_invoices.kpi.open')) ?></span><span class="kpi__value"><?= e(format_money($kpis['open_usd'], 'USD')) ?></span><span class="kpi__hint"><?= e(t('supplier_invoices.kpi.open_hint')) ?></span></a>
  <a class="kpi kpi--button<?= $kpis['due_soon_usd'] > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/supplier-invoices', ['status' => 'open'])) ?>"><span class="kpi__label"><?= e(t('supplier_invoices.kpi.due_soon')) ?></span><span class="kpi__value"><?= e(format_money($kpis['due_soon_usd'], 'USD')) ?></span><span class="kpi__hint"><?= e(t('supplier_invoices.kpi.due_soon_hint')) ?></span></a>
  <a class="kpi kpi--button<?= $kpis['overdue_usd'] > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('/supplier-invoices', ['status' => 'overdue'])) ?>"><span class="kpi__label"><?= e(t('invoices.kpi.overdue')) ?></span><span class="kpi__value"><?= e(format_money($kpis['overdue_usd'], 'USD')) ?></span><span class="kpi__hint"><?= e(t('invoices.kpi.overdue_hint', ['n' => $kpis['overdue_count']])) ?></span></a>
</section>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/supplier-invoices')) ?>" data-autosubmit>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('filters.view')) ?></span>
        <select name="status">
          <?php foreach ($options as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $status === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="filter"><span><?= e(t('logistics.filter_kind')) ?></span>
        <select name="kind">
          <option value=""><?= e(t('filters.all')) ?></option>
          <option value="goods"<?= $kind === 'goods' ? ' selected' : '' ?>><?= e(t('logistics.kind_goods')) ?></option>
          <option value="logistics"<?= $kind === 'logistics' ? ' selected' : '' ?>><?= e(t('logistics.kind')) ?></option>
        </select>
      </label>
      <label class="filter"><span><?= e(t('sourcing.supplier')) ?></span>
        <select name="supplier_id">
          <option value=""><?= e(t('filters.all')) ?></option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int) $s['id'] ?>"<?= $supplierId === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
  </form>
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('file-input') ?><p><?= e(t('supplier_invoices.empty')) ?></p></div>
  <?php else: ?>
    <?= partial('supplier_invoices/_table', ['rows' => $rows]) ?>
  <?php endif; ?>
</section>
