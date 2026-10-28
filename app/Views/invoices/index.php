<?php

use App\Domain\Invoicing\InvoiceStatus;

/** @var list<array<string, mixed>> $rows @var string $status @var array<string, int> $kpis */

$options = ['' => t('filters.all'), 'open' => t('invoices.filter_open'), 'overdue' => t('invoices.filter_overdue')];
foreach (InvoiceStatus::cases() as $case) {
    $options[$case->value] = t($case->labelKey());
}
$actions = can('payments.manage') ? '<a class="btn" href="' . e(url('/payments/new')) . '">' . icon('wallet') . '<span>' . e(t('payments.new')) . '</span></a>' : '';
?>
<?= partial('partials/page-header', [
    'icon' => 'receipt',
    'title' => t('invoices.title'),
    'meta' => e(t('invoices.meta', ['outstanding' => format_money($kpis['outstanding'], 'USD'), 'overdue' => format_money($kpis['overdue'], 'USD'), 'n' => $kpis['overdue_count']])),
    'actions' => export_button() . ($actions),
]) ?>
<section class="kpis kpis--compact">
  <a class="kpi kpi--button" href="<?= e(url('/invoices', ['status' => 'open'])) ?>"><span class="kpi__label"><?= e(t('invoices.kpi.outstanding')) ?></span><span class="kpi__value"><?= e(format_money($kpis['outstanding'], 'USD')) ?></span><span class="kpi__hint"><?= e(t('invoices.kpi.outstanding_hint', ['n' => $kpis['open_count']])) ?></span></a>
  <a class="kpi kpi--button<?= $kpis['overdue'] > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('/invoices', ['status' => 'overdue'])) ?>"><span class="kpi__label"><?= e(t('invoices.kpi.overdue')) ?></span><span class="kpi__value"><?= e(format_money($kpis['overdue'], 'USD')) ?></span><span class="kpi__hint"><?= e(t('invoices.kpi.overdue_hint', ['n' => $kpis['overdue_count']])) ?></span></a>
  <a class="kpi kpi--button<?= $kpis['drafts'] > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/invoices', ['status' => 'draft'])) ?>"><span class="kpi__label"><?= e(t('invoices.kpi.drafts')) ?></span><span class="kpi__value"><?= (int) $kpis['drafts'] ?></span><span class="kpi__hint"><?= e(t('invoices.kpi.drafts_hint')) ?></span></a>
  <a class="kpi kpi--button" href="<?= e(url('/client-orders', ['status' => 'to_invoice'])) ?>"><span class="kpi__label"><?= e(t('invoices.kpi.to_invoice')) ?></span><span class="kpi__value"><?= icon('arrow-right') ?></span><span class="kpi__hint"><?= e(t('invoices.kpi.to_invoice_hint')) ?></span></a>
</section>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/invoices')) ?>" data-autosubmit>
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
    <div class="empty-state"><?= icon('receipt') ?><p><?= e(t('invoices.empty')) ?></p></div>
  <?php else: ?>
    <?= partial('invoices/_table', ['rows' => $rows]) ?>
  <?php endif; ?>
</section>
